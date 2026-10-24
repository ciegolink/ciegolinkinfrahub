#!/usr/bin/env python3
"""Run isolated API tests with PHP CLI, no production database or credentials used."""
import base64, hashlib, hmac, http.cookiejar, json, os, pathlib, shutil, socket, struct, subprocess, tempfile, time, unittest, urllib.request, urllib.error
ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
if not PHP:
    raise SystemExit('PHP CLI missing. Run the tests on a machine with php-cli and php-sqlite3.')
for file in ROOT.rglob('*.php'):
    subprocess.run([PHP, '-l', str(file)], check=True)
class API:
    def __init__(self, base):
        self.base = base
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''
    def call(self, action, data=None, token=None):
        headers = {'Content-Type':'application/json'}
        if data is not None: headers['X-CSRF-Token'] = self.csrf if token is None else token
        req = urllib.request.Request(self.base+'/api.php?action='+action, data=None if data is None else json.dumps(data).encode(), headers=headers)
        try:
            with self.opener.open(req, timeout=5) as r: status, out = r.status, json.load(r)
        except urllib.error.HTTPError as e:
            status, out = e.code, json.load(e)
        if action == 'state' and status == 200: self.csrf = out['csrf']
        return status, out
    def login(self, name, password='Test-only-password-123!', code=None):
        self.call('state')
        result = self.call('login', {'username':name,'password':password,'code':code or totp()})
        self.call('state')
        return result
