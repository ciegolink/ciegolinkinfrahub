#!/usr/bin/env python3
"""CiegoLink Infrastructure Hub - SSH gateway.

Bridges a browser terminal (WebSocket) to an SSH server. Runs on localhost only.
  POST /ticket  (from PHP, shared secret)  -> one-time ticket, valid 30 s, holds the login in memory only
  POST /forget  (from PHP, shared secret)  -> forget a stored host key
  GET  /ws?ticket=...  (proxied by Apache)  -> terminal session
Host keys are trusted on first use and stored in GATEWAY_KNOWN_HOSTS; a changed key blocks the connection.
"""
import asyncio, hmac, json, logging, os, secrets, time

import asyncssh
from aiohttp import web, WSMsgType

SECRET = os.environ.get('GATEWAY_SECRET', '')
HOST = os.environ.get('GATEWAY_HOST', '127.0.0.1')
PORT = int(os.environ.get('GATEWAY_PORT', '8022'))
KNOWN = os.environ.get('GATEWAY_KNOWN_HOSTS', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'state', 'known_hosts.json'))
ORIGIN = os.environ.get('GATEWAY_ORIGIN', '').rstrip('/')
IDLE = int(os.environ.get('GATEWAY_IDLE_SECONDS', '1800'))
MAX_SESSION = int(os.environ.get('GATEWAY_MAX_SECONDS', '28800'))
MAX_SESSIONS = int(os.environ.get('GATEWAY_MAX_SESSIONS', '10'))
TICKET_TTL = 30

log = logging.getLogger('ciegolinkinfrahub-ssh')
tickets: dict[str, tuple[float, dict]] = {}
sessions = 0


def load_known() -> dict:
    try:
        with open(KNOWN) as f:
            return json.load(f)
    except FileNotFoundError:
        return {}


