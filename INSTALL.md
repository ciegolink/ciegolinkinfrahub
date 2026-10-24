# Installation (Docker)

The Infrastructure Hub runs as Docker containers. Everything the old manual installation needed (PHP packages, Apache vHost, file permissions, upload limits, SSH gateway service, systemd) is already built into the images.
Without Docker (e.g. shared hosting): see `INSTALL-CLASSIC.md`. Updates: `UPDATE.md`.

Placeholders: `hub.example.com` = your hostname, `/opt/ciegolinkinfrahub` = folder with the project files.

## 1. Requirements

| | Required | Notes |
|---|---|---|
| Server | Linux with Docker Engine 24+ and the Compose plugin | Check with `docker compose version`. |
| Hostname | e.g. `hub.example.com` | DNS points to the server. |
| HTTPS | reverse proxy in front | Usually the web server already running on the host (Apache, nginx); only on a server without web server the included Caddy (section 4). The hub refuses to work without HTTPS. |
| Clock | synchronised (NTP) on the host | Required for 2FA codes. |
| Network | the host reaches your devices | For the SSH terminal and the status check, e.g. via VPN/WireGuard on the host. |

## 2. What runs

| Container | Purpose | Port |
|---|---|---|
| `hub` | Web application (PHP 8.3 + Apache), maintenance API | `127.0.0.1:18438` on the host |
| `ssh-gateway` | SSH terminal for administrators | internal only |
| `caddy` (optional, profile `caddy`) | Automatic HTTPS - only if no web server runs on the host | 80, 443 |

Data lives in Docker volumes and survives every update:

| Volume | Content |
|---|---|
| `ciegolinkinfrahub_config` | `config.php` with the **encryption key** (created on first start) |
| `ciegolinkinfrahub_data` | Database, encrypted attachments, software library, sessions |
| `ciegolinkinfrahub_gateway` | Trusted SSH host keys |

The device status check uses ICMP ping (unprivileged, no extra container rights). If a device does not answer ICMP, nothing else is tried for that address; if ICMP is not available at all, the hub falls back to a TCP connection to the device's web control port (default 443).

## 3. Install with the script (recommended)

Copy the project folder to the server, e.g. `/opt/ciegolinkinfrahub`, then:

```bash
cd /opt/ciegolinkinfrahub
sudo bash install.sh
```

Always start it with `bash` (not `./install.sh`). The script asks for the URL (`https://...`), the hostname without `https://` and the admin username. It creates `.env` with a random gateway secret, detects whether Apache/nginx already uses port 80/443 (then no Caddy), builds and starts the containers, waits until the hub is ready and shows:

```
 One-time admin password
   Username: admin
   Password: ...
```

Without Caddy it also writes `apache-ciegolinkinfrahub.conf` with your hostname and prints the Apache commands (section 6). Then continue with section 5, step 1. Sections 3a-4 describe the same steps by hand.

## 3a. Configure by hand

Copy the project folder to the server, e.g. `/opt/ciegolinkinfrahub`, then:

```bash
cd /opt/ciegolinkinfrahub
cp .env.example .env
openssl rand -hex 32        # copy the output into GATEWAY_SECRET
nano .env
```

| Variable | Example | Meaning |
|---|---|---|
| `PUBLIC_URL` | `https://hub.example.com` | Exactly the address in the browser, no trailing slash |
| `PUBLIC_HOST` | `hub.example.com` | Only for the Caddy profile (certificate) |
| `GATEWAY_SECRET` | 64 hex characters | Shared secret hub <-> SSH gateway, required |
| `TZ` | `Europe/Zurich` | Time zone for the maintenance API and logs |
| `HUB_PORT` | `18438` | Port on `127.0.0.1` for your reverse proxy |
| `SOFTWARE_MAX_MB` | `2048` | Max. file size in the software library (upper limit 2 GiB) |
| `INFRA_ADMIN_USER` | `admin` | First administrator, created on the very first start only |
| `INFRA_ADMIN_PASSWORD` | empty | Its temporary password (14-72 characters). Empty = random password, shown in the log |
| `COMPOSE_PROFILES` | empty or `caddy` | `caddy` only on a server without Apache/nginx (section 4, variant B) |

`chmod 600 .env` - it contains the gateway secret.

## 4. Start

Choose **one** of the two variants:

**A - a web server (Apache, nginx ...) already runs on the host** (normal case). It does HTTPS and forwards to the hub (section 6):

```bash
docker compose up -d --build
```

