@extends('layouts.app')
@section('title','Scalp Strategy Replay')

@push('styles')
<style>
    #scalp-chart{height:560px}.scalp-stat{min-height:94px}.scalp-stat .value{font:700 1.45rem 'JetBrains Mono',monospace}
    #scalp-chart-wrap{position:relative}#scalp-zone-overlay{position:absolute;inset:0;width:100%;height:100%;z-index:4;pointer-events:none}
    @media(max-width:768px){#scalp-chart{height:430px}}
</style>
@endpush

@section('content')
@php
    $s=$report['summary'];$c=$report['coverage'];$o=$report['outcome_counts'];$capital=$report['capital'];$d=$report['diagnostics'];
    $eat=fn($timestamp)=>$timestamp?\Carbon\CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone('Africa/Dar_es_Salaam')->format('d M H:i'):'—';
    $chartBars=array_map(static fn($bar)=>array_intersect_key($bar,array_flip(['time','open','high','low','close'])),$report['bars']);
@endphp
<div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
    <div><h5 class="mb-1" style="font-weight:700"><i class="bi bi-lightning-charge me-2" style="color:var(--gold)"></i>M5 Scalp Confluence Replay</h5>
        <div class="small" style="color:var(--text-muted-custom)">H1 swing range + relative participation + delta share + M5 price confirmation · expanded entry threshold {{ config('orderflow.scalp_min_confluence') }}%</div></div>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <a href="{{ route('setups.tester') }}" class="btn btn-sm btn-outline-secondary text-nowrap">Reversal replay</a>
        <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0">
        <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm" style="background:var(--bg-panel);border-color:var(--bg-border);color:#e2e8f0">
        <button class="btn btn-sm btn-warning">Run replay</button>
    </form>
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>Loss concentration and risk audit</span><span>Combined 60–100% setup sequence</span></div>
    <div class="p-3 small" style="color:var(--text-muted-custom)">
        The three largest stopped trades lost <strong style="color:var(--red-bear)">${{ number_format($d['top_three_loss'],2) }}</strong>,
        {{ number_format($d['top_three_share'],1) }}% of the ${{ number_format($d['gross_loss'],2) }} gross loss.
        These comparisons filter the trades already selected; they do not fill the newly empty periods with alternative entries.
    </div>
    <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Risk-width audit</th><th>Trades</th><th>Worked</th><th>Failed</th><th>Open</th><th>Win rate</th><th>Net R</th><th>0.01-lot P/L</th></tr></thead><tbody>
        @foreach($d['risk_audits'] as $row)<tr><td>Stop distance ≤{{ number_format($row['cap'],1) }} gold points</td><td>{{ $row['setups'] }}</td><td>{{ $row['worked'] }}</td><td>{{ $row['failed'] }}</td><td>{{ $row['open'] }}</td><td>{{ number_format($row['success_rate'],1) }}%</td><td>{{ sprintf('%+.2fR',$row['result_r']) }}</td><td style="color:{{ $row['pnl']>=0?'var(--green-bull)':'var(--red-bear)' }}">{{ sprintf('%+.2f',$row['pnl']) }}</td></tr>@endforeach
    </tbody></table></div>
</div>

@foreach(['worst'=>'Stopped setups · worst first','profitable'=>'Profitable setups · best first'] as $diagnosticKey=>$diagnosticTitle)
<div class="card-panel mb-3">
    <div class="card-header-custom"><span>{{ $diagnosticTitle }}</span><span>{{ count($d[$diagnosticKey]) }} setups</span></div>
    <div class="table-responsive" style="max-height:430px"><table class="table of-table mb-0"><thead><tr><th>Signal EAT</th><th>Side</th><th>Score</th><th>Z/V/Δ/P</th><th>Zone</th><th>Participation</th><th>Delta</th><th>Risk</th><th>Outcome</th><th>P/L</th><th>Evidence notes</th></tr></thead><tbody>
        @foreach($d[$diagnosticKey] as $row)<tr>
            <td>{{ $eat($row['signal_time']) }}</td><td style="color:{{ $row['direction']==='BUY'?'var(--green-bull)':'var(--red-bear)' }}">{{ $row['direction'] }}</td><td>{{ $row['score'] }}%</td>
            <td>{{ implode(' / ',$row['components']['points']) }}</td><td>{{ $row['zone']['type']??'NONE' }}</td><td>{{ $row['participation'] }} · {{ number_format((float)$row['volume_ratio'],2) }}x</td>
            <td>{{ sprintf('%+.1f%%',$row['delta_pct']) }}</td><td>{{ number_format($row['risk'],2) }} pts</td><td>{{ $row['outcome_bucket'] }}</td>
            <td style="color:{{ $row['account_pnl']>=0?'var(--green-bull)':'var(--red-bear)' }}">{{ sprintf('%+.2f',$row['account_pnl']) }}</td><td>{{ implode(' · ',$row['diagnostic_tags']) }}</td>
        </tr>@endforeach
    </tbody></table></div>
</div>
@endforeach

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>$100 fixed-lot account replay</span><span>{{ number_format($capital['lot'],2) }} lot · ${{ number_format($capital['dollars_per_price'],2) }} per $1 gold move</span></div>
    <div class="row g-3 p-3">
        @foreach([
            ['Starting','$'.number_format($capital['starting_balance'],2),'var(--text-main)'],
            ['Realized balance','$'.number_format($capital['ending_balance'],2),$capital['ending_balance']>=$capital['starting_balance']?'var(--green-bull)':'var(--red-bear)'],
            ['Floating',sprintf('%+.2f',$capital['floating_pnl']),'#60a5fa'],
            ['Ending equity','$'.number_format($capital['ending_equity'],2),$capital['ending_equity']>=$capital['starting_balance']?'var(--green-bull)':'var(--red-bear)'],
            ['Return',sprintf('%+.1f%%',$capital['return_pct']),$capital['return_pct']>=0?'var(--green-bull)':'var(--red-bear)'],
            ['Lowest balance','$'.number_format($capital['lowest_balance'],2),'var(--gold)'],
            ['Max drawdown','$'.number_format($capital['max_drawdown'],2).' · '.number_format($capital['max_drawdown_pct'],1).'%','var(--red-bear)'],
            ['Largest planned risk',number_format($capital['max_risk_pct'],1).'% of balance','var(--red-bear)']
        ] as [$label,$value,$color])
        <div class="col-6 col-md-3"><div class="scalp-stat p-3" style="background:rgba(255,255,255,.015);border:1px solid var(--bg-border);border-radius:.5rem"><div class="text-uppercase" style="font-size:.65rem;color:var(--text-muted-custom)">{{ $label }}</div><div class="value mt-2" style="color:{{ $color }}">{{ $value }}</div></div></div>
        @endforeach
    </div>
    <div class="px-3 pb-3 small" style="color:var(--text-muted-custom)">
        {{ $capital['survived_before_costs']?'The replay balance remained above zero.':'The replay balance reached zero; later trades could not have been taken.' }}
        This excludes spread, commission, swap, slippage and broker margin/stop-out rules. A 50% TP1 assumes the broker permits closing 0.005 lot.
    </div>
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>Risk-plan outcome distribution</span><span>TP1 closes 50% · TP2 closes remaining 50%</span></div>
    <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Outcome</th><th>Setups</th><th>Share</th><th>Risk-plan meaning</th></tr></thead><tbody>
        @foreach([
            ['Stop loss',$o['loss'],'var(--red-bear)','SL reached before TP1 · −1.00R'],
            ['TP1 · 50% closed',$o['tp1_only'],'var(--gold)','First half closed at 1.5R; TP2 was not reached'],
            ['TP2 · 100% closed',$o['tp2'],'var(--green-bull)','Half at TP1 plus remaining half at TP2 · +2.25R'],
            ['Open before TP1',$o['open'],'#60a5fa','No tested exit yet'],
            ['Ambiguous candle',$o['ambiguous'],'#a78bfa','SL and target crossed inside the same M5 candle']
        ] as [$label,$count,$color,$meaning])
        <tr><td style="color:{{ $color }}">{{ $label }}</td><td>{{ $count }}</td><td>{{ $o['total']?number_format(100*$count/$o['total'],1).'%' :'—' }}</td><td>{{ $meaning }}</td></tr>
        @endforeach
    </tbody></table></div>
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>Expanded confluence scoring</span><span>60% minimum · 75% high tier</span></div>
    <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Reason</th><th>Full points</th><th>Partial points</th></tr></thead><tbody>
        <tr><td>Matching confirmed H1 wick-to-body range</td><td>Inside matching zone: 25</td><td>Outside zone: 0</td></tr>
        <tr><td>M5 market participation</td><td>Increased: 25</td><td>Normal: 10 · Decreased: 0</td></tr>
        <tr><td>Directional delta ÷ total volume</td><td>Absolute share ≥10%: 25</td><td>5–9.9%: 15 · Below 5%: ignored</td></tr>
        <tr><td>M5 price confirmation</td><td>Aligned candle closing in outer 35%: 25</td><td>Aligned candle: 10 · Opposing candle: 0</td></tr>
    </tbody></table></div>
</div>

<div class="row g-3 mb-3">
@foreach([
    ['Setups',$s['setups'],'var(--gold)'],['BUY / SELL',$s['buy'].' / '.$s['sell'],'#60a5fa'],
    ['Worked',$s['worked'],'var(--green-bull)'],['Failed',$s['failed'],'var(--red-bear)'],
    ['Success',$s['success_rate']===null?'—':$s['success_rate'].'%','#a78bfa'],
    ['Resolved net',number_format($s['net_r'],2).'R',$s['net_r']>0?'var(--green-bull)':'var(--red-bear)']
] as [$label,$value,$color])
<div class="col-6 col-lg-2"><div class="card-panel scalp-stat p-3"><div class="text-uppercase" style="font-size:.65rem;color:var(--text-muted-custom);letter-spacing:.07em">{{ $label }}</div><div class="value mt-2" style="color:{{ $color }}">{{ $value }}</div></div></div>
@endforeach
</div>

@unless($c['available'])
<div class="alert mb-3" style="background:rgba(255,193,7,.08);border:1px solid rgba(255,193,7,.3);color:var(--gold)">
    {{ $c['reason'] }} Requested {{ $from }} through {{ $to }}; {{ $c['bars'] }} archived M5 bars were found.
</div>
@endunless

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>XAUUSDm M5 · confluence setups and H1 ranges</span><span>{{ $c['bars'] }} bars</span></div>
    <div id="scalp-chart-wrap"><div id="scalp-chart"></div><svg id="scalp-zone-overlay" aria-label="Historical H1 ranges"></svg></div>
    <div class="px-3 pb-3 small" style="color:var(--text-muted-custom)">Green and red arrows are entries at ≥{{ config('orderflow.scalp_min_confluence') }}% confluence. High setups score 75–100%; expanded setups score 60–74%. Thin low-opacity boxes are confirmed H1 swing ranges. Percentages are rule completion, not win probability.</div>
</div>

<div class="card-panel mb-3">
    <div class="card-header-custom"><span>Scalp setups</span><span>{{ count($report['setups']) }} rows</span></div>
    <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Signal EAT</th><th>Side</th><th>Confluence</th><th>Point reasons Z/V/Δ/P</th><th>Tier</th><th>Delta</th><th>Volume</th><th>Entry</th><th>Risk</th><th>SL</th><th>TP1 · 50%</th><th>TP2 · 100%</th><th>Exit EAT / price</th><th>Outcome</th><th>Net</th><th>Trade P/L</th><th>Balance</th></tr></thead>
    <tbody>@forelse($report['setups'] as $row)<tr>
        <td>{{ $eat($row['signal_time']) }}</td><td style="color:{{ $row['direction']==='BUY'?'var(--green-bull)':'var(--red-bear)' }}">{{ $row['direction'] }}</td>
        <td>{{ $row['score'] }}%</td><td>{{ implode(' / ',$row['components']['points']) }}</td><td>{{ $row['confidence_tier']==='HIGH'?'HIGH':'EXPANDED' }}</td><td>{{ sprintf('%+.1f%%',$row['delta_pct']) }}</td>
        <td>{{ number_format($row['volume']) }} · {{ number_format((float)$row['volume_ratio'],2) }}x</td>
        <td>{{ number_format($row['entry'],2) }}</td><td>{{ number_format($row['risk'],2) }} pts<br><small>1:1.5 / 1:3</small></td><td>{{ number_format($row['stop'],2) }}</td>
        <td>{{ number_format($row['tp1'],2) }}<br><small>{{ $row['tp1_hit']?'reached '.$eat($row['tp1_time']):'not reached' }}</small></td><td>{{ number_format($row['tp2'],2) }}</td>
        <td>{{ $eat($row['exit_time']) }}<br><small>{{ $row['exit_price']===null?'—':number_format($row['exit_price'],2) }}</small></td>
        <td>{{ $row['outcome_bucket'] }}</td><td style="color:{{ $row['result_r']>0?'var(--green-bull)':($row['result_r']<0?'var(--red-bear)':'var(--text-muted-custom)') }}">{{ sprintf('%+.2fR',$row['result_r']) }}</td>
        <td style="color:{{ $row['account_pnl']>=0?'var(--green-bull)':'var(--red-bear)' }}">{{ sprintf('%+.2f',$row['account_pnl']) }}</td><td>${{ number_format($row['account_balance_after'],2) }}</td>
    </tr>@empty<tr><td colspan="17" class="text-center py-4" style="color:var(--text-muted-custom)">No setup can be calculated until historical M5 volume/delta exists for this period.</td></tr>@endforelse</tbody>
    </table></div>
</div>

<div class="row g-3 mb-3">
@foreach(['tier'=>'By confidence tier','confluence'=>'By confluence','direction'=>'By direction','day'=>'By day'] as $key=>$title)
<div class="col-12 {{ $key==='day'?'col-xl-6':'col-xl-3' }}"><div class="card-panel h-100">
    <div class="card-header-custom"><span>{{ $title }}</span></div>
    <div class="table-responsive"><table class="table of-table mb-0"><thead><tr><th>Group</th><th>N</th><th>W/L/O</th><th>Win</th><th>Net</th></tr></thead>
    <tbody>@forelse($report['breakdowns'][$key] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['setups'] }}</td>
        <td>{{ $row['worked'] }}/{{ $row['failed'] }}/{{ $row['open'] }}</td><td>{{ $row['success_rate']===null?'—':$row['success_rate'].'%' }}</td><td>{{ number_format($row['net_r'],2) }}R</td></tr>
    @empty<tr><td colspan="5">No resolved setups.</td></tr>@endforelse</tbody></table></div>
