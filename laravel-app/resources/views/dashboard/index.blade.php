@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')

{{-- Row 1 — Active Setup + Metrics --}}
<div class="row g-3 mb-3">

    {{-- Active Setup --}}
    <div class="col-12 col-xl-4">
        <div class="card-panel h-100">
            <div class="card-header-custom">
                <span><i class="bi bi-lightning-charge-fill me-1" style="color:var(--gold)"></i>Active Setup</span>
                <span class="signal-badge {{ $activeSetup ? strtolower($activeSetup->direction) : 'wait' }}" id="active-signal-badge">
                    @if($activeSetup)
                        <i class="bi bi-arrow-{{ $activeSetup->direction === 'BUY' ? 'up' : 'down' }}-circle-fill"></i> {{ $activeSetup->direction }}
                    @else
                        <i class="bi bi-hourglass-split"></i> Waiting
                    @endif
                </span>
            </div>
            <div class="p-3">
                @if($activeSetup)
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="confluence-ring">
                        <svg width="80" height="80" viewBox="0 0 80 80">
                            <circle cx="40" cy="40" r="32" fill="none" stroke="var(--bg-panel)" stroke-width="7"/>
                            <circle cx="40" cy="40" r="32" fill="none"
                                stroke="{{ $activeSetup->direction === 'BUY' ? 'var(--green-bull)' : 'var(--red-bear)' }}"
                                stroke-width="7"
                                stroke-dasharray="{{ round($activeSetup->score / 100 * 201.1, 1) }} 201.1"
                                stroke-linecap="round"/>
                        </svg>
                        <div class="score-text">
                            <span style="color:{{ $activeSetup->direction === 'BUY' ? 'var(--green-bull)' : 'var(--red-bear)' }}">{{ $activeSetup->score }}%</span>
                            <span>Score</span>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="row g-2">
                            <div class="col-6"><div class="metric-pill"><div class="label">Entry</div><div class="value text-warning">{{ number_format($activeSetup->entry, 2) }}</div></div></div>
                            <div class="col-6"><div class="metric-pill"><div class="label">Stop Loss</div><div class="value text-danger">{{ number_format($activeSetup->stop_loss, 2) }}</div></div></div>
                            <div class="col-6"><div class="metric-pill"><div class="label">TP1</div><div class="value text-success">{{ number_format($activeSetup->tp1, 2) }}</div></div></div>
                            <div class="col-6"><div class="metric-pill"><div class="label">TP2</div><div class="value text-success">{{ number_format($activeSetup->tp2, 2) }}</div></div></div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap" style="font-size:.72rem">
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-arrows-expand me-1"></i>Risk: {{ number_format(abs($activeSetup->entry - $activeSetup->stop_loss), 1) }} pts</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-diagram-3 me-1"></i>RR: {{ number_format(abs($activeSetup->tp1 - $activeSetup->entry) / max(0.01, abs($activeSetup->entry - $activeSetup->stop_loss)), 1) }}:1</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-clock me-1"></i>{{ $activeSetup->created_at->diffForHumans() }}</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-bar-chart me-1"></i>{{ strtoupper($activeSetup->timeframe) }}</span>
                </div>
                @else
                <div class="text-center py-4" style="color:var(--text-muted-custom)">
                    <i class="bi bi-radar fs-2 d-block mb-2" style="color:var(--gold);opacity:.4"></i>
                    <div style="font-size:.85rem">No active setup</div>
                    <div style="font-size:.72rem;margin-top:.25rem">Engine is scanning orderflow…</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Orderflow Metrics --}}
    <div class="col-12 col-xl-8">
        <div class="card-panel h-100">
            <div class="card-header-custom">
                <span><i class="bi bi-activity me-1" style="color:var(--gold)"></i>Live Orderflow Metrics</span>
                <span style="font-size:.65rem;color:var(--text-muted-custom)" id="metrics-updated">—</span>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    @php
                        $metricDefs = [
                            ['id'=>'m-cvd',        'label'=>'CVD',           'val'=>$metrics['cvd']??'—',              'color'=>'var(--gold)'],
                            ['id'=>'m-delta',      'label'=>'Bar Delta',      'val'=>$metrics['delta']??'—',            'color'=>($metrics['delta']??0)>=0?'var(--green-bull)':'var(--red-bear)'],
                            ['id'=>'m-buy-vol',    'label'=>'Buy Vol',        'val'=>$metrics['buy_volume']??'—',       'color'=>'var(--green-bull)'],
                            ['id'=>'m-sell-vol',   'label'=>'Sell Vol',       'val'=>$metrics['sell_volume']??'—',      'color'=>'var(--red-bear)'],
                            ['id'=>'m-absorption', 'label'=>'Absorption',     'val'=>$metrics['absorption']??'—',       'color'=>'var(--gold)'],
                            ['id'=>'m-imbalance',  'label'=>'Imbalance',      'val'=>$metrics['imbalance']??'—',        'color'=>'var(--gold)'],
                            ['id'=>'m-poc',        'label'=>'POC',            'val'=>$metrics['poc']??'—',              'color'=>'var(--gold)'],
                            ['id'=>'m-vwap',       'label'=>'VWAP',           'val'=>$metrics['vwap']??'—',             'color'=>'#a78bfa'],
                            ['id'=>'m-exhaustion', 'label'=>'Exhaustion',     'val'=>$metrics['exhaustion']??'NONE',    'color'=>'var(--text-muted-custom)'],
                            ['id'=>'m-stacked',    'label'=>'Stacked Imb',    'val'=>$metrics['stacked_imbalance']??'NO','color'=>'var(--text-muted-custom)'],
                        ];
                    @endphp
                    @foreach($metricDefs as $m)
                    <div class="col-6 col-md-4 col-lg-2-4" style="flex:0 0 20%;max-width:20%">
                        <div class="metric-pill text-center">
                            <div class="label mb-1">{{ $m['label'] }}</div>
                            <div class="value" id="{{ $m['id'] }}" style="color:{{ $m['color'] }}">{{ $m['val'] }}</div>
                            @if(in_array($m['id'], ['m-buy-vol','m-sell-vol','m-cvd']))
                            <div class="mt-1">
                                <div class="delta-bar-wrap">
                                    <div class="{{ $m['id'] === 'm-sell-vol' ? 'delta-bar-neg' : 'delta-bar-pos' }}" id="{{ $m['id'] }}-bar" style="width:{{ $metrics[$m['id']==='m-buy-vol'?'buy_pct':($m['id']==='m-sell-vol'?'sell_pct':'cvd_pct')]??0 }}%"></div>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Row 2 — Chart + Confluence --}}
