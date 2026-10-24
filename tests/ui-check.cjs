const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict'),path=require('path');
const nodes=new Map();const el=s=>{if(!nodes.has(s))nodes.set(s,{innerHTML:'',textContent:'',hidden:false,disabled:false,querySelector:el,querySelectorAll:()=>[],close(){},showModal(){},reset(){},addEventListener(){},classList:{toggle(){},add(){},remove(){}},style:{},setAttribute(){}});return nodes.get(s)};
const ctx={document:{querySelector:el,querySelectorAll:()=>[],addEventListener(){}},setTimeout(){},clearTimeout(){},setInterval(){},URL,console};vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../public/secure.js'),'utf8').replace(';run(init);',';'),ctx);
(async()=>{
vm.runInContext("user={id:1,role:'admin'};records=[{id:1,kind:'buildings',data:{name:'Office'}},{id:2,kind:'devices',data:{name:'Server',building:'1',ip:'198.51.100.10\\n2001:db8::10',note:'Maintenance Friday',software:'Docker nginx:1.27'}},{id:3,kind:'credentials',data:{name:'SSH access',device:'2',username:'admin'}}];page='devices';render();edit('devices',2)",ctx);
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
{const m=nodes.get('#main').innerHTML;assert(m.includes('data-node="20"'));assert(m.includes('data-node="2"'));assert(m.includes('www.aw.plus'));assert(m.includes('data-label="21"'));assert(m.includes('data-handle="20"'));}
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
console.log('PASS: info tab with manual, audit paging + search, system tab, half-width rack items, IP links, version, software library, export & import in account popup, CSV escaping, full-page map, stats only on overview, IP type list, device credentials copy, autofill off, connection map, admin popup, device ping, Web control, IP sort ascending, list inputs, Webmin field, IP range tabs, English forms, wide fields, external IP and software search, Files button, escaped filenames, reader UI permissions.');
})().catch(e=>{console.error(e);process.exitCode=1});
