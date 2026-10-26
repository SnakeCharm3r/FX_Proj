#!/usr/bin/env bash
set -euo pipefail

service_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ "$service_root" != /var/www/FX_Proj ]]; then
    echo 'These units are configured for /var/www/FX_Proj; review their paths before installing elsewhere.' >&2
    exit 1
fi
user_config_dir="${XDG_CONFIG_HOME:-$HOME/.config}"
mkdir -p "$user_config_dir/systemd/user" "$user_config_dir/autostart"
for unit in "$service_root"/ops/systemd/user/goldflow.* "$service_root"/ops/systemd/user/goldflow-*.service; do
    install -m 0644 "$unit" "$user_config_dir/systemd/user/"
done
install -m 0644 "$service_root/ops/autostart/goldflow-mt5.desktop" "$user_config_dir/autostart/"
systemctl --user daemon-reload
systemctl --user disable --now goldflow-mt5.service
systemctl --user enable goldflow.target
echo 'Installed backend startup units. MT5 automatic startup and restarting are disabled; launch MT5 manually when needed.'
