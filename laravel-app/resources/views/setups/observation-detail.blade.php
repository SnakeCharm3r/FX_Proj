@extends('layouts.app')
@section('title','Spotted setup history')
@section('content')
<a href="{{ route('setups.index') }}#setup-observation-history" style="color:var(--gold)">← Spotted setup history</a>
<div class="card-panel mt-3">
    <div class="card-header-custom"><span>Observation #{{ $observation->id }} · {{ $observation->direction==='NEUTRAL'?'TIED':$observation->direction }} · {{ strtoupper($observation->timeframe) }}</span><span>{{ $observation->score }}/100</span></div>
    <div class="p-3">
        <p class="small">{{ $observation->source }} · {{ $observation->instrument }} · Spotted {{ $observation->spotted_at->copy()->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i:s') }} EAT</p>
        <p class="small" style="color:var(--text-muted-custom)">BUY {{ $observation->bull_score ?? '—' }}/100 · SELL {{ $observation->bear_score ?? '—' }}/100 · History floor {{ $observation->history_floor }} · Live threshold at observation: {{ $observation->signal_threshold ?? 'not stored in original record' }}. Scores are rule weights, not win probabilities.</p>
        @if($observation->setup_id)
        <a class="btn btn-sm btn-outline-warning mb-3" href="{{ route('setups.index',['setup_id'=>$observation->setup_id]) }}#setup-dashboard">View live setup #{{ $observation->setup_id }} and its TP/SL status</a>
        @if($observation->setup && $observation->setup->score!==$observation->score)
        <p class="small">First observed score: {{ $observation->score }}/100. The linked live setup later qualified at {{ $observation->setup->score }}/100.</p>
        @endif
        @else
        <p class="small" style="color:var(--gold)">{{ $observation->origin==='saved_setup_import'?'Imported saved setup. Its linked live record is no longer available.':'Observation only. No live setup is linked to this snapshot; entry alerts require a qualifying live setup.' }}</p>
        @endif
        @if($observation->metrics['zone_retest'] ?? null)
        <p class="small" style="color:var(--gold)">Zone rule: {{ $observation->metrics['zone_retest']['reason'] }} Stage: {{ $observation->metrics['zone_retest']['stage'] }}.</p>
        @endif
        <div class="row g-2 mb-3">
            @foreach(['entry'=>'Entry reference','stop_loss'=>'SL reference','tp1'=>'TP1 reference','tp2'=>'TP2 reference'] as $key=>$label)
            <div class="col-6 col-lg-3"><div class="metric-pill"><div class="label">{{ $label }}</div><div class="value">{{ $observation->$key!==null ? number_format($observation->$key,2) : '—' }}</div></div></div>
            @endforeach
        </div>
        <p class="small" style="color:var(--text-muted-custom)">{{ $observation->origin==='saved_setup_import'?'Imported from a previously saved setup; missing historical inputs were not reconstructed.':'Frozen at the first validated evaluation meeting the history floor for this candle.' }} Strategy: {{ $observation->strategy_version }}. Reference levels are not order fills.</p>
    </div>
    <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Condition</th><th>Direction</th><th>Points</th><th>Saved observation</th></tr></thead><tbody>
        @forelse($observation->confluence_detail as $condition)
        <tr><td>{{ $condition['name']??$condition['key']??'Condition' }}</td><td>{{ strtoupper($condition['status']??'unknown') }}</td><td>{{ $condition['weight']??0 }}</td><td>{{ $condition['detail']??'No saved detail' }}</td></tr>
        @empty
        <tr><td colspan="4">No condition snapshot was saved with the original setup.</td></tr>
        @endforelse
    </tbody></table></div>
</div>
@endsection
