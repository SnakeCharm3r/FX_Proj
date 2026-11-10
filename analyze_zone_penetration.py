#!/usr/bin/env python3
"""
Analyze zone deep-penetration patterns.
Pattern: Price pushes DEEP into a demand/supply zone, triggers pending orders, then reverses strongly.
"""

import json
import gzip
import os
from pathlib import Path
from datetime import datetime
from collections import defaultdict
import statistics

DATA_ROOT = Path("/var/www/FX_Proj/laravel-app/storage/app/private/research/clusterdelta/GC")

def load_gzipped_json(filepath):
    """Load a gzipped JSON file."""
    with gzip.open(filepath, 'rt') as f:
        return json.load(f)

def load_data_for_date(date_str, tf='5m'):
    """Load both broker and footprint data for a specific date."""
    date_path = DATA_ROOT / tf / date_str
    broker_path = date_path / "broker"
    footprint_path = date_path / "footprint"

    if not broker_path.exists() or not footprint_path.exists():
        return [], []

    broker_files = sorted([f for f in os.listdir(broker_path) if f.endswith('.json.gz')])
    footprint_files = sorted([f for f in os.listdir(footprint_path) if f.endswith('.json.gz')])

    broker_data = []
    for bf in broker_files:
        try:
            data = load_gzipped_json(broker_path / bf)
            broker_data.append(data)
        except Exception as e:
            print(f"Error loading {bf}: {e}")

    footprint_data = {}
    for ff in footprint_files:
        try:
            data = load_gzipped_json(footprint_path / ff)
            footprint_data[data['bar']['time']] = data
        except Exception as e:
            print(f"Error loading {ff}: {e}")

    return broker_data, footprint_data

def detect_zones_from_footprint(footprint_data, lookback=20):
    """
    Detect demand/supply zones from footprint data.
    A zone is where there's a rejection wick followed by strong departure.
    """
    bars = sorted(footprint_data.values(), key=lambda x: x['bar']['time'])
    zones = []

    for i in range(lookback, len(bars) - 5):
        origin = bars[i]['bar']
        prev_bars = bars[i-lookback:i]

        # Calculate wick
        body_top = max(origin['open'], origin['close'])
        body_bottom = min(origin['open'], origin['close'])
        range_size = origin['high'] - origin['low']

        if range_size <= 0:
            continue

        # Check for significant wick (at least 20% of range)
        wick_top = origin['high'] - body_top
        wick_bottom = body_bottom - origin['low']
        wick_ratio = max(wick_top, wick_bottom) / range_size

        if wick_ratio < 0.2:
            continue

        # Determine zone type
        if wick_bottom > wick_top:
            # Demand zone (wick at bottom)
            zone_type = 'DEMAND'
            zone_low = origin['low']
            zone_high = body_bottom
        else:
            # Supply zone (wick at top)
            zone_type = 'SUPPLY'
            zone_low = body_top
            zone_high = origin['high']

        # Check for departure in next 5 bars
        confirmed = False
        departure_strength = 0

        for j in range(1, 6):
            if i + j >= len(bars):
                break
            next_bar = bars[i + j]['bar']

            if zone_type == 'DEMAND':
                if next_bar['close'] > zone_high:
                    confirmed = True
                    departure_strength = max(departure_strength, (next_bar['close'] - zone_high) / range_size)
                    break
            else:  # SUPPLY
                if next_bar['close'] < zone_low:
                    confirmed = True
                    departure_strength = max(departure_strength, (zone_low - next_bar['close']) / range_size)
                    break

        if confirmed and departure_strength > 0.5:
            zones.append({
                'type': zone_type,
                'low': zone_low,
                'high': zone_high,
                'origin_time': origin['time'],
                'depth': zone_high - zone_low,
                'wick_ratio': wick_ratio,
                'departure_strength': departure_strength,
            })

    return zones