**B - no web server on the host** (ports 80 and 443 free and reachable from the internet). The included Caddy does HTTPS automatically; set `PUBLIC_HOST` in `.env`:

```bash
docker compose --profile caddy up -d --build
```

Never use variant B next to Apache or nginx: Caddy then fails with `failed to bind host port 0.0.0.0:80/tcp: address already in use` (section 14).

Check: `docker compose ps` shows `hub` and `ssh-gateway` as `healthy`. On the first start the log (`docker compose logs hub`) shows that `config.php` with a new key was created - **back it up now** (section 10).

## 5. First administrator and first sign-in

On the very first start the container creates the first administrator automatically (`INFRA_ADMIN_USER`, default `admin`). Without `INFRA_ADMIN_PASSWORD` a random temporary password is generated and shown once in the log:

```bash
docker compose logs hub | grep -A3 "first administrator"
```

The password is only temporary: at the first sign-in a new own password and 2FA are forced. Sign in right after the start, so nobody else can use it. If you set `INFRA_ADMIN_PASSWORD` in `.env`, remove it again after the first sign-in. Then:

1. Open `PUBLIC_URL` and sign in. Leave the 2FA field empty on the first sign-in.
2. Set your own password, add the setup key to an authenticator app (TOTP, 6 digits, 30 seconds) and confirm with the current code.
3. Create further users under your name (bottom left) > User management. Every user sets an own password and 2FA on first sign-in.
4. Start with a location, then add devices, IP addresses, racks and credentials.

Data from another installation can be imported as administrator under your name > Export & import.

## 6. Reverse proxy

The proxy must forward to `http://127.0.0.1:18438`, pass WebSockets on `/ssh-gateway/ws` (terminal), send `X-Forwarded-For` and allow request bodies up to 2.1 GB (software uploads).

### Apache

1. Enable modules:

   ```bash
   sudo a2enmod ssl proxy proxy_http proxy_wstunnel headers rewrite
   ```

2. Create `/etc/apache2/sites-available/ciegolinkinfrahub.conf` (replace `hub.example.com`):

   ```apache
   <VirtualHost *:80>
       ServerName hub.example.com
       RewriteEngine On
       RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
       RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
   </VirtualHost>

   <IfFile /etc/letsencrypt/live/hub.example.com/fullchain.pem>
   <VirtualHost *:443>
       ServerName hub.example.com
       SSLEngine on
       SSLCertificateFile    /etc/letsencrypt/live/hub.example.com/fullchain.pem
       SSLCertificateKeyFile /etc/letsencrypt/live/hub.example.com/privkey.pem

       ProxyRequests     Off
       ProxyPreserveHost On
       RequestHeader set X-Forwarded-Proto "https"
       LimitRequestBody 2202009600
       ProxyTimeout 3600

       # WebSocket for the SSH terminal - must come before "/"
       ProxyPass        /ssh-gateway/ws ws://127.0.0.1:18438/ssh-gateway/ws
       ProxyPassReverse /ssh-gateway/ws ws://127.0.0.1:18438/ssh-gateway/ws
       ProxyPass        /               http://127.0.0.1:18438/
       ProxyPassReverse /               http://127.0.0.1:18438/
   </VirtualHost>
   </IfFile>
   ```

   `<IfFile>` keeps Apache running before the certificate exists.

3. Enable the site and get the certificate:

   ```bash
   sudo a2ensite ciegolinkinfrahub
   sudo apache2ctl configtest && sudo systemctl reload apache2
   sudo certbot certonly --webroot -w /var/www/html -d hub.example.com
   sudo systemctl reload apache2
   ```

   If `/.well-known/` is not served from `/var/www/html` on your server, use `sudo certbot certonly --apache -d hub.example.com` instead. Renewal runs automatically; after a renewal Apache must be reloaded (certbot does this with `--deploy-hook "systemctl reload apache2"`).

4. Open `https://hub.example.com`. Apache adds `X-Forwarded-For` itself.

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name hub.example.com;
    # ssl_certificate ... / ssl_certificate_key ...

    client_max_body_size 2100m;

    location / {
        proxy_pass http://127.0.0.1:18438;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_request_buffering off;
        proxy_read_timeout 3600s;
    }
    location /ssh-gateway/ws {
        proxy_pass http://127.0.0.1:18438;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 3600s;
    }
}
```

### Traefik

Add labels to the `hub` service (router rule ``Host(`hub.example.com`)``, entrypoint `websecure`, your TLS resolver, service port `80`), put `hub` into the Traefik network and remove its `ports:` entry. WebSockets work without extra settings.

### Important

The container tells the app that the connection is HTTPS, because TLS ends at the proxy. That is why the port is bound to `127.0.0.1` only - never publish it on a public interface. Client IPs from `X-Forwarded-For` are only accepted from private networks (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.1`).

