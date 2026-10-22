<?php

namespace Tests\Feature;

use App\Models\Setup;
use App\Services\AlertDispatchService;
use App\Services\H1TrendRangeStrategyService;
use App\Services\H1TrendService;
use App\Services\SetupBuilderService;
use App\Services\ZoneAbsorptionStrategyService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class H1TrendRangeStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.external_alerts_enabled'=>false,'orderflow.live_max_stop_points'=>5.0]);
        DB::purge('sqlite');Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
        $alerter=\Mockery::mock(AlertDispatchService::class);$alerter->shouldNotReceive('dispatch');
        $this->app->instance(AlertDispatchService::class,$alerter);
    }

    public function test_h1_trend_uses_only_completed_structure(): void
    {
        $bars=[];$start=1_000_000;
        for($i=0;$i<20;$i++)$bars[]=['time'=>$start+$i*3600,'open'=>100+$i,'high'=>102+$i,
            'low'=>99+$i,'close'=>101+$i,'closed'=>true];
        $trend=app(H1TrendService::class)->assess($start+20*3600,$bars);
        $this->assertTrue($trend['qualified']);$this->assertSame('BUY',$trend['direction']);
    }

    public function test_continuation_requires_cvd_footprint_and_capital_gate_then_saves_setup(): void
    {
        DB::table('price_zones')->insert(['id'=>7,'zone_key'=>'trend-range-test','source'=>'clusterdelta',
            'instrument'=>'GC','version'=>'test','type'=>'DEMAND','timeframe'=>'1h','low'=>98.5,'high'=>101,
            'atr'=>2,'origin_time'=>1,'confirmed_time'=>2,'status'=>'active','entry_count'=>0,
            'created_at'=>now(),'updated_at'=>now()]);
        $service=$this->service('BUY','BUY');$metrics=$this->metrics();
        $gate=$service->assess($metrics);
        $this->assertTrue($gate['qualified']);$this->assertSame('CONTINUATION',$gate['mode']);
        $this->assertSame(100,$gate['rule_score']);$this->assertCount(5,$gate['signals']);
        $metrics['zone_retest']=$gate;
        $setup=app(SetupBuilderService::class)->evaluate($metrics);
        $this->assertInstanceOf(Setup::class,$setup);$this->assertSame('h1_trend_range_v3',$setup->strategy_version);
        $this->assertSame(100,$setup->score);$this->assertCount(5,$setup->confluence_detail);
    }

    public function test_countertrend_reversal_requires_matching_cvd_divergence(): void
    {
        $metrics=$this->metrics(['cvd'=>-200,'cvd_divergence'=>'NONE','stacked_imbalance'=>'BEARISH',
            'open'=>101,'high'=>102,'low'=>99,'close'=>100,'price'=>100]);
        $service=$this->service('BUY','SELL');
        $waiting=$service->assess($metrics);
        $this->assertFalse($waiting['qualified']);$this->assertSame('REVERSAL',$waiting['mode']);
        $this->assertSame('waiting_for_orderflow_confluence',$waiting['stage']);
        $confirmed=$service->assess(array_merge($metrics,['cvd_divergence'=>'BEARISH_DIVERGENCE']));
        $this->assertTrue($confirmed['qualified']);$this->assertSame('SELL',$confirmed['direction']);
    }

    public function test_wide_stop_is_blocked_after_all_evidence_aligns(): void
    {
        $gate=$this->service('BUY','BUY',90)->assess($this->metrics());
        $this->assertFalse($gate['qualified']);$this->assertSame('risk_too_wide',$gate['stage']);
        $this->assertStringContainsString('No alert',$gate['reason']);
    }

    public function test_waiting_state_exposes_all_five_live_criteria(): void
    {
        $trend=\Mockery::mock(H1TrendService::class);
        $trend->shouldReceive('assess')->andReturn(['qualified'=>true,'direction'=>'SELL',
            'reason'=>'Test H1 sell trend.']);
        $zone=\Mockery::mock(ZoneAbsorptionStrategyService::class);
        $zone->shouldReceive('assess')->andReturn(['qualified'=>false,'direction'=>'NEUTRAL',
            'stage'=>'waiting_for_confirmation','reason'=>'Waiting for completed absorption.']);
        $gate=(new H1TrendRangeStrategyService($zone,$trend))->assess($this->metrics());

        $this->assertFalse($gate['qualified']);
        $this->assertSame(20,$gate['rule_score']);
        $this->assertCount(5,$gate['signals']);
        $this->assertSame('bearish',$gate['signals'][0]['status']);
        $this->assertSame('neutral',$gate['signals'][1]['status']);
    }

    private function service(string $trendDirection,string $zoneDirection,float $zoneLow=98.5): H1TrendRangeStrategyService
    {
        $trend=\Mockery::mock(H1TrendService::class);
        $trend->shouldReceive('assess')->andReturn(['qualified'=>true,'direction'=>$trendDirection,
            'reason'=>'Test H1 trend.']);
        $zone=\Mockery::mock(ZoneAbsorptionStrategyService::class);
        $zone->shouldReceive('assess')->andReturn(['qualified'=>true,'strategy_version'=>'zone_absorption_v2',
            'direction'=>$zoneDirection,'stage'=>'confirmed','price_zone_id'=>7,'zone_low'=>$zoneLow,
            'zone_high'=>101.0,'rejection_time'=>900,'rejection_low'=>$zoneLow,'rejection_high'=>101.5,
            'reason'=>'Confirmed range absorption.']);
        return new H1TrendRangeStrategyService($zone,$trend);
    }

    private function metrics(array $overrides=[]): array
    {
        return array_merge(['source'=>'clusterdelta','instrument'=>'GC','timeframe'=>'5m','bar_time'=>1200,
            'price'=>100.0,'open'=>99.0,'high'=>101.0,'low'=>98.0,'close'=>100.0,'delta'=>100,
            'buy_volume'=>200,'sell_volume'=>100,'cvd'=>300,'cvd_divergence'=>'NONE',
            'stacked_imbalance'=>'BULLISH','poc_rejection'=>'NONE','absorption'=>'BULL_ABSORPTION'], $overrides);
    }
}
