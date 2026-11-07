const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),path=require('path');
const nodes=new Map();const el=s=>{if(!nodes.has(s))nodes.set(s,{innerHTML:'',textContent:'',hidden:false,disabled:false,querySelector:el,querySelectorAll:()=>[],close(){},showModal(){},reset(){},addEventListener(){},classList:{toggle(){},add(){},remove(){}},style:{},setAttribute(){}});return nodes.get(s)};
const ctx={document:{querySelector:el,querySelectorAll:()=>[],addEventListener(){}},setTimeout(){},clearTimeout(){},setInterval(){},URL,console};vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/secure.js'),'utf8').replace(';run(init);',';'),ctx);
(async()=>{
vm.runInContext("workspaces=[{id:1,name:'Main workspace',note:'',role:'user',records:3,docs:0}];wsId=1;user={id:1,role:'admin'};records=[{id:1,kind:'buildings',data:{name:'Office'}},{id:2,kind:'devices',data:{name:'Server',building:'1',ip:'198.51.100.10\\n2001:db8::10',note:'Maintenance Friday',software:'Docker nginx:1.27'}},{id:3,kind:'credentials',data:{name:'SSH access',device:'2',username:'admin'}}];page='devices';render();edit('devices',2)",ctx);
assert(nodes.get('#fields').innerHTML.includes('class="field-wide">Notes'));
assert(nodes.get('#fields').innerHTML.includes('Installed software / packages / Docker images'));
assert(nodes.get('#fields').innerHTML.includes('External IP addresses'));
assert(nodes.get('#fields').innerHTML.includes('data-add-row="ip"'));
assert(nodes.get('#fields').innerHTML.includes('value="2001:db8::10"'));
assert(nodes.get('#fields').innerHTML.includes('name="webmin"'));
vm.runInContext("search='NGINX:1.27';filter='1'",ctx);assert.equal(vm.runInContext("subset('devices').length",ctx),1);
vm.runInContext("search='2001:db8::10'",ctx);assert.equal(vm.runInContext("subset('devices').length",ctx),1);
vm.runInContext("page='credentials';search='';render();api=async()=>({files:[{id:1,filename:'<img src=x onerror=alert(1)>.enc',size:12,created_at:'2026-10-22'}]});",ctx);
assert(nodes.get('#main').innerHTML.includes('data-files="3"'));
await vm.runInContext('showFiles(3)',ctx);
assert(nodes.get('#fields').innerHTML.includes('&lt;img'));
assert(!nodes.get('#fields').innerHTML.includes('<img src=x'));
assert(nodes.get('#fields').innerHTML.includes('id="upload-file"'));
assert.equal(el('.dialogactions button.primary').hidden,true);
vm.runInContext("closeModal();user.role='reader'",ctx);
await vm.runInContext('showFiles(3)',ctx);
assert(!nodes.get('#fields').innerHTML.includes('id="upload-file"'));
assert(!nodes.get('#fields').innerHTML.includes('data-remove-file='));
assert(nodes.get('#fields').innerHTML.includes('data-download-file='));
vm.runInContext("closeModal();edit('credentials',3)",ctx);
assert(nodes.get('#fields').innerHTML.includes('Device (optional)'));
assert(nodes.get('#fields').innerHTML.includes('Link (optional)'));
assert(nodes.get('#fields').innerHTML.includes('ID / reference (optional)'));
assert(!nodes.get('#fields').innerHTML.includes('name=\"device\" required'));
vm.runInContext("closeModal();page='credentials';filter='';search='';render()",ctx);
assert(nodes.get('#main').innerHTML.includes('data-copy-password=\"3\"'));
vm.runInContext("closeModal();user.role='admin';records.push({id:4,kind:'ips',revision:1,data:{name:'Router',building:'1',ip:'10.1.1.1',status:'Assigned',url:'https://example.com:8443/',credential_id:'3'}});page='ips';search='';filter='';render();",ctx);
assert(nodes.get('#main').innerHTML.includes('aria-label="Open admin page"'));
assert(nodes.get('#main').innerHTML.includes('https://example.com:8443/'));
assert(nodes.get('#main').innerHTML.includes('aria-label="Copy password"'));
vm.runInContext("records[0].data.subnet='10.1.1.0/24 LAN\\n10.2.0.0/16\\nOld note';ipTab='';render()",ctx);
assert(nodes.get('#main').innerHTML.includes('data-iptab="1|10.1.1.0/24 LAN"'));
assert(!nodes.get('#main').innerHTML.includes('Old note'));
vm.runInContext("ipTab='1|10.2.0.0/16';render()",ctx);assert(nodes.get('#main').innerHTML.includes('No records yet.'));
vm.runInContext("ipTab='1|10.1.1.0/24 LAN';render()",ctx);assert(nodes.get('#main').innerHTML.includes('<code>10.1.1.1</code>'));
assert.equal(vm.runInContext("inCidr('2001:db8::5',cidr('2001:db8::/64'))",ctx),true);
assert.equal(vm.runInContext("inCidr('2001:db9::5',cidr('2001:db8::/64'))",ctx),false);
vm.runInContext("ipTab='';records[0].data.subnet=''",ctx);
assert(nodes.get('#main').innerHTML.includes('SSH access'));
vm.runInContext("search='SSH access'",ctx);assert.equal(vm.runInContext("subset('ips').length",ctx),1);
vm.runInContext("edit('ips',4)",ctx);
assert(nodes.get('#fields').innerHTML.includes('Web admin access (optional)'));
assert(nodes.get('#fields').innerHTML.includes('name=\"credential_id\"'));
assert(nodes.get('#fields').innerHTML.includes('No linked credential'));
vm.runInContext("closeModal();get(1).data.subnet='10.10.0.0/24\\n192.168.2.0/24';get(2).data.internal_ips='10.10.0.11\\n192.168.2.11';page='devices';search='192.168.2.11';filter='1';render();",ctx);
assert.equal(vm.runInContext("subset('devices').length",ctx),1);
assert(nodes.get('#main').innerHTML.includes('INTERNAL IP ADDRESSES'));
assert(nodes.get('#main').innerHTML.includes('192.168.2.11'));
vm.runInContext("edit('devices',2)",ctx);
assert(nodes.get('#fields').innerHTML.includes('name=\"internal_ips\"'));
vm.runInContext("closeModal();edit('buildings',1)",ctx);
assert(nodes.get('#fields').innerHTML.includes('name=\"subnet\" data-multi'));
assert(nodes.get('#fields').innerHTML.includes('value=\"192.168.2.0/24\"'));
vm.runInContext("closeModal();page='buildings';search='192.168.2.0';render()",ctx);
assert(nodes.get('#main').innerHTML.includes('NETWORKS / IP RANGES'));
assert.equal(vm.runInContext("subset('buildings').length",ctx),1);
vm.runInContext("records.push({id:9,kind:'ips',revision:1,data:{name:'B',building:'1',ip:'10.0.0.20',status:'Assigned'}},{id:10,kind:'ips',revision:1,data:{name:'A',building:'1',ip:'10.0.0.3',status:'Assigned'}},{id:11,kind:'ips',revision:1,data:{name:'C',building:'1',ip:'10.0.0.255',status:'Assigned'}});page='ips';ipTab='';filter='';search='10.0.0.';render()",ctx);
{const h=nodes.get('#main').innerHTML;assert(h.indexOf('10.0.0.3<')<h.indexOf('10.0.0.20<')&&h.indexOf('10.0.0.20<')<h.indexOf('10.0.0.255<'));}
assert.equal(vm.runInContext("ipSort('10.0.0.255\\n2001:db8::1\\n10.0.0.3\\n10.0.0.20')",ctx),'10.0.0.3\n10.0.0.20\n10.0.0.255\n2001:db8::1');
vm.runInContext("closeModal();edit('devices',2)",ctx);assert(nodes.get('#fields').innerHTML.includes('Web admin access (optional)'));assert(!nodes.get('#fields').innerHTML.includes('connection URL'));
vm.runInContext("page='devices';search='';filter='';pings[2]={at:'2026-10-22T20:00:00Z',results:[{ip:'198.51.100.10',type:'external',up:true,ms:0.4,method:'icmp'},{ip:'2001:db8::10',type:'external',up:false,ms:null,method:'icmp'}]};render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('data-ping="2"'));assert(m.includes('data-ping-all="1"'));assert(m.includes('dot up'));assert(m.includes('dot down'));assert(m.includes('1/2 online'));}
vm.runInContext("closeModal();user.role='admin';records.push({id:20,kind:'elements',revision:1,data:{name:'Website',type:'Website',url:'https://www.aw.plus'}},{id:21,kind:'links',revision:1,data:{name:'HTTPS',from:'20',to:'2'}});page='map';render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('No map yet'));assert(m.includes('data-new="maps"'));assert(!m.includes('data-node='));}
vm.runInContext("records.push({id:22,kind:'maps',revision:1,data:{name:'Streaming <s>',note:''}},{id:23,kind:'maps',revision:1,data:{name:'Empty',note:''}});mapNodesData=[{map:22,node:20,x:40,y:40},{map:22,node:2,x:400,y:40}];mapId=22;render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('data-node="20"'));assert(m.includes('data-node="2"'));assert(m.includes('www.aw.plus'));assert(m.includes('data-label="21"'));assert(m.includes('data-handle="20"'));assert(m.includes('data-maptab="22"'));assert(m.includes('Streaming &lt;s&gt;'));assert(m.includes('data-map-hide="2"'));assert(m.includes('data-zoom="fit"'));assert(m.includes('data-zoom="in"'));assert(m.includes('data-map-add="1"'));assert(m.includes('id="mapsizer"'));}
vm.runInContext("mapNodesData=mapNodesData.filter(n=>n.node!==2);render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('data-node="20"'));assert(!m.includes('data-node="2"'));assert(!m.includes('data-label="21"'));}
vm.runInContext("mapAdd()",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('value="2"'));assert(!f.includes('value="20"'));assert(f.includes('id="map-all"'));}
vm.runInContext("closeModal();edit('links',0,{from:'20',to:''})",ctx);assert(!nodes.get('#fields').innerHTML.includes('<option value="2"'));
assert.deepEqual(JSON.parse(vm.runInContext("JSON.stringify(mapSpots(5))",ctx)),[{x:40,y:178},{x:330,y:178},{x:620,y:178},{x:910,y:178},{x:40,y:308}]);
vm.runInContext("closeModal();mapId=23;render()",ctx);{const m=nodes.get('#main').innerHTML;assert(m.includes('This map is empty'));assert(!m.includes('data-zoom='));}
vm.runInContext("edit('maps',23)",ctx);assert(nodes.get('#fields').innerHTML.includes('Delete map'));
vm.runInContext("closeModal();user.role='reader';render()",ctx);{const m=nodes.get('#main').innerHTML;assert(!m.includes('data-new="maps"'));assert(!m.includes('data-map-add'));}
vm.runInContext("user.role='reader';mapId=22;mapNodesData.push({map:22,node:2,x:400,y:40});render()",ctx);{const m=nodes.get('#main').innerHTML;assert(m.includes('data-zoom="fit"'));assert(!m.includes('data-map-hide'));}
vm.runInContext("user.role='admin';workspaces=[{id:1,name:'Main workspace',note:'',records:5,docs:1},{id:2,name:'Kunde <AG>',note:'Vertrag 2026',records:0,docs:0}];wsId=1;renderWs();page='overview';render()",ctx);
{const w=nodes.get('#wsbox').innerHTML;assert(w.includes('id="ws-select"'));assert(w.includes('Kunde &lt;AG&gt;'));assert(w.includes('value="1" selected'));assert(!w.includes('data-ws-manage'));assert(!w.includes('<button'));}
assert.equal(nodes.get('#crumb').textContent,'Main workspace / Overview');assert(nodes.get('#main').innerHTML.includes('Workspace <b>Main workspace</b>'));
await vm.runInContext("acct('workspaces')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('data-acct="workspaces"'));assert(f.includes('name="name"'));assert(f.includes('data-ws-open="2"'));assert(f.includes('data-ws-del="2"'));assert(f.includes('Kunde &lt;AG&gt;'));assert(f.includes('Vertrag 2026'));}
vm.runInContext("closeModal();wsDelete(2)",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('name="password"'));assert(f.includes('Type the name “Kunde &lt;AG&gt;”'));}
vm.runInContext("closeModal();user.role='editor';renderWs()",ctx);assert(nodes.get('#wsbox').innerHTML.includes('id="ws-select"'));
vm.runInContext("closeModal()",ctx);await vm.runInContext("acct('workspaces')",ctx);{const f=nodes.get('#fields').innerHTML;assert(!f.includes('data-acct="workspaces"'));assert(!f.includes('data-ws-del'));}
// 2FA setup: QR code from the otpauth URI, drawn locally as SVG
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/assets/vendor/qrcode/qrcode.js'),'utf8'),ctx);
vm.runInContext("loadScript=async()=>{};api=async a=>a==='totp_begin'?{secret:'JBSWY3DPEHPK3PXP',uri:'otpauth://totp/CiegoLink%3Amarco?secret=JBSWY3DPEHPK3PXP&issuer=CiegoLink&algorithm=SHA1&digits=6&period=30'}:{}",ctx);
await vm.runInContext("enroll()",ctx);{const m=nodes.get('#main').innerHTML;assert(m.includes('class="qrbox"'));assert(m.includes('<svg class="qr" viewBox="0 0 '));assert(m.includes('JBSWY3DPEHPK3PXP'));assert(/<path d="(M\d+ \d+h1v1h-1z)+" fill="#000"/.test(m));}
{const n=vm.runInContext("(()=>{const q=qrcode(0,'M');q.addData('otpauth://totp/CiegoLink%3Amarco?secret=JBSWY3DPEHPK3PXP&issuer=CiegoLink&algorithm=SHA1&digits=6&period=30','Byte');q.make();return q.getModuleCount()})()",ctx);assert(n>=21&&n<=57);}
vm.runInContext("qrcode=undefined",ctx);await vm.runInContext("enroll()",ctx);{const m=nodes.get('#main').innerHTML;assert(!m.includes('qrbox'));assert(m.includes('Add an account manually'));}
vm.runInContext("user.role='admin';page='map';render()",ctx);
// workspace roles: workspace admin edits, workspace user only reads, no workspace = notice
vm.runInContext("closeModal();workspaces=[{id:1,name:'Main workspace',note:'',role:'admin',records:5,docs:1},{id:2,name:'Kunde',note:'',role:'user',records:0,docs:0}];wsId=1;user={id:5,role:'reader',username:'anna'};page='devices';render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('data-new="devices"'));assert(m.includes('data-edit="2"'));}
assert.equal(vm.runInContext("canSoftware()",ctx),true);
await vm.runInContext("acct('workspaces')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('data-ws-members="1"'));assert(!f.includes('data-ws-members="2"'));assert(!f.includes('data-ws-del'));assert(!f.includes('name="name"'));assert(!f.includes('data-acct="users"'));assert(f.includes('data-acct="data"'));}
vm.runInContext("closeModal();wsId=2;render()",ctx);{const m=nodes.get('#main').innerHTML;assert(!m.includes('data-new="devices"'));assert(!m.includes('data-edit="2"'));assert(m.includes('data-copy-password="3"'));}
await vm.runInContext("acct('data')",ctx);assert(!nodes.get('#fields').innerHTML.includes('id="import-json"'));
vm.runInContext("closeModal();workspaces=[{id:2,name:'Kunde',note:'',role:'user',records:0,docs:0}];render()",ctx);assert.equal(vm.runInContext("canSoftware()",ctx),false);
vm.runInContext("workspaces=[];wsId=0;render()",ctx);assert(nodes.get('#main').innerHTML.includes('no access to a workspace'));
vm.runInContext("page='software';render()",ctx);assert(nodes.get('#main').innerHTML.includes('Software <em>library'));
vm.runInContext("workspaces=[{id:1,name:'Main workspace',note:'',role:'sysadmin',records:5,docs:1},{id:2,name:'Kunde <x>',note:'',role:'sysadmin',records:0,docs:0}];wsId=1;user={id:1,role:'admin',username:'marco'};api=async a=>a==='users'?{users:[{id:1,username:'marco',role:'admin',active:1,must_change:0,has_totp:1,workspaces:{}},{id:5,username:'anna',display_name:'Anna',role:'reader',active:1,must_change:0,has_totp:1,workspaces:{'2':'admin','1':'user'}}]}:a==='ws_members'?{users:[{id:1,username:'marco',name:'',active:true,role:'sysadmin'},{id:5,username:'anna',name:'Anna',active:true,role:'admin'},{id:6,username:'tom',name:'',active:false,role:''}]}:{}",ctx);
await vm.runInContext("acct('users')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('System administrator'));assert(f.includes('Main workspace, Kunde &lt;x&gt; (admin)'));}
vm.runInContext("closeModal();userCreated('anna<x>','Abcde-Fghij-Kmnpq-Rstuv')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('Abcde-Fghij-Kmnpq-Rstuv'));assert(f.includes('anna&lt;x&gt;'));assert(f.includes('data-copy-text="Abcde-Fghij-Kmnpq-Rstuv"'));}
vm.runInContext("closeModal();userDelete({id:5,username:'anna<x>',display_name:'Anna',active:0})",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('Type the username “anna&lt;x&gt;”'));assert(f.includes('name="password"'));assert(f.includes('cannot be undone'));}
vm.runInContext("closeModal()",ctx);await vm.runInContext("wsMembers(2)",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('data-member="5"'));assert(f.includes('data-member="6"'));assert(!f.includes('data-member="1"'));assert(f.includes('System administrator · always'));assert(f.includes('disabled'));}
vm.runInContext("closeModal()",ctx);await vm.runInContext("acct('workspaces')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('data-ws-del="2"'));assert(f.includes('name="name"'));assert(f.includes('data-ws-members="2"'));}
vm.runInContext("closeModal();page='map';render()",ctx);
vm.runInContext("page='devices';render()",ctx);assert(nodes.get('#main').innerHTML.includes('CONNECTIONS'));
vm.runInContext("edit('links',0,{from:'20',to:'2'})",ctx);assert(nodes.get('#fields').innerHTML.includes('<option value="20" selected>'));
vm.runInContext("closeModal();user.role='reader';page='map';render()",ctx);assert(!nodes.get('#main').innerHTML.includes('data-handle='));
vm.runInContext("user.role='admin';init=async()=>{};api=async a=>a==='users'?{users:[{id:1,username:'marco',role:'admin',active:1,must_change:0,has_totp:1},{id:2,username:'<b>x',role:'reader',active:1,must_change:1,has_totp:0}]}:a==='system'?{app:{name:'Hub',version:'1.0.0'},server:{PHP:'8.3'},gateway:{configured:false},components:[{name:'xterm.js',version:'5.5.0',use:'t',license:'MIT'}]}:{entries:[{at:'2026-10-23T08:00:00+00:00',actor:'<i>m',action:'login',target:'',ip:'1.2.3.4'}],page:2,pages:3,total:120,per:50}",ctx);
await vm.runInContext("acct('audit')",ctx);{const f=nodes.get('#audit-box').innerHTML;assert(f.includes('51–100 of 120'));assert(f.includes('data-audit-page="3"'));assert(f.includes('&lt;i&gt;m'));assert(f.includes('Page 2 / 3'));}
vm.runInContext("closeModal()",ctx);await vm.runInContext("acct('system')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('Version <b>1.0.0</b>'));assert(f.includes('xterm.js'));assert(f.includes('Not installed'));}
{const f=nodes.get('#fields').innerHTML;assert(f.includes('href="manual/"'));assert(f.includes('data-acct="system"'));}
vm.runInContext("closeModal();user.role='reader';appVersion='1.0.0'",ctx);await vm.runInContext("acct('system')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('href="manual/"'));assert(f.includes('Version <b>1.0.0</b>'));assert(!f.includes('xterm.js'));assert(!f.includes('data-acct="audit"'));}
vm.runInContext("closeModal();user.role='admin'",ctx);
vm.runInContext("closeModal()",ctx);await vm.runInContext("acct('users')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('data-acct="audit"'));assert(f.includes('&lt;b&gt;x'));assert(f.includes('data-new-user'));}
vm.runInContext("closeModal()",ctx);
vm.runInContext("closeModal();user.role='admin';page='devices';search='';filter='';render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('CREDENTIALS'));assert(m.includes('data-copy-password="3"'));assert(m.includes('data-copy-user="3"'));assert(m.includes('id="search" autocomplete="off"'));}
vm.runInContext("closeModal();records.find(r=>r.id===4).data.type='Router';page='ips';search='';filter='';ipTab='';render();edit('ips',4)",ctx);
{const f=nodes.get('#fields').innerHTML;assert(f.includes('list="typelist"'));assert(f.includes('<option value="Switch">'));assert(f.includes('value="Router"'));}
assert(nodes.get('#main').innerHTML.includes('<span class="pill">Router</span>'));
assert.equal(vm.runInContext("typeOptions().filter(t=>t.toLowerCase()==='router').length",ctx),1);
vm.runInContext("closeModal()",ctx);
vm.runInContext("user.role='admin';page='devices';search='';filter='';render()",ctx);assert(!nodes.get('#main').innerHTML.includes('class="stats"'));
vm.runInContext("page='overview';render()",ctx);assert(nodes.get('#main').innerHTML.includes('class="stats"'));assert.equal(nodes.get('#main').className,'');
vm.runInContext("page='map';render()",ctx);assert.equal(nodes.get('#main').className,'mapmode');assert(!nodes.get('#main').innerHTML.includes('class="stats"'));
vm.runInContext("software={max:104857600,files:[{id:7,name:'PlyCast <b>',version:'3.1',platform:'Windows',note:'Free',filename:'setup.exe',size:2048,sha256:'a'.repeat(64),created_at:'2026-10-23T08:00:00Z',uploaded_by:'Marco'}]};page='software';search='';render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('software.php?action=download&amp;id=7'));assert(m.includes('PlyCast &lt;b&gt;'));assert(m.includes('data-sw-upload'));assert(m.includes('data-sw-delete="7"'));assert.equal(nodes.get('#main').className,'');}
vm.runInContext("search='nomatch';render()",ctx);assert(nodes.get('#main').innerHTML.includes('No records yet.'));
vm.runInContext("search='';softwareUpload()",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('type="file" name="file"'));assert(f.includes('100.0 MiB'));}
vm.runInContext("closeModal();softwareEdit(7)",ctx);assert(nodes.get('#fields').innerHTML.includes('value="setup.exe" disabled'));
vm.runInContext("closeModal();user.role='reader';render()",ctx);{const m=nodes.get('#main').innerHTML;assert(m.includes('action=download'));assert(!m.includes('data-sw-upload'));assert(!m.includes('data-sw-delete'));}
vm.runInContext("user.role='admin';api=async a=>({users:[],entries:[]});",ctx);await vm.runInContext("acct('data')",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('data-acct="data"'));assert(f.includes('id="export-json"'));assert(f.includes('id="import-json"'));assert(f.includes('data-csv="devices"'));assert(f.includes('value="replace"'));}
vm.runInContext("closeModal();user.role='editor'",ctx);await vm.runInContext("acct('data')",ctx);assert(!nodes.get('#fields').innerHTML.includes('id="import-json"'));assert(!nodes.get('#fields').innerHTML.includes('data-acct="data"'));vm.runInContext("closeModal();user.role='admin'",ctx);
assert(!vm.runInContext("Object.keys(names).includes('export')",ctx));
assert.equal(vm.runInContext("csvCell('=cmd()')",ctx),"'=cmd()");assert.equal(vm.runInContext("csvCell('+41 79 000')",ctx),'+41 79 000');assert.equal(vm.runInContext(`csvCell('a,"b"')`,ctx),'"a,""b"""');
vm.runInContext("closeModal();user.role='admin';records.push({id:40,kind:'racks',revision:1,data:{name:'A1',building:'1',height:'12',note:''}},{id:41,kind:'mounts',revision:1,data:{name:'Server <x>',rack:'40',position:'3',size:'2',device:'2',ip_id:'4',type:'Server',note:''}});page='racks';search='';filter='';render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('Rack <em>management'));assert(m.includes('Server &lt;x&gt;'));assert(m.includes('data-row="9" data-span="2"'));assert(m.includes('data-mount-at="40:1"'));assert(m.includes('2 U used'));assert(m.includes('10.1.1.1'));}
{const m=nodes.get('#main').innerHTML;assert(m.includes('data-goto-ip="10.1.1.1"'));assert(m.includes('href="https://example.com:8443/"'));assert(m.includes('data-col="full"'));}
vm.runInContext("records.push({id:42,kind:'mounts',revision:1,data:{name:'Half L',rack:'40',position:'7',size:'1',width:'left',device:'',ip_id:'',type:'',note:''}});render()",ctx);{const m=nodes.get('#main').innerHTML;assert(m.includes('data-mount-at="40:7:right"'));assert(!m.includes('data-mount-at="40:7"'));assert(m.includes('data-col="left"'));}
vm.runInContext("appVersion='9.9.9';render()",ctx);assert(nodes.get('#main').innerHTML.includes('v9.9.9'));
vm.runInContext("page='devices';render()",ctx);assert(nodes.get('#main').innerHTML.includes('U3–U4'));
vm.runInContext("edit('mounts',41)",ctx);{const f=nodes.get('#fields').innerHTML;assert(f.includes('name="position" type="number"'));assert(f.includes('No linked IP address'));assert(f.includes('Delete rack item'));}
vm.runInContext("closeModal();user.role='reader';page='racks';render()",ctx);{const m=nodes.get('#main').innerHTML;assert(!m.includes('data-mount-at'));assert(!m.includes('data-edit="41"'));}
vm.runInContext("user.role='admin'",ctx);
vm.runInContext("closeModal();user.role='admin';docList=[{id:5,title:'Runbook <x>',category:'Ops',updated_at:'2026-10-23T08:00:00+00:00',updated_by:'Alex',revision:1}];docCur={id:5,title:'Runbook <x>',category:'Ops',body:'',updated_at:'2026-10-23T08:00:00+00:00',updated_by:'Alex',revision:1};mdToHtml=s=>'<p>'+E(s)+'</p>';page='docs';render()",ctx);
{const m=nodes.get('#main').innerHTML;assert(m.includes('Team <em>documentation'));assert(m.includes('data-doc-new="1"'));assert(m.includes('Runbook &lt;x&gt;'));assert(m.includes('data-doc="5"'));}
{const d=nodes.get('#docpane').innerHTML;assert(d.includes('data-doc-edit="1"'));assert(d.includes('data-doc-md="1"'));}
vm.runInContext("startDocEdit(docCur)",ctx);{const d=nodes.get('#docpane').innerHTML;assert(d.includes('data-cmd="bold"'));assert(d.includes('data-docmode="md"'));assert(d.includes('id="doc-visual"'));}
vm.runInContext("docEdit=null;docDirty=false;user.role='reader';render()",ctx);{const m=nodes.get('#main').innerHTML;assert(!m.includes('data-doc-new'));assert(!nodes.get('#docpane').innerHTML.includes('data-doc-edit'));}
vm.runInContext("user.role='admin';docCur=null",ctx);
console.log('PASS: generated temporary password, delete user dialog, 2FA QR code, workspace roles (admin edits, user reads, members, access per user), workspaces (switcher, manage, delete confirmation), several connection maps, hide boxes per map, add-to-map picker, zoom controls, info tab with manual, audit paging + search, system tab, half-width rack items, IP links, version, software library, export & import in account popup, CSV escaping, full-page map, stats only on overview, IP type list, device credentials copy, autofill off, connection map, admin popup, device ping, Web control, IP sort ascending, list inputs, Webmin field, IP range tabs, English forms, wide fields, external IP and software search, Files button, escaped filenames, reader UI permissions.');
})().catch(e=>{console.error(e);process.exitCode=1});
