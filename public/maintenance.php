<?php
// Maintenance API (read-only). Token: Authorization: Bearer, X-Api-Token or ?token=
declare(strict_types=1);
require dirname(__DIR__).'/core.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Headers: Authorization, X-Api-Token');header('Access-Control-Allow-Methods: GET, OPTIONS');
try {
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if($method==='OPTIONS'){http_response_code(204);exit;}
    if($method!=='GET'&&$method!=='HEAD')fail('Method not allowed.',405);
    if(($_SERVER['HTTPS']??'')!=='on'&&!(config()['allow_http_local']??false))fail('HTTPS is required.',403);
    migrate();
    rate('mapi:'.($_SERVER['REMOTE_ADDR']??''),1200);
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??($_SERVER['REDIRECT_HTTP_AUTHORIZATION']??''));
    $token=preg_match('/^Bearer\s+(\S+)$/i',$auth,$m)?$m[1]:(string)($_SERVER['HTTP_X_API_TOKEN']??($_GET['token']??''));
    $t=apiTokenCheck($token);
    if(!$t){rate('mapi-bad:'.($_SERVER['REMOTE_ADDR']??''),30);fail('Invalid or missing API token.',401);}
    $scope=(string)($_GET['scope']??'current');
    if(!in_array($scope,['current','active','upcoming','completed','all'],true))fail('Invalid scope. Use current, active, upcoming, completed or all.',422);
    $days=max(1,min(365,(int)($_GET['days']??90)));
    $past=max(1,min(365,(int)($_GET['past']??30)));
    $now=time();$items=[];
    $wsf=(string)($_GET['workspace']??'');if($wsf!==''&&!ctype_digit($wsf))fail('Invalid workspace. Use the numeric workspace ID.',422);
    foreach(query("SELECT r.id,r.data,r.ws,w.name AS ws_name FROM records r LEFT JOIN workspaces w ON w.id=r.ws WHERE r.kind='maintenance'".($wsf!==''?' AND r.ws=?':''),$wsf!==''?[(int)$wsf]:[])->fetchAll() as $r){
        $d=json_decode($r['data'],true)?:[];if(($d['public']??'1')!=='1')continue;
        $i=maintenanceItem((int)$r['id'],$d,$now)+['workspace'=>['id'=>(int)$r['ws'],'name'=>(string)($r['ws_name']??'')]];$st=strtotime($i['start_utc']);$en=strtotime($i['end_utc']);
        $ok=match($scope){
            'current'=>in_array($i['status'],['active','upcoming'],true)&&$st<=$now+$days*86400,
            'active'=>$i['status']==='active',
            'upcoming'=>$i['status']==='upcoming'&&$st<=$now+$days*86400,
            'completed'=>$i['status']==='completed'&&$en>=$now-$past*86400,
            'all'=>$en>=$now-$past*86400&&$st<=$now+$days*86400,
        };
        if($ok)$items[]=$i;
    }
    usort($items,fn($a,$b)=>strcmp($a['start_utc'],$b['start_utc'])?:$a['id']<=>$b['id']);
    $active=array_values(array_filter($items,fn($i)=>$i['status']==='active'));
    $next=null;foreach($items as $i)if($i['status']==='upcoming'){$next=$i;break;}
    if($method==='HEAD')exit;
    response(['api'=>'ciegolinkinfrahub-maintenance','api_version'=>1,'workspace'=>$wsf!==''?(int)$wsf:null,'generated_at'=>maintenanceTime($now),'timezone'=>maintenanceZone()->getName(),'scope'=>$scope,'maintenance_active'=>(bool)$active,'active'=>$active,'next'=>$next,'count'=>count($items),'items'=>$items]);
}catch(Throwable $e){error_log('CiegoLink Infra maintenance API: '.$e->getMessage());fail('Server error.',500);}
