<?php

namespace App\Http\Controllers;

use App\Models\Setup;
use Illuminate\Support\Facades\Redis;

class DashboardController extends Controller
{
    public function index()
    {
        $activeSetup       = Setup::where('status', 'active')->orderByDesc('created_at')->first();
        $recentSetups      = Setup::orderByDesc('created_at')->limit(6)->get();
        $metrics           = $this->getMetrics();
        $confluenceSignals = $this->getConfluenceSignals();
        $recentBars        = $this->getRecentBars('15m');

        return view('dashboard.index', compact(
            'activeSetup', 'recentSetups', 'metrics', 'confluenceSignals', 'recentBars'
        ));
    }

    private function getMetrics(): array
    {
        try { $r = Redis::get('xauusd:metrics:latest'); return $r ? json_decode($r, true) : []; }
        catch (\Exception) { return []; }
    }

    private function getConfluenceSignals(): array
    {
        try { $r = Redis::get('xauusd:confluence:latest'); return $r ? json_decode($r, true) : $this->defaultSignals(); }
        catch (\Exception) { return $this->defaultSignals(); }
    }

    private function getRecentBars(string $tf): array
    {
        try {
            $raw = Redis::lrange("xauusd:bars:{$tf}", 0, 19);
            return array_map(fn($b) => json_decode($b, true), $raw);
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
        ];
    }
}
