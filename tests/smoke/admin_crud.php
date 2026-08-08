<?php
/**
 * 后台 CRUD 冒烟测试客户端。
 * 对着本地 php -S 服务器：登录 → 逐类 POST「新建」→ 断言无致命错误、保存成功。
 * 专抓 job_edit 的 getAdminId() 那类「控制器层未定义函数 / Fatal」类回归。
 *
 * 用法：先 `php tests/smoke/setup.php` 装机 + 起 `php -S 127.0.0.1:8080`，再跑本脚本。
 * 任一类失败 → 退出码 1（CI 变红）。
 */
declare(strict_types=1);

$BASE = getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8080';
$JAR  = sys_get_temp_dir() . '/smoke_cookies_' . getmypid() . '.txt';
@unlink($JAR);
$fx = json_decode(@file_get_contents(__DIR__ . '/fixtures.json') ?: '{}', true) ?: [];

function req(string $method, string $url, array $post = null): array
{
    global $JAR, $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function csrf(string $html): string
{
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $html, $m)) return $m[1];
    return '';
}

// ---- 登录 ----
[$c, $loginPage] = req('GET', '/admin/login.php');
$token = csrf($loginPage);
if ($token === '') { fwrite(STDERR, "❌ 拿不到登录 CSRF token（HTTP {$c}）\n"); exit(2); }
[$c, $r] = req('POST', '/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $token]);
// 严判：登录成功 = 302 跳转（传统表单流）。200 = 带错误文案的登录页——把文案吐出来。
if ($c !== 302) {
    $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($r)));
    fwrite(STDERR, "❌ 登录 POST 未跳转（HTTP {$c}）。页面文本：" . mb_substr($plain, 0, 300) . "\n");
    exit(2);
}
// 二次确认：进得去仪表盘且不是登录页
[$c, $dash] = req('GET', '/admin/index.php');
if ($c !== 200 || stripos($dash, 'name="password"') !== false) {
    fwrite(STDERR, "❌ 登录后访问仪表盘失败（HTTP {$c}）\n"); exit(2);
}
echo "✓ 登录成功\n";

// 模板解析会触发远程下载与审计，必须保持 POST + CSRF；先锁定无 token 必须被拒。
[$csrfCode, $csrfBody] = req('POST', '/admin/blox_template_api.php', [
    'action' => 'get',
    'context' => 'page',
    'key' => 'remote:pricing-3col',
]);
$csrfJson = json_decode($csrfBody, true);
if ($csrfCode !== 200 || !is_array($csrfJson) || (int) ($csrfJson['code'] ?? 0) !== 403) {
    fwrite(STDERR, "❌ Blox 模板解析端点未拒绝无 CSRF token 的 POST\n");
    exit(2);
}
echo "✓ Blox 模板解析 CSRF 拒绝行为正常\n";

$chId  = $fx['channel_list'] ?: ($fx['channel_any'] ?: 1);
$pcId  = $fx['product_cat'] ?: 0;
$dcId  = $fx['download_cat'] ?: 0;

// 每类：[名称, 编辑页, 端点, POST 数据]
$cases = [
    ['栏目',   '/admin/channel.php',       '/admin/channel.php',       ['action' => 'save', 'name' => '冒烟栏目', 'slug' => 'smoke-ch-' . mt_rand(1000, 9999), 'type' => 'list', 'parent_id' => 0]],
    ['产品',   '/admin/product_edit.php',  '/admin/product_edit.php',  ['title' => '冒烟产品', 'category_id' => $pcId, 'status' => 1]],
    ['内容',   '/admin/content_edit.php?channel_id=' . $chId, '/admin/content_edit.php', ['title' => '冒烟内容', 'channel_id' => $chId, 'status' => 1]],
    ['文章',   '/admin/article_edit.php',  '/admin/article_edit.php',  ['title' => '冒烟文章', 'channel_id' => $chId, 'status' => 1]],
    ['下载',   '/admin/download_edit.php', '/admin/download_edit.php', ['title' => '冒烟下载', 'category_id' => $dcId, 'status' => 1]],
    ['招聘',   '/admin/job_edit.php',      '/admin/job_edit.php',      ['title' => '冒烟职位', 'content' => '测试', 'location' => '上海', 'status' => 1]],
];

