<?php
/** Real package install, authenticated admin smoke, and published-package upgrade on an owned MySQL instance. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib/ComponentRoundtripServer.php';

$options = getopt('', ['from-package:', 'to-package:', 'from:', 'to:', 'report:', 'upgrade-only']);
foreach (['from-package', 'to-package', 'from', 'to', 'report'] as $required) {
    if (empty($options[$required])) throw new RuntimeException('Missing --' . $required);
}
$repo = dirname(__DIR__);
$fromPackage = realpath($options['from-package']);
$toPackage = realpath($options['to-package']);
if ($fromPackage === false || $toPackage === false) throw new RuntimeException('Package not found');
$reportPath = $options['report'];
if (!is_dir(dirname($reportPath))) throw new RuntimeException('Report directory must exist');
$sandbox = sys_get_temp_dir() . '/yk-mysql-runtime-' . bin2hex(random_bytes(8));
mkdir($sandbox, 0700, true);
$report = ['passed'=>false, 'php'=>PHP_VERSION, 'checks'=>[], 'from'=>$options['from'], 'to'=>$options['to'],
    'from_sha256'=>hash_file('sha256',$fromPackage), 'to_sha256'=>hash_file('sha256',$toPackage), 'upgrade_only'=>isset($options['upgrade-only'])];
$pdo = null; $identity = []; $ownedDatabases = []; $ownedSites = []; $server = null;
$dbUser = (string)getenv('YK_COMPONENT_MYSQL_USER');
$dbPass = (string)getenv('YK_COMPONENT_MYSQL_PASS');
$dbPort = (string)getenv('YK_COMPONENT_MYSQL_PORT');

function mpa_check(bool $ok, string $label): void {
    global $report;
    $report['checks'][] = ['ok'=>$ok, 'label'=>$label];
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) throw new RuntimeException($label);
}
function mpa_identity(PDO $pdo): array {
    return $pdo->query('SELECT @@datadir AS datadir, @@port AS port, @@server_uuid AS uuid, VERSION() AS version')->fetch(PDO::FETCH_ASSOC);
}
function mpa_path(string $path): string {
    $path = rtrim(str_replace('\\','/',$path),'/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
}
function mpa_owner(): void {
    global $pdo, $identity;
    if (!$pdo instanceof PDO || mpa_identity($pdo) !== $identity) throw new RuntimeException('Temporary MySQL identity changed');
}
function mpa_run(array $command, string $log): array {
    $process = proc_open($command, [0=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','r'],1=>['file',$log,'w'],2=>['redirect',1]], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot launch owned test command');
    $code = proc_close($process);
    return ['exit'=>$code, 'output'=>(string)file_get_contents($log)];
}
function mpa_http(string $base, string $path, ?array $post = null, string $jar = ''): array {
    $ch = curl_init($base.$path);
    $settings = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>90,CURLOPT_PROXY=>'',
        CURLOPT_HTTPHEADER=>['X-Requested-With: XMLHttpRequest']];
    if ($jar !== '') $settings += [CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar];
    if ($post !== null) $settings += [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)];
    curl_setopt_array($ch,$settings);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    if ($body === false) throw new RuntimeException('HTTP failed: '.curl_error($ch));
    return ['status'=>$status,'body'=>$body];
}
function mpa_port(): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if (!$socket) throw new RuntimeException('Cannot reserve loopback port');
    $address = (string)stream_socket_get_name($socket,false); fclose($socket);
    return (int)substr(strrchr($address,':'),1);
}
function mpa_site_identity(string $site): array {
    $probe = $site.'/acceptance-identity.php';
    file_put_contents($probe, '<?php if(PHP_SAPI!=="cli")exit; define("ROOT_PATH",__DIR__); require __DIR__."/config/config.php"; echo json_encode(["driver"=>DB_DRIVER,"host"=>DB_HOST,"port"=>DB_PORT,"name"=>DB_NAME,"prefix"=>DB_PREFIX]);');
    try { $result = mpa_run([PHP_BINARY,$probe],$site.'/identity.log'); }
    finally { unlink($probe); }
    $data = json_decode(trim($result['output']),true);
    if ($result['exit'] !== 0 || !is_array($data)) throw new RuntimeException('Cannot read installed database identity');
    return $data;
}
function mpa_draft_crud(string $site, string $base, string $database, string $logPrefix): void {
    global $pdo;
    $jar = $site.'/draft-crud-cookies.txt';
    $login = mpa_http($base,'/admin/login.php',null,$jar);
    preg_match('/name="(?:csrf-token|_token)"[^>]*(?:content|value)="([a-f0-9]+)"/',$login['body'],$match);
    $login = mpa_http($base,'/admin/login.php',['username'=>'admin','password'=>'smoke@Test123','_token'=>$match[1]??''],$jar);
    mpa_check($login['status']===302,$logPrefix.' draft CRUD login');
    $form = mpa_http($base,'/admin/article_edit.php',null,$jar);
    preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/',$form['body'],$match);
    $token = $match[1]??'';
    $mark = 'MYSQL_RUNTIME_'.bin2hex(random_bytes(6));
    $data = ['title'=>$mark,'slug'=>strtolower($mark),'summary'=>'摘要 🧪','content'=>'<p>正文 🧪</p>',
        'seo_title'=>'SEO '.$mark,'seo_keywords'=>'测试,SQL','seo_description'=>'SEO 摘要','status'=>0,'_token'=>$token];
    $saved = mpa_http($base,'/admin/article_edit.php',$data,$jar);
    $json = json_decode($saved['body'],true); $id = (int)($json['data']['id']??0);
    mpa_check($saved['status']===200 && ($json['code']??-1)===0 && $id>0,$logPrefix.' HTTP create article draft');
    $query = $pdo->prepare('SELECT title,summary,content,seo_title,seo_keywords,seo_description,status,deleted_at FROM `'.$database.'`.yikai_contents WHERE id=?');
    $query->execute([$id]); $row = $query->fetch(PDO::FETCH_ASSOC);
    mpa_check(is_array($row) && (int)$row['status']===0 && $row['title']===$mark && $row['summary']===$data['summary']
        && $row['content']===$data['content'] && $row['seo_title']===$data['seo_title'] && $row['seo_keywords']===$data['seo_keywords']
        && $row['seo_description']===$data['seo_description'],$logPrefix.' draft title/body/SEO and utf8mb4 read back');
    $edit = mpa_http($base,'/admin/article_edit.php?id='.$id,null,$jar);
    mpa_check($edit['status']===200 && str_contains($edit['body'],$mark),$logPrefix.' HTTP read article draft');
    $data['id']=$id; $data['title']=$mark.'_EDIT'; $data['summary']='修改摘要 🧪';
    $updated = mpa_http($base,'/admin/article_edit.php?id='.$id,$data,$jar);
    $json = json_decode($updated['body'],true); $query->execute([$id]); $row=$query->fetch(PDO::FETCH_ASSOC);
    mpa_check($updated['status']===200 && ($json['code']??-1)===0 && $row['title']===$data['title']
        && $row['summary']===$data['summary'] && (int)$row['status']===0,$logPrefix.' HTTP update remains draft');
    $deleted = mpa_http($base,'/admin/article.php',['action'=>'delete','id'=>$id,'_token'=>$token],$jar);
    $json = json_decode($deleted['body'],true); $query->execute([$id]); $row=$query->fetch(PDO::FETCH_ASSOC);
    mpa_check($deleted['status']===200 && ($json['code']??-1)===0 && is_array($row) && !empty($row['deleted_at'])
        && (int)$row['status']===0,$logPrefix.' HTTP delete moves draft to recycle bin');
}
function mpa_admin(string $site, string $database, string $label, ?string $existingBase = null): void {
    global $repo, $report, $server, $dbPort, $reportPath;
    $config = mpa_site_identity($site);
    mpa_check($config===['driver'=>'mysql','host'=>'127.0.0.1','port'=>(string)$dbPort,'name'=>$database,'prefix'=>'yikai_']
        || ($config['driver']==='mysql' && $config['host']==='127.0.0.1' && (int)$config['port']===(int)$dbPort
            && $config['name']===$database && $config['prefix']==='yikai_'),$label.' installed config targets owned database');
    $base=$existingBase;
    if ($base===null) {
        $port=mpa_port(); $base='http://127.0.0.1:'.$port;
        $server = new ComponentRoundtripServer('builtin',$port,'','',$repo);
        $report[$label]['web']=$server->start($site);
    }
    try {
        // Copy only smoke clients into this temporary site, so fixtures.json and
        // dirname-based bootstraps cannot read or modify the source/dev checkout.
        mkdir($site.'/tests/smoke',0700,true);
        foreach (['mysql_runtime.php','admin_crud.php','admin_pages.php'] as $name) copy($repo.'/tests/smoke/'.$name,$site.'/tests/smoke/'.$name);
        $logDir=dirname($reportPath);
        foreach (['mysql_runtime','admin_crud','admin_pages'] as $script) {
            $run=mpa_run([PHP_BINARY,$site.'/tests/smoke/'.$script.'.php','--root='.$site,'--base='.$base],$logDir.'/'.$label.'-'.$script.'.txt');
            mpa_check($run['exit']===0,$label.' '.$script.' passed');
            $report[$label][$script]=['exit'=>$run['exit'],'summary'=>array_values(array_filter(explode("\n",trim($run['output']))))];
        }
        mpa_draft_crud($site,$base,$database,$label);
        mpa_check(preg_match('/Fatal error|Uncaught|Warning:/',$server->logText())!==1,$label.' PHP server has no errors or warnings');
    } finally { $server->stop(); $server=null; }
}
function mpa_remove(string $path, string $root): void {
    $resolved = realpath($path); $boundary = realpath($root);
    if ($resolved===false || $boundary===false || (mpa_path($resolved)!==mpa_path($boundary)
        && !str_starts_with(mpa_path($resolved),mpa_path($boundary).'/')) || is_link($path)) throw new RuntimeException('Unsafe test directory cleanup');
    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot()) continue;
        if ($file->isLink()) throw new RuntimeException('Refusing cleanup through a link');
        if ($file->isDir()) mpa_remove($file->getPathname(),$root); else unlink($file->getPathname());
    }
    rmdir($path);
}

try {
    $expectedDir=(string)getenv('YK_COMPONENT_MYSQL_DATADIR'); $family=(string)getenv('YK_COMPONENT_MYSQL_VERSION');
    if (!ctype_digit($dbPort) || (int)$dbPort<1024 || $expectedDir==='' || $dbUser==='') throw new RuntimeException('Explicit temporary MySQL identity required');
    $pdo=new PDO('mysql:host=127.0.0.1;port='.$dbPort.';charset=utf8mb4',$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $identity=mpa_identity($pdo);
    mpa_check(mpa_path($identity['datadir'])===mpa_path($expectedDir) && (int)$identity['port']===(int)$dbPort
        && in_array($family,['5.7','8.0','8.4'],true) && str_starts_with($identity['version'],$family.'.'),'Temporary MySQL identity verified before mutation');
    $report['mysql']=$identity;
    putenv('EXPECTED_MYSQL_SERIES='.$family);
    $database='';
    if (!isset($options['upgrade-only'])) {
    $fresh=$sandbox.'/fresh'; mkdir($fresh,0700,true); $ownedSites[]=$fresh;
    $zip=new ZipArchive();
    if ($zip->open($toPackage)!==true || !$zip->extractTo($fresh)) throw new RuntimeException('Cannot extract candidate package');
    $zip->close();
    if (!is_file($fresh.'/index.php')) {
        $children=array_values(array_filter(glob($fresh.'/*')?:[],static fn($path)=>is_dir($path) && is_file($path.'/index.php')));
        if (count($children)!==1) throw new RuntimeException('Expected one package site root');
        $fresh=$children[0];
    }
    $database='yk_cmp_rt_'.bin2hex(random_bytes(10)).'_source';
    mpa_owner(); $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'); $ownedDatabases[]=$database;
    $port=mpa_port(); $base='http://127.0.0.1:'.$port;
    $server=new ComponentRoundtripServer('builtin',$port,'','',$repo); $report['fresh']['web']=$server->start($fresh);
    $installed=mpa_http($base,'/install/index.php',['action'=>'install','db_driver'=>'mysql','db_host'=>'127.0.0.1','db_port'=>$dbPort,
        'db_name'=>$database,'db_user'=>$dbUser,'db_pass'=>$dbPass,'db_prefix'=>'yikai_','admin_user'=>'admin','admin_pass'=>'smoke@Test123',
        'admin_email'=>'mysql-runtime@example.test','site_name'=>'Isolated MySQL package acceptance','site_url'=>$base,
        'site_lang'=>'zh-CN','admin_lang'=>'zh-CN','install_demo'=>'1']);
    $json=json_decode($installed['body'],true);
    mpa_check($installed['status']===200 && !empty($json['success']),'Candidate ZIP installs through real HTTP installer');
    mpa_admin($fresh,$database,'fresh',$base);
    }

    mpa_owner();
    $before=$pdo->query('SELECT schema_name FROM information_schema.schemata')->fetchAll(PDO::FETCH_COLUMN);
    putenv('YK_TEST_DB_PASS='.$dbPass);
    $upgrade=mpa_run([PHP_BINARY,$repo.'/tests/smoke/package_upgrade.php','--from-package='.$fromPackage,'--to-package='.$toPackage,
        '--from='.$options['from'],'--to='.$options['to'],'--db=mysql','--db-host=127.0.0.1','--db-port='.$dbPort,'--db-user='.$dbUser,'--keep','--verify-preservation'],dirname($reportPath).'/package-upgrade.txt');
    mpa_owner();
    $after=$pdo->query('SELECT schema_name FROM information_schema.schemata')->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_diff($after,$before) as $name) {
        if (!preg_match('/^yikaicms_upgrade_test_[a-f0-9]{8}$/D',$name)) throw new RuntimeException('Unexpected database created by upgrade gate');
        $ownedDatabases[]=$name;
    }
    if (preg_match('/Kept test site: ([^\r\n]+)/',$upgrade['output'],$match)) {
        $upgradedBase=realpath(trim($match[1]));
        if ($upgradedBase===false || mpa_path(dirname($upgradedBase))!==mpa_path(sys_get_temp_dir())
            || !preg_match('/^yikaicms-nminus1-[a-f0-9]{10}$/D',basename($upgradedBase))) throw new RuntimeException('Unsafe retained upgrade path');
        $ownedSites[]=$upgradedBase;
    }
    mpa_check($upgrade['exit']===0,'Published package real prepare/batch/finalize/migrate gate passed');
    mpa_check(isset($upgradedBase) && count(array_diff($after,$before))===1,'Upgrade created exactly one owned database and retained site');
    $siteCandidates=glob($upgradedBase.'/site/*/index.php')?:[];
    $upgradedSite=is_file($upgradedBase.'/site/index.php')?$upgradedBase.'/site':(count($siteCandidates)===1?dirname($siteCandidates[0]):'');
    if ($upgradedSite==='') throw new RuntimeException('Cannot locate upgraded package site');
    $config=mpa_site_identity($upgradedSite);
    mpa_check(in_array($config['name'],$ownedDatabases,true) && $config['name']!==$database,'Upgraded config points to the newly created upgrade database');
    $report['upgrade_checks']=substr_count($upgrade['output'],'  OK  ');
    mpa_admin($upgradedSite,$config['name'],'upgraded');
} catch (Throwable $e) {
    $report['error']=$e->getMessage(); echo 'ERROR '.$e->getMessage()."\n";
} finally {
    if ($server instanceof ComponentRoundtripServer) $server->stop();
    try {
        foreach ($ownedDatabases as $database) {
            mpa_owner();
            if (!preg_match('/^(?:yk_cmp_rt_[a-f0-9]{20}_source|yikaicms_upgrade_test_[a-f0-9]{8})$/D',$database)) throw new RuntimeException('Unsafe database cleanup name');
            $pdo->exec('DROP DATABASE `'.$database.'`');
            $check=$pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?'); $check->execute([$database]);
            mpa_check((int)$check->fetchColumn()===0,'Owned temporary database removed: '.$database);
        }
        $report['database_cleanup']=['created'=>$ownedDatabases,'remaining'=>[]];
    } catch (Throwable $e) { $report['error']='Cleanup failed: '.$e->getMessage(); }
    $report['passed']=!isset($report['error']);
    if ($report['passed']) {
        try {
            foreach ($ownedSites as $site) if (is_dir($site)) mpa_remove($site,$site);
            if (is_dir($sandbox)) mpa_remove($sandbox,$sandbox);
            $report['owned_sites_removed']=true;
        } catch (Throwable $e) { $report['passed']=false; $report['error']=$e->getMessage(); }
    }
    if (!$report['passed']) $report['retained_sandbox']=$sandbox;
    $report['assertions']=count($report['checks']); $report['failed']=count(array_filter($report['checks'],static fn($r)=>!$r['ok']));
    file_put_contents($reportPath,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(['passed'=>$report['passed'],'assertions'=>$report['assertions'],'failed'=>$report['failed'],'error'=>$report['error']??null])."\n";
}
exit($report['passed']?0:1);
