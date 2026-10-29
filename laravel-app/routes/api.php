<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Redis;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\SettingsController;
use App\Events\TickReceived;
use App\Events\MetricsUpdated;

Route::get('/system-status', function (\App\Services\SystemMonitorService $monitor) {
    return response()->json($monitor->status())->header('Cache-Control', 'no-store');
});

Route::get('/price-zones', function (\App\Services\PriceZoneService $zones) {
    return response()->json($zones->status())->header('Cache-Control', 'no-store');
});
Route::get('/zone-study',[\App\Http\Controllers\ZoneStudyController::class,'data']);
Route::get('/variable-opportunities',[\App\Http\Controllers\VariableOpportunityController::class,'data']);

Route::get('/zone-alerts', function (\Illuminate\Http\Request $request, \App\Services\PriceZoneService $zones) {
    $data = $request->validate(['after' => 'sometimes|integer|min:0']);
    $query = \App\Models\AlertLog::where('channel', 'browser')->where('type', 'like', 'ZONE_%')
        ->whereIn('price_zone_id', $zones->scope()->select('id'));
    $latest = (int) (clone $query)->max('id');
    if (!isset($data['after']) || $data['after'] > $latest) {
        return response()->json(['cursor' => $latest, 'alerts' => []])->header('Cache-Control', 'no-store');
    }
    $alerts = $query->where('id', '>', $data['after'])->orderBy('id')->limit(50)
        ->get(['id', 'message', 'created_at', 'price_zone_id']);
    return response()->json(['cursor' => $alerts->last()?->id ?? (int) $data['after'], 'alerts' => $alerts])
        ->header('Cache-Control', 'no-store');
});

Route::prefix('clusterdelta')->middleware('throttle:120,1')->group(function () {
    Route::post('/history', [\App\Http\Controllers\BrokerHistoryController::class, 'storeHistory']);
    Route::post('/footprints', [\App\Http\Controllers\FootprintController::class, 'storeFootprint']);
    Route::get('/instruments', [\App\Http\Controllers\ClusterDeltaController::class, 'instruments']);
    Route::post('/bars', [\App\Http\Controllers\ClusterDeltaController::class, 'store']);
    Route::get('/bars', [\App\Http\Controllers\ClusterDeltaController::class, 'bars']);
});

Route::get('/orderflow/footprint', [\App\Http\Controllers\FootprintController::class, 'showFootprint']);

/* ── Python microservice → tick data ── */
Route::post('/ticks', function (\Illuminate\Http\Request $request) {
    abort_if(config('orderflow.connector') === 'clusterdelta', 409, 'cTrader ingestion disabled.');
    $request->validate(['bid' => 'required|numeric', 'ask' => 'required|numeric']);
    Redis::set('xauusd:tick:latest', json_encode($request->all()));
    broadcast(new TickReceived($request->all()))->toOthers();
    return response()->json(['ok' => true]);
})->middleware('throttle:600,1');

/* ── Python microservice → bar data ── */
Route::post('/bars', function (\Illuminate\Http\Request $request) {
    abort_if(config('orderflow.connector') === 'clusterdelta', 409, 'cTrader ingestion disabled.');
    $request->validate(['tf' => 'required|string', 'bars' => 'required|array']);
    foreach ($request->bars as $bar) {
        Redis::lpush("xauusd:bars:{$request->tf}", json_encode($bar));
        Redis::ltrim("xauusd:bars:{$request->tf}", 0, 499);
    }
    return response()->json(['ok' => true]);
})->middleware('throttle:300,1');

/* ── Python microservice → metrics ── */
Route::post('/metrics', function (\Illuminate\Http\Request $request) {
    abort_if(config('orderflow.connector') === 'clusterdelta', 409, 'cTrader ingestion disabled.');
    Redis::set('xauusd:metrics:latest', json_encode($request->all()));
    broadcast(new MetricsUpdated($request->all()))->toOthers();
    return response()->json(['ok' => true]);
})->middleware('throttle:120,1');