def save_known(data: dict) -> None:
    os.makedirs(os.path.dirname(KNOWN), mode=0o700, exist_ok=True)
    tmp = KNOWN + '.tmp'
    with open(os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as f:
        json.dump(data, f, indent=1, sort_keys=True)
    os.replace(tmp, KNOWN)


class HostKeyChanged(Exception):
    pass


class TofuClient(asyncssh.SSHClient):
    """Trust on first use; called by asyncssh before authentication, so no password is sent to an unknown changed host."""

    def __init__(self, host: str, port: int, info: dict):
        self.target, self.info = f'{host}:{port}', info

    def validate_host_public_key(self, host, addr, port, key):
        fp = key.get_fingerprint('sha256')
        known = load_known()
        stored = known.get(self.target)
        self.info['fingerprint'] = fp
        if stored is None:
            known[self.target] = fp
            save_known(known)
            self.info['new_key'] = True
            return True
        if hmac.compare_digest(stored, fp):
            return True
        self.info['changed'] = stored
        return False


def authorized(request: web.Request) -> bool:
    return bool(SECRET) and hmac.compare_digest(request.headers.get('X-Gateway-Secret', ''), SECRET)


async def ticket(request: web.Request):
    if not authorized(request):
        return web.json_response({'error': 'forbidden'}, status=403)
    data = await request.json()
    for k in ('host', 'port', 'username'):
        if not data.get(k):
            return web.json_response({'error': f'missing {k}'}, status=422)
    now = time.monotonic()
    for t, (exp, _) in list(tickets.items()):
        if exp < now:
            tickets.pop(t, None)
    t = secrets.token_urlsafe(32)
    tickets[t] = (now + TICKET_TTL, data)
    return web.json_response({'ticket': t, 'expires_in': TICKET_TTL})


async def forget(request: web.Request):
    if not authorized(request):
        return web.json_response({'error': 'forbidden'}, status=403)
    data = await request.json()
    known = load_known()
    removed = known.pop(f"{data.get('host')}:{int(data.get('port') or 22)}", None) is not None
    save_known(known)
    return web.json_response({'ok': True, 'removed': removed})


async def health(request: web.Request):
    import platform, aiohttp
    return web.json_response({'ok': True, 'sessions': sessions, 'gateway': '1.0.0', 'python': platform.python_version(),
                              'asyncssh': asyncssh.__version__, 'aiohttp': aiohttp.__version__})


async def terminal(request: web.Request):
    global sessions
    if ORIGIN and request.headers.get('Origin', '').rstrip('/') != ORIGIN:
        return web.Response(status=403, text='origin not allowed')
    entry = tickets.pop(request.query.get('ticket', ''), None)
    if not entry or entry[0] < time.monotonic():
        return web.Response(status=403, text='ticket invalid or expired')
    if sessions >= MAX_SESSIONS:
        return web.Response(status=503, text='too many sessions')
    t = entry[1]
    ws = web.WebSocketResponse(heartbeat=25, max_msg_size=1 << 20)
    await ws.prepare(request)
    sessions += 1
    host, port, user = t['host'], int(t['port']), t['username']
    who = t.get('actor', '?')
    info: dict = {}
    started = time.monotonic()

    async def status(kind: str, text: str):
        if not ws.closed:
            await ws.send_str(json.dumps({'t': kind, 'm': text}))

    log.info('open actor=%s target=%s@%s:%s', who, user, host, port)
    conn = None
    try:
        keys = []
        if t.get('private_key'):
            try:
                keys = [asyncssh.import_private_key(t['private_key'], t.get('passphrase') or None)]
            except asyncssh.KeyEncryptionError:
                await status('err', 'Wrong passphrase for the private key. The password of the selected credential is used as passphrase.')
                return ws
            except Exception as e:  # noqa: BLE001 - shown to the admin, never contains key material
                text = str(e)
                if 'Passphrase must be specified' in text:
                    text = 'The private key is protected by a passphrase. Store the passphrase as password of the credential.'
                elif 'bcrypt' in text:
                    text = 'Encrypted keys need the Python package bcrypt: run ssh-gateway/setup.sh again or pip install bcrypt in the gateway venv, then restart the service.'
                else:
                    text = f'Private key could not be read ({text}). Supported: OpenSSH and PEM keys (ed25519, ECDSA, RSA).'
                await status('err', text)
                return ws
        await status('info', f'Connecting to {user}@{host}:{port} …')
        try:
            conn, _ = await asyncio.wait_for(asyncssh.create_connection(
                lambda: TofuClient(host, port, info), host, port, username=user,
                password=t.get('password') or None, client_keys=keys or None,
                # use only the login from the hub: never read ~/.ssh (config, keys, agent identities, X.509 paths)
                config=[], agent_identities=None, x509_trusted_certs=None, x509_trusted_cert_paths=None, gss_host=None,
                known_hosts=([], [], []), server_host_key_algs='default', agent_path=None, preferred_auth='publickey,keyboard-interactive,password',
                keepalive_interval=30), timeout=15)
        except asyncssh.HostKeyNotVerifiable:
            await status('err', f"HOST KEY CHANGED for {host}:{port}. Stored {info.get('changed')}, now {info.get('fingerprint')}. "
                                'Connection blocked. If the server was reinstalled on purpose, use “Forget host key” and connect again.')
            return ws
        except asyncssh.PermissionDenied:
            await status('err', 'Authentication failed (username, password or key).')
            return ws
        except (OSError, asyncio.TimeoutError, asyncssh.Error) as e:
            await status('err', f'Connection failed: {e or type(e).__name__}')
            return ws
        if info.get('new_key'):
            await status('info', f"New host key stored: {info['fingerprint']}")
        cols, rows = int(t.get('cols') or 120), int(t.get('rows') or 32)
        proc = await conn.create_process(term_type='xterm-256color', term_size=(cols, rows), encoding=None)
        await status('ready', f'{user}@{host}')
        last = time.monotonic()

        async def pump(stream):
            while True:
                data = await stream.read(65536)
                if not data:
                    break
                await ws.send_bytes(data)

        out = asyncio.create_task(pump(proc.stdout))
        err = asyncio.create_task(pump(proc.stderr))

        async def watchdog():
            while True:
                await asyncio.sleep(15)
                now = time.monotonic()
                if now - last > IDLE or now - started > MAX_SESSION:
                    await status('err', 'Session closed (idle or maximum duration reached).')
                    await ws.close()
                    return

        dog = asyncio.create_task(watchdog())
        reader = asyncio.ensure_future(ws.receive())
        while True:
            done, _ = await asyncio.wait({reader, out}, return_when=asyncio.FIRST_COMPLETED)
            if out in done:
                break
            msg = reader.result()
            if msg.type != WSMsgType.TEXT:
                break
            last = time.monotonic()
            try:
                m = json.loads(msg.data)
            except ValueError:
                m = {}
            if m.get('t') == 'd' and isinstance(m.get('d'), str):
                proc.stdin.write(m['d'].encode())
            elif m.get('t') == 'r':
                c, r = int(m.get('c', 0)), int(m.get('r', 0))
                if 2 <= c <= 1000 and 2 <= r <= 500:
                    proc.change_terminal_size(c, r)
            reader = asyncio.ensure_future(ws.receive())
        for task in (reader, out, err, dog):
            task.cancel()
        await status('closed', 'Connection closed.')
    finally:
        sessions -= 1
        if conn:
            conn.close()
        if not ws.closed:
            await ws.close()
        log.info('close actor=%s target=%s@%s:%s duration=%ss', who, user, host, port, int(time.monotonic() - started))
    return ws


def app() -> web.Application:
    a = web.Application(client_max_size=64 * 1024)
    a.add_routes([web.post('/ticket', ticket), web.post('/forget', forget), web.get('/health', health), web.get('/ws', terminal)])
    return a


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    logging.getLogger('asyncssh').setLevel(logging.WARNING)
    if len(SECRET) < 32:
        raise SystemExit('GATEWAY_SECRET (at least 32 characters) is required.')
    web.run_app(app(), host=HOST, port=PORT, access_log=None)
