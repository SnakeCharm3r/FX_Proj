<?php
namespace Tests\Feature;

use App\Services\MarketDataService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClusterDeltaMarketDataTest extends TestCase
{
    public function test_latest_quote_uses_a_fresh_native_broker_close_across_timeframes(): void
    {
        config(['orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 12:10:00','UTC'));
        $market=\Mockery::mock(MarketDataService::class)->makePartial();
        $market->shouldReceive('storedBars')->andReturnUsing(function ($tf) {
            if (!in_array($tf,['15m','1h'])) return [];
            return [json_encode(['time'=>intdiv(now()->timestamp,3600)*3600,'closed'=>false,
                'source'=>'clusterdelta','price_source'=>'mt5_broker','close'=>4137.73,
                'received_at'=>now()->timestamp-($tf==='1h'?2:120)])];
        });
        $quote=$market->latestBrokerQuote();
        $this->assertSame('1h',$quote['timeframe']);
        $this->assertSame('GC',$quote['instrument']);
        $this->assertSame(4137.73,$quote['close']);
    }

    public function test_latest_quote_excludes_closed_stale_future_and_futures_price_samples(): void
    {
        config(['orderflow.connector'=>'clusterdelta']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 12:10:00','UTC'));
        $base=['time'=>intdiv(now()->timestamp,3600)*3600,'closed'=>false,'source'=>'clusterdelta',
            'price_source'=>'mt5_broker','close'=>4137.73,'received_at'=>now()->timestamp];
        foreach ([['closed'=>true],['received_at'=>now()->timestamp-60],['received_at'=>now()->timestamp+60],
            ['price_source'=>'GC_futures'],['source'=>'ctrader'],['time'=>now()->timestamp+60],['close'=>0]] as $change) {
            $market=\Mockery::mock(MarketDataService::class)->makePartial();
            $market->shouldReceive('storedBars')->andReturnUsing(fn ($tf)=>$tf==='1h'?[json_encode(array_merge($base,$change))]:[]);
            $this->assertNull($market->latestBrokerQuote());
        }
    }

    public function test_feed_follows_latest_export_and_honors_manual_timeframe_selection(): void
    {
        config(['orderflow.connector' => 'clusterdelta']);
        $market = \Mockery::mock(MarketDataService::class)->makePartial();
        $market->shouldReceive('bars')->andReturnUsing(function ($tf) {
            if (!in_array($tf, ['15m', '1h'])) return [];
            return [json_encode(['time' => 1700000000, 'closed' => false,
                'received_at' => now()->timestamp - ($tf === '1h' ? 1 : 120),
                'open' => 2000, 'high' => 2002, 'low' => 1999, 'close' => 2001,
                'buy_vol' => 7, 'sell_vol' => 3, 'delta' => 4])];
        });
        $this->app->instance(MarketDataService::class, $market);
        $this->assertSame('1h', $market->activeTimeframe());
        $this->getJson('/api/feed-status')->assertOk()
            ->assertJsonPath('timeframe', '1h')->assertJsonPath('connected', true)
            ->assertJsonPath('metrics.timeframe', '1h')->assertJsonPath('metrics.delta', 4);
        $this->getJson('/api/feed-status?tf=15m')->assertOk()
            ->assertJsonPath('timeframe', '15m')->assertJsonPath('connected', false)
            ->assertJsonPath('metrics.timeframe', '15m');
        $this->getJson('/api/feed-status?tf=invalid')->assertUnprocessable();
    }

    public function test_missing_feed_is_not_reported_as_zero_market_metrics(): void
    {
        config(['orderflow.connector' => 'clusterdelta', 'clusterdelta.instrument' => 'GC']);
        Redis::shouldReceive('zrevrange')->once()->with('clusterdelta:GC:15m:times', 0, 0)->andReturn([]);
        $this->getJson('/api/feed-status?tf=15m')->assertOk()
            ->assertJsonPath('has_data', false)->assertJsonPath('connected', false)
            ->assertJsonPath('status', 'waiting_for_first_bar')
            ->assertJsonPath('metrics', null)->assertJsonPath('last_received', null);
    }

    public function test_charts_use_cluster_data_and_never_fall_back_to_ctrader(): void
    {
        config(['orderflow.connector' => 'clusterdelta', 'clusterdelta.instrument' => 'GC']);
        Redis::shouldReceive('lrange')->never();
        Redis::shouldReceive('zrevrange')->once()->with('clusterdelta:GC:15m:times', 0, 199)->andReturn([]);
        $this->getJson('/api/chart-data?tf=15m')->assertOk()->assertJsonPath('candles', []);
    }

    public function test_candles_and_true_delta_are_read_in_timestamp_order(): void
    {
        config(['orderflow.connector' => 'clusterdelta', 'clusterdelta.instrument' => 'GC']);
        $bar = ['time' => 1700000000, 'open' => 2000, 'high' => 2002, 'low' => 1999,
            'close' => 2001, 'volume' => 10, 'delta' => 4, 'buy_vol' => 7, 'sell_vol' => 3];
        Redis::shouldReceive('zrevrange')->once()->with('clusterdelta:GC:15m:times', 0, 99)->andReturn(['1700000000']);
        Redis::shouldReceive('hmget')->once()->with('clusterdelta:GC:15m:values', '1700000000')->andReturn([json_encode($bar)]);
        $this->getJson('/api/orderflow/delta?tf=15m')->assertOk()
            ->assertJsonPath('delta.0.delta', 4)->assertJsonPath('cvd.0.value', 4);
    }

    public function test_legacy_ingestion_is_disabled(): void
    {
        config(['orderflow.connector' => 'clusterdelta']);
        foreach (['ticks', 'bars', 'metrics'] as $path) {
            $this->postJson('/api/'.$path, [])->assertStatus(409);
        }
    }
}
