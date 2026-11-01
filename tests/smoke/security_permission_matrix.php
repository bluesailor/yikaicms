<?php
/** High-risk permission matrix: valid payloads, real sessions, DB/file no-write assertions. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
if(!is_file($root.'/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Isolated smoke setup required');
define('ROOT_PATH',$root);
require $root.'/includes/init.php';
require_once $root.'/includes/ai-access/AiProposalService.php';
require_once $root.'/includes/ai-access/AiAccessTokenStore.php';
$base=SITE_URL;
if(DB_DRIVER!=='sqlite' || DB_PREFIX!=='yikai_' || !preg_match('#^http://127\.0\.0\.1:([0-9]+)$#D',$base,$m)) throw new RuntimeException('Disposable loopback SQLite only');
$port=(int)$m[1];
$probe=@stream_socket_server('tcp://127.0.0.1:'.$port);
if(!$probe) throw new RuntimeException('Test port already in use');
fclose($probe);
$db=db();
settingModel()->set('ai_access_enabled','1');
$ownedRoots=[$root.'/uploads',$root.'/storage/backups/media']; $ownedBefore=[];
foreach($ownedRoots as $ownedRoot)if(is_dir($ownedRoot)) {
    $resolved=str_replace('\\','/',(string)realpath($ownedRoot));
    if(!str_starts_with($resolved,str_replace('\\','/',(string)realpath($root)).'/'))throw new RuntimeException('Fixture directories must stay inside this worktree');
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ownedRoot,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file)if($file->isFile())$ownedBefore[$file->getPathname()]=true;
}
$roles=['none'=>[], 'article'=>['edit_article'], 'page'=>['blox_edit','edit_page'], 'media'=>['media'], 'other'=>['ai_access','blox_edit','edit_page']];
$accounts=['admin'=>['admin','smoke@Test123']];
foreach(array_keys($roles) as $i=>$role) {
    $id=91+$i;
    $db->insert('roles',['id'=>$id,'name'=>'Matrix '.$role,'permissions'=>json_encode($roles[$role]),'status'=>1,'created_at'=>time()]);
    $user='matrix_'.$role;
    $db->insert('users',['username'=>$user,'password'=>password_hash('Matrix@Test123',PASSWORD_DEFAULT),'role_id'=>$id,'status'=>1,'created_at'=>time(),'updated_at'=>time()]);
    $accounts[$role]=[$user,'Matrix@Test123'];
}
$marker='MATRIX_PRIVATE_DRAFT_1101';
$article=$db->insert('contents',['channel_id'=>7,'type'=>'article','title'=>$marker,'content'=>'<p>'.$marker.'</p>','lang'=>'zh-CN','status'=>0,'admin_id'=>1,'created_at'=>time(),'updated_at'=>time()]);
$doc=json_encode(['schema'=>1,'settings'=>[], 'sections'=>[['id'=>'matrix-s','settings'=>[],'columns'=>[['id'=>'matrix-c','settings'=>[],'elements'=>[['id'=>'matrix-h','type'=>'heading','data'=>['text'=>$marker]]]]]]]]);
$db->execute('DELETE FROM yikai_blox_page_drafts WHERE page_id=2');
$db->insert('blox_page_drafts',['page_id'=>2,'draft_data'=>$doc,'updated_at'=>time()]);
$path=$root.'/uploads/images/matrix-permission.png';
if(is_file($path)) throw new RuntimeException('Fixture file already exists');
$image=imagecreatetruecolor(3,2); imagepng($image,$path); imagedestroy($image);
$pdf=tempnam(sys_get_temp_dir(),'matrix-pdf-');
file_put_contents($pdf,"%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
$svg=tempnam(sys_get_temp_dir(),'matrix-svg-');
file_put_contents($svg,'<svg xmlns="http://www.w3.org/2000/svg" width="3" height="2"><rect width="3" height="2" fill="blue"/></svg>');
$media=$db->insert('media',['name'=>'Matrix image','path'=>$path,'url'=>'/uploads/images/matrix-permission.png','type'=>'image','ext'=>'png','mime'=>'image/png','size'=>filesize($path),'width'=>3,'height'=>2,'admin_id'=>1,'created_at'=>time()]);
$media=(int)$media; $article=(int)$article;
setMeta('media',$media,'alt','MATRIX_OLD_ALT');
$token=(new AiAccessTokenStore($db))->issue(1,'Matrix synthetic',['page_read','page_content_propose','proposal_status'],1,time());
require_once $root.'/includes/ai-access/AiPageDocumentSource.php';
$source=new AiPageDocumentSource($db); $state=$source->load($source->page(2));
$ctx=new AiAccessAuthContext(1,$token['id'],['*'],['page_read','page_content_propose','proposal_status']);
$proposal=(new AiProposalService($db))->proposePageFromToken($ctx,'matrix-proposal-1101',['page_id'=>2,'lang'=>'zh-CN','base_revision'=>$state['base_revision'],'changes'=>[['element_id'=>'matrix-h','field'=>'text','value'=>'MATRIX_PROPOSED_PRIVATE_1101']]],time());
$item=$proposal['items'][0]['item_id'];
$results=[]; $raw=[]; $jars=[]; $csrf=[]; $ownedUploadPaths=[];
$check=static function(bool $ok,string $label) use(&$results):void {$results[]=['ok'=>$ok,'label'=>$label];echo($ok?'PASS ':'FAIL ').$label."\n";};
$snapshot=static function() use($db,$root):string {
    $rows=[];
    foreach(['settings','metas','media','channels','contents','blox_page_drafts','blox_templates','content_revisions','ai_proposals','ai_proposal_items','ai_change_log'] as $table) if($db->tableExists($table)) $rows[$table]=$db->fetchAll('SELECT * FROM yikai_'.$table.' ORDER BY id');
    $files=[];
    foreach(['uploads','storage/backups','storage/site-templates','storage/site_templates','storage/imports'] as $dir) {
        if(!is_dir($root.'/'.$dir))continue;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
        foreach($it as $f) if($f->isFile()) $files[str_replace('\\','/',substr($f->getPathname(),strlen($root)+1))]=hash_file('sha256',$f->getPathname());
    }
    ksort($files);return hash('sha256',json_encode([$rows,$files]));
};
$request=static function(string $actor,string $method,string $url,?array $data=null,bool $withCsrf=true) use($base,&$jars,&$csrf,&$raw):array {
    if(!isset($jars[$actor]))$jars[$actor]=tempnam(sys_get_temp_dir(),'yk-matrix-cookie-');
    $ch=curl_init($base.$url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$jars[$actor],CURLOPT_COOKIEJAR=>$jars[$actor],CURLOPT_HTTPHEADER=>['X-Requested-With: XMLHttpRequest']]);
    if($method==='POST') {
        $data??=[];if($withCsrf && isset($csrf[$actor]))$data['_token']=$csrf[$actor];
        $multipart=count(array_filter($data,static fn($v)=>$v instanceof CURLFile))>0;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$data:http_build_query($data)]);
    } elseif($method!=='GET')curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$raw[]=$body;
    return [$status,$body,json_decode($body,true)];
};
$log=tempnam(sys_get_temp_dir(),'yk-matrix-server-');
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
try {
    for($i=0;$i<60;$i++){$s=@stream_socket_client('tcp://127.0.0.1:'.$port,$n,$e,.1);if($s){fclose($s);break;}usleep(100000);}
    foreach($accounts as $actor=>[$user,$pass]) {
        [, $login]=$request($actor,'GET','/admin/login.php');
        preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/',$login,$match);$csrf[$actor]=$match[1]??'';
        [$status]=$request($actor,'POST','/admin/login.php',['username'=>$user,'password'=>$pass]);
        if($status!==302)throw new RuntimeException('Synthetic login failed: '.$actor);
        [, $profile]=$request($actor,'GET','/admin/profile.php');
        if(preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/',$profile,$match))$csrf[$actor]=$match[1];
    }
    $templateData=json_decode((string)file_get_contents($root.'/tests/e2e/fixtures/section-template.json'),true);
    $templateData['name']='Matrix import fixture'; $template=json_encode($templateData);
    $before=$snapshot();
    [,,$plan]=$request('admin','POST','/admin/recipe.php',['action'=>'preview','slug'=>'business-site']);
    $check(($plan['code']??-1)===0 && !empty($plan['data']['token']),'Authorized recipe preview produces a real plan');
    $check($before===$snapshot(),'Recipe preview does not mutate business data or files');
    $cases=[
        ['draft-preview','GET','/admin/draft_preview.php?type=contents&id='.$article,null,['admin','article']],
        ['blox-preview','POST','/admin/blox_preview.php?id=2',['action'=>'preview','blocks_data'=>$doc],['admin','page','other']],
        ['image-upload','POST','/admin/upload.php',['type'=>'images','file'=>new CURLFile($path,'image/png','matrix.png')],['admin','article','page','media','other']],
        ['svg-upload','POST','/admin/upload.php',['type'=>'images','file'=>new CURLFile($svg,'image/svg+xml','matrix.svg')],['admin','article','page','media','other']],
        ['document-upload','POST','/admin/upload.php',['type'=>'files','file'=>new CURLFile($pdf,'application/pdf','matrix.pdf')],['admin','media']],
        ['media-edit','POST','/admin/media_edit.php',['action'=>'save','id'=>$media,'history'=>'[]','alt'=>'MATRIX_NEW_ALT'],['admin','media']],
        ['template-import','POST','/admin/blox_templates.php',['action'=>'import_confirm','template_json'=>$template],['admin']],
        ['recipe-preview','POST','/admin/recipe.php',['action'=>'preview','slug'=>'business-site'],['admin']],
        ['recipe-apply','POST','/admin/recipe.php',['action'=>'apply','slug'=>'business-site','plan_token'=>$plan['data']['token']??''],['admin']],
        ['proposal-preview','GET','/admin/ai_proposals.php?preview='.$item,null,['admin']],
        ['proposal-apply','POST','/admin/ai_proposals.php',['action'=>'apply','item_ids'=>[$item]],['admin']],
    ];
    foreach($cases as [$name,$method,$url,$payload,$allowed])foreach(['anonymous','none','article','page','media','other'] as $actor) {
        if(in_array($actor,$allowed,true))continue;
        $before=$snapshot();[$status,$body,$json]=$request($actor,$method,$url,$payload);
        $denied=in_array($status,[401,403],true) || in_array((int)($json['code']??0),[401,403],true) || ($name==='proposal-preview' && $actor==='other' && $status===409)
            || ($name==='proposal-apply' && $actor==='other' && (int)($json['code']??0)===1);
        $check($denied,$name.': '.$actor.' denied');
        $check(!str_contains($body,$marker) && !str_contains($body,'MATRIX_PROPOSED_PRIVATE_1101'),$name.': '.$actor.' no private content leak');
        $check($before===$snapshot(),$name.': '.$actor.' DB and files unchanged');
    }
    foreach($cases as [$name,$method,$url,$payload,$allowed])if($method==='POST') {
        $before=$snapshot();[$status,$body,$json]=$request('admin','POST',$url,$payload,false);
        $check($status===419 || $status===403 || in_array((int)($json['code']??0),[419,403],true),$name.': missing CSRF denied');
        $check($before===$snapshot(),$name.': missing CSRF no writes');
    }
    foreach(['save','restore'] as $action)foreach(['GET','HEAD','PUT','PATCH','DELETE'] as $method) {
        if($action==='save')setMeta('media',$media,'alt','MATRIX_OLD_ALT');
        else {
            $original=$root.'/storage/backups/media/originals/'.$media.'.png';
            if(!is_dir(dirname($original)))mkdir(dirname($original),0755,true);
            copy($path,$original);
        }
        $before=$snapshot();[$status]=$request('media',$method,'/admin/media_edit.php?action='.$action.'&id='.$media);
        $check($status===405,'media-edit '.$action.': '.$method.' rejected with 405');
        $check($before===$snapshot(),'media-edit '.$action.': '.$method.' no writes');
    }
    [,,$json]=$request('media','POST','/admin/media_edit.php',['action'=>'save','id'=>$media,'history'=>'[]','alt'=>'MATRIX_ALLOWED_ALT']);
    $check(($json['code']??-1)===0 && getMeta('media',$media,'alt')==='MATRIX_ALLOWED_ALT','Authorized media POST really saves');
    [,,$json]=$request('media','POST','/admin/media_edit.php',['action'=>'save','id'=>$media,'history'=>'[{"op":"rotate","deg":90}]','alt'=>'MATRIX_ALLOWED_ALT']);
    $check(($json['code']??-1)===0 && ($json['data']['width']??0)===2 && ($json['data']['height']??0)===3,'Authorized image rotation POST creates a real restorable edit');
    $before=$snapshot();[$status]=$request('media','POST','/admin/media_edit.php',['action'=>'restore','id'=>$media],false);
    $check($status===403 && $before===$snapshot(),'Restore without CSRF refuses and preserves edited image plus backup');
    [,,$json]=$request('media','POST','/admin/media_edit.php',['action'=>'restore','id'=>$media]);
    $dimensions=getimagesize($path);
    $check(($json['code']??-1)===0 && $dimensions[0]===3 && $dimensions[1]===2,'Authorized restore POST restores real original dimensions');
    [$status,$body]=$request('article','GET','/admin/draft_preview.php?type=contents&id='.$article);
    $check($status===200 && str_contains($body,$marker),'Authorized article editor sees real draft');
    [$status,$body]=$request('page','POST','/admin/blox_preview.php?id=2',['action'=>'preview','blocks_data'=>$doc]);
    $check($status===200 && str_contains($body,$marker),'Authorized page editor sees real canvas preview');
    [$status]=$request('admin','POST','/admin/blox_templates.php',['action'=>'import_confirm','template_json'=>$template]);
    $check($status===302 && (int)$db->fetchColumn("SELECT COUNT(*) FROM yikai_blox_templates WHERE source='import' AND name='Matrix import fixture' AND status=0")===1,'Authorized admin imports a real template draft');
    foreach([['article','images',$path,'image/png','matrix.png'],['article','images',$svg,'image/svg+xml','matrix.svg'],['media','files',$pdf,'application/pdf','matrix.pdf']] as [$actor,$type,$file,$mime,$name]) {
        [,,$json]=$request($actor,'POST','/admin/upload.php',['type'=>$type,'file'=>new CURLFile($file,$mime,$name)]);
        if((int)($json['data']['id']??0)>0) {
            $uploaded=$db->fetchColumn('SELECT path FROM yikai_media WHERE id=?',[(int)$json['data']['id']]);
            if(is_string($uploaded) && str_starts_with(str_replace('\\','/',$uploaded),str_replace('\\','/',$root).'/uploads/'))$ownedUploadPaths[]=$uploaded;
        }
        $check(($json['code']??-1)===0 && (int)($json['data']['id']??0)>0,'Authorized '.$actor.' uploads real '.$name);
    }
    $check(!preg_match('/Fatal error|Uncaught|Warning:/',implode("\n",$raw).(string)file_get_contents($log)),'No PHP errors in responses or server log');
} finally {
    fclose($pipes[0]);proc_terminate($server);proc_close($server);foreach($jars as $jar)@unlink($jar);@unlink($log);
    // Only the uniquely named fixture is removed here; smoke restore handles database state.
    @unlink($path);
    @unlink($pdf);@unlink($svg);
    foreach($ownedUploadPaths as $ownedPath)@unlink($ownedPath);
    foreach($ownedRoots as $ownedRoot)if(is_dir($ownedRoot)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ownedRoot,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file)if($file->isFile() && !$file->isLink() && !isset($ownedBefore[$file->getPathname()]))@unlink($file->getPathname());
    }
}
$output=['php'=>PHP_VERSION,'results'=>$results,'assertions'=>count($results),'failed'=>count(array_filter($results,static fn($r)=>!$r['ok']))];
foreach($argv as $arg)if(str_starts_with($arg,'--out='))file_put_contents(substr($arg,6),json_encode($output,JSON_PRETTY_PRINT));
echo json_encode(['assertions'=>$output['assertions'],'failed'=>$output['failed']])."\n";
exit($output['failed']>0?1:0);
