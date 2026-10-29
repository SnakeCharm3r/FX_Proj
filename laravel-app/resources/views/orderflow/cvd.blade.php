@extends('layouts.app')
@section('title', 'CVD')

@section('content')
<div class="mb-3">
    <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-graph-up-arrow me-2" style="color:var(--gold)"></i>Cumulative Volume Delta</h5>
    <small style="color:var(--text-muted-custom)">Native GC footprint CVD against native GC futures price</small>
</div>
<div class="row g-3">
    <div class="col-12">
        <div class="card-panel">
            <div class="card-header-custom">
                <span>Price vs CVD</span>
                <div class="d-flex gap-1">
                    @foreach(['5m','15m','30m','1h','4h'] as $tf)
                    <button onclick="loadCVD('{{ $tf }}')" class="btn btn-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;font-size:.7rem;padding:.2rem .55rem">{{ $tf }}</button>
                    @endforeach
                </div>
            </div>
            <div id="price-chart-cvd" style="height:280px"></div>
            <div id="cvd-main-chart"  style="height:150px;border-top:1px solid var(--bg-border)"></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-panel p-3 text-center">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted-custom);margin-bottom:.5rem">Current CVD</div>
            <div id="current-cvd" style="font-family:'JetBrains Mono',monospace;font-size:1.8rem;font-weight:700;color:var(--gold)">—</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-panel p-3 text-center">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted-custom);margin-bottom:.5rem">Divergence</div>
            <div id="divergence-status" style="font-size:1.2rem;font-weight:700;color:var(--text-muted-custom)">—</div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card-panel p-3 text-center">
            <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted-custom);margin-bottom:.5rem">CVD Trend</div>
            <div id="cvd-trend" style="font-size:1.2rem;font-weight:700;color:var(--text-muted-custom)">—</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const priceChartCVD = LightweightCharts.createChart(document.getElementById('price-chart-cvd'), {
    layout:{background:{color:'transparent'},textColor:'#8d97b0'},
    grid:{vertLines:{color:'rgba(37,42,56,.8)'},horzLines:{color:'rgba(37,42,56,.8)'}},
    rightPriceScale:{borderColor:'#252a38'}, timeScale:{borderColor:'#252a38',timeVisible:true}, height:280,
});
const cvdChart2 = LightweightCharts.createChart(document.getElementById('cvd-main-chart'), {
    layout:{background:{color:'transparent'},textColor:'#8d97b0'},
    grid:{vertLines:{color:'rgba(37,42,56,.8)'},horzLines:{color:'rgba(37,42,56,.8)'}},
    rightPriceScale:{borderColor:'#252a38'}, timeScale:{borderColor:'#252a38',timeVisible:true}, height:150,
});
const priceSeries = priceChartCVD.addCandlestickSeries({upColor:'#00c076',downColor:'#ff3b5c',borderUpColor:'#00c076',borderDownColor:'#ff3b5c'});
const cvdLine     = cvdChart2.addLineSeries({color:'#a78bfa',lineWidth:2});

priceChartCVD.timeScale().subscribeVisibleLogicalRangeChange(r=>{if(r)cvdChart2.timeScale().setVisibleLogicalRange(r);});
cvdChart2.timeScale().subscribeVisibleLogicalRangeChange(r=>{if(r)priceChartCVD.timeScale().setVisibleLogicalRange(r);});

function loadCVD(tf) {
    fetch(`/api/orderflow/cvd?tf=${tf}`)
        .then(r=>r.json())
        .then(d=>{
            if(d.candles) priceSeries.setData(d.candles);
            if(d.cvd)     cvdLine.setData(d.cvd);
            if(d.summary){
                document.getElementById('current-cvd').textContent = d.summary.cvd?.toLocaleString() ?? '—';
                document.getElementById('divergence-status').textContent = d.summary.divergence||'—';
                document.getElementById('divergence-status').style.color = d.summary.divergence!=='NONE'?'var(--gold)':'var(--text-muted-custom)';
                document.getElementById('cvd-trend').textContent = d.summary.trend||'—';
                document.getElementById('cvd-trend').style.color = d.summary.trend==='UP'?'var(--green-bull)':(d.summary.trend==='DOWN'?'var(--red-bear)':'var(--text-muted-custom)');
            }
        })
        .catch(()=>{ priceSeries.setData([]); cvdLine.setData([]); document.getElementById('current-cvd').textContent='—'; });
}
document.addEventListener('DOMContentLoaded', ()=>loadCVD('15m'));

Echo.channel('xauusd.metrics').listen('.metrics.updated', e=>{
    if(e.metrics?.cvd!==undefined){
        document.getElementById('current-cvd').textContent = parseInt(e.metrics.cvd).toLocaleString();
        document.getElementById('current-cvd').style.color = e.metrics.cvd>=0?'var(--green-bull)':'var(--red-bear)';
    }
});
</script>
@endpush
