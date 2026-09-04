<?php
/**
 * 角色 × 后台端点 × 方法 的权限矩阵测试。
 *
 * 为什么必须单列一个：admin_crud.php 用的是 role_id=1 的超级管理员，
 * `hasPermission()` 对超管恒真——**任何越权与死权限问题它都结构性地看不见**。
 * 已经因此漏掉过：job_edit / recycle / setting_member 挂在迁移后已废弃的键上，
 * 非超管一律进不去；upload.php / media_api.php / revision.php 只判登录不判能力。
 *
 * 做法：用真实 HTTP 会话分别以「投稿者」「内容编辑」登录，逐条断言该拒的拒、该放的放。
 * 拒绝的判定统一看 perm_denied 文案或 403，因为后台有两种拒绝形态
 * （AJAX → JSON code 403；普通请求 → die 一段 HTML）。
 *
 * 用法：先 setup.php 装机 + 起 php -S，再跑本脚本。任一条不符 → 退出码 1。
 */

declare(strict_types=1);

$BASE = getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8080';

// ─────────────────────────────────────────────────────────────
// 测试账号：直接写库建，避免依赖后台用户管理页
// 角色沿用 install SQL 预置：1=超管 2=投稿者(edit_article) 3=内容编辑(edit_*+media)
// ─────────────────────────────────────────────────────────────
$root   = dirname(__DIR__, 2);
$dbFile = $root . '/storage/database.sqlite';
if (!is_file($dbFile)) {
    fwrite(STDERR, "❌ 找不到 {$dbFile}，请先跑 tests/smoke/setup.php\n");
    exit(2);
}
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ACCOUNTS = [
    'super'       => ['user' => 'admin',       'pass' => 'smoke@Test123', 'role' => 1],
    'contributor' => ['user' => 'pm_contrib',  'pass' => 'Perm@Test123',  'role' => 2],
    'editor'      => ['user' => 'pm_editor',   'pass' => 'Perm@Test123',  'role' => 3],
];
foreach ($ACCOUNTS as $key => $a) {
    if ($a['role'] === 1) {
        continue;   // 超管由 setup.php 建好
    }
    $t = time();
    $pdo->prepare('DELETE FROM yikai_users WHERE username = ?')->execute([$a['user']]);
    $pdo->prepare(
        'INSERT INTO yikai_users (username,password,nickname,email,role_id,status,created_at,updated_at)'
        . ' VALUES (?,?,?,?,?,1,?,?)'
    )->execute([$a['user'], password_hash($a['pass'], PASSWORD_BCRYPT), $a['user'], $a['user'] . '@t.local', $a['role'], $t, $t]);
}

// 各角色实际拿到的权限，打印出来便于排错（也顺带验证迁移/预置没跑偏）
foreach ($pdo->query('SELECT id, name, permissions FROM yikai_roles ORDER BY id') as $r) {
    echo "  角色 {$r['id']} {$r['name']}：{$r['permissions']}\n";
}
echo "\n";

// 造一条单页内容，用于验证「跨类型改写」是否被拦住
$t = time();
$pdo->prepare(
    "INSERT INTO yikai_contents (channel_id,type,title,slug,content,status,lang,translation_group_id,created_at,updated_at,publish_time)"
    . " VALUES (0,'page','权限矩阵测试单页','pm-test-page','x',1,'zh-CN',0,?,?,?)"
)->execute([$t, $t, $t]);
$PAGE_ID = (int) $pdo->lastInsertId();
$BLOX_PAGE_ID = (int) $pdo->query("SELECT id FROM yikai_channels WHERE type = 'page' ORDER BY id LIMIT 1")->fetchColumn();
echo "测试单页 id={$PAGE_ID}\n\n";
unset($pdo);

// ─────────────────────────────────────────────────────────────
// HTTP 客户端（每个角色一份独立 cookie jar）
// ─────────────────────────────────────────────────────────────
function pmReq(string $jar, string $method, string $url, ?array $post = null): array
{
    global $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['X-Requested-With: XMLHttpRequest'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?? []));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function pmCsrf(string $html): string
{
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $html, $m)) return $m[1];
    if (preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $html, $m)) return $m[1];
    return '';
}

