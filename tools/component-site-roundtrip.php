<?php
/** Synthetic components → real export ZIP → fresh HTTP install → import → render/diff. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$options=getopt('',['report:','port::','keep']);
$reportPath=$options['report']??'';
if($reportPath===''){fwrite(STDERR,"Usage: php tools/component-site-roundtrip.php --report=<result.json> [--port=18907] [--keep]\n");exit(2);}
if(!is_dir(dirname($reportPath)) || !is_writable(dirname($reportPath)))throw new RuntimeException('Report directory must exist and be writable');
$port=(int)($options['port']??18907);
if($port<1024 || $port>65535)throw new RuntimeException('Invalid loopback port');
$probe=@stream_socket_server('tcp://127.0.0.1:'.$port);if(!$probe)throw new RuntimeException('Port already occupied');fclose($probe);
$base='http://127.0.0.1:'.$port;
$parent=str_replace('\\','/',rtrim(sys_get_temp_dir(),'/\\')).'/yk-component-roundtrip-'.bin2hex(random_bytes(6));
mkdir($parent,0700,true);
$realParent=str_replace('\\','/',(string)realpath($parent));
$report=['php'=>PHP_VERSION,'db'=>'sqlite','git_head'=>trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD')),'checks'=>[],'passed'=>false];
foreach(['includes/SiteTemplateData.php','includes/builder/BloxComponents.php','tests/fixtures/component-site-driver.php',
    'tools/component-site-roundtrip.php','config/site-template-schema.php'] as $file)$report['source_sha256'][$file]=hash_file('sha256',$root.'/'.$file);
$server=null; $httpBodies=['source'=>[],'target'=>[]];
function cc_check(bool $ok,string $label): void {
    global $report; $report['checks'][]=['ok'=>$ok,'label'=>$label];echo ($ok?'PASS ':'FAIL ').$label."\n";
}
function cc_http(string $path,?array $form=null): array {
    global $base;
    $ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>40,CURLOPT_FOLLOWLOCATION=>false]);
    if($form!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);
    $body=(string)curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    return ['status'=>$status,'body'=>$body,'type'=>$type];
}
function cc_start(string $site): void {
    global $server,$port;
    $ready=bin2hex(random_bytes(12));file_put_contents($site.'/roundtrip-ready.txt',$ready);
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$site],[0=>['pipe','r'],1=>['file',$site.'/server.log','a'],2=>['file',$site.'/server.log','a']],$pipes,$site);
    if(!is_resource($server))throw new RuntimeException('Server start failed');fclose($pipes[0]);
    for($i=0;$i<80;$i++){
        if(!proc_get_status($server)['running'])throw new RuntimeException('Owned server exited before readiness');
        $response=cc_http('/roundtrip-ready.txt');if($response['status']===200 && $response['body']===$ready)return;
        usleep(100000);
    }
    throw new RuntimeException('Server readiness timeout');
}
function cc_stop(): void { global $server;if(is_resource($server)){proc_terminate($server);proc_close($server);$server=null;} }
function cc_install(string $site): void {
    global $root,$base;
    mkdir($site,0700,true);
    $files=explode("\0",(string)shell_exec('git -C '.escapeshellarg($root).' ls-files -z'));
    foreach($files as $file){
        if($file==='' || str_contains($file,'..') || preg_match('#^(?:tests|docs|tools|vendor|node_modules|marketplace|\.github|\.git)/#',$file))continue;
        $source=$root.'/'.$file;if(!is_file($source))continue;
        $destination=$site.'/'.$file;if(!is_dir(dirname($destination)))mkdir(dirname($destination),0700,true);copy($source,$destination);
    }
    copy($root.'/tests/fixtures/component-site-driver.php',$site.'/component-driver.php');
    cc_start($site);
    $response=cc_http('/install/index.php',['action'=>'install','db_driver'=>'sqlite','db_prefix'=>'yikai_','admin_user'=>'rt'.bin2hex(random_bytes(4)),
        'admin_pass'=>'Rt#'.bin2hex(random_bytes(12)),'admin_email'=>'roundtrip@example.test','site_name'=>'Roundtrip','site_url'=>$base,
        'site_lang'=>'zh-CN','admin_lang'=>'zh-CN','install_demo'=>'0']);
    $json=json_decode($response['body'],true);
    if($response['status']!==200 || empty($json['success']))throw new RuntimeException('Synthetic HTTP installation failed: '.substr($response['body'],0,400));
    cc_check(is_file($site.'/installed.lock'),'Real installer completed: '.basename($site));
}
function cc_driver(string $site,string $mode,string $lang='zh-CN',string $path=''): array {
    $out=[];$code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($site.'/component-driver.php').' '.escapeshellarg($mode).' '.escapeshellarg($lang).' '.escapeshellarg($path).' 2>&1',$out,$code);
    $json=json_decode(implode("\n",$out),true);
    if($code!==0 || !is_array($json))throw new RuntimeException('Fixture '.$mode.' failed: '.implode("\n",array_slice($out,-7)));
    return $json;
}
$originals=['cmp_1234567890abcdef','cmp_abcdef1234567890','cmp_1111222233334444'];$frozen=[];
foreach($originals as $uuid)$frozen['cmp_'.substr(hash('sha256',$uuid.'@1'),0,16)]=$uuid;
$report['allowed_uuid_mapping']=$frozen;
function cc_tree(mixed $value): mixed {
    global $frozen,$originals;
    if(!is_array($value))return is_string($value)?cc_html($value):$value;
    if(($value['type']??'')==='component' && is_array($value['data']??null)) {
        $uuid=$value['data']['component']??'';
        if(isset($frozen[$uuid])){$value['data']['component']=$frozen[$uuid];$value['data']['pin']=1;}
        elseif(in_array($uuid,$originals,true) && in_array((int)($value['data']['pin']??0),[0,2],true))unset($value['data']['pin']);
    }
    foreach($value as $key=>$child)$value[$key]=cc_tree($child);
    if(!array_is_list($value))ksort($value);
    return $value;
}
function cc_html(string $html): string {
    global $frozen;
    $html=strtr($html,$frozen);
    return preg_replace('#/uploads/sitepack-[a-z0-9-]+/#','/uploads/',$html)??$html;
}
function cc_order(mixed $value): mixed {
    if(!is_array($value))return $value;
    foreach($value as $key=>$child)$value[$key]=cc_order($child);
    if(!array_is_list($value))ksort($value);
    return $value;
}
function cc_fragment(string $html): string {
    $doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();
    $out='';foreach((new DOMXPath($doc))->query('//*[@data-yk-component and not(ancestor::*[@data-yk-component])]') as $node)$out.=$doc->saveHTML($node);
    return $out;
}
function cc_fetch_pages(array $state,string $side): array {
    global $httpBodies;
    $result=[];
    foreach($state['pages'] as $id=>$page) {
        $response=cc_http('/page.php?id='.$id.'&_lang='.($id>=700?'en':'zh-CN'));
        cc_check($response['status']===200 && !preg_match('/Fatal error|Uncaught|Warning:/',$response['body']),'HTTP page '.$side.' '.$id);
        $httpBodies[$side][$id]=$response['body'];$result[$id]=cc_fragment($response['body']);
    }
    return $result;
}
function cc_styles(string $html,string $side): array {
    $doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);libxml_clear_errors();
    $xpath=new DOMXPath($doc);$hashes=[];
    foreach($xpath->query('//link[@rel="stylesheet"]/@href') as $attribute){
        $url=html_entity_decode($attribute->nodeValue,ENT_QUOTES);
        if(!str_starts_with($url,'/') || str_starts_with($url,'//'))continue;
        $response=cc_http($url);cc_check($response['status']===200,'Stylesheet resolves '.$side.' '.basename((string)parse_url($url,PHP_URL_PATH)));
        $key=preg_replace('#/themes/sitepack-[a-z0-9-]+/#','/themes/default/',(string)parse_url($url,PHP_URL_PATH));
        $hashes[$key]=hash('sha256',cc_html($response['body']));
    }
    foreach($xpath->query('//style') as $n=>$node)$hashes['inline-'.$n]=hash('sha256',cc_html($node->textContent));
    ksort($hashes);return $hashes;
}
function cc_cleanup(string $dir): void {
    global $realParent;
    $resolved=str_replace('\\','/',(string)realpath($dir));
    if($resolved!==$realParent && !str_starts_with($resolved,$realParent.'/'))throw new RuntimeException('Cleanup escaped owned sandbox');
    foreach(new DirectoryIterator($dir) as $entry)if(!$entry->isDot()) {
        $path=$entry->getPathname();
        if($entry->isLink())unlink($path);elseif($entry->isDir())cc_cleanup($path);else unlink($path);
    }
    rmdir($dir);
}
try {
    $source=$parent.'/source';$target=$parent.'/target';$zip=$parent.'/components.zip';
    cc_install($source);
    cc_check(cc_driver($source,'inspect')['pending']===[],'Pristine source install has zero pending migrations');
    cc_driver($source,'seed');
    $before=[];$sourceHttp=[];
    foreach(['zh-CN','en'] as $lang){$before[$lang]=cc_driver($source,'inspect',$lang);$sourceHttp+=cc_fetch_pages($before[$lang],'source');}
    cc_check(str_contains($before['zh-CN']['pages'][601]['html'],'FEATURED_V1') && !str_contains($before['zh-CN']['pages'][601]['html'],'VERSION_2_ONLY'),'Source pin actually renders v1 despite latest v2');
    cc_check(str_contains($before['en']['pages'][705]['html'],'OVERRIDE_EN') && str_contains($before['en']['pages'][706]['html'],'DEFAULT_EN_V1'),'Source localized instance and default controls');
    cc_check(str_contains($before['zh-CN']['pages'][607]['html'],'OUTER_V1') && str_contains($before['zh-CN']['pages'][607]['html'],'VERSION_2_ONLY'),'Source shallow pin leaves unpinned inner component on latest');
    cc_check(str_contains($sourceHttp[601],'FEATURED_V1') && !str_contains($sourceHttp[601],'VERSION_2_ONLY'),'Anonymous source HTTP actually renders pinned v1');
    cc_check(str_contains($sourceHttp[605],'OVERRIDE_ZH') && str_contains($sourceHttp[705],'OVERRIDE_EN') && str_contains($sourceHttp[706],'DEFAULT_EN_V1'),'Anonymous source HTTP language controls');
    cc_check(str_contains($sourceHttp[601],'yk-c-roundtrip-title'),'Source global class is actually attached to rendered content');
    $sourceHome=cc_http('/');$sourceHomeFragment=cc_fragment($sourceHome['body']);
    cc_check($sourceHome['status']===200 && str_contains($sourceHomeFragment,'OUTER_V1') && str_contains($sourceHomeFragment,'FEATURED_V1'),'Source home renders the nested pinned control');
    $sourceStyles=cc_styles($httpBodies['source'][601],'source');
    cc_check($sourceStyles!==[],'Source has actual stylesheet controls');
    cc_driver($source,'export','zh-CN',$zip);
    cc_check($before['zh-CN']===cc_driver($source,'inspect'),'Export did not mutate source documents, revisions or indexes');
    $report['package_sha256']=hash_file('sha256',$zip);
    $archive=new ZipArchive();$archive->open($zip);$payload='';for($i=0;$i<$archive->numFiles;$i++)$payload.=$archive->getFromIndex($i);$archive->close();
    cc_check(!str_contains($payload,'VERSION_99_ONLY') && !str_contains($payload,'RT_PRIVATE_SECRET'),'Unpublished component draft and secrets excluded');
    cc_check(str_contains($payload,'rt-v1.svg'),'Old-version-only media included');
    $repeat=$parent.'/repeat.zip';cc_driver($source,'export','zh-CN',$repeat);
    $readData=static function(string $file):array{$z=new ZipArchive();$z->open($file);$json=$z->getFromName('site.json');$z->close();return json_decode($json,true)['data'];};
    $expectedData=$readData($zip);
    cc_check(cc_order($expectedData)===cc_order($readData($repeat)),'Repeated export has stable frozen UUIDs, IDs and payload');
    cc_driver($source,'existing-frozen','zh-CN',$zip);cc_driver($source,'export','zh-CN',$repeat);
    cc_check(cc_order($expectedData)===cc_order($readData($repeat)),'Identical existing frozen masters are deduplicated');
    foreach(cc_driver($source,'collision','zh-CN',$repeat)['cases'] as $kind=>$blocked)
        cc_check($blocked,'Conflicting frozen UUID is rejected: '.$kind);
    cc_stop();
    cc_install($target);
    cc_check(cc_driver($target,'inspect')['pending']===[],'Fresh target install has zero pending migrations');
    cc_driver($target,'import','zh-CN',$zip);
    $after=[];$targetHttp=[];
    foreach(['zh-CN','en'] as $lang){
        $after[$lang]=cc_driver($target,'inspect',$lang);$targetHttp+=cc_fetch_pages($after[$lang],'target');
        foreach($before[$lang]['pages'] as $id=>$page) {
            $actual=$after[$lang]['pages'][$id]??[];
            cc_check(cc_tree($page['doc'])===cc_tree($actual['doc']??[]),'Document tree/props/variant '.$id);
            cc_check(cc_html($page['html'])===cc_html($actual['html']??''),'Rendered fragment/styles '.$id);
            cc_check(cc_html($sourceHttp[$id])===cc_html($targetHttp[$id]??''),'HTTP component DOM '.$id);
            $instanceUuid=$actual['doc']['sections'][0]['columns'][0]['elements'][0]['data']['component']??'';
            cc_check((bool)array_filter($after[$lang]['refs'],static fn($ref)=>$ref['doc_key']==='page:'.$id && $ref['component_uuid']===$instanceUuid && (int)$ref['ref_count']===1),'Imported page usage UUID/count '.$id);
        }
        cc_check($after[$lang]['pending']===[],'No pending migrations '.$lang);
        cc_check(($after[$lang]['missing_pins']['instances']??-1)===0,'No missing locked versions '.$lang);
    }
    foreach($frozen as $uuid=>$original){
        $actual=$after['zh-CN']['components'][$uuid]??[];
        cc_check(($actual['version']??0)===1 && cc_tree($actual['doc']??[])===cc_tree($before['zh-CN']['snapshots'][$original.'@1']),'Frozen v1 master and nested references '.$original);
    }
    foreach($originals as $uuid)cc_check(cc_tree($before['zh-CN']['components'][$uuid])===cc_tree($after['zh-CN']['components'][$uuid]??[]),'Latest component UUID/version/variant '.$uuid);
    cc_check(count($after['zh-CN']['components'])===6,'Exactly three latest and three frozen component masters');
    cc_check(cc_tree($before['zh-CN']['home'])===cc_tree($after['zh-CN']['home']),'Home component references preserved');
    $targetHome=cc_http('/');
    cc_check($targetHome['status']===200 && cc_html($sourceHomeFragment)===cc_html(cc_fragment($targetHome['body'])),'Home anonymous HTTP component DOM preserved');
    foreach(['libraries'=>'data','sections'=>'published_data'] as $store=>$field){
        $a=array_map(static fn($row)=>cc_tree(json_decode($row[$field],true)),$before['zh-CN'][$store]);
        $b=array_map(static fn($row)=>cc_tree(json_decode($row[$field],true)),$after['zh-CN'][$store]);
        cc_check($a===$b,'Portable component references in '.$store);
    }
    cc_check($before['zh-CN']['classes']===$after['zh-CN']['classes'],'Global class UUID/settings preserved');
    $targetStyles=cc_styles($httpBodies['target'][601],'target');
    cc_check($sourceStyles===$targetStyles,'Linked CSS bytes and inline styles preserved');
    $report['style_hashes']=['source'=>$sourceStyles,'target'=>$targetStyles];
    // Fetch real assets and component destinations, not just their paths in markup.
    foreach($targetHttp as $id=>$fragment) {
        preg_match_all('/(?:src|href)="([^"#]+)"/',$fragment,$matches);
        foreach(array_unique($matches[1]) as $url){
            if(!str_starts_with($url,'/') || str_starts_with($url,'//'))continue;
            $response=cc_http(html_entity_decode($url,ENT_QUOTES));
            cc_check($response['status']===200,'Component link/asset resolves '.$id.' '.basename((string)parse_url($url,PHP_URL_PATH)));
            if(str_contains($url,'.svg')){
                $name=basename((string)parse_url($url,PHP_URL_PATH));
                cc_check(hash('sha256',$response['body'])===hash_file('sha256',$source.'/uploads/'.$name),'Referenced SVG bytes preserved '.$id);
            }
        }
    }
    foreach([$source,$target] as $site)cc_check(!preg_match('/Fatal error|Uncaught|Warning:/',(string)file_get_contents($site.'/server.log')),'Server error log clean '.basename($site));
    $report['passed']=!array_filter($report['checks'],static fn($r)=>!$r['ok']);
    $report['source']=$before;$report['target']=$after;
} catch(Throwable $e){$report['error']=$e->getMessage();echo 'ERROR '.$e->getMessage()."\n";}
finally {
    cc_stop();
    $report['assertions']=count($report['checks']);$report['failed']=count(array_filter($report['checks'],static fn($r)=>!$r['ok']));
    if(isset($options['keep']) || !$report['passed'])$report['retained_sandbox']=$parent;else cc_cleanup($parent);
    file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(['passed'=>$report['passed'],'assertions'=>$report['assertions'],'failed'=>$report['failed'],'error'=>$report['error']??null])."\n";
}
exit($report['passed']?0:1);
