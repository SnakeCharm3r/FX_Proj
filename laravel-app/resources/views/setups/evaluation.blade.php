@php
    $configuredSetupStrategy = config('orderflow.setup_strategy');
    $dedicatedSetupStrategy = in_array(
        $configuredSetupStrategy,
        config('orderflow.dedicated_strategies', []),
        true
    );
    $detectorGateText = match ($configuredSetupStrategy) {
        'h1_trend_range_v3' => 'Entry gate: H1 trend/range + absorption + CVD + footprint + capital gate',
        'zone_absorption_v2' => 'Entry gate: H1 zone + confirmed M5 absorption sequence',
        default => 'Required score: '.config('orderflow.min_confluence_score').' / 100 in one direction',
    };
@endphp

<div class="card-panel mb-3">
    <div class="card-header-custom d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <span><i class="bi bi-activity me-1" style="color:var(--gold)"></i>Live setup detector</span>
        <span id="detector-health" class="small" role="status">Checking detector…</span>
    </div>
    <div class="p-3">
        <div class="d-flex gap-2 flex-wrap mb-3" id="detector-timeframes" aria-label="Detector timeframe">
            @foreach(config('orderflow.timeframes') as $tf)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-tf="{{ $tf }}" aria-pressed="false">{{ $tf }}</button>
            @endforeach
        </div>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="metric-pill h-100 p-3">
                    <div class="label mb-2" id="detector-title">15m · completed candle</div>
                    <div id="detector-reason" class="small mb-3" aria-live="polite">Waiting for the first check.</div>
                    <div class="d-flex gap-4 mb-2">
                        <div><div class="label">BUY score</div><div id="detector-bull" class="value" style="color:var(--green-bull)">—</div></div>
                        <div><div class="label">SELL score</div><div id="detector-bear" class="value" style="color:var(--red-bear)">—</div></div>
                    </div>
                    <div id="detector-threshold" class="small mb-2">{{ $detectorGateText }}</div>
                    <div id="detector-candle" class="small" style="color:var(--text-muted-custom)"></div>
                    <div id="detector-checked" class="small" style="color:var(--text-muted-custom)"></div>
                    <div id="detector-inputs" class="small mt-2" style="color:var(--text-muted-custom)"></div>
                    <div id="detector-zone" class="small mt-2" style="color:var(--gold)"></div>
                    <a href="{{ route('monitor.index') }}" class="d-inline-block small mt-3" style="color:var(--gold)">Open System Monitor →</a>
                </div>
            </div>
            <div class="col-12 col-lg-8">
                <div class="table-responsive">
                    <table class="table of-table mb-0">
                        <thead><tr><th>Condition</th><th>Direction</th><th>Weight</th><th>Observation</th></tr></thead>
                        <tbody id="detector-signals"><tr><td colspan="4">Checking completed candle and footprint inputs…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="small mt-3" style="color:var(--text-muted-custom)">
            Confirmed H1 zone → high-participation M5 effort fails → weaker-delta rejection → opposite-delta break → saved setup.
            Neutral / 0 points means the condition was evaluated and did not qualify. Unavailable means an input is missing.
            Opposing signals do not add to the same score. Scores are rule weights, not win probabilities.
            @if(config('orderflow.setup_strategy')==='h1_trend_range_v3')
            Live v3 requires a completed M5 absorption sequence at a valid H1 range, established H1 direction, aligned CVD and native footprint confirmation. Countertrend signals additionally require matching CVD divergence. Stops wider than {{ number_format(config('orderflow.live_max_stop_points',5),1) }} gold points are blocked for the $100 / 0.01-lot research profile.
            @else
            Absorption uses a completed multi-bar M5 sequence. Other scores remain diagnostics and do not approve or veto this strategy. Footprint stacks use native GC levels.
            @endif
            Entries, stops and targets use broker XAUUSD prices at the signal candle close; a saved setup is not a filled order.
        </div>
    </div>
</div>