$fails = [];
foreach ($cases as [$name, $editUrl, $postUrl, $data]) {
    [$gc, $form] = req('GET', $editUrl);          // 取该页 CSRF
    $data['_token'] = csrf($form) ?: csrf($dash); // 会话级 token，复用亦可
    [$code, $body] = req('POST', $postUrl, $data);

    $fatal = preg_match('/Fatal error|Uncaught|Parse error|Call to undefined (function|method)/i', $body, $mm);
    $okCode = in_array($code, [200, 302], true);
    // JSON 成功判定（success() 返回 code:0；channel.php 可能重定向）
    $json = json_decode($body, true);
    $jsonOk = !is_array($json) || (isset($json['code']) ? (int) $json['code'] === 0 : true);

    if ($fatal || !$okCode || !$jsonOk) {
        $why = $fatal ? ('致命错误: ' . trim($mm[0])) : (!$okCode ? "HTTP $code" : ('返回: ' . mb_substr(strip_tags($body), 0, 120)));
        $fails[] = "$name → $why";
        echo "✗ 新建$name 失败：$why\n";
    } else {
        echo "✓ 新建$name OK" . (is_array($json) && isset($json['data']['id']) ? "（id={$json['data']['id']}）" : '') . "\n";
    }
}

// ---- r17：画布另存区块模板（save_section）行为验证（审计要求行为测试而非源码 grep）----
// 依赖 blox 开关：fixture 未开则跳过段（不判失败——开关是产品闸）
$secDoc = json_encode(['type' => 'section', 'settings' => [], 'columns' => [[
    'elements' => [['type' => 'heading', 'data' => ['text' => '行为测试区块', 'level' => 'h2']]],
]]], JSON_UNESCAPED_UNICODE);
[$c, $body] = req('POST', '/admin/blox_template_api.php', [
    'action' => 'save_section', 'name' => '行为测试模板', 'section' => $secDoc, '_token' => $token,
]);
$j = json_decode($body, true);
if (is_array($j) && (int) ($j['code'] ?? 1) === 0 && (int) ($j['data']['id'] ?? 0) > 0) {
    $tid = (int) $j['data']['id'];
    $row = null;
    // 直接查库验证：单 section、已发布、ID 被管线重建（tpl_ 前缀）
    $pdo = new PDO('sqlite:' . __DIR__ . '/../../storage/database.sqlite');
    $st = $pdo->query("SELECT type, status, draft_data FROM yikai_blox_templates WHERE id = $tid");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    $doc = is_array($row) ? json_decode((string) $row['draft_data'], true) : null;
    $okType = is_array($row) && $row['type'] === 'section' && (int) $row['status'] === 1;
    $okDoc = is_array($doc) && count($doc['sections'] ?? []) === 1
        && str_starts_with((string) ($doc['sections'][0]['id'] ?? ''), 'tpl');
    // 危险元素拒绝：code 元素不进注册表白名单外？code 是注册元素——用未知类型验证拒绝
    [$c2, $body2] = req('POST', '/admin/blox_template_api.php', [
        'action' => 'save_section', 'name' => 'x',
        'section' => json_encode(['type' => 'section', 'settings' => [], 'columns' => [[
            'elements' => [['type' => 'totally-unknown-element', 'data' => []]],
        ]]]),
        '_token' => $token,
    ]);
    $j2 = json_decode($body2, true);
    $okReject = is_array($j2) && (int) ($j2['code'] ?? 0) !== 0;
    $pdo->exec("DELETE FROM yikai_blox_templates WHERE id = $tid");
    if ($okType && $okDoc && $okReject) {
        echo "✓ save_section 行为（发布/单区块/ID 重建/未知元素拒绝）OK
";
    } else {
        $fails[] = 'save_section 行为断言失败 (type=' . var_export($okType, true)
            . ' doc=' . var_export($okDoc, true) . ' reject=' . var_export($okReject, true) . ')';
        echo "✗ save_section 行为断言失败
";
    }
} elseif (is_array($j) && str_contains((string) ($j['msg'] ?? $j['message'] ?? ''), '未启用')) {
    echo "· save_section 跳过（blox 开关未开）
";
} else {
    $fails[] = 'save_section 请求失败: ' . mb_substr(strip_tags((string) $body), 0, 120);
    echo "✗ save_section 请求失败
";
}

@unlink($JAR);
if ($fails) {
    fwrite(STDERR, "\n❌ 冒烟测试失败 " . count($fails) . " 项：\n  - " . implode("\n  - ", $fails) . "\n");
    exit(1);
}
echo "\n✅ 后台 CRUD 冒烟测试全部通过（6 类新建）\n";
