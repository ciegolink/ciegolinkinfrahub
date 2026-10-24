<?php
declare(strict_types=1);
$v=htmlspecialchars(trim((string)@file_get_contents(dirname(__DIR__,2).'/VERSION'))?:'dev',ENT_QUOTES);
header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
if(($_SERVER['HTTPS']??'')==='on')header('Strict-Transport-Security: max-age=31536000');
function fig(string $img,string $caption):string{return '<figure><a href="img/'.$img.'.webp" target="_blank" rel="noopener"><img src="img/'.$img.'.webp" alt="'.htmlspecialchars($caption,ENT_QUOTES).'" loading="lazy" width="1440" height="900"></a><figcaption>'.$caption.'</figcaption></figure>';}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Manual · CiegoLink Infrastructure Hub</title><link rel="icon" href="../assets/img/favicon.png"><link rel="stylesheet" href="manual.css?v=<?=$v?>"></head><body>
<aside class="toc">
<a class="brand" href="../"><img src="../assets/img/ciegolinklogowhite.png" alt="CiegoLink"></a>
<div class="eyebrow">MANUAL · VERSION <?=$v?></div>
<nav>
<a href="#start">Getting started</a>
<a href="#interface">The interface</a>
<a href="#locations">Locations</a>
<a href="#devices">Servers &amp; devices</a>
<a href="#ips">IP management</a>
<a href="#racks">Racks</a>
<a href="#map">Connection map</a>
<a href="#vault">Password manager</a>
<a href="#docs">Documentation</a>
<a href="#maintenance">Maintenance</a>
<a href="#software">Software library</a>
<a href="#terminal">SSH terminal</a>
<a href="#account">My account &amp; administration</a>
<a href="#roles">Roles &amp; permissions</a>
<a href="#security">Security &amp; data</a>
<a href="#install">Installation &amp; updates</a>
<a href="#faq">Troubleshooting</a>
</nav>
<a class="back" href="../">← Back to the hub</a>
</aside>
<main>
<header class="hero"><div class="eyebrow">CIEGOLINK / INFRASTRUCTURE HUB</div><h1>User <em>manual.</em></h1><p>Everything about documenting and operating your infrastructure in one place: locations, devices, IP addresses, racks, connections, credentials, team documentation, software and terminal access. Version <?=$v?>.</p></header>

<section id="start"><h2>Getting started</h2>
<p>Every person signs in with a personal account. Your administrator creates the account and gives you a temporary password.</p>
<ol>
<li><b>First sign-in:</b> enter username and temporary password, leave the 2FA field empty.</li>
<li><b>Set your own password:</b> at least 14 characters. Other sessions of your account end.</li>
<li><b>Set up two-factor authentication:</b> add the shown setup key manually to an authenticator app (e.g. Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden) as TOTP with 6 digits and 30 seconds, then confirm with the current code.</li>
<li><b>Later sign-ins:</b> username, password and the current 6-digit code from the app.</li>
</ol>
<?=fig('login','Sign-in with username, password and 2FA code')?>
<p class="note">Sessions end after 15 minutes without activity and after 8 hours at the latest. Tick <b>Stay signed in for 30 days on this device</b> to skip password and 2FA on your own computer for 30 days; never use it on shared devices. Use <b>Sign out</b> (top right) when you are done; closing the tab is not a sign-out.</p>
</section>

<section id="interface"><h2>The interface</h2>
<p>The sidebar on the left leads to all modules. Your name at the bottom of the sidebar opens <b>My account</b>: password, <b>Info</b> with the version and this manual, and for administrators user management, audit log and export &amp; import. The footer of each page shows the installed version.</p>
<?=fig('overview','Overview with counters, location filter, search and all devices')?>
<ul>
<li><b>Overview</b> shows counters for devices, locations, IP records and credentials, followed by all devices.</li>
<li><b>Location filter</b> (top left of the list) limits devices, IP addresses and racks to one location.</li>
<li><b>Search</b> filters live and also searches notes, installed software, Docker images and IP addresses.</li>
<li>Status colours: <span class="dot up"></span> reachable, <span class="dot partial"></span> partly reachable, <span class="dot down"></span> no response, <span class="dot"></span> not checked.</li>
</ul>
</section>

