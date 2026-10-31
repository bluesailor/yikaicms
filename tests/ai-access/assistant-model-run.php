<?php
/** Explicit, opt-in paid model check on a synthetic smoke site; drafts only. */
declare(strict_types=1);
$args = getopt('', ['base:', 'out:']);
$root = dirname(__DIR__, 2);
if (!is_file($root . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Run only on an isolated smoke installation');
$base = (string) ($args['base'] ?? '');
if (!preg_match('~^http://127\.0\.0\.1:\d+$~D', $base)) throw new RuntimeException('Loopback test URL required');
$db = new PDO('sqlite:' . $root . '/storage/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cookies = [];
$request = static function (string $method, string $path, array $form = []) use ($base, &$cookies): string {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k,$v) => "$k=$v", array_keys($cookies), $cookies));
    $text = (string) file_get_contents($base . $path, false, stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>http_build_query($form),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>180]]));
    foreach ($http_response_header ?? [] as $h) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $cookies[$m[1]]=$m[2];
    return $text;
};
$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void { $checks[] = [$ok,$label]; echo ($ok?'PASS ':'FAIL ') . $label . "\n"; };
$login = $request('GET','/admin/login.php');
preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $login,$m) || preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/',$login,$m);
$request('POST','/admin/login.php',['username'=>'admin','password'=>'smoke@Test123','_token'=>$m[1]??'']);
preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $request('GET','/admin/ai_assistant.php'),$m);
$csrf=$m[1]??'';
$check($csrf!=='','authenticated smoke admin obtains CSRF token');
$channel=(int)$db->query("SELECT id FROM yikai_channels WHERE type='list' AND status=1 AND lang='zh-CN' ORDER BY id LIMIT 1")->fetchColumn();
$insert=$db->prepare("INSERT INTO yikai_contents (type,lang,channel_id,title,content,summary,tags,status,publish_time,created_at,updated_at) VALUES ('article','zh-CN',?,'S4 model staging test','<p>A synthetic editor acceptance test.</p>','Synthetic only','before-s4',0,0,?,?)");
$insert->execute([$channel,time(),time()]);
$id=(int)$db->lastInsertId();
$beforePublished=$db->query('SELECT COUNT(*) FROM yikai_contents WHERE status=1')->fetchColumn();
$response=json_decode($request('POST','/admin/api_ai_agent.php',[
    '_token'=>$csrf,'abilities'=>'cms_auto_tag_content','max_iter'=>3,
    'prompt'=>"This is a synthetic acceptance test. Call cms_auto_tag_content exactly once with id={$id} and tags=\"s4-test,local-draft\". Do not publish anything. Do not call any other tool. Then briefly report that the change is staged."
]),true);
$set=(string)($response['proposal_set_id']??'');
$check(!empty($response['success']) && $set!=='' && !empty($response['proposals']),'real model returns an actual server-side staged proposal');
$check($db->query("SELECT tags FROM yikai_contents WHERE id={$id}")->fetchColumn()==='before-s4','model dialogue alone does not change the draft');
if ($set!=='') {
    $noCsrf=json_decode($request('POST','/admin/api_ai_apply.php',['set_id'=>$set]),true);
    $check(empty($noCsrf['applied']),'applying without CSRF is refused');
    $apply=json_decode($request('POST','/admin/api_ai_apply.php',['set_id'=>$set,'_token'=>$csrf]),true);
    $log=(int)($apply['applied'][0]['log_id']??0);
    $row=$db->query("SELECT tags,status,publish_time FROM yikai_contents WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
    $check(!empty($apply['success']) && $log>0 && $row['tags']==='s4-test,local-draft' && (int)$row['status']===0 && (int)$row['publish_time']===0,'review applies the model proposal as a draft with a change log');
    $undo=json_decode($request('POST','/admin/api_ai_undo.php',['id'=>$log,'_token'=>$csrf]),true);
    $check(!empty($undo['success']) && $db->query("SELECT tags FROM yikai_contents WHERE id={$id}")->fetchColumn()==='before-s4','undo through the real endpoint restores the original draft');
} else {
    $check(false,'review and undo require an actual staged proposal');
    echo 'Model failure category: ' . (empty($response)?'invalid response':'no successful proposal') . "\n";
}
$check($db->query('SELECT COUNT(*) FROM yikai_contents WHERE status=1')->fetchColumn()===$beforePublished,'published-content count is unchanged');
$failed=count(array_filter($checks,static fn(array $c):bool=>!$c[0]));
$report=['php'=>PHP_VERSION,'assertions'=>count($checks),'failed'=>$failed,'draft_id'=>$id,'checks'=>$checks];
if(isset($args['out']))file_put_contents($args['out'],json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
exit($failed===0?0:1);
