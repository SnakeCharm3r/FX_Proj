@extends('layouts.app')
@section('title', 'Alerts')

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-bell-fill me-2" style="color:var(--gold)"></i>Alerts</h5>
        <small style="color:var(--text-muted-custom)">Telegram &amp; email notification history</small>
    </div>
    <button onclick="testAlert()" class="btn btn-sm" style="background:rgba(245,200,66,.1);border:1px solid rgba(245,200,66,.3);color:var(--gold);font-size:.78rem">
        <i class="bi bi-send me-1"></i> Test Alert
    </button>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['telegram','Telegram','bi-telegram','#29b6f6','alerts.telegram_token'],
        ['email','Email','bi-envelope-fill','var(--gold)','alerts.alert_email'],
    ] as [$key,$label,$icon,$color,$cfgKey])
    <div class="col-12 col-md-6">
        <div class="card-panel p-3">
            <div class="d-flex align-items-center gap-3">
                <div style="width:44px;height:44px;border-radius:10px;background:rgba(0,0,0,.2);border:1px solid var(--bg-border);display:flex;align-items:center;justify-content:center">
                    <i class="bi {{ $icon }} fs-4" style="color:{{ $color }}"></i>
                </div>
                <div class="flex-grow-1">
                    <div style="font-size:.85rem;font-weight:600">{{ $label }}</div>
                    <div style="font-size:.72rem;color:var(--text-muted-custom)">{{ $key==='telegram' ? config('alerts.telegram_bot_name','Not configured') : config('alerts.alert_email','Not configured') }}</div>
                </div>
                @php $configured = (bool)config($cfgKey); @endphp
                <span class="badge" style="font-size:.7rem;background:{{ $configured?'rgba(0,192,118,.15)':'rgba(255,59,92,.15)' }};color:{{ $configured?'var(--green-bull)':'var(--red-bear)' }};border:1px solid {{ $configured?'rgba(0,192,118,.3)':'rgba(255,59,92,.3)' }}">
                    {{ $configured ? '● Active' : '● Not Set' }}
                </span>
            </div>
        </div>
    </div>
    @endforeach
</div>

<div class="card-panel">
    <div class="card-header-custom">
        <span><i class="bi bi-clock-history me-1" style="color:var(--gold)"></i>Alert Log</span>
        <span style="font-size:.65rem;color:var(--text-muted-custom)">{{ $alertLogs->total() ?? 0 }} total</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table table-dark table-hover of-table mb-0">
            <thead><tr><th>Time</th><th>Channel</th><th>Type</th><th>Message</th><th>Status</th></tr></thead>
            <tbody>
                @forelse($alertLogs as $log)
                <tr>
                    <td style="color:#8d97b0;white-space:nowrap">{{ $log->created_at->format('d M y H:i:s') }}</td>
                    <td>
                        @if($log->channel==='telegram') <span style="color:#29b6f6;font-size:.75rem"><i class="bi bi-telegram me-1"></i>Telegram</span>
                        @else <span style="color:var(--gold);font-size:.75rem"><i class="bi bi-envelope me-1"></i>Email</span>
                        @endif
                    </td>
                    <td><span class="badge" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.65rem">{{ $log->type }}</span></td>
                    <td style="font-size:.78rem;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $log->message }}</td>
                    <td>
                        @if($log->status==='sent') <span style="color:var(--green-bull);font-size:.72rem">✓ Sent</span>
                        @elseif($log->status==='failed') <span style="color:var(--red-bear);font-size:.72rem" title="{{ $log->error }}">✕ Failed</span>
                        @else <span style="color:#555;font-size:.72rem">{{ ucfirst($log->status) }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center py-5" style="color:var(--text-muted-custom)"><i class="bi bi-bell-slash fs-2 d-block mb-2" style="opacity:.3"></i>No alerts sent yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($alertLogs->hasPages())
    <div class="p-3 d-flex justify-content-center" style="border-top:1px solid var(--bg-border)">{{ $alertLogs->links() }}</div>
    @endif
</div>

@endsection

@push('scripts')
<script>
function testAlert() {
    fetch('/api/alerts/test', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
    })
    .then(r => r.json())
    .then(d => GF.toast(d.message || 'Test alert sent', 'info'))
    .catch(() => GF.toast('Failed to send test alert', 'error'));
}
</script>
@endpush
