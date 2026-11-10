#!/usr/bin/env python3
"""
Define combined signal parameters:
1. False positive delta detection (trap patterns)
2. Zone deep-penetration detection (liquidity grab patterns)
"""

import json
from pathlib import Path

# Load the analysis results
with open('/var/www/FX_Proj/research/opportunity-audit/false_positive_analysis.json', 'r') as f:
    fp_results = json.load(f)

with open('/var/www/FX_Proj/research/opportunity-audit/zone_penetration_analysis.json', 'r') as f:
    zone_results = json.load(f)

print("=" * 80)
print("COMBINED SIGNAL ANALYSIS")
print("=" * 80)

print("\n1. FALSE POSITIVE DELTA SIGNALS (Trap Patterns)")
print("-" * 80)
print(f"   Buy false positives (heavy buying, price fell): {len(fp_results['buy_false_positives'])}")
print(f"   Sell false positives (heavy selling, price rose): {len(fp_results['sell_false_positives'])}")

print("\n2. ZONE DEEP-PENETRATION SIGNALS (Liquidity Grab Patterns)")
print("-" * 80)
print(f"   Demand zone penetrations (deep push, reversal up): {len(zone_results['demand'])}")
print(f"   Supply zone penetrations (deep push, reversal down): {len(zone_results['supply'])}")

print("\n" + "=" * 80)
print("ZONE PENETRATION PARAMETERS")
print("=" * 80)

if zone_results['demand']:
    demand_depths = [p['penetration_depth_pct'] for p in zone_results['demand']]
    demand_future = [p['avg_future_change'] for p in zone_results['demand']]
    demand_delta = [p['delta'] for p in zone_results['demand']]
    demand_volume = [p['volume'] for p in zone_results['demand']]

    print("\nDEMAND ZONE PENETRATION:")
    print(f"  Average penetration depth: {sum(demand_depths)/len(demand_depths):.2f}%")
    print(f"  Min penetration depth: {min(demand_depths):.2f}%")
    print(f"  Max penetration depth: {max(demand_depths):.2f}%")
    print(f"  RECOMMENDED THRESHOLD: > 50% (deep penetration)")
    print(f"  Average future change: {sum(demand_future)/len(demand_future):.2f}")
    print(f"  Average delta: {sum(demand_delta)/len(demand_delta):.2f}")
    print(f"  Average volume: {sum(demand_volume)/len(demand_volume):.0f}")

if zone_results['supply']:
    supply_depths = [p['penetration_depth_pct'] for p in zone_results['supply']]
    supply_future = [p['avg_future_change'] for p in zone_results['supply']]
    supply_delta = [p['delta'] for p in zone_results['supply']]
    supply_volume = [p['volume'] for p in zone_results['supply']]

    print("\nSUPPLY ZONE PENETRATION:")
    print(f"  Average penetration depth: {sum(supply_depths)/len(supply_depths):.2f}%")
    print(f"  Min penetration depth: {min(supply_depths):.2f}%")
    print(f"  Max penetration depth: {max(supply_depths):.2f}%")
    print(f"  RECOMMENDED THRESHOLD: > 50% (deep penetration)")
    print(f"  Average future change: {sum(supply_future)/len(supply_future):.2f}")
    print(f"  Average delta: {sum(supply_delta)/len(supply_delta):.2f}")
    print(f"  Average volume: {sum(supply_volume)/len(supply_volume):.0f}")

print("\n" + "=" * 80)
print("COMBINED SIGNAL STRATEGY")
print("=" * 80)

print("""
The two patterns work together to identify high-probability reversals:

PATTERN 1: FALSE POSITIVE DELTA (Trap)
--------------------------------------
Heavy delta in one direction but price moves opposite.
- Indicates absorption or stop hunt
- Preceded by opposite pressure
- Bid/ask ratio shows absorption

PATTERN 2: ZONE DEEP PENETRATION (Liquidity Grab)
------------------------------------------------
Price pushes deep (>50%) into a demand/supply zone.
- Triggers pending orders
- Strong reversal follows
- High volume confirms

COMBINED SIGNAL (HIGH CONVICTION):
----------------------------------
When BOTH patterns occur together:

BUY SIGNAL (after deep demand zone penetration):
1. Price penetrates >50% into demand zone
2. Delta is negative (selling pressure) at penetration bottom
3. Price reverses up with positive delta
4. Previous 3 bars show downward pressure
5. Bid/ask ratio indicates absorption at the bottom

SELL SIGNAL (after deep supply zone penetration):
1. Price penetrates >50% into supply zone
2. Delta is positive (buying pressure) at penetration top
3. Price reverses down with negative delta
4. Previous 3 bars show upward pressure
5. Bid/ask ratio indicates absorption at the top

FALSE POSITIVE WARNING (avoid these):
-------------------------------------
1. Heavy positive delta but no zone penetration context
2. Previous 3 bars show opposite pressure
3. Bid/ask ratio > 0.70 (buy trap) or < 0.90 (sell trap)
""")