/* ── Frontend → chart candles ── */
Route::get('/chart-data', function (\Illuminate\Http\Request $request) {
    $tf     = $request->get('tf', '15m');
    $parsed = app(\App\Services\FootprintService::class)->bars($tf,200);

    if (empty($parsed)) {
        return response()->json(['price_source'=>'GC_futures','candles'=>[],'volume'=>[],'setups'=>[],'vwap'=>[],'poc'=>[],'high_vol_markers'=>[],
            'stats'=>['price_source'=>'GC_futures','reference_price_source'=>'GC_futures','bar_count'=>0,
                'price_only_bars'=>0,'last_bar_time'=>null,'last_received'=>null]]);
    }

    // ── Volume stats for high-volume detection ──────────────────────────
    $volumes = array_map(fn($b) => $b['buy_vol'] + $b['sell_vol'], array_filter($parsed, fn($b)=>isset($b['buy_vol'],$b['sell_vol'])));
    $avgVol  = count($volumes) > 0 ? array_sum($volumes) / count($volumes) : 0;
    $volMultiplier = (float) $request->get('vol_mult', 2.0); // bars > 2× avg = high volume

    // Use the same rolling native-GC estimates as the metric cards.
    $references = app(\App\Services\BrokerReferenceService::class)->series($parsed, 20);
    $vwapLine = $references['vwap'];
    $pocLine = $references['poc'];
    $pocPrice = $references['latest']['poc'];

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
        if (isset($b['buy_vol'], $b['sell_vol'])) $volume[] = [
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

    $firstCandle=(int)($candles[0]['time']??0);$lastCandle=(int)($candles[array_key_last($candles)]['time']??0);
    $setups = \App\Models\Setup::where('source',config('orderflow.connector'))
        ->where('instrument',config('clusterdelta.instrument','GC'))->where('timeframe', $tf)
        ->whereBetween('candle_time',[$firstCandle,$lastCandle])
        ->get(['id','direction','entry','score','candle_time','strategy_version','confluence_detail'])
        ->map(fn($s) => [
            'id'        => $s->id,
            'time'      => $s->candle_time,
            'direction' => $s->direction,
            'entry'     => $s->entry,
            'score'     => $s->score,
            'strategy'  => $s->strategy_version,
            'mode'      => data_get(collect($s->confluence_detail)->firstWhere('key', 'h1_trend'), 'status'),
        ]);

    return response()->json([
        'price_source'     => 'GC_futures',
        'candles'          => $candles,
        'volume'           => $volume,
        'vwap'             => $vwapLine,
        'poc'              => $pocLine,
        'high_vol_markers' => $highVolMarkers,
        'setups'           => $setups,
        'stats'            => [
            'avg_volume' => round($avgVol, 0),
            'poc_price'  => $pocPrice,
            'vwap_price' => $references['latest']['vwap'],
            'reference_lookback' => $references['lookback'],
            'reference_window_bars' => $references['window_bars'],
            'reference_price_source' => 'GC_futures',
            'price_source' => 'GC_futures',
            'vwap_method' => 'rolling_typical_price_volume_weighted',
            'poc_method' => 'rolling_close_volume_proxy',
            'bar_count'  => count($parsed),
            'price_only_bars' => count(array_filter($parsed, fn($b)=>!isset($b['buy_vol'],$b['sell_vol']))),
            'last_bar_time' => $parsed[count($parsed) - 1]['time'],
            'last_bar_closed' => $parsed[count($parsed) - 1]['closed'] ?? true,
            'last_received' => $parsed[count($parsed) - 1]['received_at'] ?? null,
            'candle_source' => $parsed[count($parsed) - 1]['candle_source'] ?? 'native',
        ],
    ]);
});

/* ── Frontend → orderflow data ── */
Route::get('/orderflow/delta', function (\Illuminate\Http\Request $request) {
    $tf     = $request->get('tf', '15m');
    $parsed = app(\App\Services\FootprintService::class)->bars($tf,100);
    $delta  = array_map(fn($b) => ['time' => $b['time'] ?? 0, 'delta' => $b['delta'] ?? 0], $parsed);
    $cvd    = []; $cum = 0;
    foreach ($parsed as $b) { $cum += ($b['delta'] ?? 0); $cvd[] = ['time' => $b['time'] ?? 0, 'value' => $cum]; }
    return response()->json(compact('delta', 'cvd')+['price_source'=>'GC_futures']);
});

Route::get('/orderflow/imbalance', function (\Illuminate\Http\Request $request, \App\Services\FootprintService $footprint) {
    $data = $request->validate(['tf' => ['sometimes', \Illuminate\Validation\Rule::in(config('orderflow.timeframes'))]]);
    return response()->json($footprint->imbalanceSnapshot($data['tf'] ?? '15m'))->header('Cache-Control', 'no-store');
});

Route::get('/orderflow/cvd', function (\Illuminate\Http\Request $request) {
    $tf      = $request->get('tf', '15m');
    $parsed  = app(\App\Services\FootprintService::class)->bars($tf,200);
    $candles = array_map(fn($b) => ['time'=>$b['time']??0,'open'=>$b['open']??0,'high'=>$b['high']??0,'low'=>$b['low']??0,'close'=>$b['close']??0], $parsed);
    $cvd     = []; $cum = 0;
    foreach ($parsed as $b) { $cum += ($b['delta'] ?? 0); $cvd[] = ['time' => $b['time']??0, 'value' => $cum]; }
    $summary = ['cvd' => $cum, 'divergence' => 'NONE', 'trend' => $cum >= 0 ? 'UP' : 'DOWN'];
    return response()->json(compact('candles', 'cvd', 'summary')+['price_source'=>'GC_futures']);
});

/* ── Setups ── */
Route::get('/setups/{setup}/focus', [SetupController::class, 'focus'])->whereNumber('setup');
Route::get('/setups/status', function (\App\Services\SetupEngineService $engine) {
    return response()->json($engine->status())->header('Cache-Control', 'no-store');
});
Route::get('/setups/{setup}',  [SetupController::class, 'show'])->whereNumber('setup');
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

Route::get('/feed-status', function (\Illuminate\Http\Request $request, \App\Services\FootprintService $footprint) {
    $data = $request->validate(['tf' => ['sometimes', \Illuminate\Validation\Rule::in(['1m', '5m', '15m', '30m', '1h', '4h', '1d'])]]);
    $tf = $data['tf'] ?? $footprint->activeTimeframe();
    $bars=$footprint->bars($tf,1);$bar=$bars?end($bars):null;
    $fresh = $bar && now()->timestamp - ($bar['received_at'] ?? 0) < 60;
    try { $brokerQuote=app(\App\Services\MarketDataService::class)->latestBrokerQuote(); }
    catch(\Throwable) { $brokerQuote=null; }
    return response()->json(['source' => 'clusterdelta_footprint','price_source'=>'GC_futures',
        'instrument' => config('clusterdelta.instrument'), 'connected' => (bool) $fresh,
        'timeframe' => $tf,
        'has_data' => $bar !== null,
        'status' => !$bar ? 'waiting_for_first_bar' : ($fresh ? 'receiving' : 'stale'),
        'last_received' => $bar['received_at'] ?? null, 'price' => $bar['close'] ?? null,
        'broker_quote'=>$brokerQuote ? array_intersect_key($brokerQuote,array_flip(['close','time','timeframe','received_at','price_source'])) : null,
        'metrics' => $bar ? app(\App\Services\OrderflowMetricsService::class)->computeFootprint($tf) : null]);
});

Route::get('/orderflow/participation', function (\Illuminate\Http\Request $request) {
    $data = $request->validate([
        'instrument' => ['sometimes', 'string', 'max:32'],
        'tf' => ['sometimes', \Illuminate\Validation\Rule::in(['1m','5m','15m','30m','1h','4h','1d'])],
        'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
    ]);
    $instrument = $data['instrument'] ?? config('clusterdelta.instrument', 'GC');
    $timeframe = $data['tf'] ?? '5m';
    $rows = \App\Models\MarketParticipationSnapshot::where('source', config('orderflow.connector'))
        ->where('instrument', $instrument)->where('timeframe', $timeframe)
        ->orderByDesc('candle_time')->limit($data['limit'] ?? 100)->get();
    return response()->json(['instrument'=>$instrument,'timeframe'=>$timeframe,
        'classification'=>['increased_ratio'=>config('orderflow.participation_increased_ratio'),
            'decreased_ratio'=>config('orderflow.participation_decreased_ratio'),
            'baseline'=>'rolling median of prior completed bars'],
        'rows'=>$rows]);
});
