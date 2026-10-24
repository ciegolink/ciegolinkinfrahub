#!/usr/bin/env bash
# Installs the SSH gateway for the Infrastructure Hub terminal (Debian/Ubuntu, run as root).
# Usage: sudo bash ssh-gateway/setup.sh https://ciegolinkinfrahub.example.com
set -euo pipefail
ORIGIN="${1:-}"
[[ $EUID -eq 0 ]] || { echo "Run as root (sudo)."; exit 1; }
[[ "$ORIGIN" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || { echo "Usage: sudo bash ssh-gateway/setup.sh https://your-hub-hostname"; exit 1; }
DIR="$(cd "$(dirname "$0")" && pwd)"
apt-get install -y python3-venv >/dev/null
id ciegolinkinfrahub-ssh >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin ciegolinkinfrahub-ssh
python3 -m venv "$DIR/venv"
"$DIR/venv/bin/pip" install -q --upgrade pip
"$DIR/venv/bin/pip" install -q -r "$DIR/requirements.txt"
install -d -m 700 -o ciegolinkinfrahub-ssh -g ciegolinkinfrahub-ssh "$DIR/state"
if [[ ! -f "$DIR/gateway.env" ]]; then
  SECRET="$(openssl rand -hex 32)"
  umask 077
  printf 'GATEWAY_SECRET=%s\nGATEWAY_HOST=127.0.0.1\nGATEWAY_PORT=8022\nGATEWAY_ORIGIN=%s\nGATEWAY_KNOWN_HOSTS=%s/state/known_hosts.json\nGATEWAY_IDLE_SECONDS=1800\nGATEWAY_MAX_SECONDS=28800\nGATEWAY_MAX_SESSIONS=10\n' "$SECRET" "$ORIGIN" "$DIR" > "$DIR/gateway.env"
  chown root:ciegolinkinfrahub-ssh "$DIR/gateway.env"; chmod 640 "$DIR/gateway.env"
fi
sed "s|@DIR@|$DIR|g" "$DIR/ciegolinkinfrahub-ssh-gateway.service" > /etc/systemd/system/ciegolinkinfrahub-ssh-gateway.service
systemctl daemon-reload
systemctl enable --now ciegolinkinfrahub-ssh-gateway
a2enmod proxy proxy_wstunnel >/dev/null
SECRET="$(grep '^GATEWAY_SECRET=' "$DIR/gateway.env" | cut -d= -f2)"
cat <<MSG

SSH gateway is running (systemctl status ciegolinkinfrahub-ssh-gateway).

1) Add this line to config/config.php inside the returned array:

    'ssh_gateway' => ['secret' => '$SECRET'],

2) Add to the HTTPS vHost of the hub and reload Apache:

    ProxyPass        /ssh-gateway/ws ws://127.0.0.1:8022/ws
    ProxyPassReverse /ssh-gateway/ws ws://127.0.0.1:8022/ws

   sudo apache2ctl configtest && sudo systemctl reload apache2

3) Reload the hub as administrator: devices now show a "Terminal" button.
MSG