print("\n" + "=" * 80)
print("IMPLEMENTATION PARAMETERS")
print("=" * 80)

combined_params = {
    'false_positive_detection': {
        'buy': {
            'delta_threshold': 8.0,
            'delta_high_risk': 15.0,
            'bid_ask_ratio_threshold': 0.70,
            'bid_ask_ratio_high_risk': 0.75,
            'prev_delta_threshold': -10.0,
            'lookback_bars': 3,
        },
        'sell': {
            'delta_threshold': -8.0,
            'delta_high_risk': -15.0,
            'bid_ask_ratio_threshold': 0.90,
            'bid_ask_ratio_high_risk': 0.85,
            'prev_delta_threshold': 10.0,
            'lookback_bars': 3,
        }
    },
    'zone_penetration_detection': {
        'penetration_depth_threshold': 50.0,  # % of zone depth
        'penetration_depth_high_conviction': 70.0,
        'min_future_change': 1.0,  # Minimum reversal strength
        'lookahead_bars': 5,
        'min_zone_wick_ratio': 0.2,  # Zone must have significant wick
        'min_departure_strength': 0.5,  # Zone must have strong departure
    },
    'combined_signal': {
        'high_conviction': {
            'requires_zone_penetration': True,
            'requires_delta_confirmation': True,
            'penetration_depth_min': 70.0,
            'delta_alignment': 'opposite_at_penetration',  # Delta opposite to penetration direction
        },
        'medium_conviction': {
            'requires_zone_penetration': True,
            'requires_delta_confirmation': False,
            'penetration_depth_min': 50.0,
        },
        'avoid_signals': {
            'false_positive_delta_only': True,  # Avoid signals with delta but no zone context
            'delta_threshold': 10.0,  # If delta > 10% without zone, flag as avoid
        }
    },
    'time_context': {
        'peak_false_positive_hours': [14, 23, 1, 2],
    },
    'price_context': {
        'common_false_positive_levels': [4000, 4020, 4010, 4015, 3975],
    }
}

print("\nRecommended parameters:")
print(json.dumps(combined_params, indent=2))

# Save parameters
output_path = Path("/var/www/FX_Proj/research/opportunity-audit/combined_signal_parameters.json")
output_path.parent.mkdir(parents=True, exist_ok=True)

with open(output_path, 'w') as f:
    json.dump(combined_params, f, indent=2)

print(f"\nParameters saved to: {output_path}")

print("\n" + "=" * 80)
print("SIGNAL CLASSIFICATION")
print("=" * 80)

print("""
SIGNAL TYPE A: HIGH CONVICTION REVERSAL
---------------------------------------
Conditions:
- Zone penetration > 70% of zone depth
- Delta opposite to penetration direction at bottom/top
- Strong reversal follows (>1.0 points in 5 bars)
- Previous 3 bars show pressure into the zone

Action: TAKE THE TRADE
Confidence: HIGH

SIGNAL TYPE B: MEDIUM CONVICTION REVERSAL
-----------------------------------------
Conditions:
- Zone penetration > 50% of zone depth
- Some delta confirmation
- Moderate reversal follows

Action: CONSIDER WITH CAUTION
Confidence: MEDIUM

SIGNAL TYPE C: FALSE POSITIVE WARNING
-------------------------------------
Conditions:
- High delta (>10%) but NO zone penetration context
- Previous 3 bars show opposite pressure
- Bid/ask ratio indicates absorption

Action: AVOID
Confidence: TRAP

SIGNAL TYPE D: STANDARD OPPORTUNITY
------------------------------------
Conditions:
- Delta threshold met
- No false positive flags
- No zone penetration context

Action: STANDARD EVALUATION
Confidence: BASELINE
""")

print("\n" + "=" * 80)
print("IMPLEMENTATION STEPS")
print("=" * 80)

print("""
1. Add zone penetration detection to PriceZoneDetector
2. Add false positive detection method to VariableOpportunityService
3. Create signal classification method that combines both
4. Update alert messages to include signal type and confidence
5. Add visual indicators in alerts:
   - 🔥 HIGH CONVICTION (zone + delta alignment)
   - ⚠️ MEDIUM CONVICTION (zone only)
   - 🚫 AVOID (false positive delta trap)
   - ℹ️ STANDARD (baseline signal)
6. Backtest combined signals on historical data
7. Monitor live performance and refine parameters
""")
