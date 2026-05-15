@extends('layouts.app')
@section('title', 'Settings')

@section('content')

<div class="mb-3">
    <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-gear-fill me-2" style="color:var(--gold)"></i>Settings</h5>
    <small style="color:var(--text-muted-custom)">Signal thresholds, broker connection, and alert configuration</small>
</div>

<form id="settings-form">
@csrf
<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card-panel">
            <div class="card-header-custom"><span><i class="bi bi-sliders me-1" style="color:var(--gold)"></i>Signal Thresholds</span></div>
            <div class="p-3">
                @php
                    $fields = [
                        ['min_confluence_score',    'Min Confluence Score (%)',   config('orderflow.min_confluence_score',60),   'Minimum score (0–100) to emit a setup'],
                        ['delta_threshold',          'Delta Threshold',            config('orderflow.delta_threshold',500),       'Min absolute bar delta for delta signal'],
                        ['cvd_divergence_threshold', 'CVD Divergence Threshold',  config('orderflow.cvd_divergence_threshold',200),'CVD vs price divergence threshold'],
                        ['imbalance_ratio',          'Imbalance Ratio',            config('orderflow.imbalance_ratio',3.0),       'Buy/Sell vol ratio to flag imbalance'],
                        ['absorption_threshold',     'Absorption Threshold',       config('orderflow.absorption_threshold',0.3),  'Wick-to-body ratio for absorption'],
                        ['stacked_imbalance_count',  'Stacked Imbalance Count',    config('orderflow.stacked_imbalance_count',3), 'Consecutive imbalance bars for stacked signal'],
                        ['exhaustion_lookback',      'Exhaustion Lookback (bars)', config('orderflow.exhaustion_lookback',10),    'Bars to look back for exhaustion'],
                        ['vwap_deviation_pips',      'VWAP Deviation (pips)',      config('orderflow.vwap_deviation_pips',150),   'Distance from VWAP to trigger signal'],
                    ];
                @endphp
                @foreach($fields as [$name,$label,$val,$hint])
                <div class="mb-3">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">{{ $label }}</label>
                    <input type="number" name="{{ $name }}" value="{{ $val }}" step="any"
                           class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-family:'JetBrains Mono',monospace">
                    <div style="font-size:.67rem;color:var(--text-muted-custom);margin-top:.2rem">{{ $hint }}</div>
                </div>
                @endforeach
                <div class="mb-0">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Active Timeframes</label>
                    <div class="d-flex gap-3 flex-wrap mt-1">
                        @foreach(['5m','15m','30m','1h','4h'] as $tf)
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" name="timeframes[]" value="{{ $tf }}" id="tf-{{ $tf }}"
                                   {{ in_array($tf, config('orderflow.timeframes',['15m','30m','1h'])) ? 'checked' : '' }}
                                   style="background:var(--bg-panel);border-color:var(--bg-border)">
                            <label class="form-check-label" for="tf-{{ $tf }}" style="font-size:.8rem">{{ $tf }}</label>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card-panel mb-3">
            <div class="card-header-custom"><span><i class="bi bi-bell-fill me-1" style="color:var(--gold)"></i>Alert Configuration</span></div>
            <div class="p-3">
                <div class="mb-3">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Telegram Bot Token</label>
                    <input type="password" name="telegram_token" placeholder="Leave blank to keep current"
                           class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-family:'JetBrains Mono',monospace">
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Telegram Chat ID</label>
                    <input type="text" name="telegram_chat_id" value="{{ config('alerts.telegram_chat_id','') }}"
                           class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-family:'JetBrains Mono',monospace">
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Alert Email</label>
                    <input type="email" name="alert_email" value="{{ config('alerts.alert_email','') }}"
                           class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0">
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Min Score to Alert (%)</label>
                    <input type="number" name="alert_min_score" value="{{ config('alerts.min_score',65) }}" min="0" max="100"
                           class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-family:'JetBrains Mono',monospace">
                </div>
                <div>
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Alert Channels</label>
                    <div class="d-flex gap-3 mt-1">
                        @foreach(['telegram'=>'Telegram','email'=>'Email','browser'=>'Browser'] as $key=>$lbl)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="alert_channels[]" value="{{ $key }}" id="ch-{{ $key }}"
                                   {{ in_array($key,config('alerts.channels',['telegram','email']))?'checked':'' }}
                                   style="background:var(--bg-panel);border-color:var(--bg-border)">
                            <label class="form-check-label" for="ch-{{ $key }}" style="font-size:.8rem">{{ $lbl }}</label>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="card-panel">
            <div class="card-header-custom"><span><i class="bi bi-plug-fill me-1" style="color:var(--gold)"></i>Broker Connection</span></div>
            <div class="p-3">
                <div class="mb-3">
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Connector</label>
                    <select name="connector" class="form-select form-select-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-size:.8rem">
                        <option value="ctrader" {{ config('orderflow.connector')!=='mt5'&&config('orderflow.connector')!=='demo'?'selected':'' }}>Pepperstone — cTrader Open API</option>
                        <option value="mt5"     {{ config('orderflow.connector')==='mt5'?'selected':'' }}>MetaTrader 5</option>
                        <option value="demo"    {{ config('orderflow.connector')==='demo'?'selected':'' }}>Demo / Simulated Feed</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:.75rem;font-weight:500;color:#c0c8d8">Ingestion Service</label>
                    <div class="d-flex align-items-center gap-2 mt-1">
                        <span class="pulse-dot {{ $pythonRunning?'':'off' }}"></span>
                        <span style="font-size:.8rem;color:var(--text-muted-custom)">{{ $pythonRunning?'Running':'Stopped' }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <button type="submit" class="btn" style="background:var(--gold);color:#000;font-weight:700;padding:.5rem 2rem">
            <i class="bi bi-floppy-fill me-2"></i>Save Settings
        </button>
        <span id="save-feedback" class="ms-3" style="font-size:.8rem;display:none"></span>
    </div>
</div>
</form>

@endsection

@push('scripts')
<script>
document.getElementById('settings-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = this.querySelector('[type=submit]');
    btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Saving…';
    fetch('/api/settings', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
        body: new FormData(this),
    })
    .then(r => r.json())
    .then(d => {
        GF.toast(d.message || 'Settings saved', 'info');
        const fb = document.getElementById('save-feedback');
        fb.textContent = '✓ Saved at ' + new Date().toLocaleTimeString();
        fb.style.color = 'var(--green-bull)'; fb.style.display = 'inline';
    })
    .catch(() => GF.toast('Failed to save settings', 'error'))
    .finally(() => { btn.disabled = false; btn.innerHTML = '<i class="bi bi-floppy-fill me-2"></i>Save Settings'; });
});
</script>
@endpush
