"""Keep the existing Wine MT5 session; launch it when none exists in this prefix."""
import os
import time
from pathlib import Path


def existing_terminal(prefix):
    for process in Path('/proc').glob('[0-9]*'):
        try:
            if process.stat().st_uid != os.getuid():
                continue
            names = [part.decode(errors='replace').replace('\\', '/').rsplit('/', 1)[-1].lower()
                     for part in (process / 'cmdline').read_bytes().split(b'\0')[:3]]
            if (process / 'comm').read_text().strip().lower() != 'terminal64.exe' and 'terminal64.exe' not in names:
                continue
            environment = dict(item.split(b'=', 1) for item in (process / 'environ').read_bytes().split(b'\0') if b'=' in item)
            actual = environment.get(b'WINEPREFIX')
            if actual and Path(os.fsdecode(actual)).resolve() == prefix:
                return int(process.name)
        except (OSError, ValueError):
            continue
    return None


def main():
    prefix = Path(os.environ.get('WINEPREFIX', str(Path.home() / '.mt5'))).resolve()
    terminal = prefix / 'drive_c/Program Files/MetaTrader 5/terminal64.exe'
    if not terminal.is_file():
        raise SystemExit('MT5 executable is missing from the configured Wine prefix.')
    pid = existing_terminal(prefix)
    if pid:
        print(f'Preserving existing MT5 session (PID {pid}); no chart changes.', flush=True)
        # Never restart or detach the currently running chart to adopt its process.
        while existing_terminal(prefix) is not None:
            time.sleep(5)
    print('Starting MT5 using its existing Wine profile and saved charts.', flush=True)
    os.execv('/usr/bin/wine', ['/usr/bin/wine', str(terminal)])


if __name__ == '__main__':
    main()
