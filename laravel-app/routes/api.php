<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redis;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\SettingsController;
use App\Events\TickReceived;
use App\Events\MetricsUpdated;

/* ── Python microservice → tick data ── */
Route::post('/ticks', function (\Illuminate\Http\Request $request) {
    $request->validate(['bid' => 'required|numeric', 'ask' => 'required|numeric']);
    Redis::set('xauusd:tick:latest', json_encode($request->all()));
    broadcast(new TickReceived($request->all()))->toOthers();
    return response()->json(['ok' => true]);
})->middleware('throttle:600,1');

/* ── Python microservice → bar data ── */
Route::post('/bars', function (\Illuminate\Http\Request $request) {
    $request->validate(['tf' => 'required|string', 'bars' => 'required|array']);
    foreach ($request->bars as $bar) {
        Redis::lpush("xauusd:bars:{$request->tf}", json_encode($bar));
        Redis::ltrim("xauusd:bars:{$request->tf}", 0, 499);
    }
    return response()->json(['ok' => true]);
})->middleware('throttle:300,1');

/* ── Python microservice → metrics ── */
Route::post('/metrics', function (\Illuminate\Http\Request $request) {
    Redis::set('xauusd:metrics:latest', json_encode($request->all()));
    broadcast(new MetricsUpdated($request->all()))->toOthers();
    return response()->json(['ok' => true]);
})->middleware('throttle:120,1');

/* ── Frontend → chart candles ── */
Route::get('/chart-data', function (\Illuminate\Http\Request $request) {
    $tf     = $request->get('tf', '15m');
    $raw    = Redis::lrange("xauusd:bars:{$tf}", 0, 199);
    $parsed = array_reverse(array_map(function ($b) {
        $decoded = json_decode($b, true);
        // Python redis-py stores strings as JSON-encoded strings ("\"{ }\"")
        // so we may need a second decode pass
        return is_string($decoded) ? json_decode($decoded, true) : $decoded;
    }, $raw));

    if (empty($parsed)) {
        return response()->json(['candles'=>[],'volume'=>[],'setups'=>[],'vwap'=>[],'poc'=>[],'high_vol_markers'=>[]]);
    }

    // ── Volume stats for high-volume detection ──────────────────────────
    $volumes = array_map(fn($b) => ($b['buy_vol'] ?? 0) + ($b['sell_vol'] ?? 0), $parsed);
    $avgVol  = count($volumes) > 0 ? array_sum($volumes) / count($volumes) : 0;
    $volMultiplier = (float) $request->get('vol_mult', 2.0); // bars > 2× avg = high volume

    // ── POC (price level with most cumulative volume) ─────────────────
    $volumeByPrice = [];
    foreach ($parsed as $b) {
        $p = (string) round(($b['close'] ?? 0) * 2) / 2;
        $volumeByPrice[$p] = ($volumeByPrice[$p] ?? 0) + ($volumes[array_search($b, $parsed)] ?? 0);
    }
    arsort($volumeByPrice);
    $pocPrice = (float) array_key_first($volumeByPrice);

    // ── VWAP ──────────────────────────────────────────────────────────
    $cumTPV = 0; $cumVol = 0;
    $vwapLine = [];
    foreach ($parsed as $b) {
        $tp = (($b['high'] ?? 0) + ($b['low'] ?? 0) + ($b['close'] ?? 0)) / 3;
        $v  = ($b['buy_vol'] ?? 0) + ($b['sell_vol'] ?? 0);
        $cumTPV += $tp * $v;
        $cumVol += $v;
        $vwapLine[] = ['time' => $b['time'] ?? 0, 'value' => $cumVol > 0 ? round($cumTPV / $cumVol, 2) : 0];
    }

    // ── POC horizontal line ────────────────────────────────────────────
    $pocLine = [];
    if ($pocPrice > 0 && count($parsed) >= 2) {
        $pocLine = [
            ['time' => $parsed[0]['time'] ?? 0,                  'value' => $pocPrice],
            ['time' => $parsed[count($parsed)-1]['time'] ?? 0,   'value' => $pocPrice],
        ];
    }

    // ── Build candles, volume bars, high-volume markers ───────────────
    $candles         = [];
    $volume          = [];
    $highVolMarkers  = [];

    foreach ($parsed as $b) {
        $totalVol = ($b['buy_vol'] ?? 0) + ($b['sell_vol'] ?? 0);
        $isBull   = ($b['delta'] ?? 0) >= 0;
        $isHighVol = $avgVol > 0 && $totalVol >= ($avgVol * $volMultiplier);

        $candles[] = [
            'time'  => $b['time']  ?? 0, 'open'  => $b['open']  ?? 0,
            'high'  => $b['high']  ?? 0, 'low'   => $b['low']   ?? 0,
            'close' => $b['close'] ?? 0,
        ];

        // High-volume bars get a brighter, opaque colour
        $volume[] = [
            'time'  => $b['time'] ?? 0,
            'value' => $totalVol,
            'color' => $isHighVol
                ? ($isBull ? 'rgba(0,192,118,.95)'  : 'rgba(255,59,92,.95)')
                : ($isBull ? 'rgba(0,192,118,.35)'  : 'rgba(255,59,92,.35)'),
        ];

        // Chart marker for very high volume bars
        if ($isHighVol) {
            $highVolMarkers[] = [
                'time'     => $b['time'] ?? 0,
                'position' => $isBull ? 'belowBar' : 'aboveBar',
                'color'    => $isBull ? '#00c076' : '#ff3b5c',
                'shape'    => 'square',
                'text'     => 'HV ' . number_format($totalVol),
            ];
        }
    }

    $setups = \App\Models\Setup::where('timeframe', $tf)
        ->whereDate('created_at', '>=', now()->subDays(3))
        ->get(['direction', 'entry', 'score', 'created_at'])
        ->map(fn($s) => [
            'time'      => $s->created_at->timestamp,
            'direction' => $s->direction,
            'entry'     => $s->entry,
            'score'     => $s->score,
        ]);

    return response()->json([
        'candles'          => $candles,
        'volume'           => $volume,
        'vwap'             => $vwapLine,
        'poc'              => $pocLine,
        'high_vol_markers' => $highVolMarkers,
        'setups'           => $setups,
        'stats'            => [
            'avg_volume' => round($avgVol, 0),
            'poc_price'  => $pocPrice,
            'bar_count'  => count($parsed),
        ],
    ]);
});

