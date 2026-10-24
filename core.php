<?php
declare(strict_types=1);
function appVersion(): string {static $v;return $v??=(trim((string)@file_get_contents(__DIR__.'/VERSION'))?:'dev');}
function config(): array {static $c; if(!$c){$p=getenv('INFRA_CONFIG')?:__DIR__.'/config/config.php';if(!is_file($p))throw new RuntimeException('Configuration missing or unreadable. Run the CLI installer and check file permissions.');$c=require $p;}return $c;}
function db(): PDO {static $d;if(!$d){$d=new PDO('sqlite:'.config()['data_dir'].'/infra.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$d->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000');}return $d;}
function query(string $sql,array $v=[]): PDOStatement {$s=db()->prepare($sql);$s->execute($v);return $s;}
function schema(): void {db()->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE COLLATE NOCASE NOT NULL, hash TEXT NOT NULL, role TEXT NOT NULL CHECK(role IN ('admin','editor','reader')), active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, must_change INTEGER NOT NULL DEFAULT 1, totp TEXT, last_step INTEGER NOT NULL DEFAULT -1, display_name TEXT NOT NULL DEFAULT ''); CREATE TABLE IF NOT EXISTS records(id INTEGER PRIMARY KEY, kind TEXT NOT NULL, data TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1); CREATE TABLE IF NOT EXISTS audit(id INTEGER PRIMARY KEY, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL, ip TEXT NOT NULL); CREATE TABLE IF NOT EXISTS attempts(id INTEGER PRIMARY KEY, at INTEGER NOT NULL, bucket TEXT NOT NULL); CREATE INDEX IF NOT EXISTS attempts_bucket ON attempts(bucket,at);");}
function enc(string $s): string {$iv=random_bytes(12);$tag='';$out=openssl_encrypt($s,'aes-256-gcm',hex2bin(config()['key']),OPENSSL_RAW_DATA,$iv,$tag,'ciegolink-infra-v1');if($out===false)throw new RuntimeException('Encryption failed');return base64_encode($iv.$tag.$out);}
function dec(string $s): string {$b=base64_decode($s,true);if($b===false||strlen($b)<28)throw new RuntimeException('Invalid encrypted data');$v=openssl_decrypt(substr($b,28),'aes-256-gcm',hex2bin(config()['key']),OPENSSL_RAW_DATA,substr($b,0,12),substr($b,12,16),'ciegolink-infra-v1');if($v===false)throw new RuntimeException('Decryption failed');return $v;}
function audit(string $a,string $t='',?string $actor=null):void {query('INSERT INTO audit(at,actor,action,target,ip) VALUES(?,?,?,?,?)',[gmdate('c'),$actor??($_SESSION['username']??'anonymous'),$a,$t,$_SERVER['REMOTE_ADDR']??'CLI']);}
function b32(string $data):string {$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($data) as $c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5) as $b)$out.=$alphabet[bindec(str_pad($b,5,'0'))];return $out;}
function unb32(string $s):string {$bits='';foreach(str_split($s) as $c){$n=strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c);if($n===false)throw new RuntimeException('TOTP secret');$bits.=str_pad(decbin($n),5,'0',STR_PAD_LEFT);}$out='';foreach(str_split($bits,8) as $b)if(strlen($b)===8)$out.=chr(bindec($b));return $out;}
function otp(string $secret,int $step):string {$h=hash_hmac('sha1',pack('N2',0,$step),unb32($secret),true);$o=ord($h[19])&15;$n=unpack('N',substr($h,$o,4))[1]&0x7fffffff;return str_pad((string)($n%1000000),6,'0',STR_PAD_LEFT);}
function verifyOtp(string $secret,string $code,int $last=-1):int {if(!preg_match('/^\d{6}$/D',$code))return -1;$now=(int)floor(time()/30);foreach([$now-1,$now,$now+1] as $step)if($step>$last&&hash_equals(otp($secret,$step),$code))return $step;return -1;}
function passwordCheck(string $p):void {if(strlen($p)<14||strlen($p)>72)fail('Password must be 14 to 72 bytes long.',422);}
function fail(string $m,int $status=400):never {http_response_code($status);echo json_encode(['error'=>$m],JSON_UNESCAPED_UNICODE);exit;}
function response(array $v):never {echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
function sessionStart():void {if(($_SERVER['HTTPS']??'')!=='on'&&!(config()['allow_http_local']??false))fail('HTTPS is required.',403);ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');session_name('infra_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!(config()['allow_http_local']??false),'httponly'=>true,'samesite'=>'Strict']);session_start();$_SESSION['started']??=time();$rem=(int)($_SESSION['remember']??0);if($rem?$rem<time():(isset($_SESSION['last'])&&(time()-$_SESSION['last']>900||time()-($_SESSION['started']??0)>28800))){$_SESSION=[];session_regenerate_id(true);}if(empty($_SESSION['uid'])&&isset($_COOKIE['infra_remember']))rememberRestore();if(!in_array($_GET['action']??'state',['state','ping'],true))$_SESSION['last']=time();$_SESSION['csrf']??=bin2hex(random_bytes(32));}
// Remember-me tokens
const REMEMBER_DAYS=30;
function rememberTable():void {db()->exec('CREATE TABLE IF NOT EXISTS remember(id INTEGER PRIMARY KEY, selector TEXT UNIQUE NOT NULL, hash TEXT NOT NULL, uid INTEGER NOT NULL, version INTEGER NOT NULL, expires INTEGER NOT NULL)');}
function rememberCookie(string $v,int $exp):void {setcookie('infra_remember',$v,['expires'=>$exp,'path'=>'/','secure'=>!(config()['allow_http_local']??false),'httponly'=>true,'samesite'=>'Strict']);}
function rememberCreate(array $u):int {rememberTable();$exp=time()+REMEMBER_DAYS*86400;$sel=bin2hex(random_bytes(12));$val=bin2hex(random_bytes(32));query('DELETE FROM remember WHERE expires<?',[time()]);query('INSERT INTO remember(selector,hash,uid,version,expires) VALUES(?,?,?,?,?)',[$sel,hash('sha256',$val),(int)$u['id'],(int)$u['version'],$exp]);rememberCookie($sel.'.'.$val,$exp);return $exp;}
function rememberClear():void {$p=explode('.',(string)($_COOKIE['infra_remember']??''),2);if(count($p)===2){rememberTable();query('DELETE FROM remember WHERE selector=?',[$p[0]]);}if(isset($_COOKIE['infra_remember']))rememberCookie('',1);unset($_COOKIE['infra_remember']);}
function rememberRestore():void {$p=explode('.',(string)$_COOKIE['infra_remember'],2);rememberTable();$r=count($p)===2?query('SELECT * FROM remember WHERE selector=?',[$p[0]])->fetch():false;$u=$r?query('SELECT * FROM users WHERE id=?',[$r['uid']])->fetch():false;if($r&&!hash_equals($r['hash'],hash('sha256',$p[1]))){rememberCookie('',1);return;}if(!$r||!$u||(int)$r['expires']<time()||!$u['active']||(int)$u['version']!==(int)$r['version']||$u['must_change']||!$u['totp']){rememberClear();return;}session_regenerate_id(true);$_SESSION=['uid'=>(int)$u['id'],'username'=>$u['username'],'version'=>(int)$u['version'],'csrf'=>bin2hex(random_bytes(32)),'started'=>time(),'last'=>time(),'remember'=>(int)$r['expires']];audit('login_remembered');}
function folderName(string $f):string {$f=trim(preg_replace('/\s+/u',' ',$f)??'');if(mb_strlen($f)>80)fail('Folder name is too long (max. 80 characters).',422);return $f;}
// Maintenance
function maintenanceZone():DateTimeZone {static $z;if(!$z){try{$z=new DateTimeZone((string)(config()['timezone']??'Europe/Zurich'));}catch(Throwable){$z=new DateTimeZone('UTC');}}return $z;}
function maintenanceTime(int $t):string {return (new DateTimeImmutable('@'.$t))->setTimezone(maintenanceZone())->format('c');}
function maintenanceCheck(array $v):array {
    $s=trim((string)($v['start']??''));if($s===''||!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/',$s))fail('Enter a valid start date and time.',422);
    try{$d=new DateTimeImmutable($s,maintenanceZone());}catch(Throwable){fail('Enter a valid start date and time.',422);}
    $y=(int)$d->format('Y');if($y<2000||$y>2100)fail('Enter a valid start date and time.',422);
    $dur=trim((string)($v['duration']??''));if(!ctype_digit($dur)||(int)$dur<1||(int)$dur>43200)fail('Duration must be between 1 minute and 30 days.',422);
    $state=(string)($v['state']??'planned');if($state==='')$state='planned';if(!in_array($state,['planned','cancelled'],true))fail('Invalid state.',422);
    $pub=(string)($v['public']??'1');if(!in_array($pub,['1','0'],true))fail('Invalid visibility.',422);
    $dev=[];foreach(preg_split('/[\s,;]+/',(string)($v['devices']??''),-1,PREG_SPLIT_NO_EMPTY) as $x){if(!ctype_digit($x)||!query("SELECT id FROM records WHERE id=? AND kind='devices'",[(int)$x])->fetch())fail('Select valid servers or devices.',422);$dev[(int)$x]=(string)(int)$x;}
    return ['name'=>(string)$v['name'],'devices'=>implode("\n",array_values($dev)),'start'=>$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),'duration'=>(string)(int)$dur,'services'=>implode("\n",array_values(array_filter(array_map('trim',preg_split('/\r?\n/',(string)($v['services']??''))),'strlen'))),'message'=>trim((string)($v['message']??'')),'note'=>trim((string)($v['note']??'')),'state'=>$state,'public'=>$pub];
}
function maintenanceItem(int $id,array $d,?int $now=null):array {$now??=time();$st=strtotime((string)($d['start']??''))?:0;$min=(int)($d['duration']??0);$en=$st+$min*60;$status=($d['state']??'')==='cancelled'?'cancelled':($now<$st?'upcoming':($now<$en?'active':'completed'));
    return ['id'=>$id,'title'=>(string)($d['name']??''),'status'=>$status,'start'=>maintenanceTime($st),'end'=>maintenanceTime($en),'start_utc'=>gmdate('Y-m-d\TH:i:s\Z',$st),'end_utc'=>gmdate('Y-m-d\TH:i:s\Z',$en),'duration_minutes'=>$min,'devices'=>maintenanceDevices($d),'services'=>textLines($d['services']??''),'message'=>(string)($d['message']??'')];}
