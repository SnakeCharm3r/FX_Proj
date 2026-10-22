<div class="p-3" style="border-top:1px solid var(--bg-border)">
    <div class="d-flex justify-content-between gap-2 flex-wrap mb-2">
        <span class="small fw-semibold">1H demand &amp; supply · wick to candle body</span>
        <div class="d-flex gap-3 small">
            <button type="button" id="sd-h1-view" class="btn btn-sm p-0" style="color:var(--gold)">1H view</button>
            <label><input id="sd-show" type="checkbox" checked> Show boxes</label>
            <label><input id="sd-invalidated" type="checkbox" checked> Include invalidated</label>
        </div>
    </div>
    <div class="small mb-2" id="sd-status" role="status" aria-live="polite" style="color:var(--text-muted-custom)">Checking 1H zones…</div>
    <div class="small mb-2" style="color:var(--text-muted-custom)"><span style="color:#68bfa7">□ Demand</span> · <span style="color:#db8e8a">□ Supply</span> · broken zones remain dashed for retest observation. <a href="{{ route('zones.index') }}" style="color:var(--gold)">View today's silent zone observations →</a></div>
    <details>
        <summary class="small" style="color:var(--gold);cursor:pointer" id="sd-summary">Zone levels and detection rules</summary>
        <div class="table-responsive mt-2" style="max-height:240px;overflow:auto">
            <table class="table of-table mb-0"><thead><tr><th>1H zone</th><th>Broker price range</th><th>Origin UTC</th><th>Confirmed UTC</th><th>Status</th></tr></thead>
                <tbody id="sd-list"><tr><td colspan="5">Waiting for the detector.</td></tr></tbody>
            </table>
        </div>
        <div class="small mt-2" id="sd-rules" style="color:var(--text-muted-custom)"></div>
        <div class="small mt-2" style="color:var(--text-muted-custom)">Zones use broker prices and confirmed hourly candles. Missing hours are filled only by complete sets of four M15 candles. A closed H1 candle beyond the far wick edge breaks a zone; its dashed box stays visible for retests and reclaims. Arrival checks use fresh sampled prices, about every 10 seconds. Notifications update the badge and log silently.</div>
    </details>
</div>

