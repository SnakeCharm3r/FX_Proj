@extends('layouts.app')
@section('title', 'Footprint')
@push('styles')
<style>
.fp-grid{--fp-paper:#fffbd9;--fp-selling:#f59683;--fp-buying:#b5e991;--fp-poc:#f6bd46;display:grid;grid-auto-rows:25px;min-width:max-content;font-family:'JetBrains Mono',monospace;font-size:11px;font-variant-numeric:tabular-nums;isolation:isolate;background:#282a2f}
.fp-price{position:sticky;left:0;background:#20232b;color:#bdc6d6;z-index:2;text-align:right;padding:4px 10px;border-bottom:1px solid #30343e}
.fp-cell{position:relative;display:grid;grid-template-columns:1fr 1fr;margin:0 5px;min-width:0}
.fp-cell span{display:block;min-width:0;padding:4px 8px;color:#252820;line-height:16px;border-bottom:1px solid #66644e;font-weight:500}
.fp-bid{text-align:right;background:color-mix(in srgb,var(--fp-selling) var(--fp-strength,0%),var(--fp-paper));border-right:1px solid #77755c}
.fp-ask{text-align:left;background:color-mix(in srgb,var(--fp-buying) var(--fp-strength,0%),var(--fp-paper))}
.fp-head{position:sticky;top:0;z-index:3;background:#20232b;color:#c8d1e0;text-align:center;border-left:1px solid #343944;padding:5px;grid-row:span 3;font-size:11px}
.fp-poc::after{content:'';position:absolute;inset:0;border:2px solid var(--fp-poc);border-left-width:4px;pointer-events:none;z-index:1}
.fp-poc span{font-weight:700}
.fp-cell .fp-ask-imb{color:#164923;box-shadow:inset 0 -3px #26783d;font-weight:800}
.fp-cell .fp-bid-imb{color:#68251c;box-shadow:inset 0 -3px #b34837;font-weight:800}
.fp-legend{display:flex;flex-wrap:wrap;gap:10px 20px;font-size:12px;color:#bbc4d4}
.fp-legend-item{display:inline-flex;align-items:center;gap:7px}
.fp-swatch{width:18px;height:12px;border-radius:2px;display:inline-block;border:1px solid #77755c}
.fp-swatch-paper{background:#fffbd9}.fp-swatch-bid{background:#f59683}.fp-swatch-ask{background:#b5e991}.fp-swatch-poc{background:transparent;border:2px solid #f6bd46}
</style>
@endpush
@section('content')
<div class="d-flex justify-content-between flex-wrap gap-3 mb-3">
 <div><h5 class="mb-1"><i class="bi bi-grid-3x3 me-2" style="color:var(--gold)"></i>GC Footprint</h5>
 <small style="color:var(--text-muted-custom)">Real ClusterDelta bid × ask at each futures price, exported from your XAUUSD MT5 chart.</small></div>
 <div class="d-flex gap-2 align-items-center">
  <select id="fp-tf" class="form-select form-select-sm" aria-label="Footprint timeframe" style="width:85px">
   @foreach(['1m','5m','15m','30m','1h','4h','1d'] as $tf)<option value="{{ $tf }}">{{ $tf }}</option>@endforeach
  </select>
  <select id="fp-bars" class="form-select form-select-sm" aria-label="Visible footprint candles" style="width:110px"><option value="3">3 candles</option><option value="6" selected>6 candles</option><option value="12">12 candles</option></select>
  <select id="fp-group" class="form-select form-select-sm" aria-label="Ticks per price row" style="width:110px"><option value="1">1 tick</option><option value="2">2 ticks</option><option value="5">5 ticks</option><option value="10">10 ticks</option></select>
  <button id="fp-refresh" class="btn btn-sm btn-outline-warning" type="button">Refresh</button>
 </div>
</div>
<div class="card-panel p-3 mb-3" role="status" aria-live="polite"><div id="fp-status">Checking footprint feed…</div><small id="fp-source" style="color:var(--text-muted-custom)">GC futures prices are separate from broker XAUUSD prices.</small></div>
<div class="row g-2 mb-3">
 @foreach(['volume'=>'Volume','delta'=>'Delta','poc'=>'Candle POC','delta_min'=>'Min delta','delta_max'=>'Max delta'] as $id=>$label)
 <div class="col"><div class="metric-pill"><div class="label">{{ $label }}</div><div class="value" id="fp-{{ $id }}">—</div></div></div>
 @endforeach
</div>
<div class="card-panel mb-3">
 <div class="card-header-custom"><span>Bid × Ask · native GC price ladder</span><small id="fp-group-label"></small></div>
 <div class="fp-legend px-3 py-2" aria-label="Footprint color legend">
  <span class="fp-legend-item"><i class="fp-swatch fp-swatch-paper" aria-hidden="true"></i>Lower volume</span>
  <span class="fp-legend-item"><i class="fp-swatch fp-swatch-bid" aria-hidden="true"></i>Bid · selling</span>
  <span class="fp-legend-item"><i class="fp-swatch fp-swatch-ask" aria-hidden="true"></i>Ask · buying</span>
  <span class="fp-legend-item"><i class="fp-swatch fp-swatch-poc" aria-hidden="true"></i>Candle POC</span>
 </div>
 <div id="fp-empty" class="p-4 text-center" style="color:#a8b2c8">Waiting for price-level footprint data.</div>
 <div id="fp-scroll" style="max-height:650px;overflow:auto;display:none"><div id="fp-grid" class="fp-grid"></div></div>
 <div class="p-3 small" style="color:var(--text-muted-custom)">Cream cells deepen to salmon for bid volume and green for ask volume. Color intensity is scaled within each candle. Amber outline: candle POC. Dark green/red underlines: native-tick diagonal imbalance, ratio ≥ 3:1 and major volume ≥ 10; zero opposing volume is excluded. Grouped rows show summed volume and omit imbalance highlights.</div>
</div>
<div class="card-panel">
 <div class="card-header-custom">Compare with the existing volume/delta feed</div>
 <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Candle UTC</th><th>Footprint volume / delta</th><th>Bar volume / delta</th><th>Difference volume / delta</th><th>GC close − broker close</th></tr></thead><tbody id="fp-comparison"></tbody></table></div>
 <div class="p-3 small" style="color:var(--text-muted-custom)">Comparisons match exact candle timestamps. Developing bars and separately refreshed feeds may differ. Futures prices are not shifted onto broker prices. This view calculates POC and imbalances from the exported cells; it does not import the vendor's chart colors/settings.</div>
</div>
@endsection
@push('scripts')
<script>
(() => {
 let snapshot=null, pending=false, lastSignature='', selectedTf=null;
 const tfControl=document.getElementById('fp-tf');
 const requested=new URLSearchParams(location.search).get('tf');
 tfControl.value=[...tfControl.options].some(o=>o.value===requested)?requested:'15m';
 window.GFTimeframe=tfControl.value;window.GFFollowActiveFeed=false;
 const number=n=>Number(n).toLocaleString('en-US',{maximumFractionDigits:2});
 const utc=t=>new Date(t*1000).toISOString().slice(5,16).replace('T',' ');
 function element(tag,text,className=''){const el=document.createElement(tag);el.textContent=text;if(className)el.className=className;return el;}
 function render(){
  const grid=document.getElementById('fp-grid');grid.replaceChildren();
  const comparison=document.getElementById('fp-comparison');comparison.replaceChildren();
  if(!snapshot?.bars?.length){
   document.getElementById('fp-empty').style.display='block';document.getElementById('fp-scroll').style.display='none';
   document.getElementById('fp-empty').textContent='No footprint export yet. Add FXClusterDeltaFootprint to your gold chart with DLL imports allowed; use the same timeframe and keep MetaTrader_GMT=0.';
   ['volume','delta','poc','delta_min','delta_max'].forEach(k=>document.getElementById('fp-'+k).textContent='—');return;
  }
  const bars=snapshot.bars.slice(-Number(document.getElementById('fp-bars').value));
  const tick=Number(snapshot.tick_size);
  const indices=bars.flatMap(b=>b.levels.map(l=>Math.round(l.price/tick)));
  const low=Math.min(...indices),high=Math.max(...indices);
  const step=Math.max(Number(document.getElementById('fp-group').value),Math.ceil((high-low+1)/450));
  const top=Math.floor(high/step),bottom=Math.floor(low/step);
  const digits=Math.min(8,Math.max(0,Math.ceil(-Math.log10(tick))));
  const price=p=>Number(p).toFixed(digits);
  document.getElementById('fp-group-label').textContent=step+' tick'+(step===1?'':'s')+' per row';
  grid.style.gridTemplateColumns=`90px repeat(${bars.length},160px)`;
  const priceHead=element('div','GC price','fp-head');priceHead.style.left='0';priceHead.style.zIndex='4';grid.appendChild(priceHead);
  const grouped=bars.map(bar=>{
   const map=new Map();
   for(const level of bar.levels){const i=Math.floor(Math.round(level.price/tick)/step);const cell=map.get(i)||{ask:0,bid:0};cell.ask+=level.ask;cell.bid+=level.bid;if(step===1){cell.ask_imbalance=level.ask_imbalance;cell.bid_imbalance=level.bid_imbalance;}map.set(i,cell);}
   const head=element('div','','fp-head');head.append(element('div',utc(bar.time)+' UTC'),element('div',bar.closed?'Closed':'Developing'),element('div','Bid × Ask · Δ '+number(bar.delta)));grid.appendChild(head);
   return {map,max:Math.max(1,...[...map.values()].flatMap(c=>[c.ask,c.bid])),poc:Math.floor(Math.round(bar.poc/tick)/step)};
  });
  for(let row=top;row>=bottom;row--){
   grid.appendChild(element('div',price(row*step*tick),'fp-price'));
   for(const data of grouped){
    const cell=data.map.get(row);const el=element('div','','fp-cell'+(data.poc===row?' fp-poc':''));
    if(cell){
     const bid=element('span',number(cell.bid),'fp-bid'+(cell.bid_imbalance?' fp-bid-imb':''));
     const ask=element('span',number(cell.ask),'fp-ask'+(cell.ask_imbalance?' fp-ask-imb':''));
     bid.style.setProperty('--fp-strength',(90*cell.bid/data.max).toFixed(1)+'%');
     ask.style.setProperty('--fp-strength',(90*cell.ask/data.max).toFixed(1)+'%');
     el.append(bid,ask);
     el.title='Volume '+number(cell.ask+cell.bid)+' · delta '+number(cell.ask-cell.bid);
    }else el.setAttribute('aria-label','No traded volume at this price');grid.appendChild(el);
   }
  }
  document.getElementById('fp-empty').style.display='none';document.getElementById('fp-scroll').style.display='block';
  const last=bars[bars.length-1];
  ['volume','delta','poc','delta_min','delta_max'].forEach(k=>document.getElementById('fp-'+k).textContent=k==='poc'?price(last[k]):number(last[k]));
  for(const bar of bars){const row=document.createElement('tr'),c=bar.comparison;
   [utc(bar.time),number(bar.volume)+' / '+number(bar.delta),c?number(c.bar_volume)+' / '+number(c.bar_delta):'No matching bar',c?number(c.volume_difference)+' / '+number(c.delta_difference):'—',c?number(c.price_basis):'—'].forEach(value=>row.appendChild(element('td',value)));
   if(!bar.source_totals_match)row.title='Vendor summary totals differ from the sum of price cells.';comparison.appendChild(row);
  }
  const signature=bars.map(b=>b.time).join(',')+step;
  if(signature!==lastSignature){const container=document.getElementById('fp-scroll');container.scrollTop=Math.max(0,(top-Math.floor(last.close/tick/step))*25-container.clientHeight/2);container.scrollLeft=container.scrollWidth;lastSignature=signature;}
 }
 async function refresh(){
  if(pending)return;pending=true;const tf=tfControl.value;selectedTf=tf;
  try{
   const response=await fetch('/api/orderflow/footprint?instrument=GC&tf='+encodeURIComponent(tf),{cache:'no-store'});
   if(!response.ok)throw new Error('Footprint request failed');const data=await response.json();if(tf!==tfControl.value)return;
   snapshot=data;const fresh=data.received_at && Date.now()/1000-data.received_at<60;
   const totalsMismatch=data.bars.some(b=>b.source_totals_match===false);
   document.getElementById('fp-status').textContent=!data.bars.length?'Waiting for footprint export':fresh?'Receiving real footprint cells'+(totalsMismatch?' · vendor summary differs from price-cell totals':''):'Footprint feed stale · showing last snapshot';
   document.getElementById('fp-status').style.color=fresh&&!totalsMismatch?'var(--green-bull)':'var(--gold)';
   document.getElementById('fp-source').textContent=data.bars.length?`GC futures · ${data.symbol} chart · ${tf} · tick ${data.tick_size} · received ${new Date(data.received_at*1000).toLocaleTimeString()} · source UTC offset ${data.source_utc_offset_minutes||0} min`:'GC futures prices are separate from broker XAUUSD prices.';
   render();
  }catch(_){document.getElementById('fp-status').textContent='Footprint request failed · retrying in 5s';document.getElementById('fp-status').style.color='var(--red-bear)';}
  finally{pending=false;if(selectedTf!==tfControl.value)refresh();}
 }
 tfControl.addEventListener('change',()=>{window.GFTimeframe=tfControl.value;lastSignature='';snapshot=null;render();refresh();});
 document.getElementById('fp-bars').addEventListener('change',render);document.getElementById('fp-group').addEventListener('change',render);
 document.getElementById('fp-refresh').addEventListener('click',refresh);setInterval(refresh,5000);refresh();
})();
</script>
@endpush
