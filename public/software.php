<?php
declare(strict_types=1);
// Software upload/download (streamed)
require dirname(__DIR__).'/core.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    sessionStart();migrate();$u=requireUser();softwareSchema();$action=$_GET['action']??'';
    if($action==='download'){
        if($_SERVER['REQUEST_METHOD']!=='GET')fail('GET required.',405);
        $s=query('SELECT * FROM software WHERE id=?',[(int)($_GET['id']??0)])->fetch();
        if(!$s)fail('Software not found.',404);
        $path=softwareDir().'/'.$s['stored'];
        if(!preg_match('/^[a-f0-9]{32}$/D',$s['stored'])||!is_file($path)||!is_readable($path))fail('File is missing on the server.',404);
        audit('software_download',(string)$s['id']);
        session_write_close();// do not block other requests of this user during long downloads
        $ascii=preg_replace('/[^A-Za-z0-9._ -]/','_',$s['filename'])?:'download';
        while(ob_get_level())ob_end_clean();
        set_time_limit(0);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($s['filename']));
        header('Content-Length: '.filesize($path));
        header('Content-Security-Policy: sandbox; default-src \'none\'');
        readfile($path);exit;
    }
    if($action==='upload'){
        if($_SERVER['REQUEST_METHOD']!=='POST')fail('POST required.',405);
        if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail('Session expired. Reload this page.',403);
        if($u['role']==='reader')fail('Read-only access.',403);
        $max=softwareMax();
        if(empty($_FILES['file'])){
            if((int)($_SERVER['CONTENT_LENGTH']??0)>iniBytes((string)ini_get('post_max_size')))fail('File exceeds the server upload limit (post_max_size).',413);
            fail('No file received.',422);
        }
        $f=$_FILES['file'];
        if(is_array($f['error']))fail('Upload one file at a time.',422);
        $errors=[UPLOAD_ERR_INI_SIZE=>'File exceeds the server upload limit (upload_max_filesize).',UPLOAD_ERR_FORM_SIZE=>'File too large.',UPLOAD_ERR_PARTIAL=>'Upload was interrupted. Please try again.',UPLOAD_ERR_NO_FILE=>'No file received.',UPLOAD_ERR_NO_TMP_DIR=>'Server has no temporary upload folder.',UPLOAD_ERR_CANT_WRITE=>'Server could not write the upload (disk full?).',UPLOAD_ERR_EXTENSION=>'Upload blocked by a PHP extension.'];
        if($f['error']!==UPLOAD_ERR_OK)fail($errors[$f['error']]??'Upload failed.',$f['error']===UPLOAD_ERR_INI_SIZE?413:422);
        if(!is_uploaded_file($f['tmp_name']))fail('Invalid upload.',422);
        $size=(int)filesize($f['tmp_name']);
        if($size<1)fail('The file is empty.',422);
        if($size>$max)fail('File too large. Maximum is '.round($max/1048576).' MiB.',413);
        $filename=preg_replace('/[\x00-\x1f\x7f]/','_',str_replace(['\\','/'],'_',(string)$f['name']));
        if($filename===null||trim($filename)===''||in_array($filename,['.','..'],true)||strlen($filename)>240)fail('Invalid filename.',422);
        $post=$_POST;if(!is_string($post['name']??null)||trim($post['name'])==='')$post['name']=substr(pathinfo($filename,PATHINFO_FILENAME),0,200);
        $meta=softwareMeta($post);
        $stored=bin2hex(random_bytes(16));$target=softwareDir().'/'.$stored;
        if(!move_uploaded_file($f['tmp_name'],$target))throw new RuntimeException('Cannot move upload to '.$target);
        @chmod($target,0600);
        $hash=hash_file('sha256',$target);
        try{query('INSERT INTO software(name,version,platform,note,filename,size,sha256,stored,created_at,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?)',[$meta['name'],$meta['version'],$meta['platform'],$meta['note'],$filename,$size,$hash,$stored,gmdate('c'),$u['display_name']?:$u['username']]);}
        catch(Throwable $e){@unlink($target);throw $e;}
        audit('software_uploaded',(string)db()->lastInsertId());
        response(['ok'=>true]);
    }
    fail('Unknown action.',404);
}catch(Throwable $e){error_log('CiegoLink Infra: '.$e->getMessage());fail('Server error. See the server log for details.',500);}