function maintenanceDevices(array $d):array {$out=[];foreach(textLines($d['devices']??'') as $x){$r=query("SELECT data FROM records WHERE id=? AND kind='devices'",[(int)$x])->fetch();if(!$r)continue;$v=json_decode($r['data'],true)?:[];$out[]=['id'=>(int)$x,'name'=>(string)($v['name']??''),'type'=>(string)($v['type']??'')];}return $out;}
function apiTokenTable():void {db()->exec('CREATE TABLE IF NOT EXISTS api_tokens(id INTEGER PRIMARY KEY, name TEXT NOT NULL, hash TEXT UNIQUE NOT NULL, prefix TEXT NOT NULL, scope TEXT NOT NULL DEFAULT \'maintenance:read\', created_at TEXT NOT NULL, created_by TEXT NOT NULL, last_used TEXT)');}
function apiTokenCheck(string $token):?array {if(!preg_match('/^ihm_[0-9a-f]{48}$/D',$token))return null;apiTokenTable();$t=query('SELECT * FROM api_tokens WHERE hash=?',[hash('sha256',$token)])->fetch();if(!$t)return null;if(!$t['last_used']||strtotime($t['last_used'])<time()-60)query('UPDATE api_tokens SET last_used=? WHERE id=?',[gmdate('c'),$t['id']]);return $t;}
function infraCurrentUser():?array {if(empty($_SESSION['uid']))return null;$u=query('SELECT * FROM users WHERE id=?',[$_SESSION['uid']])->fetch();if(!$u||!$u['active']||(int)$u['version']!==($_SESSION['version']??0)){unset($_SESSION['uid']);return null;}return $u;}
function requireUser():array {$u=infraCurrentUser();if(!$u)fail('Please sign in.',401);if($u['must_change']||!$u['totp'])fail('Complete password and two-factor setup first.',403);return $u;}
function admin():array {$u=requireUser();if($u['role']!=='admin')fail('Administrators only.',403);return $u;}
function rate(string $bucket,int $limit):void {query('DELETE FROM attempts WHERE at<?',[time()-1800]);$n=query('SELECT COUNT(*) FROM attempts WHERE bucket=? AND at>?',[$bucket,time()-900])->fetchColumn();if((int)$n>=$limit)fail('Too many attempts. Please wait 15 minutes.',429);query('INSERT INTO attempts(at,bucket) VALUES(?,?)',[time(),$bucket]);}
function pingBinary():?string {foreach(['/usr/bin/ping','/bin/ping','/usr/sbin/ping','/sbin/ping'] as $p)if(@is_file($p)&&@is_executable($p))return $p;return null;}
function tcpProbe(string $ip,int $port):array {$t=microtime(true);$s=@fsockopen(str_contains($ip,':')?'['.$ip.']':$ip,$port,$no,$err,1.0);if($s){fclose($s);return ['up'=>true,'ms'=>round((microtime(true)-$t)*1000,1),'method'=>'tcp:'.$port];}return ['up'=>false,'ms'=>null,'method'=>'tcp:'.$port];}
function pingMany(array $targets,string $url=''):array {
    $port=443;if($url!==''){$scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));$port=(int)(parse_url($url,PHP_URL_PORT)?:($scheme==='http'?80:443));}
    $bin=function_exists('proc_open')?pingBinary():null;$procs=[];
    if($bin)foreach($targets as $i=>$t){$p=@proc_open([$bin,'-n','-c','1','-W','1',$t['ip']],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(is_resource($p))$procs[$i]=[$p,$pipes];}
    $out=[];
    foreach($targets as $i=>$t){
        if(isset($procs[$i])){[$p,$pipes]=$procs[$i];$txt=(string)stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
            if($code===0||$code===1){$out[]=$t+['up'=>$code===0,'ms'=>$code===0&&preg_match('/time[=<]\s*([\d.]+)\s*ms/',$txt,$m)?(float)$m[1]:null,'method'=>'icmp'];continue;}}
        $out[]=$t+tcpProbe($t['ip'],$port);
    }
    return $out;
}
function migrate():void {static $done=false;if($done)return;$done=true;$cols=array_column(query('PRAGMA table_info(users)')->fetchAll(),'name');if($cols&&!in_array('display_name',$cols,true))db()->exec("ALTER TABLE users ADD COLUMN display_name TEXT NOT NULL DEFAULT ''");}
function displayName(array $input):string {$n=trim((string)($input['display_name']??''));if(strlen($n)>120||preg_match('/[\x00-\x1f\x7f]/',$n))fail('Invalid name (maximum 80 characters).',422);return $n;}
function attachmentSchema():void {db()->exec('CREATE TABLE IF NOT EXISTS attachments(id INTEGER PRIMARY KEY, credential_id INTEGER NOT NULL REFERENCES records(id) ON DELETE CASCADE, filename TEXT NOT NULL, size INTEGER NOT NULL, sha256 TEXT NOT NULL, payload TEXT NOT NULL, created_at TEXT NOT NULL); CREATE INDEX IF NOT EXISTS attachment_credential ON attachments(credential_id)');}
// Software library
function softwareSchema():void {db()->exec("CREATE TABLE IF NOT EXISTS software(id INTEGER PRIMARY KEY, name TEXT NOT NULL, version TEXT NOT NULL DEFAULT '', platform TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', filename TEXT NOT NULL, size INTEGER NOT NULL, sha256 TEXT NOT NULL, stored TEXT NOT NULL, created_at TEXT NOT NULL, uploaded_by TEXT NOT NULL DEFAULT '')");}
function softwareDir():string {$d=(string)(config()['software_dir']??(config()['data_dir'].'/software'));if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))throw new RuntimeException('Software folder cannot be created: '.$d);return $d;}
function iniBytes(string $v):int {$v=trim($v);if($v===''||$v==='-1'||$v==='0')return PHP_INT_MAX;$n=(int)$v;switch(strtolower(substr($v,-1))){case 'g':$n*=1024;case 'm':$n*=1024;case 'k':$n*=1024;}return $n;}
function softwareMax():int {return (int)min(iniBytes((string)ini_get('upload_max_filesize')),max(0,iniBytes((string)ini_get('post_max_size'))-65536),(int)(config()['software_max_mb']??4096)*1048576);}
function softwareMeta(array $input):array {$v=[];foreach(['name'=>200,'version'=>80,'platform'=>80,'note'=>4096] as $k=>$max){$s=$input[$k]??'';if(!is_string($s)||strlen($s)>$max)fail('Invalid field: '.$k,422);$s=trim(str_replace("\r\n","\n",$s));if(preg_match($k==='note'?'/[\x00-\x09\x0b-\x1f\x7f]/':'/[\x00-\x1f\x7f]/',$s))fail('Invalid characters in '.$k.'.',422);$v[$k]=$s;}if($v['name']==='')fail('Name is required.',422);return $v;}
function textLines($s):array {return array_values(array_filter(array_map('trim',preg_split('/\r?\n/',(string)$s)),fn($x)=>$x!==''));}
function exportData(array $u,bool $passwords,bool $files):array {
    attachmentSchema();softwareSchema();$by=[];
    foreach(query('SELECT * FROM records ORDER BY id')->fetchAll() as $r){$r['data']=json_decode($r['data'],true)?:[];$by[(int)$r['id']]=$r;}
    $ref=fn($id)=>(is_string($id)&&ctype_digit($id))||is_int($id)?(int)$id:null;
    $name=fn($id)=>($i=$ref($id))!==null&&isset($by[$i])?(string)($by[$i]['data']['name']??''):null;
    $pos=fn($d)=>isset($d['x'])&&$d['x']!==''?['x'=>(int)$d['x'],'y'=>(int)($d['y']??0)]:null;
    $att=[];foreach(query('SELECT * FROM attachments ORDER BY id')->fetchAll() as $a){$f=['filename'=>$a['filename'],'size'=>(int)$a['size'],'sha256'=>$a['sha256'],'created_at'=>$a['created_at']];if($files){$plain=dec($a['payload']);if(!hash_equals($a['sha256'],hash('sha256',$plain)))throw new RuntimeException('Attachment integrity check failed');$f['base64']=base64_encode($plain);}$att[(int)$a['credential_id']][]=$f;}
    $out=['format'=>'ciegolink-infrahub-export','format_version'=>1,'app_version'=>appVersion(),'exported_at'=>gmdate('c'),'exported_by'=>$u['username'],'includes_passwords'=>$passwords,'includes_credential_files'=>$files,'locations'=>[],'devices'=>[],'ip_addresses'=>[],'credentials'=>[],'elements'=>[],'connections'=>[],'racks'=>[],'rack_items'=>[],'docs'=>[],'software'=>[],'maintenance'=>[],'users'=>[]];
    foreach($by as $id=>$r){$d=$r['data'];$s=fn($k)=>(string)($d[$k]??'');switch($r['kind']){
        case 'buildings':$out['locations'][]=['id'=>$id,'name'=>$s('name'),'address'=>$s('address'),'networks'=>textLines($d['subnet']??'')];break;
        case 'devices':$out['devices'][]=['id'=>$id,'name'=>$s('name'),'type'=>$s('type'),'location_id'=>$ref($d['building']??''),'location'=>$name($d['building']??''),'external_ips'=>textLines($d['ip']??''),'internal_ips'=>textLines($d['internal_ips']??''),'web_control'=>$s('webmin')!==''?$s('webmin'):$s('url'),'notes'=>$s('note'),'software'=>textLines($d['software']??''),'map_position'=>$pos($d)];break;
        case 'ips':$out['ip_addresses'][]=['id'=>$id,'ip'=>$s('ip'),'name'=>$s('name'),'location_id'=>$ref($d['building']??''),'location'=>$name($d['building']??''),'type'=>$s('type'),'status'=>$s('status'),'admin_url'=>$s('url'),'credential_id'=>$ref($d['credential_id']??''),'credential'=>$name($d['credential_id']??'')];break;
        case 'credentials':$c=['id'=>$id,'name'=>$s('name'),'device_id'=>$ref($d['device']??''),'device'=>$name($d['device']??''),'url'=>$s('url'),'reference'=>$s('reference'),'username'=>$s('username'),'folder'=>$s('folder')];if($passwords)$c['password']=isset($d['password'])&&$d['password']!==''?dec((string)$d['password']):'';$c['attachments']=$att[$id]??[];$out['credentials'][]=$c;break;
        case 'elements':$out['elements'][]=['id'=>$id,'name'=>$s('name'),'type'=>$s('type'),'url'=>$s('url'),'notes'=>$s('note'),'map_position'=>$pos($d)];break;
        case 'racks':$out['racks'][]=['id'=>$id,'name'=>$s('name'),'location_id'=>$ref($d['building']??''),'location'=>$name($d['building']??''),'height'=>(int)($d['height']??42),'notes'=>$s('note')];break;
        case 'mounts':$out['rack_items'][]=['id'=>$id,'rack_id'=>$ref($d['rack']??''),'rack'=>$name($d['rack']??''),'position'=>(int)($d['position']??0),'size'=>(int)($d['size']??1),'width'=>(string)($d['width']??'full'),'name'=>$s('name'),'type'=>$s('type'),'device_id'=>$ref($d['device']??''),'device'=>$name($d['device']??''),'ip_id'=>$ref($d['ip_id']??''),'ip'=>($i=$ref($d['ip_id']??''))!==null&&isset($by[$i])?(string)($by[$i]['data']['ip']??''):null,'notes'=>$s('note')];break;
        case 'maintenance':$out['maintenance'][]=['id'=>$id,'title'=>$s('name'),'device_ids'=>array_values(array_filter(array_map(fn($x)=>$ref($x),textLines($d['devices']??'')),fn($x)=>$x!==null)),'devices'=>array_values(array_filter(array_map(fn($x)=>$name($x),textLines($d['devices']??'')),fn($x)=>$x!==null)),'start_utc'=>$s('start'),'duration_minutes'=>(int)($d['duration']??0),'services'=>textLines($d['services']??''),'message'=>$s('message'),'note'=>$s('note'),'state'=>$s('state')?:'planned','public'=>($d['public']??'1')==='1'];break;
        case 'links':$out['connections'][]=['id'=>$id,'label'=>$s('name'),'from_id'=>$ref($d['from']??''),'from'=>$name($d['from']??''),'to_id'=>$ref($d['to']??''),'to'=>$name($d['to']??'')];break;
    }}
    docsSchema();foreach(query('SELECT * FROM docs ORDER BY id')->fetchAll() as $d)$out['docs'][]=['id'=>(int)$d['id'],'title'=>$d['title'],'category'=>$d['category'],'markdown'=>$d['body'],'updated_at'=>$d['updated_at'],'updated_by'=>$d['updated_by']];
    foreach(query('SELECT * FROM software ORDER BY id')->fetchAll() as $f)$out['software'][]=['id'=>(int)$f['id'],'name'=>$f['name'],'version'=>$f['version'],'platform'=>$f['platform'],'notes'=>$f['note'],'filename'=>$f['filename'],'size'=>(int)$f['size'],'sha256'=>$f['sha256'],'uploaded_at'=>$f['created_at'],'uploaded_by'=>$f['uploaded_by'],'server_file'=>'software/'.$f['stored']];
    foreach(query('SELECT username,display_name,role,active FROM users ORDER BY username')->fetchAll() as $x)$out['users'][]=['username'=>$x['username'],'name'=>$x['display_name'],'role'=>$x['role'],'active'=>(bool)$x['active']];
    return $out;
}
// Import of a JSON export (format ciegolink-infrahub-export). Runs inside the caller's transaction; any fail() rolls back.
function importData(array $in,bool $replace):array {
    if(($in['format']??'')!=='ciegolink-infrahub-export')fail('This is not an Infrastructure Hub export file.',422);
    attachmentSchema();
    $str=function($v,string $f,int $max=4096):string{if($v===null)return '';if(is_int($v)||is_float($v))$v=(string)$v;if(!is_string($v)||strlen($v)>$max)fail('Invalid value in '.$f.'.',422);return trim($v);};
    $list=fn($v,string $f):string=>is_array($v)?implode("\n",array_filter(array_map(fn($x)=>$str($x,$f),$v),fn($x)=>$x!=='')):$str($v,$f);
    $url=function($v,string $f)use($str):string{$v=$str($v,$f);if($v!==''&&(!filter_var($v,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($v,PHP_URL_SCHEME)),['http','https'],true)))fail('Invalid URL in '.$f.': '.$v,422);return $v;};
    $ips=function($v,string $f)use($list):string{$out=[];foreach(preg_split('/[\s,;]+/u',$list($v,$f),-1,PREG_SPLIT_NO_EMPTY) as $a){if(!filter_var($a,FILTER_VALIDATE_IP))fail('Invalid IP address in '.$f.': '.$a,422);$out[bin2hex(inet_pton($a))]=inet_ntop(inet_pton($a));}return implode("\n",$out);};
    $rows=fn(string $k)=>is_array($in[$k]??null)?array_values(array_filter($in[$k],'is_array')):[];
    $map=[];$ref=function($old)use(&$map):string{return ($old===null||$old===''||!is_scalar($old))?'':(string)($map[(string)$old]??'');};
    $name=function(array $r,string $what)use($str):string{$n=$str($r['name']??'',$what.' name');if($n==='')fail('A '.$what.' without name cannot be imported.',422);return $n;};
    $pos=fn($r)=>is_array($r['map_position']??null)?['x'=>max(0,min(6000,(int)($r['map_position']['x']??0))),'y'=>max(0,min(4000,(int)($r['map_position']['y']??0)))]:[];
    // merge key per kind
    $key=function(string $k,array $d):string{$l=fn($x)=>mb_strtolower(trim((string)($d[$x]??'')));return match($k){'buildings','elements'=>$l('name'),'devices'=>$l('name').'|'.($d['building']??''),'credentials'=>$l('name').'|'.$l('username'),'ips'=>($d['building']??'').'|'.bin2hex((string)@inet_pton((string)($d['ip']??''))),'links'=>($d['from']??'').'|'.($d['to']??'').'|'.$l('name'),'racks'=>$l('name').'|'.($d['building']??''),'mounts'=>($d['rack']??'').'|'.($d['position']??'').'|'.(($d['width']??'')?:'full'),'maintenance'=>$l('name').'|'.($d['start']??''),default=>''};};
    if($replace)query('DELETE FROM records');
    $ex=[];foreach(query('SELECT id,kind,data FROM records')->fetchAll() as $o)$ex[$o['kind']][$key($o['kind'],json_decode($o['data'],true)?:[])]=(int)$o['id'];
    $n=['locations'=>0,'devices'=>0,'credentials'=>0,'files'=>0,'ip_addresses'=>0,'elements'=>0,'connections'=>0,'racks'=>0,'rack_items'=>0,'docs'=>0,'maintenance'=>0,'existing'=>0];
    $created=true;
    $add=function(string $kind,array $d,$old)use(&$map,&$ex,&$n,&$created,$key):int{$k=$key($kind,$d);if(isset($ex[$kind][$k])){$id=$ex[$kind][$k];$n['existing']++;$created=false;}else{query('INSERT INTO records(kind,data) VALUES(?,?)',[$kind,json_encode($d,JSON_THROW_ON_ERROR)]);$id=(int)db()->lastInsertId();$ex[$kind][$k]=$id;$created=true;}if($old!==null&&$old!==''&&is_scalar($old))$map[(string)$old]=$id;return $id;};
    foreach($rows('locations') as $r){$add('buildings',['name'=>$name($r,'location'),'address'=>$str($r['address']??'','address'),'subnet'=>$list($r['networks']??'','networks')],$r['id']??null);if($created)$n['locations']++;}
    foreach($rows('devices') as $r){$nm=$name($r,'device');$b=$ref($r['location_id']??null);if($b==='')fail('Device “'.$nm.'” has no valid location in the file.',422);
        $add('devices',['name'=>$nm,'building'=>$b,'type'=>$str($r['type']??'','type'),'ip'=>$ips($r['external_ips']??'','external IPs of '.$nm),'internal_ips'=>$ips($r['internal_ips']??'','internal IPs of '.$nm),'url'=>'','webmin'=>$url($r['web_control']??'','web control of '.$nm),'note'=>$str($r['notes']??'','notes'),'software'=>$list($r['software']??'','software')]+$pos($r),$r['id']??null);if($created)$n['devices']++;}
    foreach($rows('credentials') as $r){$nm=$name($r,'credential');
        $id=$add('credentials',['name'=>$nm,'folder'=>folderName($str($r['folder']??'','folder',400)),'device'=>$ref($r['device_id']??null),'url'=>$url($r['url']??'','link of '.$nm),'reference'=>$str($r['reference']??'','reference'),'username'=>$str($r['username']??'','username'),'password'=>enc(is_string($r['password']??null)?$r['password']:'')],$r['id']??null);if(!$created)continue;$n['credentials']++;
        foreach(is_array($r['attachments']??null)?$r['attachments']:[] as $a){if(!is_array($a)||!is_string($a['base64']??null))continue;$plain=base64_decode($a['base64'],true);$fn=preg_replace('/[\x00-\x1f\x7f]/','_',str_replace(['\\','/'],'_',$str($a['filename']??'','filename',240)));if($plain===false||strlen($plain)>5242880||$fn===''||$fn==='.'||$fn==='..')fail('Invalid file attached to “'.$nm.'”.',422);query('INSERT INTO attachments(credential_id,filename,size,sha256,payload,created_at) VALUES(?,?,?,?,?,?)',[$id,$fn,strlen($plain),hash('sha256',$plain),enc($plain),is_string($a['created_at']??null)?substr($a['created_at'],0,40):gmdate('c')]);$n['files']++;}}
    foreach($rows('ip_addresses') as $r){$nm=$name($r,'IP record');$b=$ref($r['location_id']??null);if($b==='')fail('IP record “'.$nm.'” has no valid location in the file.',422);$ip=$str($r['ip']??'','IP');if(!filter_var($ip,FILTER_VALIDATE_IP))fail('Invalid IP address in “'.$nm.'”: '.$ip,422);
        $add('ips',['name'=>$nm,'building'=>$b,'ip'=>$ip,'type'=>$str($r['type']??'','type'),'status'=>$str($r['status']??'','status'),'url'=>$url($r['admin_url']??'','admin URL of '.$nm),'credential_id'=>$ref($r['credential_id']??null)],$r['id']??null);if($created)$n['ip_addresses']++;}
    foreach($rows('elements') as $r){$nm=$name($r,'element');$add('elements',['name'=>$nm,'type'=>$str($r['type']??'','type'),'url'=>$url($r['url']??'','link of '.$nm),'note'=>$str($r['notes']??'','notes')]+$pos($r),$r['id']??null);if($created)$n['elements']++;}
    foreach($rows('connections') as $r){$f=$ref($r['from_id']??null);$t=$ref($r['to_id']??null);if($f===''||$t===''||$f===$t)continue;$add('links',['name'=>$str($r['label']??'','label'),'from'=>$f,'to'=>$t],$r['id']??null);if($created)$n['connections']++;}
    $num=function($v,string $f,int $min,int $max):string{if(!is_int($v)&&!(is_string($v)&&ctype_digit($v)))fail('Invalid number in '.$f.'.',422);$v=(int)$v;if($v<$min||$v>$max)fail('Invalid value in '.$f.'.',422);return (string)$v;};
    $heights=[];
    foreach($rows('racks') as $r){$nm=$name($r,'rack');$b=$ref($r['location_id']??null);if($b==='')fail('Rack “'.$nm.'” has no valid location in the file.',422);$h=$num($r['height']??42,'height of '.$nm,1,60);$rid=$add('racks',['name'=>$nm,'building'=>$b,'height'=>$h,'note'=>$str($r['notes']??'','notes')],$r['id']??null);$heights[$rid]=(int)$h;if($created)$n['racks']++;}
    foreach($rows('rack_items') as $r){$rk=$ref($r['rack_id']??null);if($rk==='')fail('A rack item has no valid rack in the file.',422);$h=$heights[(int)$rk]??60;$pos=$num($r['position']??'','rack position',1,$h);$size=$num($r['size']??1,'rack item height',1,$h);if((int)$pos+(int)$size-1>$h)fail('Rack item at U'.$pos.' does not fit into its rack.',422);
        $dv=$ref($r['device_id']??null);$ip=$ref($r['ip_id']??null);$nm=$str($r['name']??'','rack item name');if($nm==='')$nm=$dv!==''?(string)(json_decode((string)query('SELECT data FROM records WHERE id=?',[(int)$dv])->fetchColumn(),true)['name']??''):'';if($nm==='')fail('A rack item without name cannot be imported.',422);
        $wd=$str($r['width']??'full','rack item width');if($wd==='')$wd='full';if(!in_array($wd,['full','left','right'],true))fail('Invalid rack item width.',422);$add('mounts',['name'=>$nm,'rack'=>$rk,'position'=>$pos,'size'=>$size,'width'=>$wd,'device'=>$dv,'ip_id'=>$ip,'type'=>$str($r['type']??'','type'),'note'=>$str($r['notes']??'','notes')],$r['id']??null);if($created)$n['rack_items']++;}
    foreach($rows('maintenance') as $r){$nm=$name(['name'=>$r['title']??($r['name']??'')],'maintenance');$add('maintenance',maintenanceCheck(['name'=>$nm,'devices'=>implode("\n",array_filter(array_map(fn($x)=>$ref($x),is_array($r['device_ids']??null)?$r['device_ids']:[]),'strlen')),'start'=>$str($r['start_utc']??'','maintenance start',40),'duration'=>(string)(int)($r['duration_minutes']??0),'services'=>$list($r['services']??'','services'),'message'=>$str($r['message']??'','message'),'note'=>$str($r['note']??'','note'),'state'=>$str($r['state']??'planned','state',20),'public'=>($r['public']??true)?'1':'0']),$r['id']??null);if($created)$n['maintenance']++;}
    docsSchema();if($replace)query('DELETE FROM docs');
    foreach($rows('docs') as $r){$f=docFields(['title'=>$r['title']??'','category'=>$r['category']??'','body'=>$r['markdown']??'']);
        if(query('SELECT id FROM docs WHERE title=? AND category=?',[$f['title'],$f['category']])->fetch()){$n['existing']++;continue;}
        $at=is_string($r['updated_at']??null)?substr($r['updated_at'],0,40):gmdate('c');query('INSERT INTO docs(title,category,body,created_at,updated_at,updated_by) VALUES(?,?,?,?,?,?)',[$f['title'],$f['category'],$f['body'],$at,$at,is_string($r['updated_by']??null)?substr($r['updated_by'],0,120):'']);$n['docs']++;}
    return $n;
}

