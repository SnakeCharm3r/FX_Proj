<?php

namespace Tests\Feature;

use App\Models\PriceZone;
use App\Models\Setup;
use App\Services\AlertDispatchService;
use App\Services\MarketDataService;
use App\Services\SetupBuilderService;
use App\Services\ZoneAbsorptionStrategyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ZoneAbsorptionStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC',
            'orderflow.min_confluence_score'=>60,'orderflow.external_alerts_enabled'=>false]);
        DB::purge('sqlite');
        Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
        $alerter=\Mockery::mock(AlertDispatchService::class);$alerter->shouldNotReceive('dispatch');
        $this->app->instance(AlertDispatchService::class,$alerter);
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);
    }

    private function metrics(): array
    {
        return ['source'=>'clusterdelta','instrument'=>'GC','timeframe'=>'5m',
            'bar_time'=>strtotime('2026-10-22 06:25 UTC'),'closed'=>true,
            'open'=>4098.39,'high'=>4098.44,'low'=>4091.43,'close'=>4091.69,'price'=>4091.69,
            'delta'=>-194,'buy_volume'=>320,'sell_volume'=>514,'cvd'=>0,'vwap'=>4095,
            'absorption'=>'BEAR_ABSORPTION','cvd_divergence'=>'NONE','stacked_imbalance'=>'NONE',
            'exhaustion'=>'NONE','poc_rejection'=>'NONE','body_range_ratio'=>.95,
            'participation'=>['state'=>'NORMAL','volume'=>834,'ratio'=>1.09,'baseline_sample_size'=>20,
                'session_coverage'=>'COMPLETE'],
            'absorption_evidence'=>['qualified'=>true,'type'=>'BEAR_ABSORPTION','direction'=>'SELL','stage'=>'confirmed',
                'effort_time'=>strtotime('2026-10-22 06:05 UTC'),'effort_low'=>4089.95,'effort_high'=>4097.00,
                'effort_volume'=>1416,'effort_volume_ratio'=>2.77,'effort_delta'=>128,
                'rejection_time'=>strtotime('2026-10-22 06:20 UTC'),'rejection_low'=>4096.85,
                'rejection_high'=>4102.91,'rejection_delta'=>7,'confirmation_time'=>strtotime('2026-10-22 06:25 UTC'),
                'confirmation_delta'=>-194,'reason'=>'Buy-side absorption confirmed from test evidence.']];
    }

    public function test_complete_zone_absorption_sequence_creates_setup_without_weighted_score_gate(): void
    {
        $zone=PriceZone::create(['zone_key'=>'supply-test','source'=>'clusterdelta','instrument'=>'GC',
            'version'=>config('price_zones.version'),'type'=>'SUPPLY','timeframe'=>'1h','low'=>4096,'high'=>4103,
            'atr'=>8,'origin_time'=>strtotime('2026-10-22 03:00 UTC'),
            'confirmed_time'=>strtotime('2026-10-22 06:00 UTC'),'status'=>'active']);
        $metrics=$this->metrics();
        $gate=app(ZoneAbsorptionStrategyService::class)->assess($metrics);
        $this->assertTrue($gate['qualified']);
        $this->assertSame('SELL',$gate['direction']);
        $metrics['zone_retest']=$gate;
        $setup=app(SetupBuilderService::class)->evaluate($metrics);
        $this->assertNotNull($setup);
        $this->assertSame('zone_absorption_v2',$setup->strategy_version);
        $this->assertSame($zone->id,$setup->price_zone_id);
        $this->assertSame('SELL',$setup->direction);
        $this->assertLessThan(60,$setup->score);
    }

    public function test_absorption_away_from_pre_existing_supply_is_blocked(): void
    {
        $gate=app(ZoneAbsorptionStrategyService::class)->assess($this->metrics());
        $this->assertFalse($gate['qualified']);
        $this->assertSame('waiting_for_zone',$gate['stage']);
        $this->assertStringContainsString('No alert',$gate['reason']);
    }
}