## 7. SSH terminal

Included and active: administrators see a **Terminal** button on devices. Nothing else to install. The login comes from the password manager (password, or a key file from the credential's Files with the password as passphrase) or is entered manually; the browser only gets a one-time ticket. The address must be one of the device's IPs. Sessions end after 30 minutes without input or 8 hours in total; at most 10 at the same time (see `docker-compose.yml`).

Not needed? Remove the `ssh-gateway` service and the `depends_on` block of `hub` from `docker-compose.yml` and remove `GATEWAY_SECRET` from `hub`. The Terminal button then disappears.

## 8. Software library

Upload limit: 2 GiB per file (PHP and Apache in the image are set accordingly), lower with `SOFTWARE_MAX_MB`. Your reverse proxy must allow the same size (`client_max_body_size` in nginx; the included Caddy is configured). Files are stored in the `data` volume; make sure the Docker disk has enough space.

## 9. Maintenance API

Read-only JSON API for **public** maintenance entries, e.g. for a status banner on a website or a check on another server. Administrators create a token per server under **Maintenance > API access**. The token is shown once; only its SHA-256 hash is stored. Revoking a token takes effect immediately.

Endpoint: `https://hub.example.com/maintenance.php`

Send the token in one of these ways:

```
curl -H "Authorization: Bearer ihm_..." https://hub.example.com/maintenance.php
curl -H "X-Api-Token: ihm_..." https://hub.example.com/maintenance.php
curl "https://hub.example.com/maintenance.php?token=ihm_..."
```

Parameters (all optional):

| Parameter | Values | Default |
|---|---|---|
| `scope` | `current` (in progress + planned), `active`, `upcoming`, `completed`, `all` | `current` |
| `days` | planned entries up to N days ahead (1-365) | `90` |
| `past` | completed entries of the last N days for `completed`/`all` (1-365) | `30` |

Response:

```json
{
  "api": "ciegolinkinfrahub-maintenance", "api_version": 1,
  "generated_at": "2026-10-24T15:20:00+02:00", "timezone": "Europe/Zurich", "scope": "current",
  "maintenance_active": true,
  "active": [ { "id": 12, "title": "Playout server update", "status": "active",
    "start": "2026-10-24T15:00:00+02:00", "end": "2026-10-24T16:30:00+02:00",
    "start_utc": "2026-10-24T13:00:00Z", "end_utc": "2026-10-24T14:30:00Z",
    "duration_minutes": 90, "devices": [ { "id": 7, "name": "Playout 1", "type": "Playout server" } ], "services": ["Playout", "SRT gateway"], "message": "Short interruptions possible." } ],
  "next": "next planned entry (same fields) or null",
  "count": 2,
  "items": "all matching entries, sorted by start"
}
```

`status` is `active`, `upcoming`, `completed` or `cancelled` (cancelled only with `scope=all`). Internal notes and entries marked "Internal only" are never returned. Times use `TZ` from `.env` (default Europe/Zurich). Errors: `401` invalid/missing token, `422` invalid parameter, `429` more than 1200 requests per 15 minutes from one IP. The API sends `Access-Control-Allow-Origin: *`, but do not put a token into public website JavaScript; call the API from your server instead.

## 10. Backup and restore

Back up the database **and** `config.php`. Without the key in `config.php`, passwords, 2FA secrets and attachments cannot be decrypted - a backup without it is worthless.

```bash
cd /opt/ciegolinkinfrahub
B=~/infra-backup/$(date +%F) && mkdir -p "$B" && chmod 700 ~/infra-backup
docker compose exec -T -u www-data hub sqlite3 /var/www/ciegolinkinfrahub/data/infra.sqlite ".backup '/var/www/ciegolinkinfrahub/data/backup.sqlite'"
docker compose cp hub:/var/www/ciegolinkinfrahub/data/backup.sqlite "$B/infra.sqlite"
docker compose exec -T -u www-data hub rm /var/www/ciegolinkinfrahub/data/backup.sqlite
docker compose cp hub:/var/www/ciegolinkinfrahub/config/config.php "$B/config.php"
docker compose cp hub:/var/www/ciegolinkinfrahub/data/software "$B/software" 2>/dev/null || true
chmod -R go-rwx "$B"
```

Store backups encrypted and separate from the server. Administrators can additionally download a JSON export under their name > Export & import.

Restore (e.g. on a new server):

```bash
docker compose up -d && docker compose stop hub
docker compose cp "$B/config.php" hub:/var/www/ciegolinkinfrahub/config/config.php
docker compose cp "$B/infra.sqlite" hub:/var/www/ciegolinkinfrahub/data/infra.sqlite
docker compose cp "$B/software" hub:/var/www/ciegolinkinfrahub/data/software
docker compose start hub
```

The container fixes owners and permissions on start. Test a restore once before relying on it.

## 11. Users and account recovery (command line)

Normally users are managed in the web interface (your name > User management). The command line is for emergencies, e.g. when no administrator can sign in any more:

| Task | Command |
|---|---|
| Create another administrator | `docker compose exec -it -u www-data hub php /var/www/ciegolinkinfrahub/bin/install.php create-admin` |
| Reset password and 2FA | `docker compose exec -it -u www-data hub php /var/www/ciegolinkinfrahub/bin/install.php reset-user` |

Always use `-u www-data`, so the database files keep the right owner. After a reset the user must set an own password and set up 2FA again; existing sessions and "stay signed in" devices of that account end. Every action is written to the audit log (actor `CLI`). There is no recovery by e-mail and no 2FA bypass in the web interface.

## 12. Security notes

- Passwords, 2FA secrets and attachments are encrypted with AES-256-GCM using the key in `config.php`. Whoever controls the Docker host and the key can decrypt them; this is not end-to-end encryption.
- The password manager is a shared team vault: every signed-in user can read all entries and files. Each reveal, copy and download is logged. Attachments: up to 5 MiB per file, 50 MiB per entry, downloaded as files, never opened by the hub.
- Sessions end after 15 minutes without activity or after 8 hours. "Stay signed in for 30 days" stores only a hash of the device token; sign-out, a password/role/2FA change or locking the user revokes it.
- SSH terminal: administrators only. Host keys are trusted on first use (volume `gateway`); a changed key blocks the connection before any password is sent. Opening a session is audited, keystrokes are not recorded.
- The containers run without extra privileges; the gateway runs as non-root with a read-only file system and is not reachable from outside.
- The audit log is read-only for web users, but not tamper-proof against an administrator of the Docker host.
- Before storing real credentials, check: HTTPS works, all three roles, 2FA, and a test restore of a backup. There has been no external security audit.

## 13. Useful commands

```bash
docker compose ps                     # status and health
docker compose logs -f hub            # web server / PHP
docker compose logs -f ssh-gateway    # terminal sessions
docker compose restart hub
docker compose down                   # stop (volumes stay)
```

`docker compose down -v` **deletes the volumes including key and database** - only use it if you really want to start from scratch.

## 14. Troubleshooting

| Problem | Solution |
|---|---|
| `sudo: ./install.sh: command not found` | Start the script with bash: `sudo bash install.sh` (the execute permission is often lost when unpacking). With `$'\r': command not found` the file has Windows line endings: `sed -i 's/\r$//' install.sh`. |
| `failed to bind host port 0.0.0.0:80/tcp: address already in use` | Caddy was started next to an existing web server. Remove it and use variant A: `docker compose --profile caddy rm -sf caddy`, `docker volume rm ciegolinkinfrahub_caddy-data ciegolinkinfrahub_caddy-config`, `docker compose up -d`. Then set up the proxy (section 6). |
| `127.0.0.1:18438` already in use | Set another `HUB_PORT` in `.env`, run `docker compose up -d` and change the port in the proxy configuration. |
| Browser shows "HTTPS is required" or sign-in does not stick | The site is opened without HTTPS or directly on the port. Always use `https://hub.example.com` via the proxy. |
| No temporary admin password in the log | The volumes already contained users from an earlier start. Use `reset-user` (section 11) or start from scratch with `docker compose down -v` (deletes all data). |
| Terminal: "origin not allowed" | `PUBLIC_URL` in `.env` must be exactly the browser address (`https://...`, no trailing slash). Then `docker compose up -d`. |
| Terminal does not connect | The `/ssh-gateway/ws` lines are missing in the proxy or come after `/`; with Apache, `proxy_wstunnel` must be enabled. |
| Upload of large software files fails | Raise the limit in the proxy (`LimitRequestBody` in Apache, `client_max_body_size` in nginx) and check `SOFTWARE_MAX_MB`. |
| `hub` not healthy | `docker compose logs hub` shows the reason (e.g. invalid `INFRA_ADMIN_PASSWORD`: 14-72 characters). |