function pmLogin(string $jar, string $user, string $pass): string
{
    @unlink($jar);
    [, $page] = pmReq($jar, 'GET', '/admin/login.php');
    $token = pmCsrf($page);
    [$code] = pmReq($jar, 'POST', '/admin/login.php', ['username' => $user, 'password' => $pass, '_token' => $token]);
    if ($code !== 302) {
        fwrite(STDERR, "❌ {$user} 登录失败（HTTP {$code}）\n");
        exit(2);
    }
    [, $dash] = pmReq($jar, 'GET', '/admin/index.php');
    return pmCsrf($dash);
}

/**
 * 响应是否是「被拒绝」。
 * 后台有两种拒绝形态：AJAX 走 error() 返回 JSON（code 403 / 业务 code），
 * 普通请求走 requirePermission() die 一段 HTML。统一按文案与状态码识别。
 */
function pmDenied(int $code, string $body): bool
{
    if ($code === 403) return true;
    if (str_contains($body, '没有操作权限') || str_contains($body, 'Permission denied')
        || str_contains($body, '没有上传权限') || str_contains($body, '没有媒体库权限')) {
        return true;
    }
    $j = json_decode($body, true);
    if (is_array($j) && (int) ($j['code'] ?? 0) === 403) return true;
    return false;
}

// ─────────────────────────────────────────────────────────────
// 矩阵
//   allow：该角色必须能通过权限闸（不要求业务成功，只要求不是「无权限」）
//   deny ：该角色必须被拒
// ─────────────────────────────────────────────────────────────
$M = [];
$add = function (string $label, string $method, string $url, array $expect, ?array $post = null) use (&$M): void {
    $M[] = compact('label', 'method', 'url', 'expect', 'post');
};

// —— 曾经的死权限页面：迁移后非超管一律进不去，修复后应放行 ——
$add('文章列表',        'GET', '/admin/article.php',        ['contributor' => 'allow', 'editor' => 'allow']);
$add('招聘列表',        'GET', '/admin/job.php',            ['contributor' => 'allow', 'editor' => 'allow']);
$add('招聘编辑（曾死锁）', 'GET', '/admin/job_edit.php',       ['contributor' => 'allow', 'editor' => 'allow']);
$add('会员设置（超管专属）', 'GET', '/admin/setting_member.php', ['contributor' => 'deny',  'editor' => 'deny']);

// —— 类型隔离：只有 edit_article 的投稿者不得碰产品/单页 ——
$add('产品列表',    'GET', '/admin/product.php', ['contributor' => 'deny',  'editor' => 'allow']);
$add('单页列表',    'GET', '/admin/page.php',    ['contributor' => 'deny',  'editor' => 'allow']);
$add('案例列表',    'GET', '/admin/case.php',    ['contributor' => 'deny',  'editor' => 'allow']);

// —— Blox 场景权限：普通页面与首页/全站设计分开授权 ——
$add('Blox 普通页面', 'GET', '/admin/blox_editor.php?id=' . $BLOX_PAGE_ID,
     ['contributor' => 'deny', 'editor' => 'allow']);
$add('Blox 首页', 'GET', '/admin/blox_editor.php?home=1',
     ['contributor' => 'deny', 'editor' => 'deny']);
$add('Blox 站点设计', 'GET', '/admin/site_design.php',
     ['contributor' => 'deny', 'editor' => 'allow']);
$add('Blox 全局设计系统', 'GET', '/admin/blox_design.php',
     ['contributor' => 'deny', 'editor' => 'deny']);
$add('Blox 模板管理', 'GET', '/admin/blox_templates.php',
     ['contributor' => 'deny', 'editor' => 'deny']);

// —— 超管专属结构项 ——
$add('栏目管理', 'GET', '/admin/channel.php',  ['contributor' => 'deny', 'editor' => 'deny']);
$add('角色管理', 'GET', '/admin/role.php',     ['contributor' => 'deny', 'editor' => 'deny']);
$add('用户管理', 'GET', '/admin/user.php',     ['contributor' => 'deny', 'editor' => 'deny']);
$add('系统设置', 'GET', '/admin/setting.php',  ['contributor' => 'deny', 'editor' => 'deny']);
$add('插件管理', 'GET', '/admin/plugin.php',   ['contributor' => 'deny', 'editor' => 'deny']);
$add('在线升级', 'GET', '/admin/upgrade_online.php', ['contributor' => 'deny', 'editor' => 'deny']);
$add('授权管理', 'GET', '/admin/license.php',  ['contributor' => 'deny', 'editor' => 'deny']);

