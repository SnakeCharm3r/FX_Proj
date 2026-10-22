@once
@push('styles')
<style>
.metric-pill.tp-card{border:1px solid var(--bg-border);transition:border-color .2s}
.metric-pill.tp-card.tp-hit{border-color:rgba(0,192,118,.7);animation:tp-card-glow 2s ease-in-out infinite}
.tp-led{display:inline-block;width:8px;height:8px;border-radius:50%;background:#00e28c;box-shadow:0 0 8px rgba(0,226,140,.8);animation:tp-led-blink 2s ease-in-out infinite;flex-shrink:0}
.tp-led[hidden]{display:none}
.tp-hit .value{color:#00e28c!important}
.tp-hit-label{font-size:.58rem;color:#6fe9b6;letter-spacing:.04em}
.metric-pill.sl-hit{border-color:var(--red-bear);background:rgba(255,59,92,.09)}
.sl-hit-label{font-size:.58rem;color:var(--red-bear);letter-spacing:.04em}
@keyframes tp-card-glow{0%,100%{background:rgba(0,192,118,.04);box-shadow:0 0 0 0 rgba(0,192,118,0)}50%{background:rgba(0,192,118,.12);box-shadow:0 0 12px 1px rgba(0,192,118,.18)}}
@keyframes tp-led-blink{0%,100%{opacity:.35}50%{opacity:1}}
@media(prefers-reduced-motion:reduce){.metric-pill.tp-card.tp-hit,.tp-led{animation:none}.tp-led{opacity:1}}
</style>
@endpush
@endonce
