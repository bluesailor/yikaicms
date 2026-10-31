<?php
// S4 补充：真实模型下的 SEO 摘要能力——对话阶段生成并暂存固定摘要、不写文章；应用写入同一摘要、不再调用模型；
// 撤销恢复；正文改动后旧暂存拒绝应用。只用于隔离 smoke 站，内容始终是草稿。
// php tests/ai-access/assistant-seo-model-run.php --base=http://127.0.0.1:<port> [--out=<report.json>]（显式付费调用，先在隔离站配置测试 API）
declare(strict_types=1);
$args = getopt('', ['base:', 'out:']);
$root = dirname(__DIR__, 2);
$base = (string) ($args['base'] ?? '');
if (!is_file($root . '/storage/.smoke-state-backup/manifest.json')) exit("not a smoke site\n");
if (!preg_match('~^http://127\.0\.0\.1:\d+$~D', $base)) exit("loopback only\n");
$db = new PDO('sqlite:' . $root . '/storage/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$cookies = [];
$request = static function (string $method, string $path, array $form = []) use ($base, &$cookies): string {
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($cookies) $headers[] = 'Cookie: ' . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($cookies), $cookies));
    $text = (string) file_get_contents($base . $path, false, stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => http_build_query($form), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 180]]));
    foreach ($http_response_header ?? [] as $h) if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m)) $cookies[$m[1]] = $m[2];
    return $text;
};
$checks = [];
$check = static function (bool $ok, string $label) use (&$checks): void { $checks[] = [$ok, $label]; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; };
$login = $request('GET', '/admin/login.php');
preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $login, $m) || preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $login, $m);
$request('POST', '/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $m[1] ?? '']);
preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $request('GET', '/admin/ai_assistant.php'), $m);
$csrf = $m[1] ?? '';
$check($csrf !== '', 'logged in to the smoke admin');

$channel = (int) $db->query("SELECT id FROM yikai_channels WHERE type='list' AND status=1 AND lang='zh-CN' ORDER BY id LIMIT 1")->fetchColumn();
$insert = $db->prepare("INSERT INTO yikai_contents (type,lang,channel_id,title,content,summary,tags,status,publish_time,created_at,updated_at) VALUES ('article','zh-CN',?,?,?,'原摘要','',0,0,?,?)");
$stage = static function (int $id) use ($request, $csrf): array {
    $r = json_decode($request('POST', '/admin/api_ai_agent.php', ['_token' => $csrf, 'abilities' => 'cms_generate_seo_summary', 'max_iter' => 3,
        'prompt' => "这是自动化验收测试。请只调用一次 cms_generate_seo_summary，参数 id={$id}，不要调用其他工具，不要发布任何内容。然后简短说明改动已暂存待审核。"]), true);
    return is_array($r) ? $r : [];
};
$body = '<p>易开CMS 是一套面向外贸企业的多语言建站系统，支持产品目录、询盘表单、伪静态与子目录部署。</p><p>本文用于自动化验收，内容为合成数据。</p>';
$insert->execute([$channel, 'S4 SEO 摘要验收', $body, time(), time()]);
$id = (int) $db->lastInsertId();
$published = $db->query('SELECT COUNT(*) FROM yikai_contents WHERE status=1')->fetchColumn();

$r = $stage($id);
$set = (string) ($r['proposal_set_id'] ?? '');
$p = $r['proposals'][0] ?? [];
$staged = (string) ($p['preview']['after'] ?? $p['after'] ?? '');
$check(!empty($r['success']) && $set !== '' && ($p['ability'] ?? '') === 'cms_generate_seo_summary', 'real model staged an SEO summary proposal');
$check($staged !== '' && $staged !== '原摘要', 'the staged proposal carries the generated summary (' . mb_strlen($staged) . ' chars)');
$check($db->query("SELECT summary FROM yikai_contents WHERE id={$id}")->fetchColumn() === '原摘要', 'the dialogue alone did not change the article');

if ($set !== '') {
    $apply = json_decode($request('POST', '/admin/api_ai_apply.php', ['set_id' => $set, '_token' => $csrf]), true);
    $log = (int) ($apply['applied'][0]['log_id'] ?? 0);
    $row = $db->query("SELECT summary,status FROM yikai_contents WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
    $check(!empty($apply['success']) && $log > 0 && $row['summary'] === $staged && (int) $row['status'] === 0, 'apply writes exactly the staged summary and the article stays a draft');
    $undo = json_decode($request('POST', '/admin/api_ai_undo.php', ['id' => $log, '_token' => $csrf]), true);
    $check(!empty($undo['success']) && $db->query("SELECT summary FROM yikai_contents WHERE id={$id}")->fetchColumn() === '原摘要', 'undo restores the original summary');
}

$r2 = $stage($id);
$set2 = (string) ($r2['proposal_set_id'] ?? '');
$check($set2 !== '', 'second staging for the stale-source check');
if ($set2 !== '') {
    $db->prepare('UPDATE yikai_contents SET content = ?, updated_at = updated_at + 5 WHERE id = ?')->execute([$body . '<p>人工补充一段。</p>', $id]);
    $stale = json_decode($request('POST', '/admin/api_ai_apply.php', ['set_id' => $set2, '_token' => $csrf]), true);
    $check(empty($stale['success']) && empty($stale['applied']) && $db->query("SELECT summary FROM yikai_contents WHERE id={$id}")->fetchColumn() === '原摘要',
        'after a human edit to the body the stale proposal is refused and nothing is written');
}
$check($db->query('SELECT COUNT(*) FROM yikai_contents WHERE status=1')->fetchColumn() === $published, 'published-content count is unchanged');

$failed = count(array_filter($checks, static fn(array $c): bool => !$c[0]));
echo 'checks=' . count($checks) . ' failed=' . $failed . "\n";
if (isset($args['out'])) file_put_contents((string) $args['out'], json_encode(['php' => PHP_VERSION, 'assertions' => count($checks), 'failed' => $failed, 'draft_id' => $id, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($failed === 0 ? 0 : 1);
