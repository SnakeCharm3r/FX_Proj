@extends('layouts.app')
@section('title', 'Setup History')

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-lightning-charge-fill me-2" style="color:var(--gold)"></i>Setup History</h5>
        <small style="color:var(--text-muted-custom)">All detected XAUUSD trade setups</small>
    </div>
    <div class="d-flex gap-2">
        <a href="/api/setups/export?format=csv" class="btn btn-sm" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.78rem"><i class="bi bi-download me-1"></i> CSV</a>
        <button onclick="window.location.reload()" class="btn btn-sm" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.78rem"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
    </div>
</div>

{{-- Stats --}}
<div class="row g-3 mb-3">
    @php
        $stats = [
            ['Total Setups',   $totalSetups??0,       'bi-collection',           'var(--gold)'],
            ['BUY Setups',     $buyCount??0,           'bi-arrow-up-circle-fill', 'var(--green-bull)'],
            ['SELL Setups',    $sellCount??0,          'bi-arrow-down-circle-fill','var(--red-bear)'],
            ['Avg Score',      ($avgScore??0).'%',     'bi-percent',              '#a78bfa'],
            ['High Conf ≥70%', $highConfCount??0,      'bi-star-fill',            'var(--gold)'],
            ['Active Now',     $activeCount??0,        'bi-broadcast',            'var(--green-bull)'],
        ];
    @endphp
    @foreach($stats as [$label,$value,$icon,$color])
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card-panel p-3 text-center">
            <i class="bi {{ $icon }} fs-4 mb-1" style="color:{{ $color }}"></i>
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.3rem;font-weight:700;color:{{ $color }}">{{ $value }}</div>
            <div style="font-size:.65rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted-custom)">{{ $label }}</div>
        </div>
    </div>
    @endforeach
</div>