<div class="row g-3 mb-3">
    <div class="col-12 col-xl-8">
        <div class="card-panel">
            <div class="card-header-custom">
                <span><i class="bi bi-graph-up me-1" style="color:var(--gold)"></i>XAUUSD Chart</span>
                <div class="d-flex align-items-center gap-2">
                    <div class="btn-group btn-group-sm" id="tf-buttons">
                        @foreach(['5m','15m','30m','1h','4h'] as $tf)
                        <button type="button" onclick="switchTimeframe('{{ $tf }}')"
                                class="btn btn-sm {{ $tf==='15m'?'active':'' }}"
                                style="background:var(--bg-panel);border-color:var(--bg-border);color:{{ $tf==='15m'?'var(--gold)':'#8d97b0' }};font-size:.7rem;padding:.2rem .55rem">{{ $tf }}</button>
                        @endforeach
                    </div>
                    <select id="chart-type" onchange="switchChartType(this.value)"
                            class="form-select form-select-sm"
                            style="width:auto;background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;font-size:.7rem">
                        <option value="candlestick">Candles</option>
                        <option value="bar">OHLC Bars</option>
                        <option value="area">Area</option>
                    </select>
                </div>
            </div>
            <div style="position:relative">
                <div id="tv-chart" style="height:380px;width:100%"></div>
                <div id="chart-legend" style="position:absolute;top:8px;left:12px;z-index:10;font-size:.72rem;font-family:'JetBrains Mono',monospace;pointer-events:none">
                    <div><span style="color:#8d97b0">XAUUSD &bull; <span id="legend-tf">15m</span></span>
                    <span id="legend-ohlc" style="color:#aaa;margin-left:.5rem"></span></div>
                    <div id="chart-stats" style="margin-top:.2rem;font-size:.65rem"></div>
                </div>
            </div>
            <div id="volume-chart" style="height:80px;border-top:1px solid var(--bg-border)"></div>
        </div>
    </div>

    {{-- Confluence Signals --}}
    <div class="col-12 col-xl-4">
        <div class="card-panel h-100">
            <div class="card-header-custom">
                <span><i class="bi bi-puzzle-fill me-1" style="color:var(--gold)"></i>Confluence Signals</span>
                <span style="font-size:.65rem" id="conf-timeframe">15m</span>
            </div>
            <div class="p-3">
                @foreach($confluenceSignals as $sig)
                <div class="d-flex align-items-center justify-content-between py-2"
                     style="border-bottom:1px solid {{ $loop->last?'transparent':'var(--bg-border)' }}">
                    <div class="d-flex align-items-center gap-2">
                        @php $ic = match($sig['status']) { 'bullish','buy'=>'bi-check-circle-fill text-success', 'bearish','sell'=>'bi-check-circle-fill text-danger', default=>'bi-circle text-secondary' }; @endphp
                        <i class="bi {{ $ic }}" style="font-size:.9rem"></i>
                        <div>
                            <div style="font-size:.78rem;font-weight:500">{{ $sig['name'] }}</div>
                            <div style="font-size:.65rem;color:var(--text-muted-custom)">{{ $sig['detail'] }}</div>
                        </div>
                    </div>
                    @php
                        $isBull = in_array($sig['status'], ['bullish','buy']);
                        $isBear = in_array($sig['status'], ['bearish','sell']);
                        $bg = $isBull ? 'rgba(0,192,118,.15)' : ($isBear ? 'rgba(255,59,92,.15)' : 'var(--bg-panel)');
                        $fc = $isBull ? 'var(--green-bull)'    : ($isBear ? 'var(--red-bear)'    : '#6c7793');
                        $bc = $isBull ? 'rgba(0,192,118,.3)'   : ($isBear ? 'rgba(255,59,92,.3)' : 'var(--bg-border)');
                    @endphp
                    <span class="badge" style="font-size:.62rem;background:{{ $bg }};color:{{ $fc }};border:1px solid {{ $bc }}">
                        {{ strtoupper($sig['status']) }}
                    </span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- Row 3 — Orderflow Table + Recent Setups --}}
