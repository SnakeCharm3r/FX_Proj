# Background startup

GoldFlow runs at http://localhost:8003. Its web server binds to this computer's loopback interface. Reverb uses local port 8080.

The installed systemd user target `goldflow.target` starts the web server, ClusterDelta forwarder, Reverb and orderflow watcher. User lingering is enabled for Simon, so these services start at boot before desktop login and continue after logout. MySQL (through the existing XAMPP service) and Redis are already enabled at boot. Services retry after failure, including while their dependencies are starting.

MT5 automatic startup and restarting are disabled. Launch MetaTrader 5 manually when needed and close it normally when finished. The optional `goldflow-mt5.service` uses `Restart=no` and is disabled. The desktop startup entry starts only the backend target.

Keep the bridge and footprint indicator attached, DLL imports enabled, and save the desired chart profile. The backends cannot receive new MT5 data while the terminal is closed.

## Controls

Run these as Simon:

```bash
systemctl --user status 'goldflow-*.service'
systemctl --user restart goldflow.target
systemctl --user stop goldflow.target
systemctl --user start goldflow.target
journalctl --user -u goldflow-forwarder.service -n 50 --no-pager
journalctl --user -u goldflow-web.service -f
```

The target controls the four backend services. MT5 is launched manually and is independent of backend restarts.

After changing Laravel `.env`, clear cached configuration and restart the backend services:

```bash
cd /var/www/FX_Proj/laravel-app
/opt/lampp/bin/php artisan config:clear
systemctl --user restart goldflow.target
```

## Installation

Definitions are in `ops/systemd/user`. Run `ops/goldflow/install-user-services.sh` to reinstall them for this workspace. The installer enables the backend target at boot and stops/disables the MT5 service; `ops/autostart/goldflow-mt5.desktop` imports the current display environment and starts only the backend target at login. User lingering is a separate administrator setting: `sudo loginctl enable-linger simon`.

Stop any previous manually started project services before starting the target. Shared file locks prevent duplicate web, forwarder, Reverb and watcher instances. Do not run additional terminal copies alongside the services.

## Verification on 22 October 2026

All five services were running, both startup units were enabled, and `loginctl` reported `Linger=yes`. Dashboard, Setups and health endpoints responded on port 8003. Fresh H1 bridge exports reached the application and the setup detector evaluated matching footprint data. M15 was stale because the active export was H1. A reboot was not performed during installation.

## MT5 startup disabled on 26 October 2026

The previous MT5 unit used `Restart=always`, `RestartSec=10`, and unlimited restart attempts, including after a normal terminal exit. It was enabled for the graphical session and also explicitly started by the desktop login script. Both startup paths are now disabled for MT5, and its restart policy is `no`.
