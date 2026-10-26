#!/usr/bin/env bash
set -euo pipefail

session_names=()
for name in DISPLAY XAUTHORITY WAYLAND_DISPLAY XDG_SESSION_TYPE XDG_CURRENT_DESKTOP; do
    if [[ -n "${!name:-}" ]]; then session_names+=("$name"); fi
done
if ((${#session_names[@]})); then
    /usr/bin/systemctl --user import-environment "${session_names[@]}"
fi
/usr/bin/systemctl --user start goldflow.target
