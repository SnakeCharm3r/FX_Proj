#!/usr/bin/env python3
"""
Detailed analysis of Friday (2026-10-30) false positives
Focus on the heavy buying with falling price phenomenon
"""

import json
import gzip
import os
from pathlib import Path
from datetime import datetime
from collections import defaultdict

DATA_ROOT = Path("/var/www/FX_Proj/laravel-app/storage/app/private/research/clusterdelta/GC")
TF = "5m"

def load_gzipped_json(filepath):
    """Load a gzipped JSON file."""
    with gzip.open(filepath, 'rt') as f:
        return json.load(f)

def load_data_for_date(date_str):
    """Load both broker and footprint data for a specific date."""
    date_path = DATA_ROOT / TF / date_str
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

def analyze_friday_detail():
    """Analyze Friday Oct 30 in detail."""
    date = '2026-10-30'
    broker_data, footprint_data = load_data_for_date(date)

    # Sort by time
    broker_data = sorted(broker_data, key=lambda x: x['bar']['time'])

    # Load the previous analysis results
    with open('/var/www/FX_Proj/research/opportunity-audit/false_positive_analysis.json', 'r') as f:
        all_results = json.load(f)

    # Get Friday's false positive buys
    friday_fp_buys = [fp for fp in all_results['buy_false_positives']
                      if fp['time'].startswith('2026-10-30')]

    print("=" * 80)
    print(f"FRIDAY ANALYSIS: {date}")
    print("=" * 80)
    print(f"\nTotal bars: {len(broker_data)}")
    print(f"False positive buys (heavy buying, price fell): {len(friday_fp_buys)}")

    # Group by hour
    by_hour = defaultdict(list)
    for fp in friday_fp_buys:
        hour = datetime.fromtimestamp(fp['timestamp']).hour
        by_hour[hour].append(fp)

    print("\nFalse positive buys by hour:")
    for hour in sorted(by_hour.keys()):
        count = len(by_hour[hour])
        avg_delta = sum(fp['delta_pct'] for fp in by_hour[hour]) / count
        avg_future = sum(fp['future_change'] for fp in by_hour[hour]) / count
        print(f"  {hour:02d}:00 - {count} occurrences, avg delta: {avg_delta:.2f}%, avg future change: {avg_future:.2f}")

    # Analyze the most significant ones
    print("\n" + "-" * 80)
    print("TOP 20 FALSE POSITIVE BUYS ON FRIDAY")
    print("-" * 80)

    sorted_fp = sorted(friday_fp_buys, key=lambda x: abs(x['delta_pct']), reverse=True)[:20]

    for i, fp in enumerate(sorted_fp, 1):
        dt = datetime.fromtimestamp(fp['timestamp'])
        print(f"\n{i}. {dt.strftime('%Y-%m-%d %H:%M:%S')}")
        print(f"   Delta: {fp['delta_pct']:.2f}%")
        print(f"   Volume: {fp['volume']}")
        print(f"   Price: O={fp['open']:.2f} H={fp['high']:.2f} L={fp['low']:.2f} C={fp['close']:.2f}")
        print(f"   Price change (bar): {fp['price_change']:.2f}")
        print(f"   Future change (5 bars): {fp['future_change']:.2f}")
        print(f"   Ask volume: {fp['ask']}")
        print(f"   Bid volume: {fp['bid']}")
        print(f"   Bid/Ask ratio: {fp['bid']/fp['ask'] if fp['ask'] > 0 else 0:.3f}")

    # Look for contextual clues - what happened before/after these false positives
    print("\n" + "-" * 80)
    print("CONTEXTUAL ANALYSIS - What precedes false positives?")
    print("-" * 80)

    # For each false positive, look at the 3 bars before
    context_analysis = {
        'prev_avg_delta': [],
        'prev_avg_volume': [],
        'prev_price_trend': [],  # -1 for down, 0 for flat, 1 for up
    }

    for fp in sorted_fp[:10]:  # Analyze top 10
        timestamp = fp['timestamp']
        # Find index in broker_data
        try:
            idx = next(i for i, b in enumerate(broker_data) if b['bar']['time'] == timestamp)
        except StopIteration:
            continue

        # Look at previous 3 bars
        prev_deltas = []
        prev_volumes = []
        prev_changes = []

        for j in range(max(0, idx-3), idx):
            prev_bar = broker_data[j]['bar']
            prev_timestamp = prev_bar['time']
            if prev_timestamp in footprint_data:
                prev_fp = footprint_data[prev_timestamp]['bar']
                prev_deltas.append(prev_fp.get('delta', 0))
                prev_volumes.append(prev_fp.get('ask', 0) + prev_fp.get('bid', 0))
                prev_changes.append(prev_fp['close'] - prev_fp['open'])

        if prev_deltas:
            context_analysis['prev_avg_delta'].append(sum(prev_deltas) / len(prev_deltas))
            context_analysis['prev_avg_volume'].append(sum(prev_volumes) / len(prev_volumes))
            avg_prev_change = sum(prev_changes) / len(prev_changes)
            context_analysis['prev_price_trend'].append(1 if avg_prev_change > 0 else (-1 if avg_prev_change < 0 else 0))

    print(f"\nAverage delta in 3 bars before false positive: {sum(context_analysis['prev_avg_delta'])/len(context_analysis['prev_avg_delta']):.2f}")
    print(f"Average volume in 3 bars before false positive: {sum(context_analysis['prev_avg_volume'])/len(context_analysis['prev_avg_volume']):.2f}")
    print(f"Price trend before false positive: {'UP' if sum(context_analysis['prev_price_trend']) > 0 else ('DOWN' if sum(context_analysis['prev_price_trend']) < 0 else 'FLAT')}")

    # Check if these are happening at specific price levels
    print("\n" + "-" * 80)
    print("PRICE LEVEL ANALYSIS")
    print("-" * 80)

    price_levels = defaultdict(int)
    for fp in friday_fp_buys:
        # Round to nearest 5
        level = round(fp['close'] / 5) * 5
        price_levels[level] += 1

    print("Most common price levels for false positives:")
    for level, count in sorted(price_levels.items(), key=lambda x: x[1], reverse=True)[:5]:
        print(f"  {level:.0f}: {count} occurrences")

    # Analyze footprint levels data for clues
    print("\n" + "-" * 80)
    print("FOOTPRINT LEVELS ANALYSIS (checking for absorption patterns)")
    print("-" * 80)

    # Check top 3 false positives for footprint level patterns
    for i, fp in enumerate(sorted_fp[:3], 1):
        timestamp = fp['timestamp']
        if timestamp in footprint_data:
            fp_data = footprint_data[timestamp]
            fp_bar = fp_data['bar']

            print(f"\n{i}. {datetime.fromtimestamp(timestamp).strftime('%H:%M:%S')} - Delta: {fp['delta_pct']:.2f}%")

            # Analyze levels
            levels = fp_bar.get('levels', [])
            if levels:
                # Calculate imbalance at each level
                imbalances = []
                for level in levels:
                    ask = level.get('ask', 0)
                    bid = level.get('bid', 0)
                    total = ask + bid
                    if total > 0:
                        imbalance = (ask - bid) / total
                        imbalances.append(abs(imbalance))

                if imbalances:
                    avg_imbalance = sum(imbalances) / len(imbalances)
                    max_imbalance = max(imbalances)
                    print(f"   Average level imbalance: {avg_imbalance:.3f}")
                    print(f"   Max level imbalance: {max_imbalance:.3f}")

                    # Check for extreme imbalances (> 0.7)
                    extreme_count = sum(1 for imb in imbalances if imb > 0.7)
                    print(f"   Levels with extreme imbalance (>70%): {extreme_count}/{len(imbalances)}")

    print("\n" + "=" * 80)
    print("KEY FINDINGS SUMMARY")
    print("=" * 80)

    if by_hour:
        peak_hour = max(by_hour.items(), key=lambda x: len(x[1]))
        print(f"\nPeak hour for false positives: {peak_hour[0]:02d}:00 with {len(peak_hour[1])} occurrences")

    if friday_fp_buys:
        avg_delta = sum(fp['delta_pct'] for fp in friday_fp_buys) / len(friday_fp_buys)
        avg_future = sum(fp['future_change'] for fp in friday_fp_buys) / len(friday_fp_buys)
        print(f"Average delta percentage: {avg_delta:.2f}%")
        print(f"Average future price change: {avg_future:.2f}")

        # Calculate bid/ask ratio pattern
        avg_bid_ask = sum(fp['bid']/fp['ask'] for fp in friday_fp_buys if fp['ask'] > 0) / len([fp for fp in friday_fp_buys if fp['ask'] > 0])
        print(f"Average bid/ask ratio: {avg_bid_ask:.3f}")

if __name__ == "__main__":
    analyze_friday_detail()
