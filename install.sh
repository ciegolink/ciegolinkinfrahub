#!/usr/bin/env bash
# CiegoLink Infrastructure Hub - installer
# Usage: sudo bash install.sh
set -euo pipefail
cd "$(dirname "$0")"

say()  { printf '%s\n' "$*"; }
fail() { printf '\nError: %s\n' "$*" >&2; exit 1; }
ask()  { local q="$1" def="${2:-}" a; if [ -n "$def" ]; then read -r -p "$q [$def]: " a; else read -r -p "$q: " a; fi; printf '%s' "${a:-$def}"; }

command -v docker >/dev/null || fail "Docker is not installed."
docker compose version >/dev/null 2>&1 || fail "Docker Compose plugin is missing (docker compose)."
docker info >/dev/null 2>&1 || fail "No access to Docker. Run with sudo or add your user to the docker group."
command -v openssl >/dev/null || fail "openssl is missing (sudo apt install openssl)."

say ""
say "=== CiegoLink Infrastructure Hub - Installation ==="
say ""

# existing installation?
if docker volume inspect ciegolinkinfrahub_data >/dev/null 2>&1; then
  say "Warning: an installation already exists (volume ciegolinkinfrahub_data)."
  say "The administrator is only created on an empty installation."
  [ "$(ask 'Continue anyway? (y/n)' 'n')" = "y" ] || exit 0
fi

KEEP_ENV=n
if [ -f .env ]; then
  KEEP_ENV="$(ask '.env already exists. Keep it? (y/n)' 'y')"
fi

if [ "$KEEP_ENV" != "y" ]; then
  while :; do
    URL="$(ask 'URL (https://...)')"
    URL="${URL%/}"
    [[ "$URL" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] && break
    say "  Please enter the full address with https://, e.g. https://hub.ciegolink.ch"
  done
  DEF_HOST="${URL#https://}"; DEF_HOST="${DEF_HOST%%:*}"
  while :; do
    HOST="$(ask 'Hostname (without https://)' "$DEF_HOST")"
    [[ "$HOST" =~ ^[A-Za-z0-9.-]+$ ]] && break
    say "  Hostname only, e.g. hub.ciegolink.ch"
  done
  ADMIN="$(ask 'Administrator username' 'admin')"
  [[ "$ADMIN" =~ ^[A-Za-z0-9._@-]{3,80}$ ]] || fail "Invalid username (3-80 characters: A-Z a-z 0-9 . _ @ -)."

  # web server on 80/443 -> no Caddy
  BUSY=n
  if command -v ss >/dev/null && ss -ltnH '( sport = :80 or sport = :443 )' 2>/dev/null | grep -q .; then BUSY=y; fi
  if [ "$BUSY" = "y" ]; then
    say "Port 80/443 is in use (Apache/nginx) - the hub runs behind your web server on 127.0.0.1:18438."
    PROFILES=""
  else
    CADDY="$(ask 'No web server found. Use Caddy for automatic HTTPS? (y/n)' 'y')"
    PROFILES=""; [ "$CADDY" = "y" ] && PROFILES="caddy"
  fi

  umask 077
  cat > .env <<EOF
# Created by install.sh on $(date '+%Y-%m-%d %H:%M')
PUBLIC_URL=$URL
PUBLIC_HOST=$HOST
GATEWAY_SECRET=$(openssl rand -hex 32)
TZ=Europe/Zurich
HUB_PORT=18438
SOFTWARE_MAX_MB=2048
INFRA_ADMIN_USER=$ADMIN
INFRA_ADMIN_PASSWORD=
COMPOSE_PROFILES=$PROFILES
EOF
  chmod 600 .env
  say ".env created."
fi

URL="$(grep -E '^PUBLIC_URL=' .env | cut -d= -f2-)"
HOST="$(grep -E '^PUBLIC_HOST=' .env | cut -d= -f2-)"
PORT="$(grep -E '^HUB_PORT=' .env | cut -d= -f2-)"; PORT="${PORT:-18438}"
PROFILES="$(grep -E '^COMPOSE_PROFILES=' .env | cut -d= -f2- || true)"

say ""
say "Building and starting the containers (the first time takes a few minutes) ..."
docker compose up -d --build

# wait for healthy
CID="$(docker compose ps -q hub)"
for _ in $(seq 1 90); do
  STATE="$(docker inspect -f '{{.State.Health.Status}}' "$CID" 2>/dev/null || echo starting)"
  [ "$STATE" = "healthy" ] && break
  [ "$STATE" = "unhealthy" ] && fail "The hub does not start. Details: docker compose logs hub"
  sleep 2
done
[ "${STATE:-}" = "healthy" ] || fail "Timeout. Details: docker compose logs hub"

PW="$(docker compose logs --no-log-prefix hub 2>/dev/null | sed -n 's/^ *Temporary password: *//p' | tail -1)"
USER_NAME="$(docker compose logs --no-log-prefix hub 2>/dev/null | sed -n 's/^ *Username: *//p' | tail -1)"

# Apache vhost (without Caddy)
if [ "$PROFILES" != "caddy" ]; then
  cat > apache-ciegolinkinfrahub.conf <<EOF
<VirtualHost *:80>
    ServerName $HOST
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</VirtualHost>

<IfFile /etc/letsencrypt/live/$HOST/fullchain.pem>
<VirtualHost *:443>
    ServerName $HOST
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/$HOST/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/$HOST/privkey.pem

    ProxyRequests     Off
    ProxyPreserveHost On
    RequestHeader set X-Forwarded-Proto "https"
    LimitRequestBody 2202009600
    ProxyTimeout 3600

    ProxyPass        /ssh-gateway/ws ws://127.0.0.1:$PORT/ssh-gateway/ws
    ProxyPassReverse /ssh-gateway/ws ws://127.0.0.1:$PORT/ssh-gateway/ws
    ProxyPass        /               http://127.0.0.1:$PORT/
    ProxyPassReverse /               http://127.0.0.1:$PORT/
</VirtualHost>
</IfFile>
EOF
fi

say ""
say "================================================================"
say " CiegoLink Infrastructure Hub is running."
say ""
if [ -n "$PW" ]; then
  say " One-time admin password"
  say "   Username: ${USER_NAME:-admin}"
  say "   Password: $PW"
  say ""
  say " Valid for the first sign-in only (leave the 2FA field empty)."
  say " Then set your own password and 2FA."
else
  say " No new administrator was created (existing installation)."
  say " Reset a password:"
  say "   docker compose exec -it -u www-data hub php /var/www/ciegolinkinfrahub/bin/install.php reset-user"
fi
say "================================================================"
say ""
if [ "$PROFILES" = "caddy" ]; then
  say "Caddy handles HTTPS automatically. Open: $URL"
else
  say "Set up Apache (apache-ciegolinkinfrahub.conf was created for $HOST):"
  say "  sudo a2enmod ssl proxy proxy_http proxy_wstunnel headers rewrite"
  say "  sudo cp apache-ciegolinkinfrahub.conf /etc/apache2/sites-available/ciegolinkinfrahub.conf"
  say "  sudo a2ensite ciegolinkinfrahub && sudo apache2ctl configtest && sudo systemctl reload apache2"
  say "  sudo certbot certonly --apache -d $HOST && sudo systemctl reload apache2"
  say "Then open: $URL"
fi
say ""
say "Important: back up config.php with the encryption key (INSTALL.md, section 10)."
