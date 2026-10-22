@include('components.setup-card-styles')
@push('styles')
<style>
#setup-dashboard{scroll-margin-top:76px}
#setup-focus-content .of-table{font-size:.72rem}
#setup-history tr[aria-selected="true"] td{background:rgba(245,200,66,.065)}
#setup-focus-select{min-width:0;width:100%;max-width:540px;background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0}
</style>
@endpush
<section id="setup-dashboard" class="mb-4" aria-label="Selected setup dashboard">
    <div class="d-flex align-items-end justify-content-between flex-wrap gap-3 mb-3">
        <div class="flex-grow-1" style="min-width:220px">
            <label for="setup-focus-select" class="small fw-semibold d-block mb-2">Setup dashboard · select a saved setup</label>
            <select id="setup-focus-select" class="form-select form-select-sm">
                @if(!$focusedSetup)<option value="">No saved setups</option>@endif
                @foreach($setupChoices as $choice)
                <option value="{{ $choice->id }}" @selected($focusedSetup?->id === $choice->id)>#{{ $choice->id }} · {{ $choice->direction }} · {{ strtoupper($choice->timeframe) }} · {{ ucfirst($choice->status) }} · {{ $choice->created_at->format('d M H:i') }} UTC</option>
                @endforeach
            </select>
        </div>
        <a id="focused-chart-link" class="btn btn-sm btn-outline-warning" @if($focusedSetup && $focusedSetup->source === config('orderflow.connector') && $focusedSetup->instrument === config('clusterdelta.instrument')) href="{{ route('dashboard', ['setup_id' => $focusedSetup->id]) }}" @else hidden @endif>Open this setup on chart →</a>
    </div>
    <div class="small mb-3" style="color:var(--text-muted-custom)">BUY and SELL signals are separate records. Each keeps its own entry, stop, targets, score and TP hits. Your selection stays fixed as new signals arrive.</div>
    <div id="setup-focus-status" class="small mb-2" role="status" aria-live="polite" style="color:var(--text-muted-custom)">{{ $focusedSetup ? 'Viewing setup #'.$focusedSetup->id.' · refreshes every 10s' : 'Waiting for a saved setup' }}</div>
    <div id="setup-focus-content">@include('setups.focus-detail')</div>
</section>

@push('scripts')
<script>
(() => {
    const selector=document.getElementById('setup-focus-select'),content=document.getElementById('setup-focus-content');
    const status=document.getElementById('setup-focus-status'),chartLink=document.getElementById('focused-chart-link');
    const initialId=@json($focusedSetup?->id);
    let selected=initialId===null?null:String(initialId),version=@json($focusVersion);
    let generation=0,controller=null,pending=false;
    function valid(id){return /^[1-9]\d*$/.test(String(id));}
    function markRows(){
        document.querySelectorAll('#setup-history [id^="setup-row-"]').forEach(row=>row.setAttribute('aria-selected',row.id==='setup-row-'+selected?'true':'false'));
        document.querySelectorAll('[data-focus-setup]').forEach(link=>link.setAttribute('aria-current',link.dataset.focusSetup===selected?'true':'false'));
    }
    function remember(id,method='replaceState'){
        const url=new URL(location.href);url.searchParams.set('setup_id',id);history[method]({setupId:id},'',url);
        for(const inputId of ['setup-focus-filter','observation-focus-filter']){
            const input=document.getElementById(inputId);if(input){input.value=id;input.disabled=false;}
        }
        document.querySelectorAll('a[href]').forEach(link=>{
            if(link.hasAttribute('data-focus-setup'))return;
            const target=new URL(link.href,location.href);
            if(target.origin===location.origin&&target.pathname==='/setups'){target.searchParams.set('setup_id',id);link.href=target.href;}
        });
    }
    function message(text){content.replaceChildren();const panel=document.createElement('div');panel.className='card-panel p-4 small';panel.textContent=text;content.appendChild(panel);}
    async function refresh({switching=false}={}){
        if(!selected||(!switching&&pending))return;
        if(switching)controller?.abort();
        const id=selected,request=++generation;
        controller=new AbortController();const local=controller,timeout=setTimeout(()=>local.abort(),8000);pending=true;
        try{
            const response=await fetch('/api/setups/'+encodeURIComponent(id)+'/focus',{cache:'no-store',signal:local.signal});
            if(!response.ok)throw new Error(response.status===404?'This setup is no longer available.':'Could not refresh this setup.');
            const data=await response.json();
            if(request!==generation||id!==selected)return;
            if(String(data.setup_id)!==id)throw new Error('Setup selection did not match the response.');
            if(data.version!==version){content.innerHTML=data.html;version=data.version;}
            chartLink.hidden=!data.chart_url;if(data.chart_url)chartLink.href=data.chart_url;
            status.textContent='Viewing setup #'+id+' · checked '+new Date().toLocaleTimeString()+' · selection pinned';
            status.style.color='var(--text-muted-custom)';markRows();
        }catch(error){
            if(request!==generation||id!==selected)return;
            status.textContent='Setup #'+id+' · '+(error.name==='AbortError'?'Refresh timed out.':error.message)+' Retrying in 10s.';status.style.color='var(--gold)';
            if(switching||!version){message('Setup #'+id+' cannot be shown until its data is available.');chartLink.hidden=true;}
        }finally{clearTimeout(timeout);if(request===generation)pending=false;}
    }
    function select(id,{push=true,scroll=false}={}){
        if(!valid(id))return;id=String(id);
        if(id===selected){if(scroll)document.getElementById('setup-dashboard').scrollIntoView({behavior:'smooth',block:'start'});return;}
        selected=id;version=null;selector.value=id;chartLink.hidden=true;
        if(push)remember(id,'pushState');else remember(id);
        message('Loading setup #'+id+'…');status.textContent='Loading setup #'+id+'…';markRows();
        refresh({switching:true});
        if(scroll)document.getElementById('setup-dashboard').scrollIntoView({behavior:'smooth',block:'start'});
    }
    selector.addEventListener('change',()=>select(selector.value));
    document.addEventListener('click',event=>{
        const link=event.target.closest('[data-focus-setup]');
        if(!link||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
        event.preventDefault();
        if(![...selector.options].some(option=>option.value===link.dataset.focusSetup))selector.add(new Option(link.dataset.focusLabel||'Setup #'+link.dataset.focusSetup,link.dataset.focusSetup));
        select(link.dataset.focusSetup,{scroll:true});
    });
    window.addEventListener('popstate',()=>{
        const id=new URL(location.href).searchParams.get('setup_id')||initialId;
        if(valid(id)){
            if(![...selector.options].some(option=>option.value===String(id)))selector.add(new Option('Setup #'+id,String(id)));
            select(id,{push:false});
        }
    });
    window.SetupFocus={
        syncChoices(incoming){
            if(!incoming)return;const previous=[...selector.options].find(option=>option.value===selected)?.cloneNode(true);
            selector.replaceChildren(...[...incoming.options].filter(option=>option.value).map(option=>option.cloneNode(true)));
            if(selected){
                if(![...selector.options].some(option=>option.value===selected)&&previous)selector.add(previous);
                selector.value=selected;remember(selected);
            }else if(selector.options.length){select(selector.options[0].value,{push:false});}
            markRows();
        }
    };
    if(selected){remember(selected);markRows();refresh();}
    setInterval(()=>refresh(),10000);
})();
</script>
@endpush
