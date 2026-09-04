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

/** @return ?string */
function smokeOption(string $name): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (is_string($argument) && str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
}

$BASE = smokeOption('base') ?: (getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8080');
$SMOKE_ROOT = smokeOption('root') ?: (getenv('SMOKE_SITE_ROOT') ?: dirname(__DIR__, 2));
$JAR  = sys_get_temp_dir() . '/smoke_cookies_' . getmypid() . '.txt';
@unlink($JAR);
$fx = json_decode(@file_get_contents(__DIR__ . '/fixtures.json') ?: '{}', true) ?: [];

/**
 * Direct database checks must follow the installed site's driver. Keeping a
 * hard-coded SQLite connection here made the HTTP smoke silently verify a
 * different database when the site itself ran on MySQL.
 */
function smokeDatabase(string $root): PDO
{
    $root = rtrim($root, '/\\');
    if (!is_file($root . '/config/config.php')) {
        throw new RuntimeException("Smoke target has no installed config: {$root}");
    }
    if (!defined('ROOT_PATH')) {
        define('ROOT_PATH', $root);
    }
    require_once $root . '/config/config.php';

    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_PATH);
    } else {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_EMULATE_PREPARES => false]
        );
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

$directDb = smokeDatabase($SMOKE_ROOT);

function smokeArticleChannel(PDO $pdo): int
{
    $table = DB_PREFIX . 'channels';
    $settings = DB_PREFIX . 'settings';
    $siteLang = (string) ($pdo->query(
        "SELECT value FROM {$settings} WHERE `key` = 'site_lang' LIMIT 1"
    )->fetchColumn() ?: 'zh-CN');
    $sourceGroup = (int) ($pdo->query(
        "SELECT translation_group_id FROM {$table} WHERE slug = 'news' LIMIT 1"
    )->fetchColumn() ?: 0);
    if ($sourceGroup <= 0) {
        return 0;
    }

    $rootStatement = $pdo->prepare(
        "SELECT id FROM {$table} WHERE translation_group_id = ? AND lang = ? AND parent_id = 0 LIMIT 1"
    );
    $rootStatement->execute([$sourceGroup, $siteLang]);
    $rootId = (int) ($rootStatement->fetchColumn() ?: 0);
    if ($rootId <= 0) {
        return 0;
    }

    $childStatement = $pdo->prepare(
        "SELECT id FROM {$table} WHERE parent_id = ? AND lang = ? AND status = 1 ORDER BY sort_order, id LIMIT 1"
    );
    $childStatement->execute([$rootId, $siteLang]);
    return (int) ($childStatement->fetchColumn() ?: $rootId);
}

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
if ($csrfCode !== 403 || !is_array($csrfJson) || (int) ($csrfJson['code'] ?? 0) !== 403) {
    fwrite(STDERR, "❌ Blox 模板解析端点未拒绝无 CSRF token 的 POST\n");
    exit(2);
}
echo "✓ Blox 模板解析 CSRF 拒绝行为正常\n";

$chId  = (int) (($fx['channel_list'] ?? 0) ?: (($fx['channel_any'] ?? 0) ?: 1));
$articleChId = (int) (($fx['article_channel'] ?? 0) ?: smokeArticleChannel($directDb));
$pcId  = (int) ($fx['product_cat'] ?? 0);
$dcId  = (int) ($fx['download_cat'] ?? 0);

// 唯一标记：建完要回列表页找它——只判「保存返回成功」会漏掉「存进去了但
// 列表查不到」这类 bug（新建行 lang 落错桶，客户英文站实测：分类/友链/时间线
// 三处全中，而保存接口一路 code:0）。
$mark = 'SMK' . mt_rand(100000, 999999);

// 每类：[名称, 编辑页, 端点, POST 数据, 列表页(空=跳过可见性回读), 期望在列表出现的文本]
$cases = [
    ['栏目',     '/admin/channel.php',       '/admin/channel.php',       ['action' => 'save', 'name' => '冒烟栏目' . $mark, 'slug' => 'smoke-ch-' . mt_rand(1000, 9999), 'type' => 'list', 'parent_id' => 0], '/admin/channel.php', '冒烟栏目' . $mark],
    ['产品',     '/admin/product_edit.php',  '/admin/product_edit.php',  ['title' => '冒烟产品' . $mark, 'category_id' => $pcId, 'status' => 1], '/admin/product.php', '冒烟产品' . $mark],
    ['内容',     '/admin/content_edit.php?channel_id=' . $chId, '/admin/content_edit.php', ['title' => '冒烟内容' . $mark, 'channel_id' => $chId, 'status' => 1], '', ''],
    ['文章',     '/admin/article_edit.php',  '/admin/article_edit.php',  ['title' => '冒烟文章' . $mark, 'channel_id' => $articleChId, 'status' => 1], '/admin/article.php', '冒烟文章' . $mark],
    ['下载',     '/admin/download_edit.php', '/admin/download_edit.php', ['title' => '冒烟下载' . $mark, 'category_id' => $dcId, 'status' => 1], '/admin/download.php', '冒烟下载' . $mark],
    ['招聘',     '/admin/job_edit.php',      '/admin/job_edit.php',      ['title' => '冒烟职位' . $mark, 'content' => '测试', 'location' => '上海', 'status' => 1], '/admin/job.php', '冒烟职位' . $mark],
    // ── 以下四类原先完全不在冒烟清单里，正是 lang bug 的藏身处 ──
    ['产品分类', '/admin/product_category.php', '/admin/product_category.php', ['action' => 'save', 'name' => '冒烟分类' . $mark, 'slug' => 'smoke-pc-' . mt_rand(1000, 9999), 'status' => 1], '/admin/product_category.php', '冒烟分类' . $mark],
    ['产品标签', '/admin/product_tag.php',   '/admin/product_tag.php',   ['action' => 'save', 'name' => '冒烟标签' . $mark, 'group_name' => '冒烟组' . $mark], '/admin/product_tag.php', '冒烟标签' . $mark],
    ['友情链接', '/admin/link.php',          '/admin/link.php',          ['action' => 'save', 'name' => '冒烟友链' . $mark, 'url' => 'https://example.com/' . $mark, 'status' => 1], '/admin/link.php', '冒烟友链' . $mark],
    ['时间线',   '/admin/timeline.php',      '/admin/timeline.php',      ['action' => 'save', 'year' => '2026', 'title' => '冒烟时间线' . $mark, 'content' => '测试', 'status' => 1], '/admin/timeline.php', '冒烟时间线' . $mark],
];

