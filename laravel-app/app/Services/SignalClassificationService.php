<?php

namespace App\Services;

class SignalClassificationService
{
    private array $params;

    public function __construct()
    {
        $this->params = $this->loadParameters();
    }

    private function loadParameters(): array
    {
        $path = base_path('../research/opportunity-audit/combined_signal_parameters.json');
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true) ?? [];
        }
        return $this->defaultParameters();
    }

    private function defaultParameters(): array
    {
        return [
            'false_positive_detection' => [
                'buy' => [
                    'delta_threshold' => 8.0,
                    'delta_high_risk' => 15.0,
                    'bid_ask_ratio_threshold' => 0.70,
                    'bid_ask_ratio_high_risk' => 0.75,
                    'prev_delta_threshold' => -10.0,
                    'lookback_bars' => 3,
                ],
                'sell' => [
                    'delta_threshold' => -8.0,
                    'delta_high_risk' => -15.0,
                    'bid_ask_ratio_threshold' => 0.90,
                    'bid_ask_ratio_high_risk' => 0.85,
                    'prev_delta_threshold' => 10.0,
                    'lookback_bars' => 3,
                ]
            ],
            'zone_penetration_detection' => [
                'penetration_depth_threshold' => 50.0,
                'penetration_depth_high_conviction' => 70.0,
                'min_future_change' => 1.0,
                'lookahead_bars' => 5,
            ],
            'combined_signal' => [
                'high_conviction' => [
                    'requires_zone_penetration' => true,
                    'requires_delta_confirmation' => true,
                    'penetration_depth_min' => 70.0,
                ],
                'medium_conviction' => [
                    'requires_zone_penetration' => true,
                    'requires_delta_confirmation' => false,
                    'penetration_depth_min' => 50.0,
                ],
                'avoid_signals' => [
                    'false_positive_delta_only' => true,
                    'delta_threshold' => 10.0,
                ]
            ],
        ];
    }

    /**
     * Detect false positive delta patterns (trap signals)
     */
    public function detectFalsePositive(array $bar, array $prefixBars, string $direction): array
    {
        $delta = $bar['delta'] ?? 0;
        $totalVolume = ($bar['ask'] ?? 0) + ($bar['bid'] ?? 0);
        $deltaPct = $totalVolume > 0 ? ($delta / $totalVolume * 100) : 0;

        $bidAskRatio = ($bar['bid'] ?? 0) > 0 ? ($bar['ask'] ?? 0) / ($bar['bid'] ?? 1) : 1.0;

        // Calculate previous delta trend
        $prevDeltas = [];
        $lookback = $this->params['false_positive_detection'][$direction]['lookback_bars'] ?? 3;
        for ($i = max(0, count($prefixBars) - $lookback); $i < count($prefixBars); $i++) {
            $prevBar = $prefixBars[$i];
            $prevTotal = ($prevBar['ask'] ?? 0) + ($prevBar['bid'] ?? 0);
            if ($prevTotal > 0) {
                $prevDeltas[] = ($prevBar['delta'] ?? 0) / $prevTotal * 100;
            }
        }
        $avgPrevDelta = count($prevDeltas) > 0 ? array_sum($prevDeltas) / count($prevDeltas) : 0;

        $params = $this->params['false_positive_detection'][$direction] ?? [];

        $isFalsePositive = false;
        $riskLevel = 'none';

        if ($direction === 'BUY') {
            // Check for buy false positive (positive delta but price falls)
            if ($deltaPct > $params['delta_threshold']) {
                if ($bidAskRatio > $params['bid_ask_ratio_threshold']) {
                    if ($avgPrevDelta < $params['prev_delta_threshold']) {
                        $isFalsePositive = true;
                        $riskLevel = $deltaPct > $params['delta_high_risk'] ? 'high' : 'medium';
                    }
                }
            }
        } else {
            // Check for sell false positive (negative delta but price rises)
            if ($deltaPct < $params['delta_threshold']) {
                if ($bidAskRatio < $params['bid_ask_ratio_threshold']) {
                    if ($avgPrevDelta > $params['prev_delta_threshold']) {
                        $isFalsePositive = true;
                        $riskLevel = abs($deltaPct) > abs($params['delta_high_risk']) ? 'high' : 'medium';
                    }
                }
            }
        }

        return [
            'is_false_positive' => $isFalsePositive,
            'risk_level' => $riskLevel,
            'delta_pct' => $deltaPct,
            'bid_ask_ratio' => $bidAskRatio,
            'avg_prev_delta' => $avgPrevDelta,
            'prev_delta_threshold' => $params['prev_delta_threshold'] ?? 0,
        ];
    }

    /**
     * Detect zone penetration patterns (liquidity grab signals)
     */
    public function detectZonePenetration(array $bar, array $zones, string $direction): array
    {
        $penetration = [
            'detected' => false,
            'zone_type' => null,
            'penetration_depth_pct' => 0,
            'zone_low' => null,
            'zone_high' => null,
            'confidence' => 'none',
        ];

        $barLow = $bar['low'] ?? $bar['close'];
        $barHigh = $bar['high'] ?? $bar['close'];

        foreach ($zones as $zone) {
            $zoneType = $zone['type'] ?? '';
            $zoneLow = $zone['low'] ?? 0;
            $zoneHigh = $zone['high'] ?? 0;
            $zoneDepth = $zoneHigh - $zoneLow;

            if ($zoneDepth <= 0) continue;

            $penetrated = false;
            $penetrationDepth = 0;

            if ($zoneType === 'DEMAND') {
                // Price pushing down into demand zone
                if ($barLow < $zoneHigh && $barLow >= $zoneLow) {
                    $penetrated = true;
                    $penetrationDepth = (($zoneHigh - $barLow) / $zoneDepth) * 100;
                }
            } elseif ($zoneType === 'SUPPLY') {
                // Price pushing up into supply zone
                if ($barHigh > $zoneLow && $barHigh <= $zoneHigh) {
                    $penetrated = true;
                    $penetrationDepth = (($barHigh - $zoneLow) / $zoneDepth) * 100;
                }
            }

            if ($penetrated && $penetrationDepth > ($this->params['zone_penetration_detection']['penetration_depth_threshold'] ?? 50)) {
                $penetration['detected'] = true;
                $penetration['zone_type'] = $zoneType;
                $penetration['penetration_depth_pct'] = $penetrationDepth;
                $penetration['zone_low'] = $zoneLow;
                $penetration['zone_high'] = $zoneHigh;

                $highConvictionThreshold = $this->params['zone_penetration_detection']['penetration_depth_high_conviction'] ?? 70;
                $penetration['confidence'] = $penetrationDepth >= $highConvictionThreshold ? 'high' : 'medium';

                // Stop at first significant penetration
                break;
            }
        }

        return $penetration;
    }

    /**
     * Classify signal based on combined analysis
     */
    public function classifySignal(array $bar, array $prefixBars, array $zones, string $direction): array
    {
        $falsePositive = $this->detectFalsePositive($bar, $prefixBars, $direction);
        $zonePenetration = $this->detectZonePenetration($bar, $zones, $direction);

        $signalType = 'STANDARD';
        $confidence = 'BASELINE';
        $emoji = 'ℹ️';
        $message = 'Standard opportunity';

        $avoidParams = $this->params['combined_signal']['avoid_signals'] ?? [];

        // Check for false positive delta only (avoid signal)
        if ($avoidParams['false_positive_delta_only'] ?? false) {
            if ($falsePositive['is_false_positive'] && !$zonePenetration['detected']) {
                if (abs($falsePositive['delta_pct']) > ($avoidParams['delta_threshold'] ?? 10)) {
                    $signalType = 'AVOID';
                    $confidence = 'TRAP';
                    $emoji = '🚫';
                    $message = 'False positive delta trap - avoid';
                }
            }
        }

        // Check for high conviction reversal (zone + delta alignment)
        $highConviction = $this->params['combined_signal']['high_conviction'] ?? [];
        if ($zonePenetration['detected'] && $zonePenetration['confidence'] === 'high') {
            if ($highConviction['requires_delta_confirmation'] ?? false) {
                // Check if delta is opposite to penetration direction
                $deltaOpposite = false;
                if ($zonePenetration['zone_type'] === 'DEMAND' && $direction === 'BUY') {
                    // Price pushed down into demand, delta should be negative at bottom
                    $deltaOpposite = ($bar['delta'] ?? 0) < 0;
                } elseif ($zonePenetration['zone_type'] === 'SUPPLY' && $direction === 'SELL') {
                    // Price pushed up into supply, delta should be positive at top
                    $deltaOpposite = ($bar['delta'] ?? 0) > 0;
                }

                if ($deltaOpposite) {
                    $signalType = 'HIGH_CONVICTION';
                    $confidence = 'HIGH';
                    $emoji = '🔥';
                    $message = 'High conviction reversal - zone penetration with delta alignment';
                }
            }
        }

        // Check for medium conviction reversal (zone only)
        $mediumConviction = $this->params['combined_signal']['medium_conviction'] ?? [];
        if ($signalType === 'STANDARD' && $zonePenetration['detected']) {
            if ($zonePenetration['penetration_depth_pct'] >= ($mediumConviction['penetration_depth_min'] ?? 50)) {
                $signalType = 'MEDIUM_CONVICTION';
                $confidence = 'MEDIUM';
                $emoji = '⚠️';
                $message = 'Medium conviction reversal - zone penetration';
            }
        }

        return [
            'signal_type' => $signalType,
            'confidence' => $confidence,
            'emoji' => $emoji,
            'message' => $message,
            'false_positive' => $falsePositive,
            'zone_penetration' => $zonePenetration,
        ];
    }

    /**
     * Get formatted alert message with signal classification
     */
    public function formatAlertMessage(array $classification, array $candidate): string
    {
        $emoji = $classification['emoji'];
        $signalType = $classification['signal_type'];
        $message = $classification['message'];

        $additionalInfo = '';

        if ($classification['zone_penetration']['detected']) {
            $zp = $classification['zone_penetration'];
            $additionalInfo .= sprintf("\n📍 Zone %s: %.2f-%.2f (penetration: %.1f%%)",
                strtolower($zp['zone_type'] ?? ''),
                $zp['zone_low'] ?? 0,
                $zp['zone_high'] ?? 0,
                $zp['penetration_depth_pct'] ?? 0
            );
        }

        if ($classification['false_positive']['is_false_positive']) {
            $fp = $classification['false_positive'];
            $additionalInfo .= sprintf("\n⚠️ False positive risk: %s (delta: %.1f%%, bid/ask: %.2f)",
                ucfirst($fp['risk_level']),
                $fp['delta_pct'] ?? 0,
                $fp['bid_ask_ratio'] ?? 0
            );
        }

        return sprintf("%s %s\n%s%s",
            $emoji,
            $signalType,
            $message,
            $additionalInfo
        );
    }
}
