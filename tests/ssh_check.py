#!/usr/bin/env python3
"""End-to-end test of the SSH terminal: test SSH server + ssh-gateway + PHP API, isolated temp data.
Requires: php-cli, python3 packages asyncssh and aiohttp (pip install -r ssh-gateway/requirements.txt)."""
import asyncio, json, os, pathlib, secrets, socket, subprocess, sys, tempfile, time
import asyncssh, aiohttp

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tests'))
from check import API, PHP, totp  # noqa: E402

def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p

USER_KEY = asyncssh.generate_private_key('ssh-ed25519')
class EchoServer(asyncssh.SSHServer):
    def begin_auth(self, username): return True
    def password_auth_supported(self): return True
    def public_key_auth_supported(self): return True
    def validate_public_key(self, username, key): return username == 'keyuser' and key.public_data == USER_KEY.public_data
    def validate_password(self, username, password): return username == 'tester' and password == 'pw-123-secret'

async def shell(proc):
    proc.stdout.write('hello from test server\r\n')
    while True:
        try: data = await proc.stdin.read(1024)
        except asyncssh.TerminalSizeChanged: continue
        except asyncssh.BreakReceived: break
        if not data or 'exit' in data: break
        proc.stdout.write('echo:' + data)
    proc.exit(0)

async def start_ssh(port, key):
    return await asyncssh.create_server(EchoServer, '127.0.0.1', port, server_host_keys=[key], process_factory=shell)

async def session(base_ws, ticket, send=None):
    out, status = b'', []
    async with aiohttp.ClientSession() as s:
        try:
            async with s.ws_connect(f'{base_ws}/ws?ticket={ticket}') as ws:
                end = time.time() + 8
                while time.time() < end:
                    try: msg = await ws.receive(timeout=1)
                    except asyncio.TimeoutError: continue
                    if msg.type == aiohttp.WSMsgType.BINARY:
                        out += msg.data
                        if send and b'hello' in out:
                            await ws.send_str(json.dumps({'t': 'd', 'd': send})); send = None
                        if b'echo:' in out: break
                    elif msg.type == aiohttp.WSMsgType.TEXT:
                        m = json.loads(msg.data); status.append(m)
                        if m['t'] in ('err', 'closed'): break
                    else: break
        except aiohttp.WSServerHandshakeError as e:
            status.append({'t': 'http', 'm': e.status})
    return out, status

