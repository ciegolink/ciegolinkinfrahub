# Installation without Docker (Apache + PHP)

**The recommended installation is Docker, see `INSTALL.md`.** This guide is only for servers without Docker, e.g. shared hosting. Updates: replace the application files, keep `config/` and `data/`, reload the page.
Placeholders: `/var/www/ciegolinkinfrahub` = installation folder, `hub.example.com` = hostname, `www-data` = user that runs PHP. Replace them with your values.

## 1. Requirements

| | Required | Notes |
|---|---|---|
| PHP | 8.2 or newer | Extensions `pdo_sqlite` and `openssl` (check with `php -m`). `mbstring` recommended. |
| Web server | Apache 2.4 with HTTPS | Own hostname (e.g. a subdomain), DocumentRoot = `public/`. No subfolder of an existing website. |
| Command line | once, for the installer | SSH access to the server. |
| Storage | local disk | SQLite must not be on NFS. |
| Clock | synchronised (NTP) | Required for 2FA codes. |
| Ping status | optional | Uses the `ping` binary via `proc_open`; falls back to a TCP check. |
| SSH terminal | optional | Own server (VPS) with Python 3.10+, systemd and `mod_proxy_wstunnel`. See section 8. |

No Node.js, Composer, CDN or external service is needed. All fonts and scripts are included.

Ubuntu/Debian example:

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 sqlite3
```

## 2. Upload the files

Unpack the package and move the contents of the folder `ciegolinkinfrahub` to the installation folder:

```text
/var/www/ciegolinkinfrahub/
  core.php  VERSION  CHANGELOG.md
  bin/install.php
  public/index.php        <- DocumentRoot
  config/                 <- the installer creates config.php here
  data/                   <- the installer creates infra.sqlite here
  ssh-gateway/  tests/
```

Only `public/` may be reachable from the web. Never publish the whole project folder.

## 3. Run the installer

```bash
cd /var/www/ciegolinkinfrahub
sudo php bin/install.php
```

The installer asks for the username and a temporary password (at least 14 characters) of the first administrator. It creates `config/config.php` with a random encryption key and the database `data/infra.sqlite`. It never overwrites an existing configuration or database.

## 4. Set permissions

```bash
sudo chown -R root:root /var/www/ciegolinkinfrahub
sudo find /var/www/ciegolinkinfrahub -type d -exec chmod 755 {} \;
sudo find /var/www/ciegolinkinfrahub -type f -exec chmod 644 {} \;
sudo chown root:www-data /var/www/ciegolinkinfrahub/config /var/www/ciegolinkinfrahub/config/config.php
sudo chmod 750 /var/www/ciegolinkinfrahub/config
sudo chmod 640 /var/www/ciegolinkinfrahub/config/config.php
sudo chown -R www-data:www-data /var/www/ciegolinkinfrahub/data
sudo chmod 700 /var/www/ciegolinkinfrahub/data
sudo chmod 600 /var/www/ciegolinkinfrahub/data/infra.sqlite
```

PHP may read code and configuration but not change them. It needs write access to `data/` only (database, WAL files, software uploads).

## 5. Apache

Copy `apache-vhost.conf.example`, replace hostname, paths and certificate paths, and enable the site. It sets DocumentRoot to `public/` and denies everything else in the project folder.

Also deny the private folders for **all** virtual hosts, so no other site on the server can expose them:

```bash
sudo nano /etc/apache2/conf-available/ciegolinkinfrahub-private.conf
```

```apache
<Directory /var/www/ciegolinkinfrahub/config>
    AllowOverride None
    Require all denied
</Directory>
<Directory /var/www/ciegolinkinfrahub/data>
    AllowOverride None
    Require all denied
</Directory>
<Directory /var/www/ciegolinkinfrahub/bin>
    AllowOverride None
    Require all denied
</Directory>
<Directory /var/www/ciegolinkinfrahub/ssh-gateway>
    AllowOverride None
    Require all denied
</Directory>
```

```bash
sudo a2enconf ciegolinkinfrahub-private
sudo apache2ctl configtest && sudo systemctl reload apache2
```

HTTPS is mandatory; the API refuses plain HTTP. Behind a TLS-terminating reverse proxy the backend vHost must detect HTTPS itself (`HTTPS=on`). Forwarded headers are not trusted; for correct client IPs behind a proxy configure Apache `mod_remoteip` for the trusted proxy addresses only.

## 6. Test and first sign-in

```bash
cd /var/www/ciegolinkinfrahub && python3 tests/check.py
```

The tests run isolated and do not touch your installation (details in `TESTS.md`). Then:

1. Open `https://hub.example.com` and sign in with the administrator. Leave the 2FA field empty on the first sign-in.
2. Set your own password, add the setup key to an authenticator app (TOTP, 6 digits, 30 seconds) and confirm with the current code.
3. Create users under your name (bottom left) > User management. Share temporary passwords securely; every user sets their own password and 2FA on first sign-in.
4. Start with a location, then add devices, IP addresses, racks and credentials.

Data from another installation can be imported as administrator under your name > Export & import.

## 7. Upload limit for the software library

The maximum file size is the smallest of PHP `upload_max_filesize`, `post_max_size` and the optional config value `'software_max_mb'` (default 4096). The current value is shown under your name > System. For large installers set in php.ini or the PHP-FPM pool and restart PHP:

```ini
upload_max_filesize = 2G
post_max_size = 2100M
max_input_time = 3600
```

Apache accepts request bodies up to 1 GiB by default; for bigger files add `LimitRequestBody 2202009600` to the vHost. The PHP temporary upload folder needs enough free space.

## 8. Optional: SSH terminal

Administrators can open an SSH session to a device directly in the browser. This needs a small service on the same server, so it is **not available on shared hosting**. Everything else works without it; the Terminal button only appears once the service is configured.

```bash
cd /var/www/ciegolinkinfrahub
sudo bash ssh-gateway/setup.sh https://hub.example.com
```

The script creates the system user `ciegolinkinfrahub-ssh`, a Python environment, `ssh-gateway/gateway.env` with a random secret and the systemd service `ciegolinkinfrahub-ssh-gateway` (listens on 127.0.0.1:8022 only). At the end it prints:

1. a line for `config/config.php` inside the returned array: `'ssh_gateway' => ['secret' => '...'],`
2. two lines for the HTTPS vHost:
   ```apache
   ProxyPass        /ssh-gateway/ws ws://127.0.0.1:8022/ws
   ProxyPassReverse /ssh-gateway/ws ws://127.0.0.1:8022/ws
   ```

Then `sudo apache2ctl configtest && sudo systemctl reload apache2`. The server must be able to reach the devices (for internal addresses e.g. via VPN/WireGuard). Security details: `INSTALL.md`, section "Security notes".

## 9. Shared hosting

The hub runs on shared hosting if all of these apply:

- PHP 8.2+ with `pdo_sqlite` and `openssl`, HTTPS for the (sub)domain
- the document root of the (sub)domain can be set to the folder `public/`; if not, do not install there, because `config/` and `data/` would become reachable
- SSH access to run `php bin/install.php` once. Without SSH: run the installer on any computer with PHP, then upload the created `config/config.php` and `data/infra.sqlite`. The configuration uses relative paths.

The included `.htaccess` files additionally deny web access to `config/` and `data/`. Not available on shared hosting: the SSH terminal. Ping status may fall back to a TCP check.

## 10. Backup and recovery

Back up the database **and** the configuration; without the key in `config/config.php` passwords, 2FA secrets and attachments cannot be decrypted.

```bash
sudo install -d -m 700 /root/infra-backup
sudo sqlite3 /var/www/ciegolinkinfrahub/data/infra.sqlite ".backup '/root/infra-backup/infra-$(date +%F).sqlite'"
sudo cp /var/www/ciegolinkinfrahub/config/config.php /root/infra-backup/config-$(date +%F).php
sudo tar -C /var/www/ciegolinkinfrahub/data -czf /root/infra-backup/software-$(date +%F).tgz software 2>/dev/null || true
sudo chmod 600 /root/infra-backup/*
```

Use the SQLite backup command, not a plain copy of the active database (WAL mode). Store backups encrypted and separate from the server. To restore: take the hub offline, put back database, configuration and `data/software/`, remove old `-wal`/`-shm` files, set permissions again (section 4). Test a restore on a separate installation first.

In addition, administrators can download a JSON export of all data under their name > Export & import.

## 11. Administrators and account recovery (command line)

Normally administrators create and manage users in the web interface (your name > User management). The command line is for emergencies, e.g. when no administrator can sign in any more. Run the commands as the web server user, so the database files keep the right owner.

| Task | Command |
|---|---|
| Create an administrator | `sudo -u www-data php /var/www/ciegolinkinfrahub/bin/install.php create-admin` |
| Reset password and 2FA | `sudo -u www-data php /var/www/ciegolinkinfrahub/bin/install.php reset-user` |

`create-admin` asks for a new username and a temporary password (at least 14 characters); it refuses an existing username. `reset-user` asks for an existing username and a new temporary password; existing sessions and "stay signed in" devices of that account end. In both cases the user must set an own password and set up 2FA at the next sign-in. Every action is written to the audit log (actor `CLI`). There is no recovery by e-mail and no 2FA bypass in the web interface.

## 12. Security notes

- Passwords, 2FA secrets and attachments are encrypted with AES-256-GCM using the key in `config/config.php`. Whoever controls the server and the key can decrypt them; this is not end-to-end encryption.
- The password manager is a shared team vault: every signed-in user can read all entries. Each reveal, copy and download is logged.
- Sessions end after 15 minutes without activity or after 8 hours. Locking a user, changing a role or a password ends their sessions.
- Optional "Stay signed in for 30 days" on the sign-in page: an HttpOnly, Secure, SameSite=Strict device cookie (only a SHA-256 hash is stored in table `remember`). Sign out removes it; locking a user, a role change, a password change or a 2FA reset revokes all of that user's devices.
- The audit log is read-only for web users, but not tamper-proof against a server administrator.
- Before storing real credentials, check HTTPS, PHP execution, that `config/` and `data/` are not reachable from the web, file permissions, all roles, 2FA and a backup restore. There has been no external security audit.

## 13. Maintenance API

See `INSTALL.md`, section "Maintenance API". Endpoint: `https://hub.example.com/maintenance.php`.