// —— 上传分三档：图片（能编辑就能传）/ 文档压缩包（下载编辑者与媒体管理员）/
//    媒体库管理（仅 media）。$type 是客户端传的，两档必须分别判。
$add('媒体库管理页（要 media）', 'GET',  '/admin/media.php',                ['contributor' => 'deny',  'editor' => 'allow']);
$add('媒体选择器·列表',        'GET',  '/admin/media_api.php?action=list', ['contributor' => 'allow', 'editor' => 'allow']);
$add('上传图片',               'POST', '/admin/upload.php',               ['contributor' => 'allow', 'editor' => 'allow'], ['type' => 'images']);
$add('上传视频',               'POST', '/admin/upload.php',               ['contributor' => 'allow', 'editor' => 'allow'], ['type' => 'videos']);
// 投稿者只有 edit_article：不该能上传 pdf/zip。内容编辑有 edit_download，可以。
$add('上传文档/压缩包',        'POST', '/admin/upload.php',               ['contributor' => 'deny',  'editor' => 'allow'], ['type' => 'files']);

// —— 回收站：读取也要按权限过滤，不能只拦写操作 ——
//    投稿者只有 edit_article、无任何 delete_ → 一个分类也看不到 → 整页拒绝。
//    内容编辑有 media → 相册分类可见 → 整页放行（但只该看到相册，见下方 tab 过滤断言）。
$add('回收站', 'GET', '/admin/recycle.php', ['contributor' => 'deny', 'editor' => 'allow']);

// —— 招聘 / 发展历程：2026-07-30 起独立成键。迁移把 edit_article 持有者
//    自动补上 edit_job + edit_timeline，所以两个角色都应放行（语义无损）。
$add('招聘（独立 edit_job）',      'GET', '/admin/job.php',      ['contributor' => 'allow', 'editor' => 'allow']);
$add('发展历程（edit_timeline）',  'GET', '/admin/timeline.php', ['contributor' => 'allow', 'editor' => 'allow']);

// —— 版本历史：按类型判定，投稿者不得读/恢复单页版本 ——
$add('版本·读文章', 'GET', '/admin/revision.php?action=list&type=article&id=1',
     ['contributor' => 'allow', 'editor' => 'allow']);
$add('版本·读单页', 'GET', '/admin/revision.php?action=list&type=page&id=' . $PAGE_ID,
     ['contributor' => 'deny',  'editor' => 'allow']);

// 独立成键的意义在于「能单独收紧」。造一个只有 edit_article、
// 不含 edit_job / edit_timeline 的角色，验证招聘与历程确实被挡住——
// 否则等于白拆。
$ISOLATED = ['user' => 'pm_artonly', 'pass' => 'Perm@Test123', 'roleId' => 91];
$pdo2 = new PDO('sqlite:' . $dbFile);
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$t = time();
$pdo2->exec('DELETE FROM yikai_roles WHERE id = ' . $ISOLATED['roleId']);
$pdo2->prepare(
    'INSERT INTO yikai_roles (id,name,name_en,name_ja,description,description_en,description_ja,permissions,status,created_at)'
    . " VALUES (?,'仅文章','Article Only','記事のみ','','','',?,1,?)"
)->execute([$ISOLATED['roleId'], json_encode(['edit_article', 'delete_article']), $t]);
$pdo2->prepare('DELETE FROM yikai_users WHERE username = ?')->execute([$ISOLATED['user']]);
$pdo2->prepare(
    'INSERT INTO yikai_users (username,password,nickname,email,role_id,status,created_at,updated_at)'
    . ' VALUES (?,?,?,?,?,1,?,?)'
)->execute([$ISOLATED['user'], password_hash($ISOLATED['pass'], PASSWORD_BCRYPT), 'art', 'art@t.local', $ISOLATED['roleId'], $t, $t]);
unset($pdo2);

echo str_repeat('─', 72) . "\n";