/* ── Frontend → orderflow data ── */
Route::get('/orderflow/delta', function (\Illuminate\Http\Request $request) {
    $tf     = $request->get('tf', '15m');
    $parsed = array_reverse(array_map(function($b){$d=json_decode($b,true);return is_string($d)?json_decode($d,true):$d;}, Redis::lrange("xauusd:bars:{$tf}", 0, 99)));
    $delta  = array_map(fn($b) => ['time' => $b['time'] ?? 0, 'delta' => $b['delta'] ?? 0], $parsed);
    $cvd    = []; $cum = 0;
    foreach ($parsed as $b) { $cum += ($b['delta'] ?? 0); $cvd[] = ['time' => $b['time'] ?? 0, 'value' => $cum]; }
    return response()->json(compact('delta', 'cvd'));
});

Route::get('/orderflow/imbalance', function (\Illuminate\Http\Request $request) {
    $tf      = $request->get('tf', '15m');
    $parsed  = array_reverse(array_map(function($b){$d=json_decode($b,true);return is_string($d)?json_decode($d,true):$d;}, Redis::lrange("xauusd:bars:{$tf}", 0, 199)));
    $candles = array_map(fn($b) => ['time'=>$b['time']??0,'open'=>$b['open']??0,'high'=>$b['high']??0,'low'=>$b['low']??0,'close'=>$b['close']??0], $parsed);
    return response()->json(['candles' => $candles, 'zones' => []]);
});

Route::get('/orderflow/cvd', function (\Illuminate\Http\Request $request) {
    $tf      = $request->get('tf', '15m');
    $parsed  = array_reverse(array_map(function($b){$d=json_decode($b,true);return is_string($d)?json_decode($d,true):$d;}, Redis::lrange("xauusd:bars:{$tf}", 0, 199)));
    $candles = array_map(fn($b) => ['time'=>$b['time']??0,'open'=>$b['open']??0,'high'=>$b['high']??0,'low'=>$b['low']??0,'close'=>$b['close']??0], $parsed);
    $cvd     = []; $cum = 0;
    foreach ($parsed as $b) { $cum += ($b['delta'] ?? 0); $cvd[] = ['time' => $b['time']??0, 'value' => $cum]; }
    $summary = ['cvd' => $cum, 'divergence' => 'NONE', 'trend' => $cum >= 0 ? 'UP' : 'DOWN'];
    return response()->json(compact('candles', 'cvd', 'summary'));
});

/* ── Setups ── */
Route::get('/setups/{setup}',  [SetupController::class, 'show']);
Route::get('/setups/export',   function () {
    $setups  = \App\Models\Setup::all();
    $headers = ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="setups.csv"'];
    $cb = function () use ($setups) {
        $f = fopen('php://output', 'w');
        fputcsv($f, ['ID','Direction','Timeframe','Entry','SL','TP1','TP2','Score','Status','Created']);
        foreach ($setups as $s) fputcsv($f, [$s->id,$s->direction,$s->timeframe,$s->entry,$s->stop_loss,$s->tp1,$s->tp2,$s->score,$s->status,$s->created_at]);
        fclose($f);
    };
    return response()->stream($cb, 200, $headers);
});

/* ── Alerts & Settings ── */
Route::post('/alerts/test', [AlertController::class,  'test']);
Route::post('/settings',    [SettingsController::class,'update']);