async def main():
    tmp = tempfile.TemporaryDirectory(prefix='infra-ssh-test-'); folder = pathlib.Path(tmp.name)
    secret = secrets.token_hex(32); gport, sport, wport = free_port(), free_port(), free_port()
    conf = folder / 'config.php'
    conf.write_text("<?php return ['data_dir'=>%r,'key'=>'%s','allow_http_local'=>true,'ssh_gateway'=>['url'=>'http://127.0.0.1:%d','secret'=>'%s','ws'=>'/ws']];" % (str(folder), os.urandom(32).hex(), gport, secret))
    env = dict(os.environ, INFRA_CONFIG=str(conf))
    subprocess.run([PHP, '-r', "require $argv[1];schema();foreach(['admin','editor'] as $r)query('INSERT INTO users(username,hash,role,must_change,totp) VALUES(?,?,?,0,?)',[$r,password_hash('Test-only-password-123!',PASSWORD_DEFAULT),$r,enc('JBSWY3DPEHPK3PXP')]);", str(ROOT / 'core.php')], env=env, check=True)
    procs = [subprocess.Popen([PHP, '-S', f'127.0.0.1:{wport}', '-t', str(ROOT / 'public')], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)]
    genv = dict(os.environ, GATEWAY_SECRET=secret, GATEWAY_PORT=str(gport), GATEWAY_KNOWN_HOSTS=str(folder / 'known_hosts.json'))
    procs.append(subprocess.Popen([sys.executable, str(ROOT / 'ssh-gateway' / 'gateway.py')], env=genv, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
    key1, key2 = asyncssh.generate_private_key('ssh-ed25519'), asyncssh.generate_private_key('ssh-ed25519')
    server = await start_ssh(sport, key1)
    try:
        base = f'http://127.0.0.1:{wport}'; gws = f'ws://127.0.0.1:{gport}'
        for _ in range(100):
            try:
                if API(base).call('state')[0] == 200: break
            except OSError: time.sleep(.05)
        time.sleep(.8)
        admin, editor = API(base), API(base)
        assert admin.login('admin')[0] == 200; time.sleep(0.01); assert editor.login('editor', code=totp(step=int(time.time()//30)+1))[0] == 200
        assert admin.call('state')[1]['ssh'] is True and editor.call('state')[1]['ssh'] is False
        assert admin.call('save', {'kind': 'buildings', 'data': {'name': 'Lab'}})[0] == 200
        loc = next(r for r in admin.call('list', {})[1]['records'] if r['kind'] == 'buildings')
        assert admin.call('save', {'kind': 'devices', 'data': {'name': 'Test box', 'building': str(loc['id']), 'internal_ips': '127.0.0.1'}})[0] == 200
        assert admin.call('save', {'kind': 'credentials', 'data': {'name': 'Test login', 'username': 'tester', 'password': 'pw-123-secret'}})[0] == 200
        assert admin.call('save', {'kind': 'credentials', 'data': {'name': 'Wrong login', 'username': 'tester', 'password': 'nope'}})[0] == 200
        recs = admin.call('list', {})[1]['records']
        dev = next(r for r in recs if r['kind'] == 'devices'); good = next(r for r in recs if r['data']['name'] == 'Test login'); bad = next(r for r in recs if r['data']['name'] == 'Wrong login')
        req = {'device_id': dev['id'], 'host': '127.0.0.1', 'port': sport, 'credential_id': good['id']}
        assert editor.call('ssh_ticket', req)[0] == 403, 'editor must not open a terminal'
        assert admin.call('ssh_ticket', {**req, 'host': '10.9.9.9'})[0] == 422, 'host must belong to the device'
        st, t = admin.call('ssh_ticket', req); assert st == 200, t
        assert 'password' not in json.dumps(t) and 'pw-123' not in json.dumps(t), 'login must not reach the browser'
        out, status = await session(gws, t['ticket'], send='abc\r')
        assert any(s['t'] == 'ready' for s in status), status
        assert any('New host key stored' in s.get('m', '') for s in status), status
        assert b'hello from test server' in out and b'echo:abc' in out, out
        out, status = await session(gws, t['ticket']); assert status == [{'t': 'http', 'm': 403}], 'ticket must be single use'
        _, t2 = admin.call('ssh_ticket', {**req, 'credential_id': bad['id']})
        _, status = await session(gws, t2['ticket']); assert any('Authentication failed' in s.get('m', '') for s in status), status
        _, t3 = admin.call('ssh_ticket', {**req, 'credential_id': 0, 'username': 'tester', 'password': 'pw-123-secret'})
        out, status = await session(gws, t3['ticket']); assert b'hello' in out, (out, status)
        server.close(); await server.wait_closed(); server = await start_ssh(sport, key2)  # host key changed
        _, t4 = admin.call('ssh_ticket', req)
        _, status = await session(gws, t4['ticket']); assert any('HOST KEY CHANGED' in s.get('m', '') for s in status), status
        st, r = admin.call('ssh_forget', {'host': '127.0.0.1', 'port': sport}); assert st == 200 and r['removed'], r
        _, t5 = admin.call('ssh_ticket', req)
        out, status = await session(gws, t5['ticket']); assert b'hello' in out, status
        async with aiohttp.ClientSession() as s:
            async with s.post(f'http://127.0.0.1:{gport}/ticket', json={'host': 'x', 'port': 22, 'username': 'x'}) as r: assert r.status == 403, 'ticket endpoint needs the secret'
        # private key with passphrase, stored as file of a credential (passphrase = credential password)
        import base64
        enc = USER_KEY.export_private_key('openssh', passphrase='key-pass-123')
        for name, pw in (('Key login', 'key-pass-123'), ('Key wrong pass', 'nope'), ('Key no pass', '')):
            assert admin.call('save', {'kind': 'credentials', 'data': {'name': name, 'username': 'keyuser', 'password': pw}})[0] == 200
        recs = admin.call('list', {})[1]['records']
        kid = {}
        for name in ('Key login', 'Key wrong pass', 'Key no pass'):
            cid = next(r for r in recs if r['data']['name'] == name)['id']
            assert admin.call('attachment_upload', {'credential_id': cid, 'filename': 'id_ed25519', 'base64': base64.b64encode(enc).decode()})[0] == 200
            kid[name] = (cid, admin.call('attachments', {'credential_id': cid})[1]['files'][0]['id'])
        server.close(); await server.wait_closed(); server = await start_ssh(sport, key2)
        st, tk = admin.call('ssh_ticket', {**req, 'credential_id': kid['Key login'][0], 'key_id': kid['Key login'][1]}); assert st == 200, tk
        out, status = await session(gws, tk['ticket']); assert b'hello' in out and any(x['t'] == 'ready' for x in status), (out, status)
        _, tk = admin.call('ssh_ticket', {**req, 'credential_id': kid['Key wrong pass'][0], 'key_id': kid['Key wrong pass'][1]})
        _, status = await session(gws, tk['ticket']); assert any('Wrong passphrase' in x.get('m', '') for x in status), status
        _, tk = admin.call('ssh_ticket', {**req, 'credential_id': kid['Key no pass'][0], 'key_id': kid['Key no pass'][1]})
        _, status = await session(gws, tk['ticket']); assert any('protected by a passphrase' in x.get('m', '') for x in status), status
        assert admin.call('ssh_ticket', {**req, 'credential_id': good['id'], 'key_id': kid['Key login'][1]})[0] == 404, 'key file must belong to the credential'
        print('PASS: SSH terminal – encrypted ed25519 key login, wrong/missing passphrase messages.')
        audit = admin.call('audit', {})[1]['entries']; assert sum(a['action'] == 'ssh_session' for a in audit) >= 5
        procs[1].terminate(); procs[1].wait(5)
        st, r = admin.call('ssh_ticket', req); assert st == 502 and 'not reachable' in r['error'], r
        print('PASS: SSH terminal – admin only, host bound to device, login stays on server, single-use ticket, echo through PTY, wrong password, manual login, host key TOFU + change blocked + forget, gateway secret, gateway down.')
    finally:
        server.close()
        for p in procs: p.terminate()
        tmp.cleanup()

if __name__ == '__main__':
    asyncio.run(main())
