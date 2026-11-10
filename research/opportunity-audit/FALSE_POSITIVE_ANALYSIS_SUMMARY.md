# False Positive Analysis - Delta vs Price Movement

## Executive Summary

Analysis of MT5 and footprint data from the last week (Oct 25-30, 2026) revealed significant patterns in false positives - situations where large delta values did not align with subsequent price movement.

**Key Finding:** 364 false positives identified over 6 days, with 208 buy false positives (heavy buying, price fell) and 156 sell false positives (heavy selling, price rose).

## Friday Analysis (Oct 30, 2026)

Friday showed the highest concentration of false positives:
- **92 false positive buys** (heavy buying with falling price)
- Peak hour: 14:00 with 10 occurrences
- Average delta: 9.04%
- Average future price change: -2.84 points (5 bars)

### Critical Pattern Identified

**The Trap:** Heavy buying (positive delta) AFTER selling pressure (negative delta) in the preceding 3 bars.

**Contextual Analysis:**
- Average delta in 3 bars BEFORE false positive buy: **-16.46** (strong selling pressure)
- Price trend before false positive: **DOWN**
- Average volume before: 511.71

This pattern suggests **absorption or stop hunts** - large market participants absorbing sell orders, creating the illusion of buying pressure while the actual trend remains bearish.

## False Positive Parameters

### Buy False Positives (Heavy Buying, Price Fell)

**Delta Thresholds:**
- Min: 0.41%
- Max: 38.97%
- Average: 9.92%
- Median: 7.60%
- **Recommended threshold: > 8.0%**

**Bid/Ask Ratio Pattern:**
- Average: 0.827
- Min: 0.439
- Max: 0.992
- **Recommended check: If bid/ask ratio > 0.70 despite positive delta, flag as potential false positive**

**Volume Context:**
- Average: 450
- Min: 80
- Max: 2,159
- **High volume (>400) with positive delta increases false positive risk**

### Sell False Positives (Heavy Selling, Price Rose)

**Delta Thresholds:**
- Min: -37.31%
- Max: -0.39%
- Average: -9.62%
- Median: -8.75%
- **Recommended threshold: < -8.0%**

**Bid/Ask Ratio Pattern:**
- Average: 1.230
- Min: 1.008
- Max: 2.190
- **Recommended check: If bid/ask ratio < 0.90 despite negative delta, flag as potential false positive**

## Detection Logic

### For BUY Signals (Positive Delta)

**FLAG as potential false positive if:**
1. Delta > 8.0% AND
2. Bid/Ask ratio > 0.70 (indicates absorption) AND
3. Previous 3 bars show negative delta (selling pressure) OR
4. Previous 3 bars show downward price trend

**HIGH RISK if:**
- Delta > 15.0% AND
- Bid/Ask ratio > 0.75 AND
- Previous 3 bars had average delta < -10.0

### For SELL Signals (Negative Delta)

**FLAG as potential false positive if:**
1. Delta < -8.0% AND
2. Bid/Ask ratio < 0.90 (indicates absorption) AND
3. Previous 3 bars show positive delta (buying pressure) OR
4. Previous 3 bars show upward price trend

**HIGH RISK if:**
- Delta < -15.0% AND
- Bid/Ask ratio < 0.85 AND
- Previous 3 bars had average delta > 10.0

## Additional Contextual Clues

### 1. Footprint Level Imbalance
- Check if levels show extreme imbalance (>70% one-sided)
- High imbalance with positive delta but no price move = absorption

### 2. Market Participation
- Check if participation is DECREASING despite delta
- Decreasing participation + high delta = trap

### 3. Zone Context
- Check if signal is at a demand/supply zone
- Signal away from zone = higher false positive risk

### 4. Absorption Sequence
- Check if absorption sequence is qualified
- If NOT qualified but delta is high = potential false positive

## Time and Price Context

**Peak Hours for False Positives:**
- 14:00 (10 occurrences on Friday)
- 23:00
- 01:00
- 02:00

**Common Price Levels:**
- 4000 (15 occurrences)
- 4020 (12 occurrences)
- 4010 (10 occurrences)
- 4015 (8 occurrences)
- 3975 (8 occurrences)

## Implementation Parameters

```json
{
  "buy_false_positive": {
    "delta_threshold": 8.0,
    "delta_high_risk": 15.0,
    "bid_ask_ratio_threshold": 0.7,
    "bid_ask_ratio_high_risk": 0.75,
    "prev_delta_threshold": -10.0,
    "lookback_bars": 3
  },
  "sell_false_positive": {
    "delta_threshold": -8.0,
    "delta_high_risk": -15.0,
    "bid_ask_ratio_threshold": 0.9,
    "bid_ask_ratio_high_risk": 0.85,
    "prev_delta_threshold": 10.0,
    "lookback_bars": 3
  },
  "time_context": {
    "peak_hours": [14, 23, 1, 2]
  },
  "price_context": {
    "common_levels": [4000, 4020, 4010, 4015, 3975]
  }
}
```

## Next Steps

1. ✅ Analyze historical data for patterns
2. ✅ Define detection parameters
3. ⏳ Implement false positive detection in VariableOpportunityService
4. ⏳ Add false positive flag to opportunity alerts
5. ⏳ Create warning system for high-risk delta signals
6. ⏳ Backtest parameters on historical data
7. ⏳ Monitor live signals and validate parameters

## Files Generated

- `/var/www/FX_Proj/analyze_false_positives.py` - Main analysis script
- `/var/www/FX_Proj/analyze_friday_detailed.py` - Friday detailed analysis
- `/var/www/FX_Proj/define_false_positive_parameters.py` - Parameter definition
- `/var/www/FX_Proj/research/opportunity-audit/false_positive_analysis.json` - Detailed results
- `/var/www/FX_Proj/research/opportunity-audit/false_positive_parameters.json` - Implementation parameters