def analyze_zone_penetrations(broker_data, footprint_data, zones):
    """
    Analyze deep penetrations into zones and subsequent reversals.
    """
    broker_data = sorted(broker_data, key=lambda x: x['bar']['time'])
    footprint_times = sorted(footprint_data.keys())

    penetrations = []

    for zone in zones:
        zone_low = zone['low']
        zone_high = zone['high']
        zone_depth = zone['depth']
        zone_type = zone['type']

        # Find bars that penetrate this zone
        for i, broker in enumerate(broker_data):
            bar = broker['bar']
            timestamp = bar['time']

            if timestamp not in footprint_data:
                continue

            fp_bar = footprint_data[timestamp]['bar']

            # Check if bar penetrates the zone
            penetrated = False
            penetration_depth = 0

            if zone_type == 'DEMAND':
                # Price pushing down into demand zone
                if fp_bar['low'] < zone_high and fp_bar['low'] >= zone_low:
                    penetrated = True
                    penetration_depth = (zone_high - fp_bar['low']) / zone_depth if zone_depth > 0 else 0
            else:  # SUPPLY
                # Price pushing up into supply zone
                if fp_bar['high'] > zone_low and fp_bar['high'] <= zone_high:
                    penetrated = True
                    penetration_depth = (fp_bar['high'] - zone_low) / zone_depth if zone_depth > 0 else 0

            if not penetrated or penetration_depth < 0.3:
                continue  # Only consider deep penetrations (>30% of zone depth)

            # Check subsequent price action for reversal
            future_changes = []
            for j in range(1, 10):  # Look at next 10 bars
                if i + j >= len(broker_data):
                    break
                next_bar = broker_data[i + j]['bar']
                next_timestamp = next_bar['time']
                if next_timestamp in footprint_data:
                    next_fp = footprint_data[next_timestamp]['bar']
                    if zone_type == 'DEMAND':
                        future_changes.append(next_fp['close'] - fp_bar['close'])
                    else:
                        future_changes.append(fp_bar['close'] - next_fp['close'])

            if not future_changes:
                continue

            avg_future_change = statistics.mean(future_changes[:5])  # First 5 bars
            max_future_change = max(future_changes[:5])

            # Check for strong reversal (positive future change for demand, negative for supply)
            if zone_type == 'DEMAND':
                reversal = avg_future_change > 0 and max_future_change > zone_depth * 0.5
            else:
                reversal = avg_future_change > 0 and max_future_change > zone_depth * 0.5

            if reversal:
                penetrations.append({
                    'zone_type': zone_type,
                    'zone_low': zone_low,
                    'zone_high': zone_high,
                    'zone_depth': zone_depth,
                    'penetration_time': datetime.fromtimestamp(timestamp).isoformat(),
                    'penetration_timestamp': timestamp,
                    'penetration_depth_pct': penetration_depth * 100,
                    'penetration_price': fp_bar['close'],
                    'penetration_low': fp_bar['low'],
                    'penetration_high': fp_bar['high'],
                    'avg_future_change': avg_future_change,
                    'max_future_change': max_future_change,
                    'delta': fp_bar.get('delta', 0),
                    'volume': fp_bar.get('ask', 0) + fp_bar.get('bid', 0),
                    'future_bars': len(future_changes),
                })

    return penetrations