{{-- Filters --}}
<div class="card-panel mb-3 p-3">
    <form method="GET" action="{{ route('setups.index') }}" class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em">Direction</label>
            <select name="direction" class="form-select form-select-sm mt-1" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-size:.8rem">
                <option value="">All</option>
                <option value="BUY"  {{ request('direction')==='BUY'  ?'selected':'' }}>BUY</option>
                <option value="SELL" {{ request('direction')==='SELL' ?'selected':'' }}>SELL</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em">Timeframe</label>
            <select name="timeframe" class="form-select form-select-sm mt-1" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-size:.8rem">
                <option value="">All</option>
                @foreach(['5m','15m','30m','1h','4h'] as $tf)
                <option value="{{ $tf }}" {{ request('timeframe')===$tf?'selected':'' }}>{{ $tf }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em">Status</label>
            <select name="status" class="form-select form-select-sm mt-1" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-size:.8rem">
                <option value="">All</option>
                @foreach(['active','triggered','closed','invalidated'] as $st)
                <option value="{{ $st }}" {{ request('status')===$st?'selected':'' }}>{{ ucfirst($st) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em">Min Score</label>
            <input type="number" name="min_score" value="{{ request('min_score',0) }}" min="0" max="100"
                   class="form-control form-control-sm mt-1" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-size:.8rem">
        </div>
        <div class="col-6 col-md-2">
            <label style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em">Date From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                   class="form-control form-control-sm mt-1" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0;font-size:.8rem">
        </div>
        <div class="col-6 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm w-100 mt-3" style="background:var(--gold);color:#000;font-weight:600;font-size:.78rem"><i class="bi bi-funnel-fill me-1"></i> Filter</button>
            <a href="{{ route('setups.index') }}" class="btn btn-sm mt-3" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.78rem"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

{{-- Table --}}
<div class="card-panel">
    <div class="card-header-custom">
        <span><i class="bi bi-table me-1" style="color:var(--gold)"></i>All Setups</span>
        <span style="font-size:.65rem;color:var(--text-muted-custom)">{{ $setups->total() ?? 0 }} records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table table-dark table-hover of-table mb-0">
            <thead>
                <tr><th>#</th><th>Time</th><th>Dir</th><th>TF</th><th>Entry</th><th>Stop Loss</th><th>TP1</th><th>TP2</th><th>Risk</th><th>RR</th><th>Score</th><th>Status</th><th>Triggered</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($setups as $setup)
                <tr id="setup-row-{{ $setup->id }}">
                    <td style="color:var(--text-muted-custom)">{{ $setup->id }}</td>
                    <td style="color:#8d97b0;white-space:nowrap">{{ $setup->created_at->format('d M y H:i') }}</td>
                    <td><span class="signal-badge {{ strtolower($setup->direction) }}" style="font-size:.65rem;padding:.15rem .45rem">{{ $setup->direction }}</span></td>
                    <td style="color:var(--text-muted-custom)">{{ strtoupper($setup->timeframe) }}</td>
                    <td style="color:var(--gold)">{{ number_format($setup->entry,2) }}</td>
                    <td class="text-danger">{{ number_format($setup->stop_loss,2) }}</td>
                    <td class="text-success">{{ number_format($setup->tp1,2) }}</td>
                    <td class="text-success">{{ number_format($setup->tp2,2) }}</td>
                    <td>{{ number_format(abs($setup->entry-$setup->stop_loss),1) }}</td>
                    <td>{{ number_format(abs($setup->tp1-$setup->entry)/max(0.01,abs($setup->entry-$setup->stop_loss)),1) }}:1</td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <div style="width:36px;height:4px;background:var(--bg-panel);border-radius:2px;overflow:hidden">
                                <div style="height:100%;width:{{ $setup->score }}%;background:{{ $setup->score>=70?'var(--green-bull)':($setup->score>=50?'var(--gold)':'var(--red-bear)') }};border-radius:2px"></div>
                            </div>
                            <span style="font-size:.78rem;font-weight:600;color:{{ $setup->score>=70?'var(--green-bull)':($setup->score>=50?'var(--gold)':'var(--red-bear)') }}">{{ $setup->score }}%</span>
                        </div>
                    </td>
                    <td>
                        @php $sc=['active'=>'var(--green-bull)','triggered'=>'var(--gold)','closed'=>'#555','invalidated'=>'var(--red-bear)']; @endphp
                        <span style="font-size:.7rem;color:{{ $sc[$setup->status]??'#555' }}">● {{ ucfirst($setup->status) }}</span>
                    </td>
                    <td style="font-size:.72rem;color:var(--text-muted-custom)">{{ $setup->triggered_at?$setup->triggered_at->format('H:i'):'—' }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <button onclick="viewSetup({{ $setup->id }})" class="btn btn-sm p-1" title="View" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;line-height:1"><i class="bi bi-eye" style="font-size:.75rem"></i></button>
                            <a href="{{ route('dashboard') }}?highlight={{ $setup->id }}" class="btn btn-sm p-1" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;line-height:1"><i class="bi bi-graph-up" style="font-size:.75rem"></i></a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="14" class="text-center py-5" style="color:var(--text-muted-custom)"><i class="bi bi-inbox fs-2 d-block mb-2" style="opacity:.3"></i>No setups found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($setups->hasPages())
    <div class="p-3 d-flex justify-content-center" style="border-top:1px solid var(--bg-border)">
        {{ $setups->withQueryString()->links('vendor.pagination.custom') }}
    </div>
    @endif
</div>

{{-- Detail Modal --}}
<div class="modal fade" id="setupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="background:var(--bg-card);border:1px solid var(--bg-border)">
            <div class="modal-header" style="border-color:var(--bg-border)">
                <h6 class="modal-title" style="color:var(--gold)"><i class="bi bi-lightning-charge-fill me-2"></i>Setup Details</h6>
                <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="setup-modal-body">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm" style="color:var(--gold)"></div></div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function viewSetup(id) {
    new bootstrap.Modal(document.getElementById('setupModal')).show();
    fetch(`/api/setups/${id}`)
        .then(r => r.json())
        .then(s => {
            document.getElementById('setup-modal-body').innerHTML = `
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card-panel p-3">
                        <div class="d-flex justify-content-between mb-3">
                            <span class="signal-badge ${s.direction.toLowerCase()}">${s.direction}</span>
                            <span style="font-size:.75rem;color:var(--text-muted-custom)">${s.created_at}</span>
                        </div>
                        <div class="row g-2">
                            ${[['Entry',s.entry,'var(--gold)'],['Stop Loss',s.stop_loss,'var(--red-bear)'],['TP1',s.tp1,'var(--green-bull)'],['TP2',s.tp2,'var(--green-bull)']].map(([l,v,c])=>`
                            <div class="col-6"><div class="metric-pill"><div class="label">${l}</div><div class="value" style="color:${c}">${parseFloat(v).toFixed(2)}</div></div></div>`).join('')}
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card-panel p-3">
                        <div style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.5rem">Confluence Score</div>
                        <div style="font-family:'JetBrains Mono',monospace;font-size:2rem;font-weight:700;color:${s.score>=70?'var(--green-bull)':(s.score>=50?'var(--gold)':'var(--red-bear)')}">${s.score}%</div>
                        <hr style="border-color:var(--bg-border)">
                        <div class="row g-1" style="font-size:.75rem">
                            <div class="col-6"><span style="color:var(--text-muted-custom)">Timeframe:</span> <b>${s.timeframe?.toUpperCase()}</b></div>
                            <div class="col-6"><span style="color:var(--text-muted-custom)">Status:</span> <b>${s.status}</b></div>
                            <div class="col-6"><span style="color:var(--text-muted-custom)">Risk:</span> <b>${Math.abs(s.entry-s.stop_loss).toFixed(1)} pts</b></div>
                            <div class="col-6"><span style="color:var(--text-muted-custom)">RR:</span> <b>${(Math.abs(s.tp1-s.entry)/Math.max(0.01,Math.abs(s.entry-s.stop_loss))).toFixed(1)}:1</b></div>
                        </div>
                    </div>
                </div>
            </div>`;
        })
        .catch(() => { document.getElementById('setup-modal-body').innerHTML = '<div class="text-center py-3" style="color:var(--text-muted-custom)">Could not load setup.</div>'; });
}

Echo.channel('xauusd.setups').listen('.setup.updated', (e) => {
    const row = document.getElementById('setup-row-' + e.id);
    if (row) {
        const sc = {'active':'var(--green-bull)','triggered':'var(--gold)','closed':'#555','invalidated':'var(--red-bear)'};
        const cell = row.querySelector('td:nth-child(12)');
        if (cell) cell.innerHTML = `<span style="font-size:.7rem;color:${sc[e.status]||'#555'}">● ${e.status.charAt(0).toUpperCase()+e.status.slice(1)}</span>`;
    }
});
</script>
@endpush