// ─────────────────────────────────────────────────────────────
// 执行
// ─────────────────────────────────────────────────────────────
$fail = 0;
$checked = 0;
foreach (['contributor', 'editor'] as $roleKey) {
    $a   = $ACCOUNTS[$roleKey];
    $jar = sys_get_temp_dir() . "/pm_{$roleKey}_" . getmypid() . '.txt';
    $tok = pmLogin($jar, $a['user'], $a['pass']);
    echo "【{$roleKey}】{$a['user']}\n";

    foreach ($M as $row) {
        if (!isset($row['expect'][$roleKey])) {
            continue;
        }
        $want = $row['expect'][$roleKey];
        $post = $row['post'];
        if ($row['method'] === 'POST') {
            $post = array_merge($post ?? [], ['_token' => $tok]);
        }
        [$code, $body] = pmReq($jar, $row['method'], $row['url'], $post);
        $denied = pmDenied($code, $body);
        $ok = ($want === 'deny') ? $denied : !$denied;
        $checked++;
        if (!$ok) {
            $fail++;
            $got = $denied ? '被拒' : '放行';
            printf("  ✗ %-22s %-4s 期望%s 实际%s (HTTP %d)\n", $row['label'], $row['method'], $want === 'deny' ? '拒绝' : '放行', $got, $code);
        } else {
            printf("  ✓ %-22s %-4s %s\n", $row['label'], $row['method'], $want === 'deny' ? '已拒绝' : '已放行');
        }
    }
    @unlink($jar);
    echo "\n";
}

// ── 隔离验证：只有 edit_article 的角色，招聘与历程必须被挡住 ──
$jar = sys_get_temp_dir() . '/pm_artonly_' . getmypid() . '.txt';
pmLogin($jar, $ISOLATED['user'], $ISOLATED['pass']);
echo "【仅 edit_article（验证独立成键真能收紧）】{$ISOLATED['user']}\n";
foreach ([
    ['文章列表（应放行）', '/admin/article.php',  'allow'],
    ['招聘（应拒绝）',     '/admin/job.php',      'deny'],
    ['发展历程（应拒绝）', '/admin/timeline.php', 'deny'],
    ['回收站（有 delete_article，应放行）', '/admin/recycle.php', 'allow'],
] as [$label, $url, $want]) {
    [$code, $body] = pmReq($jar, 'GET', $url);
    $denied = pmDenied($code, $body);
    $ok = ($want === 'deny') ? $denied : !$denied;
    $checked++;
    if (!$ok) {
        $fail++;
        printf("  ✗ %-22s 期望%s 实际%s (HTTP %d)\n", $label, $want === 'deny' ? '拒绝' : '放行', $denied ? '被拒' : '放行', $code);
    } else {
        printf("  ✓ %-22s %s\n", $label, $denied ? '已拒绝' : '已放行');
    }
}
// 只拦住入口不算数：页面内的分类 tab 也必须按权限过滤，
// 否则「已删产品/相册有几条、叫什么」照样看得到。
[$c, $body] = pmReq($jar, 'GET', '/admin/recycle.php');
foreach ([
    ['内容分类可见',   "?type=content",  true],
    ['产品分类不可见', "?type=product",  false],
    ['相册分类不可见', "?type=album",    false],
    ['招聘分类不可见', "?type=job",      false],
] as [$label, $needle, $want]) {
    $got = str_contains($body, $needle);
    $checked++;
    if ($got !== $want) {
        $fail++;
        printf("  ✗ 回收站 tab：%-16s 期望%s 实际%s\n", $label, $want ? '有' : '无', $got ? '有' : '无');
    } else {
        printf("  ✓ 回收站 tab：%s\n", $label);
    }
}

@unlink($jar);
echo "\n";

// ── 会话身份不是登录时的快照：改权限 / 停用账号必须立即生效 ──
// 会话里的 admin_permissions 原本只在登录时写一次，导致「收紧某个角色」
// 与「停用某人」对已登录的人都不生效。后者尤其严重。
echo "【会话身份即时失效】\n";
$jar2 = sys_get_temp_dir() . '/pm_live_' . getmypid() . '.txt';
pmLogin($jar2, $ISOLATED['user'], $ISOLATED['pass']);

// 登录时有 edit_article → 文章列表可进
[$c, $b] = pmReq($jar2, 'GET', '/admin/article.php');
$checked++;
if (pmDenied($c, $b)) { $fail++; echo "  ✗ 前置：登录后本应进得去文章列表\n"; }
else { echo "  ✓ 前置：登录后可进文章列表\n"; }

$pdo3 = new PDO('sqlite:' . $dbFile);
$pdo3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1) 抽掉角色权限——同一会话下一次请求就该被拒
$pdo3->prepare('UPDATE yikai_roles SET permissions = ? WHERE id = ?')
     ->execute([json_encode([]), $ISOLATED['roleId']]);
