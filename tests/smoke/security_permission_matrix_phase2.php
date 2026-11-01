<?php
/** Installed-package and public-query boundaries on a disposable smoke site. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__,2);
if(!is_file($root.'/storage/.smoke-state-backup/manifest.json'))throw new RuntimeException('Isolated smoke install required');
define('ROOT_PATH',$root); require $root.'/includes/init.php';
require_once $root.'/includes/SiteTemplateService.php';
$base=SITE_URL;
if(DB_DRIVER!=='sqlite' || DB_PREFIX!=='yikai_' || !preg_match('#^http://127\.0\.0\.1:([0-9]+)$#D',$base,$m))throw new RuntimeException('Loopback SQLite only');
$port=(int)$m[1];$probe=@stream_socket_server('tcp://127.0.0.1:'.$port);if(!$probe)throw new RuntimeException('Port in use');fclose($probe);
$db=db();$results=[];$raw=[];$jars=[];$csrf=[];$httpErrors=[];
$cleanupRoots=[$root.'/themes',$root.'/plugins',$root.'/uploads',$root.'/storage/site-templates',$root.'/storage/plugin-backup',$root.'/storage/plugin-locks',$root.'/storage/theme-backup',$root.'/storage/theme-locks'];
$beforeFiles=[];$beforeDirs=[];
foreach($cleanupRoots as $dir)if(is_dir($dir)) {
    if(!str_starts_with(str_replace('\\','/',(string)realpath($dir)),str_replace('\\','/',(string)realpath($root)).'/'))throw new RuntimeException('Fixture directories outside worktree');
    $beforeDirs[$dir]=true;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $f){if($f->isFile())$beforeFiles[$f->getPathname()]=true;elseif($f->isDir())$beforeDirs[$f->getPathname()]=true;}
}
$check=static function(bool $ok,string $label)use(&$results):void{$results[]=['ok'=>$ok,'label'=>$label];echo($ok?'PASS ':'FAIL ').$label."\n";};
foreach([[96,'phase2_maintenance',['system_maintenance']],[97,'phase2_editor',['edit_article']],[98,'phase2_other_admin',['*']]] as [$id,$name,$permissions]) {
    $db->insert('roles',['id'=>$id,'name'=>$name,'permissions'=>json_encode($permissions),'status'=>1,'created_at'=>time()]);
    $db->insert('users',['username'=>$name,'password'=>password_hash('Phase2@Test123',PASSWORD_DEFAULT),'role_id'=>$id,'status'=>1,'created_at'=>time(),'updated_at'=>time()]);
}
$private=[];$ids=[];
foreach(['article','case','product','download'] as $kind)foreach(['public','draft','deleted','foreign'] as $visibility) {
    $marker='PHASE2_QUERY_'.strtoupper($kind).'_'.strtoupper($visibility);
    $row=['title'=>$marker,'slug'=>strtolower($marker),'lang'=>$visibility==='foreign'?'en':'zh-CN','status'=>$visibility==='draft'?0:1,'created_at'=>time(),'updated_at'=>time()];
    if($visibility==='deleted')$row['deleted_at']=time();
    if($kind==='product'){$table='products';$row['category_id']=1;$row['summary']=$marker;}
    elseif($kind==='download'){$table='downloads';unset($row['slug']);$row['category_id']=2;$row['description']=$marker;}
    else {$table='contents';$row+=['channel_id'=>$kind==='case'?8:7,'type'=>$kind,'content'=>'<p>'.$marker.'</p>','summary'=>$marker];}
    $ids[$kind][$visibility]=(int)$db->insert($table,$row);
    if($visibility!=='public')$private[]=$marker;
}
$doc=json_encode(['schema'=>1,'settings'=>[], 'sections'=>[['id'=>'phase2-s','settings'=>[],'columns'=>[['id'=>'phase2-c','settings'=>[],'elements'=>[['id'=>'phase2-loop','type'=>'container','data'=>['_query'=>['source'=>'channel:7','keyword'=>'PHASE2_QUERY_','limit'=>2,'pagination'=>'ajax'],'children'=>[['id'=>'phase2-h','type'=>'heading','data'=>['text'=>'{{loop.title}}']]]]]]]]]]]);
$db->execute("UPDATE yikai_contents SET content_type='blocks',blocks_data=? WHERE channel_id=2",[$doc]);
$mediaFile=$root.'/uploads/phase2-media.svg';
if(is_file($mediaFile))throw new RuntimeException('Fixture already exists');
file_put_contents($mediaFile,'<svg xmlns="http://www.w3.org/2000/svg" width="3" height="2"><rect width="3" height="2" fill="blue"/></svg>');
$db->insert('media',['name'=>'Phase2 media','path'=>$mediaFile,'url'=>'/uploads/phase2-media.svg','type'=>'image','ext'=>'svg','mime'=>'image/svg+xml','size'=>filesize($mediaFile),'width'=>3,'height'=>2,'created_at'=>time()]);
$db->execute('UPDATE yikai_products SET cover=? WHERE id=?',['/uploads/phase2-media.svg',$ids['product']['public']]);
$package=tempnam(sys_get_temp_dir(),'phase2-site-');$service=new SiteTemplateService($root);$service->export($package);
$pluginZip=tempnam(sys_get_temp_dir(),'phase2-plugin-');
$writePlugin=static function(string $version)use($pluginZip):void {
    $z=new ZipArchive();$z->open($pluginZip,ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $z->addFromString('phase2-fixture-plugin/plugin.json',json_encode(['slug'=>'phase2-fixture-plugin','name'=>'Phase2 fixture','version'=>$version]));
    $z->addFromString('phase2-fixture-plugin/main.php','<?php declare(strict_types=1); if (!defined("ROOT_PATH")) exit; // '.$version);
    $z->close();
};$writePlugin('1.0.0');
$snapshot=static function()use($root,$db):string {
    $rows=[];foreach(array_merge(SiteTemplateData::TABLES,['settings','plugins']) as $table)if($db->tableExists($table)) {
        $rows[$table]=$db->fetchAll('SELECT * FROM yikai_'.$table);
        usort($rows[$table],static fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));
    }
    $files=[];foreach(['uploads','themes','plugins/phase2-fixture-plugin','storage/site-templates','storage/plugin-staging','storage/plugin-backup','storage/theme-staging','storage/theme-backup','storage/upgrade'] as $dir) {
        if(!is_dir($root.'/'.$dir))continue;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));
        foreach($it as $f)if($f->isFile() && !str_ends_with($f->getFilename(),'.lock'))$files[str_replace('\\','/',substr($f->getPathname(),strlen($root)+1))]=hash_file('sha256',$f->getPathname());
    }ksort($files);return hash('sha256',json_encode([$rows,$files]));
};
$request=static function(string $actor,string $method,string $url,?array $data=null,bool $token=true)use($base,&$jars,&$csrf,&$raw,&$httpErrors):array {
    if(!isset($jars[$actor]))$jars[$actor]=tempnam(sys_get_temp_dir(),'phase2-cookie-');
    $ch=curl_init($base.$url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>45,CURLOPT_COOKIEFILE=>$jars[$actor],CURLOPT_COOKIEJAR=>$jars[$actor],CURLOPT_HTTPHEADER=>['X-Requested-With: XMLHttpRequest']]);
    if($method==='POST'){$data??=[];if($token&&isset($csrf[$actor]))$data['_token']=$csrf[$actor];$multipart=count(array_filter($data,static fn($v)=>$v instanceof CURLFile))>0;curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$data:http_build_query($data)]);}
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$raw[]=$body;if($status>=500)$httpErrors[]=$url;return[$status,$body,json_decode($body,true)];
};
$log=tempnam(sys_get_temp_dir(),'phase2-server-');$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
try {
    for($i=0;$i<60;$i++){$s=@stream_socket_client('tcp://127.0.0.1:'.$port,$n,$e,.1);if($s){fclose($s);break;}usleep(100000);}
    foreach(['admin'=>['admin','smoke@Test123'],'maintenance'=>['phase2_maintenance','Phase2@Test123'],'editor'=>['phase2_editor','Phase2@Test123'],'other'=>['phase2_other_admin','Phase2@Test123']] as $actor=>[$user,$password]) {
        [, $html]=$request($actor,'GET','/admin/login.php');preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/',$html,$match);$csrf[$actor]=$match[1]??'';
        [$status]=$request($actor,'POST','/admin/login.php',['username'=>$user,'password'=>$password]);if($status!==302)throw new RuntimeException('Synthetic login failed: '.$actor);
        [, $html]=$request($actor,'GET','/admin/profile.php');if(preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/',$html,$match))$csrf[$actor]=$match[1];
    }
    $cases=[
        ['package-prepare','/admin/site_templates.php',['action'=>'prepare','package'=>new CURLFile($package,'application/zip','site.zip')]],
        ['plugin-upload','/admin/plugin.php',['action'=>'upload','plugin_zip'=>new CURLFile($pluginZip,'application/zip','plugin.zip')]],
        ['plugin-upgrade','/admin/plugin.php',['action'=>'market_install','slug'=>'cookie-consent']],
        ['theme-install','/admin/theme.php',['action'=>'market_install','slug'=>'default']],
        ['online-upgrade','/admin/upgrade_online.php',['action'=>'apply_prepare']],
    ];
    foreach($cases as [$name,$url,$data])foreach(['anonymous','editor','maintenance'] as $actor) {
        if($name==='online-upgrade' && $actor==='maintenance')continue;
        $before=$snapshot();[$status,$body,$json]=$request($actor,'POST',$url,$data);
        $check(in_array($status,[401,403],true)||in_array((int)($json['code']??0),[401,403],true),$name.': '.$actor.' denied');
        $check($before===$snapshot(),$name.': '.$actor.' no DB/package/files mutation');
    }
    foreach($cases as [$name,$url,$data]) {
        $before=$snapshot();[$status]=$request('admin','POST',$url,$data,false);
        $check($status===403 && $before===$snapshot(),$name.': missing CSRF denied without writes');
    }
    foreach(['article','case','product','download','all'] as $kind) {
        [$status,$body]=$request('anonymous','GET','/search.php?keyword=PHASE2_QUERY_&type='.$kind);
        $plain=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');
        $check($status===200 && str_contains($plain,'PHASE2_QUERY_'.strtoupper($kind==='all'?'article':$kind).'_PUBLIC'),'Public search '.$kind.' includes real published control');
        $check(count(array_filter($private,static fn($m)=>str_contains($plain,$m)))===0,'Public search '.$kind.' excludes drafts, deleted and foreign-language rows');
    }
    foreach(['article','case','product'] as $kind)foreach(['draft','deleted'] as $visibility) {
        $url=($kind==='product'?'/product.php':($kind==='case'?'/detail.php':'/article.php')).'?id='.$ids[$kind][$visibility].'&preview=admin&password=fake';
        [$status,$body]=$request('anonymous','GET',$url);
        $check($status===404 && !str_contains($body,'PHASE2_QUERY_'.strtoupper($kind).'_'.strtoupper($visibility)),'Forged public preview cannot reveal '.$kind.' '.$visibility);
    }
    foreach(['','&_ykq=missing-loop'] as $query) {
        [$status,$body]=$request('anonymous','GET','/page.php?id=2'.$query);
        $check($status===200 && str_contains($body,'<body') && str_contains($body,'PHASE2_QUERY_ARTICLE_PUBLIC'),'Normal page shell remains HTML for '.($query===''?'no fragment':'unmatched fragment'));
    }
    [$status,$body,$fragment]=$request('anonymous','GET','/page.php?id=2&_ykq=phase2-loop');
    $check($status===200 && is_array($fragment) && ($fragment['total']??-1)===1 && str_contains($fragment['html']??'','PHASE2_QUERY_ARTICLE_PUBLIC'),'Real AJAX loop returns only published query rows and count');
    $check(count(array_filter($private,static fn($m)=>str_contains($body,$m)))===0,'AJAX loop leaks no private markers');
    [$status,$body,$forged]=$request('anonymous','GET','/page.php?id=2&_ykq=phase2-loop&status=0&preview=admin&type=users');
    $check($status===200 && ($forged['total']??-1)===1 && count(array_filter($private,static fn($m)=>str_contains($body,$m)))===0,'Forged filter/status/preview parameters cannot widen AJAX visibility');
    [$status,$body]=$request('anonymous','GET','/search.php?keyword[]=PHASE2_QUERY_&type=article');
    $check($status===400 && !preg_match('/Fatal error|Uncaught|TypeError/',$body),'Malformed search keyword array returns 400 safely');
    [$status,$body]=$request('anonymous','GET','/search.php?keyword=PHASE2_QUERY_&type[]=article');
    $check($status===400 && !preg_match('/Fatal error|Uncaught|TypeError/',$body),'Malformed search type array returns 400 safely');
    foreach(['1.0.0','1.1.0'] as $version) {
        $writePlugin($version);[,,$json]=$request('admin','POST','/admin/plugin.php',['action'=>'upload','plugin_zip'=>new CURLFile($pluginZip,'application/zip','plugin.zip')]);
        $meta=json_decode((string)@file_get_contents($root.'/plugins/phase2-fixture-plugin/plugin.json'),true);
        $check(($json['code']??-1)===0 && ($meta['version']??'')===$version && (int)$db->fetchColumn("SELECT status FROM yikai_plugins WHERE slug='phase2-fixture-plugin'")===0,'Authorized local plugin install/update '.$version.' stays disabled');
    }
    foreach([['/admin/plugin.php?action=activate&slug=phase2-fixture-plugin'],['/admin/theme.php?action=market_install&slug=default'],['/admin/upgrade_online.php?action=apply_prepare']] as [$url]) {
        $before=$snapshot();[$status]=$request('admin','GET',$url);
        $check($status===200 && $before===$snapshot(),'GET action parameters cannot invoke install/upgrade mutations: '.parse_url($url,PHP_URL_PATH));
    }
    $originalSiteName=$db->fetchColumn("SELECT value FROM yikai_settings WHERE `key`='site_name'");
    [$status]=$request('admin','POST','/admin/site_templates.php',['action'=>'prepare','package'=>new CURLFile($package,'application/zip','site.zip')]);
    [, $planHtml]=$request('admin','GET','/admin/site_templates.php');
    preg_match('/name="token"[^>]*value="([a-f0-9]+)"/',$planHtml,$match);$planToken=$match[1]??'';
    $check($status===302 && $planToken!=='','Authorized HTTP package prepare stores a real plan');
    $actions=[['stage'],['refresh_preview'],['apply'],['restore']];
    foreach($actions as [$action])foreach(['anonymous','editor','maintenance'] as $actor) {
        $before=$snapshot();[$status,$body,$json]=$request($actor,'POST','/admin/site_templates.php',['action'=>$action,'token'=>$planToken,'trusted'=>'1','confirm'=>'1']);
        $check((in_array($status,[401,403],true)||in_array((int)($json['code']??0),[401,403],true)) && $before===$snapshot(),'Package '.$action.': '.$actor.' denied and unchanged');
    }
    foreach(['stage','apply'] as $action) {
        $before=$snapshot();$request('other','POST','/admin/site_templates.php',['action'=>$action,'token'=>$planToken,'trusted'=>'1','confirm'=>'1']);
        $check($before===$snapshot(),'Other superadmin cannot consume package owner token: '.$action);
    }
    foreach(['stage','refresh_preview','apply'] as $action) {
        $data=['action'=>$action,'token'=>$planToken,'trusted'=>'1','confirm'=>'1'];
        $before=$snapshot();[$status]=$request('admin','POST','/admin/site_templates.php',$data,false);
        $check($status===403 && $before===$snapshot(),'Package owner '.$action.' requires CSRF without writes');
        $before=$snapshot();[$status]=$request('admin','GET','/admin/site_templates.php?'.http_build_query($data));
        $check($status===200 && $before===$snapshot(),'GET cannot invoke package '.$action);
    }
    for($i=0;$i<20;$i++){[,,$stage]=$request('admin','POST','/admin/site_templates.php',['action'=>'stage','token'=>$planToken]);if(!empty($stage['data']['complete']))break;}
    $check(!empty($stage['data']['complete']) && ($stage['data']['total']??0)>0,'Authorized package media staging completes with real referenced media');
    $before=$snapshot();[,,$refused]=$request('admin','POST','/admin/site_templates.php',['action'=>'apply','token'=>$planToken,'trusted'=>'0','confirm'=>'0','ajax'=>'1']);
    $check(($refused['code']??0)===1,'Unconfirmed package apply is explicitly refused');
    $check($before===$snapshot(),'Unconfirmed package apply preserves DB, package plan and staged media');
    // Keep this run bounded; validation failures must not prevent a later authorized retry.
    [$status]=$request('admin','POST','/admin/site_templates.php',['action'=>'apply','token'=>$planToken,'trusted'=>'1','confirm'=>'1','site_name'=>'PHASE2_APPLIED_SITE']);
    $check($status===302 && $db->fetchColumn("SELECT value FROM yikai_settings WHERE `key`='site_name'")==='PHASE2_APPLIED_SITE','Authorized package replacement applies after refused attempt');
    $before=$snapshot();[$status]=$request('admin','GET','/admin/site_templates.php?action=restore&confirm=1');
    $check($status===200 && $before===$snapshot(),'GET cannot restore a replaced site');
    $before=$snapshot();[$status]=$request('admin','POST','/admin/site_templates.php',['action'=>'restore','confirm'=>'1'],false);
    $check($status===403 && $before===$snapshot(),'Package restore requires CSRF even after real replacement');
    [$status,,$restored]=$request('admin','POST','/admin/site_templates.php',['action'=>'restore','confirm'=>'1','ajax'=>'1']);
    $check($status===302 && $db->fetchColumn("SELECT value FROM yikai_settings WHERE `key`='site_name'")===$originalSiteName,'Authorized package restore returns previous site');
    $check($httpErrors===[] && !preg_match('/Fatal error|Uncaught|Warning:/',implode("\n",$raw).(string)file_get_contents($log)),'HTTP paths and server log have no PHP warnings/errors or 500 responses');
} finally {
    fclose($pipes[0]);proc_terminate($server);proc_close($server);foreach($jars as $jar)@unlink($jar);@unlink($log);@unlink($package);@unlink($pluginZip);@unlink($mediaFile);
    foreach($cleanupRoots as $dir)if(is_dir($dir)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $f) {
            if($f->isLink())continue;
            if($f->isFile() && !isset($beforeFiles[$f->getPathname()]))@unlink($f->getPathname());
            elseif($f->isDir() && !isset($beforeDirs[$f->getPathname()]))@rmdir($f->getPathname());
        }
        if(!isset($beforeDirs[$dir]))@rmdir($dir);
    }
}
$report=['php'=>PHP_VERSION,'assertions'=>count($results),'failed'=>count(array_filter($results,static fn($r)=>!$r['ok'])),'results'=>$results];
foreach($argv as $arg)if(str_starts_with($arg,'--out='))file_put_contents(substr($arg,6),json_encode($report,JSON_PRETTY_PRINT));
echo json_encode(['assertions'=>$report['assertions'],'failed'=>$report['failed']])."\n";exit($report['failed']?1:0);