SECRET = 'JBSWY3DPEHPK3PXP'
def totp(secret=SECRET, step=None):
    counter = int(time.time()//30) if step is None else step
    digest = hmac.new(base64.b32decode(secret), struct.pack('>Q',counter),hashlib.sha1).digest()
    offset = digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
class SecurityChecks(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='infra-test-')
        cls.folder = pathlib.Path(cls.tmp.name)
        key = os.urandom(32).hex()
        conf = cls.folder/'config.php'
        conf.write_text("<?php return ['data_dir'=>"+repr(str(cls.folder))+", 'key'=>'"+key+"', 'allow_http_local'=>true];")
        cls.env = dict(os.environ, INFRA_CONFIG=str(conf))
        fixture = "require $argv[1];schema();foreach(['admin','editor','reader','pending','replay','keeper'] as $r){query('INSERT INTO users(username,hash,role,must_change,totp) VALUES(?,?,?,?,?)',[$r,password_hash('Test-only-password-123!',PASSWORD_DEFAULT),in_array($r,['admin','editor','reader'])?$r:'reader',$r==='pending'?1:0,$r==='pending'?null:enc('JBSWY3DPEHPK3PXP')]);}"
        subprocess.run([PHP,'-r',fixture,str(ROOT/'core.php')],env=cls.env,check=True)
        s=socket.socket();s.bind(('127.0.0.1',0));port=s.getsockname()[1];s.close()
        cls.base=f'http://127.0.0.1:{port}'
        cls.log=open(cls.folder/'php.log','w')
        cls.proc=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=cls.env,stdout=cls.log,stderr=cls.log)
        for _ in range(60):
            try:
                if API(cls.base).call('state')[0]==200:break
            except OSError:time.sleep(.05)
        else:raise RuntimeError('PHP test server does not start')
        cls.admin=API(cls.base);assert cls.admin.login('admin')[0]==200
        cls.editor=API(cls.base);assert cls.editor.login('editor')[0]==200
        cls.reader=API(cls.base);assert cls.reader.login('reader')[0]==200
    @classmethod
    def tearDownClass(cls):
        cls.proc.terminate();cls.proc.wait(timeout=5);cls.log.close();cls.tmp.cleanup()
    def save(self,k,d,api=None):
        return (api or self.admin).call('save',{'kind':k,'data':d})
    def records(self):return self.admin.call('list',{})[1]['records']
    def test_01_anonymous_and_csrf(self):
        a=API(self.base);a.call('state')
        self.assertEqual(a.call('list',{})[0],401)
        self.assertEqual(self.admin.call('users',{},token='invalid')[0],403)
    def test_02_roles(self):
        self.assertEqual(self.reader.call('users',{})[0],403)
        self.assertEqual(self.editor.call('user_create',{})[0],403)
        self.assertEqual(self.save('buildings',{'name':'Denied'},self.reader)[0],403)
    def test_03_pending_onboarding(self):
        a=API(self.base);self.assertEqual(a.login('pending')[0],200)
        self.assertEqual(a.call('list',{})[0],403)
        self.assertEqual(a.call('password',{'old':'Test-only-password-123!','password':'Updated-test-password-456!'})[0],200)
        secret=a.call('totp_begin',{})[1]['secret']
        self.assertEqual(a.call('totp_confirm',{'code':totp(secret)})[0],200)
        self.assertEqual(a.call('list',{})[0],200)
    def test_04_totp_replay(self):
        # Deliberately use the same known fixture step twice.
        c=totp();a=API(self.base);self.assertEqual(a.login('replay',code=c)[0],200)
        b=API(self.base);self.assertEqual(b.login('replay',code=c)[0],401)
    def test_05_records_ip_and_credentials(self):
        for name in ['Building A','Building B']:
            self.assertEqual(self.save('buildings',{'name':name})[0],200)
        buildings=[r for r in self.records() if r['kind']=='buildings']
        b1,b2=[str(r['id']) for r in buildings]
        self.assertEqual(self.save('devices',{'name':'Device','building':b1,'ip':'198.51.100.10\n2001:db8::10\n198.51.100.10','url':'https://example.com','type':'Server','note':'Wartung am Freitag','software':'Docker: nginx:1.27\nImage: postgres:16'})[0],200)
        self.assertEqual(self.save('ips',{'name':'First address','building':b1,'ip':'10.1.1.1','status':'Reserviert'})[0],200)
        self.assertEqual(self.save('ips',{'name':'Typed','building':b1,'ip':'10.1.1.9','type':'Switch','status':'Assigned'})[0],200)
        self.assertEqual(next(r for r in self.records() if r['data']['name']=='Typed')['data']['type'],'Switch')
        self.assertEqual(self.save('ips',{'name':'Duplicate','building':b1,'ip':'10.1.1.1','status':'Reserviert'})[0],409)
        self.assertEqual(self.save('ips',{'name':'Other building','building':b2,'ip':'10.1.1.1','status':'Reserviert'})[0],200)
        self.assertEqual(self.save('devices',{'name':'Unsafe link','building':b1,'url':'javascript:alert(1)'})[0],400)
        device=next(r for r in self.records() if r['kind']=='devices')
        self.assertEqual(device['data']['software'],'Docker: nginx:1.27\nImage: postgres:16')
        self.assertEqual(device['data']['note'],'Wartung am Freitag')
        self.assertEqual(device['data']['ip'],'198.51.100.10\n2001:db8::10')
        self.assertEqual(self.save('devices',{'name':'Shared external IP','building':b1,'ip':'198.51.100.10'})[0],200)
        self.assertEqual(self.save('devices',{'name':'Invalid external IP','building':b1,'ip':'198.51.100.10\ninvalid'})[0],400)
        self.assertEqual(self.save('credentials',{'name':'Test access','device':str(device['id']),'username':'tester','password':'This-is-a-test-secret'})[0],200)
        credential=next(r for r in self.records() if r['kind']=='credentials')
        self.assertNotIn('password',credential['data'])
        self.assertEqual(self.reader.call('reveal',{'id':credential['id']})[1]['password'],'This-is-a-test-secret')
        self.assertNotIn(b'This-is-a-test-secret',(self.folder/'infra.sqlite').read_bytes())
        wal=self.folder/'infra.sqlite-wal'
        if wal.exists():self.assertNotIn(b'This-is-a-test-secret',wal.read_bytes())
        self.assertEqual(self.admin.call('delete',{'id':device['id'],'revision':device['revision']})[0],409)
        self.assertEqual(self.admin.call('save',{'kind':'devices','id':device['id'],'revision':0,'data':device['data']})[0],409)
    def test_05b_webmin(self):
        b=next(r for r in self.records() if r['kind']=='buildings')['id']
        self.assertEqual(self.save('devices',{'name':'Unsafe webmin','building':str(b),'webmin':'javascript:alert(1)'})[0],400)
        self.assertEqual(self.save('devices',{'name':'Webmin box','building':str(b),'webmin':'https://10.1.1.1:10000'})[0],200)
        self.assertEqual(next(r for r in self.records() if r['data']['name']=='Webmin box')['data']['webmin'],'https://10.1.1.1:10000')
    def test_05c_ping(self):
        b=next(r for r in self.records() if r['kind']=='buildings')['id']
        port=self.base.rsplit(':',1)[1]
        self.assertEqual(self.save('devices',{'name':'Ping target','building':str(b),'internal_ips':'127.0.0.1','ip':'203.0.113.77','webmin':'http://127.0.0.1:'+port+'/'})[0],200)
        dev=next(r for r in self.records() if r['data']['name']=='Ping target')
        st,out=self.reader.call('ping',{'id':dev['id']})
        self.assertEqual(st,200,out)
        res={r['ip']:r for r in out['results']}
        self.assertTrue(res['127.0.0.1']['up'],res)
        self.assertFalse(res['203.0.113.77']['up'],res)
        self.assertEqual(self.reader.call('ping',{'id':999999})[0],404)
        self.assertEqual(API(self.base).call('ping',{'id':dev['id']})[0],403)
    def test_05d_map(self):
        b=next(r for r in self.records() if r['kind']=='buildings')['id']
        self.assertEqual(self.save('devices',{'name':'Map device','building':str(b)})[0],200)
        self.assertEqual(self.save('elements',{'name':'Website','type':'Website','url':'https://www.aw.plus'})[0],200)
        self.assertEqual(self.save('elements',{'name':'Bad','url':'javascript:alert(1)'})[0],400)
        recs=self.records();dev=next(r for r in recs if r['data']['name']=='Map device');el=next(r for r in recs if r['kind']=='elements')
        self.assertEqual(self.save('links',{'name':'HTTPS','from':str(el['id']),'to':str(dev['id'])})[0],200)
        self.assertEqual(self.save('links',{'name':'Self','from':str(el['id']),'to':str(el['id'])})[0],400)
        self.assertEqual(self.save('links',{'name':'To building','from':str(el['id']),'to':str(b)})[0],400)
        self.assertEqual(self.reader.call('position',{'id':dev['id'],'x':100,'y':200})[0],403)
        st,out=self.editor.call('position',{'id':dev['id'],'x':120,'y':999999})
        self.assertEqual(st,200);dev=next(r for r in self.records() if r['id']==dev['id'])
        self.assertEqual((dev['data']['x'],dev['data']['y'],dev['revision']),(120,4000,out['revision']))
        d=dict(dev['data']);d.pop('x');d.pop('y')
        self.assertEqual(self.admin.call('save',{'kind':'devices','id':dev['id'],'revision':dev['revision'],'data':d})[0],200)
        dev=next(r for r in self.records() if r['id']==dev['id']);self.assertEqual(dev['data']['x'],120)
        self.assertEqual(self.admin.call('delete',{'id':el['id'],'revision':el['revision']})[0],200)
        self.assertFalse(any(r['kind']=='links' for r in self.records()))
    def test_05e_display_name(self):
        self.assertEqual(self.admin.call('user_create',{'username':'named-user','display_name':'Anna Muster','password':'Testing-temporary-pass!','role':'editor'})[0],200)
        users=self.admin.call('users',{})[1]['users'];u=next(x for x in users if x['username']=='named-user')
        self.assertEqual(u['display_name'],'Anna Muster')
        self.assertEqual(self.admin.call('user_update',{'id':u['id'],'display_name':'Anna M.','role':'editor','active':True})[0],200)
        me=next(x for x in users if x['username']=='admin')
        self.assertEqual(self.admin.call('user_update',{'id':me['id'],'display_name':'Chef'})[0],200)
        st=self.admin.call('state')[1]['user'];self.assertEqual((st['name'],st['role']),('Chef','admin'))
        self.assertEqual(self.admin.call('user_update',{'id':u['id'],'display_name':'x\u0001','role':'editor','active':True})[0],422)
        self.assertEqual(self.editor.call('user_update',{'id':u['id'],'display_name':'Hack'})[0],403)
    def test_00_migration(self):
        import sqlite3
        db=sqlite3.connect(self.folder/'infra.sqlite');cols=[r[1] for r in db.execute('PRAGMA table_info(users)')];db.close()
        self.assertIn('display_name',cols)
    def test_06_user_revocation(self):
        self.assertEqual(self.admin.call('user_create',{'username':'disabled-test','password':'Testing-temporary-pass!','role':'reader'})[0],200)
        u=next(u for u in self.admin.call('users',{})[1]['users'] if u['username']=='disabled-test')
        a=API(self.base);self.assertEqual(a.login('disabled-test',password='Testing-temporary-pass!')[0],200)
        self.assertEqual(self.admin.call('user_update',{'id':u['id'],'role':'reader','active':False})[0],200)
        self.assertIsNone(a.call('state')[1]['user'])
    def test_07_audit(self):
        entries=self.admin.call('audit',{})[1]['entries']
        self.assertTrue(any(r['action']=='credential_read' for r in entries))
        self.assertFalse(any('This-is-a-test-secret' in str(r) for r in entries))
    def test_08_ratelimit(self):
        a=API(self.base);a.call('state')
        for _ in range(10):a.call('login',{'username':'nonexistent','password':'wrong'})
        self.assertEqual(a.call('login',{'username':'nonexistent','password':'wrong'})[0],429)
    def test_09_crypto_and_rfc_totp(self):
        script = "require $argv[1];if(otp(b32('12345678901234567890'),1)!=='287082')exit(1);$e=enc('roundtrip');if(dec($e)!=='roundtrip')exit(2);$b=base64_decode($e);$b[15]=chr(ord($b[15])^1);try{dec(base64_encode($b));exit(3);}catch(RuntimeException $ex){}"
        subprocess.run([PHP,'-r',script,str(ROOT/'core.php')],env=self.env,check=True)
    def test_10_attachments(self):
        import sqlite3
        credential=next(r for r in self.records() if r['kind']=='credentials')
        cid=credential['id']
        contents=b'SSH encrypted connection test\x00\xff\x01'
        data={'credential_id':cid,'filename':'connection.conf.enc','base64':base64.b64encode(contents).decode()}
        self.assertEqual(self.reader.call('attachment_upload',data)[0],403)
        self.assertEqual(self.editor.call('attachment_upload',data)[0],200)
        files=self.reader.call('attachments',{'credential_id':cid})[1]['files']
        file=next(f for f in files if f['filename']=='connection.conf.enc')
        self.assertNotIn('payload',file)
        downloaded=self.reader.call('attachment_download',{'id':file['id']})[1]
        self.assertEqual(base64.b64decode(downloaded['base64']),contents)
        self.assertEqual(self.reader.call('attachment_delete',{'id':file['id']})[0],403)
        self.assertEqual(self.admin.call('attachment_upload',{**data,'base64':'!not-base64!'})[0],422)
        self.assertEqual(self.admin.call('attachment_upload',{**data,'credential_id':999999})[0],404)
        self.assertEqual(self.admin.call('attachment_upload',{**data,'filename':'..'})[0],422)
        self.assertEqual(self.admin.call('attachment_upload',{**data,'base64':base64.b64encode(b'x'*5242881).decode()})[0],422)
        with sqlite3.connect(self.folder/'infra.sqlite') as con:
            payload=con.execute('SELECT payload FROM attachments WHERE id=?',(file['id'],)).fetchone()[0]
            self.assertNotEqual(base64.b64decode(payload),contents)
        audit=self.admin.call('audit',{})[1]['entries']
        self.assertTrue(any(a['action']=='attachment_download' for a in audit))
        self.assertEqual(self.editor.call('attachment_delete',{'id':file['id']})[0],200)
        self.assertEqual(self.reader.call('attachment_download',{'id':file['id']})[0],404)
    def test_11_standalone_credentials(self):
        self.assertEqual(self.save('credentials',{'name':'Reference only','reference':'CUSTOMER-123','url':'https://example.com/account','device':'','password':''})[0],200)
        record=next(r for r in self.records() if r['data']['name']=='Reference only')
        self.assertEqual(record['data']['reference'],'CUSTOMER-123')
        self.assertEqual(record['data']['device'],'')
        self.assertEqual(self.reader.call('reveal',{'id':record['id']})[1]['password'],'')
        self.assertEqual(self.save('credentials',{'name':'Unsafe URL','url':'javascript:alert(1)'})[0],400)
        self.assertEqual(self.save('credentials',{'name':'Invalid device','device':'999999'})[0],400)
        self.assertEqual(self.reader.call('attachments',{'credential_id':record['id']})[0],200)
    def test_12_ip_admin_and_linked_credentials(self):
        credential=next(r for r in self.records() if r['kind']=='credentials')
        location=next(r for r in self.records() if r['kind']=='buildings')
        entry={'name':'Linked router','building':str(location['id']),'ip':'10.99.88.77','status':'Assigned','url':'https://example.com:8443/','credential_id':str(credential['id'])}
        self.assertEqual(self.save('ips',entry)[0],200)
        record=next(r for r in self.records() if r['data']['name']=='Linked router')
        self.assertEqual(record['data']['credential_id'],str(credential['id']))
        self.assertEqual(record['data']['url'],'https://example.com:8443/')
        self.assertNotIn('password',record['data'])
        self.assertEqual(self.admin.call('delete',{'id':credential['id'],'revision':credential['revision']})[0],409)
        self.assertEqual(self.save('ips',{**entry,'ip':'10.99.88.78','credential_id':'999999'})[0],400)
        self.assertEqual(self.save('ips',{**entry,'ip':'10.99.88.78','credential_id':str(location['id'])})[0],400)
        self.assertEqual(self.save('ips',{**entry,'ip':'10.99.88.78','url':'javascript:alert(1)'})[0],400)
        self.assertEqual(self.save('ips',{**entry,'ip':'10.99.88.78','credential_id':''})[0],200)
    def test_13_multiple_ranges_and_internal_ips(self):
        networks='10.50.0.0/24\n192.168.50.0/24\n2001:db8:50::/64'
        self.assertEqual(self.save('buildings',{'name':'Multi-network location','subnet':networks})[0],200)
        location=next(r for r in self.records() if r['data']['name']=='Multi-network location')
        self.assertEqual(location['data']['subnet'],networks)
        entry={'name':'Multi-network device','building':str(location['id']),'ip':'198.51.100.80','internal_ips':'10.50.0.10\n192.168.50.10\n2001:db8:50::10\n10.50.0.10'}
        self.assertEqual(self.save('devices',entry)[0],200)
        record=next(r for r in self.records() if r['data']['name']=='Multi-network device')
        self.assertEqual(record['data']['ip'],'198.51.100.80')
        self.assertEqual(record['data']['internal_ips'],'10.50.0.10\n192.168.50.10\n2001:db8:50::10')
        self.assertEqual(self.save('devices',{**entry,'name':'Invalid internal','internal_ips':'10.50.0.10\ninvalid'})[0],400)
    def upload(self,api,content,filename='setup.exe',fields=None,token=None):
        boundary='----infra'+os.urandom(8).hex()
        body=b''
        for k,v in (fields or {}).items():body+=f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
        body+=f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode()
        req=urllib.request.Request(api.base+'/software.php?action=upload',data=body,headers={'Content-Type':'multipart/form-data; boundary='+boundary,'X-CSRF-Token':api.csrf if token is None else token})
        try:
            with api.opener.open(req,timeout=5) as r:return r.status,json.load(r)
        except urllib.error.HTTPError as e:return e.code,json.load(e)
    def download(self,api,id):
        try:
            with api.opener.open(api.base+f'/software.php?action=download&id={id}',timeout=5) as r:return r.status,r.read(),dict(r.headers)
        except urllib.error.HTTPError as e:return e.code,e.read(),dict(e.headers)
    def test_14_software_library(self):
        content=os.urandom(200000)+b'\x00end'
        self.assertEqual(self.upload(self.reader,content)[0],403)
        self.assertEqual(self.upload(self.editor,content,token='wrong')[0],403)
        anon=API(self.base);anon.call('state')
        self.assertEqual(self.upload(anon,content)[0],401)
        st,out=self.upload(self.editor,content,'PlyCast Setup ü.exe',{'name':'PlyCast','version':'3.1.0','platform':'Windows','note':'Free playout\nsecond line'})
        self.assertEqual(st,200,out)
        st,out=self.upload(self.admin,b'abc','tool-1.2.tar.gz',{'name':''})
        self.assertEqual(st,200,out)
        st,out=self.reader.call('software',{})
        self.assertEqual(st,200);self.assertGreater(out['max'],0)
        f=next(x for x in out['files'] if x['name']=='PlyCast')
        self.assertNotIn('stored',f)
        self.assertEqual(f['size'],len(content));self.assertEqual(f['sha256'],hashlib.sha256(content).hexdigest())
        self.assertEqual(f['note'],'Free playout\nsecond line')
        self.assertTrue(any(x['name']=='tool-1.2.tar' and x['filename']=='tool-1.2.tar.gz' for x in out['files']))
        st,body,h=self.download(self.reader,f['id'])
        self.assertEqual(st,200);self.assertEqual(body,content)
        self.assertEqual(h['Content-Type'],'application/octet-stream')
        self.assertIn('attachment;',h['Content-Disposition']);self.assertIn("UTF-8''PlyCast%20Setup%20%C3%BC.exe",h['Content-Disposition'])
        self.assertEqual(self.download(anon,f['id'])[0],401)
        self.assertEqual(self.download(self.reader,999999)[0],404)
        stored=list((self.folder/'software').iterdir())
        self.assertTrue(all(len(p.name)==32 for p in stored))
        self.assertTrue(any(p.read_bytes()==content for p in stored))
        self.assertEqual(self.reader.call('software_update',{'id':f['id'],'name':'X'})[0],403)
        self.assertEqual(self.editor.call('software_update',{'id':f['id'],'name':'','version':''})[0],422)
        self.assertEqual(self.editor.call('software_update',{'id':f['id'],'name':'PlyCast WS','version':'3.2','platform':'Windows','note':''})[0],200)
        self.assertTrue(any(x['name']=='PlyCast WS' and x['version']=='3.2' for x in self.reader.call('software',{})[1]['files']))
        audit=self.admin.call('audit',{})[1]['entries']
        self.assertTrue(any(a['action']=='software_download' for a in audit));self.assertTrue(any(a['action']=='software_uploaded' for a in audit))
        self.assertEqual(self.upload(self.editor,b'',fields={'name':'empty'})[0],422)
        self.assertEqual(self.reader.call('software_delete',{'id':f['id']})[0],403)
        before=len(list((self.folder/'software').iterdir()))
        self.assertEqual(self.editor.call('software_delete',{'id':f['id']})[0],200)
        self.assertEqual(len(list((self.folder/'software').iterdir())),before-1)
        self.assertEqual(self.download(self.reader,f['id'])[0],404)
    def test_15_export(self):
        self.assertEqual(self.reader.call('export',{})[0],403)
        self.assertEqual(self.editor.call('export',{})[0],403)
        self.assertEqual(self.save('credentials',{'name':'Export secret','username':'exp','password':'S3cret-für-Export'})[0],200)
        st,out=self.admin.call('export',{})
        self.assertEqual(st,200);self.assertEqual(out['format'],'ciegolink-infrahub-export')
        for k in ['locations','devices','ip_addresses','credentials','elements','connections','software','users']:self.assertIn(k,out)
        c=next(x for x in out['credentials'] if x['name']=='Export secret')
        self.assertNotIn('password',c);self.assertFalse(out['includes_passwords'])
        self.assertNotIn('hash',json.dumps(out['users']));self.assertNotIn('totp',json.dumps(out['users']))
        self.assertEqual(self.admin.call('export',{'passwords':True,'password':'wrong'})[0],403)
        st,out=self.admin.call('export',{'passwords':True,'files':True,'password':'Test-only-password-123!'})
        self.assertEqual(st,200)
        self.assertEqual(next(x for x in out['credentials'] if x['name']=='Export secret')['password'],'S3cret-für-Export')
        d=next((x for x in out['devices'] if x['location_id']),None)
        if d:self.assertIsInstance(d['external_ips'],list);self.assertIsNotNone(d['location'])
        self.assertTrue(any(a['action']=='data_export' and 'passwords' in a['target'] for a in self.admin.call('audit',{})[1]['entries']))
    def test_16_import(self):
        pw='Test-only-password-123!'
        self.assertEqual(self.save('buildings',{'name':'Import RZ','subnet':'10.77.0.0/24'})[0],200)
        loc=next(r for r in self.records() if r['data']['name']=='Import RZ')
        self.assertEqual(self.save('devices',{'name':'Import server','building':str(loc['id']),'ip':'198.51.100.77','internal_ips':'10.77.0.5'})[0],200)
        self.assertEqual(self.save('credentials',{'name':'Import cred','username':'root','password':'Imp0rt-Secret','device':str(next(r for r in self.records() if r['data']['name']=='Import server')['id'])})[0],200)
        self.assertEqual(self.save('elements',{'name':'Import site','url':'https://example.com'})[0],200)
        rs=self.records();el=next(r for r in rs if r['data']['name']=='Import site');dv=next(r for r in rs if r['data']['name']=='Import server')
        self.assertEqual(self.save('links',{'name':'HTTPS','from':str(el['id']),'to':str(dv['id'])})[0],200)
        self.assertEqual(self.save('ips',{'name':'Import ip','building':str(loc['id']),'ip':'10.77.0.9','status':'Assigned'})[0],200)
        export=self.admin.call('export',{'passwords':True,'password':pw})[1]
        before=len(self.records())
        self.assertEqual(self.editor.call('import',{'password':pw,'data':export})[0],403)
        self.assertEqual(self.admin.call('import',{'password':'wrong','data':export})[0],403)
        self.assertEqual(self.admin.call('import',{'password':pw,'data':{'format':'other'}})[0],422)
        bad=json.loads(json.dumps(export));bad['devices'][0]['external_ips']=['999.1.1.1']
        self.assertEqual(self.admin.call('import',{'password':pw,'data':bad})[0],422)
        self.assertEqual(len(self.records()),before)  # rolled back
        st,out=self.admin.call('import',{'password':pw,'data':export})
        self.assertEqual(st,200,out);self.assertEqual(out['counts']['devices'],0);self.assertGreater(out['counts']['existing'],0)
        self.assertEqual(len(self.records()),before)  # merge of identical data creates nothing new
        st,out=self.admin.call('import',{'password':pw,'replace':True,'data':export})
        self.assertEqual(st,200,out)
        rs=self.records();self.assertEqual(out['counts']['existing'],0)
        self.assertEqual(sum(1 for r in rs if r['kind']=='devices'),len(export['devices']))
        c=next(r for r in rs if r['data']['name']=='Import cred');d=next(r for r in rs if r['data']['name']=='Import server')
        self.assertEqual(c['data']['device'],str(d['id']))
        self.assertEqual(self.admin.call('reveal',{'id':c['id']})[1]['password'],'Imp0rt-Secret')
        l=next(r for r in rs if r['kind']=='links' and r['data']['name']=='HTTPS');self.assertEqual(l['data']['to'],str(d['id']))
        self.assertEqual(d['data']['internal_ips'],'10.77.0.5')
        self.assertTrue(any(a['action']=='data_import' for a in self.admin.call('audit',{})[1]['entries']))
        self.assertEqual(self.reader.call('state')[0],200)
    def test_17_racks(self):
        self.assertEqual(self.save('buildings',{'name':'Rack RZ'})[0],200)
        loc=next(r for r in self.records() if r['data']['name']=='Rack RZ');b=str(loc['id'])
        self.assertEqual(self.save('devices',{'name':'Rack server','building':b,'type':'Server','internal_ips':'10.88.0.5'})[0],200)
        self.assertEqual(self.save('ips',{'name':'Rack server','building':b,'ip':'10.88.0.5','status':'Assigned'})[0],200)
        rs=self.records();dv=next(r for r in rs if r['kind']=='devices' and r['data']['name']=='Rack server');ip=next(r for r in rs if r['kind']=='ips' and r['data']['ip']=='10.88.0.5')
        self.assertEqual(self.save('racks',{'name':'A1','building':b,'height':'12'},self.reader)[0],403)
        self.assertEqual(self.save('racks',{'name':'Bad','building':b,'height':'99'})[0],422)
        self.assertEqual(self.save('racks',{'name':'No location','building':''})[0],400)
        st,out=self.save('racks',{'name':'A1','building':b,'height':'12'});self.assertEqual(st,200,out)
        rack=next(r for r in self.records() if r['kind']=='racks' and r['data']['name']=='A1');rid=str(rack['id'])
        self.assertEqual(self.save('racks',{'name':'Default','building':b,'height':''})[0],200)
        self.assertEqual(next(r for r in self.records() if r['kind']=='racks' and r['data']['name']=='Default')['data']['height'],'42')
        m={'rack':rid,'position':'3','size':'2','device':str(dv['id']),'ip_id':str(ip['id']),'name':'','type':'Server','note':''}
        st,out=self.save('mounts',m);self.assertEqual(st,200,out)
        mount=next(r for r in self.records() if r['kind']=='mounts');self.assertEqual(mount['data']['name'],'Rack server')
        self.assertEqual(self.save('mounts',{**m,'position':'4','size':'1','device':'','ip_id':'','name':'Patch'})[0],409)  # overlap U4
        self.assertEqual(self.save('mounts',{**m,'position':'12','size':'2','device':'','ip_id':'','name':'Too high'})[0],422)
        self.assertEqual(self.save('mounts',{**m,'position':'0','device':'','ip_id':'','name':'Zero'})[0],422)
        self.assertEqual(self.save('mounts',{**m,'position':'5','size':'1','device':'','ip_id':'','name':''})[0],400)  # no name, no link
        self.assertEqual(self.save('mounts',{**m,'position':'5','size':'1','device':'999999','ip_id':'','name':'X'})[0],400)
        self.assertEqual(self.save('mounts',{**m,'position':'1','size':'1','device':'','ip_id':'','name':'Patch panel'})[0],200)
        self.assertEqual(self.admin.call('save',{'kind':'mounts','id':mount['id'],'revision':mount['revision'],'data':{**m,'position':'1','name':'Rack server'}})[0],409)  # U1-U2 overlaps patch panel at U1
        rack=next(r for r in self.records() if r['id']==rack['id'])
        self.assertEqual(self.admin.call('save',{'kind':'racks','id':rack['id'],'revision':rack['revision'],'data':{'name':'A1','building':b,'height':'3','note':''}})[0],409)  # item up to U4
        self.assertEqual(self.admin.call('delete',{'id':ip['id'],'revision':ip['revision']})[0],409)
        self.assertEqual(self.admin.call('delete',{'id':dv['id'],'revision':dv['revision']})[0],409)
        exp=self.admin.call('export',{})[1]
        self.assertEqual(next(x for x in exp['racks'] if x['name']=='A1')['height'],12)
        it=next(x for x in exp['rack_items'] if x['name']=='Rack server');self.assertEqual((it['position'],it['size'],it['ip'],it['device']),(3,2,'10.88.0.5','Rack server'))
        st,out=self.admin.call('import',{'password':'Test-only-password-123!','replace':True,'data':exp});self.assertEqual(st,200,out)
        self.assertEqual(out['counts']['racks'],len(exp['racks']));self.assertEqual(out['counts']['rack_items'],len(exp['rack_items']))
        rs=self.records();nr=next(r for r in rs if r['kind']=='racks' and r['data']['name']=='A1');nm=next(r for r in rs if r['kind']=='mounts' and r['data']['name']=='Rack server')
        self.assertEqual(nm['data']['rack'],str(nr['id']));self.assertEqual(get_kind:=next(r for r in rs if r['id']==int(nm['data']['ip_id']))['data']['ip'],'10.88.0.5')
        st,out=self.admin.call('import',{'password':'Test-only-password-123!','data':exp});self.assertEqual(out['counts']['rack_items'],0)
        self.assertEqual(self.admin.call('delete',{'id':nr['id'],'revision':nr['revision']})[0],200)
        self.assertFalse(any(r['kind']=='mounts' and r['data']['rack']==str(nr['id']) for r in self.records()))
    def test_18_rack_halves_and_version(self):
        st,out=self.admin.call('state');self.assertEqual(out['version'],(ROOT/'VERSION').read_text().strip())
        self.assertEqual(self.save('buildings',{'name':'Half RZ'})[0],200)
        b=str(next(r for r in self.records() if r['data']['name']=='Half RZ')['id'])
        self.assertEqual(self.save('racks',{'name':'H1','building':b,'height':'10'})[0],200)
        rid=str(next(r for r in self.records() if r['kind']=='racks' and r['data']['name']=='H1')['id'])
        m={'rack':rid,'position':'5','size':'1','device':'','ip_id':'','type':'','note':''}
        self.assertEqual(self.save('mounts',{**m,'name':'Left','width':'left'})[0],200)
        self.assertEqual(self.save('mounts',{**m,'name':'Right','width':'right'})[0],200)
        self.assertEqual(self.save('mounts',{**m,'name':'Left2','width':'left'})[0],409)
        self.assertEqual(self.save('mounts',{**m,'name':'Full','width':'full'})[0],409)
        self.assertEqual(self.save('mounts',{**m,'name':'Bad','width':'middle'})[0],422)
        self.assertEqual(self.save('mounts',{**m,'position':'6','name':'Default'})[0],200)
        self.assertEqual(next(r for r in self.records() if r['kind']=='mounts' and r['data']['name']=='Default')['data']['width'],'full')
        self.assertEqual(self.save('mounts',{**m,'position':'6','name':'Half on full','width':'right'})[0],409)
        exp=self.admin.call('export',{})[1];self.assertEqual(exp['app_version'],out['version'])
        self.assertEqual({x['name']:x['width'] for x in exp['rack_items'] if x['rack']=='H1'},{'Left':'left','Right':'right','Default':'full'})
        st,o=self.admin.call('import',{'password':'Test-only-password-123!','replace':True,'data':exp});self.assertEqual(st,200,o)
        self.assertEqual(sorted(r['data']['width'] for r in self.records() if r['kind']=='mounts' and r['data']['name'] in ('Left','Right')),['left','right'])
    def test_19_audit_paging_and_system(self):
        for i in range(60):self.admin.call('reveal',{'id':next(r for r in self.records() if r['kind']=='credentials')['id']}) if i==0 else None
        st,d=self.admin.call('audit',{});self.assertEqual(st,200);self.assertLessEqual(len(d['entries']),50);self.assertEqual(d['per'],50);self.assertGreaterEqual(d['total'],len(d['entries']))
        if d['pages']>1:
            p2=self.admin.call('audit',{'page':2})[1];self.assertEqual(p2['page'],2);self.assertNotEqual(p2['entries'][0],d['entries'][0])
        s=self.admin.call('audit',{'q':'credential_read'})[1];self.assertTrue(s['entries']);self.assertTrue(all('credential_read' in json.dumps(e) for e in s['entries']))
        self.assertEqual(self.admin.call('audit',{'q':'%'})[1]['total'],0)  # % is literal, not a wildcard
        self.assertEqual(self.admin.call('audit',{'page':99999})[1]['page'],self.admin.call('audit',{})[1]['pages'])
        self.assertEqual(self.reader.call('audit',{})[0],403);self.assertEqual(self.editor.call('system',{})[0],403)
        st,sy=self.admin.call('system',{});self.assertEqual(st,200);self.assertEqual(sy['app']['version'],(ROOT/'VERSION').read_text().strip())
        self.assertIn('PHP',sy['server']);self.assertTrue(any(c['name']=='xterm.js' for c in sy['components']));self.assertFalse(sy['gateway']['configured'])
    def test_20_docs(self):
        md='# Restart playout\n\nUse **systemctl** and *check* the log.\n\n- step one\n- step two\n\n| A | B |\n| --- | --- |\n| 1 | 2 |\n'
        self.assertEqual(self.reader.call('doc_save',{'title':'X','body':'y'})[0],403)
        self.assertEqual(self.editor.call('doc_save',{'title':'','body':'y'})[0],422)
        st,r=self.editor.call('doc_save',{'title':'Restart playout','category':'Runbooks','body':md});self.assertEqual(st,200,r);did=r['id']
        d=self.reader.call('doc',{'id':did})[1]['doc'];self.assertEqual(d['body'],md);self.assertEqual(d['category'],'Runbooks');self.assertEqual(d['revision'],1)
        self.assertTrue(any(x['title']=='Restart playout' for x in self.reader.call('docs',{})[1]['docs']))
        self.assertEqual([x['id'] for x in self.reader.call('docs',{'q':'systemctl'})[1]['docs']],[did])
        self.assertEqual(self.reader.call('docs',{'q':'nothing-like-this'})[1]['docs'],[])
        self.assertEqual(self.reader.call('docs',{'q':'%'})[1]['docs'],[])
        st,r=self.admin.call('doc_save',{'id':did,'revision':1,'title':'Restart playout','category':'Runbooks','body':md+'\nmore\n'});self.assertEqual(st,200);self.assertEqual(r['revision'],2)
        st,r=self.editor.call('doc_save',{'id':did,'revision':1,'title':'Old','body':'stale'});self.assertEqual(st,409)  # stale revision
        self.assertEqual(self.editor.call('doc_save',{'title':'Big','body':'x'*1048577})[0],422)
        self.assertEqual(self.editor.call('doc_save',{'title':'Ctrl','body':'a\x00b'})[0],422)
        exp=self.admin.call('export',{})[1];self.assertTrue(any(x['title']=='Restart playout' and '**systemctl**' in x['markdown'] for x in exp['docs']))
        self.assertEqual(self.admin.call('doc_delete',{'id':did,'revision':2})[0],200);self.assertEqual(self.reader.call('doc',{'id':did})[0],404)
        st,o=self.admin.call('import',{'password':'Test-only-password-123!','data':exp});self.assertEqual(st,200,o);self.assertEqual(o['counts']['docs'],len(exp['docs']))
        self.assertTrue(any(x['title']=='Restart playout' for x in self.reader.call('docs',{})[1]['docs']))
        self.assertTrue(any(a['action']=='doc_saved' for a in self.admin.call('audit',{'q':'doc_saved'})[1]['entries']))
    def test_21_remember_me(self):
        k=API(self.base);k.call('state')
        self.assertEqual(k.call('login',{'username':'keeper','password':'Test-only-password-123!','code':totp(),'remember':True})[0],200)
        jar=k.opener.handlers[[type(h).__name__ for h in k.opener.handlers].index('HTTPCookieProcessor')].cookiejar
        names={c.name for c in jar};self.assertIn('infra_remember',names)
        tok=next(c.value for c in jar if c.name=='infra_remember')
        jar.clear(domain=next(c.domain for c in jar),path='/',name='infra_session')
        self.assertEqual(k.call('state')[1]['user']['username'],'keeper')
        self.assertEqual(k.call('list',{})[0],200)
        forged=API(self.base);forged.opener.addheaders=[('Cookie','infra_remember='+tok.split('.')[0]+'.'+'0'*64)]
        self.assertIsNone(forged.call('state')[1]['user'])
        thief=API(self.base);thief.opener.addheaders=[('Cookie','infra_remember='+tok)]
        self.assertEqual(thief.call('state')[1]['user']['username'],'keeper')
        self.assertEqual(k.call('logout',{})[0],200)
        replay=API(self.base);replay.opener.addheaders=[('Cookie','infra_remember='+tok)]
        self.assertIsNone(replay.call('state')[1]['user'])
        plain=API(self.base);plain.call('state')
        self.assertNotIn('infra_remember',{c.name for c in plain.opener.handlers[[type(h).__name__ for h in plain.opener.handlers].index('HTTPCookieProcessor')].cookiejar})
    def test_22_credential_folders(self):
        self.assertEqual(self.save('credentials',{'name':'Folder A1','folder':'  Kunden   Star TV ','username':'a','password':'x-secret-1'})[0],200)
        self.assertEqual(self.save('credentials',{'name':'Folder A2','folder':'Kunden Star TV','username':'b','password':'x-secret-2'})[0],200)
        self.assertEqual(self.save('credentials',{'name':'Folder long','folder':'x'*81,'username':'c','password':'x'})[0],422)
        recs=[r for r in self.records() if r['kind']=='credentials' and r['data']['name'].startswith('Folder A')]
        self.assertEqual({r['data']['folder'] for r in recs},{'Kunden Star TV'})
        self.assertEqual(self.reader.call('folder_rename',{'from':'Kunden Star TV','to':'X'})[0],403)
        st,out=self.editor.call('folder_rename',{'from':'Kunden Star TV','to':'Star TV'});self.assertEqual((st,out['changed']),(200,2))
        self.assertEqual({r['data']['folder'] for r in self.records() if r['kind']=='credentials' and r['data']['name'].startswith('Folder A')},{'Star TV'})
        self.assertIn('Star TV',[c.get('folder') for c in self.admin.call('export',{})[1]['credentials']])
    def test_23_maintenance_api(self):
        import datetime as dt
        now=dt.datetime.now(dt.timezone.utc).replace(microsecond=0)
        iso=lambda d:d.strftime('%Y-%m-%dT%H:%M:%S.000Z')
        self.assertEqual(self.save('buildings',{'name':'Maint RZ'})[0],200);loc=next(r for r in self.records() if r['data'].get('name')=='Maint RZ')
        self.assertEqual(self.save('devices',{'name':'Playout 1','building':str(loc['id']),'type':'Playout server'})[0],200);dev=next(r for r in self.records() if r['data'].get('name')=='Playout 1')
        self.assertEqual(self.save('maintenance',{'name':'Bad dev','devices':'999999','start':iso(now),'duration':'5'})[0],422)
        self.assertEqual(self.save('maintenance',{'name':'Running now','devices':str(dev['id'])+'\n'+str(dev['id']),'start':iso(now-dt.timedelta(minutes=10)),'duration':'60','services':'Playout\nSRT','message':'Short interruptions','note':'secret internal','state':'planned','public':'1'})[0],200)
        self.assertEqual(self.save('maintenance',{'name':'Next week','start':iso(now+dt.timedelta(days=7)),'duration':'120','state':'planned','public':'1'})[0],200)
        self.assertEqual(self.save('maintenance',{'name':'Hidden','start':iso(now+dt.timedelta(days=1)),'duration':'30','state':'planned','public':'0'})[0],200)
        self.assertEqual(self.save('maintenance',{'name':'Bad','start':'tomorrow','duration':'30'})[0],422)
        self.assertEqual(self.save('maintenance',{'name':'Bad','start':iso(now),'duration':'0'})[0],422)
        self.assertEqual(self.save('maintenance',{'name':'Reader','start':iso(now),'duration':'5'},self.reader)[0],403)
        self.assertEqual(self.editor.call('api_token_create',{'name':'x'})[0],403)
        st,out=self.admin.call('api_token_create',{'name':'Website'});self.assertEqual(st,200);tok=out['token']
        def get(q='',headers=None):
            req=urllib.request.Request(self.base+'/maintenance.php'+q,headers=headers or {})
            try:
                with urllib.request.urlopen(req,timeout=5) as r:return r.status,json.load(r)
            except urllib.error.HTTPError as e:return e.code,json.load(e)
        self.assertEqual(get()[0],401)
        self.assertEqual(get('?token=ihm_'+'0'*48)[0],401)
        st,out=get(headers={'Authorization':'Bearer '+tok});self.assertEqual(st,200)
        self.assertTrue(out['maintenance_active']);self.assertEqual([i['title'] for i in out['items']],['Running now','Next week'])
        self.assertEqual(out['active'][0]['devices'],[{'id':dev['id'],'name':'Playout 1','type':'Playout server'}]);self.assertEqual(out['active'][0]['services'],['Playout','SRT']);self.assertNotIn('note',out['active'][0]);self.assertNotIn('secret internal',json.dumps(out))
        self.assertEqual(out['next']['title'],'Next week');self.assertEqual(out['next']['duration_minutes'],120)
        self.assertEqual([i['title'] for i in get('?scope=upcoming&days=3&token='+tok)[1]['items']],[])
        self.assertEqual(get('?scope=nope',{'X-Api-Token':tok})[0],422)
        tid=self.admin.call('api_tokens',{})[1]['tokens'][0]['id']
        self.assertIsNotNone(self.admin.call('api_tokens',{})[1]['tokens'][0]['last_used'])
        self.assertEqual(self.admin.call('api_token_delete',{'id':tid})[0],200)
        self.assertEqual(get(headers={'Authorization':'Bearer '+tok})[0],401)
        pw='Test-only-password-123!';export=self.admin.call('export',{'passwords':True,'password':pw})[1]
        self.assertEqual({m['title'] for m in export['maintenance']},{'Running now','Next week','Hidden'})
        self.assertEqual(next(m for m in export['maintenance'] if m['title']=='Running now')['devices'],['Playout 1'])
        st,out=self.admin.call('import',{'password':pw,'data':export});self.assertEqual(st,200,out);self.assertEqual(out['counts']['maintenance'],0)
        m=next(r for r in self.records() if r['kind']=='maintenance' and r['data']['name']=='Running now')
        self.assertEqual((m['data']['services'],m['data']['note'],m['data']['public']),('Playout\nSRT','secret internal','1'))
        self.assertEqual(next(r for r in self.records() if r['data'].get('name')=='Folder A1')['data']['folder'],'Star TV')
if __name__=='__main__':unittest.main(verbosity=2)