<section id="locations"><h2>Locations</h2>
<p>A location is a site, data centre, studio or cloud region. Every device, IP record and rack belongs to a location, so create locations first.</p>
<?=fig('locations','Locations with networks and IP ranges')?>
<ul>
<li><b>Networks / IP ranges:</b> one per line, e.g. <code>10.10.0.0/24</code> or <code>10.10.10.0/24 Management</code>. Ranges in CIDR notation appear as tabs in IP management.</li>
<li>A location with devices, IP records or racks cannot be deleted until they are moved or removed.</li>
</ul>
</section>

<section id="devices"><h2>Servers &amp; devices</h2>
<p>Each card shows type, status and location, directly below the name the buttons <b>Ping</b>, <b>Terminal</b> (administrators) and <b>Web admin access ↗</b>, then the notes and compact sections.</p>
<?=fig('devices','Compact device cards: actions at the top, sections open with a click')?>
<ul>
<li><b>IP addresses</b> shows the main address with status dots; click to see all internal and external addresses with response times.</li>
<li><b>Connections</b>, <b>Credentials</b> and <b>Software</b> show the number of entries; click to open them. Credentials offer <b>Copy user</b>, <b>Copy password</b> and <b>Show</b>.</li>
<li><b>Rack</b> shows where the device is mounted.</li>
<li>Opened sections stay open while you work, also when the status is refreshed.</li>
</ul>
<h3>Add or edit a device</h3>
<?=fig('device-edit','Device form')?>
<ul>
<li><b>Type:</b> choose from the list (router, switch, server, encoder, playout server …) or type your own.</li>
<li><b>Web admin access:</b> address of the device's web interface; opens from the card with <b>Web admin access ↗</b>.</li>
<li><b>External / internal IP addresses:</b> any number of IPv4 or IPv6 addresses, one per field; use <b>＋ Add</b> for more.</li>
<li><b>Notes</b> and <b>Installed software</b> (one entry per line, e.g. <code>Docker: nginx:1.27 · Port 8080</code>) are searchable.</li>
</ul>
<h3>Reachability (ping)</h3>
<p>All devices are checked automatically when the page loads and every 5 minutes while it is open. <b>Ping</b> on a card checks one device, <b>Ping all devices</b> all of them. The check runs from the server, so it shows whether the hub's server can reach the device. Where ping is not available a TCP check is used.</p>
</section>

<section id="ips"><h2>IP management</h2>
<p>The IP list documents individual addresses per location, sorted numerically. Tabs show each network range of the location with the number of entries, plus <b>Outside ranges</b> for addresses that do not belong to any range.</p>
<?=fig('ips','IP management with range tabs, status, web admin access and linked credentials')?>
<ul>
<li><b>Status:</b> Assigned, Reserved or Available.</li>
<li><b>Device match:</b> when you type an address that belongs to a device of the same location, name and type are taken over automatically.</li>
<li><b>Web admin access:</b> opens with <b>Open ↗</b>.</li>
<li><b>Password manager entry:</b> link a credential; username, <b>Show</b>, <b>Copy password</b> and <b>Files</b> then appear directly in the list. The password stays in the password manager.</li>
<li>The same address may exist in different locations, but only once per location.</li>
</ul>
</section>

<section id="racks"><h2>Racks</h2>
<p>Racks show what is mounted where, per location. The usage bar and the counter show the used height units (U).</p>
<?=fig('racks','Rack view with linked devices, IP addresses, status and half-width items')?>
<ol>
<li><b>＋ Add rack:</b> name, location, height in U (1–60, default 42) and notes such as row or power feed.</li>
<li><b>Mount an item:</b> click a free unit (＋) or <b>＋ Item</b>. Enter the lowest unit and the height; the item grows upwards.</li>
<li><b>Width:</b> full width, left half or right half. Two half-width items can share a unit; click the free half directly.</li>
<li><b>Linked device / IP address</b> (optional): the name, type and matching IP record are taken over automatically. Patch panels, UPS or shelves can be added without a link.</li>
</ol>
<?=fig('rack-item','Mounting an item: rack, device, position, height, width and IP address')?>
<ul>
<li>Click the IP address of an item to open it in IP management; <b>↗</b> opens the web admin access of the IP record.</li>
<li>Click an item to edit, move (change the lowest unit) or delete it.</li>
<li>Overlapping items and items above the rack height are rejected. Device cards show the rack position, e.g. <code>U36–U37 · Rack A1</code>.</li>
<li>Deleting a rack removes its items. Linked devices and IP records stay.</li>
</ul>
</section>

