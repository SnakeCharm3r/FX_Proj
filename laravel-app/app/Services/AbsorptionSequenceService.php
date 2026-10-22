<?php

namespace App\Services;

class AbsorptionSequenceService
{
    /**
     * Detect completed M5 effort -> rejection -> confirmation sequences.
     * Bars must be chronological. The final bar is the confirmation candidate.
     */
    public function analyse(array $bars, string $timeframe): array
    {
        $base = ['type' => 'NONE', 'direction' => 'NEUTRAL', 'stage' => 'waiting_for_effort',
            'qualified' => false, 'reason' => 'Waiting for a high-participation effort bar that fails to move price.'];
        if ($timeframe !== '5m') {
            return array_merge($base, ['reason' => 'The absorption entry sequence is evaluated on completed M5 bars only.']);
        }

        $bars = array_values(array_filter($bars, fn ($bar) => ($bar['closed'] ?? false) === true));
        if (count($bars) < 13) return array_merge($base, ['reason' => 'Need at least 13 completed M5 bars to measure normal volume and delta.']);

        $confirmation = $bars[array_key_last($bars)];
        $rejectionIndex = count($bars) - 2;
        $rejection = $bars[$rejectionIndex];
        $window = (int) config('orderflow.absorption_effort_lookback', 4);
        $start = max(0, $rejectionIndex - $window);

        foreach (['SELL', 'BUY'] as $direction) {
            if (!$this->isConfirmation($confirmation, $rejection, $direction)) continue;
            for ($index = $rejectionIndex - 1; $index >= $start; $index--) {
                $effort = $bars[$index];
                $context = array_slice($bars, 0, $index + 1);
                $participation = app(MarketParticipationService::class)->analyse($context, $timeframe);
                $deltaBaseline = $this->median(array_map(
                    fn ($bar) => abs((float) ($bar['delta'] ?? 0)),
                    array_slice($bars, max(0, $index - 20), min(20, $index))
                ));
                if (!$this->isEffort($effort, $direction, $participation, $deltaBaseline)) continue;
                if (!$this->isRejectedRetest($effort, $rejection, $direction)) continue;

                $type = $direction === 'BUY' ? 'BULL_ABSORPTION' : 'BEAR_ABSORPTION';
                return [
                    'type' => $type, 'direction' => $direction, 'stage' => 'confirmed', 'qualified' => true,
                    'effort_time' => (int) $effort['time'], 'effort_low' => (float) $effort['low'],
                    'effort_high' => (float) $effort['high'], 'effort_volume' => $this->volume($effort),
                    'effort_volume_ratio' => (float) $participation['ratio'],
                    'effort_delta' => (float) $effort['delta'], 'effort_delta_baseline' => round($deltaBaseline, 2),
                    'rejection_time' => (int) $rejection['time'], 'rejection_low' => (float) $rejection['low'],
                    'rejection_high' => (float) $rejection['high'], 'rejection_delta' => (float) $rejection['delta'],
                    'confirmation_time' => (int) $confirmation['time'],
                    'confirmation_delta' => (float) $confirmation['delta'],
                    'reason' => sprintf(
                        '%s absorption confirmed: effort volume %.0f (%.2fx normal), delta %+.0f failed to hold; retest delta weakened to %+.0f; confirmation broke the rejection with delta %+.0f.',
                        $direction === 'BUY' ? 'Sell-side' : 'Buy-side', $this->volume($effort),
                        $participation['ratio'], $effort['delta'], $rejection['delta'], $confirmation['delta']
                    ),
                ];
            }
        }

        return array_merge($base, ['stage' => 'waiting_for_confirmation',
            'reason' => 'No completed M5 effort, failed retest and opposite-delta break are aligned yet.']);
    }

    private function isEffort(array $bar, string $direction, array $participation, float $deltaBaseline): bool
    {
        if (($participation['state'] ?? '') !== 'INCREASED' || $deltaBaseline <= 0
            || abs((float) ($bar['delta'] ?? 0)) < $deltaBaseline) return false;
        $range = (float) $bar['high'] - (float) $bar['low'];
        if ($range <= 0) return false;
        $closeLocation = ((float) $bar['close'] - (float) $bar['low']) / $range;
        $wickRatio = $direction === 'SELL'
            ? ((float) $bar['high'] - max((float) $bar['open'], (float) $bar['close'])) / $range
            : (min((float) $bar['open'], (float) $bar['close']) - (float) $bar['low']) / $range;
        $failed = $direction === 'SELL' ? $closeLocation <= 0.45 : $closeLocation >= 0.55;
        $aligned = $direction === 'SELL' ? $bar['delta'] > 0 : $bar['delta'] < 0;
        return $failed && $aligned && $wickRatio >= (float) config('orderflow.absorption_effort_wick_ratio', 0.4);
    }

    private function isRejectedRetest(array $effort, array $rejection, string $direction): bool
    {
        $range = (float) $rejection['high'] - (float) $rejection['low'];
        if ($range <= 0) return false;
        $closeLocation = ((float) $rejection['close'] - (float) $rejection['low']) / $range;
        if ($direction === 'SELL') {
            $wick = ((float) $rejection['high'] - max((float) $rejection['open'], (float) $rejection['close'])) / $range;
            return $rejection['high'] >= $effort['high'] && $rejection['close'] < $rejection['open']
                && $closeLocation <= 0.45 && $wick >= .2 && $rejection['delta'] < $effort['delta'];
        }
        $wick = (min((float) $rejection['open'], (float) $rejection['close']) - (float) $rejection['low']) / $range;
        return $rejection['low'] <= $effort['low'] && $rejection['close'] > $rejection['open']
            && $closeLocation >= 0.55 && $wick >= .2 && $rejection['delta'] > $effort['delta'];
    }

    private function isConfirmation(array $bar, array $rejection, string $direction): bool
    {
        return $direction === 'SELL'
            ? $bar['close'] < $rejection['low'] && $bar['delta'] < 0
            : $bar['close'] > $rejection['high'] && $bar['delta'] > 0;
    }

    private function volume(array $bar): float
    {
        return (float) ($bar['buy_vol'] ?? 0) + (float) ($bar['sell_vol'] ?? 0);
    }

    private function median(array $values): float
    {
        $values = array_values(array_filter($values, fn ($value) => $value > 0));
        if (!$values) return 0.0;
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        return count($values) % 2 ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }
}
