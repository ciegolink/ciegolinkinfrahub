# Third-Party Notices

CiegoLink Infrastructure Hub is licensed under the MIT License (see `LICENSE`).
It includes or uses the following third-party components, each under its own license.

## Bundled in this repository (`public/assets/vendor/`)

| Component | Version | License | License file |
|---|---|---|---|
| xterm.js (+ addon-fit, addon-canvas) | – | MIT | `public/assets/vendor/xterm/LICENSE-xterm.txt` |
| marked | 12.0.2 | MIT | `public/assets/vendor/markdown/LICENSE-marked.md` |
| DOMPurify | 3.1.6 | Apache-2.0 or MPL-2.0 (used under Apache-2.0) | `public/assets/vendor/markdown/LICENSE-dompurify.txt` |
| Turndown | – | MIT | `public/assets/vendor/markdown/LICENSE-turndown.txt` |
| turndown-plugin-gfm | – | MIT, Copyright (c) 2017 Dom Christie | (same terms as Turndown) |

## Installed at build time (not included in this repository)

| Component | License |
|---|---|
| asyncssh | EPL-2.0 or GPL-2.0-or-later |
| aiohttp | Apache-2.0 |
| bcrypt | Apache-2.0 |
| PHP 8.3 (Docker image `php:8.3-apache-bookworm`) | PHP License 3.01 |
| Apache HTTP Server | Apache-2.0 |
| Python 3.12 (Docker image `python:3.12-slim-bookworm`) | PSF License |
| SQLite | Public Domain |

These components are used unmodified. Their licenses apply to them; the MIT
License in `LICENSE` applies only to CiegoLink's own code.
