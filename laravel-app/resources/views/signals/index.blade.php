@extends('layouts.app')
@section('title', 'Signal History')

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-activity me-2" style="color:var(--gold)"></i>Signal History</h5>
        <small style="color:var(--text-muted-custom)">Signals supporting saved setups. Conditions below the setup threshold are visible on the <a href="{{ route('setups.index') }}" style="color:var(--gold)">live setup checklist</a>.</small>
    </div>
</div>

<div class="row g-3 mb-3">
    @foreach([['Total Signals',$totalSignals??0,'var(--gold)'],['Today',$todaySignals??0,'#a78bfa'],['Bullish',$bullishSignals??0,'var(--green-bull)'],['Bearish',$bearishSignals??0,'var(--red-bear)']] as [$l,$v,$c])
    <div class="col-6 col-md-3">
        <div class="card-panel p-3 text-center">
            <div style="font-family:'JetBrains Mono',monospace;font-size:1.4rem;font-weight:700;color:{{ $c }}">{{ $v }}</div>
            <div style="font-size:.65rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted-custom)">{{ $l }}</div>
        </div>
    </div>
    @endforeach
</div>

<div class="card-panel">
    <div class="card-header-custom"><span><i class="bi bi-list-ul me-1" style="color:var(--gold)"></i>All Signals</span></div>
    <div style="overflow-x:auto">
        <table class="table table-dark table-hover of-table mb-0">
            <thead><tr><th>Time</th><th>Type</th><th>Direction</th><th>Timeframe</th><th>Value</th><th>Price</th><th>Setup</th></tr></thead>
            <tbody>
                @forelse($signals as $sig)
                <tr>
                    <td style="color:#8d97b0">{{ $sig->created_at->format('d M y H:i:s') }}</td>
                    <td><span class="badge" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.68rem">{{ $sig->type }}</span></td>
                    <td>
                        @if(in_array($sig->direction,['BULLISH','BUY'])) <span style="color:var(--green-bull);font-size:.78rem">▲ {{ $sig->direction }}</span>
                        @elseif(in_array($sig->direction,['BEARISH','SELL'])) <span style="color:var(--red-bear);font-size:.78rem">▼ {{ $sig->direction }}</span>
                        @else <span style="color:#555;font-size:.78rem">{{ $sig->direction ?? '—' }}</span>
                        @endif
                    </td>
                    <td style="color:var(--text-muted-custom)">{{ strtoupper($sig->timeframe) }}</td>
                    <td style="font-family:'JetBrains Mono',monospace">{{ $sig->value ?? '—' }}</td>
                    <td style="font-family:'JetBrains Mono',monospace;color:var(--gold)">{{ number_format($sig->price, 2) }}</td>
                    <td>
                        @if($sig->setup_id) <a href="{{ route('setups.index') }}?id={{ $sig->setup_id }}" style="color:var(--gold);font-size:.75rem;text-decoration:none"><i class="bi bi-link me-1"></i>#{{ $sig->setup_id }}</a>
                        @else <span style="color:#555;font-size:.75rem">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center py-5" style="color:var(--text-muted-custom)"><i class="bi bi-inbox fs-2 d-block mb-2" style="opacity:.3"></i>No signals yet — engine warming up…</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($signals->hasPages())
    <div class="p-3 d-flex justify-content-center" style="border-top:1px solid var(--bg-border)">{{ $signals->links() }}</div>
    @endif
</div>

@endsection
