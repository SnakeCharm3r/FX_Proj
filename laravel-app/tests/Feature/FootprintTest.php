<?php

namespace Tests\Feature;

use App\Services\FootprintService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class FootprintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['clusterdelta.footprint_utc_offset_minutes' => 0]);
    }

    public function test_explicit_source_clock_offset_aligns_times_and_retains_the_original(): void
    {
        config(['clusterdelta.bridge_token' => 'test-secret', 'clusterdelta.footprint_utc_offset_minutes' => 180]);
        $payload = $this->payload();
        $payload['bars'][0]['time'] = 1700010800;
        Redis::shouldReceive('setex')->once()->with('clusterdelta:footprint:GC:15m:latest', 604800,
            \Mockery::on(function ($json) {
                $data = json_decode($json, true); $bar = $data['bars'][0];
                return $data['source_utc_offset_minutes'] === 180 && $bar['source_time'] === 1700010800
                    && $bar['time'] === 1700000000 && $bar['closed'] === true && $bar['source_closed'] === false;
            }));
        Redis::shouldReceive('transaction')->once();
        $this->withToken('test-secret')->postJson('/api/clusterdelta/footprints', $payload)->assertOk();
    }

    private function payload(): array
    {
        return ['instrument' => 'GC', 'tf' => '15m', 'symbol' => 'XAUUSDm', 'contract' => '', 'tick_size' => 0.1,
            'bars' => [['time' => 1700000000, 'closed' => false,
                'open' => 2000, 'high' => 2000.2, 'low' => 2000, 'close' => 2000.1,
                'ask' => 44, 'bid' => 60, 'reported_ask' => 44, 'reported_bid' => 60,
                'delta_min' => -20, 'delta_max' => 50,
                'levels' => [['price' => 2000, 'ask' => 2, 'bid' => 10],
                    ['price' => 2000.1, 'ask' => 40, 'bid' => 30],
                    ['price' => 2000.2, 'ask' => 2, 'bid' => 20]]]]];
    }

    public function test_authenticated_ingestion_computes_poc_and_native_diagonal_imbalances(): void
    {
        config(['clusterdelta.bridge_token' => 'test-secret']);
        Redis::shouldReceive('setex')->once()->with('clusterdelta:footprint:GC:15m:latest', 604800,
            \Mockery::on(function ($json) {
                $data = json_decode($json, true); $bar = $data['bars'][0];
                return $data['price_source'] === 'GC_futures' && $data['contract'] === null
                    && $data['vendor_expiration'] === '02.11.2026' && $bar['volume'] === 104 && $bar['delta'] === -16
                    && $bar['poc'] === 2000.1 && $bar['closed'] === false && $bar['source_totals_match']
                    && $bar['levels'][1]['ask_imbalance'] && $bar['levels'][1]['bid_imbalance'];
            }));
        Redis::shouldReceive('transaction')->once();
        $payload = $this->payload(); $payload['contract'] = '02.11.2026';
        $this->withToken('test-secret')->postJson('/api/clusterdelta/footprints', $payload)
            ->assertOk()->assertJsonPath('accepted', 1);
    }

    public function test_invalid_or_duplicate_price_cells_and_inconsistent_totals_are_rejected(): void
    {
        config(['clusterdelta.bridge_token' => 'test-secret']);
        Redis::shouldReceive('setex')->never();
        $this->postJson('/api/clusterdelta/footprints', $this->payload())->assertUnauthorized();
        foreach (['negative', 'duplicate', 'off_grid', 'totals'] as $case) {
            $payload = $this->payload();
            if ($case === 'negative') $payload['bars'][0]['levels'][0]['ask'] = -1;
            if ($case === 'duplicate') $payload['bars'][0]['levels'][1]['price'] = 2000;
            if ($case === 'off_grid') $payload['bars'][0]['levels'][1]['price'] = 2000.15;
            if ($case === 'totals') $payload['bars'][0]['ask'] = 45;
            $this->withToken('test-secret')->postJson('/api/clusterdelta/footprints', $payload)->assertUnprocessable();
        }
    }

    public function test_price_cells_are_correlated_to_bar_totals_without_merging_price_sources(): void
    {
        $payload = $this->payload();
        $payload['bars'][0] = app(FootprintService::class)->enrich($payload['bars'][0], 0.1);
        Redis::shouldReceive('get')->once()->with('clusterdelta:footprint:GC:15m:latest')->andReturn(json_encode($payload));
        Redis::shouldReceive('hmget')->once()->with('clusterdelta:GC:15m:values', '1700000000')->andReturn([
            json_encode(['buy_vol' => 45, 'sell_vol' => 55, 'delta' => -10, 'close' => 1999.5]),
        ]);
        $this->getJson('/api/orderflow/footprint?instrument=GC&tf=15m')->assertOk()
            ->assertJsonPath('bars.0.volume', 104)->assertJsonPath('bars.0.poc', 2000.1)
            ->assertJsonPath('bars.0.comparison.volume_difference', 4)
            ->assertJsonPath('bars.0.comparison.delta_difference', -6)
            ->assertJsonPath('bars.0.comparison.price_basis', 0.6);
    }

    public function test_no_export_shows_an_empty_feed_without_generated_cells(): void
    {
        Redis::shouldReceive('get')->once()->andReturn(null);
        $this->getJson('/api/orderflow/footprint?instrument=GC&tf=15m')->assertOk()
            ->assertJsonPath('bars', [])->assertJsonPath('received_at', null);
        $this->get('/orderflow/footprint')->assertOk()->assertSee('GC Footprint');
    }

    public function test_vendor_summary_disagreement_is_retained_for_inspection(): void
    {
        $bar = $this->payload()['bars'][0]; $bar['reported_ask'] = 999;
        $result = app(FootprintService::class)->enrich($bar, 0.1);
        $this->assertFalse($result['source_totals_match']);
        $this->assertSame(104, $result['volume']);
    }
}
