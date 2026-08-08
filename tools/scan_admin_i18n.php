<?php
/**
 * 后台 i18n 渲染态扫描器（CLI）——在英文语言环境下逐页 GET 后台页面，
 * 提取 UI 中的 CJK 残留（硬编码中文/半翻译体）。
 *
 * 原理与方法论：yikaicms-docs/admin-i18n-audit-methodology-2026-08-09.md
 * 用法（需 smoke 环境已 setup、admin_lang=en、php -S 已在 127.0.0.1:8080）：
 *   php tools/scan_admin_i18n.php            # 摘要（按残留数排序）
 *   php tools/scan_admin_i18n.php -v         # 每页完整片段清单
 *
 * 降噪设计：
 *   - 剥 <script src>/<style>/HTML 注释；内联 <script> 先剥 JS 注释再抓引号串
 *     （否则中文注释误报——首版实证）；
 *   - 数据白名单**动态拉库**（栏目/产品分类/标签/菜单组名等内容数据本来就是
 *     中文，不是 UI 缺陷）+ 静态白名单（语言名标签等）；
 *   - 属性（placeholder/title/aria-label/alt）单独抓取。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';

$verbose = in_array('-v', $argv, true);
$BASE = 'http://127.0.0.1:8080';
$JAR = sys_get_temp_dir() . '/i18n_scan_' . getmypid() . '.txt';

function scan_req(string $path, array $post = []): array
{
    global $BASE, $JAR;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $JAR,
        CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 20,
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// 登录（smoke 固定管理员）
[$c, $login] = scan_req('/admin/login.php');
preg_match('/name="_token" value="([a-f0-9]+)"/', $login, $m);
scan_req('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $m[1] ?? '']);

// 数据白名单：内容性中文（动态拉库）+ 语言标签
$whitelist = ['中文', '日本語', '繁體中文', '한국어'];
foreach ([
    'SELECT name FROM ' . DB_PREFIX . 'channels',
    'SELECT name FROM ' . DB_PREFIX . 'product_categories',
    'SELECT name FROM ' . DB_PREFIX . 'product_tags',
    'SELECT group_name FROM ' . DB_PREFIX . 'product_tags',
    'SELECT name FROM ' . DB_PREFIX . 'nav_menus',
    'SELECT title FROM ' . DB_PREFIX . 'contents',
    'SELECT nickname FROM ' . DB_PREFIX . 'users',
    'SELECT name FROM ' . DB_PREFIX . 'blox_templates',
] as $sql) {
    try {
        foreach (db()->fetchAll($sql) as $row) {
            $v = trim((string) reset($row));
            if ($v !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) {
                $whitelist[] = $v;
            }
        }
    } catch (Throwable) {
    }
}
$whitelist = array_unique($whitelist);

$skip = ['login.php', 'logout.php', 'upgrade.php', 'upgrade_online.php', 'blox_editor.php'];
$report = [];
$errors = [];
foreach (glob(ROOT_PATH . '/admin/*.php') as $file) {
    $name = basename($file);
    if (in_array($name, $skip, true) || str_contains($name, '_api') || str_starts_with($name, 'api_')) {
        continue;
    }
    [$code, $html] = scan_req('/admin/' . $name);
    if ($code !== 200) {
        $errors[$name] = "HTTP $code";
        continue;
    }

    // 内联 JS：剥注释后抓引号串
    $jsText = '';
    preg_match_all('/<script(?![^>]*src)[^>]*>(.*?)<\/script>/s', $html, $scripts);
    foreach ($scripts[1] ?? [] as $js) {
        $js = preg_replace('~//[^\n]*|/\*.*?\*/~s', '', $js);
        preg_match_all('/[\'"`]([^\'"`\n]*[\x{4e00}-\x{9fff}][^\'"`\n]*)[\'"`]/u', (string) $js, $jm);
        $jsText .= ' ' . implode(' ', $jm[1] ?? []);
    }

    $clean = preg_replace('/<script.*?<\/script>|<style.*?<\/style>|<!--.*?-->/s', ' ', $html);
    preg_match_all('/(?:placeholder|title|aria-label|alt)="([^"]*[\x{4e00}-\x{9fff}][^"]*)"/u', (string) $clean, $attrs);
    $text = strip_tags((string) $clean) . ' ' . implode(' ', $attrs[1] ?? []) . $jsText;

    preg_match_all('/[\x{4e00}-\x{9fff}][\x{4e00}-\x{9fff}\x{ff01}-\x{ff5e}a-zA-Z0-9 ：ःः:，、．.]{0,24}/u', $text, $mm);
    $frags = [];
    foreach (array_unique($mm[0]) as $frag) {
        $frag = trim($frag);
        if ($frag === '') {
            continue;
        }
        $isData = false;
        foreach ($whitelist as $w) {
            if (mb_strlen($w) >= 2 && (str_contains($frag, $w) || str_contains($w, $frag))) {
                $isData = true;
                break;
            }
        }
        if (!$isData) {
            $frags[] = $frag;
        }
    }
    if ($frags !== []) {
        $report[$name] = $frags;
    }
}

uasort($report, static fn (array $a, array $b): int => count($b) <=> count($a));
$total = 0;
foreach ($report as $page => $frags) {
    $total += count($frags);
    echo "== $page (" . count($frags) . ") ==\n";
    echo '  ' . implode(' | ', $verbose ? $frags : array_slice($frags, 0, 12)) . "\n";
}
if ($errors !== []) {
    echo "\n-- 非 200 页面（需带参/写端点/异常）--\n";
    foreach ($errors as $page => $why) {
        echo "  $page: $why\n";
    }
}
echo "\nTOTAL pages_with_residue=" . count($report) . " fragments=$total\n";
@unlink($JAR);