@push('scripts')
<script>
window.GFPriceZones = {
    mount(chart, getSeries, getCandles, getPeriod) {
        const svg = document.getElementById('sd-zone-overlay'), wrap = document.getElementById('price-chart-wrap');
        const ns = 'http://www.w3.org/2000/svg';
        let state = null, pending = false, frame = null;
        const show = document.getElementById('sd-show'), includeInvalid = document.getElementById('sd-invalidated');
        const filtered = () => (state?.zones || []).filter(zone =>
            (includeInvalid.checked || zone.status !== 'invalidated')
            && (!document.getElementById('zone-focus')?.value || String(zone.id)===document.getElementById('zone-focus').value));
        const price = value => Number(value).toFixed(3);
        function coordinate(time) {
            const bars = getCandles(); if (!bars.length) return null;
            if (time < bars[0].time) return -1;
            let i = bars.findIndex(bar => bar.time >= time);
            if (i < 0) {
                const x = chart.timeScale().timeToCoordinate(bars[bars.length-1].time);
                const next = chart.timeScale().logicalToCoordinate(bars.length);
                if (x === null || next === null) return null;
                return x + (time - bars[bars.length-1].time) / getPeriod() * (next - x);
            }
            const right = chart.timeScale().timeToCoordinate(bars[i].time);
            if (i === 0 || bars[i].time === time) return right;
            const left = chart.timeScale().timeToCoordinate(bars[i-1].time);
            if (left === null || right === null) return null;
            return left + (time - bars[i-1].time) / (bars[i].time - bars[i-1].time) * (right-left);
        }
        function paint() {
            frame = null; svg.replaceChildren();
            if (!state || !show.checked || !getCandles().length) return;
            const width = wrap.clientWidth, height = wrap.clientHeight;
            const paneWidth = Math.max(0, width - chart.priceScale('right').width()), paneHeight = Math.max(0, height-28);
            svg.setAttribute('viewBox', '0 0 '+width+' '+height);
            const defs = document.createElementNS(ns,'defs'), clip = document.createElementNS(ns,'clipPath'), area = document.createElementNS(ns,'rect');
            clip.id='sd-zone-clip';area.setAttribute('width',paneWidth);area.setAttribute('height',paneHeight);clip.appendChild(area);defs.appendChild(clip);svg.appendChild(defs);
            const group = document.createElementNS(ns,'g');group.setAttribute('clip-path','url(#sd-zone-clip)');svg.appendChild(group);
            for (const zone of filtered()) {
                const y1=getSeries().priceToCoordinate(zone.high),y2=getSeries().priceToCoordinate(zone.low);
                const origin=coordinate(zone.origin_time),end=paneWidth;
                if (y1===null||y2===null||origin===null||end===null||end<=0||origin>=paneWidth) continue;
                const x=Math.max(0,origin),right=Math.min(paneWidth,end),y=Math.min(y1,y2),h=Math.max(1,Math.abs(y2-y1));
                if (right<=x||y>paneHeight||y+h<0) continue;
                const color=zone.type==='DEMAND'?'64,178,145':'212,113,113';
                const verified=zone.status==='active'&&state.monitor_running;
                const rect=document.createElementNS(ns,'rect');rect.classList.add('sd-zone-box');rect.dataset.zoneId=zone.id;
                for (const [name,value] of Object.entries({x,y,width:right-x,height:h,fill:`rgba(${color},${verified ? .035 : .015})`,stroke:`rgba(${color},${verified ? .45 : .24})`,'stroke-width':1}))rect.setAttribute(name,value);
                if(!verified)rect.setAttribute('stroke-dasharray','4 4');
                const title=document.createElementNS(ns,'title');title.textContent=zone.type+' H1 '+price(zone.low)+'–'+price(zone.high)+' · '+zone.status;rect.appendChild(title);group.appendChild(rect);
                const label=document.createElementNS(ns,'text');label.setAttribute('x',x+5);label.setAttribute('y',Math.max(12,y+12));label.setAttribute('fill',`rgba(${color},.7)`);label.setAttribute('font-size','10');label.setAttribute('font-family','monospace');label.textContent=zone.type+' H1 #'+zone.id+(zone.status==='invalidated'?' · broken → potential '+(zone.type==='DEMAND'?'supply':'demand'):(verified?'':' · '+zone.status));group.appendChild(label);
            }
        }
        function draw() { if(frame===null)frame=requestAnimationFrame(paint); }
        function renderList() {
            const list=document.getElementById('sd-list');list.replaceChildren();const zones=filtered();
            for(const zone of zones){
                const row=list.insertRow();
                const status=zone.status==='unverified'?'Unverified · missing hourly history':zone.status;
                [zone.type,price(zone.low)+' – '+price(zone.high),new Date(zone.origin_time*1000).toISOString().slice(0,16).replace('T',' '),new Date(zone.confirmed_time*1000).toISOString().slice(0,16).replace('T',' '),status].forEach((text,i)=>{
                    const cell=row.insertCell();cell.textContent=text;if(i===0)cell.style.color=zone.type==='DEMAND'?'#68bfa7':'#db8e8a';
                });
            }
            if(!zones.length){const cell=list.insertRow().insertCell();cell.colSpan=5;cell.textContent='No confirmed zones meet these rules in the available history.';}
            document.getElementById('sd-summary').textContent=zones.length+' zone'+(zones.length===1?'':'s')+' · levels and detection rules';
            draw();
        }
        async function refresh() {
            if(pending)return;pending=true;const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),8000);
            try{
                const response=await fetch('/api/price-zones',{cache:'no-store',signal:controller.signal});if(!response.ok)throw new Error('Zones unavailable');
                state=await response.json();
                const active=state.zones.filter(z=>z.status==='active').length,unknown=state.zones.filter(z=>z.status==='unverified').length;
                document.getElementById('sd-status').textContent=state.reason+' '+active+' active · '+unknown+' unverified · '+(state.closed_h1_bars||0)+' closed H1 candles.';
                document.getElementById('sd-status').style.color=state.monitor_running&&state.status==='watching'?'var(--text-muted-custom)':'var(--gold)';
                const rules=state.rules;
                document.getElementById('sd-rules').textContent=rules.version==='rejection-swing-v2'
                    ?'Demand: lower-wick rejection at an H1 swing low. Supply: upper-wick rejection at an H1 swing high. Candle colour does not restrict the zone. Wick ≥ '+Math.round(100*rules.rejection_wick_ratio)+'%; confirmed after '+rules.swing_bars+' later H1 closes. Shared with the scalp tester.'
                    :'Last opposite candle at a '+rules.pivot_lookback+'-bar local extreme; rejection wick ≥ '+Math.round(100*rules.rejection_wick_ratio)+'% of range; departure ≥ '+rules.departure_atr+' × ATR.';
                renderList();
            }catch(_){state=null;svg.replaceChildren();document.getElementById('sd-status').textContent='Cannot verify 1H zones. Retrying in 10 seconds.';document.getElementById('sd-status').style.color='var(--gold)';document.getElementById('sd-list').replaceChildren();}
            finally{clearTimeout(timeout);pending=false;}
        }
        chart.timeScale().subscribeVisibleLogicalRangeChange(draw);
        for(const event of ['wheel','pointermove','pointerup'])wrap.addEventListener(event,draw,{passive:true});
        new ResizeObserver(draw).observe(wrap);
        show.addEventListener('change',draw);includeInvalid.addEventListener('change',renderList);
        document.getElementById('sd-h1-view').addEventListener('click',()=>{switchTimeframe('1h');document.getElementById('tv-chart').scrollIntoView({behavior:'smooth',block:'center'});});
        setInterval(refresh,10000);refresh();
        return {draw};
    }
};
</script>
@endpush