// Racks
function rackCheck(string $kind,array &$v,int $id):void {
    $int=function(string $f,string $l,int $min,int $max)use(&$v):int{if($v[$f]==='')fail($l.' is required.',422);if(!preg_match('/^[0-9]{1,3}$/D',$v[$f])||(int)$v[$f]<$min||(int)$v[$f]>$max)fail($l.' must be between '.$min.' and '.$max.'.',422);$v[$f]=(string)(int)$v[$f];return (int)$v[$f];};
    if($kind==='racks'){if($v['height']==='')$v['height']='42';$h=$int('height','Rack height',1,60);if($id)foreach(query("SELECT data FROM records WHERE kind='mounts'")->fetchAll() as $m){$d=json_decode($m['data'],true);if(($d['rack']??'')===(string)$id&&(int)$d['position']+(int)$d['size']-1>$h)fail('Items are mounted above U'.$h.'. Move them first.',409);}return;}
    if(!preg_match('/^[0-9]+$/D',$v['rack'])||!($rack=query("SELECT data FROM records WHERE id=? AND kind='racks'",[(int)$v['rack']])->fetch()))fail('Select a valid rack.');
    $h=(int)(json_decode($rack['data'],true)['height']??42);if($v['size']==='')$v['size']='1';
    if($v['width']==='')$v['width']='full';if(!in_array($v['width'],['full','left','right'],true))fail('Invalid width.',422);
    $pos=$int('position','Position',1,$h);$size=$int('size','Height',1,$h);$top=$pos+$size-1;
    if($top>$h)fail('The item does not fit: U'.$pos.' + '.$size.' U exceeds the rack height of '.$h.' U.',422);
    foreach(query("SELECT id,data FROM records WHERE kind='mounts' AND id<>?",[$id])->fetchAll() as $m){$d=json_decode($m['data'],true);if(($d['rack']??'')!==$v['rack'])continue;$w=$d['width']??'full';if($w!=='full'&&$v['width']!=='full'&&$w!==$v['width'])continue;$p=(int)$d['position'];$t=$p+(int)$d['size']-1;if($pos<=$t&&$top>=$p)fail('U'.$pos.($size>1?'–U'.$top:'').' is already used by “'.($d['name']??'').'” (U'.$p.($t>$p?'–U'.$t:'').').',409);}
    if($v['device']!==''&&(!preg_match('/^[0-9]+$/D',$v['device'])||!query("SELECT id FROM records WHERE id=? AND kind='devices'",[(int)$v['device']])->fetch()))fail('Select a valid device.');
    if($v['ip_id']!==''&&(!preg_match('/^[0-9]+$/D',$v['ip_id'])||!query("SELECT id FROM records WHERE id=? AND kind='ips'",[(int)$v['ip_id']])->fetch()))fail('Select a valid IP address.');
}
// SSH gateway
function sshConfig():?array {$c=config()['ssh_gateway']??null;return is_array($c)&&strlen((string)($c['secret']??''))>=32?$c+['url'=>'http://127.0.0.1:8022','ws'=>'/ssh-gateway/ws']:null;}
function gatewayCall(string $path,array $data):array {
    $c=sshConfig();if(!$c)fail('The SSH terminal is not configured on this server.',503);
    $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-Gateway-Secret: ".$c['secret']."\r\n",'content'=>json_encode($data,JSON_THROW_ON_ERROR),'timeout'=>5,'ignore_errors'=>true]]);
    $raw=@file_get_contents(rtrim($c['url'],'/').$path,false,$ctx);
    if($raw===false)fail('SSH gateway not reachable. Is the service ciegolinkinfrahub-ssh-gateway running?',502);
    $out=json_decode($raw,true);if(!is_array($out)||isset($out['error']))fail('SSH gateway error: '.(is_array($out)?$out['error']:'invalid response'),502);
    return $out;
}

