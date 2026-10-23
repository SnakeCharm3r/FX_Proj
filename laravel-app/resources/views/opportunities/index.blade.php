@extends('layouts.app')
@section('title','Opportunity Chart')
@push('styles')
<style>
#op-chart{height:560px}.op-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:16px}.op-list{max-height:360px;overflow:auto}.op-button{width:100%;text-align:left;border:0;border-left:3px solid transparent;border-bottom:1px solid var(--bg-border);background:transparent;color:#e2e8f0;padding:12px}.op-button.selected{background:rgba(245,200,66,.07);border-left-color:var(--gold)}.op-values{display:grid;grid-template-columns:1fr 1fr;gap:8px}.op-values .label{font-size:.65rem;color:var(--text-muted-custom)}.op-values .value{font-family:'JetBrains Mono',monospace}.op-copy{font-size:.75rem;color:var(--text-muted-custom)}@media(max-width:1100px){.op-layout{grid-template-columns:minmax(0,1fr)}#op-chart{height:420px}}
</style>
@endpush
@section('content')
<div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
    <div><h5 class="mb-1">Variable entry opportunities</h5><div class="op-copy">M5 arrows · 25 / 50 / 75 / 100% rule completion · select a candidate to inspect its variables</div></div>
    <form id="op-date-form" class="d-flex gap-2 align-items-center"><label class="small" for="op-date">Trading date</label><input type="date" id="op-date" class="form-control form-control-sm" value="{{ request('date',now('Africa/Dar_es_Salaam')->format('Y-m-d')) }}"><button class="btn btn-sm btn-warning">View</button></form>
</div>
<div id="op-status" class="op-copy mb-2" role="status">Checking saved candidates and live feed…</div>
<div class="d-flex flex-wrap gap-3 mb-3 small">
    <label style="color:var(--green-bull)"><input id="op-buy" type="checkbox" checked> BUY</label><label style="color:var(--red-bear)"><input id="op-sell" type="checkbox" checked> SELL</label>
    @foreach([25,50,75,100] as $score)<label><input class="op-tier" type="checkbox" value="{{ $score }}" checked> {{ $score }}% rules</label>@endforeach
    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="op-reset">Show full session</button>
</div>
<div class="op-layout">
    <div>
        <div class="card-panel mb-3"><div class="card-header-custom"><span>XAUUSD · 5 MIN · opportunities</span><span id="op-period">EAT</span></div><div id="op-chart"></div><div class="p-3 op-copy">Arrows mark completed signal candles. Selecting an arrow or sidebar item plots its entry, SL, TP1 and TP2. Rule completion is not a win probability.</div></div>
        <div class="card-panel p-3"><div class="small fw-semibold mb-2">Why an arrow appears</div><div class="op-copy">25%: participation increased with directional delta. 50%: participation increased at an H1 zone. 75%: completed absorption without a matching H1 zone. 100%: absorption at a matching H1 zone. The candidate stream has its own Telegram notifications; the strict trade strategy still has its additional confirmation and risk checks.</div></div>
    </div>
    <aside class="card-panel"><div class="card-header-custom"><span>Entry candidates</span><span id="op-count">0</span></div><div class="op-list" id="op-list"></div>
        <div class="p-3" style="border-top:1px solid var(--bg-border)"><div id="op-title" class="fw-semibold mb-2">Select a candidate</div><div id="op-selected-time" class="op-copy mb-3"></div><div class="op-values" id="op-values"></div><div class="op-copy mt-3" id="op-reason"></div><div class="op-copy mt-2" id="op-support"></div><div class="op-copy mt-2" id="op-risk"></div><div class="op-copy mt-2" id="op-delivery"></div></div>
    </aside>
