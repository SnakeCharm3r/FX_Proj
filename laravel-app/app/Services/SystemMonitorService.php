<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class SystemMonitorService
{
    public function __construct(private MarketDataService $marketData,private ?FootprintService $footprint=null) {}

    public function status(): array
    {
        $now = now()->timestamp;
        $processes = $this->processes();
        $instrument = config('clusterdelta.instrument', 'GC');
        try {
            $redisReady = (bool) Redis::ping();
        } catch (\Throwable) {
            $redisReady = false;
        }

        $feeds = [];
        foreach (['1m', '5m', '15m', '30m', '1h', '4h', '1d'] as $tf) {
            $file = config('monitor.export_directory')."/FXFootprint_{$instrument}_{$tf}.json";
            clearstatcache(true, $file);
            $modified = is_file($file) ? (@filemtime($file) ?: null) : null;
            $bar = null;
            if ($redisReady) {
                try {
                    if($this->footprint){
                        $snapshot=$this->footprint->snapshot($instrument,$tf);
                        $bar=$snapshot&&$snapshot['bars']?end($snapshot['bars']):null;
                        if($bar)$bar['received_at']=$snapshot['received_at']??null;
                    }else{
                        $raw=$this->marketData->bars($tf,1);$bar=$raw?json_decode($raw[0],true):null;
                    }
                } catch (\Throwable) {
                    // A failed feed read must not hide the other service statuses.
                }
            }
            if ($tf !== '15m' && !$modified && !$bar) continue;
            $received = $bar['received_at'] ?? null;
            $feeds[] = [
                'instrument' => $instrument, 'timeframe' => $tf,
                'exported_at' => $modified, 'received_at' => $received,
                'export_fresh' => $this->fresh($modified, $now),
                'receiving' => $this->fresh($received, $now),
            ];
        }
        $freshExport = count(array_filter($feeds, fn ($feed) => $feed['export_fresh'])) > 0;
        $receiving = count(array_filter($feeds, fn ($feed) => $feed['receiving'])) > 0;
        $engine = null;
        if ($redisReady) {
            try { $engine = json_decode(Redis::get('orderflow:engine:heartbeat') ?: 'null', true); }
            catch (\Throwable) {}
        }
        $engineFresh = $engine && $now - ($engine['checked_at'] ?? 0) < 90;
        $services = [
            ['id' => 'app', 'name' => 'Application server', 'state' => 'running',
                'detail' => 'Responded to this health check.', 'pids' => []],
            $this->service('mt5', 'MT5 terminal', $processes, $freshExport,
                'Footprint export is updating.', 'No recent footprint export. Check MT5 and the footprint exporter.'),
            $this->service('forwarder', 'Data forwarder', $processes, $receiving,
                'Native footprint data is reaching the application.', 'No recent arrivals from the footprint forwarder.'),
            ['id' => 'redis', 'name' => 'Redis storage', 'state' => $redisReady ? 'running' : 'unavailable',
                'detail' => $redisReady ? 'Storage answered a ping.' : 'Storage did not answer. Check Redis.', 'pids' => []],
            $this->service('reverb', 'WebSocket server', $processes, false,
                '', 'Optional for this feed: the dashboard also refreshes by polling.'),
            ['id' => 'engine', 'name' => 'Setup detector',
                'state' => $engineFresh ? (($engine['error'] ?? false) ? 'unavailable' : 'active') : 'not_detected',
                'detail' => $engineFresh ? 'Recent evaluation recorded. See Setups for conditions and feed blockers.'
                    : 'No recent evaluation. Start php artisan orderflow:watch in its own terminal.', 'pids' => []],
        ];
        $summary = !$redisReady ? 'Redis is unavailable; incoming data cannot be read.'
            : ($receiving ? 'Native GC footprint data is reaching the application.'
            : ($freshExport ? 'MT5 is exporting, but ingestion is stale. Check the data forwarder.'
            : 'No fresh footprint export. Check MT5, FXClusterDeltaFootprint and DLL permission.'));

        return ['checked_at' => $now, 'healthy' => $redisReady && $receiving,
            'summary' => $summary, 'services' => $services, 'feeds' => $feeds];
    }

    private function fresh(?int $timestamp, int $now): bool
    {
        return $timestamp !== null && $timestamp <= $now + 5
            && $now - $timestamp < config('monitor.fresh_seconds', 60);
    }

    private function service(string $id, string $name, ?array $processes, bool $active, string $activeDetail, string $inactiveDetail): array
    {
        $pids = $processes[$id] ?? [];
        return ['id' => $id, 'name' => $name,
            'state' => $pids ? 'running' : ($active ? 'active' : ($processes === null ? 'unknown' : 'not_detected')),
            'detail' => $active ? $activeDetail : $inactiveDetail,
            'pids' => $pids];
    }

    protected function processes(): ?array
    {
        $entries = glob('/proc/[0-9]*/cmdline');
        if (!$entries) return null;
        $found = ['mt5' => [], 'forwarder' => [], 'reverb' => []];
        $readable = false;
        foreach ($entries as $entry) {
            $raw = @file_get_contents($entry);
            if (!$raw) continue;
            $readable = true;
            $args = explode("\0", rtrim($raw, "\0"));
            $names = array_map(fn ($arg) => strtolower(basename(str_replace('\\', '/', $arg))), $args);
            $comm = strtolower(trim(@file_get_contents(dirname($entry).'/comm') ?: ''));
            $pid = (int) basename(dirname($entry));
            // Match executable/argument names; never return full command lines.
            if ($comm === 'terminal64.exe' || in_array('terminal64.exe', array_slice($names, 0, 3), true)) $found['mt5'][] = $pid;
            if (str_starts_with($names[0] ?? '', 'python') && in_array('clusterdelta_ingestion.py', $names, true)) $found['forwarder'][] = $pid;
            if (in_array('artisan', $names, true) && in_array('reverb:start', $args, true)) $found['reverb'][] = $pid;
        }
        return $readable ? $found : null;
    }
}
