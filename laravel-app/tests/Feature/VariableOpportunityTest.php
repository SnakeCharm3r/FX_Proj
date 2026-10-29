<?php

namespace Tests\Feature;

use App\Models\AlertLog;
use App\Models\Setup;
use App\Models\VariableOpportunity;
use App\Services\AbsorptionSequenceService;
use App\Services\AlertDispatchService;
use App\Services\FootprintService;
use App\Services\MarketDataService;
use App\Services\VariableOpportunityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VariableOpportunityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC',
            'price_zones.version'=>'rejection-swing-v2','orderflow.variable_min_rules'=>25,
            'orderflow.external_alerts_enabled'=>false,'orderflow.variable_opportunities_enabled'=>true]);
        DB::purge('sqlite');Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
    }

    private function history(): array
    {
        $last=intdiv(now()->timestamp,300)*300-300;$bars=[];
        for($i=0;$i<20;$i++)$bars[]=['time'=>$last-(20-$i)*300,'open'=>101,'high'=>104,'low'=>98,
            'close'=>103,'closed'=>true,'buy_vol'=>50,'sell_vol'=>50,'delta'=>0,'received_at'=>now()->timestamp];
        $bars[]=['time'=>$last,'open'=>101,'high'=>104,'low'=>98,'close'=>103,'closed'=>true,
            'buy_vol'=>120,'sell_vol'=>80,'delta'=>40,'received_at'=>now()->timestamp];
        return $bars;
    }

    public function test_chart_uses_real_broker_prices_and_can_select_native_gc_without_fallback(): void
    {
        $time=intdiv(now()->timestamp,300)*300;
        $fpBar=['time'=>$time,'open'=>4009,'high'=>4011,'low'=>4008,'close'=>4009.1,'received_at'=>now()->timestamp];
        $brokerBar=array_merge($fpBar,['open'=>3997,'high'=>3999,'low'=>3996,'close'=>3997.899,'price_source'=>'mt5_broker']);
        $replay=\Mockery::mock(\App\Services\StrategyDryRunService::class);
        $replay->shouldReceive('archive')->andReturn([]);
        $this->app->instance(\App\Services\StrategyDryRunService::class,$replay);
        $fp=\Mockery::mock(FootprintService::class);$fp->shouldReceive('bars')->andReturn([$fpBar]);
        $this->app->instance(FootprintService::class,$fp);
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('storedBars')->once()->andReturn([json_encode($brokerBar)]);
        $market->shouldReceive('storedBars')->once()->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);
        $url='/api/variable-opportunities?date='.gmdate('Y-m-d',$time);
        $this->getJson($url)->assertOk()->assertJsonPath('price_source','mt5_broker')
            ->assertJsonPath('candles.0.close',3997.899)->assertJsonPath('feed_fresh',true);
        $this->getJson($url.'&price_source=GC_futures')->assertOk()->assertJsonPath('candles.0.close',4009.1);
        $this->getJson($url)->assertOk()->assertJsonPath('candles',[])
            ->assertJsonPath('chart_fresh',false)->assertJsonPath('feed_fresh',true);
    }

    private function frame(array $bar): array
    {
        return ['time'=>$bar['time'],'closed'=>$bar['closed'] ?? true,'open'=>$bar['open'],'high'=>$bar['high'],
            'low'=>$bar['low'],'close'=>$bar['close'],'ask'=>$bar['buy_vol'],'bid'=>$bar['sell_vol'],
            'buy_vol'=>$bar['buy_vol'],'sell_vol'=>$bar['sell_vol'],
            'volume'=>$bar['buy_vol']+$bar['sell_vol'],'delta'=>$bar['delta'],'received_at'=>$bar['received_at'],
            'source_totals_match'=>true,'tick_size'=>.1,
            'levels'=>[['price'=>$bar['open'],'ask'=>$bar['buy_vol'],'bid'=>$bar['sell_vol']]]];
    }

    private function frames(array $bars): array { return array_map(fn($bar)=>$this->frame($bar),$bars); }

    private function h1(): array
    {
        $start=(intdiv(now()->timestamp,3600)-10)*3600;
        $prices=[[107,110,104,108],[105,109,102,104],[100,104,95,103],[103,107,99,105],[105,111,103,110]];
        return array_map(fn($p,$i)=>['time'=>$start+$i*3600,'open'=>$p[0],'high'=>$p[1],'low'=>$p[2],
            'close'=>$p[3],'closed'=>true],$prices,array_keys($prices));
    }

    public function test_increased_participation_without_zone_records_25_percent_and_historical_import_never_sends(): void
    {
        $alerts=\Mockery::mock(AlertDispatchService::class);$alerts->shouldNotReceive('dispatch');
        $this->app->instance(AlertDispatchService::class,$alerts);
        $bars=$this->history();$frames=$this->frames($bars);$service=app(VariableOpportunityService::class);
        $first=$service->record($bars,$frames,[],true);$second=$service->record($bars,$frames,[],true);
        $this->assertCount(1,$first);$this->assertSame(25,$first[0]->score);
        $this->assertSame(20.0,$first[0]->delta_pct);$this->assertSame($first[0]->id,$second[0]->id);
        $this->assertSame(1,VariableOpportunity::count());$this->assertSame(0,Setup::count());
        $this->assertSame('historical',$first[0]->telegram_status);
        $this->assertFalse($service->notify($first[0]));
    }

    public function test_same_h1_zone_as_replay_records_50_percent_without_enforcing_the_strict_risk_gate(): void
    {
        config(['orderflow.live_max_stop_points'=>2]);
        $bars=$this->history();$rows=app(VariableOpportunityService::class)->record($bars,$this->frames($bars),$this->h1(),false);
        $this->assertCount(1,$rows);$row=$rows[0];$this->assertSame(50,$row->score);
        $this->assertSame('DEMAND',$row->evidence['zone_type']);$this->assertFalse($row->evidence['strict_risk_passed']);
        $this->assertSame('pending',$row->telegram_status);$this->assertSame(103.0,$row->entry);
        $this->assertSame(96.5,$row->stop_loss);$this->assertSame(112.75,$row->tp1);$this->assertSame(122.5,$row->tp2);
    }

    public function test_completed_absorption_has_75_percent_without_zone_and_100_with_zone(): void
    {
        $bars=$this->history();$time=end($bars)['time'];
        $abs=\Mockery::mock(AbsorptionSequenceService::class);
        $abs->shouldReceive('analyse')->andReturn(['qualified'=>true,'type'=>'BULL_ABSORPTION','direction'=>'BUY',
            'stage'=>'confirmed','reason'=>'Test absorption sequence','effort_time'=>$time-600,'effort_low'=>98,
            'effort_high'=>102,'rejection_low'=>98,'rejection_high'=>102]);
        $this->app->instance(AbsorptionSequenceService::class,$abs);
        $service=app(VariableOpportunityService::class);$frames=$this->frames($bars);
        $rows=$service->record($bars,$frames,[],true);$this->assertSame(75,$rows[0]->score);
        VariableOpportunity::query()->delete();
        $rows=$service->record($bars,$frames,$this->h1(),true);$this->assertSame(100,$rows[0]->score);
    }

    public function test_stale_future_developing_and_mismatched_data_do_not_create_live_candidates(): void
    {
        $bars=$this->history();$frames=$this->frames($bars);$service=app(VariableOpportunityService::class);
        $bad=$frames;$bad[20]['received_at']=now()->timestamp-120;
        $this->assertSame([],$service->record($bars,$bad,[],false));
        $bad=$frames;$bad[20]['time']=now()->timestamp+300;
        $this->assertSame([],$service->record($bars,$bad,[],false));
        $bad=$frames;$bad[20]['closed']=false;$this->assertSame([],$service->record($bars,$bad,[],false));
        $bad=$frames;$bad[20]['source_totals_match']=false;
        $this->assertSame([],$service->record($bars,$bad,[],false));
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->andReturn(array_map('json_encode',array_reverse($bars)));
        $fp=\Mockery::mock(FootprintService::class);$fp->shouldReceive('snapshot')->andReturn(['received_at'=>now()->timestamp-120,'bars'=>$frames]);
        $this->app->instance(MarketDataService::class,$market);$this->app->instance(FootprintService::class,$fp);
        $this->assertSame('waiting_for_fresh_footprint',$service->live()['status']);
        $this->assertSame(0,VariableOpportunity::count());
    }

    public function test_telegram_delivery_has_simple_levels_and_occurs_once_even_after_repeated_evaluation(): void
    {
        config(['orderflow.external_alerts_enabled'=>true]);$bars=$this->history();
        $alerts=\Mockery::mock(AlertDispatchService::class);
        $alerts->shouldReceive('dispatch')->once()->andReturnUsing(function($type,$message,$level,$setupId,$opportunityId){
            $this->assertSame('VARIABLE_OPPORTUNITY',$type);$this->assertNull($setupId);
            $this->assertStringContainsString('GC · Buy opportunity',$message);
            $this->assertStringContainsString('🟢 ▲ Positive Delta: <b>+20.0%</b>',$message);
            $this->assertStringContainsString('📊 Volume: <b>200</b>',$message);
            $this->assertStringContainsString('Confluence: <b>25%</b>',$message);
            $this->assertStringNotContainsString('UTC',$message);$this->assertStringNotContainsString('Historical',$message);
            AlertLog::create(['variable_opportunity_id'=>$opportunityId,'channel'=>'telegram','type'=>$type,
                'message'=>$message,'status'=>'sent']);
        });
        $this->app->instance(AlertDispatchService::class,$alerts);$service=app(VariableOpportunityService::class);
        $row=$service->record($bars,$this->frames($bars),[],false)[0];
        $this->assertTrue($service->notify($row));$this->assertFalse($service->notify($row->fresh()));
        $this->assertSame('sent',$row->fresh()->telegram_status);$this->assertSame(1,AlertLog::count());
        $this->assertStringContainsString('GC · Historical Buy opportunity',$service->message($row,true));
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);
        $date=gmdate('Y-m-d',$row->candle_time);
        $this->getJson('/api/variable-opportunities?date='.$date)->assertOk()->assertJsonPath('summary.total',1)
            ->assertJsonPath('opportunities.0.score',25)->assertJsonPath('opportunities.0.telegram_status','sent');
        $this->get('/opportunities')->assertOk()->assertSee('Variable entry opportunities')->assertSee('Entry candidates');
    }

    public function test_telegram_template_marks_negative_delta_and_uses_the_saved_bar_volume(): void
    {
        $row=new VariableOpportunity(['instrument'=>'XAUUSD','direction'=>'SELL','score'=>75,
            'delta_pct'=>-23.3,'stop_loss'=>4100.25,'tp1'=>4080.50,'tp2'=>4070.25,
            'evidence'=>['volume'=>834]]);

        $message=app(VariableOpportunityService::class)->message($row);
        $this->assertStringContainsString('XAUUSD · Sell opportunity',$message);
        $this->assertStringContainsString('🔴 ▼ Negative Delta: <b>-23.3%</b>',$message);
        $this->assertStringContainsString('📊 Volume: <b>834</b>',$message);
    }
}
