        <div class="card-panel h-100" id="{{ $setupCardId ?? 'active-setup-panel' }}" @if($activeSetup) data-setup-id="{{ $activeSetup->id }}" @endif>
            <div class="card-header-custom">
                <span><i class="bi bi-lightning-charge-fill me-1" style="color:var(--gold)"></i>{{ $setupCardTitle ?? 'Active Setup' }}</span>
                <span class="signal-badge {{ $activeSetup ? strtolower($activeSetup->direction) : 'wait' }}" id="{{ ($targetPrefix ?? '').'active-signal-badge' }}">
                    @if($activeSetup)
                        <i class="bi bi-arrow-{{ $activeSetup->direction === 'BUY' ? 'up' : 'down' }}-circle-fill"></i> {{ $activeSetup->direction }}
                    @else
                        <i class="bi bi-hourglass-split"></i> Waiting
                    @endif
                </span>
            </div>
            <div class="p-3">
                @if($activeSetup)
                <div class="small mb-2" style="color:var(--text-muted-custom)">{{ $activeSetup->strategy_version === 'zone_absorption_v2' ? 'H1 zone + M5 absorption · Zone #'.$activeSetup->price_zone_id : ($activeSetup->strategy_version === 'zone_retest_v1' ? '1H zone retest · Zone #'.$activeSetup->price_zone_id : 'Orderflow score · original strategy') }}</div>
                @php $absorptionStrategy = $activeSetup->strategy_version === 'zone_absorption_v2'; @endphp
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="confluence-ring">
                        <svg width="80" height="80" viewBox="0 0 80 80">
                            <circle cx="40" cy="40" r="32" fill="none" stroke="var(--bg-panel)" stroke-width="7"/>
                            <circle cx="40" cy="40" r="32" fill="none"
                                stroke="{{ $activeSetup->direction === 'BUY' ? 'var(--green-bull)' : 'var(--red-bear)' }}"
                                stroke-width="7"
                                stroke-dasharray="{{ round($activeSetup->score / 100 * 201.1, 1) }} 201.1"
                                stroke-linecap="round"/>
                        </svg>
                        <div class="score-text">
                            <span style="color:{{ $activeSetup->direction === 'BUY' ? 'var(--green-bull)' : 'var(--red-bear)' }}">{{ $activeSetup->score }}{{ $absorptionStrategy ? '' : '%' }}</span>
                            <span>{{ $absorptionStrategy ? 'Context' : 'Score' }}</span>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="row g-2">
                            <div class="col-6"><div class="metric-pill"><div class="label">Entry</div><div class="value text-warning">{{ number_format($activeSetup->entry, 2) }}</div></div></div>
                            <div class="col-6"><div id="{{ ($targetPrefix ?? '').'sl-card' }}" class="metric-pill {{ $activeSetup->sl_hit_at ? 'sl-hit' : '' }}" role="status" aria-live="polite" aria-label="SL {{ $activeSetup->sl_hit_at ? 'price reached' : 'waiting for price' }}">
                                <div class="label">Stop Loss</div><div class="value text-danger">{{ number_format($activeSetup->stop_loss, 2) }}</div>
                                <div id="{{ ($targetPrefix ?? '').'sl-hit-label' }}" class="sl-hit-label" @if(!$activeSetup->sl_hit_at) hidden @endif>PRICE REACHED</div>
                            </div></div>
                            @foreach(['tp1' => 'TP1', 'tp2' => 'TP2'] as $target => $label)
                            @php $hit = $activeSetup->{$target.'_hit_at'}; @endphp
                            <div class="col-6"><div id="{{ ($targetPrefix ?? '').$target }}-card" class="metric-pill tp-card {{ $hit ? 'tp-hit' : '' }}"
                                role="status" aria-live="polite" aria-label="{{ $label }} {{ $hit ? 'price reached' : 'waiting for price' }}"
                                @if($hit) title="Price reached; observed {{ $hit->format('d M H:i:s') }} UTC. This records a price touch." @endif>
                                <div class="label d-flex align-items-center justify-content-between gap-1"><span>{{ $label }}</span><span class="tp-led" @if(!$hit) hidden @endif aria-hidden="true"></span></div>
                                <div class="value text-success">{{ number_format($activeSetup->$target, 2) }}</div>
                                <div class="tp-hit-label" id="{{ ($targetPrefix ?? '').$target }}-hit-label" @if(!$hit) hidden @endif>PRICE REACHED</div>
                            </div></div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap" style="font-size:.72rem">
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-arrows-expand me-1"></i>Risk: {{ number_format(abs($activeSetup->entry - $activeSetup->stop_loss), 1) }} pts</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-diagram-3 me-1"></i>RR: {{ number_format(abs($activeSetup->tp1 - $activeSetup->entry) / max(0.01, abs($activeSetup->entry - $activeSetup->stop_loss)), 1) }}:1</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-clock me-1"></i>{{ $activeSetup->created_at->diffForHumans() }}</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)"><i class="bi bi-bar-chart me-1"></i>{{ strtoupper($activeSetup->timeframe) }}</span>
                    <span class="badge" style="background:var(--bg-panel);color:#8d97b0;border:1px solid var(--bg-border)">{{ ucfirst($activeSetup->status) }}</span>
                </div>
                <div id="{{ ($targetPrefix ?? '').'exit-tracking-status' }}" class="small mt-2" role="status" style="color:var(--gold)" @if(!in_array($activeSetup->exit_tracking_state, ['sl','tp2','ambiguous'], true)) hidden @endif>
                    @if($activeSetup->exit_tracking_state === 'ambiguous')
                    Stop and target crossed in one candle. First touch is unresolved; further exit tracking is paused.
                    @elseif($activeSetup->exit_tracking_state === 'sl')
                    SL price reached. Further target tracking has ended.
                    @elseif($activeSetup->exit_tracking_state === 'tp2')
                    TP2 price reached. Price tracking is complete.
                    @endif
                </div>
                @if($showManageLink ?? true)
                <a class="small d-inline-block mt-3" style="color:var(--gold)" href="{{ route('setups.index', ['setup_id' => $activeSetup->id]) }}">Manage this setup →</a>
                @endif
                @else
                <div class="text-center py-4" style="color:var(--text-muted-custom)">
                    <i class="bi bi-radar fs-2 d-block mb-2" style="color:var(--gold);opacity:.4"></i>
                    <div style="font-size:.85rem">No active setup</div>
                    <div style="font-size:.72rem;margin-top:.25rem">Engine is scanning orderflow…</div>
                </div>
                @endif
            </div>
        </div>
