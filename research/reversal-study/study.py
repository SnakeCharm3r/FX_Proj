"""Price-only H1 hypothesis study. No orders, alerts, or claims of strategy returns."""
import argparse
import hashlib
import json
import math
import statistics
from pathlib import Path

VERSION = 'h1-rejection-study-v1'
HORIZONS = (1, 4, 12)


def load_bars(document):
    bars = document['candles']
    last_closed = document.get('stats', {}).get('last_bar_closed', True)
    bars = [dict(b) for i, b in enumerate(bars) if b.get('closed', last_closed if i == len(bars)-1 else True)]
    previous = None
    for b in bars:
        if not isinstance(b['time'], int) or b['time'] % 3600 or (previous is not None and b['time'] <= previous):
            raise ValueError('Expected distinct chronological UTC H1 candles')
        for k in ('open', 'high', 'low', 'close'):
            if not isinstance(b[k], (int, float)) or not math.isfinite(b[k]) or b[k] <= 0:
                raise ValueError('Invalid OHLC price')
        if b['high'] < max(b['open'], b['close'], b['low']) or b['low'] > min(b['open'], b['close']):
            raise ValueError('Invalid OHLC range')
        previous = b['time']
    return bars


def prior_atr(bars, index, period=14):
    if index < period:
        return None
    ranges = []
    for i in range(index-period, index):
        b = bars[i]
        previous = bars[i-1]['close'] if i else b['open']
        ranges.append(max(b['high']-b['low'], abs(b['high']-previous), abs(b['low']-previous)))
    return statistics.mean(ranges)


def candidates(bars):
    """Events use only data through signal_index. Future outcomes are computed separately."""
    events = []
    for i in range(20, len(bars)):
        b = bars[i]
        span = b['high']-b['low']
        atr = prior_atr(bars, i)
        if not atr or span <= 0:
            continue
        context = bars[i-20:i]
        lower = (min(b['open'], b['close'])-b['low'])/span
        upper = (b['high']-max(b['open'], b['close']))/span
        position = (b['close']-b['low'])/span
        sides = []
        if b['low'] < min(p['low'] for p in context) and lower >= .5 and position >= .75:
            sides.append('BUY')
        if b['high'] > max(p['high'] for p in context) and upper >= .5 and position <= .25:
            sides.append('SELL')
        for side in sides:
            base = {'origin_index': i, 'origin_time': b['time'], 'direction': side, 'atr': atr}
            events.append(base | {'rule': 'rejection_only', 'signal_index': i})
            # Freeze one of two subsequent outcomes: confirmed break away, or failed rejection.
            for j in range(i+1, min(i+4, len(bars))):
                if bars[j]['time'] != bars[j-1]['time']+3600:
                    break  # Do not bridge an unobserved/session gap when confirming a pattern.
                close = bars[j]['close']
                failed = close < b['low'] if side == 'BUY' else close > b['high']
                followed = close > b['high'] if side == 'BUY' else close < b['low']
                if failed:
                    events.append(base | {'rule': 'failed_rejection', 'direction': 'SELL' if side == 'BUY' else 'BUY', 'signal_index': j})
                    break
                if followed and j <= i+2:
                    events.append(base | {'rule': 'rejection_followthrough', 'signal_index': j})
                    break
    return events


def outcomes(bars, event, horizon):
    """Reference entry at the next available H1 open; intervals are trading bars, not elapsed hours."""
    start = event['signal_index']+1
    end = start+horizon
    if end > len(bars):
        return None
    entry = bars[start]['open']
    path = bars[start:end]
    sign = 1 if event['direction'] == 'BUY' else -1
    atr = event['atr']
    favorable = max((b['high']-entry if sign == 1 else entry-b['low'])/atr for b in path)
    adverse = max((entry-b['low'] if sign == 1 else b['high']-entry)/atr for b in path)
    first = 'neither'
    for b in path:
        up = (b['high']-entry if sign == 1 else entry-b['low'])/atr >= 2
        down = (entry-b['low'] if sign == 1 else b['high']-entry)/atr >= 1
        if up or down:
            first = 'ambiguous' if up and down else ('+2ATR' if up else '-1ATR')
            break
    return {'entry_time': bars[start]['time'], 'entry_reference': entry,
            'forward_change_atr': sign*(path[-1]['close']-entry)/atr,
            'mfe_atr': max(0, favorable), 'mae_atr': max(0, adverse), 'first_excursion': first,
            'elapsed_seconds': path[-1]['time']+3600-bars[start]['time']}


def study(document):
    bars = load_bars(document)
    if len(bars) < 40:
        raise ValueError('Need at least 40 completed H1 candles')
    split = int(len(bars)*.7)
    events = candidates(bars)
    groups, detailed = {}, []
    for event in events:
        signal = event['signal_index']
        start = signal+1
        partition = 'development' if start < split else 'later_period'
        for horizon in HORIZONS:
            # Embargo development observations whose outcome reaches the held-out period.
            embargo = partition == 'development' and start+horizon > split
            result = None if embargo else outcomes(bars, event, horizon)
            key = (partition, event['rule'], event['direction'], horizon)
            group = groups.setdefault(key, {'results': [], 'censored': 0, 'embargoed': 0})
            if embargo:
                group['embargoed'] += 1
            elif result is None:
                group['censored'] += 1
            else:
                group['results'].append(result)
            detailed.append(event | {'signal_time':bars[signal]['time'], 'partition':partition,
                                     'horizon_bars':horizon, 'embargoed':embargo, 'outcome':result})
    summary = []
    for (partition, rule, direction, horizon), group in sorted(groups.items()):
        rows = group['results']
        summary.append({'partition':partition, 'rule':rule, 'direction':direction, 'horizon_bars':horizon,
            'n':len(rows), 'censored':group['censored'], 'embargoed':group['embargoed'],
            'median_change_atr':statistics.median(r['forward_change_atr'] for r in rows) if rows else None,
            'median_mae_atr':statistics.median(r['mae_atr'] for r in rows) if rows else None,
            'first_2atr':sum(r['first_excursion']=='+2ATR' for r in rows),
            'first_minus_1atr':sum(r['first_excursion']=='-1ATR' for r in rows),
            'ambiguous':sum(r['first_excursion']=='ambiguous' for r in rows)})
    return {'version':VERSION, 'mode':'price_only_diagnostic', 'closed_bars':len(bars),
            'first_time':bars[0]['time'], 'last_time':bars[-1]['time'], 'later_period_from':bars[split]['time'],
            'untouched_holdout':False, 'independent_events':False, 'costs_included':False, 'footprint_tested':False,
            'summary':summary, 'events':detailed}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    raw = args.input.read_bytes()
    report = study(json.loads(raw))
    report['input_sha256'] = hashlib.sha256(raw).hexdigest()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2)+'\n')
    print(json.dumps({k:report[k] for k in ['version','closed_bars','later_period_from','mode']}))
    for row in report['summary']:
        if row['horizon_bars'] == 12:
            print(json.dumps(row))


if __name__ == '__main__':
    main()
