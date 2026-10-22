@extends('layouts.app')
@section('title','Strategy Tester')

@push('styles')
<style>
    #tester-chart{height:520px}.tester-stat{min-height:96px}.tester-stat .value{font:700 1.55rem 'JetBrains Mono',monospace}
    .coverage-track{height:7px;background:var(--bg-panel);border-radius:8px;overflow:hidden}.coverage-fill{height:100%;background:var(--gold)}
    @media(max-width:768px){#tester-chart{height:420px}}
</style>
@endpush

@section('content')
@php
    $s=$report['summary'];$c=$report['coverage'];
    $eat=fn($timestamp)=>$timestamp?\Carbon\CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone('Africa/Dar_es_Salaam')->format('d M H:i'): '—';
    $chartBars=array_map(static fn($bar)=>array_intersect_key($bar,array_flip(['time','open','high','low','close'])),$report['bars']);
    $buyPotentialMarkers=array_values(array_filter($report['potentialMarkers'],static fn($row)=>str_starts_with($row['text'],'BUY')));
    $sellPotentialMarkers=array_values(array_filter($report['potentialMarkers'],static fn($row)=>str_starts_with($row['text'],'SELL')));
@endphp
<div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
    <div>
        <h5 class="mb-1" style="font-weight:700"><i class="bi bi-clipboard-data me-2" style="color:var(--gold)"></i>Zone Absorption Strategy Tester</h5>
        <div class="small" style="color:var(--text-muted-custom)">Retrospective M5 replay · Asian session starts 00:00 UTC / 03:00 EAT · `zone_absorption_v2`</div>
    </div>
    <form method="GET" class="d-flex gap-2">
        <a href="{{ route('setups.tester.scalp') }}" class="btn btn-sm btn-outline-warning text-nowrap">Scalp replay</a>
        <input type="date" name="date" value="{{ $date }}" max="{{ now()->utc()->format('Y-m-d') }}" class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0">
        <button class="btn btn-sm btn-warning">Run replay</button>
    </form>
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Setups',$s['setups'],'var(--gold)'],['Worked',$s['worked'],'var(--green-bull)'],['Failed',$s['failed'],'var(--red-bear)'],
        ['Open / unresolved',$s['open']+$s['ambiguous'],'#a78bfa'],['Resolved success',$s['success_rate']===null?'—':$s['success_rate'].'%', '#60a5fa'],
        ['Expectancy',$s['expectancy_r']===null?'—':number_format($s['expectancy_r'],2).'R', $s['expectancy_r']!==null&&$s['expectancy_r']>0?'var(--green-bull)':'var(--text-muted-custom)']
    ] as [$label,$value,$color])
    <div class="col-6 col-lg-2"><div class="card-panel tester-stat p-3"><div class="label text-uppercase" style="font-size:.65rem;color:var(--text-muted-custom);letter-spacing:.07em">{{ $label }}</div><div class="value mt-2" style="color:{{ $color }}">{{ $value }}</div></div></div>
    @endforeach
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom flex-wrap gap-2"><span><i class="bi bi-bar-chart-line me-1" style="color:var(--gold)"></i>XAUUSDm M5 replay</span><span>{{ $eat($report['start']) }}–{{ $eat($report['end']) }} EAT</span></div>
    <div class="px-3 pt-3 d-flex flex-wrap gap-3 small">
        <label style="color:var(--green-bull)"><input id="show-buy-potential" type="checkbox" checked> Notable BUY ({{ count($buyPotentialMarkers) }})</label>
        <label style="color:var(--red-bear)"><input id="show-sell-potential" type="checkbox" checked> Notable SELL ({{ count($sellPotentialMarkers) }})</label>
    </div>
    <div id="tester-chart"></div>
    <div class="px-3 pb-3 small" style="color:var(--text-muted-custom)">
        Green ↑ and red ↓ show directional M5 order flow. “Rules” is completion of four required stages: H1 zone, failed high-volume effort, rejected retest and opposite-delta break. Delta % is the bar’s net delta share; neither percentage predicts the chance of winning. Grey circles are completed absorption sequences blocked by the H1-zone gate.
    </div>
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>Notable M5 findings</span><span>Rule completion, not win probability</span></div>
    <div class="table-responsive"><table class="table of-table mb-0">
        <thead><tr><th>Time EAT</th><th>Direction</th><th>Rules</th><th>Delta share</th><th>Volume</th><th>Participation</th><th>Finding</th></tr></thead>
        <tbody>
        @forelse($report['notableFindings'] as $row)
            <tr><td>{{ $eat($row['time']) }}</td>
                <td style="color:{{ $row['direction']==='BUY'?'var(--green-bull)':'var(--red-bear)' }}">{{ $row['direction'] }}</td>
                <td>{{ $row['rule_completion'] }}%</td><td>{{ sprintf('%+.1f%%',$row['delta_pct']) }}</td>
                <td>{{ number_format($row['volume']) }} · {{ $row['volume_ratio']===null?'—':number_format($row['volume_ratio'],2).'x' }}</td>
                <td>{{ $row['participation'] }}</td><td style="white-space:normal;min-width:360px">{{ $row['finding'] }}</td></tr>
        @empty
            <tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted-custom)">No increased-participation or completed absorption finding is available.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-lg-7"><div class="card-panel h-100">
        <div class="card-header-custom"><span>Test interpretation</span></div>
        <div class="p-3">
            <div class="mb-2" style="font-weight:700;color:var(--gold)">{{ $s['verdict'] }}</div>
            <p class="small mb-2">A setup is counted as worked when TP1 was reached before SL. Failure means SL was reached before TP1. TP2 produces +2.25R under the half-at-TP1 model; TP1 followed by the original stop produces +0.25R. Open and ambiguous results are excluded from the success-rate denominator.</p>
            <p class="small mb-0" style="color:var(--text-muted-custom)">Entries use the next M5 open after confirmation. This replay excludes spread, fees and slippage and uses final archived bars, so it is a dry run rather than a live-fill performance claim.</p>
        </div>
    </div></div>
    <div class="col-12 col-lg-5"><div class="card-panel h-100">
        <div class="card-header-custom"><span>Evidence coverage</span><span>{{ $c['coverage_pct'] }}%</span></div>
        <div class="p-3 small">
            <div class="coverage-track mb-3"><div class="coverage-fill" style="width:{{ min(100,$c['coverage_pct']) }}%"></div></div>
            <div>Requested: {{ $eat($c['requested_start']) }} EAT</div>
            <div>First broker bar: {{ $eat($c['broker_start']) }} EAT</div>
            <div>First matched footprint: {{ $eat($c['paired_start']) }} EAT</div>
            <div>Bars: {{ $c['broker_bars'] }} broker · {{ $c['paired_bars'] }} footprint-matched · {{ $c['expected_bars'] }} expected</div>
            <div class="mt-2" style="color:var(--gold)">The period before matched footprint coverage is displayed but cannot create a tested setup.</div>
        </div>
    </div></div>
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>Qualified setups and outcomes</span><span>{{ count($report['setups']) }} rows</span></div>
    <div class="table-responsive"><table class="table of-table mb-0">
        <thead><tr><th>Signal EAT</th><th>Side</th><th>Entry</th><th>SL</th><th>TP1</th><th>TP2</th><th>Exit EAT</th><th>Outcome</th><th>Result</th><th>Order-flow reason</th></tr></thead>
        <tbody>
        @forelse($report['setups'] as $setup)
            <tr><td>{{ $eat($setup['signal_time']) }}</td><td style="color:{{ $setup['direction']==='BUY'?'var(--green-bull)':'var(--red-bear)' }}">{{ $setup['direction'] }} ↑</td>
            <td>{{ number_format($setup['entry'],2) }}</td><td>{{ number_format($setup['stop'],2) }}</td><td>{{ number_format($setup['tp1'],2) }}</td><td>{{ number_format($setup['tp2'],2) }}</td>
            <td>{{ $eat($setup['exit_time']) }}</td><td>{{ str_replace('_',' ',$setup['status']) }}</td><td>{{ number_format($setup['result_r'],2) }}R</td><td style="white-space:normal;min-width:340px">{{ $setup['evidence']['reason'] }}</td></tr>
        @empty<tr><td colspan="10" class="text-center py-4" style="color:var(--text-muted-custom)">No candle sequence satisfied every current strategy rule in the testable evidence window.</td></tr>@endforelse
        </tbody>
    </table></div>
