@extends('layouts.app')
@section('title', 'Dashboard')
@include('components.setup-card-styles')

@section('content')

{{-- Row 1 — Active Setup + Metrics --}}
<div class="row g-3 mb-3">

    {{-- Active Setup --}}
    <div class="col-12 col-xl-4">
        @include('components.setup-card', ['setupCardTitle' => request()->filled('setup_id') ? 'Selected setup #'.$activeSetup?->id : 'Active Setup'])
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
                            ['id'=>'m-absorption', 'label'=>'Absorption sequence','val'=>$metrics['absorption']??'—',       'color'=>'var(--gold)'],
                            ['id'=>'m-imbalance',  'label'=>'Bar vol ratio',  'val'=>$metrics['imbalance']??'—',        'color'=>'var(--gold)'],
                            ['id'=>'m-poc',        'label'=>'POC proxy · 20 bars','val'=>$metrics['poc']??'—',           'color'=>'var(--gold)'],
                            ['id'=>'m-vwap',       'label'=>'VWAP proxy · 20 bars','val'=>$metrics['vwap']??'—',        'color'=>'#a78bfa'],
                            ['id'=>'m-exhaustion', 'label'=>'Exhaustion proxy','val'=>$metrics['exhaustion']??'NONE',    'color'=>'var(--text-muted-custom)'],
                            ['id'=>'m-stacked',    'label'=>'Stack (closed)', 'val'=>'—',                              'color'=>'var(--text-muted-custom)'],
                            ['id'=>'m-participation','label'=>'Participation · closed','val'=>isset($metrics['participation_ratio']) ? ($metrics['participation_state'].' '.number_format($metrics['participation_ratio'],2).'×') : '—','color'=>'var(--gold)'],
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
                        @foreach(['1m','5m','15m','30m','1h','4h','1d'] as $tf)
                        <button type="button" onclick="switchTimeframe('{{ $tf }}')"
                                class="btn btn-sm {{ $tf===$initialTimeframe?'active':'' }}"
                                style="background:var(--bg-panel);border-color:var(--bg-border);color:{{ $tf===$initialTimeframe?'var(--gold)':'#8d97b0' }};font-size:.7rem;padding:.2rem .55rem">{{ $tf }}</button>
                        @endforeach
                    </div>
                    <select id="chart-type" onchange="switchChartType(this.value)"
                            class="form-select form-select-sm"
                            style="width:auto;background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;font-size:.7rem">
                        <option value="candlestick">Candles</option>
                        <option value="bar">OHLC Bars</option>
                        <option value="area">Area</option>
                    </select>
                    <a class="btn btn-sm btn-outline-warning" href="{{ route('orderflow.footprint') }}" onclick="this.href='/orderflow/footprint?tf='+currentTf">Footprint</a>
                </div>
            </div>
            <div style="position:relative" id="price-chart-wrap">
                <div id="tv-chart" style="height:380px;width:100%"></div>
                <svg id="sd-zone-overlay" role="img" aria-label="1H demand and supply zones" style="position:absolute;inset:0;width:100%;height:100%;z-index:4;pointer-events:none"></svg>
                <div id="chart-legend" style="position:absolute;top:8px;left:12px;z-index:10;font-size:.72rem;font-family:'JetBrains Mono',monospace;pointer-events:none">
                    <div><span style="color:#8d97b0">XAUUSD &bull; <span id="legend-tf">{{ $initialTimeframe }}</span></span>
                    <span id="legend-ohlc" style="color:#aaa;margin-left:.5rem"></span></div>
                    <div id="chart-stats" style="margin-top:.2rem;font-size:.65rem"></div>
                    <div id="chart-update-status" style="margin-top:.2rem;font-size:.65rem;color:#8d97b0">Loading chart…</div>
                </div>
            </div>
            <div id="volume-chart" style="height:80px;border-top:1px solid var(--bg-border)"></div>
            @include('dashboard.price-zones')
        </div>
    </div>

    {{-- Confluence Signals --}}
    <div class="col-12 col-xl-4">
        <div class="card-panel h-100">
            <div class="card-header-custom">
                <span><i class="bi bi-puzzle-fill me-1" style="color:var(--gold)"></i>Confluence Signals</span>
                <span style="font-size:.65rem" id="conf-timeframe">{{ $initialTimeframe }}</span>
            </div>
            <div class="p-3" id="confluence-signals">
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
                    <thead><tr><th>Time UTC</th><th>Open</th><th>Close</th><th>Delta</th><th>Buy Vol</th><th>Sell Vol</th><th>Bar vol ratio</th><th>Saved setup</th></tr></thead>
                    <tbody id="of-table-body">
                        @forelse($recentBars as $bar)
                        <tr>
                            <td style="color:#8d97b0">{{ isset($bar['time']) ? gmdate('d M H:i', $bar['time']) : '—' }}</td>
                            <td>{{ number_format($bar['open'] ?? 0, 2) }}</td>
                            <td style="color:{{ ($bar['close']??0) >= ($bar['open']??0) ? 'var(--green-bull)' : 'var(--red-bear)' }}">{{ number_format($bar['close'] ?? 0, 2) }}</td>
                            <td style="color:{{ ($bar['delta']??0) >= 0 ? 'var(--green-bull)' : 'var(--red-bear)' }}">{{ ($bar['delta']??0) >= 0 ? '+' : '' }}{{ $bar['delta'] ?? 0 }}</td>
                            <td class="text-success">{{ $bar['buy_vol'] ?? '—' }}</td>
                            <td class="text-danger">{{ $bar['sell_vol'] ?? '—' }}</td>
                            <td>
                                @if(($bar['imbalance']??'') === 'BULLISH') <span style="color:var(--green-bull);font-size:.7rem">▲ BULL</span>
                                @elseif(($bar['imbalance']??'') === 'BEARISH') <span style="color:var(--red-bear);font-size:.7rem">▼ BEAR</span>
                                @else <span style="color:var(--text-muted-custom);font-size:.7rem">Neutral</span>
                                @endif
                            </td>
                            <td>
                                @if(!empty($bar['signal'])) <span class="signal-badge {{ strtolower($bar['signal']) }}" style="font-size:.62rem;padding:.15rem .4rem">{{ $bar['signal'] }}</span>
                                @else <span style="color:var(--text-muted-custom);font-size:.7rem">{{ ($bar['closed'] ?? false) ? 'No setup' : 'Developing' }}</span>
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
let currentTf = @json($initialTimeframe);
window.GFTimeframe = currentTf;
window.GFFollowActiveFeed = @json(!request()->filled('setup_id'));
let chartType = 'candlestick', chartLoadSequence = 0, chartLoadedTf = null;
let priceZoneOverlay = null, latestChartCandles = [];
let targetPollPending = false;
const focusedChartSetup = @json($focusedChartData);
function addFocusedSetupLevels() {
    if (!focusedChartSetup) return;
    for (const [key, label, color] of [['entry','Entry','#f5c842'], ['stop_loss','SL','#ff637d'], ['tp1','TP1','#00c076'], ['tp2','TP2','#00c076']]) {
        candleSeries.createPriceLine({price:Number(focusedChartSetup[key]),color,lineWidth:1,lineStyle:2,axisLabelVisible:true,title:'#'+focusedChartSetup.id+' '+label});
    }
}

