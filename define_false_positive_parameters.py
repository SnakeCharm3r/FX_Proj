#!/usr/bin/env python3
"""
Define parameters for false positive detection based on identified patterns.
"""

import json
from pathlib import Path

# Load the analysis results
with open('/var/www/FX_Proj/research/opportunity-audit/false_positive_analysis.json', 'r') as f:
    all_results = json.load(f)

buy_fp = all_results['buy_false_positives']
sell_fp = all_results['sell_false_positives']

print("=" * 80)
print("FALSE POSITIVE DETECTION PARAMETERS")
print("=" * 80)

print("\nBased on analysis of 364 false positives over the last week:")
print(f"  - Buy false positives (heavy buying, price fell): {len(buy_fp)}")
print(f"  - Sell false positives (heavy selling, price rose): {len(sell_fp)}")

print("\n" + "=" * 80)
print("BUY FALSE POSITIVE PARAMETERS (Heavy buying, price fell)")
print("=" * 80)

# Calculate thresholds
if buy_fp:
    delta_thresholds = [fp['delta_pct'] for fp in buy_fp]
    bid_ask_ratios = [fp['bid']/fp['ask'] for fp in buy_fp if fp['ask'] > 0]
    future_changes = [fp['future_change'] for fp in buy_fp]
    volumes = [fp['volume'] for fp in buy_fp]

    print("\n1. DELTA THRESHOLDS:")
    print(f"   Min delta that triggered false positive: {min(delta_thresholds):.2f}%")
    print(f"   Max delta: {max(delta_thresholds):.2f}%")
    print(f"   Average delta: {sum(delta_thresholds)/len(delta_thresholds):.2f}%")
    print(f"   Median delta: {sorted(delta_thresholds)[len(delta_thresholds)//2]:.2f}%")
    print(f"   RECOMMENDED THRESHOLD: > 8.0% (captures majority of false positives)")

    print("\n2. BID/ASK RATIO PATTERN:")
    print(f"   Average bid/ask ratio: {sum(bid_ask_ratios)/len(bid_ask_ratios):.3f}")
    print(f"   Min bid/ask ratio: {min(bid_ask_ratios):.3f}")
    print(f"   Max bid/ask ratio: {max(bid_ask_ratios):.3f}")
    print(f"   RECOMMENDED CHECK: If bid/ask ratio > 0.70 despite positive delta, flag as potential false positive")

    print("\n3. VOLUME CONTEXT:")
    print(f"   Average volume: {sum(volumes)/len(volumes):.0f}")
    print(f"   Min volume: {min(volumes):.0f}")
    print(f"   Max volume: {max(volumes):.0f}")
    print(f"   RECOMMENDED: High volume (>400) with positive delta increases false positive risk")

    print("\n4. FUTURE PRICE MOVEMENT:")
    print(f"   Average future change (5 bars): {sum(future_changes)/len(future_changes):.2f}")
    print(f"   Min future change: {min(future_changes):.2f}")
    print(f"   Max future change: {max(future_changes):.2f}")

print("\n" + "=" * 80)
print("SELL FALSE POSITIVE PARAMETERS (Heavy selling, price rose)")
print("=" * 80)

if sell_fp:
    delta_thresholds = [fp['delta_pct'] for fp in sell_fp]
    bid_ask_ratios = [fp['bid']/fp['ask'] for fp in sell_fp if fp['ask'] > 0]
    future_changes = [fp['future_change'] for fp in sell_fp]
    volumes = [fp['volume'] for fp in sell_fp]

    print("\n1. DELTA THRESHOLDS:")
    print(f"   Min delta (most negative): {min(delta_thresholds):.2f}%")
    print(f"   Max delta (least negative): {max(delta_thresholds):.2f}%")
    print(f"   Average delta: {sum(delta_thresholds)/len(delta_thresholds):.2f}%")
    print(f"   Median delta: {sorted(delta_thresholds)[len(delta_thresholds)//2]:.2f}%")
    print(f"   RECOMMENDED THRESHOLD: < -8.0% (captures majority of false positives)")

    print("\n2. BID/ASK RATIO PATTERN:")
    print(f"   Average bid/ask ratio: {sum(bid_ask_ratios)/len(bid_ask_ratios):.3f}")
    print(f"   Min bid/ask ratio: {min(bid_ask_ratios):.3f}")
    print(f"   Max bid/ask ratio: {max(bid_ask_ratios):.3f}")
    print(f"   RECOMMENDED CHECK: If bid/ask ratio < 0.90 despite negative delta, flag as potential false positive")