@push('scripts')
<script>
(() => {
    let snapshot = null, busy = false, selected = @json(in_array(request('timeframe'), config('orderflow.timeframes')) ? request('timeframe') : '5m');
    const dedicatedStrategy = @json($dedicatedSetupStrategy);
    const strategyName = @json($configuredSetupStrategy);
    let latestId = @json(\App\Models\Setup::max('id')), count = @json($totalSetups), polls = 0;
    const put = (id, value) => { document.getElementById(id).textContent = value; };
    const stamp = value => value ? new Date(value * 1000).toISOString().replace('T', ' ').slice(0, 19) + ' UTC' : '—';
    function render() {
        if (!snapshot) return;
        const row = snapshot.timeframes.find(row => row.timeframe === selected);
        const live = row?.engine_fresh === true;
        put('detector-health', live ? 'Detector checking · refreshes every 10s' : 'Detector needs attention');
        document.getElementById('detector-health').style.color = live ? 'var(--green-bull)' : 'var(--gold)';
        document.querySelectorAll('#detector-timeframes button').forEach(button => {
            const state = snapshot.timeframes.find(row => row.timeframe === button.dataset.tf);
            button.className = 'btn btn-sm ' + (button.dataset.tf === selected ? 'btn-warning' : 'btn-outline-secondary');
            button.setAttribute('aria-pressed', button.dataset.tf === selected ? 'true' : 'false');
            button.title = state?.reason || 'Waiting for a detector check';
        });
        put('detector-title', selected + ' · completed candle');
        put('detector-reason', row?.reason || 'No evaluation available for this timeframe.');
        put('detector-bull', live && row?.bull_score != null ? row.bull_score + ' / 100' : '—');
        put('detector-bear', live && row?.bear_score != null ? row.bear_score + ' / 100' : '—');
        put('detector-threshold', dedicatedStrategy ? (strategyName === 'h1_trend_range_v3'
            ? 'Entry gate: H1 trend/range + absorption + CVD + footprint + capital gate'
            : 'Entry gate: H1 zone + confirmed M5 absorption sequence')
            : 'Required score: ' + (row?.threshold ?? @json(config('orderflow.min_confluence_score'))) + ' / 100 in one direction');
        put('detector-candle', 'Candle opened: ' + stamp(row?.candle_time));
        put('detector-checked', 'Last check: ' + stamp(row?.checked_at));
        put('detector-inputs', live && row?.footprint_matched ? row.bar_count + ' completed bars · footprint volume/delta matched' : 'Input readiness: ' + (row?.status || 'unknown'));
        put('detector-zone', row?.zone_retest ? 'Zone rule: ' + row.zone_retest.stage.replaceAll('_', ' ') + (row.zone_retest.price_zone_id ? ' · Zone #' + row.zone_retest.price_zone_id + ' · ' + row.zone_retest.zone_low + '–' + row.zone_retest.zone_high : '') : 'Zone rule: waiting for validated inputs.');
        const body = document.getElementById('detector-signals'); body.replaceChildren();
        const signals = live ? row?.signals || [] : [];
        if (!signals.length) {
            const tr = body.insertRow(); const td = tr.insertCell(); td.colSpan = 4;
            td.textContent = 'Conditions are unavailable until the detector and its required inputs are ready.';
        }
        signals.forEach(signal => {
            const tr = body.insertRow();
            [signal.name, signal.available === false ? 'UNAVAILABLE' : signal.status.toUpperCase(), signal.weight + ' / ' + (signal.max_weight ?? signal.weight) + ' pts', signal.detail].forEach((value, index) => {
                const td = tr.insertCell(); td.textContent = value;
                if (index === 1) td.style.color = signal.status === 'bullish' ? 'var(--green-bull)' : signal.status === 'bearish' ? 'var(--red-bear)' : 'var(--text-muted-custom)';
            });
        });
    }
    async function refreshHistory(signal) {
        const response = await fetch(window.location.href, {cache: 'no-store', signal});
        if (!response.ok) throw new Error('History unavailable');
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        for (const id of ['setup-stats', 'setup-history', 'setup-observation-history']) {
            const replacement = doc.getElementById(id);
            if (!replacement) throw new Error('History missing');
            document.getElementById(id).replaceWith(replacement);
        }
        window.SetupFocus?.syncChoices(doc.getElementById('setup-focus-select'));
    }
    async function refresh() {
        if (busy) return; busy = true;
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch('/api/setups/status', {cache: 'no-store', signal: controller.signal});
            if (!response.ok) throw new Error('Detector unavailable');
            snapshot = await response.json(); render();
            if (snapshot.latest_setup_id !== latestId || snapshot.setup_count !== count || ++polls % 3 === 0) {
                await refreshHistory(controller.signal);
                latestId = snapshot.latest_setup_id; count = snapshot.setup_count;
            }
        } catch (_) {
            snapshot = null;
            put('detector-health', 'Connection unavailable · retrying');
            document.getElementById('detector-health').style.color = 'var(--gold)';
            put('detector-reason', 'Cannot verify detection. Check the application and local services.');
            put('detector-bull', '—'); put('detector-bear', '—');
            put('detector-checked', 'Last check cannot be verified.');
            put('detector-inputs', 'Input readiness cannot be verified.');
            put('detector-zone', 'Zone readiness cannot be verified.');
            const body = document.getElementById('detector-signals'); body.replaceChildren();
            const td = body.insertRow().insertCell(); td.colSpan = 4; td.textContent = 'Live conditions unavailable.';
        } finally { clearTimeout(timeout); busy = false; }
    }
    document.querySelectorAll('#detector-timeframes button').forEach(button => button.addEventListener('click', () => { selected = button.dataset.tf; render(); }));
    refresh(); setInterval(refresh, 10000);
})();
</script>
@endpush