async function pollSetupTargets() {
    const id = document.getElementById('active-setup-panel')?.dataset.setupId;
    if (!id || targetPollPending) return;
    targetPollPending = true;
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    try {
        const response = await fetch('/api/setups/' + encodeURIComponent(id), {cache:'no-store',signal:controller.signal});
        if (!response.ok) throw new Error('Setup unavailable');
        const setup = await response.json();
        if (String(setup.id) !== id) return;
        for (const target of ['tp1', 'tp2']) {
            const card = document.getElementById(target + '-card'), observed = setup[target + '_hit_at'];
            if (!card || !observed) continue;
            // A recorded touch remains lit even after price retraces.
            card.classList.add('tp-hit'); card.querySelector('.tp-led').hidden = false;
            document.getElementById(target + '-hit-label').hidden = false;
            card.setAttribute('aria-label', target.toUpperCase() + ' price reached');
            card.title = 'Price reached; observed ' + new Date(observed).toISOString().replace('T',' ').slice(0,19) + ' UTC. This records a price touch.';
        }
        const stopCard = document.getElementById('sl-card');
        if (stopCard && setup.sl_hit_at) {
            stopCard.classList.add('sl-hit');stopCard.setAttribute('aria-label','SL price reached');
            document.getElementById('sl-hit-label').hidden = false;
        }
        const tracking = document.getElementById('exit-tracking-status');
        const trackingMessages = {sl:'SL price reached. Further target tracking has ended.',tp2:'TP2 price reached. Price tracking is complete.',
            ambiguous:'Stop and target crossed in one candle. First touch is unresolved; further exit tracking is paused.'};
        if (tracking && trackingMessages[setup.exit_tracking_state]) {
            tracking.hidden = false;tracking.textContent = trackingMessages[setup.exit_tracking_state];
        }
    } catch (_) {
        // Keep confirmed hits visible; retry without inventing target results.
    } finally { clearTimeout(timeout); targetPollPending = false; }
}
setInterval(pollSetupTargets, 10000); pollSetupTargets();

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
    addFocusedSetupLevels();
    window.vwapSeries = mainChart.addLineSeries({ color: '#a78bfa', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, title: 'VWAP proxy (20)' });
    window.pocSeries  = mainChart.addLineSeries({ color: '#f5c842', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dotted, title: 'POC proxy (20)' });

    volChart = LightweightCharts.createChart(document.getElementById('volume-chart'), { ...opts, height: 80 });
    volumeSeries = volChart.addHistogramSeries({ color: 'rgba(245,200,66,.4)', priceFormat: { type: 'volume' }, priceScaleId: '' });
    volChart.priceScale('').applyOptions({ scaleMargins: { top: 0.1, bottom: 0 } });

    mainChart.timeScale().subscribeVisibleLogicalRangeChange(r => { if (r) volChart.timeScale().setVisibleLogicalRange(r); });
    volChart.timeScale().subscribeVisibleLogicalRangeChange(r => { if (r) mainChart.timeScale().setVisibleLogicalRange(r); });
    priceZoneOverlay = GFPriceZones.mount(mainChart, () => candleSeries, () => latestChartCandles,
        () => ({'1m':60,'5m':300,'15m':900,'30m':1800,'1h':3600,'4h':14400,'1d':86400}[currentTf]));

    mainChart.subscribeCrosshairMove(param => {
        if (!param.time || !param.seriesData.size) return;
        const d = param.seriesData.get(candleSeries);
        if (d) document.getElementById('legend-ohlc').textContent = `O:${d.open.toFixed(2)}  H:${d.high.toFixed(2)}  L:${d.low.toFixed(2)}  C:${d.close.toFixed(2)}`;
    });

    loadChartData(currentTf);
    setInterval(() => loadChartData(currentTf), 10000);
    new ResizeObserver(() => {
        mainChart.resize(document.getElementById('tv-chart').clientWidth, 380);
        volChart.resize(document.getElementById('volume-chart').clientWidth, 80);
    }).observe(document.getElementById('tv-chart'));
}

