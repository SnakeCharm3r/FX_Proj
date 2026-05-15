<?php

return [
    'connector'               => env('ORDERFLOW_CONNECTOR', 'demo'),
    'timeframes'              => ['5m', '15m', '30m', '1h', '4h'],
    'min_confluence_score'    => (int)   env('OF_MIN_CONFLUENCE_SCORE',    60),
    'delta_threshold'         => (int)   env('OF_DELTA_THRESHOLD',        500),
    'cvd_divergence_threshold'=> (int)   env('OF_CVD_DIVERGENCE_THRESHOLD',200),
    'imbalance_ratio'         => (float) env('OF_IMBALANCE_RATIO',         3.0),
    'absorption_threshold'    => (float) env('OF_ABSORPTION_THRESHOLD',    0.3),
    'stacked_imbalance_count' => (int)   env('OF_STACKED_IMBALANCE_COUNT',   3),
    'exhaustion_lookback'     => (int)   env('OF_EXHAUSTION_LOOKBACK',      10),
    'vwap_deviation_pips'     => (int)   env('OF_VWAP_DEVIATION_PIPS',     150),

    'signal_weights' => [
        'cvd_divergence'    => 20,
        'delta_imbalance'   => 20,
        'absorption'        => 15,
        'stacked_imbalance' => 20,
        'exhaustion'        => 10,
        'vwap_deviation'    => 10,
        'poc_rejection'     => 5,
    ],

    'sl_buffer_pips' => (float) env('OF_SL_BUFFER_PIPS',  15),
    'tp1_rr_ratio'   => (float) env('OF_TP1_RR_RATIO',   1.5),
    'tp2_rr_ratio'   => (float) env('OF_TP2_RR_RATIO',   3.0),

    'redis_keys' => [
        'tick_latest'       => 'xauusd:tick:latest',
        'metrics_latest'    => 'xauusd:metrics:latest',
        'confluence_latest' => 'xauusd:confluence:latest',
        'bars_prefix'       => 'xauusd:bars:',
    ],
];
