# Changelog

The current version is in the file `VERSION` and is shown in the footer of every page and in every JSON export (`app_version`).
Versioning: MAJOR.MINOR.PATCH - MINOR for new features, PATCH for fixes. A MAJOR change would need a data migration and is announced here.
Update: see `UPDATE.md` (`docker compose up -d --build`). No manual steps unless stated.

## 1.0.1 - 2026-11-07
- **Workspaces**: one workspace per customer or project, chosen at the top of the sidebar. Locations, devices, IP addresses, credentials and files, racks, connection maps, documentation and maintenance windows belong to one workspace; users, audit log, software library and API tokens are shared. Administrators create, rename and delete workspaces under My account → Workspaces (deleting needs the workspace name and the current password). Export and import work on the open workspace.
- **Access per workspace**: accounts are system administrators (everything, every workspace) or users. Users only see the workspaces they are members of, as **workspace admin** (changes content, members, name, export/import) or **workspace user** (sees everything including passwords, changes nothing). Set under My account → Workspaces → Members or per user under User management. A new workspace is visible to system administrators only until members are added. Existing "Edit" accounts become workspace admins and "Read" accounts workspace users of every existing workspace, so nobody loses access by the update.
- **Several connection maps** per workspace (tabs). A map shows only the devices and elements added to it (**＋ Add to map**, **✕** hides a box on one map); positions are stored per map. Deleting a map keeps devices, elements and connections.
- **Zoom** on connection maps: buttons (−, ＋, Fit, 100 %), Ctrl + mouse wheel and trackpad pinch; remembered per map on the device.
- **QR code for the 2FA setup**: scan it with the authenticator app instead of typing the key (the key is still shown). Drawn locally in the browser as SVG with the bundled library qrcode-generator 2.0.4 (MIT); the key is never sent to another server.
- **Delete users** (system administrators): only disabled users, confirmed with the username and the own password; workspace access and saved sign-ins are removed, the audit log keeps the name.
- Maintenance API: optional parameter `workspace=<ID>`; every item contains `workspace` (id, name).
- Export format version 2: `workspace` name and `maps` (with box positions) instead of `map_position` per device/element. Exports of 1.0.0 can still be imported; their positions become the map "Main map".
- `update.sh`: update with backup (database, key, software, old files), `git pull` or `--source <zip>`, build, health and version check and automatic rollback; `--rollback` by hand; `--install-timer` for automatic updates of new versions (systemd).
- Docker images are tagged 1.0.1, so the 1.0.0 images stay available for a rollback.
- Automatic migration on the first request after the update: existing data goes into "Main workspace", the existing map becomes "Main map" with all boxes at their current positions. No manual steps.

## 1.0.0 - 2026-10-24
First versioned release.

- Locations with networks / IP ranges; servers & devices with external and internal IPs, web control link, notes, installed software; ping status (auto-check every 5 minutes).
- IP management with range tabs, status, admin web URL and linked password-manager entry.
- Password manager (shared team vault, AES-256-GCM) with encrypted file attachments, copy and audited reveal; folders with tabs and "Rename folder" (move, merge, remove).
- Maintenance module: maintenance windows with start date/time, duration, affected servers & devices, services, public message, internal note, status (planned/cancelled, in progress/completed automatically) and visibility (public/internal).
- Public read-only maintenance API `maintenance.php` with tokens (created and revoked by administrators, only SHA-256 hashes stored).
- Racks per location (1-60 U): full-width and half-width items, link to device and IP record, overlap check, usage bar, jump to IP management.
- Connection map (full page): drag & drop boxes, one orthogonal line per pair with stacked labels.
- Software library with streamed upload/download for all team members.
- Documentation module: documents with categories and full-text search, visual (WYSIWYG) or Markdown editing, always stored as Markdown, download as .md, included in export/import.
- Export (JSON with optional passwords/files, CSV per table) and JSON import (merge or replace) for administrators.
- Optional SSH terminal for administrators (needs its own server with the SSH gateway service, not available on shared hosting): login from the password manager, one-time ticket, host key trust on first use, sessions audited.
- Personal accounts with roles (read, edit, admin), mandatory 2FA, CSRF protection, session timeouts; optional "Stay signed in for 30 days on this device" (hashed device token, revoked by sign-out, password/role/2FA change or locking the user).
- Audit log with search and paging (50 per page). System tab with version, server details and software components.
- Docker: `Dockerfile` (PHP 8.3 + Apache), SSH gateway image, `docker-compose.yml` with volumes, health checks and optional Caddy for automatic HTTPS; guide in `INSTALL.md` (installation without Docker: `INSTALL-CLASSIC.md`). Installation script `install.sh` (asks for URL and hostname, creates `.env`, starts the containers, shows the one-time admin password, prepares the Apache vHost). First administrator is created automatically on the first start (random temporary password in the log or `INFRA_ADMIN_PASSWORD`); CLI mode `install.php create-admin` for further administrators.
- User manual (HTML with screenshots) at `/manual/`, linked in the sidebar and on the sign-in page; printable as PDF from the browser.