function loadChartData(tf) {
    if (!candleSeries || !volumeSeries) return;
    const sequence = ++chartLoadSequence;
    const followLatest = mainChart.timeScale().scrollPosition() < 2;
    fetch(`/api/chart-data?tf=${tf}`, {cache: 'no-store'})
        .then(r => { if (!r.ok) throw new Error('Chart request failed'); return r.json(); })
        .then(d => {
            if (sequence !== chartLoadSequence || tf !== currentTf) return;
            latestChartCandles = d.candles || [];
            if (d.candles) candleSeries.setData(chartType === 'area'
                ? d.candles.map(b => ({time: b.time, value: b.close})) : d.candles);
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
                text:     `${s.direction} ${s.strategy === 'h1_trend_range_v3' ? 'H1 ' : ''}${s.score}%`,
            }));
            const allMarkers = [...hvMarkers, ...setupMarkers]
                .sort((a, b) => a.time - b.time);
            candleSeries.setMarkers(allMarkers);
            if (d.candles?.length) {
                if (chartLoadedTf !== tf) mainChart.timeScale().fitContent();
                else if (followLatest) mainChart.timeScale().scrollToRealTime();
                chartLoadedTf = tf;
                const last = d.candles[d.candles.length - 1];
                document.getElementById('legend-ohlc').textContent = `O:${last.open.toFixed(2)} H:${last.high.toFixed(2)} L:${last.low.toFixed(2)} C:${last.close.toFixed(2)}`;
                const time = new Date(last.time * 1000).toISOString().slice(11,16) + ' UTC';
                document.getElementById('chart-update-status').textContent = d.stats?.last_bar_closed === false
                    ? `Developing candle ${time} · refreshes every 10s`
                    : `Latest completed candle ${time} · refreshes every 10s`;
                if (tf === '1h' && d.stats?.candle_source === 'aggregated_15m') {
                    document.getElementById('chart-update-status').textContent += ' · H1 built from M15';
                }
            } else {
                document.getElementById('chart-update-status').textContent = `No ${tf} bars received. Attach the bridge to an MT5 ${tf} chart.`;
            }
            priceZoneOverlay?.draw();

            // Update chart stats legend
            if (d.stats) {
                const statsEl = document.getElementById('chart-stats');
                if (statsEl) statsEl.innerHTML =
                    `<span style="color:#8d97b0">POC proxy (20): <b style="color:#f5c842">${d.stats.poc_price?.toFixed(2) ?? '—'}</b></span>` +
                    `<span style="color:#8d97b0;margin-left:.75rem">VWAP proxy (20): <b style="color:#a78bfa">${d.stats.vwap_price?.toFixed(2) ?? '—'}</b></span>` +
                    `<span style="color:#8d97b0;margin-left:.75rem">Avg Vol: <b style="color:#a78bfa">${Number(d.stats.avg_volume).toLocaleString()}</b></span>` +
                    `<span style="color:#8d97b0;margin-left:.75rem"><b style="color:#00c076">■</b>/<b style="color:#ff3b5c">■</b> = High Vol bar</span>`;
            } else {
                document.getElementById('chart-stats').textContent = 'VWAP / POC unavailable for this timeframe';
            }
        })
        .catch(() => {
            if (sequence !== chartLoadSequence || tf !== currentTf) return;
            @if(config('orderflow.connector') === 'clusterdelta')
            document.getElementById('chart-update-status').textContent = 'Chart refresh failed · retrying in 10s';
            @else
            seedDemoChart();
            @endif
        });
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

