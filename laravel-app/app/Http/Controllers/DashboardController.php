<?php

namespace App\Http\Controllers;

use App\Models\Setup;
use Illuminate\Support\Facades\Redis;

class DashboardController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $selection = $request->validate(['setup_id' => 'sometimes|integer|min:1']);
        $activeSetup       = isset($selection['setup_id']) ? Setup::findOrFail($selection['setup_id'])
            : Setup::where('status', 'active')->orderByDesc('created_at')->orderByDesc('id')->first();
        if (isset($selection['setup_id'])) {
            abort_unless($activeSetup->source === config('orderflow.connector') && $activeSetup->instrument === config('clusterdelta.instrument'),
                409, 'The current chart feed does not match this setup source and instrument.');
        }
        $recentSetups      = Setup::orderByDesc('created_at')->limit(6)->get();
        $initialTimeframe  = isset($selection['setup_id']) ? $activeSetup->timeframe
            : app(\App\Services\MarketDataService::class)->activeTimeframe();
        $metrics           = $this->getMetrics($initialTimeframe);
        $confluenceSignals = $this->getConfluenceSignals($initialTimeframe);
        $recentBars        = $this->getRecentBars($initialTimeframe);
        $focusedChartData = isset($selection['setup_id']) ? $activeSetup->only(['id', 'entry', 'stop_loss', 'tp1', 'tp2']) : null;

        return view('dashboard.index', compact(
            'activeSetup', 'recentSetups', 'metrics', 'confluenceSignals', 'recentBars', 'initialTimeframe', 'focusedChartData'
        ));
    }

    private function getMetrics(string $tf): array
    {
        if (config('orderflow.connector') === 'clusterdelta') {
            if (!app(\App\Services\MarketDataService::class)->bars($tf, 1)) return [];
            return app(\App\Services\OrderflowMetricsService::class)->compute($tf);
        }
        try { $r = Redis::get('xauusd:metrics:latest'); return $r ? json_decode($r, true) : []; }
        catch (\Exception) { return []; }
    }

    private function getConfluenceSignals(string $tf): array
    {
        if (config('orderflow.connector') === 'clusterdelta') {
            try {
                $key = app(\App\Services\SetupEngineService::class)->key($tf);
                $state = json_decode(Redis::get($key) ?: 'null', true);
                if ($state && now()->timestamp - $state['checked_at'] < 90 && $state['signals']) return $state['signals'];
                $reason = $state['reason'] ?? 'Waiting for the setup detector';
                return array_map(fn ($signal) => array_merge($signal, ['detail' => $reason]), $this->defaultSignals());
            } catch (\Throwable) { return $this->defaultSignals(); }
        }
        try { $r = Redis::get('xauusd:confluence:latest'); return $r ? json_decode($r, true) : $this->defaultSignals(); }
        catch (\Exception) { return $this->defaultSignals(); }
    }

    private function getRecentBars(string $tf): array
    {
        try {
            $raw = app(\App\Services\MarketDataService::class)->bars($tf, 20);
            $bars = array_map(fn($b) => json_decode($b, true), $raw);
            $setups = Setup::where('source', config('orderflow.connector'))
                ->where('instrument', config('clusterdelta.instrument', 'GC'))
                ->where('timeframe', $tf)->whereIn('candle_time', array_column($bars, 'time'))
                ->get()->keyBy('candle_time');
            foreach ($bars as &$bar) {
                $buy = $bar['buy_vol'] ?? 0; $sell = $bar['sell_vol'] ?? 0;
                $ratio = config('orderflow.imbalance_ratio', 3);
                $bar['imbalance'] = $sell > 0 && $buy >= $ratio * $sell ? 'BULLISH'
                    : ($buy > 0 && $sell >= $ratio * $buy ? 'BEARISH' : 'NEUTRAL');
                $bar['signal'] = $setups->get($bar['time'])?->direction;
            }
            unset($bar);
            return $bars;
        } catch (\Exception) { return []; }
    }

    private function defaultSignals(): array
    {
        return [
            ['name' => 'CVD Divergence',    'status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'Delta Imbalance',   'status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'Absorption Pattern','status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'Stacked Imbalance', 'status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'Exhaustion Signal', 'status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'VWAP Deviation',    'status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'POC Rejection',     'status' => 'neutral', 'detail' => 'Waiting for data'],
            ['name' => 'GC Market Participation', 'status' => 'neutral', 'detail' => 'Waiting for data'],
        ];
    }
}
