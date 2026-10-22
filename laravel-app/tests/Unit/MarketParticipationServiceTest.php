<?php

namespace Tests\Unit;

use App\Services\MarketParticipationService;
use Tests\TestCase;

class MarketParticipationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['orderflow.participation_lookback'=>20,'orderflow.participation_min_samples'=>10,
            'orderflow.participation_increased_ratio'=>1.5,'orderflow.participation_decreased_ratio'=>0.7,
            'orderflow.participation_delta_share'=>0.1,'orderflow.gc_session_timezone'=>'America/New_York',
            'orderflow.gc_session_open'=>'18:00']);
    }

    private function bars(float $latestVolume, float $latestDelta): array
    {
        $start = 1792612800;
        $bars = [];
        for ($i=0; $i<20; $i++) {
            $bars[] = ['time'=>$start+$i*300,'closed'=>true,'buy_vol'=>550,'sell_vol'=>450,'delta'=>100];
        }
        $bars[] = ['time'=>$start+6000,'closed'=>true,
            'buy_vol'=>($latestVolume+$latestDelta)/2,'sell_vol'=>($latestVolume-$latestDelta)/2,'delta'=>$latestDelta];
        return $bars;
    }

    public function test_classifies_participation_separately_from_active_flow(): void
    {
        $service = app(MarketParticipationService::class);
        $increased = $service->analyse($this->bars(1600, 320), '5m');
        $this->assertSame('INCREASED', $increased['state']);
        $this->assertSame('BUY_DOMINANT', $increased['flow']);
        $this->assertEquals(1.6, $increased['ratio']);
        $this->assertEquals(0.2, $increased['delta_share']);

        $decreased = $service->analyse($this->bars(600, -30), '5m');
        $this->assertSame('DECREASED', $decreased['state']);
        $this->assertSame('BALANCED', $decreased['flow']);

        $normal = $service->analyse($this->bars(1000, -200), '5m');
        $this->assertSame('NORMAL', $normal['state']);
        $this->assertSame('SELL_DOMINANT', $normal['flow']);
    }

    public function test_requires_a_minimum_baseline_and_marks_partial_session_coverage(): void
    {
        $service = app(MarketParticipationService::class);
        $result = $service->analyse(array_slice($this->bars(2000, 500), -6), '5m');
        $this->assertSame('UNAVAILABLE', $result['state']);
        $this->assertSame(5, $result['baseline_sample_size']);
        $this->assertSame('PARTIAL', $result['session_coverage']);
    }
}
