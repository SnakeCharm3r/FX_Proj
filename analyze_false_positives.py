#!/usr/bin/env python3
"""
Analyze footprint and broker data to identify false positives:
Cases where delta direction doesn't align with price movement direction.
"""

import json
import gzip
import os
from pathlib import Path
from datetime import datetime, timedelta
from collections import defaultdict
import statistics

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
            # Index by timestamp for easy lookup
            footprint_data[data['bar']['time']] = data
        except Exception as e:
            print(f"Error loading {ff}: {e}")

    return broker_data, footprint_data

def analyze_false_positives(broker_data, footprint_data):
    """
    Identify false positives where delta direction opposes price movement.
    """
    results = {
        'total_bars': 0,
        'paired_bars': 0,
        'false_positive_buy': [],  # Positive delta but price went down
        'false_positive_sell': [],  # Negative delta but price went up
        'true_positive_buy': [],    # Positive delta and price went up
        'true_positive_sell': [],   # Negative delta and price went down
    }

    # Sort broker data by time
    broker_data = sorted(broker_data, key=lambda x: x['bar']['time'])

    for i, broker in enumerate(broker_data):
        bar = broker['bar']
        timestamp = bar['time']

        # Skip if no matching footprint
        if timestamp not in footprint_data:
            continue

        fp = footprint_data[timestamp]
        fp_bar = fp['bar']

        results['paired_bars'] += 1

        # Calculate price movement (close - open)
        price_change = fp_bar['close'] - fp_bar['open']
        delta = fp_bar.get('delta', 0)

        # Calculate future price movement (next N bars)
        future_price_changes = []
        for j in range(1, 6):  # Look at next 5 bars
            if i + j < len(broker_data):
                next_bar = broker_data[i + j]['bar']
                next_timestamp = next_bar['time']
                if next_timestamp in footprint_data:
                    next_fp = footprint_data[next_timestamp]['bar']
                    future_price_changes.append(next_fp['close'] - fp_bar['close'])

        if not future_price_changes:
            continue

        # Average future price movement
        avg_future_change = statistics.mean(future_price_changes)

        # Determine if delta is significant (threshold)
        delta_threshold = 0.1  # 10% of volume as delta threshold

        # Calculate delta percentage
        total_volume = fp_bar.get('ask', 0) + fp_bar.get('bid', 0)
        delta_pct = (delta / total_volume * 100) if total_volume > 0 else 0

        # Identify false positives
        if abs(delta_pct) >= delta_threshold:
            if delta > 0 and avg_future_change < 0:
                # False positive buy: positive delta but price went down
                results['false_positive_buy'].append({
                    'time': datetime.fromtimestamp(timestamp).isoformat(),
                    'timestamp': timestamp,
                    'open': fp_bar['open'],
                    'close': fp_bar['close'],
                    'high': fp_bar['high'],
                    'low': fp_bar['low'],
                    'delta': delta,
                    'delta_pct': delta_pct,
                    'volume': total_volume,
                    'price_change': price_change,
                    'future_change': avg_future_change,
                    'ask': fp_bar.get('ask', 0),
                    'bid': fp_bar.get('bid', 0),
                })
            elif delta < 0 and avg_future_change > 0:
                # False positive sell: negative delta but price went up
                results['false_positive_sell'].append({
                    'time': datetime.fromtimestamp(timestamp).isoformat(),
                    'timestamp': timestamp,
                    'open': fp_bar['open'],
                    'close': fp_bar['close'],
                    'high': fp_bar['high'],
                    'low': fp_bar['low'],
                    'delta': delta,
                    'delta_pct': delta_pct,
                    'volume': total_volume,
                    'price_change': price_change,
                    'future_change': avg_future_change,
                    'ask': fp_bar.get('ask', 0),
                    'bid': fp_bar.get('bid', 0),
                })
            elif delta > 0 and avg_future_change > 0:
                results['true_positive_buy'].append({
                    'time': datetime.fromtimestamp(timestamp).isoformat(),
                    'timestamp': timestamp,
                    'delta_pct': delta_pct,
                    'future_change': avg_future_change,
                })
            elif delta < 0 and avg_future_change < 0:
                results['true_positive_sell'].append({
                    'time': datetime.fromtimestamp(timestamp).isoformat(),
                    'timestamp': timestamp,
                    'delta_pct': delta_pct,
                    'future_change': avg_future_change,
                })

    results['total_bars'] = len(broker_data)
    return results

