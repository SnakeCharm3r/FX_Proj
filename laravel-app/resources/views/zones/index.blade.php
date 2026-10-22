@extends('layouts.app')
@section('title','H1 Zone Monitor')
@section('content')
<div class="d-flex justify-content-between gap-3 flex-wrap mb-3">
    <div><h5>H1 demand &amp; supply observations</h5><div class="small" style="color:var(--text-muted-custom)">Silent monitoring · H1 price zones with M5 delta and native GC footprint evidence · all times EAT</div></div>
    <form id="zone-date-form" class="d-flex gap-2"><input type="date" id="zone-date" class="form-control form-control-sm" value="{{ request('date',now('Africa/Dar_es_Salaam')->format('Y-m-d')) }}"><button class="btn btn-sm btn-warning">View</button><a id="zone-export" class="btn btn-sm btn-outline-warning">CSV</a></form>
</div>
<div id="zone-health" class="small mb-3" role="status">Loading recorded observations…</div>
<div id="zone-summary" class="d-flex flex-wrap gap-2 mb-3"></div>
<div class="d-flex gap-2 align-items-center mb-3"><label for="zone-focus" class="small">Focus range</label><select id="zone-focus" class="form-select form-select-sm" style="max-width:440px"><option value="">All ranges visited today</option></select></div>
<div class="card-panel mb-3">
    <div class="card-header-custom"><span>XAUUSD · H1 · demand and supply</span><span>Thin boxes · rejection and retest markers</span></div>
    <div id="price-chart-wrap" style="position:relative"><div id="tv-chart" style="height:500px"></div><svg id="sd-zone-overlay" role="img" aria-label="Demand and supply zones" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:4"></svg></div>
    @include('dashboard.price-zones')
</div>
<div class="card-panel mb-3 p-3 small">
    Approach → touch → rejection records a possible range reaction. A closed H1 candle beyond the far wick edge records a break.
    After a break, an M5 return that closes back on the breakout side records a continuation candidate; a close through the opposite edge records a counter-breakout reclaim candidate.
    These observations carry their delta, rolling CVD and footprint evidence independently of live entry approval. Missing footprint data stays unavailable.
    Historical replay uses final archived candle revisions; it does not claim these readings were received live at the time.
</div>
<div class="card-panel">
    <div class="card-header-custom"><span>Today's price and orderflow evidence</span><select id="zone-event-filter" class="form-select form-select-sm" style="max-width:240px"><option value="">All events</option><option>APPROACH</option><option>TOUCH</option><option>REJECTION</option><option>BREAK</option><option>BROKEN_RETEST</option><option>CONTINUATION</option><option>COUNTER_BREAKOUT</option></select></div>
    <div class="table-responsive" style="max-height:680px"><table class="table of-table mb-0"><thead><tr><th>M5 opened → confirmed EAT</th><th>Zone / range</th><th>Observation</th><th>Direction</th><th>Price</th><th>Volume</th><th>Delta / %</th><th>CVD</th><th>Absorption</th><th>Footprint</th><th>Evidence</th></tr></thead><tbody id="zone-events"><tr><td colspan="11">Loading…</td></tr></tbody></table></div>