function switchTimeframe(tf, automatic = false) {
    latestChartCandles = []; priceZoneOverlay?.draw();
    currentTf = tf;
    window.GFTimeframe = tf;
    if (!automatic) window.GFFollowActiveFeed = false;
    document.getElementById('legend-tf').textContent = tf;
    document.getElementById('conf-timeframe').textContent = tf;
    document.querySelectorAll('#tf-buttons .btn').forEach(b => {
        const active = b.textContent.trim() === tf;
        b.style.color = active ? 'var(--gold)' : '#8d97b0';
        b.style.borderColor = active ? 'var(--gold)' : 'var(--bg-border)';
    });
    loadChartData(tf);
    if (typeof pollConfluence === 'function') pollConfluence();
}

function switchChartType(type) {
    chartType = type;
    mainChart.removeSeries(candleSeries);
    if (type === 'candlestick') candleSeries = mainChart.addCandlestickSeries({ upColor:'#00c076', downColor:'#ff3b5c', borderUpColor:'#00c076', borderDownColor:'#ff3b5c' });
    else if (type === 'bar')    candleSeries = mainChart.addBarSeries({ upColor:'#00c076', downColor:'#ff3b5c' });
    else                        candleSeries = mainChart.addAreaSeries({ lineColor:'#f5c842', topColor:'rgba(245,200,66,.2)', bottomColor:'transparent' });
    addFocusedSetupLevels();
    loadChartData(currentTf);
}