def main():
    dates = ['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30']

    all_penetrations = {
        'demand': [],
        'supply': [],
        'daily_summary': {}
    }

    print("Analyzing zone deep-penetration patterns...")
    print("=" * 80)

    for date in dates:
        print(f"\nAnalyzing {date} (5m timeframe)...")
        broker_data, footprint_data = load_data_for_date(date, '5m')

        if not broker_data:
            print(f"  No broker data for {date}")
            continue

        # Detect zones from footprint data
        zones = detect_zones_from_footprint(footprint_data)
        print(f"  Detected {len(zones)} zones")

        # Analyze penetrations
        penetrations = analyze_zone_penetrations(broker_data, footprint_data, zones)

        demand_pen = [p for p in penetrations if p['zone_type'] == 'DEMAND']
        supply_pen = [p for p in penetrations if p['zone_type'] == 'SUPPLY']

        print(f"  Deep penetrations: {len(penetrations)}")
        print(f"    Demand zone penetrations: {len(demand_pen)}")
        print(f"    Supply zone penetrations: {len(supply_pen)}")

        all_penetrations['demand'].extend(demand_pen)
        all_penetrations['supply'].extend(supply_pen)

        all_penetrations['daily_summary'][date] = {
            'zones_detected': len(zones),
            'total_penetrations': len(penetrations),
            'demand_penetrations': len(demand_pen),
            'supply_penetrations': len(supply_pen),
        }

    print("\n" + "=" * 80)
    print("OVERALL SUMMARY")
    print("=" * 80)

    total_demand = len(all_penetrations['demand'])
    total_supply = len(all_penetrations['supply'])
    total = total_demand + total_supply

    print(f"\nTotal demand zone penetrations (deep push then reversal): {total_demand}")
    print(f"Total supply zone penetrations (deep push then reversal): {total_supply}")
    print(f"Total penetrations: {total}")

    if total_demand > 0:
        print("\n" + "-" * 80)
        print("DEMAND ZONE PENETRATION PATTERNS")
        print("-" * 80)

        avg_depth = statistics.mean([p['penetration_depth_pct'] for p in all_penetrations['demand']])
        avg_future = statistics.mean([p['avg_future_change'] for p in all_penetrations['demand']])
        avg_delta = statistics.mean([p['delta'] for p in all_penetrations['demand']])
        avg_volume = statistics.mean([p['volume'] for p in all_penetrations['demand']])

        print(f"  Average penetration depth: {avg_depth:.2f}%")
        print(f"  Average future change: {avg_future:.2f}")
        print(f"  Average delta: {avg_delta:.2f}")
        print(f"  Average volume: {avg_volume:.0f}")

        print("\nTop 10 most significant demand zone penetrations:")
        sorted_pen = sorted(all_penetrations['demand'], key=lambda x: x['penetration_depth_pct'], reverse=True)[:10]
        for i, pen in enumerate(sorted_pen, 1):
            print(f"  {i}. {pen['penetration_time']} - Depth: {pen['penetration_depth_pct']:.2f}%, "
                  f"Future: {pen['avg_future_change']:.2f}, Delta: {pen['delta']:.0f}")

    if total_supply > 0:
        print("\n" + "-" * 80)
        print("SUPPLY ZONE PENETRATION PATTERNS")
        print("-" * 80)

        avg_depth = statistics.mean([p['penetration_depth_pct'] for p in all_penetrations['supply']])
        avg_future = statistics.mean([p['avg_future_change'] for p in all_penetrations['supply']])
        avg_delta = statistics.mean([p['delta'] for p in all_penetrations['supply']])
        avg_volume = statistics.mean([p['volume'] for p in all_penetrations['supply']])

        print(f"  Average penetration depth: {avg_depth:.2f}%")
        print(f"  Average future change: {avg_future:.2f}")
        print(f"  Average delta: {avg_delta:.2f}")
        print(f"  Average volume: {avg_volume:.0f}")

        print("\nTop 10 most significant supply zone penetrations:")
        sorted_pen = sorted(all_penetrations['supply'], key=lambda x: x['penetration_depth_pct'], reverse=True)[:10]
        for i, pen in enumerate(sorted_pen, 1):
            print(f"  {i}. {pen['penetration_time']} - Depth: {pen['penetration_depth_pct']:.2f}%, "
                  f"Future: {pen['avg_future_change']:.2f}, Delta: {pen['delta']:.0f}")

    # Save results
    output_path = Path("/var/www/FX_Proj/research/opportunity-audit/zone_penetration_analysis.json")
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, 'w') as f:
        json.dump(all_penetrations, f, indent=2)

    print(f"\nDetailed results saved to: {output_path}")

if __name__ == "__main__":
    main()