<section id="map"><h2>Connection map</h2>
<p>The map shows what is connected to what: devices plus <b>elements</b> such as websites, streams or external services.</p>
<?=fig('map','Connection map with devices, elements and labelled connections')?>
<ul>
<li><b>Arrange:</b> drag boxes; positions are saved for everyone.</li>
<li><b>Connect:</b> drag from the round handle on the right side of a box onto another box, then enter a label, e.g. <code>HTTPS</code>, <code>SRT :9000</code> or <code>NFS media</code>. Alternatively use <b>＋ Connection</b>.</li>
<li><b>Several connections</b> between the same two boxes share one line; their labels are stacked on it.</li>
<li>Click a label to rename or delete the connection; click a box to edit it. <b>↗</b> opens the link of a box.</li>
<li>The dot in a box shows the reachability of the device.</li>
</ul>
</section>

<section id="vault"><h2>Password manager</h2>
<p>A shared team vault for logins, keys and configuration files. Entries can belong to a device, contain a link and a reference (e.g. a customer number), or stand alone.</p>
<?=fig('credentials','Password manager with Show, Copy and Files')?>
<ul>
<li><b>Show</b> displays the password for 30 seconds; <b>Copy</b> puts it on the clipboard (HTTPS required). The clipboard is not cleared automatically.</li>
<li>Leave the password field empty when editing to keep the existing password.</li>
<li><b>Files:</b> attach configuration files, SSH keys or encrypted connection files (up to 5 MiB per file, 50 MiB per entry). Files are stored encrypted and are only downloaded, never opened by the hub.</li>
<li>Every reveal, copy and download is written to the audit log.</li>
<li><b>Folders:</b> type a folder name in the field <b>Folder</b> (or pick an existing one). Folders appear as tabs above the list; <b>Rename folder</b> moves all entries at once. Use an existing name to merge two folders, an empty name to remove the folder.</li>
</ul>
<?=fig('credential-files','Encrypted files of a credential')?>
<p class="note">All signed-in users can read all entries of the vault. Do not store anything there that some team members must not see.</p>
</section>

<section id="docs"><h2>Documentation</h2>
<p>Procedures, runbooks, how-tos and notes for the team, grouped by category. Every signed-in user can read; users with edit rights can write, change and delete documents.</p>
<?=fig('docs-view','Documentation with categories, full-text search and a document with headings, list, quote and table')?>
<ul>
<li><b>Search</b> (top of the list) covers titles, categories and the full text.</li>
<li><b>＋ New document:</b> enter a title and optionally a category; existing categories are suggested.</li>
<li><b>Download .md</b> saves the document as a Markdown file.</li>
</ul>
<h3>Writing: Visual or Markdown</h3>
<p>The editor has two modes; switch at any time with <b>Visual</b> / <b>Markdown</b> on the right of the toolbar. Documents are always stored as Markdown, so they remain readable and portable.</p>
<?=fig('docs-edit','Visual mode: formatting is shown directly, bold looks bold')?>
<ul>
<li><b>Visual:</b> write like in a word processor. Bold, italic, strikethrough, inline code, headings H1–H3, bullet and numbered lists, quotes, code blocks, links, tables and lines via the toolbar. Shortcuts: <b>Ctrl+B</b> bold, <b>Ctrl+I</b> italic, <b>Ctrl+S</b> save.</li>
<li><b>Inline code:</b> select text and click <b>&lt;/&gt;</b>; without a selection a placeholder is inserted. Press <b>→</b> at the end of the code to continue in normal text. Clicking <b>&lt;/&gt;</b> inside code removes the formatting.</li>
<li><b>Pasting</b> from websites or Word keeps headings, lists, links and bold/italic, but removes colours and fonts.</li>
<li><b>Markdown:</b> edit the source directly, e.g. <code>**bold**</code>, <code>*italic*</code>, <code>## Heading</code>, <code>- list</code>, <code>[text](https://…)</code> and tables with <code>|</code>. The toolbar inserts the matching syntax.</li>
</ul>
<?=fig('docs-md','Markdown mode: the same document as source')?>
<p class="note">If two people edit the same document, the second save is refused with a notice. Copy your text, reload the document and apply your changes again. Images cannot be embedded; link to them instead.</p>
</section>

