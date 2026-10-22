<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ResearchArchiveService
{
    /** Keep closed-candle revisions for replay, with honest first-observation timestamps. */
    public function record(string $kind, array $snapshot): void
    {
        if (!config('research.archive_enabled')) return;
        if (!in_array($kind, ['broker','footprint'], true)) throw new \InvalidArgumentException('Unsupported archive kind.');
        $seconds = ['1m'=>60,'5m'=>300,'15m'=>900,'30m'=>1800,'1h'=>3600,'4h'=>14400,'1d'=>86400][$snapshot['tf']] ?? null;
        if (!$seconds) return;
        $now = now()->timestamp;
        try {
            $disk = Storage::disk('local');
            $root = $disk->path('research/clusterdelta');
            if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) throw new \RuntimeException('Archive directory unavailable.');
            $lock = fopen($root.'/.write.lock', 'c');
            if (!$lock) throw new \RuntimeException('Archive lock unavailable.');
            try {
                if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Archive lock failed.');
                $meta = array_intersect_key($snapshot, array_flip(['instrument','tf','symbol','tick_size','vendor_expiration','source_utc_offset_minutes','price_source','source']));
                foreach ($snapshot['bars'] as $bar) {
                    if (!($bar['closed'] ?? true) || $bar['time']+$seconds > $now) continue;
                    unset($bar['received_at']);
                    $content = ['kind'=>$kind,'metadata'=>$meta,'bar'=>$bar];
                    $hash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                    $relative = 'research/clusterdelta/'.$snapshot['instrument'].'/'.$snapshot['tf'].'/'.gmdate('Y-m-d',$bar['time']).'/'.$kind.'/'.$bar['time'].'-'.$hash.'.json.gz';
                    if ($disk->exists($relative)) continue;
                    $envelope = $content + ['schema_version'=>1,'first_observed_at'=>$now,
                        'late_observation'=>$now-($bar['time']+$seconds)>60, 'sha256'=>$hash];
                    $bytes = gzencode(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 6);
                    $temporary = $relative.'.tmp-'.bin2hex(random_bytes(4));
                    if ($bytes === false || !$disk->put($temporary, $bytes) || !$disk->move($temporary,$relative)) {
                        throw new \RuntimeException('Archive write failed.');
                    }
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        } catch (\Throwable $e) {
            // A research disk problem must not interrupt live prices or alert evaluation.
            Log::warning('Research archive write failed; live ingestion continues.', ['kind'=>$kind,'error_type'=>get_class($e)]);
        }
    }
}
