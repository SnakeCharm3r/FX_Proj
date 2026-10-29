<?php

namespace Tests\Feature;

use App\Models\PriceZone;
use App\Models\Setup;
use App\Services\{AlertDispatchService, HourlyPriceHistoryService, MarketDataService, SetupBuilderService, ZoneRetestService};
use Illuminate\Support\Facades\{Artisan, DB};
use Tests\TestCase;

class ZoneRetestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-22 10:05:00', 'UTC'));
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC',
            'clusterdelta.bridge_token'=>'test-history-token','orderflow.min_confluence_score'=>60,
            'orderflow.delta_threshold'=>500,'orderflow.external_alerts_enabled'=>false]);
        DB::purge('sqlite'); Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);
        $alerter=\Mockery::mock(AlertDispatchService::class);$alerter->shouldNotReceive('dispatch');
        $this->app->instance(AlertDispatchService::class,$alerter);
    }

    private function metrics(array $changes=[]): array
    {
        return array_merge(['source'=>'clusterdelta','instrument'=>'GC','timeframe'=>'1h',
            'bar_time'=>strtotime('2026-10-22 09:00 UTC'),'closed'=>true,'open'=>100,'high'=>105,'low'=>97,
            'close'=>104,'price'=>104,'delta'=>600,'stacked_imbalance'=>'BULLISH',
            'cvd_divergence'=>'BULLISH_DIVERGENCE','vwap'=>104],$changes);
    }

    private function zone(array $changes=[]): PriceZone
    {
        return PriceZone::create(array_merge(['zone_key'=>uniqid('test-',true),'source'=>'clusterdelta',
            'instrument'=>'GC','version'=>config('price_zones.version'),'type'=>'DEMAND','timeframe'=>'1h',
            'low'=>97,'high'=>99,'atr'=>2,'origin_time'=>strtotime('2026-10-22 06:00 UTC'),
            'confirmed_time'=>strtotime('2026-10-22 09:00 UTC'),'status'=>'active'],$changes));
    }

    private function gate(array $m): array
    {
        return app(ZoneRetestService::class)->assess($m,app(SetupBuilderService::class)->assess($m));
    }

    public function test_demand_retest_rejection_and_native_orderflow_create_one_setup_with_zone_stop(): void
    {
        config(['orderflow.external_alerts_enabled'=>true]);
        $alerter=\Mockery::mock(AlertDispatchService::class);
        $alerter->shouldReceive('dispatch')->once()->withArgs(fn($type,$message,$level,$id)=>$type==='NEW_SETUP'
            &&$level==='buy'&&str_contains($message,'Buy opportunity')&&str_contains($message,'Delta:'));
        $this->app->instance(AlertDispatchService::class,$alerter);
        $zone=$this->zone();$m=$this->metrics();$gate=$this->gate($m);
        $this->assertTrue($gate['qualified']);$this->assertSame('confirmed',$gate['stage']);
        $m['zone_retest']=$gate;$builder=app(SetupBuilderService::class);
        $setup=$builder->evaluate($m);$this->assertSame($zone->id,$setup->price_zone_id);
        $this->assertSame('zone_retest_v1',$setup->strategy_version);$this->assertEquals(95.5,$setup->stop_loss);
        $this->assertSame($setup->id,$builder->evaluate($m)->id);$this->assertSame(1,Setup::count());
    }

    public function test_supply_retest_is_symmetric(): void
    {
        $this->zone(['type'=>'SUPPLY','low'=>101,'high'=>103]);
        $m=$this->metrics(['open'=>100,'high'=>103,'low'=>95,'close'=>96,'price'=>96,
            'delta'=>-600,'stacked_imbalance'=>'BEARISH','cvd_divergence'=>'BEARISH_DIVERGENCE','vwap'=>96]);
        $this->assertTrue($this->gate($m)['qualified']);
    }

    public function test_orderflow_may_confirm_on_next_candle_without_replaying_the_same_rejection(): void
    {
        $this->zone();$m=$this->metrics();$prior=$m+['time'=>$m['bar_time']];
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->andReturnUsing(fn($tf,$limit)=>in_array($limit,[4,500],true)?[json_encode($prior)]:[]);
        $this->app->instance(MarketDataService::class,$market);
        $later=$this->metrics(['bar_time'=>$m['bar_time']+3600,'open'=>104,'high'=>107,'low'=>103,'close'=>106,'price'=>106]);
        $this->travel(1)->hours();
        $gate=$this->gate($later);$this->assertTrue($gate['qualified']);
        $this->assertSame($m['bar_time'],$gate['rejection_time']);
        $builder=app(SetupBuilderService::class);
        $first=$builder->evaluate($m+['zone_retest'=>$this->gate($m)]);
        $again=$builder->evaluate($later+['zone_retest'=>$gate]);
        $this->assertSame($first->id,$again->id);$this->assertSame(1,Setup::count());
    }

    public function test_full_engine_requires_zone_gate_after_synchronized_native_footprint_checks(): void
    {
        config(['orderflow.setup_strategy'=>'zone_retest_v1']);
        $this->zone();$m=$this->metrics(['bar_count'=>20,'received_at'=>now()->timestamp,'buy_volume'=>1000,'sell_volume'=>400]);
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->andReturnUsing(fn($tf,$limit)=>$limit===1?[json_encode(['received_at'=>now()->timestamp])]:[]);
        $this->app->instance(MarketDataService::class,$market);
        $metrics=\Mockery::mock(\App\Services\OrderflowMetricsService::class);
        $metrics->shouldReceive('compute')->andReturn($m);$this->app->instance(\App\Services\OrderflowMetricsService::class,$metrics);
        $footprints=\Mockery::mock(\App\Services\FootprintService::class)->makePartial();
        $snapshot=['received_at'=>now()->timestamp,'tick_size'=>.1,'bars'=>[
            ['time'=>$m['bar_time'],'closed'=>true,'source_totals_match'=>true,'volume'=>1400,'delta'=>600,
             'poc'=>2000,'open'=>2000,'high'=>2000.3,'low'=>2000,'close'=>2000.3,'levels'=>[
                ['price'=>2000,'ask'=>1,'bid'=>1],['price'=>2000.1,'ask'=>30,'bid'=>1],
                ['price'=>2000.2,'ask'=>30,'bid'=>1],['price'=>2000.3,'ask'=>30,'bid'=>1]]]]];
        $footprints->shouldReceive('snapshot')->andReturn($snapshot);
        $this->app->instance(\App\Services\FootprintService::class,$footprints);
        \Illuminate\Support\Facades\Redis::shouldReceive('setex')->andReturn(true);
        $state=app(\App\Services\SetupEngineService::class)->run('1h');
        $this->assertSame('detected',$state['status']);$this->assertTrue($state['zone_retest']['qualified']);
        $this->assertSame('zone_retest_v1',Setup::sole()->strategy_version);
        PriceZone::query()->delete();
        $state=app(\App\Services\SetupEngineService::class)->run('1h');
        $this->assertSame('waiting',$state['status']);$this->assertFalse($state['zone_retest']['qualified']);
        $this->assertSame(1,Setup::count());
    }

    public function test_complete_broker_window_distinguishes_closed_hours_from_candles_outside_loaded_slice(): void
    {
        $time=strtotime('2026-10-21 21:00 UTC');
        DB::table('broker_history_windows')->insert(['instrument'=>'GC','first_time'=>$time-3600,'last_time'=>$time+3600]);
        $history=app(HourlyPriceHistoryService::class);$windows=$history->coverage();
        $this->assertTrue($history->covered($time,$windows));
        $this->assertFalse($history->covered($time-7200,$windows));
        DB::table('broker_hourly_prices')->insert(['instrument'=>'GC','symbol'=>'XAUUSDm','time'=>$time,'open'=>100,'high'=>101,'low'=>99,'close'=>100]);
        $this->assertFalse($history->covered($time,$windows));
    }

    public function test_touch_without_rejection_or_without_aligned_orderflow_never_qualifies(): void
    {
        $this->zone();
        $this->assertFalse($this->gate($this->metrics(['close'=>98,'price'=>98]))['qualified']);
        $this->assertSame('arrival',$this->gate($this->metrics(['close'=>98,'price'=>98]))['stage']);
        $this->assertFalse($this->gate($this->metrics(['delta'=>-600]))['qualified']);
        $this->assertFalse($this->gate($this->metrics(['stacked_imbalance'=>'NONE']))['qualified']);
        $this->assertFalse($this->gate($this->metrics(['cvd_divergence'=>'NONE']))['qualified']); // 40 points remains below 60
        $this->assertFalse($this->gate($this->metrics(['low'=>99.9]))['qualified']);
    }

    public function test_future_invalidated_and_unverifiable_zones_cannot_create_alerts(): void
    {
        $zone=$this->zone(['confirmed_time'=>strtotime('2026-10-22 10:00 UTC')]);
        $this->assertFalse($this->gate($this->metrics())['qualified']);
        $zone->update(['confirmed_time'=>strtotime('2026-10-22 09:00 UTC'),'invalidated_time'=>strtotime('2026-10-22 09:00 UTC')]);
        $this->assertFalse($this->gate($this->metrics())['qualified']);
        $zone->update(['confirmed_time'=>strtotime('2026-10-22 08:00 UTC'),'invalidated_time'=>null]);
        $gate=$this->gate($this->metrics());$this->assertFalse($gate['qualified']);
        $this->assertStringContainsString('missing H1',$gate['reason']);
    }

    public function test_original_65_point_sell_is_preserved_as_score_but_blocked_without_supply_retest(): void
    {
        $m=$this->metrics(['open'=>4091.512,'high'=>4092.852,'low'=>4066.745,'close'=>4089.694,'price'=>4089.694,
            'delta'=>-603,'stacked_imbalance'=>'BEARISH','absorption'=>'BEAR_ABSORPTION',
            'cvd_divergence'=>'BULLISH_DIVERGENCE','vwap'=>4120.18]);
        $assessment=app(SetupBuilderService::class)->assess($m);
        $this->assertSame(65,$assessment['bear_score']);$this->assertSame(20,$assessment['bull_score']);
        $gate=$this->gate($m);$this->assertFalse($gate['qualified']);
        $this->assertStringContainsString('SUPPLY',$gate['reason']);
        $m['zone_retest']=$gate;$this->assertNull(app(SetupBuilderService::class)->evaluate($m));
        $this->assertSame(0,Setup::count());
    }

    public function test_history_endpoint_authenticates_upserts_and_stays_separate_from_orderflow(): void
    {
        $payload=['instrument'=>'GC','symbol'=>'XAUUSDm','tf'=>'1h','bars'=>[
            ['time'=>strtotime('2026-10-01 08:00 UTC'),'open'=>100,'high'=>105,'low'=>97,'close'=>104]]];
        $this->postJson('/api/clusterdelta/history',$payload)->assertUnauthorized();
        $this->withToken('test-history-token')->postJson('/api/clusterdelta/history',$payload)->assertOk()->assertJsonPath('accepted',1);
        $this->withToken('test-history-token')->postJson('/api/clusterdelta/history',$payload)->assertOk();
        $this->assertSame(1,DB::table('broker_hourly_prices')->count());
        $bar=app(HourlyPriceHistoryService::class)->bars()[0];
        $this->assertSame('broker_h1_backfill',$bar['candle_source']);
        $this->assertArrayNotHasKey('delta',$bar);$this->assertArrayNotHasKey('volume',$bar);
        $this->getJson('/api/chart-data?tf=1h')->assertOk()->assertJsonCount(0,'candles')
            ->assertJsonCount(0,'volume')->assertJsonCount(0,'vwap')->assertJsonCount(0,'poc')
            ->assertJsonPath('stats.price_only_bars',0)->assertJsonPath('stats.price_source','GC_futures');
        $payload['bars'][0]['time']=strtotime('2026-10-22 10:00 UTC');
        $this->withToken('test-history-token')->postJson('/api/clusterdelta/history',$payload)->assertUnprocessable();
        $payload['bars'][0]['time']=strtotime('2026-10-01 08:00 UTC');$payload['bars'][0]['low']=106;
        $this->withToken('test-history-token')->postJson('/api/clusterdelta/history',$payload)->assertUnprocessable();
    }
}