<section id="maintenance"><h2>Maintenance</h2>
<p>Plan maintenance windows with start date and time, duration, the affected servers &amp; devices (tick them in the list, with filter), affected services and a public message. The status is calculated automatically: <b>Planned</b>, <b>In progress</b>, <b>Completed</b>; you can also set an entry to <b>Cancelled</b>. While a maintenance is running, a banner is shown on the page. Times are shown in your browser time zone.</p>
<ul>
<li><b>Visibility:</b> “Public” entries are delivered by the maintenance API, “Internal only” entries and the internal note never are.</li>
<li><b>API access</b> (administrators): create one token per server or website that reads the maintenance plan, and revoke it when it is no longer needed. The token is shown only once. Request example: <code>curl -H "Authorization: Bearer &lt;token&gt;" https://your-hub/maintenance.php</code>. Details and all parameters: <code>INSTALL.md</code>, section “Maintenance API”.</li>
</ul>
</section>

<section id="software"><h2>Software library</h2>
<p>Installers, tools and packages for the team. Every signed-in user can download; users with edit rights can upload, edit and delete.</p>
<?=fig('software','Software library with version, platform, file size and SHA-256')?>
<ul>
<li><b>＋ Upload software:</b> choose the file and optionally name (defaults to the file name), version, platform and notes. A progress bar shows the upload.</li>
<li>The maximum file size is shown above the list; it is set by the server configuration.</li>
<li><b>Copy hash</b> copies the SHA-256 checksum to verify a download.</li>
</ul>
</section>