<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card-panel">
            <div class="card-header-custom">
                <span><i class="bi bi-table me-1" style="color:var(--gold)"></i>Recent Bars — Orderflow</span>
                <span style="font-size:.65rem;color:var(--text-muted-custom)">Last 20 bars</span>
            </div>
            <div style="overflow-x:auto">
                <table class="table table-dark table-hover of-table mb-0">
                    <thead><tr><th>Time</th><th>Open</th><th>Close</th><th>Delta</th><th>Buy Vol</th><th>Sell Vol</th><th>Imbalance</th><th>Signal</th></tr></thead>
                    <tbody id="of-table-body">
                        @forelse($recentBars as $bar)
                        <tr>
                            <td style="color:#8d97b0">{{ $bar['time'] ?? '—' }}</td>
                            <td>{{ number_format($bar['open'] ?? 0, 2) }}</td>
                            <td style="color:{{ ($bar['close']??0) >= ($bar['open']??0) ? 'var(--green-bull)' : 'var(--red-bear)' }}">{{ number_format($bar['close'] ?? 0, 2) }}</td>
                            <td style="color:{{ ($bar['delta']??0) >= 0 ? 'var(--green-bull)' : 'var(--red-bear)' }}">{{ ($bar['delta']??0) >= 0 ? '+' : '' }}{{ $bar['delta'] ?? 0 }}</td>
                            <td class="text-success">{{ $bar['buy_vol'] ?? '—' }}</td>
                            <td class="text-danger">{{ $bar['sell_vol'] ?? '—' }}</td>
                            <td>
                                @if(($bar['imbalance']??'') === 'BULLISH') <span style="color:var(--green-bull);font-size:.7rem">▲ BULL</span>
                                @elseif(($bar['imbalance']??'') === 'BEARISH') <span style="color:var(--red-bear);font-size:.7rem">▼ BEAR</span>
                                @else <span style="color:#555;font-size:.7rem">—</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($bar['signal'])) <span class="signal-badge {{ strtolower($bar['signal']) }}" style="font-size:.62rem;padding:.15rem .4rem">{{ $bar['signal'] }}</span>
                                @else <span style="color:#555">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="8" class="text-center py-4" style="color:var(--text-muted-custom)"><i class="bi bi-hourglass-split me-1"></i> Waiting for bar data…</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card-panel">
            <div class="card-header-custom">
                <span><i class="bi bi-clock-history me-1" style="color:var(--gold)"></i>Recent Setups</span>
                <a href="{{ route('setups.index') }}" style="font-size:.7rem;color:var(--gold);text-decoration:none">View All <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="p-2">
                @forelse($recentSetups as $setup)
                <div class="d-flex align-items-center gap-2 p-2 rounded mb-1" style="background:var(--bg-panel);border:1px solid var(--bg-border)">
                    <span class="signal-badge {{ strtolower($setup->direction) }}" style="font-size:.65rem;flex-shrink:0">{{ $setup->direction }}</span>
                    <div class="flex-grow-1" style="min-width:0">
                        <div style="font-size:.8rem;font-family:'JetBrains Mono',monospace">E: {{ number_format($setup->entry,2) }} &nbsp; SL: <span class="text-danger">{{ number_format($setup->stop_loss,2) }}</span></div>
                        <div style="font-size:.65rem;color:var(--text-muted-custom)">{{ $setup->created_at->format('d M H:i') }} &bull; {{ strtoupper($setup->timeframe) }}</div>
                    </div>
                    <div class="text-end" style="flex-shrink:0">
                        <div style="font-size:.85rem;font-weight:700;color:{{ $setup->score>=70?'var(--green-bull)':($setup->score>=50?'var(--gold)':'var(--red-bear)') }}">{{ $setup->score }}%</div>
                        <div style="font-size:.6rem;color:var(--text-muted-custom)">score</div>
                    </div>
                    @php $sc=['active'=>'var(--green-bull)','triggered'=>'var(--gold)','closed'=>'#555','invalidated'=>'var(--red-bear)']; @endphp
                    <span style="font-size:.62rem;color:{{ $sc[$setup->status]??'#555' }}">● {{ ucfirst($setup->status) }}</span>
                </div>
                @empty
                <div class="text-center py-4" style="color:var(--text-muted-custom);font-size:.82rem">
                    <i class="bi bi-inbox d-block fs-3 mb-2" style="opacity:.3"></i>No setups yet
                </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
