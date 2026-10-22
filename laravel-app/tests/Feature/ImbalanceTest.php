<?php

namespace Tests\Feature;

use App\Services\FootprintService;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ImbalanceTest extends TestCase
{
    private function snapshot(): array
    {
        $bar = ['time' => intdiv(now()->timestamp, 900) * 900 - 900, 'closed' => true,
            'open' => 2000.1, 'high' => 2000.3, 'low' => 2000, 'close' => 2000.2,
            'levels' => [['price' => 2000, 'ask' => 1, 'bid' => 1],
                ['price' => 2000.1, 'ask' => 30, 'bid' => 1],
                ['price' => 2000.2, 'ask' => 30, 'bid' => 1],
                ['price' => 2000.3, 'ask' => 30, 'bid' => 1]]];
        return ['instrument' => 'GC', 'tf' => '15m', 'received_at' => now()->timestamp,
            'tick_size' => 0.1, 'bars' => [app(FootprintService::class)->enrich($bar, 0.1)]];
    }

    public function test_observed_stacks_use_native_gc_prices_and_count_levels_in_one_candle(): void
    {
        config(['clusterdelta.instrument' => 'GC']);
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldNotReceive('bars');
        $this->app->instance(MarketDataService::class, $market);
        $snapshot = $this->snapshot();
        Redis::shouldReceive('get')->once()->with('clusterdelta:footprint:GC:15m:latest')->andReturn(json_encode($snapshot));
        $this->getJson('/api/orderflow/imbalance?tf=15m')->assertOk()
            ->assertJsonPath('status', 'receiving')->assertJsonPath('price_source', 'GC_futures')
            ->assertJsonPath('candles.0.close', 2000.2)->assertJsonCount(1, 'candles')->assertJsonCount(1, 'zones')
            ->assertJsonPath('zones.0.low', 2000.1)->assertJsonPath('zones.0.high', 2000.3)
            ->assertJsonPath('zones.0.count', 3)->assertJsonPath('zones.0.stacked', true)
            ->assertJsonPath('zones.0.closed', true)->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_no_export_returns_an_explicit_waiting_state_without_fake_candles_or_zones(): void
    {
        Redis::shouldReceive('get')->once()->andReturn(null);
        $this->getJson('/api/orderflow/imbalance?tf=5m')->assertOk()
            ->assertJsonPath('status', 'waiting_for_footprint')->assertJsonPath('candles', [])->assertJsonPath('zones', []);
        $this->getJson('/api/orderflow/imbalance?tf=invalid')->assertUnprocessable();
        $this->get('/orderflow/imbalance')->assertOk()->assertSee('Observed ranges');
    }

    public function test_stale_cells_are_marked_stale_and_inconsistent_candles_cannot_supply_zones(): void
    {
        $snapshot = $this->snapshot(); $snapshot['received_at'] -= 120;
        Redis::shouldReceive('get')->once()->andReturn(json_encode($snapshot));
        $this->getJson('/api/orderflow/imbalance?tf=15m')->assertOk()->assertJsonPath('status', 'stale')->assertJsonCount(1, 'zones');
        $snapshot['received_at'] = now()->timestamp; $snapshot['bars'][0]['source_totals_match'] = false;
        Redis::shouldReceive('get')->once()->andReturn(json_encode($snapshot));
        $this->getJson('/api/orderflow/imbalance?tf=15m')->assertOk()->assertJsonPath('status', 'totals_mismatch')->assertJsonPath('zones', []);
    }

    public function test_one_sided_zero_volume_does_not_create_diagonal_imbalances(): void
    {
        $bar = ['levels' => [['price' => 2000, 'ask' => 0, 'bid' => 0],
            ['price' => 2000.1, 'ask' => 100, 'bid' => 0]]];
        $this->assertSame([], app(FootprintService::class)->imbalanceRuns($bar, 0.1));
    }
}