</div>

<div class="card-panel">
    <div class="card-header-custom"><span>Rejected absorption sequences</span><span>{{ count($report['blocked']) }} rows</span></div>
    <div class="table-responsive"><table class="table of-table mb-0">
        <thead><tr><th>Confirmation EAT</th><th>Direction</th><th>Effort volume</th><th>Effort delta</th><th>Rejection delta</th><th>Confirmation delta</th><th>Why no setup</th></tr></thead>
        <tbody>@forelse($report['blocked'] as $row)<tr><td>{{ $eat($row['time']) }}</td><td>{{ $row['direction'] }}</td>
            <td>{{ number_format($row['evidence']['effort_volume']??0) }} · {{ number_format($row['evidence']['effort_volume_ratio']??0,2) }}x</td>
            <td>{{ sprintf('%+.0f',$row['evidence']['effort_delta']??0) }}</td><td>{{ sprintf('%+.0f',$row['evidence']['rejection_delta']??0) }}</td><td>{{ sprintf('%+.0f',$row['evidence']['confirmation_delta']??0) }}</td>
            <td style="white-space:normal;min-width:340px">{{ $row['reason'] }}</td></tr>
        @empty<tr><td colspan="7" class="text-center py-4" style="color:var(--text-muted-custom)">No complete absorption sequence was found.</td></tr>@endforelse</tbody>
    </table></div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const host=document.getElementById('tester-chart');
    const chart=LightweightCharts.createChart(host,{layout:{background:{color:'#141720'},textColor:'#8d97b0'},grid:{vertLines:{color:'#252a38'},horzLines:{color:'#252a38'}},rightPriceScale:{borderColor:'#252a38'},timeScale:{borderColor:'#252a38',timeVisible:true,secondsVisible:false}});
    const series=chart.addCandlestickSeries({upColor:'#00c076',downColor:'#ff3b5c',borderVisible:false,wickUpColor:'#00c076',wickDownColor:'#ff3b5c'});
    series.setData(@json($chartBars));
    const buyPotential=@json($buyPotentialMarkers);
    const sellPotential=@json($sellPotentialMarkers);
    const updateMarkers=()=>{
        const markers=[];
        if(document.getElementById('show-buy-potential').checked) markers.push(...buyPotential);
        if(document.getElementById('show-sell-potential').checked) markers.push(...sellPotential);
        markers.sort((a,b)=>a.time-b.time);series.setMarkers(markers);
    };
    ['show-buy-potential','show-sell-potential'].forEach(id=>document.getElementById(id).addEventListener('change',updateMarkers));
    updateMarkers();chart.timeScale().fitContent();
    new ResizeObserver(()=>chart.applyOptions({width:host.clientWidth})).observe(host);
})();
</script>
@endpush
