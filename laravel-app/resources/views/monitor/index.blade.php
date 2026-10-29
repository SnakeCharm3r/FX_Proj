@extends('layouts.app')
@section('title', 'System Monitor')

@section('content')
<div class="d-flex justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h5 class="mb-1"><i class="bi bi-pc-display me-2" style="color:var(--gold)"></i>System Monitor</h5>
        <small style="color:var(--text-muted-custom)">Service status and the native ClusterDelta footprint flow used by your charts.</small>
    </div>
    <button id="monitor-refresh" class="btn btn-sm btn-outline-warning" type="button"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
</div>

<div class="card-panel p-3 mb-3" role="status" aria-live="polite">
    <div id="monitor-summary" class="fw-semibold">Checking services…</div>
    <small id="monitor-updated" style="color:var(--text-muted-custom)">Refreshes every 5 seconds.</small>
</div>

<div class="row g-3 mb-4">
    @foreach(['app' => 'Application server', 'mt5' => 'MT5 terminal', 'forwarder' => 'Data forwarder', 'redis' => 'Redis storage', 'reverb' => 'WebSocket server', 'engine' => 'Setup detector'] as $id => $name)
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card-panel p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                <span class="fw-semibold">{{ $name }}</span>
                <span id="status-{{ $id }}" class="badge text-bg-secondary">Checking</span>
            </div>
            <div id="detail-{{ $id }}" class="small" style="color:#a8b2c8;min-height:2.5rem">Waiting for health check.</div>
            <div id="pid-{{ $id }}" class="small mt-2" style="color:var(--text-muted-custom)"></div>
        </div>
    </div>
    @endforeach
</div>

<div class="card-panel mb-4">
    <div class="card-header-custom">ClusterDelta footprint → local export → data forwarder → application</div>
    <div class="table-responsive">
        <table class="table of-table mb-0">
            <thead><tr><th>Feed</th><th>MT5 export</th><th>Last received by app</th><th>Data flow</th></tr></thead>
            <tbody id="monitor-feeds"><tr><td colspan="4">Checking exports…</td></tr></tbody>
        </table>
    </div>
    <div class="p-3 small" style="color:var(--text-muted-custom)">
        A running process can still have a data problem. Export and receipt ages show whether data is moving.
        “Active via data” means fresh output was observed even though the process is not visible to the web server.
        Other timeframes appear when their bridge exports or stored bars are found.
    </div>
</div>

<details class="card-panel p-3">
    <summary class="fw-semibold" style="cursor:pointer">How to keep the feed running</summary>
    <div class="small mt-3" style="color:#a8b2c8">
        <p>The application, data forwarder, WebSocket server and setup detector start automatically in the background. MT5 starts when you sign in to the desktop. No terminal windows need to stay open.</p>
        <p>Keep FXClusterDeltaBridge and FXClusterDeltaFootprint attached in MT5, with DLL imports allowed. The application runs at http://localhost:8003.</p>
        <div class="mb-1">Check the background services:</div>
        <pre class="p-2 rounded" style="background:var(--bg-base);color:var(--gold);white-space:pre-wrap">systemctl --user status goldflow-*.service</pre>
        <div class="mb-1">Restart the application and data services:</div>
        <pre class="p-2 rounded" style="background:var(--bg-base);color:var(--gold);white-space:pre-wrap">systemctl --user restart goldflow.target</pre>
        <p class="mb-0">Linux restarts failed background services automatically. This page observes their health. If the application server stops, the page will show that it is unreachable and keep retrying.</p>
    </div>
</details>
@endsection

@push('scripts')
<script>
(() => {
    let latest = null, checkedClient = 0, inFlight = false;
    const states = {
        running: ['Running', 'success'], active: ['Active via data', 'success'],
        not_detected: ['Not detected', 'warning'], unknown: ['Not visible', 'secondary'],
        unavailable: ['Unavailable', 'danger'],
    };
    function badge(id, state) {
        const el = document.getElementById('status-' + id);
        if (!el) return;
        const [label, color] = states[state] || states.unknown;
        el.textContent = label;
        el.className = 'badge text-bg-' + color;
    }
    function age(timestamp) {
        if (!timestamp) return 'Never';
        const seconds = Math.max(0, Math.floor(latest.checked_at + (Date.now() - checkedClient) / 1000 - timestamp));
        return seconds < 60 ? seconds + 's ago' : seconds < 3600 ? Math.floor(seconds / 60) + 'm ago' : Math.floor(seconds / 3600) + 'h ago';
    }
    function renderFeeds() {
        if (!latest) return;
        const body = document.getElementById('monitor-feeds');
        body.replaceChildren();
        latest.feeds.forEach(feed => {
            const row = document.createElement('tr');
            const cells = [feed.instrument + ' · ' + feed.timeframe, age(feed.exported_at), age(feed.received_at),
                feed.receiving ? 'Receiving' : feed.export_fresh ? 'Exporting; no fresh receipt' : 'Waiting / stale'];
            cells.forEach((value, i) => {
                const td = document.createElement('td');
                td.textContent = value;
                if (i === 3) td.style.color = feed.receiving ? 'var(--green-bull)' : 'var(--gold)';
                row.appendChild(td);
            });
            body.appendChild(row);
        });
    }
    async function refresh() {
        if (inFlight) return;
        inFlight = true;
        const button = document.getElementById('monitor-refresh');
        button.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('/api/system-status', {cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw new Error('Health check failed');
            latest = await response.json();
            checkedClient = Date.now();
            latest.services.forEach(service => {
                badge(service.id, service.state);
                document.getElementById('detail-' + service.id).textContent = service.detail;
                document.getElementById('pid-' + service.id).textContent = service.pids.length ? 'PID: ' + service.pids.join(', ') : '';
            });
            document.getElementById('monitor-summary').textContent = latest.summary;
            document.getElementById('monitor-summary').style.color = latest.healthy ? 'var(--green-bull)' : 'var(--gold)';
            document.getElementById('monitor-updated').textContent = 'Checked ' + new Date(latest.checked_at * 1000).toLocaleTimeString() + ' · refreshes every 5 seconds';
            renderFeeds();
        } catch (_) {
            latest = null;
            ['app','mt5','forwarder','redis','reverb','engine'].forEach(id => {
                badge(id, id === 'app' ? 'unavailable' : 'unknown');
                document.getElementById('detail-' + id).textContent = 'Cannot verify while the application is unreachable.';
                document.getElementById('pid-' + id).textContent = '';
            });
            document.getElementById('monitor-summary').textContent = 'Application unreachable. Check the background services and your connection.';
            document.getElementById('monitor-summary').style.color = 'var(--red-bear)';
            document.getElementById('monitor-updated').textContent = 'Retrying every 5 seconds.';
            const td = document.createElement('td');
            td.colSpan = 4;
            td.textContent = 'Live data status cannot be verified.';
            const row = document.createElement('tr'); row.appendChild(td);
            document.getElementById('monitor-feeds').replaceChildren(row);
        } finally {
            clearTimeout(timeout);
            inFlight = false;
            button.disabled = false;
        }
    }
    document.getElementById('monitor-refresh').addEventListener('click', refresh);
    setInterval(refresh, 5000);
    setInterval(renderFeeds, 1000);
    refresh();
})();
</script>
@endpush
