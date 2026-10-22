<script>
(() => {
    const storageKey = @json('goldflow.zone-alerts.'.config('orderflow.connector').'.'.config('clusterdelta.instrument', 'GC'));
    let memoryCursor = null, pending = false;
    function cursor() {
        try { const value=localStorage.getItem(storageKey); return value===null?memoryCursor:Number(value); }
        catch (_) { return memoryCursor; }
    }
    function remember(value) { memoryCursor=value; try { localStorage.setItem(storageKey,String(value)); } catch (_) {} }
    async function poll() {
        if(pending)return;pending=true;
        const after=cursor(),controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),8000);
        try {
            const response=await fetch('/api/zone-alerts'+(Number.isSafeInteger(after)&&after>=0?'?after='+after:''),{cache:'no-store',signal:controller.signal});
            if(!response.ok)throw new Error('Zone notifications unavailable');
            const data=await response.json();
            for(const alert of data.alerts){
                if(alert.id<=(cursor()??0))continue;
                remember(alert.id);
                // Zone observations are silent: update the badge and durable log without a toast or sound.
                const badge=document.getElementById('alert-badge');
                badge.style.display='inline';badge.textContent=Number(badge.textContent||0)+1;
                window.dispatchEvent(new CustomEvent('zone-arrival',{detail:alert}));
            }
            if(!data.alerts.length||data.cursor>(cursor()??0))remember(data.cursor);
        } catch (_) {
            // The next poll catches up from the durable alert log.
        } finally { clearTimeout(timeout);pending=false; }
    }
    poll();setInterval(poll,5000);
})();
</script>