<section id="terminal"><h2>SSH terminal</h2>
<p>Administrators can open an SSH session to a device directly in the browser. With the Docker installation the terminal is included; the <b>Terminal</b> button appears on device cards for administrators.</p>
<?=fig('terminal-connect','Connecting: address, port and login from the password manager')?>
<ol>
<li>Click <b>Terminal</b> on a device card.</li>
<li>Choose the address (one of the device's IPs), the port (default 22) and the login: an entry of the password manager or <b>Enter manually</b>. A key file from the entry's Files can be used; the entry's password is then the key passphrase.</li>
<li>Click <b>Connect</b>. <b>Fullscreen</b> enlarges the terminal; <b>Disconnect</b> ends the session.</li>
</ol>
<?=fig('terminal','Terminal session in the browser')?>
<ul>
<li>The login is sent from the server; the browser never receives the password.</li>
<li>On the first connection the server's host key is stored. If it changes later, the connection is blocked before any password is sent. After an intended reinstallation use <b>Forget stored host key</b> in the connect dialog.</li>
<li>Sessions end after 30 minutes without input or 8 hours in total. Every session is written to the audit log; keystrokes are not recorded.</li>
</ul>
</section>

<section id="account"><h2>My account &amp; administration</h2>
<p>Click your name at the bottom of the sidebar. Every user can change the password there and open <b>Info</b> with the installed version and a link to this manual; administrators see additional tabs.</p>
<?=fig('account-users','User management')?>
<h3>User management (administrators)</h3>
<ul>
<li><b>＋ Add user:</b> name, username, role and a temporary password. The new user sets their own password and 2FA on first sign-in.</li>
<li><b>Manage:</b> change name, role or status (active / disabled). Saving ends the user's current sessions.</li>
<li>Lost 2FA device: the server administrator resets password and 2FA on the command line (see <a href="#faq">Troubleshooting</a>).</li>
</ul>
<h3>Audit log (administrators)</h3>
<?=fig('account-audit','Audit log with search and paging')?>
<p>Sign-ins, changes, password reveals, file downloads, exports and terminal sessions, 50 per page with <b>« First</b>, <b>‹ Back</b>, <b>Next ›</b> and <b>Last »</b>. The search covers user, action, target, IP address and date (e.g. <code>2026-10</code>). Times are in UTC.</p>
<h3>Export &amp; import (administrators)</h3>
<?=fig('account-data','Export as JSON or CSV and import of a JSON export')?>
<ul>
<li><b>Export · JSON:</b> all data including documentation in one structured file, e.g. as a backup or for a move to another system. Passwords and credential files are optional and require your current password; the file then contains secrets without encryption.</li>
<li><b>Export · CSV:</b> one file per table for Excel, never with passwords.</li>
<li><b>Import · JSON:</b> imports an export of the hub. <b>Merge</b> keeps existing entries and adds what is missing; <b>Replace</b> deletes all infrastructure data first. Invalid data aborts the whole import without changes. Users and software files are not imported.</li>
</ul>
<h3>Info</h3>
<?=fig('account-system','Info: manual, version, server details and software components')?>
<p>Everyone sees the installed version and <b>Open manual ↗</b>. Administrators also see the status of the SSH terminal, server details (PHP, database, limits, storage) and the software components used, with version and licence.</p>
</section>

<section id="roles"><h2>Roles &amp; permissions</h2>
<table><thead><tr><th>Permission</th><th>Read</th><th>Edit</th><th>Administrator</th></tr></thead><tbody>
<tr><td>View all modules, search, ping</td><td>✓</td><td>✓</td><td>✓</td></tr>
<tr><td>Show and copy passwords, download files and software</td><td>✓</td><td>✓</td><td>✓</td></tr>
<tr><td>Create, edit and delete entries, arrange the map, mount rack items</td><td>–</td><td>✓</td><td>✓</td></tr>
<tr><td>Upload files and software</td><td>–</td><td>✓</td><td>✓</td></tr>
<tr><td>Read documentation</td><td>✓</td><td>✓</td><td>✓</td></tr>
<tr><td>Write, change and delete documentation</td><td>–</td><td>✓</td><td>✓</td></tr>
<tr><td>Manage users, audit log, export &amp; import, server details in Info</td><td>–</td><td>–</td><td>✓</td></tr>
<tr><td>SSH terminal</td><td>–</td><td>–</td><td>✓</td></tr>
</tbody></table>
<p>Two-factor authentication is mandatory for every role.</p>
</section>

<section id="security"><h2>Security &amp; data</h2>
<ul>
<li>Passwords, 2FA secrets and attached files are encrypted (AES-256-GCM). The key is stored on the server; this is not end-to-end encryption.</li>
<li>All data is stored in a database on your own server. The hub loads no external scripts, fonts or services.</li>
<li>All actions run over HTTPS with protection against cross-site requests; sign-in attempts are limited.</li>
<li>Security-relevant actions are written to the audit log.</li>
<li>Backups must contain the database <b>and</b> the configuration with the key; without the key, passwords and files cannot be restored.</li>
</ul>
</section>

<section id="install"><h2>Installation &amp; updates</h2>
<p>For server administrators. The full instructions are in the files delivered with the software:</p>
<ul>
<li><code>README.md</code>: overview and quick start</li>
<li><code>INSTALL.md</code>: installation with Docker, reverse proxy, SSH terminal, maintenance API, backup and recovery</li>
<li><code>UPDATE.md</code>: updating and rolling back</li>
<li><code>INSTALL-CLASSIC.md</code>: installation without Docker (Apache + PHP, shared hosting)</li>
<li><code>CHANGELOG.md</code>: changes per version</li>
<li><code>TESTS.md</code>: automated tests</li>
</ul>
<p>Requirements in short: a Linux server with Docker and Docker Compose, a hostname and HTTPS in front (own reverse proxy or the included Caddy). The SSH terminal is included.</p>
<p>Installation: in the project folder run <code>sudo bash install.sh</code>. The script asks for the URL, creates the configuration, starts the containers and shows the one-time administrator password.</p>
</section>

<section id="faq"><h2>Troubleshooting</h2>
<dl>
<dt>I lost my phone with the authenticator app.</dt><dd>Ask the server administrator. On the server they run <code>docker compose exec -it -u www-data hub php /var/www/ciegolinkinfrahub/bin/install.php reset-user</code>; you then sign in with a new temporary password and set up 2FA again.</dd>
<dt>“Session expired” or I am signed out.</dt><dd>After 15 minutes without activity or 8 hours in total the session ends. Sign in again, optionally with <b>Stay signed in for 30 days</b>. A password or role change also ends remembered devices.</dd>
<dt>“Record changed. Refresh the page.”</dt><dd>Someone else saved the same entry in the meantime. Reload the page and repeat your change.</dd>
<dt>A device shows “Offline” although it works.</dt><dd>The check runs from the hub's server. The device may block ping, or the server cannot reach the network (e.g. internal addresses without VPN).</dd>
<dt>The software upload fails with “too large”.</dt><dd>The limit shown above the list is set by the server. The administrator can raise it (see <code>INSTALL.md</code>, section 8).</dd>
<dt>There is no Terminal button.</dt><dd>The terminal is for administrators only and must be enabled on the server (see <code>INSTALL.md</code>, section 7).</dd>
<dt>“HOST KEY CHANGED” in the terminal.</dt><dd>The device presents a different host key than before. If the device was reinstalled on purpose, use <b>Forget stored host key</b> in the connect dialog. Otherwise do not connect and check the device: this can indicate an attack.</dd>
<dt>Copy does not work.</dt><dd>Browsers allow the clipboard only over HTTPS. Use <b>Show</b> and copy manually.</dd>
</dl>
</section>
<footer>CiegoLink · Infrastructure Hub · Manual for version <?=$v?></footer>
</main>
</body></html>
