@extends('layouts.app')
@section('title', 'Imbalance Zones')
@push('styles')
<style>#imb-zone-list button[aria-pressed="true"]{outline:1px solid var(--gold);outline-offset:-1px}</style>
@endpush

@section('content')
<div class="mb-3">
    <h5 class="mb-1"><i class="bi bi-layers-fill me-2" style="color:var(--gold)"></i>Imbalance Zones</h5>
    <small style="color:var(--text-muted-custom)">Observed bid/ask imbalances and adjacent stacks from real GC footprint cells.</small>
</div>
<div class="card-panel p-3 mb-3" role="status" aria-live="polite">
    <div id="imb-status">Checking footprint cells…</div>
    <small id="imb-source" style="color:var(--text-muted-custom)">Native GC futures prices; these ranges are separate from broker XAUUSD prices.</small>
</div>
<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card-panel">
            <div class="card-header-custom d-flex justify-content-between gap-2 flex-wrap">
                <span>GC futures candles · select a range to highlight it</span>
                <select id="imb-tf" class="form-select form-select-sm" aria-label="Imbalance timeframe" style="width:85px">
                    @foreach(config('orderflow.timeframes') as $tf)<option value="{{ $tf }}" @selected($tf === '15m')>{{ $tf }}</option>@endforeach
                </select>
            </div>
            <div id="imb-chart" style="height:480px"></div>
            <div class="p-3 small" id="imb-selection" style="color:var(--text-muted-custom)">Select an observed range from the list.</div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card-panel h-100">
            <div class="card-header-custom d-flex justify-content-between gap-2 flex-wrap"><span>Observed ranges</span>
                <label class="small"><input type="checkbox" id="imb-stacked-only"> Stacks only</label>
            </div>
            <div class="p-2" id="imb-zone-list" style="max-height:530px;overflow:auto">Checking cells…</div>
        </div>
    </div>
</div>
<div class="small mt-3" style="color:var(--text-muted-custom)">
    Imbalance compares ask at a price with bid one tick below, or bid with ask one tick above.
    Each range contains adjacent qualifying price levels in one candle; it is not a count of candles.
    A single imbalance can appear here while the setup's stacked-imbalance rule remains neutral.
    Past ranges are observations, not confirmed active support/resistance. Developing ranges can change.
</div>
@endsection

