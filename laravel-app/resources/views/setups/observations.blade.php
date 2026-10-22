<section class="card-panel mb-4" id="setup-observation-history" style="scroll-margin-top:76px">
    <div class="card-header-custom flex-wrap gap-2">
        <span><i class="bi bi-clock-history me-1"></i>Spotted setup history · ≥{{ $historyFloor }}/100</span>
        <span>{{ $observationCounts['total'] }} recorded · {{ $observationCounts['below65'] }} below 65 · {{ $observationCounts['live'] }} linked live setups</span>
    </div>
    <div class="p-3">
        <p class="small mb-3" style="color:var(--text-muted-custom)">Records the first validated completed-candle evaluation scoring at least {{ $historyFloor }}. Lower scores are not retained. Live signals require {{ config('orderflow.min_confluence_score') }}/100 in one direction plus a confirmed zone retest, rejection and aligned footprint orderflow. Observations alone send no trade alerts.</p>
        <form method="GET" action="{{ route('setups.index') }}#setup-observation-history" class="row g-2 align-items-end mb-3">
            <input type="hidden" id="observation-focus-filter" name="setup_id" value="{{ $focusedSetup?->id }}" @if(!$focusedSetup) disabled @endif>
            @foreach(request()->except(['setup_id','obs_direction','obs_timeframe','obs_band','obs_from','observations_page']) as $name=>$value)
                @if(is_scalar($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
            @endforeach
            <div class="col-6 col-lg-2"><label class="small" for="obs-direction">Direction</label><select id="obs-direction" name="obs_direction" class="form-select form-select-sm">
                <option value="">All</option>@foreach(['BUY','SELL','NEUTRAL'] as $direction)<option value="{{ $direction }}" @selected(request('obs_direction')===$direction)>{{ $direction==='NEUTRAL'?'Tied':$direction }}</option>@endforeach
            </select></div>
            <div class="col-6 col-lg-2"><label class="small" for="obs-timeframe">Timeframe</label><select id="obs-timeframe" name="obs_timeframe" class="form-select form-select-sm">
                <option value="">All</option>@foreach(config('orderflow.timeframes') as $tf)<option value="{{ $tf }}" @selected(request('obs_timeframe')===$tf)>{{ strtoupper($tf) }}</option>@endforeach
            </select></div>
            <div class="col-6 col-lg-3"><label class="small" for="obs-band">Score / records</label><select id="obs-band" name="obs_band" class="form-select form-select-sm">
                <option value="">All ≥{{ $historyFloor }}</option>
                <option value="below65" @selected(request('obs_band')==='below65')>{{ $historyFloor }}–64 · below 65</option>
                <option value="below_live" @selected(request('obs_band')==='below_live')>Below live threshold at observation</option>
                <option value="live" @selected(request('obs_band')==='live')>Linked live setups</option>
            </select></div>
            <div class="col-6 col-lg-3"><label class="small" for="obs-from">Spotted from (EAT)</label><input id="obs-from" type="date" name="obs_from" value="{{ request('obs_from') }}" class="form-control form-control-sm"></div>
            <div class="col-12 col-lg-2"><button class="btn btn-sm btn-warning w-100" type="submit">Filter history</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table of-table mb-0" aria-label="Spotted setups scoring at least {{ $historyFloor }}">
            <thead><tr><th>Candle opened · EAT</th><th>TF</th><th>Direction</th><th>First score</th><th>BUY / SELL</th><th>Entry reference</th><th>Record</th><th>Details</th></tr></thead>
            <tbody>
            @forelse($observations as $observation)
                <tr data-observation-id="{{ $observation->id }}">
                    <td>{{ \Carbon\CarbonImmutable::createFromTimestampUTC($observation->candle_time)->timezone('Africa/Dar_es_Salaam')->format('d M Y H:i') }}</td>
                    <td>{{ strtoupper($observation->timeframe) }}</td>
                    <td style="color:{{ $observation->direction==='BUY'?'var(--green-bull)':($observation->direction==='SELL'?'var(--red-bear)':'var(--text-muted-custom)') }}">{{ $observation->direction==='NEUTRAL'?'TIED':$observation->direction }}</td>
                    <td>{{ $observation->score }}/100</td>
                    <td>{{ $observation->bull_score ?? '—' }} / {{ $observation->bear_score ?? '—' }}</td>
                    <td>{{ $observation->entry!==null ? number_format($observation->entry,2) : '—' }}</td>
                    <td>@if($observation->setup_id)<a href="{{ route('setups.index',['setup_id'=>$observation->setup_id]) }}#setup-dashboard" style="color:var(--gold)">Live setup #{{ $observation->setup_id }}</a>@else<span>Observation only</span>@endif</td>
                    <td><a class="btn btn-sm btn-outline-warning" href="{{ route('setups.observation',$observation) }}">View</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center p-4">No recorded observations match these filters. Recording starts with this feature; older lower-scoring evaluations were not stored.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($observations->hasPages())<div class="p-3">{{ $observations->fragment('setup-observation-history')->links() }}</div>@endif
</section>

@once
@push('styles')
<style>#setup-observation-history .form-select,#setup-observation-history .form-control{background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0}#setup-observation-history .of-table{font-size:.74rem}</style>
@endpush
@endonce