</div>
@endsection
@push('scripts')
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(() => {
    const container=document.getElementById('tv-chart');
    const chart=LightweightCharts.createChart(container,{height:500,layout:{background:{color:'#141720'},textColor:'#8d97b0'},grid:{vertLines:{color:'#252a38'},horzLines:{color:'#252a38'}},timeScale:{timeVisible:true},localization:{timeFormatter:time=>new Date((Number(time)+10800)*1000).toISOString().slice(5,16).replace('T',' ')+' EAT'}});
    const series=chart.addCandlestickSeries({upColor:'#00c076',downColor:'#ff3b5c',borderVisible:false,wickUpColor:'#00c076',wickDownColor:'#ff3b5c'});
    let candles=[],report=null,busy=false,first=true;
    window.switchTimeframe=()=>chart.timeScale().fitContent();
    const overlay=GFPriceZones.mount(chart,()=>series,()=>candles,()=>3600);
    const time=value=>new Date((value+10800)*1000).toISOString().slice(5,16).replace('T',' ');
    const num=(value,digits=2)=>value==null?'—':Number(value).toLocaleString(undefined,{minimumFractionDigits:digits,maximumFractionDigits:digits});
    function render(){
        const body=document.getElementById('zone-events');body.replaceChildren();
        const selected=document.getElementById('zone-event-filter').value;
        const focus=document.getElementById('zone-focus').value;
        for(const event of (report?.events||[]).filter(e=>(!selected||e.event_type===selected)&&(!focus||String(e.price_zone_id)===focus)).slice().reverse()){
            const e=event.evidence, fp=e.footprint;
            const row=body.insertRow();
            const values=[time(event.candle_time)+' → '+time(event.decision_time).slice(-5),'#'+event.price_zone_id+' '+e.zone_type+' '+num(e.zone_low)+'–'+num(e.zone_high),event.event_type.replaceAll('_',' '),event.direction,num(event.price),num(e.volume,0),num(e.delta,0)+' / '+(e.delta_pct>0?'+':'')+num(e.delta_pct)+'%',num(e.cvd,0),e.absorption?.qualified?e.absorption.type:'Sequence incomplete',e.footprint_status==='matched'?'Matched · buy stack '+fp.footprint_bull_stack+' / sell '+fp.footprint_bear_stack+' · POC '+fp.poc_rejection:e.footprint_status,event.historical?'Historical replay':'Observed close'];
            for(const [index,value] of values.entries()){const td=row.insertCell();td.textContent=value;if(index===3)td.style.color=event.direction==='BUY'?'var(--green-bull)':'var(--red-bear)';}
        }
        if(!body.rows.length){const cell=body.insertRow().insertCell();cell.colSpan=11;cell.textContent='No recorded events for this selection.';}
    }
    function markers(){
        if(!report)return;
        const focus=document.getElementById('zone-focus').value,valid=new Set(candles.map(b=>b.time)),groups=new Map();
        for(const e of report.events){
            if(focus&&String(e.price_zone_id)!==focus)continue;
            if(!['REJECTION','BREAK','CONTINUATION','COUNTER_BREAKOUT'].includes(e.event_type))continue;
            const t=Math.floor(e.candle_time/3600)*3600;if(!valid.has(t))continue;
            const key=t+':'+e.direction,group=groups.get(key)||{time:t,direction:e.direction,labels:[],count:0};
            group.labels.push(e.event_type==='COUNTER_BREAKOUT'?'reclaim':e.event_type.toLowerCase());group.count++;groups.set(key,group);
        }
        series.setMarkers([...groups.values()].sort((a,b)=>a.time-b.time).map(g=>({time:g.time,position:g.direction==='BUY'?'belowBar':'aboveBar',shape:g.direction==='BUY'?'arrowUp':'arrowDown',color:g.direction==='BUY'?'#00c076':'#ff3b5c',text:g.direction+' '+[...new Set(g.labels)].join('/')+(g.count>1?' ×'+g.count:'')})));
    }
    async function refresh(){
        if(busy)return;busy=true;
        const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),15000);
        const date=document.getElementById('zone-date').value;
        document.getElementById('zone-export').href='/api/zone-study?date='+encodeURIComponent(date)+'&format=csv';
        try{
            const response=await fetch('/api/zone-study?date='+encodeURIComponent(date),{cache:'no-store',signal:controller.signal});if(!response.ok)throw Error('Unavailable');
            report=await response.json();candles=report.candles;series.setData(candles);
            const focus=document.getElementById('zone-focus'),chosen=focus.value,visited=new Set(report.events.map(e=>e.price_zone_id));
            focus.replaceChildren(new Option('All ranges',''));
            for(const z of report.zones.filter(z=>visited.has(z.id)))focus.add(new Option('#'+z.id+' '+z.type+' '+num(z.low)+'–'+num(z.high),String(z.id)));
            focus.value=[...focus.options].some(o=>o.value===chosen)?chosen:'';
            markers();
            if(first){chart.timeScale().setVisibleRange({from:report.start-12*3600,to:report.end+3600});first=false;}overlay.draw();
            const summary=document.getElementById('zone-summary');summary.replaceChildren();
            for(const [type,count] of Object.entries(report.summary)){const item=document.createElement('div');item.className='metric-pill small';item.textContent=type.replaceAll('_',' ')+': '+count;summary.appendChild(item);}
            document.getElementById('zone-health').textContent=(report.monitor.monitor_running?'Monitor active':'Monitor needs attention')+' · '+report.version+' · '+report.events.length+' observations · '+report.footprint_matched_events+' with matched native footprint · '+date+' EAT';
            render();
        }catch(_){document.getElementById('zone-health').textContent='Unable to refresh zone observations; retrying.';}
        finally{clearTimeout(timeout);busy=false;}
    }
    document.getElementById('zone-date-form').addEventListener('submit',event=>{event.preventDefault();first=true;refresh();});
    document.getElementById('zone-event-filter').addEventListener('change',render);
    document.getElementById('zone-focus').addEventListener('change',()=>{render();markers();overlay.draw();});
    new ResizeObserver(()=>chart.resize(container.clientWidth,500)).observe(container);
    refresh();setInterval(refresh,10000);
})();
</script>
@endpush