[$c, $b] = pmReq($jar2, 'GET', '/admin/article.php');
$checked++;
if (pmDenied($c, $b)) { echo "  ✓ 收紧角色权限后立即失效\n"; }
else { $fail++; printf("  ✗ 收紧角色权限后仍放行 (HTTP %d)——会话还在用旧快照\n", $c); }

// 还原权限，验证同样即时生效（不是「一律拒绝」那种假通过）
$pdo3->prepare('UPDATE yikai_roles SET permissions = ? WHERE id = ?')
     ->execute([json_encode(['edit_article', 'delete_article']), $ISOLATED['roleId']]);
[$c, $b] = pmReq($jar2, 'GET', '/admin/article.php');
$checked++;
if (!pmDenied($c, $b)) { echo "  ✓ 放开权限后同样立即生效\n"; }
else { $fail++; echo "  ✗ 放开权限后仍被拒——说明上一条是误判\n"; }

// Catalog lookup requires both Blox page access and the matching detail permission.
$catalogIds = [];
foreach (['product', 'list'] as $catalogType) {
    $stmt = $pdo3->prepare('SELECT id FROM yikai_channels WHERE type = ? ORDER BY id LIMIT 1');
    $stmt->execute([$catalogType]);
    $catalogIds[$catalogType] = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
}
[, $catalogDashboard] = pmReq($jar2, 'GET', '/admin/index.php');
$catalogToken = pmCsrf($catalogDashboard);
foreach ([[], ['edit_product'], ['edit_article'], ['edit_product', 'edit_article']] as $detailPermissions) {
    $pdo3->prepare('UPDATE yikai_roles SET permissions = ? WHERE id = ?')
        ->execute([json_encode(array_merge(['blox_edit', 'edit_page'], $detailPermissions)), $ISOLATED['roleId']]);
    foreach ($catalogIds as $catalogType => $catalogId) {
        [$c, $b] = pmReq($jar2, 'POST', '/admin/blox_page_api.php', [
            'action' => 'catalog_items', 'id' => $catalogId, '_token' => $catalogToken,
        ]);
        $want = in_array($catalogType === 'product' ? 'edit_product' : 'edit_article', $detailPermissions, true);
        $json = json_decode($b, true);
        $allowed = $c === 200 && isset($json['data']['items']) && (int) ($json['code'] ?? -1) === 0;
        $ok = $want ? $allowed : pmDenied($c, $b);
        $checked++;
        if (!$ok) $fail++;
        printf("  %s Catalog %s / %s\n", $ok ? 'PASS' : 'FAIL', $catalogType, implode(',', $detailPermissions));
    }
}
$pdo3->prepare('UPDATE yikai_roles SET permissions = ? WHERE id = ?')
    ->execute([json_encode(['edit_article', 'delete_article']), $ISOLATED['roleId']]);

// 2) 停用账号——同一会话应被踢回登录页
$pdo3->prepare('UPDATE yikai_users SET status = 0 WHERE username = ?')->execute([$ISOLATED['user']]);
[$c, $b] = pmReq($jar2, 'GET', '/admin/article.php');
$checked++;
// 判据必须与语言无关：原先只认中文串「账号已失效」，英文站上产品照常返回
// 401 + 英文文案，断言却判失败（--lang=en 腿首跑抓出的测试自身的坑）。
// 三语文案并列——与上面 pmDenied() 的既有做法一致，不引核心（本脚本不加载 functions.php）。
$kicked = $c === 302 || $c === 401 || str_contains($b, 'name="password"')
    || str_contains($b, '账号已失效')
    || str_contains($b, 'Account is no longer valid')
    || str_contains($b, 'アカウントが無効です');
if ($kicked) { echo "  ✓ 停用账号后当场踢出\n"; }
else { $fail++; printf("  ✗ 停用账号后仍可用 (HTTP %d)——「禁用」等于没禁\n", $c); }

$pdo3->prepare('UPDATE yikai_users SET status = 1 WHERE username = ?')->execute([$ISOLATED['user']]);
unset($pdo3);
@unlink($jar2);
echo "\n";

echo str_repeat('─', 72) . "\n";
if ($fail > 0) {
    fwrite(STDERR, "❌ 权限矩阵：{$checked} 项中 {$fail} 项不符\n");
    exit(1);
}
echo "✓ 权限矩阵全部符合（{$checked} 项）\n";
