# Tests

The tests are for development and for checking a changed version before building the image. They are not part of the Docker image and are not needed to run the hub.

All tests run isolated: temporary configuration, temporary SQLite database and test accounts on 127.0.0.1. No real installation is used or changed.

| Command | Needs | Covers |
|---|---|---|
| `python3 tests/check.py` | php-cli with pdo_sqlite and openssl | `php -l` for all PHP files; API security and functions: sign-in, stay signed in (30 days), credential folders, maintenance module and public maintenance API with tokens, CSRF, roles, password change and 2FA, TOTP replay, rate limits, session revocation, audit log with paging and search, encryption, locations, devices, IP management, password manager and files, connection map, status check, software library upload/download, documentation (save, revision conflict, search, limits), export/import, racks (overlap, half width), system information |
| `node tests/ui-check.cjs` | Node.js | Frontend rendering and permissions with test data: forms, search, IP ranges, map, racks, software, export/import, audit paging, system tab, HTML escaping, reader restrictions |
| `python3 tests/ssh_check.py` | php-cli, Python packages from `ssh-gateway/requirements.txt` | SSH terminal end to end with its own test SSH server: admin only, login with passphrase-protected key file, host bound to device, login stays on the server, single-use ticket, terminal I/O, wrong password, host key trust on first use, changed key blocked, forget key, gateway secret, gateway offline |

After installing or updating, check manually on the HTTPS address: `docker compose ps` healthy, sign-in/sign-out, password change, 2FA, all three roles, Terminal button for administrators, a maintenance API request with a token, and a test restore of a backup.

There has been no external security audit.
