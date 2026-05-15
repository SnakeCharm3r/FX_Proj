@extends('layouts.app')
@section('title', 'Imbalance Zones')

@section('content')
<div class="mb-3">
    <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-layers-fill me-2" style="color:var(--gold)"></i>Imbalance Zones</h5>
    <small style="color:var(--text-muted-custom)">Bid/Ask imbalances and stacked clusters</small>
</div>
<div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="card-panel">
            <div class="card-header-custom">
                <span>Price Chart with Imbalance Overlays</span>
                <div class="d-flex gap-1">
                    @foreach(['5m','15m','30m','1h'] as $tf)
                    <button onclick="loadImbalance('{{ $tf }}')" class="btn btn-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;font-size:.7rem;padding:.2rem .55rem">{{ $tf }}</button>
                    @endforeach
                </div>
            </div>
            <div id="imb-chart" style="height:420px"></div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card-panel h-100">
            <div class="card-header-custom"><span>Active Imbalance Zones</span></div>
            <div class="p-2" id="imb-zone-list">
                <div class="text-center py-4" style="color:var(--text-muted-custom);font-size:.82rem"><i class="bi bi-layers fs-2 d-block mb-2" style="opacity:.3"></i>Loading…</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const imbChart = LightweightCharts.createChart(document.getElementById('imb-chart'), {
    layout:{background:{color:'transparent'},textColor:'#8d97b0'},
    grid:{vertLines:{color:'rgba(37,42,56,.8)'},horzLines:{color:'rgba(37,42,56,.8)'}},
    rightPriceScale:{borderColor:'#252a38'}, timeScale:{borderColor:'#252a38',timeVisible:true}, height:420,
});
const imbCandles = imbChart.addCandlestickSeries({
    upColor:'#00c076',downColor:'#ff3b5c',borderUpColor:'#00c076',borderDownColor:'#ff3b5c',
    wickUpColor:'rgba(0,192,118,.5)',wickDownColor:'rgba(255,59,92,.5)',
});

function renderZones(zones) {
    const list = document.getElementById('imb-zone-list');
    if (!zones||!zones.length) { list.innerHTML='<div class="text-center py-4" style="color:var(--text-muted-custom);font-size:.82rem">No active zones</div>'; return; }
    list.innerHTML = zones.map(z=>`
    <div class="d-flex align-items-center justify-content-between p-2 mb-1 rounded"
         style="background:var(--bg-panel);border:1px solid ${z.type==='BULLISH'?'rgba(0,192,118,.2)':'rgba(255,59,92,.2)'}">
        <div>
            <div style="font-size:.75rem;font-weight:600;color:${z.type==='BULLISH'?'var(--green-bull)':'var(--red-bear)'}">${z.type}</div>
            <div style="font-size:.65rem;color:var(--text-muted-custom);font-family:'JetBrains Mono',monospace">${parseFloat(z.low).toFixed(2)} – ${parseFloat(z.high).toFixed(2)}</div>
        </div>
        <div class="text-end">
            <div style="font-size:.7rem;color:var(--text-muted-custom)">${z.tf} &bull; ${z.count} bars</div>
            <div style="font-size:.65rem;color:${z.type==='BULLISH'?'rgba(0,192,118,.6)':'rgba(255,59,92,.6)'}">${z.stacked?'⚡ Stacked':'Single'}</div>
        </div>
    </div>`).join('');
}

function loadImbalance(tf) {
    fetch(`/api/orderflow/imbalance?tf=${tf}`)
        .then(r=>r.json())
        .then(d=>{ if(d.candles) imbCandles.setData(d.candles); renderZones(d.zones||[]); })
        .catch(()=>{
            const now=Math.floor(Date.now()/1000); const candles=[]; let p=3320;
            for(let i=60;i>=0;i--){const t=now-i*900,o=p,c=o+(Math.random()-.48)*8,h=Math.max(o,c)+Math.random()*3,l=Math.min(o,c)-Math.random()*3;
                candles.push({time:t,open:+o.toFixed(2),high:+h.toFixed(2),low:+l.toFixed(2),close:+c.toFixed(2)});p=c;}
            imbCandles.setData(candles);
            renderZones([{type:'BULLISH',low:3312.5,high:3315.2,tf:'15m',count:3,stacked:true},{type:'BEARISH',low:3328,high:3330.8,tf:'15m',count:2,stacked:false}]);
        });
}
document.addEventListener('DOMContentLoaded', ()=>loadImbalance('15m'));
</script>
@endpush