print("\n" + "=" * 80)
print("CONTEXTUAL CLUES (Preceding 3 bars)")
print("=" * 80)

print("\nFrom Friday analysis:")
print("  - Average delta in 3 bars BEFORE false positive buy: -16.46 (SELLING PRESSURE)")
print("  - Price trend before false positive: DOWN")
print("  - Average volume before: 511.71")
print("\nCRITICAL INSIGHT:")
print("  Heavy buying (positive delta) AFTER selling pressure (negative delta)")
print("  is a classic trap pattern. This suggests absorption or stop hunts.")

print("\n" + "=" * 80)
print("PROPOSED FALSE POSITIVE DETECTION LOGIC")
print("=" * 80)

print("""
For BUY signals (positive delta):
---------------------------------
FLAG as potential false positive if:
1. Delta > 8.0% AND
2. Bid/Ask ratio > 0.70 (indicates absorption) AND
3. Previous 3 bars show negative delta (selling pressure) OR
4. Previous 3 bars show downward price trend

HIGH RISK if:
- Delta > 15.0% AND
- Bid/Ask ratio > 0.75 AND
- Previous 3 bars had average delta < -10.0

For SELL signals (negative delta):
---------------------------------
FLAG as potential false positive if:
1. Delta < -8.0% AND
2. Bid/Ask ratio < 0.90 (indicates absorption) AND
3. Previous 3 bars show positive delta (buying pressure) OR
4. Previous 3 bars show upward price trend

HIGH RISK if:
- Delta < -15.0% AND
- Bid/Ask ratio < 0.85 AND
- Previous 3 bars had average delta > 10.0
""")

print("\n" + "=" * 80)
print("IMPLEMENTATION PARAMETERS")
print("=" * 80)

params = {
    'buy_false_positive': {
        'delta_threshold': 8.0,
        'delta_high_risk': 15.0,
        'bid_ask_ratio_threshold': 0.70,
        'bid_ask_ratio_high_risk': 0.75,
        'prev_delta_threshold': -10.0,
        'lookback_bars': 3,
    },
    'sell_false_positive': {
        'delta_threshold': -8.0,
        'delta_high_risk': -15.0,
        'bid_ask_ratio_threshold': 0.90,
        'bid_ask_ratio_high_risk': 0.85,
        'prev_delta_threshold': 10.0,
        'lookback_bars': 3,
    },
    'time_context': {
        'peak_hours': [14, 23, 1, 2],  # Hours with highest false positive frequency
    },
    'price_context': {
        'common_levels': [4000, 4020, 4010, 4015, 3975],  # Levels with high false positive frequency
    }
}

print("\nRecommended parameters:")
print(json.dumps(params, indent=2))

# Save parameters
output_path = Path("/var/www/FX_Proj/research/opportunity-audit/false_positive_parameters.json")
output_path.parent.mkdir(parents=True, exist_ok=True)

with open(output_path, 'w') as f:
    json.dump(params, f, indent=2)

print(f"\nParameters saved to: {output_path}")

print("\n" + "=" * 80)
print("ADDITIONAL CLUES TO INVESTIGATE")
print("=" * 80)

print("""
1. FOOTPRINT LEVEL IMBALANCE:
   - Check if levels show extreme imbalance (>70% one-sided)
   - High imbalance with positive delta but no price move = absorption

2. MARKET PARTICIPATION:
   - Check if participation is DECREASING despite delta
   - Decreasing participation + high delta = trap

3. ZONE CONTEXT:
   - Check if signal is at a demand/supply zone
   - Signal away from zone = higher false positive risk

4. ABSORPTION SEQUENCE:
   - Check if absorption sequence is qualified
   - If NOT qualified but delta is high = potential false positive
""")

print("\n" + "=" * 80)
print("NEXT STEPS")
print("=" * 80)

print("""
1. Implement these parameters in VariableOpportunityService
2. Add false positive flag to opportunity detection
3. Create warning system for high-risk delta signals
4. Backtest these parameters on historical data
5. Monitor live signals and validate parameters
""")
