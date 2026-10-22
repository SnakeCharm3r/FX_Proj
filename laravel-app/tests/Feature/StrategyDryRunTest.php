<?php

namespace Tests\Feature;

use App\Services\StrategyDryRunService;
use Tests\TestCase;

class StrategyDryRunTest extends TestCase
{
    public function test_replay_requires_a_pre_existing_zone_and_resolves_a_tp2_trade(): void
    {
        $bars = [];
        for ($i = 0; $i < 13; $i++) {
            $bars[] = $this->bar($i, 100, 105, 95, 101, 100, 10);
        }
        $bars[] = $this->bar(13, 100, 110, 90, 95, 200, 30); // high-volume buy effort absorbed by sellers
        $bars[] = $this->bar(14, 105, 112, 95, 98, 120, 10); // failed retest
        $bars[] = $this->bar(15, 98, 99, 90, 92, 100, -20); // bearish confirmation
        $bars[] = $this->bar(16, 92, 94, 88, 90, 100, -10); // next-open entry bar
        $bars[] = $this->bar(17, 94, 95, 20, 70, 100, -10); // TP2
        $footprints = array_map(fn (array $bar) => [
            'time' => $bar['time'], 'volume' => $bar['volume'], 'delta' => $bar['delta'],
        ], $bars);

        $tester = app(StrategyDryRunService::class);
        $withoutZone = $tester->analyse($bars, $footprints, [], [], 0, 18 * 300);
        $this->assertSame(0, $withoutZone['summary']['setups']);
        $this->assertSame(1, $withoutZone['summary']['blocked_sequences']);

        $zone = [[
            'type' => 'SUPPLY', 'timeframe' => '1h', 'low' => 95, 'high' => 105,
            'origin_time' => 0, 'confirmed_time' => 0,
        ]];
        $report = $tester->analyse($bars, $footprints, [], $zone, 0, 18 * 300);
        $this->assertSame(1, $report['summary']['setups']);
        $this->assertSame(1, $report['summary']['worked']);
        $this->assertSame('WORKED_TP2', $report['setups'][0]['status']);
        $this->assertSame(100, $report['setups'][0]['rule_match']);
        $this->assertNotEmpty($report['potentialMarkers']);
        $this->assertSame(
            count($report['potentials']),
            $report['potentialSummary']['buy'] + $report['potentialSummary']['sell']
        );
        $confirmed=array_values(array_filter($report['potentials'],fn($row)=>$row['sequence']==='SELL'));
        $this->assertSame(100,$confirmed[0]['rule_completion']);
        $notableTimes=array_column($report['notableFindings'],'time');
        $this->assertEmpty(array_diff(array_column($report['potentialMarkers'],'time'),$notableTimes));
    }

    private function bar(int $index, float $open, float $high, float $low, float $close, int $volume, int $delta): array
    {
        return [
            'time' => $index * 300, 'open' => $open, 'high' => $high, 'low' => $low,
            'close' => $close, 'volume' => $volume, 'delta' => $delta,
            'buy_vol' => ($volume + $delta) / 2, 'sell_vol' => ($volume - $delta) / 2,
            'closed' => true,
        ];
    }
}
