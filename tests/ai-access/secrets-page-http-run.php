<?php
/** HTTP acceptance on a disposable smoke installation only. No external model calls. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
if (!is_file($root . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Run smoke setup in an isolated worktree first');
$cfgPath = $root . '/config/config.php';
$originalConfig = $cfg = (string) file_get_contents($cfgPath);
$mode = $argv[1] ?? 'key';
if (!in_array($mode, ['key', 'no-key', 'no-cipher'], true)) throw new RuntimeException('Unknown mode');
$blocked = $mode !== 'key';
if ($mode === 'no-key') {
    $cfg = preg_replace('/^.*define\(\x27ENCRYPT_KEY\x27.*$/m', '', $cfg);
    file_put_contents($cfgPath, $cfg);
}
register_shutdown_function(static fn() => file_put_contents($cfgPath, $originalConfig));
require_once $root . '/includes/ai-access/AiAccessBootstrapConfig.php';
AiAccessBootstrapConfig::beginPreparation($root);
define('ROOT_PATH', $root);
require $root . '/includes/init.php';
require_once $root . '/includes/ai-access/AiAccessTokenStore.php';
require_once $root . '/includes/AiService.php';
require_once $root . '/includes/AiProfiles.php';
$db = db();
$set = static fn(string $k, string $v) => settingModel()->set($k, $v);
$get = static fn(string $k) => $db->fetchColumn('SELECT value FROM yikai_settings WHERE `key` = ?', [$k]);
$set('ai_access_enabled', '1');
$set('smtp_pass', 'fixture-old-smtp');
$set('smtp_host', 'old.fixture.invalid');
$set('ai_api_key', 'fixture-old-ai');
$set('seo_baidu_token', 'fixture-old-seo');
$set('seo_baidu_site', 'https://old.fixture.invalid');
$db->execute("INSERT OR REPLACE INTO yikai_plugins (slug,status,installed_at,activated_at) VALUES ('seo',1,0,0)");
AiAccessBootstrapConfig::prepare($root);
$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false); fclose($socket);
$base = 'http://' . $address;
$jar = tempnam(sys_get_temp_dir(), 'yk-review-cookie-');
$log = tempnam(sys_get_temp_dir(), 'yk-review-http-');
$command = [PHP_BINARY];
if ($mode === 'no-cipher') array_push($command, '-d', 'disable_functions=openssl_encrypt');
$server = proc_open([...$command, '-S', $address, '-t', $root], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']], $pipes, $root);
$results = []; $responses = [];
$check = static function (bool $ok, string $label) use (&$results): void {
    $results[] = ['ok'=>$ok,'label'=>$label]; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
};
$request = static function (string $path, ?array $post = null, bool $anonymous = false, ?string $bearer = null) use ($base, $jar, &$responses): array {
    $ch = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($bearer !== null) $headers = ['Accept: application/json', 'Content-Type: application/json', 'MCP-Protocol-Version: 2025-06-18', 'Authorization: Bearer ' . $bearer];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers]);
    if (!$anonymous) curl_setopt_array($ch, [CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar]);
    if ($post !== null) curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$bearer !== null ? json_encode($post) : http_build_query($post)]);
    $body = (string) curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $responses[] = $body;
    return [$status, $body, json_decode($body, true)];
};
try {
    for ($i=0;$i<60;$i++) { $s=@stream_socket_client('tcp://'.$address,$n,$e,.1); if($s){fclose($s);break;} usleep(100000); }
    [, $html] = $request('/admin/login.php');
    if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $html, $m)) preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $html, $m);
    $csrf = $m[1] ?? '';
    [$status,$loginResult] = $request('/admin/login.php', ['username'=>'admin','password'=>'smoke@Test123','_token'=>$csrf]);
    if ($status !== 302) throw new RuntimeException('Synthetic login failed HTTP ' . $status . ': ' . substr(strip_tags($loginResult),0,180));
    $check($status === 302, 'Real admin login');
    [, $html] = $request('/admin/setting_ai.php');
    preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $html, $m); $csrf = $m[1] ?? $csrf;
    $post = static fn(string $path, array $data) => $request($path, $data + ['_token'=>$csrf]);
    $ai = ['action'=>'save','profile_name'=>'HTTP fixture','ai_provider'=>'openai','ai_model'=>'fixture-model','ai_api_key'=>'fixture-new-ai'];
    $before = $get('ai_profiles');
    [,,$reply] = $post('/admin/setting_ai.php', $ai);
    $check(($reply['code'] ?? -1) === ($blocked ? 1 : 0), 'AI save returns expected JSON result');
    $check($blocked ? $get('ai_profiles') === $before : str_contains((string)$get('ai_profiles'),'yks1:'), 'AI profile refusal unchanged or encrypted');
    $profiles = AiProfiles::all();
    if ($profiles !== []) {
        $id = $profiles[0]['id']; $before = $get('ai_profiles'); $activeBefore = $get('ai_active_profile');
        [,,$reply] = $post('/admin/setting_ai.php', $ai + ['profile_id'=>$id]);
        $check(($reply['code'] ?? -1) === ($blocked ? 1 : 0) && (!$blocked || ($get('ai_profiles') === $before && $get('ai_active_profile') === $activeBefore)), 'Existing AI profile change refuses atomically or encrypts');
    }
    [,,$reply] = $post('/admin/setting_email.php', ['action'=>'save','settings'=>['smtp_pass'=>'fixture-new-smtp','smtp_host'=>'new.fixture.invalid']]);
    $check(($reply['code'] ?? -1) === ($blocked ? 1 : 0), 'SMTP save returns expected JSON result');
    $check($blocked ? $get('smtp_pass') === 'fixture-old-smtp' && $get('smtp_host') === 'old.fixture.invalid' : str_starts_with((string)$get('smtp_pass'),'yks1:') && $get('smtp_host') === 'new.fixture.invalid', 'SMTP refusal is atomic or successful save encrypted');
    [,,$reply] = $post('/admin/plugin_page.php?plugin=seo', ['action'=>'save_push','baidu_token'=>'fixture-new-seo','baidu_site'=>'https://new.fixture.invalid']);
    $check(($reply['code'] ?? -1) === ($blocked ? 1 : 0), 'SEO save returns expected JSON result');
    $check($blocked ? $get('seo_baidu_token') === 'fixture-old-seo' && $get('seo_baidu_site') === 'https://old.fixture.invalid' : str_starts_with((string)$get('seo_baidu_token'),'yks1:'), 'SEO refusal is atomic or successful save encrypted');
    $old = $get('smtp_pass');
    [,,$reply] = $post('/admin/setting_email.php', ['settings'=>['smtp_pass'=>'','smtp_host'=>'blank-keeps.fixture.invalid']]);
    $check(($reply['code'] ?? -1) === 0 && $get('smtp_pass') === $old, 'Blank password preserves existing value');
    [,,$reply] = $post('/admin/setting_email.php', ['settings'=>['smtp_pass'=>''],'smtp_pass_clear'=>'1']);
    $check(($reply['code'] ?? -1) === 0 && $get('smtp_pass') === '', 'Explicit SMTP clear works without encryption');
    $old = $get('ai_api_key');
    [,,$reply] = $post('/admin/setting_email.php', ['settings'=>['ai_api_key'=>'fixture-bypass-key','smtp_host'=>'bypass.fixture.invalid']]);
    $check(($reply['code'] ?? -1) !== 0 && $get('ai_api_key') === $old && $get('smtp_host') === 'blank-keeps.fixture.invalid', 'Email rejects foreign settings without partial writes');
    foreach (['setting.php','setting_social.php','setting_seo.php','setting_customer_service.php','setting_home.php'] as $endpoint) {
        $snapshot = $db->fetchAll('SELECT * FROM yikai_settings ORDER BY id');
        [$status,,$reply] = $post('/admin/'.$endpoint, ['settings'=>['ai_api_key'=>'fixture-bypass-key','site_name'=>'MUST NOT SAVE']]);
        $check($status === 422 && ($reply['code'] ?? 0) !== 0 && $db->fetchAll('SELECT * FROM yikai_settings ORDER BY id') === $snapshot, 'Generic settings reject secret injection atomically: '.$endpoint);
    }
    [,,$reply] = $post('/admin/setting_email.php', ['settings'=>['smtp_pass'=>['unexpected']]]);
    $check(($reply['code'] ?? -1) !== 0 && $get('smtp_pass') === '', 'Malformed array password is refused without errors');
    [,,$reply] = $post('/admin/setting_email.php', ['_save_tab'=>'register','settings'=>['mail_tpl_register_subject'=>'HTTP template fixture']]);
    $check(($reply['code'] ?? -1) === 0 && $get('mail_tpl_register_subject') === 'HTTP template fixture', 'Allowed email template still saves');
    $snapshot = $get('smtp_host');
    [,,$reply] = $request('/admin/setting_email.php', ['settings'=>['smtp_host'=>'csrf.fixture.invalid']]);
    $check($get('smtp_host') === $snapshot && ($reply['code'] ?? -1) !== 0, 'Missing CSRF cannot save');
    if ($mode === 'key') {
        $doc = json_encode(['schema'=>1,'settings'=>[], 'sections'=>[['id'=>'review-section','settings'=>[], 'columns'=>[['id'=>'review-column','settings'=>[], 'elements'=>[
            ['id'=>'review-heading','type'=>'heading','data'=>['text'=>'Published fixture title','level'=>'h2']],
            ['id'=>'review-text','type'=>'text','data'=>['html'=>'<p>Published body <a href="/page.php?id=2">Local link</a></p>']],
            ['id'=>'review-image','type'=>'image','data'=>['src'=>'/assets/icons/flags/us.svg','alt'=>'Published alt']],
        ]]]]]], JSON_UNESCAPED_SLASHES);
        $db->execute('DELETE FROM yikai_contents WHERE channel_id = 2');
        $db->insert('contents',['channel_id'=>2,'title'=>'HTTP fixture','lang'=>'zh-CN','status'=>1,'content'=>'','content_type'=>'blocks','blocks_data'=>$doc,'updated_at'=>time()]);
        $db->execute('DELETE FROM yikai_blox_page_drafts WHERE page_id=2');
        $db->insert('blox_page_drafts',['page_id'=>2,'draft_data'=>$doc,'updated_at'=>time()]);
        $published = $db->fetchOne('SELECT * FROM yikai_contents WHERE channel_id=2');
        $token = (new AiAccessTokenStore($db))->issue(1,'HTTP review',['page_read','page_content_propose','proposal_status'],1,time());
        $rpc = static function (string $name, array $args) use ($request, $token): array {
            [,,$r] = $request('/mcp/', ['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args]],true,$token['secret']);
            if (!isset($r['result'])) throw new RuntimeException('MCP call failed: ' . json_encode($r));
            return $r['result']['structuredContent'] ?? json_decode($r['result']['content'][0]['text'],true);
        };
        $read = $rpc('page_read',['id'=>2,'lang'=>'zh-CN']);
        $revision = $read['page']['base_revision'];
        $proposalArgs = ['idempotency_key'=>'http-review-'.bin2hex(random_bytes(8)),'page_id'=>2,'lang'=>'zh-CN','base_revision'=>$revision,'changes'=>[
            ['element_id'=>'review-heading','field'=>'text','value'=>'AI draft fixture title'],
            ['element_id'=>'review-text','field'=>'html','value'=>'<p>AI draft body <a href="/page.php?id=2">Local link</a></p>'],
            ['element_id'=>'review-image','field'=>'alt','value'=>'AI draft image alt']]];
        $proposal = $rpc('page_content_propose',$proposalArgs); $item = $proposal['items'][0]['item_id'];
        $check($db->fetchColumn('SELECT draft_data FROM yikai_blox_page_drafts WHERE page_id=2') === $doc, 'MCP proposes without saving page');
        $replay = $rpc('page_content_propose',$proposalArgs);
        $check($replay['proposal_id'] === $proposal['proposal_id'] && $replay['replayed'], 'MCP retry replays same proposal');
        [$status,$preview] = $request('/admin/ai_proposals.php?preview='.$item);
        $check($status === 200 && str_contains($preview,'AI draft fixture title'), 'Admin preview handoff contains proposed document');
        preg_match('/action="([^"]+)"/', $preview, $action);
        preg_match_all('/name="([^"]+)" value="([^"]*)"/', $preview, $fields, PREG_SET_ORDER); $form=[];
        foreach($fields as $field) $form[html_entity_decode($field[1])] = html_entity_decode($field[2], ENT_QUOTES | ENT_HTML5,'UTF-8');
        [$status,$render] = $request(html_entity_decode($action[1]),$form);
        $check($status === 200 && str_contains($render,'AI draft fixture title') && str_contains($render,'AI draft body') && str_contains($render,'AI draft image alt') && str_contains($render,'/page.php?id=2'), 'Actual preview renders heading body link and image alt');
        [, $anon] = $request('/admin/ai_proposals.php?preview='.$item,null,true);
        $check(!str_contains($anon,'AI draft fixture title'), 'Anonymous cannot read private preview');
        [,,$reply] = $post('/admin/ai_proposals.php',['action'=>'apply','item_ids'=>[$item]]);
        $result = $reply['data']['results'][0] ?? [];
        $check(($reply['code'] ?? -1) === 0 && ($result['status'] ?? '') === 'applied', 'Admin POST applies proposal as draft');
        $after = $rpc('page_read',['id'=>2,'lang'=>'zh-CN']);
        $check($after['page']['base_revision'] !== $revision && str_contains(json_encode($after),'AI draft fixture title'), 'MCP rereads actual saved draft and changed revision');
        [, $public] = $request('/page.php?id=2',null,true);
        $check(!str_contains($public,'AI draft fixture title') && str_contains($public,'Published fixture title'), 'Public page still renders published version');
        [,,$reply] = $post('/admin/ai_proposals.php',['action'=>'undo','log_id'=>$result['log_id']]);
        $check(($reply['code'] ?? -1) === 0 && $db->fetchColumn('SELECT draft_data FROM yikai_blox_page_drafts WHERE page_id=2') === $doc, 'Admin undo restores exact draft bytes');
        $check($db->fetchOne('SELECT * FROM yikai_contents WHERE channel_id=2') === $published, 'Published row byte-for-byte unchanged');
        [$status] = $request('/admin/ai_proposals.php?preview='.$item);
        $check($status === 409, 'Old proposal preview refuses after undo');
        if (in_array('--ui-fixture', $argv, true)) {
            $proposalArgs['idempotency_key'] = 'ui-review-' . bin2hex(random_bytes(8));
            $rpc('page_content_propose', $proposalArgs);
        }
    }
    $all = implode("\n", $responses) . (string) file_get_contents($log);
    $check(!preg_match('/Fatal error|Uncaught|Warning:/', $all), 'HTTP responses and server log have no PHP errors');
    foreach (['fixture-new-ai','fixture-new-smtp','fixture-new-seo','fixture-bypass-key'] as $secret) $check(!str_contains($all,$secret), 'Submitted synthetic secret absent from responses and server log');
} finally {
    fclose($pipes[0]); proc_terminate($server); proc_close($server);
    @unlink($jar); @unlink($log);
}
$out = array_values(array_filter($argv, static fn($v) => str_starts_with($v,'--out=')));
if ($out) file_put_contents(substr($out[0],6),json_encode(['mode'=>$mode,'php'=>PHP_VERSION,'results'=>$results],JSON_PRETTY_PRINT));
exit(count(array_filter($results,static fn($r)=>!$r['ok'])) > 0 ? 1 : 0);
