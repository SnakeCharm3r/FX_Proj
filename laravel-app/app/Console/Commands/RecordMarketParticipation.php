<?php

namespace App\Console\Commands;

use App\Services\MarketDataService;
use App\Services\MarketParticipationService;
use App\Services\MarketParticipationSnapshotService;
use Illuminate\Console\Command;

class RecordMarketParticipation extends Command
{
    protected $signature = 'orderflow:record-participation {timeframe? : One configured timeframe, or all available timeframes}';
    protected $description = 'Record GC participation regimes from completed stored bars';

    public function handle(
        MarketDataService $market,
        MarketParticipationService $participation,
        MarketParticipationSnapshotService $snapshots,
    ): int {
        $requested = $this->argument('timeframe');
        $timeframes = $requested ? [$requested] : config('orderflow.timeframes');
        if ($requested && !in_array($requested, config('orderflow.timeframes'), true)) {
            $this->error('Unsupported timeframe.');
            return self::FAILURE;
        }

        foreach ($timeframes as $timeframe) {
            $raw = $market->bars($timeframe, (int) config('orderflow.participation_history_bars', 300));
            $bars = array_values(array_filter(array_map(fn ($value) => json_decode($value, true), $raw), fn ($bar) =>
                is_array($bar) && ($bar['closed'] ?? false) === true
                && isset($bar['time'], $bar['buy_vol'], $bar['sell_vol'], $bar['delta'])
            ));
            $bars = array_reverse($bars);
            $written = 0;
            foreach ($bars as $index => $bar) {
                $p = $participation->analyse(array_slice($bars, 0, $index + 1), $timeframe);
                $record = $snapshots->record([
                    'source' => $bar['source'] ?? config('orderflow.connector'),
                    'instrument' => config('clusterdelta.instrument', 'GC'),
                    'timeframe' => $timeframe, 'bar_time' => $bar['time'], 'closed' => true,
                    'buy_volume' => $bar['buy_vol'], 'sell_volume' => $bar['sell_vol'],
                    'delta' => $bar['delta'], 'participation' => $p,
                ]);
                if ($record) $written++;
            }
            $this->line("{$timeframe}: recorded {$written} completed bars");
        }
        return self::SUCCESS;
    }
}
