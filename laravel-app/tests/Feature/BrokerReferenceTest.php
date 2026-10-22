<?php

namespace Tests\Feature;

use App\Services\BrokerReferenceService;
use App\Services\MarketDataService;
use App\Services\OrderflowMetricsService;
use Tests\TestCase;

class BrokerReferenceTest extends TestCase
{
    private function bar(int $index, float $price, int $volume): array
    {
        return ['time'=>1700000000+$index*900,'open'=>$price,'high'=>$price,'low'=>$price,'close'=>$price,
            'buy_vol'=>$volume,'sell_vol'=>0,'delta'=>$volume,'closed'=>false,'received_at'=>now()->timestamp];
    }

    public function test_half_point_price_buckets_do_not_truncate_and_zero_volume_cannot_create_a_poc(): void
    {
        $service=app(BrokerReferenceService::class);
        $result=$service->summarize([$this->bar(0,2000.5,100),$this->bar(1,2000,90)]);
        $this->assertEquals(2000.5,$result['poc']);
        $this->assertEquals(2000.26,$result['vwap']);
        $this->assertSame(['vwap'=>null,'poc'=>null],$service->summarize([$this->bar(0,9999,0)]));
    }

    public function test_old_high_volume_candles_drop_out_of_the_rolling_window(): void
    {
        $bars=[$this->bar(0,100,1000000)];
        for($i=1;$i<=20;$i++) $bars[]=$this->bar($i,200.5,10);
        $series=app(BrokerReferenceService::class)->series($bars);
        $this->assertSame(21,count($series['vwap']));
        $this->assertEquals(200.5,$series['latest']['vwap']);
        $this->assertEquals(200.5,$series['latest']['poc']);
        $this->assertEquals(100,$series['poc'][19]['value']);
        $this->assertEquals(200.5,$series['poc'][20]['value']);
    }

    public function test_developing_candle_updates_change_references_without_rewriting_prior_points(): void
    {
        $service=app(BrokerReferenceService::class);
        $bars=[$this->bar(0,100,100),$this->bar(1,110.5,10)];
        $before=$service->series($bars);
        $bars[1]=$this->bar(1,110.5,200);
        $after=$service->series($bars);
        $this->assertEquals(100,$before['latest']['poc']);
        $this->assertEquals(110.5,$after['latest']['poc']);
        $this->assertGreaterThan($before['latest']['vwap'],$after['latest']['vwap']);
        $this->assertSame($before['vwap'][0],$after['vwap'][0]);
        $this->assertSame($before['poc'][0],$after['poc'][0]);
    }

    public function test_poc_can_stay_stable_while_vwap_reacts_to_new_volume(): void
    {
        $service=app(BrokerReferenceService::class);
        $bars=[$this->bar(0,100,1000),$this->bar(1,110.5,10)];
        $before=$service->summarize($bars);$bars[1]['buy_vol']=20;
        $after=$service->summarize($bars);
        $this->assertSame($before['poc'],$after['poc']);
        $this->assertGreaterThan($before['vwap'],$after['vwap']);
    }

    public function test_chart_and_live_metric_card_use_identical_windows_and_price_precision(): void
    {
        $bars=[$this->bar(0,100,1000000)];
        for($i=1;$i<=20;$i++) $bars[]=$this->bar($i,200.5,10);
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->andReturnUsing(fn($tf,$limit)=>array_slice(array_map('json_encode',array_reverse($bars)),0,$limit));
        $this->app->instance(MarketDataService::class,$market);
        // The route only reads saved markers; use an isolated database for that query.
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:']);
        \Illuminate\Support\Facades\DB::purge('sqlite');
        \Illuminate\Support\Facades\Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
        $response=$this->getJson('/api/chart-data?tf=15m')->assertOk();
        $metrics=app(OrderflowMetricsService::class)->compute('15m');
        $this->assertEquals($metrics['vwap'],$response->json('stats.vwap_price'));
        $this->assertEquals($metrics['poc'],$response->json('stats.poc_price'));
        $this->assertEquals($metrics['vwap'],array_slice($response->json('vwap'),-1)[0]['value']);
        $response->assertJsonPath('stats.reference_lookback',20)
            ->assertJsonPath('stats.reference_window_bars',20)
            ->assertJsonPath('stats.poc_method','rolling_close_volume_proxy');
    }
}