// System info
function systemInfo():array {
    $ini=fn($k)=>(string)ini_get($k);$db=config()['data_dir'].'/infra.sqlite';
    $gw=['configured'=>sshConfig()!==null,'reachable'=>false];
    if($c=sshConfig()){$raw=@file_get_contents(rtrim($c['url'],'/').'/health',false,stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true]]));$h=is_string($raw)?json_decode($raw,true):null;if(is_array($h)&&!empty($h['ok']))$gw=['configured'=>true,'reachable'=>true]+$h;}
    $sw=0;$dir=config()['data_dir'].'/software';if(is_dir($dir))foreach(new DirectoryIterator($dir) as $f)if($f->isFile())$sw+=$f->getSize();
    return [
        'app'=>['name'=>'CiegoLink Infrastructure Hub','version'=>appVersion()],
        'server'=>['PHP'=>PHP_VERSION.' ('.PHP_SAPI.')','Operating system'=>php_uname('s').' '.php_uname('r'),'Web server'=>(string)($_SERVER['SERVER_SOFTWARE']??'—'),'SQLite'=>(string)db()->query('SELECT sqlite_version()')->fetchColumn(),'OpenSSL'=>OPENSSL_VERSION_TEXT,
            'PHP extensions'=>implode(', ',array_map(fn($e)=>$e.(extension_loaded($e)?' ✓':' ✗'),['pdo_sqlite','openssl','json','mbstring'])),
            'Upload limit (software)'=>round(softwareMax()/1048576).' MiB','upload_max_filesize / post_max_size'=>$ini('upload_max_filesize').' / '.$ini('post_max_size'),'memory_limit / max_execution_time'=>$ini('memory_limit').' / '.$ini('max_execution_time').' s',
            'Database size'=>is_file($db)?round(filesize($db)/1048576,2).' MiB':'—','Software files'=>round($sw/1048576,1).' MiB','Server time (UTC)'=>gmdate('Y-m-d H:i:s')],
        'gateway'=>$gw,
        'components'=>[
            ['name'=>'xterm.js','version'=>'5.5.0','use'=>'Browser terminal','license'=>'MIT'],
            ['name'=>'@xterm/addon-fit','version'=>'0.10.0','use'=>'Terminal sizing','license'=>'MIT'],
            ['name'=>'@xterm/addon-canvas','version'=>'0.7.0','use'=>'Terminal rendering','license'=>'MIT'],
            ['name'=>'marked','version'=>'12.0.2','use'=>'Markdown rendering (documentation)','license'=>'MIT'],
            ['name'=>'Turndown','version'=>'7.2.0','use'=>'Visual editor to Markdown (documentation)','license'=>'MIT'],
            ['name'=>'turndown-plugin-gfm','version'=>'1.0.2','use'=>'Tables and strikethrough in Markdown','license'=>'MIT'],
            ['name'=>'DOMPurify','version'=>'3.1.6','use'=>'HTML sanitising (documentation)','license'=>'Apache-2.0 / MPL-2.0'],
            ['name'=>'asyncssh','version'=>(string)($gw['asyncssh']??'—'),'use'=>'SSH gateway (optional service)','license'=>'EPL-2.0 / GPL-2.0+'],
            ['name'=>'aiohttp','version'=>(string)($gw['aiohttp']??'—'),'use'=>'SSH gateway WebSocket','license'=>'Apache-2.0'],
            ['name'=>'Python','version'=>(string)($gw['python']??'—'),'use'=>'SSH gateway runtime','license'=>'PSF'],
            ['name'=>'Manrope','version'=>'—','use'=>'Interface font','license'=>'SIL OFL 1.1'],
            ['name'=>'DM Serif Display','version'=>'—','use'=>'Headline font','license'=>'SIL OFL 1.1'],
            ['name'=>'JetBrains Mono','version'=>'—','use'=>'Monospace font','license'=>'SIL OFL 1.1'],
        ]];
}
// Documentation
function docsSchema():void {db()->exec("CREATE TABLE IF NOT EXISTS docs(id INTEGER PRIMARY KEY, title TEXT NOT NULL, category TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '', revision INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, updated_by TEXT NOT NULL DEFAULT '')");}
function docFields(array $in):array {$t=trim((string)($in['title']??''));$c=trim((string)($in['category']??''));$b=$in['body']??'';
    if(!is_string($b)||strlen($b)>1048576)fail('The document is too large (maximum 1 MiB of text).',422);
    if($t===''||strlen($t)>200||preg_match('/[\x00-\x1f\x7f]/',$t))fail('A title (up to 200 characters) is required.',422);
    if(strlen($c)>80||preg_match('/[\x00-\x1f\x7f]/',$c))fail('Invalid category.',422);
    if(preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$b))fail('The document contains invalid characters.',422);
    return ['title'=>$t,'category'=>$c,'body'=>str_replace("\r\n","\n",$b)];}