def analyze_patterns(false_positives):
    """Analyze patterns in false positives."""
    if not false_positives:
        return {}

    patterns = {
        'avg_delta_pct': statistics.mean([fp['delta_pct'] for fp in false_positives]),
        'avg_volume': statistics.mean([fp['volume'] for fp in false_positives]),
        'avg_future_change': statistics.mean([fp['future_change'] for fp in false_positives]),
        'max_delta_pct': max([fp['delta_pct'] for fp in false_positives]),
        'min_delta_pct': min([fp['delta_pct'] for fp in false_positives]),
        'delta_range': max([fp['delta_pct'] for fp in false_positives]) - min([fp['delta_pct'] for fp in false_positives]),
        'count': len(false_positives),
    }

    # Analyze time patterns
    hours = [datetime.fromtimestamp(fp['timestamp']).hour for fp in false_positives]
    patterns['common_hours'] = statistics.mode(hours) if hours else None

    # Analyze price range patterns
    price_ranges = [fp['high'] - fp['low'] for fp in false_positives]
    patterns['avg_price_range'] = statistics.mean(price_ranges) if price_ranges else 0

    # Analyze bid/ask ratios
    bid_ask_ratios = []
    for fp in false_positives:
        if fp['bid'] > 0:
            bid_ask_ratios.append(fp['ask'] / fp['bid'] if fp['bid'] > 0 else 0)
    patterns['avg_bid_ask_ratio'] = statistics.mean(bid_ask_ratios) if bid_ask_ratios else 0

    return patterns

def main():
    dates = ['2026-10-25', '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30']

    all_results = {
        'buy_false_positives': [],
        'sell_false_positives': [],
        'daily_summary': {}
    }

    print("Analyzing data from the last week...")
    print("=" * 80)

    for date in dates:
        print(f"\nAnalyzing {date}...")
        broker_data, footprint_data = load_data_for_date(date)

        if not broker_data:
            print(f"  No broker data for {date}")
            continue

        results = analyze_false_positives(broker_data, footprint_data)

        print(f"  Total bars: {results['total_bars']}")
        print(f"  Paired bars: {results['paired_bars']}")
        print(f"  False positive buys (delta > 0, price ↓): {len(results['false_positive_buy'])}")
        print(f"  False positive sells (delta < 0, price ↑): {len(results['false_positive_sell'])}")
        print(f"  True positive buys: {len(results['true_positive_buy'])}")
        print(f"  True positive sells: {len(results['true_positive_sell'])}")

        all_results['buy_false_positives'].extend(results['false_positive_buy'])
        all_results['sell_false_positives'].extend(results['false_positive_sell'])

        all_results['daily_summary'][date] = {
            'total_bars': results['total_bars'],
            'paired_bars': results['paired_bars'],
            'false_positive_buy': len(results['false_positive_buy']),
            'false_positive_sell': len(results['false_positive_sell']),
            'true_positive_buy': len(results['true_positive_buy']),
            'true_positive_sell': len(results['true_positive_sell']),
        }

    print("\n" + "=" * 80)
    print("OVERALL SUMMARY")
    print("=" * 80)

    total_fp_buy = len(all_results['buy_false_positives'])
    total_fp_sell = len(all_results['sell_false_positives'])
    total_fp = total_fp_buy + total_fp_sell

    print(f"\nTotal false positive buys (heavy buying, price fell): {total_fp_buy}")
    print(f"Total false positive sells (heavy selling, price rose): {total_fp_sell}")
    print(f"Total false positives: {total_fp}")

    if total_fp_buy > 0:
        print("\n" + "-" * 80)
        print("FALSE POSITIVE BUY PATTERNS (Heavy buying, price fell)")
        print("-" * 80)
        buy_patterns = analyze_patterns(all_results['buy_false_positives'])
        for key, value in buy_patterns.items():
            print(f"  {key}: {value}")

        # Show top 10 most significant false positive buys
        print("\nTop 10 most significant false positive buys:")
        sorted_fp = sorted(all_results['buy_false_positives'], key=lambda x: abs(x['delta_pct']), reverse=True)[:10]
        for i, fp in enumerate(sorted_fp, 1):
            print(f"  {i}. {fp['time']} - Delta: {fp['delta_pct']:.2f}%, Future change: {fp['future_change']:.2f}, Volume: {fp['volume']}")

    if total_fp_sell > 0:
        print("\n" + "-" * 80)
        print("FALSE POSITIVE SELL PATTERNS (Heavy selling, price rose)")
        print("-" * 80)
        sell_patterns = analyze_patterns(all_results['sell_false_positives'])
        for key, value in sell_patterns.items():
            print(f"  {key}: {value}")

        # Show top 10 most significant false positive sells
        print("\nTop 10 most significant false positive sells:")
        sorted_fp = sorted(all_results['sell_false_positives'], key=lambda x: abs(x['delta_pct']), reverse=True)[:10]
        for i, fp in enumerate(sorted_fp, 1):
            print(f"  {i}. {fp['time']} - Delta: {fp['delta_pct']:.2f}%, Future change: {fp['future_change']:.2f}, Volume: {fp['volume']}")

    # Save detailed results
    output_path = Path("/var/www/FX_Proj/research/opportunity-audit/false_positive_analysis.json")
    output_path.parent.mkdir(parents=True, exist_ok=True)

    with open(output_path, 'w') as f:
        json.dump(all_results, f, indent=2)

    print(f"\nDetailed results saved to: {output_path}")

if __name__ == "__main__":
    main()