</div>
@endsection
@push('scripts')
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(() => {
    const host=document.getElementById('op-chart');
    const eat=value=>new Date((Number(value)+10800)*1000).toISOString().slice(5,16).replace('T',' ');
    const chart=LightweightCharts.createChart(host,{height:host.clientHeight,layout:{background:{color:'#141720'},textColor:'#8d97b0'},grid:{vertLines:{color:'#252a38'},horzLines:{color:'#252a38'}},timeScale:{timeVisible:true,tickMarkFormatter:time=>eat(time).slice(-5)},localization:{timeFormatter:time=>eat(time)+' EAT'}});
    const series=chart.addCandlestickSeries({upColor:'#00c076',downColor:'#ff3b5c',borderVisible:false,wickUpColor:'#00c076',wickDownColor:'#ff3b5c'});
    let report=null,selected=Number(new URLSearchParams(window.location.search).get('candidate'))||null,busy=false,first=true,lines=[];
    const fmt=(v,n=2)=>v==null?'—':Number(v).toLocaleString(undefined,{minimumFractionDigits:n,maximumFractionDigits:n});
    const put=(id,value)=>document.getElementById(id).textContent=value;
    const visible=()=>{
        const tiers=[...document.querySelectorAll('.op-tier:checked')].map(e=>Number(e.value));
        return (report?.opportunities||[]).filter(o=>tiers.includes(o.score)&&document.getElementById(o.direction==='BUY'?'op-buy':'op-sell').checked);
    };
    function clearDetails(){
        selected=null;put('op-title','Select a candidate');
        for(const id of ['op-selected-time','op-reason','op-support','op-risk','op-delivery'])put(id,'');
        document.getElementById('op-values').replaceChildren();
        for(const line of lines)series.removePriceLine(line);lines=[];
    }
    function focus(id,scroll=false){
        selected=id;const o=(report?.opportunities||[]).find(o=>o.id===id);if(!o)return;
        document.querySelectorAll('.op-button').forEach(b=>b.classList.toggle('selected',Number(b.dataset.id)===id));
        put('op-title',o.direction+' opportunity · '+o.score+'% rules');document.getElementById('op-title').style.color=o.direction==='BUY'?'var(--green-bull)':'var(--red-bear)';
        put('op-selected-time',eat(o.candle_time)+' → '+eat(o.candle_time+300).slice(-5)+' EAT · '+(o.historical?'Historical replay':'Live observation'));
        const grid=document.getElementById('op-values');grid.replaceChildren();
        for(const [label,value] of [['Entry',fmt(o.entry)],['SL',fmt(o.stop_loss)],['TP1',fmt(o.tp1)],['TP2',fmt(o.tp2)],['Delta',fmt(o.evidence.delta,0)+' / '+(o.delta_pct>0?'+':'')+fmt(o.delta_pct,1)+'%'],['Total volume',fmt(o.evidence.volume,0)],['Volume / normal',fmt(o.evidence.volume_ratio,2)+'×'],['H1 trend',o.evidence.h1_trend],['H1 zone',o.evidence.zone_type||'None'],['CVD',fmt(o.evidence.cvd,0)]]){
            const box=document.createElement('div'),l=document.createElement('div'),v=document.createElement('div');l.className='label';l.textContent=label;v.className='value';v.textContent=value;box.append(l,v);grid.appendChild(box);
        }
        put('op-reason',o.evidence.finding);
        const fp=o.evidence.footprint;put('op-support','Matched GC footprint · buy stack '+fp.footprint_bull_stack+' / sell '+fp.footprint_bear_stack+' · POC '+fp.poc_rejection+'. CVD divergence: '+o.evidence.cvd_divergence+'.');
        put('op-risk','Stop distance '+fmt(o.risk_points)+' points · '+(o.evidence.strict_risk_passed?'within':'above')+' strict '+fmt(o.evidence.strict_risk_limit,1)+'-point limit. Levels use the signal candle; strict entry approval is separate.');
        put('op-delivery','Telegram: '+o.telegram_status.replaceAll('_',' '));
        for(const l of lines)series.removePriceLine(l);lines=[];
        for(const [title,price,color] of [['Entry',o.entry,'#f5c842'],['SL',o.stop_loss,'#ff3b5c'],['TP1',o.tp1,'#00c076'],['TP2',o.tp2,'#48c6ac']])lines.push(series.createPriceLine({price,color,lineWidth:1,lineStyle:2,axisLabelVisible:true,title}));
        if(scroll)chart.timeScale().setVisibleRange({from:o.candle_time-24*300,to:o.candle_time+24*300});
    }
    function render(){
        const rows=visible(),list=document.getElementById('op-list');list.replaceChildren();
        for(const o of rows.slice().reverse()){
            const b=document.createElement('button');b.type='button';b.className='op-button';b.dataset.id=o.id;
            const title=document.createElement('div');title.textContent=o.direction+' '+o.score+'% · Δ'+(o.delta_pct>0?'+':'')+fmt(o.delta_pct,1)+'%';title.style.color=o.direction==='BUY'?'var(--green-bull)':'var(--red-bear)';
            const subtitle=document.createElement('div');subtitle.className='op-copy';subtitle.textContent=eat(o.candle_time)+' EAT · '+fmt(o.evidence.volume_ratio)+'× volume';b.append(title,subtitle);b.addEventListener('click',()=>focus(o.id,true));list.appendChild(b);
        }
        put('op-count',rows.length);
        if(!rows.length){const p=document.createElement('p');p.className='p-3 op-copy';p.textContent='No recorded candidates for these filters. The watcher records fresh completed M5 candles.';list.appendChild(p);}
        const candleTimes=new Set((report?.candles||[]).map(c=>c.time));
        series.setMarkers(rows.filter(o=>candleTimes.has(o.candle_time)).map(o=>({id:String(o.id),time:o.candle_time,position:o.direction==='BUY'?'belowBar':'aboveBar',color:o.direction==='BUY'?'#00c076':'#ff3b5c',shape:o.direction==='BUY'?'arrowUp':'arrowDown',text:o.direction+' '+o.score+'% · Δ'+(o.delta_pct>0?'+':'')+fmt(o.delta_pct,1)+'%'})));
        if(rows.length){if(!rows.some(o=>o.id===selected))selected=rows[rows.length-1].id;focus(selected);}else clearDetails();
    }
    async function refresh(){
        if(busy)return;busy=true;const c=new AbortController(),timer=setTimeout(()=>c.abort(),15000);
        try{
            const r=await fetch('/api/variable-opportunities?date='+encodeURIComponent(document.getElementById('op-date').value),{cache:'no-store',signal:c.signal});if(!r.ok)throw Error('Unavailable');report=await r.json();series.setData(report.candles);render();
            put('op-period',eat(report.period_start)+'–'+eat(report.period_end)+' EAT');
            put('op-status',report.summary.total+' candidates · '+report.summary.buy+' BUY / '+report.summary.sell+' SELL · Telegram '+(report.telegram_enabled&&report.enabled?'enabled':'disabled')+' · '+(report.feed_fresh?'M5 feed receiving':'viewing saved history; waiting for fresh M5 feed'));
            if(first&&report.candles.length){chart.timeScale().fitContent();first=false;}
        }catch(_){put('op-status','Cannot refresh the opportunity stream. Retrying in 10 seconds.');}finally{clearTimeout(timer);busy=false;}
    }
    chart.subscribeClick(p=>{if(!p.time)return;const o=visible().find(o=>o.candle_time===p.time);if(o)focus(o.id);});
    document.getElementById('op-date-form').addEventListener('submit',e=>{e.preventDefault();first=true;selected=null;refresh();});
    document.getElementById('op-reset').addEventListener('click',()=>chart.timeScale().fitContent());
    for(const e of document.querySelectorAll('.op-tier,#op-buy,#op-sell'))e.addEventListener('change',render);
    new ResizeObserver(()=>chart.resize(host.clientWidth,host.clientHeight)).observe(host);
    refresh();setInterval(refresh,10000);
})();
</script>
@endpush