let candleSeries, volumeSeries, mainChart, volChart;
let currentTf = '15m';

function initCharts() {
    const opts = {
        layout: { background: { color: 'transparent' }, textColor: '#8d97b0' },
        grid: { vertLines: { color: 'rgba(37,42,56,.8)' }, horzLines: { color: 'rgba(37,42,56,.8)' } },
        rightPriceScale: { borderColor: '#252a38' },
        timeScale: { borderColor: '#252a38', timeVisible: true, secondsVisible: false },
    };

    mainChart = LightweightCharts.createChart(document.getElementById('tv-chart'), { ...opts, height: 380 });
    candleSeries = mainChart.addCandlestickSeries({
        upColor: '#00c076', downColor: '#ff3b5c',
        borderUpColor: '#00c076', borderDownColor: '#ff3b5c',
        wickUpColor: 'rgba(0,192,118,.6)', wickDownColor: 'rgba(255,59,92,.6)',
    });
    window.vwapSeries = mainChart.addLineSeries({ color: '#a78bfa', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'VWAP' });
    window.pocSeries  = mainChart.addLineSeries({ color: '#f5c842', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dotted, title: 'POC' });

    volChart = LightweightCharts.createChart(document.getElementById('volume-chart'), { ...opts, height: 80 });
    volumeSeries = volChart.addHistogramSeries({ color: 'rgba(245,200,66,.4)', priceFormat: { type: 'volume' }, priceScaleId: '' });
    volChart.priceScale('').applyOptions({ scaleMargins: { top: 0.1, bottom: 0 } });

    mainChart.timeScale().subscribeVisibleLogicalRangeChange(r => { if (r) volChart.timeScale().setVisibleLogicalRange(r); });
    volChart.timeScale().subscribeVisibleLogicalRangeChange(r => { if (r) mainChart.timeScale().setVisibleLogicalRange(r); });

    mainChart.subscribeCrosshairMove(param => {
        if (!param.time || !param.seriesData.size) return;
        const d = param.seriesData.get(candleSeries);
        if (d) document.getElementById('legend-ohlc').textContent = `O:${d.open.toFixed(2)}  H:${d.high.toFixed(2)}  L:${d.low.toFixed(2)}  C:${d.close.toFixed(2)}`;
    });

    loadChartData(currentTf);
}

