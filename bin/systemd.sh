#!/usr/bin/env bash
# One file per site. Run it once: it installs a systemd --user service that keeps
# `php artisan serve` running (starts at boot, restarts on crash).
#
#   ./sites/arta.sh            # install + start + enable at boot
#   ./sites/arta.sh --remove   # stop + remove the service
#
# To add another site: copy this file, change NAME / DIR / PORT / ENV_VARS.
set -euo pipefail

# ---------- per-site settings ----------
NAME="arta-backend"
DIR="/home/javad/dev/arta-backend"
PORT="8000"
PHP="/usr/bin/php"          # host PHP (NOT the docker shim)
ENV_VARS=(
  "DB_HOST=127.0.0.1"
  "DB_PORT=3306"
  "REDIS_HOST=127.0.0.1"
  "REDIS_PORT=6370"
  "CACHE_STORE=file"
  "SESSION_DRIVER=file"
  "QUEUE_CONNECTION=sync"
)
# ---------------------------------------

UNIT_DIR="$HOME/.config/systemd/user"
UNIT="$UNIT_DIR/$NAME.service"

if [ "${1:-}" = "--remove" ]; then
  systemctl --user disable --now "$NAME.service" 2>/dev/null || true
  rm -f "$UNIT"
  systemctl --user daemon-reload
  echo "removed $NAME.service"
  exit 0
fi

[ -d "$DIR" ] || { echo "no such project dir: $DIR" >&2; exit 1; }
[ -x "$PHP" ] || { echo "php not found: $PHP" >&2; exit 1; }

mkdir -p "$UNIT_DIR"
{
  echo "[Unit]"
  echo "Description=$NAME (php artisan serve on 127.0.0.1:$PORT)"
  echo "After=network.target"
  echo ""
  echo "[Service]"
  echo "Type=simple"
  echo "WorkingDirectory=$DIR"
  for kv in "${ENV_VARS[@]}"; do echo "Environment=$kv"; done
  echo "ExecStart=$PHP artisan serve --host=127.0.0.1 --port=$PORT"
  echo "Restart=always"
  echo "RestartSec=2"
  echo ""
  echo "[Install]"
  echo "WantedBy=default.target"
} > "$UNIT"

systemctl --user daemon-reload
systemctl --user enable --now "$NAME.service"
# keep user services running even before login
loginctl enable-linger "$USER" 2>/dev/null || true

sleep 1
systemctl --user --no-pager --lines=0 status "$NAME.service" >/dev/null || true
echo "service: $NAME.service  (systemd --user, enabled at boot, Restart=always)"
echo "url:     http://localhost:$PORT   (via nginx :5000 for arta)"
echo "logs:    journalctl --user -u $NAME.service -f"
