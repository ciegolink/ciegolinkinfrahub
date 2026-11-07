# Update

Configuration, key and data are in Docker volumes. An update only replaces the images; no installer, no manual SQL, no file permissions.

## Update with the script (recommended)

```bash
cd /opt/ciegolinkinfrahub
sudo bash update.sh            # shows installed/new version and the changes, asks, then updates
sudo bash update.sh --check    # only check
```

The script does everything in one go:

1. **Backup** to `/var/backups/ciegolinkinfrahub/<date>_v<old version>/`: database (SQLite online backup + integrity check), `config.php` with the encryption key, software library, `.env` and the old project files. The newest 10 backups are kept (`--keep N`, `--no-software` skips the software files, other folder: `BACKUP_DIR=/path sudo -E bash update.sh`).
2. **New files**: with a git checkout `git pull` (fast-forward only; stops if project files were changed locally). Without git (installed from a ZIP) the script downloads the current version from GitHub itself; `.env`, `config/` and `data/` are kept. Own file or folder instead: `sudo bash update.sh --source <file.zip|file.tar.gz|folder>`.
3. **Build and restart** (`docker compose build --pull`, `up -d`).
4. **Check**: the hub must become healthy and report the new version. Otherwise the script **rolls back automatically** (old files, old images, database from the backup).

Undo the last update by hand (code and database; changes made since the update are lost):

```bash
sudo bash update.sh --rollback
```

### Automatic updates

```bash
sudo bash update.sh --install-timer          # weekly, Sunday around 04:00
sudo bash update.sh --install-timer daily    # daily around 04:00
sudo bash update.sh --remove-timer           # off
```

A systemd timer runs `update.sh --auto`. It only installs **new versions** (the file `VERSION` changed; commits without a new version are left for a manual update), always makes the backup first and rolls back on failure. A version that was rolled back is not tried again automatically. Log: `journalctl -u ciegolinkinfrahub-update` or `.update/update.log`; next run: `systemctl list-timers ciegolinkinfrahub-update.timer`.

## Update by hand

```bash
cd /opt/ciegolinkinfrahub
# 1. Backup (INSTALL.md, section 10) - at least database and config.php
# 2. Replace the project files with the new version; keep your .env
# 3. Build and restart
docker compose up -d --build
docker compose ps            # hub and ssh-gateway must be "healthy"
```

The database schema is updated automatically when the container starts. The version is shown in the footer of every page. Read `CHANGELOG.md` for the changes; anything that needs manual action is noted there.

Sessions stay valid; users with "stay signed in" stay signed in.

## Roll back

`sudo bash update.sh --rollback` does the following automatically. Images are tagged with the version (`ciegolink/ciegolinkinfrahub:<version>`). To go back by hand, put the previous project files in place and run `docker compose up -d --build` again. If the new version changed the database, restore the backup made before the update (INSTALL.md, section 10).

## Changing settings

Edit `.env`, then `docker compose up -d`. Changing `GATEWAY_SECRET` is possible at any time. Never delete the volume `ciegolinkinfrahub_config`: it contains the encryption key.

## Installation without Docker

Replace the application files, keep `config/` and `data/`, reload the page. Details: `INSTALL-CLASSIC.md`.
