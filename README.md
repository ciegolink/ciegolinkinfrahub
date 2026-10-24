# CiegoLink Workspace & Infrastructure Hub 

Web application for documenting and operating IT and broadcast infrastructure: locations, servers and devices, IP management, racks, connection map, password manager with folders and encrypted files, team documentation (visual or Markdown editor), maintenance planning with a public API, software library and an SSH terminal. Personal accounts with roles, mandatory 2FA and audit log.

Current version: see `VERSION`, Changes: `CHANGELOG.md`, User manual: `https://your-hub-hostname/manual/` (also linked in the sidebar)

## Quick start (Docker)

```bash
cd /opt/ciegolinkinfrahub
sudo bash install.sh
```

The script asks for the URL, creates `.env`, starts the containers and shows the one-time admin password. Then set up the Apache proxy with the generated `apache-ciegolinkinfrahub.conf` (commands are printed), open the URL, sign in, set your own password and 2FA.

| Document | Content |
|---|---|
| **`INSTALL.md`** | Installation with Docker, reverse proxy, SSH terminal, maintenance API, backup, account recovery, security |
| **`UPDATE.md`** | Updating and rolling back |
| `CHANGELOG.md` | Changes per version |
| `TESTS.md` | Automated tests (development) |
| `INSTALL-CLASSIC.md` | Only for servers without Docker (Apache + PHP, shared hosting) |

## Files

| File / folder | Purpose |
|---|---|
| `Dockerfile`, `docker/` | Image of the web application (PHP 8.3 + Apache) and its configuration |
| `docker/gateway.Dockerfile` | Image of the SSH gateway |
| `docker-compose.yml`, `.env.example` | Containers, volumes, settings |
| `public/` | Web interface, API, user manual |
| `core.php`, `bin/` | Application core, command line tool (create admin, reset user) |
| `ssh-gateway/` | Source of the SSH gateway |
| `tests/` | Automated tests (not part of the image) |
| `config/`, `data/`, `apache-vhost.conf.example` | Only used by the installation without Docker; with Docker, configuration and data are in volumes |

## Screenshots

<p align="center">
  <img src="https://github.com/user-attachments/assets/5a8955af-7f70-4759-b1b6-b9a742beaa74" width="49%" alt="Overview">
  <img src="https://github.com/user-attachments/assets/0df16e9d-e4a2-42ed-877d-d31d4a0a54f1" width="49%" alt="Password manager">
</p>
<p align="center">
  <img src="https://github.com/user-attachments/assets/b2984659-9c1a-4a30-a99a-29946e7a9dc8" width="49%" alt="IP management">
  <img src="https://github.com/user-attachments/assets/80f37224-58ee-4741-a992-49dd0d366ad4" width="49%" alt="Connection map">
</p>