function loadChartData(tf) {
    fetch(`/api/chart-data?tf=${tf}`)
        .then(r => r.json())
        .then(d => {
            if (d.candles)  candleSeries.setData(d.candles);
            if (d.volume)   volumeSeries.setData(d.volume);
            if (d.vwap)     vwapSeries.setData(d.vwap);
            if (d.poc)      pocSeries.setData(d.poc);

            // Merge high-volume markers + setup markers, sorted by time
            const hvMarkers = (d.high_vol_markers || []);
            const setupMarkers = (d.setups || []).map(s => ({
                time:     s.time,
                position: s.direction === 'BUY' ? 'belowBar' : 'aboveBar',
                color:    s.direction === 'BUY' ? '#00c076' : '#ff3b5c',
                shape:    s.direction === 'BUY' ? 'arrowUp' : 'arrowDown',
                text:     `${s.direction} ${s.score}%`,
            }));
            const allMarkers = [...hvMarkers, ...setupMarkers]
                .sort((a, b) => a.time - b.time);
            candleSeries.setMarkers(allMarkers);

            // Update chart stats legend
            if (d.stats) {
                const statsEl = document.getElementById('chart-stats');
                if (statsEl) statsEl.innerHTML =
                    `<span style="color:#8d97b0">POC: <b style="color:#f5c842">${d.stats.poc_price?.toFixed(2)}</b></span>` +
                    `<span style="color:#8d97b0;margin-left:.75rem">Avg Vol: <b style="color:#a78bfa">${Number(d.stats.avg_volume).toLocaleString()}</b></span>` +
                    `<span style="color:#8d97b0;margin-left:.75rem"><b style="color:#00c076">■</b>/<b style="color:#ff3b5c">■</b> = High Vol bar</span>`;
            }
        })
        .catch(() => seedDemoChart());
}

function seedDemoChart() {
    const now = Math.floor(Date.now() / 1000);
    const candles = [], volume = [], hvMarkers = [];
    let price = 3320;
    const baseVol = 150;
    for (let i = 100; i >= 0; i--) {
        const t = now - i * 900, o = price;
        const c = o + (Math.random() - 0.48) * 8;
        const h = Math.max(o,c) + Math.random()*3, l = Math.min(o,c) - Math.random()*3;
        // ~10% of bars are high-volume (spike bars)
        const isHighVol = Math.random() < 0.10;
        const vol = isHighVol
            ? Math.floor(baseVol * 2.5 + Math.random() * 200)
            : Math.floor(baseVol * 0.5 + Math.random() * baseVol);
        const isBull = c >= o;
        candles.push({ time: t, open: +o.toFixed(2), high: +h.toFixed(2), low: +l.toFixed(2), close: +c.toFixed(2) });
        volume.push({
            time: t, value: vol,
            color: isHighVol
                ? (isBull ? 'rgba(0,192,118,.95)' : 'rgba(255,59,92,.95)')
                : (isBull ? 'rgba(0,192,118,.35)' : 'rgba(255,59,92,.35)'),
        });
        if (isHighVol) {
            hvMarkers.push({
                time: t,
                position: isBull ? 'belowBar' : 'aboveBar',
                color: isBull ? '#00c076' : '#ff3b5c',
                shape: 'square',
                text: 'HV ' + vol.toLocaleString(),
            });
        }
        price = c;
    }
    candleSeries.setData(candles);
    volumeSeries.setData(volume);
    candleSeries.setMarkers(hvMarkers);
    document.getElementById('live-price').textContent = price.toFixed(2);
    const statsEl = document.getElementById('chart-stats');
    if (statsEl) statsEl.innerHTML =
        `<span style="color:#8d97b0">POC: <b style="color:#f5c842">${price.toFixed(2)}</b></span>` +
        `<span style="color:#8d97b0;margin-left:.75rem">Avg Vol: <b style="color:#a78bfa">${baseVol}</b></span>` +
        `<span style="color:#8d97b0;margin-left:.75rem"><b style="color:#00c076">■</b>/<b style="color:#ff3b5c">■</b> = High Vol bar (demo)</span>`;
}

