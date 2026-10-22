<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'XAUUSD Orderflow') — GoldFlow Pro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold:       #f5c842;
            --gold-dim:   #b89b2e;
            --green-bull: #00c076;
            --red-bear:   #ff3b5c;
            --bg-base:    #0d0f14;
            --bg-card:    #141720;
            --bg-panel:   #1a1e2b;
            --bg-border:  #252a38;
            --text-muted-custom: #6c7793;
        }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-base); color: #e2e8f0; min-height: 100vh; overflow-x: hidden; }

        /* Sidebar */
        #sidebar { width:220px; min-height:100vh; background:var(--bg-card); border-right:1px solid var(--bg-border); position:fixed; top:0; left:0; z-index:1040; display:flex; flex-direction:column; transition:width .25s; }
        #sidebar .brand { padding:1.25rem 1.25rem 1rem; border-bottom:1px solid var(--bg-border); }
        #sidebar .brand span.logo-text { font-size:1.1rem; font-weight:700; color:var(--gold); letter-spacing:.03em; }
        #sidebar .brand small { display:block; font-size:.65rem; color:var(--text-muted-custom); letter-spacing:.08em; text-transform:uppercase; }
        #sidebar nav a { display:flex; align-items:center; gap:.65rem; padding:.65rem 1.25rem; color:#8d97b0; text-decoration:none; font-size:.875rem; font-weight:500; border-left:3px solid transparent; transition:all .18s; }
        #sidebar nav a:hover, #sidebar nav a.active { color:var(--gold); background:rgba(245,200,66,.06); border-left-color:var(--gold); }
        #sidebar nav a i { font-size:1rem; min-width:1.1rem; }
        #sidebar .sidebar-section { padding:.5rem 1.25rem .2rem; font-size:.65rem; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:var(--text-muted-custom); }
        #sidebar .connection-status { margin-top:auto; padding:1rem 1.25rem; border-top:1px solid var(--bg-border); }

        /* Main */
        #main-content { margin-left:220px; min-height:100vh; display:flex; flex-direction:column; }

        /* Topbar */
        #topbar { height:56px; background:var(--bg-card); border-bottom:1px solid var(--bg-border); display:flex; align-items:center; padding:0 1.5rem; gap:1rem; position:sticky; top:0; z-index:1030; }
        #topbar .price-ticker { font-family:'JetBrains Mono',monospace; font-size:1.3rem; font-weight:700; color:var(--gold); }
        #topbar .price-change { font-family:'JetBrains Mono',monospace; font-size:.8rem; }

        /* Cards */
        .card-panel { background:var(--bg-card); border:1px solid var(--bg-border); border-radius:10px; }
        .card-panel .card-header-custom { padding:.75rem 1rem; border-bottom:1px solid var(--bg-border); font-size:.8rem; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--text-muted-custom); display:flex; align-items:center; justify-content:space-between; }

        /* Signal badges */
        .signal-badge { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .75rem; border-radius:20px; font-size:.75rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }
        .signal-badge.buy  { background:rgba(0,192,118,.15); color:var(--green-bull); border:1px solid rgba(0,192,118,.3); }
        .signal-badge.sell { background:rgba(255,59,92,.15);  color:var(--red-bear);  border:1px solid rgba(255,59,92,.3); }
        .signal-badge.wait { background:rgba(245,200,66,.1);  color:var(--gold);      border:1px solid rgba(245,200,66,.2); }

        /* Metric pill */
        .metric-pill { background:var(--bg-panel); border:1px solid var(--bg-border); border-radius:8px; padding:.6rem .9rem; }
        .metric-pill .label { font-size:.65rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--text-muted-custom); }
        .metric-pill .value { font-family:'JetBrains Mono',monospace; font-size:1rem; font-weight:600; line-height:1.3; }

        /* Confluence ring */
        .confluence-ring { position:relative; width:80px; height:80px; }
        .confluence-ring svg { transform:rotate(-90deg); }
        .confluence-ring .score-text { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:'JetBrains Mono',monospace; }
        .confluence-ring .score-text span:first-child { font-size:1.2rem; font-weight:700; line-height:1; }
        .confluence-ring .score-text span:last-child { font-size:.55rem; color:var(--text-muted-custom); text-transform:uppercase; }

        /* Orderflow table */
        .of-table { font-size:.8rem; }
        .of-table thead th { background:var(--bg-panel); color:var(--text-muted-custom); font-size:.65rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase; border-color:var(--bg-border); padding:.5rem .75rem; }
        .of-table tbody td { border-color:var(--bg-border); padding:.5rem .75rem; font-family:'JetBrains Mono',monospace; vertical-align:middle; }
        .of-table tbody tr:hover { background:rgba(255,255,255,.025); }

        /* Delta bars */
        .delta-bar-wrap { height:6px; background:var(--bg-panel); border-radius:3px; overflow:hidden; }
        .delta-bar-pos  { height:100%; background:var(--green-bull); border-radius:3px; transition:width .4s; }
        .delta-bar-neg  { height:100%; background:var(--red-bear);   border-radius:3px; transition:width .4s; }

        /* Toast */
        .alert-toast-container { position:fixed; bottom:1.5rem; right:1.5rem; z-index:9999; min-width:320px; }

        /* Scrollbar */
        ::-webkit-scrollbar { width:5px; height:5px; }
        ::-webkit-scrollbar-track { background:var(--bg-base); }
        ::-webkit-scrollbar-thumb { background:var(--bg-border); border-radius:3px; }

        /* Pulse dot */
        .pulse-dot { width:8px; height:8px; border-radius:50%; display:inline-block; background:var(--green-bull); box-shadow:0 0 0 0 rgba(0,192,118,.5); animation:pulse 2s infinite; }
        .pulse-dot.off { background:#555; animation:none; box-shadow:none; }
        @keyframes pulse { 0%{box-shadow:0 0 0 0 rgba(0,192,118,.5)} 70%{box-shadow:0 0 0 8px rgba(0,192,118,0)} 100%{box-shadow:0 0 0 0 rgba(0,192,118,0)} }

        @media (max-width:768px) {
            #sidebar { width:0; overflow:hidden; }
            #sidebar.open { width:220px; }
            #main-content { margin-left:0; }
        }
    </style>
    @stack('styles')
</head>
<body>

<aside id="sidebar">
    <div class="brand">
        <span class="logo-text"><i class="bi bi-bar-chart-fill me-1" style="color:var(--gold)"></i> GoldFlow Pro</span>
        <small>XAUUSD Orderflow Engine</small>
        @if(config('orderflow.connector') === 'clusterdelta')
        <small>ClusterDelta {{ config('clusterdelta.instrument') }} · MT5 prices</small>
        @endif
    </div>
    <nav class="mt-2">
        <div class="sidebar-section">Analysis</div>
        <a href="{{ route('dashboard') }}"       class="{{ request()->routeIs('dashboard')       ? 'active':'' }}"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="{{ route('zones.index') }}" class="{{ request()->routeIs('zones.index')?'active':'' }}"><i class="bi bi-bounding-box"></i> H1 Zone Monitor</a>
        <a href="{{ route('setups.index') }}"    class="{{ request()->routeIs('setups.index','setups.show','setups.observation') ? 'active':'' }}"><i class="bi bi-lightning-charge-fill"></i> Setups</a>
        <a href="{{ route('setups.tester') }}"   class="{{ request()->routeIs('setups.tester') ? 'active':'' }}"><i class="bi bi-clipboard-data"></i> Strategy Tester</a>
        <a href="{{ route('signals.index') }}"   class="{{ request()->routeIs('signals.*')       ? 'active':'' }}"><i class="bi bi-activity"></i> Signal History</a>
        <div class="sidebar-section mt-2">Orderflow</div>
        <a href="{{ route('orderflow.footprint') }}" class="{{ request()->routeIs('orderflow.footprint') ? 'active':'' }}"><i class="bi bi-grid-3x3"></i> Footprint</a>
        <a href="{{ route('orderflow.delta') }}"     class="{{ request()->routeIs('orderflow.delta')     ? 'active':'' }}"><i class="bi bi-arrow-left-right"></i> Delta Analysis</a>
        <a href="{{ route('orderflow.imbalance') }}" class="{{ request()->routeIs('orderflow.imbalance') ? 'active':'' }}"><i class="bi bi-layers-fill"></i> Imbalance Zones</a>
        <a href="{{ route('orderflow.cvd') }}"       class="{{ request()->routeIs('orderflow.cvd')       ? 'active':'' }}"><i class="bi bi-graph-up-arrow"></i> CVD</a>
        <div class="sidebar-section mt-2">System</div>
        <a href="{{ route('monitor.index') }}" class="{{ request()->routeIs('monitor.index') ? 'active':'' }}"><i class="bi bi-pc-display"></i> System Monitor</a>
        <a href="{{ route('alerts.index') }}"  class="{{ request()->routeIs('alerts.*')   ? 'active':'' }}"><i class="bi bi-bell-fill"></i> Alerts</a>
        <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.*') ? 'active':'' }}"><i class="bi bi-gear-fill"></i> Settings</a>
    </nav>
    <div class="connection-status">
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="pulse-dot" id="ws-dot"></span>
            <span style="font-size:.75rem;color:var(--text-muted-custom)">WebSocket</span>
            <span style="font-size:.7rem;color:#555;margin-left:auto" id="ws-label">Connecting…</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="pulse-dot off" id="feed-dot"></span>
            <span style="font-size:.75rem;color:var(--text-muted-custom)">Data Feed</span>
            <span style="font-size:.7rem;color:#555;margin-left:auto" id="feed-label">Offline</span>
        </div>
    </div>
</aside>

<div id="main-content">
    <header id="topbar">
        <button class="btn btn-sm d-md-none me-2" style="background:transparent;border:none;color:#aaa" onclick="document.getElementById('sidebar').classList.toggle('open')">
            <i class="bi bi-list fs-5"></i>
        </button>
        <div>
            <div class="price-ticker" id="live-price" data-prev="0">—</div>
        </div>
        <div>
            <span class="price-change" id="price-change">—</span>
        </div>
        <div class="ms-auto d-flex align-items-center gap-3">
            <div style="font-size:.75rem;color:var(--text-muted-custom);text-align:right">
                <div id="session-label" style="font-size:.65rem;letter-spacing:.06em;text-transform:uppercase"></div>
                <div id="utc-clock" style="font-family:'JetBrains Mono',monospace;font-size:.8rem;color:#8d97b0"></div>
            </div>
            <div id="topbar-signal">
                <span class="signal-badge wait"><i class="bi bi-hourglass-split"></i> Waiting</span>
            </div>
            <div class="position-relative">
                <a href="{{ route('alerts.index') }}" class="text-decoration-none" style="color:#8d97b0">
                    <i class="bi bi-bell fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="alert-badge" style="font-size:.55rem;display:none">0</span>
                </a>
            </div>
        </div>
    </header>

    <main class="p-3 p-md-4 flex-grow-1">
        @yield('content')
    </main>

    <footer style="padding:.75rem 1.5rem;border-top:1px solid var(--bg-border);font-size:.7rem;color:var(--text-muted-custom);display:flex;justify-content:space-between">
        <span>GoldFlow Pro &copy; {{ date('Y') }} — Simon</span>
        <span id="last-tick-time">Last tick: —</span>
    </footer>
</div>

<div class="alert-toast-container" id="toast-container"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/lightweight-charts@4.1.3/dist/lightweight-charts.standalone.production.js"></script>
<script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@1.16.1/dist/echo.iife.js"></script>

<script>
const GF = {
    formatPrice: p => parseFloat(p).toFixed(2),
    formatPips:  p => (p > 0 ? '+' : '') + parseFloat(p).toFixed(1),
    toast(msg, type = 'info') {
        const id = 'toast-' + Date.now();
        const colors = { buy:'#00c076', sell:'#ff3b5c', info:'#f5c842', error:'#ff6b6b' };
        const icons  = { buy:'bi-arrow-up-circle-fill', sell:'bi-arrow-down-circle-fill', info:'bi-info-circle-fill', error:'bi-exclamation-triangle-fill' };
        document.getElementById('toast-container').insertAdjacentHTML('beforeend', `
        <div id="${id}" class="d-flex align-items-start gap-2 mb-2 p-3 rounded-3 shadow"
             style="background:var(--bg-card);border:1px solid var(--bg-border)">
            <i class="bi ${icons[type]} mt-1" style="color:${colors[type]};font-size:1rem;flex-shrink:0"></i>
            <div style="flex:1"><div style="font-size:.8rem;color:#e2e8f0">${msg}</div>
            <div style="font-size:.65rem;color:var(--text-muted-custom)">${new Date().toLocaleTimeString()}</div></div>
            <button onclick="document.getElementById('${id}').remove()" style="background:none;border:none;color:#555;cursor:pointer;padding:0">✕</button>
        </div>`);
        setTimeout(() => document.getElementById(id)?.remove(), 7000);
    }
};

/* UTC Clock */
function updateClock() {
    const now = new Date(), h = now.getUTCHours();
    document.getElementById('utc-clock').textContent = now.toUTCString().slice(17,25) + ' UTC';
    let session = '', color = '#6c7793';
    if (h >= 0  && h < 8)  { session = 'SYDNEY / TOKYO'; color = '#a78bfa'; }
    if (h >= 7  && h < 16) { session = 'LONDON';         color = '#60a5fa'; }
    if (h >= 13 && h < 22) { session = 'NEW YORK';       color = '#f5c842'; }
    if ((h >= 7 && h < 10) || (h >= 13 && h < 16)) { session = 'LONDON+NY OVERLAP'; color = '#00c076'; }
    const lbl = document.getElementById('session-label');
    lbl.textContent = session || 'OFF-SESSION';
    lbl.style.color = color;
}
setInterval(updateClock, 1000);
updateClock();

/* Echo — Laravel Reverb */
window.Echo = new Echo({
    broadcaster: 'reverb',
    key:    '{{ env("REVERB_APP_KEY") }}',
    wsHost: '{{ env("REVERB_HOST", "localhost") }}',
    wsPort: {{ env("REVERB_PORT", 8080) }},
    wssPort: {{ env("REVERB_PORT", 8080) }},
    forceTLS: {{ env("REVERB_SCHEME", "http") === "https" ? "true" : "false" }},
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});
Echo.connector.pusher.connection.bind('connected', () => {
    document.getElementById('ws-dot').classList.remove('off');
    document.getElementById('ws-label').textContent = 'Live';
    document.getElementById('ws-label').style.color = '#00c076';
});
Echo.connector.pusher.connection.bind('disconnected', () => {
    document.getElementById('ws-dot').classList.add('off');
    document.getElementById('ws-label').textContent = 'Offline';
    document.getElementById('ws-label').style.color = '#ff3b5c';
});

/* Live tick */
@if(config('orderflow.connector') !== 'clusterdelta')
Echo.channel('xauusd.ticks').listen('.tick.updated', (e) => {
    const priceEl = document.getElementById('live-price');
    const prev = parseFloat(priceEl.dataset.prev || e.bid);
    const cur  = parseFloat(e.bid);
    const diff = cur - prev;
    priceEl.textContent  = GF.formatPrice(cur);
    priceEl.dataset.prev = cur;
    priceEl.style.color  = diff >= 0 ? 'var(--green-bull)' : 'var(--red-bear)';
    document.getElementById('price-change').textContent  = GF.formatPips(diff) + ' pts';
    document.getElementById('price-change').className    = 'price-change ' + (diff >= 0 ? 'text-success' : 'text-danger');
    document.getElementById('last-tick-time').textContent = 'Last tick: ' + new Date().toLocaleTimeString();
    document.getElementById('feed-dot').classList.remove('off');
    document.getElementById('feed-label').textContent = 'Live';
    document.getElementById('feed-label').style.color = '#00c076';
});

@endif
@if(config('orderflow.connector') === 'clusterdelta')
async function pollClusterDelta() {
    try {
        const tf = window.GFFollowActiveFeed === false ? window.GFTimeframe : null;
        const response = await fetch('/api/feed-status' + (tf ? '?tf=' + encodeURIComponent(tf) : ''), {cache: 'no-store'});
        if (!response.ok) throw new Error('Feed unavailable');
        const feed = await response.json();
        if (tf && tf !== window.GFTimeframe) return;
        document.getElementById('feed-dot').classList.toggle('off', !feed.connected);
        document.getElementById('feed-label').textContent = feed.connected ? 'ClusterDelta' : (feed.has_data ? 'Feed stale' : 'No MT5 bars');
        if (feed.price) document.getElementById('live-price').textContent = GF.formatPrice(feed.price);
        window.dispatchEvent(new CustomEvent('clusterdelta-metrics', {detail: feed}));
    } catch (_) {
        document.getElementById('feed-dot').classList.add('off');
        document.getElementById('feed-label').textContent = 'Unavailable';
    }
}
setInterval(pollClusterDelta, 10000);
pollClusterDelta();
@endif
/* New setup */
Echo.channel('xauusd.setups').listen('.setup.detected', (e) => {
    const type = e.direction?.toLowerCase();
    GF.toast(`<b>${e.direction} Setup</b> @ ${GF.formatPrice(e.entry)} — Score: ${e.score}%`, type);
    document.getElementById('topbar-signal').innerHTML =
        `<span class="signal-badge ${type}"><i class="bi bi-arrow-${type==='buy'?'up':'down'}-circle-fill"></i> ${e.direction}</span>`;
    const b = document.getElementById('alert-badge');
    b.style.display = 'inline';
    b.textContent = parseInt(b.textContent||0) + 1;
});
</script>
@include('layouts.zone-notifications')
@stack('scripts')
</body>
</html>
