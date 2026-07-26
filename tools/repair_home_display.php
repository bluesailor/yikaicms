<?php
/**
 * YikaiCMS 检测修复页：栏目（如产品中心）在首页不显示
 *
 * 病因覆盖：
 *   ① is_home 被清零 —— v1.12.8 及更早版本编辑栏目会静默清掉「首页显示」标记（主病因）
 *   ② 早期版本在首页设置里把版块删除/隐藏 —— 版块躺在 home_blocks_config 里 enabled=false
 *      （若只是从配置里删掉且 is_home=1，首页设置会自动补回，无需修复）
 *   ③ 栏目停用 / 语言不匹配 —— 提示到位，不代改
 *
 * 用法（发给客户）：
 *   1. 把本文件上传到网站的 admin/ 目录；
 *   2. 登录后台后访问  https://站点/admin/repair_home_display.php
 *   3. 看诊断 → 点「一键修复」→ 回首页设置确认 → 点「删除本脚本」。
 *
 * 鉴权：复用后台登录（checkLogin + 超管权限），无需口令。
 * 安全：修复前把原值备份到 storage/（JSON）；只 UPDATE is_home 一个字段。
 * 兼容：数据库操作走原生 PDO（不依赖 Model 层），老版本站点可用；
 *       也可放网站根目录运行（自动探测 config 位置）。
 */

declare(strict_types=1);

// 兼容放 admin/ 或根目录两种位置
if (is_file(dirname(__DIR__) . '/config/config.php')) {
    define('ROOT_PATH', dirname(__DIR__));
} elseif (is_file(__DIR__ . '/config/config.php')) {
    define('ROOT_PATH', __DIR__);
} else {
    exit('未找到 config/config.php——请把本脚本上传到 admin/ 目录（推荐）或网站根目录。');
}

require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requirePermission('*');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    exit('数据库连接失败：' . htmlspecialchars($e->getMessage()));
}

$prefix = defined('DB_PREFIX') ? DB_PREFIX : 'yikai_';
$tbl = $prefix . 'channels';
$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// 兼容老版本：探测可选列
$cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
$hasLang   = in_array('lang', $cols, true);
$hasIsHome = in_array('is_home', $cols, true);
if (!$hasIsHome) {
    exit('本站数据库无 is_home 列（版本过老），不适用本修复页。');
}

// 自删
if (isset($_GET['selfdestruct'])) {
    @unlink(__FILE__);
    exit('<h2 style="font-family:sans-serif">✅ 脚本已删除，再见。</h2>');
}

// 站点默认语言（诊断 lang 不匹配用）
$defaultLang = '';
try {
    $defaultLang = (string) ($pdo->query("SELECT `value` FROM `{$prefix}settings` WHERE `key`='site_lang' LIMIT 1")->fetchColumn() ?: '');
} catch (Throwable $e) {}

function rhd_rows(PDO $pdo, string $tbl, bool $hasLang): array
{
    return $pdo->query(
        "SELECT id, name, slug, type, status, is_home" . ($hasLang ? ", lang" : "") . "
         FROM `$tbl` WHERE parent_id = 0 ORDER BY sort_order ASC, id ASC"
    )->fetchAll();
}
$rows = rhd_rows($pdo, $tbl, $hasLang);

// home_blocks_config：版块被手动隐藏（enabled=false）也会「不显示」
$cfgDisabled = [];
try {
    $raw = (string) ($pdo->query("SELECT `value` FROM `{$prefix}settings` WHERE `key`='home_blocks_config' LIMIT 1")->fetchColumn() ?: '');
    foreach (json_decode($raw, true) ?: [] as $b) {
        if (isset($b['type']) && strpos((string) $b['type'], 'channel:') === 0 && isset($b['enabled']) && !$b['enabled']) {
            $cfgDisabled[(int) substr((string) $b['type'], 8)] = true;
        }
    }
} catch (Throwable $e) {}

// 可修列表：启用中的顶级栏目但 is_home=0。
// 注意：is_home=0 不都是病——关于我们/联系我们等单页栏目本就不上首页，
// 故按行勾选修复；内容类栏目（产品/文章/案例/下载/招聘）才默认勾选（疑似误清的受害者）。
$contentTypes = ['product', 'article', 'case', 'download', 'job'];
$fixable = array_filter($rows, fn($r) => (int) $r['status'] === 1 && (int) $r['is_home'] === 0);
$fixableIds = array_map(fn($r) => (int) $r['id'], $fixable);

