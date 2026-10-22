# Background startup

GoldFlow runs at http://localhost:8003. Its web server binds to this computer's loopback interface. Reverb uses local port 8080.

The installed systemd user target `goldflow.target` starts the web server, ClusterDelta forwarder, Reverb and orderflow watcher. User lingering is enabled for Simon, so these services start at boot before desktop login and continue after logout. MySQL (through the existing XAMPP service) and Redis are already enabled at boot. Services retry after failure, including while their dependencies are starting.

MT5 needs a graphical desktop and starts after Simon signs in. `goldflow-mt5.service` uses the existing `/home/simon/.mt5` Wine profile and saved charts. Its launcher preserves an already running terminal in that prefix. Keep the bridge and footprint indicator attached, DLL imports enabled, and save the desired chart profile. Starting the services does not add indicators or activate missing timeframes. No manual terminal windows are required.

The current terminal was preserved during installation; its process is monitored rather than replaced. A terminal launched by the service on a later login is managed directly by systemd. Signing out closes the graphical session; application services remain running but cannot receive new MT5 data until MT5 is available again.

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

The target controls the four backend services; MT5 belongs to the graphical session separately. Restarting the target leaves the terminal and charts in place. Use `systemctl --user stop goldflow-mt5.service` before intentionally closing a managed terminal to prevent its automatic relaunch. An existing manually launched terminal must be closed in MT5 itself.

After changing Laravel `.env`, clear cached configuration and restart the backend services:

```bash
cd /var/www/FX_Proj/laravel-app
/opt/lampp/bin/php artisan config:clear
systemctl --user restart goldflow.target
```

## Installation

Definitions are in `ops/systemd/user`. Run `ops/goldflow/install-user-services.sh` to reinstall them for this workspace. The installer enables the backend target at boot and MT5 with the desktop session; `ops/autostart/goldflow-mt5.desktop` imports the current display environment at login. User lingering is a separate administrator setting: `sudo loginctl enable-linger simon`.

Stop any previous manually started project services before starting the target. Shared file locks prevent duplicate web, forwarder, Reverb and watcher instances. Do not run additional terminal copies alongside the services.

## Verification on 22 October 2026

All five services were running, both startup units were enabled, and `loginctl` reported `Linger=yes`. Dashboard, Setups and health endpoints responded on port 8003. Fresh H1 bridge exports reached the application and the setup detector evaluated matching footprint data. M15 was stale because the active export was H1. A reboot was not performed during installation.
