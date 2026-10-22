@extends('layouts.app')
@section('title', 'Setups')

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0" style="font-weight:700;color:#e2e8f0"><i class="bi bi-lightning-charge-fill me-2" style="color:var(--gold)"></i>Setups</h5>
        <small style="color:var(--text-muted-custom)">Live detection and saved XAUUSD setups · ClusterDelta GC orderflow</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('setups.tester') }}" class="btn btn-sm btn-warning"><i class="bi bi-clipboard-data me-1"></i> Strategy Tester</a>
        <a href="#setup-observation-history" class="btn btn-sm btn-outline-warning">Spotted history ≥{{ config('orderflow.history_min_score',45) }}</a>
        <a href="/api/setups/export?format=csv" class="btn btn-sm" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.78rem"><i class="bi bi-download me-1"></i> CSV</a>
        <button onclick="window.location.reload()" class="btn btn-sm" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;font-size:.78rem"><i class="bi bi-arrow-clockwise me-1"></i> Refresh</button>
    </div>
</div>

@include('setups.focus')

@include('setups.evaluation')

@include('setups.observations')

{{-- Stats --}}
<div class="row g-3 mb-3" id="setup-stats">
    @php
        $stats = [
            ['Total Setups',   $totalSetups??0,       'bi-collection',           'var(--gold)'],
            ['BUY Setups',     $buyCount??0,           'bi-arrow-up-circle-fill', 'var(--green-bull)'],
            ['SELL Setups',    $sellCount??0,          'bi-arrow-down-circle-fill','var(--red-bear)'],
            ['Avg Diagnostic', ($avgScore??0).' pts',  'bi-percent',              '#a78bfa'],
            ['Diagnostic ≥70', $highConfCount??0,      'bi-star-fill',            'var(--gold)'],
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
        <input type="hidden" name="setup_id" id="setup-focus-filter" value="{{ $focusedSetup?->id }}" @if(!$focusedSetup) disabled @endif>
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
                @foreach(config('orderflow.timeframes') as $tf)
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
            <label style="font-size:.7rem;color:var(--text-muted-custom);text-transform:uppercase;letter-spacing:.07em">Min Diagnostic</label>
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
<div class="card-panel" id="setup-history">
    <div class="card-header-custom">
        <span><i class="bi bi-table me-1" style="color:var(--gold)"></i>All Setups</span>
        <span style="font-size:.65rem;color:var(--text-muted-custom)">{{ $setups->total() ?? 0 }} records</span>
    </div>
    <div style="overflow-x:auto">
        <table class="table table-dark table-hover of-table mb-0">
            <thead>
                <tr><th>#</th><th>Time</th><th>Dir</th><th>TF</th><th>Strategy</th><th>Entry</th><th>Stop Loss</th><th>TP1</th><th>TP2</th><th>Risk</th><th>RR</th><th>Rules</th><th>Status</th><th>Triggered</th><th>Actions</th></tr>
            </thead>
            <tbody>
                @forelse($setups as $setup)
                <tr id="setup-row-{{ $setup->id }}">
                    <td style="color:var(--text-muted-custom)">{{ $setup->id }}</td>
                    <td style="color:#8d97b0;white-space:nowrap">{{ $setup->created_at->format('d M y H:i') }}</td>
                    <td><span class="signal-badge {{ strtolower($setup->direction) }}" style="font-size:.65rem;padding:.15rem .45rem">{{ $setup->direction }}</span></td>
                    <td style="color:var(--text-muted-custom)">{{ strtoupper($setup->timeframe) }}</td>
                    <td style="font-size:.7rem;white-space:nowrap">{{ match($setup->strategy_version){'h1_trend_range_v3'=>'H1 trend/range v3','zone_absorption_v2'=>'Zone absorption v2',default=>'Weighted v1'} }}</td>
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
                            <a href="{{ route('setups.index', array_merge(request()->query(), ['setup_id' => $setup->id])) }}#setup-dashboard" data-focus-setup="{{ $setup->id }}" data-focus-label="#{{ $setup->id }} · {{ $setup->direction }} · {{ strtoupper($setup->timeframe) }} · {{ ucfirst($setup->status) }}" class="btn btn-sm btn-outline-warning" title="Focus this setup">View</a>
                            @if($setup->source === config('orderflow.connector') && $setup->instrument === config('clusterdelta.instrument'))
                            <a href="{{ route('dashboard', ['setup_id' => $setup->id]) }}" class="btn btn-sm p-1" title="Open this setup on chart" style="background:var(--bg-panel);border:1px solid var(--bg-border);color:#8d97b0;line-height:1"><i class="bi bi-graph-up" style="font-size:.75rem"></i></a>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="15" class="text-center py-5" style="color:var(--text-muted-custom)"><i class="bi bi-inbox fs-2 d-block mb-2" style="opacity:.3"></i>No saved setups match these filters. Setups are saved only when a completed candle qualifies; see the live checklist above. Recorded levels are not placed orders.</td></tr>
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

@endsection

@push('scripts')
<script>
window.Echo?.channel('xauusd.setups').listen('.setup.updated', (e) => {
    const row = document.getElementById('setup-row-' + e.id);
    if (row) {
        const sc = {'active':'var(--green-bull)','triggered':'var(--gold)','closed':'#555','invalidated':'var(--red-bear)'};
        const cell = row.querySelector('td:nth-child(13)');
        if (cell) cell.innerHTML = `<span style="font-size:.7rem;color:${sc[e.status]||'#555'}">● ${e.status.charAt(0).toUpperCase()+e.status.slice(1)}</span>`;
    }
});
</script>
@endpush