document.addEventListener('DOMContentLoaded', initCharts);

function switchTimeframe(tf) {
    currentTf = tf;
    document.getElementById('legend-tf').textContent = tf;
    document.getElementById('conf-timeframe').textContent = tf;
    document.querySelectorAll('#tf-buttons .btn').forEach(b => {
        const active = b.textContent.trim() === tf;
        b.style.color = active ? 'var(--gold)' : '#8d97b0';
        b.style.borderColor = active ? 'var(--gold)' : 'var(--bg-border)';
    });
    loadChartData(tf);
}

function switchChartType(type) {
    mainChart.removeSeries(candleSeries);
    if (type === 'candlestick') candleSeries = mainChart.addCandlestickSeries({ upColor:'#00c076', downColor:'#ff3b5c', borderUpColor:'#00c076', borderDownColor:'#ff3b5c' });
    else if (type === 'bar')    candleSeries = mainChart.addBarSeries({ upColor:'#00c076', downColor:'#ff3b5c' });
    else                        candleSeries = mainChart.addAreaSeries({ lineColor:'#f5c842', topColor:'rgba(245,200,66,.2)', bottomColor:'transparent' });
    loadChartData(currentTf);
}

Echo.channel('xauusd.metrics').listen('.metrics.updated', (e) => {
    const m = e.metrics;
    const upd = (id, val, colorFn) => { const el = document.getElementById(id); if (el) { el.textContent = val; if (colorFn) el.style.color = colorFn(val); } };
    upd('m-cvd',        m.cvd,        v => v >= 0 ? '#00c076' : '#ff3b5c');
    upd('m-delta',      (m.delta>=0?'+':'')+m.delta, v => parseFloat(v)>=0?'#00c076':'#ff3b5c');
    upd('m-buy-vol',    m.buy_volume,  null);
    upd('m-sell-vol',   m.sell_volume, null);
    upd('m-absorption', m.absorption,  v => v==='BULL'?'#00c076':(v==='BEAR'?'#ff3b5c':'var(--gold)'));
    upd('m-imbalance',  m.imbalance,   v => v==='BULLISH'?'#00c076':(v==='BEARISH'?'#ff3b5c':'var(--gold)'));
    upd('m-poc',        m.poc,         null);
    upd('m-vwap',       m.vwap,        null);
    upd('m-exhaustion', m.exhaustion,  v => v!=='NONE'?'#ff3b5c':'var(--text-muted-custom)');
    upd('m-stacked',    m.stacked_imbalance, v => v!=='NO'?'var(--gold)':'var(--text-muted-custom)');
    const b = (id,w) => { const el=document.getElementById(id); if(el) el.style.width=Math.min(100,w||0)+'%'; };
    b('m-buy-vol-bar', m.buy_pct); b('m-sell-vol-bar', m.sell_pct); b('m-cvd-bar', Math.abs(m.cvd_pct||0));
    document.getElementById('metrics-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
});

Echo.channel('xauusd.candles').listen('.candle.closed', (e) => {
    if (e.tf === currentTf) { candleSeries.update(e.candle); volumeSeries.update(e.volume); }
});
</script>
@endpush
