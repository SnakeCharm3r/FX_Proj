@if($focusedSetup)
<div class="row g-3" data-focused-id="{{ $focusedSetup->id }}">
    <div class="col-12 col-xl-5">
        @include('components.setup-card', ['activeSetup' => $focusedSetup, 'setupCardId' => 'selected-setup-card',
            'setupCardTitle' => 'Setup #'.$focusedSetup->id, 'targetPrefix' => 'focus-', 'showManageLink' => false])
    </div>
    <div class="col-12 col-xl-7">
        <div class="card-panel h-100">
            <div class="card-header-custom"><span>Saved conditions · setup #{{ $focusedSetup->id }}</span><span>{{ strtoupper($focusedSetup->timeframe) }}</span></div>
            <div class="p-3">
                <div class="small mb-2" style="color:var(--text-muted-custom)">
                    {{ $focusedSetup->source ?? 'Legacy source' }} · {{ $focusedSetup->instrument ?? 'XAUUSD' }} ·
                    Created {{ $focusedSetup->created_at->format('d M Y H:i:s') }} UTC
                    @if($focusedSetup->candle_time)
                    <br>Signal candle opened {{ gmdate('d M Y H:i', $focusedSetup->candle_time) }} UTC
                    @endif
                </div>
                <div class="table-responsive" style="max-height:280px;overflow:auto">
                    <table class="table of-table mb-0" aria-label="Saved confluence for setup {{ $focusedSetup->id }}">
                        <thead><tr><th>Condition</th><th>Direction</th><th>Rule weight</th><th>Observation at creation</th></tr></thead>
                        <tbody>
                        @forelse(is_array($focusedSetup->confluence_detail) ? $focusedSetup->confluence_detail : [] as $condition)
                            <tr>
                                <td>{{ $condition['name'] ?? $condition['key'] ?? 'Condition' }}</td>
                                <td style="color:{{ ($condition['status'] ?? '') === 'bullish' ? 'var(--green-bull)' : (($condition['status'] ?? '') === 'bearish' ? 'var(--red-bear)' : 'var(--text-muted-custom)') }}">{{ strtoupper($condition['status'] ?? 'unknown') }}</td>
                                <td>{{ $condition['weight'] ?? 0 }} pts</td>
                                <td>{{ $condition['detail'] ?? 'No saved observation' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4">No condition snapshot was saved with this setup.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="small mt-2" style="color:var(--text-muted-custom)">These are the conditions saved when this setup formed. Opposing conditions do not add to its directional score. TP lights use this setup's own recorded price touches.</div>
            </div>
        </div>
    </div>
</div>
@else
<div class="card-panel text-center p-4" style="color:var(--text-muted-custom)">No saved setups yet. A qualifying setup will become available here when detected.</div>
@endif