</div></div>
@endforeach
</div>

<div class="card-panel">
    <div class="card-header-custom"><span>H1 ranges visible during replay</span><span>{{ count($report['ranges']) }} ranges</span></div>
    <div class="table-responsive" style="max-height:320px"><table class="table of-table mb-0"><thead><tr><th>Type</th><th>Range</th><th>Origin EAT</th><th>Known from EAT</th><th>Invalidated EAT</th></tr></thead>
    <tbody>@forelse($report['ranges'] as $zone)<tr><td style="color:{{ $zone['type']==='DEMAND'?'var(--green-bull)':'var(--red-bear)' }}">{{ $zone['type'] }}</td>
        <td>{{ number_format($zone['low'],2) }}–{{ number_format($zone['high'],2) }}</td><td>{{ $eat($zone['origin_time']) }}</td><td>{{ $eat($zone['confirmed_time']) }}</td><td>{{ $eat($zone['invalidated_time']) }}</td>
    </tr>@empty<tr><td colspan="5">No chronologically confirmed H1 swing ranges.</td></tr>@endforelse</tbody></table></div>
</div>
@endsection

@push('scripts')
<script>
(()=>{
    const host=document.getElementById('scalp-chart'),wrap=document.getElementById('scalp-chart-wrap'),svg=document.getElementById('scalp-zone-overlay');
    const bars=@json($chartBars),zones=@json($report['ranges']),markers=@json($report['markers']);
    const chart=LightweightCharts.createChart(host,{layout:{background:{color:'#141720'},textColor:'#8d97b0'},grid:{vertLines:{color:'#252a38'},horzLines:{color:'#252a38'}},rightPriceScale:{borderColor:'#252a38'},timeScale:{borderColor:'#252a38',timeVisible:true}});
    const series=chart.addCandlestickSeries({upColor:'#00c076',downColor:'#ff3b5c',borderVisible:false,wickUpColor:'#00c076',wickDownColor:'#ff3b5c'});
    series.setData(bars);series.setMarkers(markers);chart.timeScale().fitContent();
    const ns='http://www.w3.org/2000/svg';
    const coordinate=time=>{
        if(!bars.length)return null;if(time<bars[0].time)return -1;
        const i=bars.findIndex(bar=>bar.time>=time);if(i<0)return chart.timeScale().logicalToCoordinate(bars.length);
        return chart.timeScale().timeToCoordinate(bars[i].time);
    };
    const paint=()=>{
        svg.replaceChildren();if(!bars.length)return;
        const width=wrap.clientWidth,height=wrap.clientHeight,paneWidth=Math.max(0,width-chart.priceScale('right').width()),paneHeight=Math.max(0,height-28);
        svg.setAttribute('viewBox','0 0 '+width+' '+height);
        for(const zone of zones){
            const y1=series.priceToCoordinate(zone.high),y2=series.priceToCoordinate(zone.low),origin=coordinate(zone.confirmed_time);
            const finish=zone.invalidated_time?coordinate(zone.invalidated_time):paneWidth;
            if(y1===null||y2===null||origin===null||finish===null)continue;
            const x=Math.max(0,origin),right=Math.min(paneWidth,finish),y=Math.min(y1,y2),h=Math.max(1,Math.abs(y2-y1));
            if(right<=x||x>=paneWidth||right<=0||y>paneHeight||y+h<0)continue;
            const rgb=zone.type==='DEMAND'?'0,192,118':'255,59,92';
            const rect=document.createElementNS(ns,'rect');
            for(const [name,value] of Object.entries({x,y,width:right-x,height:h,fill:'rgba('+rgb+',.025)',stroke:'rgba('+rgb+',.42)','stroke-width':1}))rect.setAttribute(name,value);
            svg.appendChild(rect);
        }
    };
    chart.timeScale().subscribeVisibleLogicalRangeChange(paint);
    new ResizeObserver(()=>{chart.applyOptions({width:host.clientWidth});paint();}).observe(host);
    setTimeout(paint,0);
})();
</script>
@endpush
