"""Forward local MT5 FILE_COMMON snapshots to the authenticated app API.
No cTrader connection or subscription credentials are used.
"""
import argparse
import json
import logging
import time
from pathlib import Path
import urllib.request
import urllib.error
import re


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--directory', type=Path, default=Path('/home/simon/.mt5/drive_c/users/simon/AppData/Roaming/MetaQuotes/Terminal/Common/Files'))
    args = parser.parse_args()
    config = {}
    for line in (Path(__file__).resolve().parents[1] / 'laravel-app/.env').read_text().splitlines():
        match = re.match(r'^(APP_URL|CLUSTERDELTA_BRIDGE_TOKEN)=(.*)$', line)
        if match:
            config[match[1]] = match[2].strip().strip('\"\'')
    token = config.get('CLUSTERDELTA_BRIDGE_TOKEN')
    if not token:
        raise SystemExit('Configure CLUSTERDELTA_BRIDGE_TOKEN in laravel-app/.env')
    endpoint_base = config.get('APP_URL', 'http://localhost:8003').rstrip('/') + '/api/clusterdelta'
    seen = {}
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(message)s')
    while True:
        for path in list(args.directory.glob('FXClusterDelta_*.json')) + list(args.directory.glob('FXFootprint_*.json')) + list(args.directory.glob('FXBrokerHistory_*.json')):
            try:
                stamp = path.stat().st_mtime_ns
                if seen.get(path) == stamp:
                    continue
                # Ignore stale snapshots from a stopped terminal.
                if time.time() - path.stat().st_mtime > 60:
                    continue
                payload = json.loads(path.read_text(encoding='utf-8-sig'))
                kind = 'history' if path.name.startswith('FXBrokerHistory_') else ('footprints' if path.name.startswith('FXFootprint_') else 'bars')
                endpoint = endpoint_base + '/' + kind
                request = urllib.request.Request(endpoint, data=json.dumps(payload).encode(),
                    headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json', 'Accept': 'application/json'})
                with urllib.request.urlopen(request, timeout=10) as response:
                    response.read()
                seen[path] = stamp
                logging.info('Accepted %s %s %s: %s bars', kind, payload['instrument'], payload['tf'], len(payload['bars']))
            except urllib.error.HTTPError as exc:
                logging.warning('Snapshot not accepted: HTTP %s (%s)', exc.code, path.name)
            except (OSError, ValueError, KeyError, TypeError, urllib.error.URLError) as exc:
                logging.warning('Snapshot not forwarded (%s)', type(exc).__name__)
        if args.once:
            return
        time.sleep(2)


if __name__ == '__main__':
    main()
