@extends('layouts.app')
@section('title', 'Delta Analysis')

@section('content')
<div class="mb-3">
    <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-arrow-left-right me-2" style="color:var(--gold)"></i>Delta Analysis</h5>
    <small style="color:var(--text-muted-custom)">Buy vs Sell aggressor volume per bar</small>
</div>
<div class="row g-3">
    <div class="col-12">
        <div class="card-panel">
            <div class="card-header-custom">
                <span>Delta Chart — <span id="delta-tf">15m</span></span>
                <div class="d-flex gap-1">
                    @foreach(['5m','15m','30m','1h','4h'] as $tf)
                    <button onclick="loadDelta('{{ $tf }}')" class="btn btn-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#8d97b0;font-size:.7rem;padding:.2rem .55rem">{{ $tf }}</button>
                    @endforeach
                </div>
            </div>
            <div id="delta-chart" style="height:320px"></div>
        </div>
    </div>
    <div class="col-12">
        <div class="card-panel">
            <div class="card-header-custom"><span>Cumulative Volume Delta (CVD)</span></div>
            <div id="cvd-chart" style="height:200px"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const deltaChart = LightweightCharts.createChart(document.getElementById('delta-chart'), {
    layout:{background:{color:'transparent'},textColor:'#8d97b0'},
    grid:{vertLines:{color:'rgba(37,42,56,.8)'},horzLines:{color:'rgba(37,42,56,.8)'}},
    rightPriceScale:{borderColor:'#252a38'}, timeScale:{borderColor:'#252a38',timeVisible:true}, height:320,
});
const deltaSeries = deltaChart.addHistogramSeries({ priceFormat:{type:'volume'} });

const cvdChart = LightweightCharts.createChart(document.getElementById('cvd-chart'), {
    layout:{background:{color:'transparent'},textColor:'#8d97b0'},
    grid:{vertLines:{color:'rgba(37,42,56,.8)'},horzLines:{color:'rgba(37,42,56,.8)'}},
    rightPriceScale:{borderColor:'#252a38'}, timeScale:{borderColor:'#252a38',timeVisible:true}, height:200,
});
const cvdSeries = cvdChart.addLineSeries({ color:'var(--gold)', lineWidth:2 });

deltaChart.timeScale().subscribeVisibleLogicalRangeChange(r => { if(r) cvdChart.timeScale().setVisibleLogicalRange(r); });
cvdChart.timeScale().subscribeVisibleLogicalRangeChange(r => { if(r) deltaChart.timeScale().setVisibleLogicalRange(r); });

function loadDelta(tf) {
    document.getElementById('delta-tf').textContent = tf;
    fetch(`/api/orderflow/delta?tf=${tf}`)
        .then(r => r.json())
        .then(d => {
            if (d.delta) deltaSeries.setData(d.delta.map(b => ({ time:b.time, value:b.delta, color:b.delta>=0?'rgba(0,192,118,.7)':'rgba(255,59,92,.7)' })));
            if (d.cvd)   cvdSeries.setData(d.cvd);
        })
        .catch(() => {
            const now = Math.floor(Date.now()/1000); const delta=[], cvd=[]; let cum=0;
            for (let i=60;i>=0;i--) {
                const t=now-i*900, d=Math.round((Math.random()-.48)*1000); cum+=d;
                delta.push({time:t,value:d,color:d>=0?'rgba(0,192,118,.7)':'rgba(255,59,92,.7)'});
                cvd.push({time:t,value:cum});
            }
            deltaSeries.setData(delta); cvdSeries.setData(cvd);
        });
}
document.addEventListener('DOMContentLoaded', () => loadDelta('15m'));

Echo.channel('xauusd.metrics').listen('.metrics.updated', e => {
    if (e.metrics?.cvd !== undefined) {
        const now = Math.floor(Date.now()/1000);
        cvdSeries.update({ time: now, value: e.metrics.cvd });
    }
});
</script>
@endpush