$fails = [];
$createdChannelId = 0;
foreach ($cases as [$name, $editUrl, $postUrl, $data, $listUrl, $expectText]) {
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
        if ($name === '栏目' && is_array($json)) {
            $createdChannelId = (int) ($json['data']['id'] ?? 0);
        }
        // 可见性回读：保存成功还不够，得能在列表页找到它
        $seen = '';
        if ($listUrl !== '' && $expectText !== '') {
            [$lc, $lbody] = req('GET', $listUrl);
            if ($lc !== 200) {
                $fails[] = "$name → 列表页 HTTP $lc";
                echo "✗ 新建$name 后列表页 HTTP $lc\n";
                continue;
            }
            if (!str_contains($lbody, $expectText)) {
                $fails[] = "$name → 保存成功但列表查不到（疑似 lang/过滤条件不匹配）";
                echo "✗ 新建$name 后在列表页找不到「{$expectText}」——存进去了但列表查不到\n";
                continue;
            }
            $seen = '，列表可见 ✓';
        }
        echo "✓ 新建$name OK" . (is_array($json) && isset($json['data']['id']) ? "（id={$json['data']['id']}）" : '') . $seen . "\n";
    }
}

// 栏目编辑表单历史上不包含 image 字段，但保存端无条件写 post('image')，会把
// 旧站已有栏目图静默清空。用真实登录态 POST 锁定“字段缺席 = 保留原值”。
if ($createdChannelId <= 0) {
    $fails[] = '无法取得新建栏目 ID，栏目图保留回归未执行';
    echo "✗ 无法取得新建栏目 ID，栏目图保留回归未执行\n";
} else {
    $pdo = $directDb;
    $imageMarker = '/uploads/smoke/channel-image-' . $mark . '.jpg';
    $setImage = $pdo->prepare('UPDATE yikai_channels SET image = ? WHERE id = ?');
    $setImage->execute([$imageMarker, $createdChannelId]);
    $row = $pdo->query('SELECT * FROM yikai_channels WHERE id = ' . $createdChannelId)->fetch(PDO::FETCH_ASSOC);

    [$gc, $form] = req('GET', '/admin/channel.php?edit=' . $createdChannelId);
    $editData = [
        'action' => 'save',
        'id' => $createdChannelId,
        'parent_id' => (int) ($row['parent_id'] ?? 0),
        'name' => (string) ($row['name'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'type' => (string) ($row['type'] ?? 'list'),
        'album_id' => (int) ($row['album_id'] ?? 0),
        'icon' => (string) ($row['icon'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'content' => (string) ($row['content'] ?? ''),
        'link_url' => (string) ($row['link_url'] ?? ''),
        'link_target' => (string) ($row['link_target'] ?? '_self'),
        'redirect_type' => (string) ($row['redirect_type'] ?? 'auto'),
        'redirect_url' => (string) ($row['redirect_url'] ?? ''),
        'seo_title' => (string) ($row['seo_title'] ?? ''),
        'seo_keywords' => (string) ($row['seo_keywords'] ?? ''),
        'seo_description' => (string) ($row['seo_description'] ?? ''),
        'is_nav' => (int) ($row['is_nav'] ?? 0),
        'is_home' => (int) ($row['is_home'] ?? 0),
        'status' => (int) ($row['status'] ?? 1),
        'sort_order' => (int) ($row['sort_order'] ?? 0),
        'hero_bg' => (string) ($row['hero_bg'] ?? ''),
        '_token' => csrf($form) ?: $token,
    ];
    if ((int) ($row['show_hero'] ?? 0) === 1) {
        $editData['show_hero'] = 1;
    }

    [$code, $body] = req('POST', '/admin/channel.php', $editData);
    $json = json_decode($body, true);
    $savedImage = $pdo->query('SELECT image FROM yikai_channels WHERE id = ' . $createdChannelId)->fetchColumn();
    if ($code !== 200 || !is_array($json) || (int) ($json['code'] ?? 1) !== 0 || $savedImage !== $imageMarker) {
        $fails[] = '栏目编辑未提交 image 时清空了已有栏目图';
        echo "✗ 栏目编辑未提交 image 时未能保留已有栏目图\n";
    } else {
        echo "✓ 栏目编辑未提交 image 时保留已有栏目图\n";
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
    $pdo = $directDb;
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
echo "\n✅ 后台 CRUD 冒烟测试全部通过（10 类新建 + 栏目图保留）\n";