// ---- 执行修复（仅勾选的行，且必须在可修集合内）----
$fixedMsg = '';
if (($_POST['rhd_action'] ?? '') === 'fix') {
    $picked = array_values(array_intersect(
        array_map('intval', (array) ($_POST['ids'] ?? [])),
        $fixableIds
    ));
    if ($picked) {
        $bakRows = array_values(array_filter($fixable, fn($r) => in_array((int) $r['id'], $picked, true)));
        @mkdir(ROOT_PATH . '/storage', 0755, true);
        $bak = ROOT_PATH . '/storage/repair-home-display-backup-' . date('YmdHis') . '.json';
        @file_put_contents($bak, json_encode($bakRows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $n = $pdo->exec("UPDATE `$tbl` SET is_home = 1 WHERE id IN (" . implode(',', $picked) . ")");
        if (function_exists('adminLog')) {
            try { adminLog('channel', 'repair', "修复页：{$n} 个栏目 is_home 置回 1"); } catch (Throwable $e) {}
        }
        $fixedMsg = "已修复 {$n} 个栏目（is_home → 1），原值备份：storage/" . basename($bak);
        $rows = rhd_rows($pdo, $tbl, $hasLang);
        $fixable = array_filter($rows, fn($r) => (int) $r['status'] === 1 && (int) $r['is_home'] === 0);
        $fixableIds = array_map(fn($r) => (int) $r['id'], $fixable);
    } else {
        $fixedMsg = '未勾选任何栏目，未做修改。';
    }
}
$csrf = function_exists('csrfToken') ? csrfToken() : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>首页版块检测修复</title>
<style>
body{font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;max-width:880px;margin:40px auto;padding:0 20px;color:#1f2937}
table{border-collapse:collapse;width:100%;margin:16px 0}
th,td{border:1px solid #e5e7eb;padding:8px 12px;text-align:left;font-size:14px}
th{background:#f9fafb}
.ok{color:#16a34a}.bad{color:#dc2626;font-weight:600}.warn{color:#d97706}
.btn{display:inline-block;padding:10px 24px;border-radius:8px;border:0;cursor:pointer;font-size:15px;text-decoration:none}
.btn-fix{background:#16a34a;color:#fff}.btn-del{background:#f3f4f6;color:#6b7280;margin-left:12px}
.note{background:#fffbeb;border:1px solid #fde68a;padding:12px 16px;border-radius:8px;font-size:14px;margin:16px 0}
.msg{background:#f0fdf4;border:1px solid #bbf7d0;padding:12px 16px;border-radius:8px;margin:16px 0}
</style>
</head>
<body>
<h1>🔧 首页版块检测修复</h1>
<p>检查「产品中心」等顶级栏目为何没出现在首页：is_home 被误清（v1.12.8 及更早编辑栏目触发）、首页设置里被隐藏、栏目停用、语言不匹配。</p>

<?php if ($fixedMsg): ?><div class="msg">✅ <?php echo $esc($fixedMsg); ?>。请到 后台 → 网站设置 → 首页设置 确认版块已回来（顺序与显示开关在那里调整）。</div><?php endif; ?>

<form method="post">
<table>
<tr><th>修复</th><th>ID</th><th>栏目</th><th>类型</th><?php if ($hasLang): ?><th>语言</th><?php endif; ?><th>启用</th><th>首页显示</th><th>诊断</th></tr>
<?php foreach ($rows as $r):
    $isFixable = in_array((int) $r['id'], $fixableIds, true);
    $isVictim  = $isFixable && in_array((string) $r['type'], $contentTypes, true);   // 内容类栏目掉出首页 = 疑似误清受害者
    $diag = [];
    if ((int) $r['status'] !== 1) $diag[] = '<span class="warn">已停用（不参与首页）</span>';
    elseif ($isVictim) $diag[] = '<span class="bad">内容栏目却不在首页 ← 疑似被误清，建议修复</span>';
    elseif ($isFixable) $diag[] = '<span class="warn">不在首页（单页类多属正常，需要展示才勾选）</span>';
    elseif (isset($cfgDisabled[(int) $r['id']])) $diag[] = '<span class="warn">首页设置里被隐藏（后台打开开关即可，无需修复）</span>';
    else $diag[] = '<span class="ok">正常</span>';
    if ($hasLang && $defaultLang !== '' && (string) ($r['lang'] ?? '') !== $defaultLang) $diag[] = '<span class="warn">语言≠默认(' . $esc($defaultLang) . ')，仅对应语言首页显示</span>';
?>
<tr>
    <td style="text-align:center"><?php echo $isFixable ? '<input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '"' . ($isVictim ? ' checked' : '') . '>' : ''; ?></td>
    <td><?php echo (int) $r['id']; ?></td>
    <td><?php echo $esc($r['name']); ?> <span style="color:#9ca3af">(<?php echo $esc($r['slug']); ?>)</span></td>
    <td><?php echo $esc($r['type']); ?></td>
    <?php if ($hasLang): ?><td><?php echo $esc($r['lang'] ?? ''); ?></td><?php endif; ?>
    <td><?php echo (int) $r['status'] === 1 ? '✔' : '✘'; ?></td>
    <td><?php echo (int) $r['is_home'] === 1 ? '✔' : '<span class="bad">✘</span>'; ?></td>
    <td><?php echo implode('；', $diag); ?></td>
</tr>
<?php endforeach; ?>
</table>

<?php if ($fixable): ?>
    <div class="note">勾选要恢复到首页的栏目（红色「疑似被误清」的已默认勾选，如产品中心/新闻资讯等内容栏目；关于我们、联系我们这类单页通常不上首页，别乱勾）。<br>修复 = 把勾选栏目的 is_home 置回 1（原值先备份到 storage/，只动这一个字段）。</div>
    <input type="hidden" name="rhd_action" value="fix">
    <?php if ($csrf): ?><input type="hidden" name="_token" value="<?php echo $esc($csrf); ?>"><?php endif; ?>
    <button class="btn btn-fix" onclick="return confirm('确认修复勾选的栏目？')">修复勾选的栏目</button>
    <a class="btn btn-del" href="?selfdestruct=1" onclick="return confirm('删除本脚本？')">删除本脚本</a>
<?php else: ?>
    <div class="msg">未发现可修项。若首页仍缺版块，按上表「诊断」列排查（被隐藏 → 首页设置里打开；停用 → 栏目管理启用；语言不符 → 检查栏目语言）。</div>
    <a class="btn btn-del" href="?selfdestruct=1" onclick="return confirm('删除本脚本？')">删除本脚本</a>
<?php endif; ?>
</form>

<p style="color:#9ca3af;font-size:13px;margin-top:32px">⚠ 用完请删除本脚本。修复后建议尽快升级到 v1.12.9+（根治编辑栏目误清「首页显示」的问题）。</p>
</body>
</html>