@push('scripts')
<script>
(() => {
    const container = document.getElementById('imb-chart');
    const chart = LightweightCharts.createChart(container, {
        layout:{background:{color:'transparent'},textColor:'#a8b2c8'},
        grid:{vertLines:{color:'#252a38'},horzLines:{color:'#252a38'}},
        rightPriceScale:{borderColor:'#252a38'}, timeScale:{borderColor:'#252a38',timeVisible:true}, height:480,
    });
    const candles = chart.addCandlestickSeries({upColor:'#00c076',downColor:'#ff3b5c',borderUpColor:'#00c076',borderDownColor:'#ff3b5c',wickUpColor:'#00c076',wickDownColor:'#ff3b5c'});
    const tfControl = document.getElementById('imb-tf');
    const requested = new URLSearchParams(location.search).get('tf');
    if ([...tfControl.options].some(o => o.value === requested)) tfControl.value = requested;
    let data = null, selectedKey = null, lines = [], busy = false, lastTf = null;
    window.GFFollowActiveFeed = false; window.GFTimeframe = tfControl.value;
    const price = value => Number(value).toFixed(2);
    const utc = time => new Date(time * 1000).toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
    const key = zone => [zone.time,zone.type,zone.low,zone.high].join(':');
    function clearSelection() {
        lines.forEach(line => candles.removePriceLine(line)); lines = [];
        document.getElementById('imb-selection').textContent = 'Select an observed range from the list.';
    }
    function select(zone) {
        clearSelection(); selectedKey = key(zone);
        const color = zone.type === 'BULLISH' ? '#00c076' : '#ff637d';
        const edges = zone.low === zone.high ? [['Level', zone.low]] : [['Low', zone.low], ['High', zone.high]];
        edges.forEach(([label, value]) => lines.push(candles.createPriceLine({price:value,color,lineWidth:2,lineStyle:2,axisLabelVisible:true,title:label})));
        document.getElementById('imb-selection').textContent = zone.type + ' · ' + price(zone.low) + ' – ' + price(zone.high)
            + ' GC · ' + zone.count + ' price level' + (zone.count === 1 ? '' : 's') + ' · ' + utc(zone.time) + ' · ' + (zone.closed ? 'Closed candle' : 'Developing candle');
        document.querySelectorAll('#imb-zone-list button').forEach(button => button.setAttribute('aria-pressed', button.dataset.key === selectedKey ? 'true' : 'false'));
    }
    function renderList() {
        const list = document.getElementById('imb-zone-list'); list.replaceChildren();
        const onlyStacks = document.getElementById('imb-stacked-only').checked;
        const zones = (data?.zones || []).filter(zone => !onlyStacks || zone.stacked);
        if (!zones.length) {
            const message = document.createElement('div'); message.className = 'text-center p-3 small';
            message.textContent = data?.status === 'waiting_for_footprint' ? data.reason
                : onlyStacks ? 'No stack meets the required consecutive-level count in these candles.' : data?.reason || 'No observations available.';
            list.appendChild(message); clearSelection(); selectedKey = null; return;
        }
        zones.forEach(zone => {
            const button = document.createElement('button'); button.type = 'button';
            button.className = 'w-100 text-start p-2 mb-2 rounded'; button.dataset.key = key(zone);
            button.style.cssText = 'background:var(--bg-panel);border:1px solid var(--bg-border);color:#c7cfdf';
            const title = document.createElement('div'); title.className = 'small fw-semibold';
            title.style.color = zone.type === 'BULLISH' ? 'var(--green-bull)' : 'var(--red-bear)';
            title.textContent = zone.type + ' · ' + (zone.stacked ? 'Stack' : 'Below stack threshold');
            const range = document.createElement('div'); range.className = 'small';
            range.textContent = price(zone.low) + ' – ' + price(zone.high) + ' GC · ' + zone.count + ' price level' + (zone.count === 1 ? '' : 's');
            const stamp = document.createElement('div'); stamp.className = 'small'; stamp.style.color = 'var(--text-muted-custom)';
            stamp.textContent = utc(zone.time) + ' · ' + (zone.closed ? 'Closed' : 'Developing');
            button.append(title, range, stamp); button.addEventListener('click', () => select(zone)); list.appendChild(button);
        });
        const selected = zones.find(zone => key(zone) === selectedKey) || zones[0]; select(selected);
    }
    async function load() {
        if (busy) return; busy = true;
        const tf = tfControl.value, controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('/api/orderflow/imbalance?tf=' + encodeURIComponent(tf), {cache:'no-store',signal:controller.signal});
            if (!response.ok) throw new Error('Imbalance feed unavailable');
            const snapshot = await response.json(); if (tf !== tfControl.value) return;
            data = snapshot; candles.setData(data.candles);
            const markers = new Map();
            for (const zone of data.zones) {
                if (!zone.stacked) continue;
                const id = zone.time + ':' + zone.type;
                if (!markers.has(id) || markers.get(id).count < zone.count) markers.set(id, zone);
            }
            candles.setMarkers([...markers.values()].sort((a,b) => a.time - b.time).map(zone => ({time:zone.time,
                position:zone.type === 'BULLISH' ? 'belowBar' : 'aboveBar', color:zone.type === 'BULLISH' ? '#00c076' : '#ff637d',
                shape:zone.type === 'BULLISH' ? 'arrowUp' : 'arrowDown', text:zone.count + ' levels'})));
            if (lastTf !== tf) { chart.timeScale().fitContent(); lastTf = tf; }
            document.getElementById('imb-status').textContent = data.reason;
            document.getElementById('imb-status').style.color = data.status === 'receiving' ? 'var(--green-bull)' : 'var(--gold)';
            document.getElementById('imb-source').textContent = 'GC futures · ' + tf + ' · ratio ≥ ' + data.ratio + ':1 · major volume ≥ 10 · stack ≥ ' + data.stacked_count
                + ' adjacent levels · ' + (data.received_at ? 'received ' + new Date(data.received_at * 1000).toLocaleTimeString() : 'no footprint received');
            renderList();
        } catch (_) {
            data = null; selectedKey = null; clearSelection(); candles.setData([]); candles.setMarkers([]);
            document.getElementById('imb-zone-list').textContent = 'Cannot verify observed ranges while the request is failing.';
            document.getElementById('imb-status').textContent = 'Imbalance request failed. Retrying in 5 seconds.';
            document.getElementById('imb-status').style.color = 'var(--gold)';
        } finally { clearTimeout(timeout); busy = false; if (tf !== tfControl.value) load(); }
    }
    tfControl.addEventListener('change', () => {
        window.GFTimeframe = tfControl.value; data = null; selectedKey = null; lastTf = null;
        clearSelection(); candles.setData([]); candles.setMarkers([]);
        document.getElementById('imb-zone-list').textContent = 'Checking footprint cells…'; load();
    });
    document.getElementById('imb-stacked-only').addEventListener('change', renderList);
    new ResizeObserver(() => chart.applyOptions({width:container.clientWidth})).observe(container);
    load(); setInterval(load, 5000);
})();
</script>
@endpush
