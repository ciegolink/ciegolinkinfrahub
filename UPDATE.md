# Update

Configuration, key and data are in Docker volumes. An update only replaces the images; no installer, no manual SQL, no file permissions.

## Update to a new version

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

Images are tagged with the version (`ciegolink/ciegolinkinfrahub:<version>`). To go back, put the previous project files in place and run `docker compose up -d --build` again. If the new version changed the database, restore the backup made before the update (INSTALL.md, section 10).

## Changing settings

Edit `.env`, then `docker compose up -d`. Changing `GATEWAY_SECRET` is possible at any time. Never delete the volume `ciegolinkinfrahub_config`: it contains the encryption key.

## Installation without Docker

Replace the application files, keep `config/` and `data/`, reload the page. Details: `INSTALL-CLASSIC.md`.