function updateMarketMetrics(m) {
    const upd = (id, val, colorFn) => { const el = document.getElementById(id); if (el) { el.textContent = val; if (colorFn) el.style.color = colorFn(val); } };
    upd('m-cvd',        m.cvd,        v => v >= 0 ? '#00c076' : '#ff3b5c');
    upd('m-delta',      (m.delta>=0?'+':'')+m.delta, v => parseFloat(v)>=0?'#00c076':'#ff3b5c');
    upd('m-buy-vol',    m.buy_volume,  null);
    upd('m-sell-vol',   m.sell_volume, null);
    upd('m-absorption', m.absorption,  v => v==='BULL_ABSORPTION'?'#00c076':(v==='BEAR_ABSORPTION'?'#ff3b5c':'var(--text-muted-custom)'));
    upd('m-imbalance',  m.imbalance,   v => v==='BULLISH'?'#00c076':(v==='BEARISH'?'#ff3b5c':'var(--gold)'));
    upd('m-poc',        m.poc,         null);
    upd('m-vwap',       m.vwap,        null);
    upd('m-exhaustion', m.exhaustion,  v => v==='BULLISH_EXHAUSTION'?'#00c076':(v==='BEARISH_EXHAUSTION'?'#ff3b5c':'var(--text-muted-custom)'));
    const participation = m.participation_ratio == null ? '—' : `${m.participation_state} ${Number(m.participation_ratio).toFixed(2)}×`;
    upd('m-participation', participation, v => v.startsWith('INCREASED')?'#f5c842':(v.startsWith('DECREASED')?'#8d97b0':'#c4ccdb'));
    const b = (id,w) => { const el=document.getElementById(id); if(el) el.style.width=Math.min(100,w||0)+'%'; };
    b('m-buy-vol-bar', m.buy_pct); b('m-sell-vol-bar', m.sell_pct); b('m-cvd-bar', Math.abs(m.cvd_pct||0));
    document.getElementById('metrics-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
}
@if(config('orderflow.connector') === 'clusterdelta')
let confluencePending = false;
async function pollConfluence() {
    if (confluencePending) return;
    confluencePending = true;
    const tf = currentTf, controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    const container = document.getElementById('confluence-signals');
    try {
        const response = await fetch('/api/setups/status', {cache:'no-store',signal:controller.signal});
        if (!response.ok) throw new Error('Evaluation unavailable');
        const data = await response.json(); if (tf !== currentTf) return;
        const state = data.timeframes.find(row => row.timeframe === tf);
        container.replaceChildren();
        document.getElementById('conf-timeframe').textContent = tf + ' · closed candle';
        const summary = document.createElement('div'); summary.className = 'small mb-2'; summary.style.color = 'var(--text-muted-custom)';
        summary.textContent = state?.reason || 'Waiting for detector evaluation'; container.appendChild(summary);
        const signals = state?.engine_fresh ? state.signals || [] : [];
        const stack = signals.find(signal => signal.key === 'stacked_imbalance');
        const stackMetric = document.getElementById('m-stacked');
        stackMetric.textContent = stack ? (stack.status === 'neutral' ? 'NONE' : stack.status.toUpperCase()) : '—';
        stackMetric.style.color = stack?.status === 'bullish' ? 'var(--green-bull)' : stack?.status === 'bearish' ? 'var(--red-bear)' : 'var(--text-muted-custom)';
        for (const signal of signals) {
            const row = document.createElement('div'); row.className = 'py-2'; row.style.borderTop = '1px solid var(--bg-border)';
            const label = document.createElement('div'); label.style.fontSize = '.78rem';
            label.style.color = signal.status === 'bullish' ? 'var(--green-bull)' : signal.status === 'bearish' ? 'var(--red-bear)' : '#c4ccdb';
            label.textContent = signal.name + ' · ' + (signal.available === false ? 'UNAVAILABLE' : signal.status.toUpperCase()) + ' · ' + signal.weight + ' pts';
            const detail = document.createElement('div'); detail.style.cssText = 'font-size:.68rem;color:var(--text-muted-custom)'; detail.textContent = signal.detail;
            row.append(label, detail); container.appendChild(row);
        }
    } catch (_) {
        if (tf === currentTf) {
            container.textContent = 'Cannot verify setup conditions. Retrying in 10 seconds.';
            document.getElementById('m-stacked').textContent = '—';
        }
    } finally { clearTimeout(timeout); confluencePending = false; if (tf !== currentTf) pollConfluence(); }
}
setInterval(pollConfluence, 10000); pollConfluence();
window.addEventListener('clusterdelta-metrics', e => {
    const feed = e.detail;
    if (window.GFFollowActiveFeed && feed.timeframe && feed.timeframe !== currentTf) switchTimeframe(feed.timeframe, true);
    if (feed.timeframe && feed.timeframe !== currentTf) return;
    if (feed.metrics) updateMarketMetrics(feed.metrics);
    else {
        ['m-cvd','m-delta','m-buy-vol','m-sell-vol','m-absorption','m-imbalance','m-poc','m-vwap','m-exhaustion','m-stacked','m-participation'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.textContent = '—';
        });
        ['m-buy-vol-bar','m-sell-vol-bar','m-cvd-bar'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.width = '0%';
        });
    }
    document.getElementById('metrics-updated').textContent = feed.last_received
        ? (feed.connected ? 'Data received ' : 'Feed stale · last received ') + new Date(feed.last_received * 1000).toLocaleTimeString()
        : `No ${currentTf} market data received · check the bridge timeframe`;
});
@else
Echo.channel('xauusd.metrics').listen('.metrics.updated', e => updateMarketMetrics(e.metrics));
@endif

Echo.channel('xauusd.candles').listen('.candle.closed', (e) => {
    if (e.tf === currentTf) { candleSeries.update(e.candle); volumeSeries.update(e.volume); }
});
</script>
@endpush
