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
$add('回收站（曾死锁）',   'GET', '/admin/recycle.php',        ['contributor' => 'allow', 'editor' => 'allow']);
$add('会员设置（超管专属）', 'GET', '/admin/setting_member.php', ['contributor' => 'deny',  'editor' => 'deny']);

// —— 类型隔离：只有 edit_article 的投稿者不得碰产品/单页 ——
$add('产品列表',    'GET', '/admin/product.php', ['contributor' => 'deny',  'editor' => 'allow']);
$add('单页列表',    'GET', '/admin/page.php',    ['contributor' => 'deny',  'editor' => 'allow']);
$add('案例列表',    'GET', '/admin/case.php',    ['contributor' => 'deny',  'editor' => 'allow']);

// —— 超管专属结构项 ——
$add('栏目管理', 'GET', '/admin/channel.php',  ['contributor' => 'deny', 'editor' => 'deny']);
$add('角色管理', 'GET', '/admin/role.php',     ['contributor' => 'deny', 'editor' => 'deny']);
$add('用户管理', 'GET', '/admin/user.php',     ['contributor' => 'deny', 'editor' => 'deny']);
$add('系统设置', 'GET', '/admin/setting.php',  ['contributor' => 'deny', 'editor' => 'deny']);
$add('插件管理', 'GET', '/admin/plugin.php',   ['contributor' => 'deny', 'editor' => 'deny']);
$add('在线升级', 'GET', '/admin/upgrade_online.php', ['contributor' => 'deny', 'editor' => 'deny']);
$add('授权管理', 'GET', '/admin/license.php',  ['contributor' => 'deny', 'editor' => 'deny']);

// —— 上传：投稿者角色描述写明「不能上传媒体」 ——
$add('媒体库页面',      'GET',  '/admin/media.php',                 ['contributor' => 'deny', 'editor' => 'allow']);
$add('媒体库 API·列表', 'GET',  '/admin/media_api.php?action=list',  ['contributor' => 'deny', 'editor' => 'allow']);
$add('上传接口',        'POST', '/admin/upload.php',                ['contributor' => 'deny', 'editor' => 'allow']);

// —— 版本历史：按类型判定，投稿者不得读/恢复单页版本 ——
$add('版本·读文章', 'GET', '/admin/revision.php?action=list&type=article&id=1',
     ['contributor' => 'allow', 'editor' => 'allow']);
$add('版本·读单页', 'GET', '/admin/revision.php?action=list&type=page&id=' . $PAGE_ID,
     ['contributor' => 'deny',  'editor' => 'allow']);

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

echo str_repeat('─', 72) . "\n";
if ($fail > 0) {
    fwrite(STDERR, "❌ 权限矩阵：{$checked} 项中 {$fail} 项不符\n");
    exit(1);
}
echo "✓ 权限矩阵全部符合（{$checked} 项）\n";
