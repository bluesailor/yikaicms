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
    'SELECT description FROM ' . DB_PREFIX . 'channels',
    'SELECT subtitle FROM ' . DB_PREFIX . 'banners',
    'SELECT name FROM ' . DB_PREFIX . 'form_templates',
    'SELECT name FROM ' . DB_PREFIX . 'plugins',
    'SELECT description FROM ' . DB_PREFIX . 'plugins',
    'SELECT name FROM ' . DB_PREFIX . 'product_categories',
    'SELECT name FROM ' . DB_PREFIX . 'product_tags',
    'SELECT group_name FROM ' . DB_PREFIX . 'product_tags',
    'SELECT name FROM ' . DB_PREFIX . 'nav_menus',
    'SELECT title FROM ' . DB_PREFIX . 'contents',
    'SELECT nickname FROM ' . DB_PREFIX . 'users',
    'SELECT name FROM ' . DB_PREFIX . 'blox_templates',
    'SELECT title FROM ' . DB_PREFIX . 'timelines',
    'SELECT content FROM ' . DB_PREFIX . 'timelines',
    'SELECT title FROM ' . DB_PREFIX . 'products',
    'SELECT subtitle FROM ' . DB_PREFIX . 'products',
    'SELECT summary FROM ' . DB_PREFIX . 'products',
    'SELECT subtitle FROM ' . DB_PREFIX . 'contents',
    'SELECT summary FROM ' . DB_PREFIX . 'contents',
    'SELECT title FROM ' . DB_PREFIX . 'album_photos',
    'SELECT description FROM ' . DB_PREFIX . 'album_photos',
    // settings.value = 站长自己填的站点内容（站点名/口号/简介…），属数据；
    // settings.name / tip / options 才是 UI，走 setting_* / setting_opt_* 键。
    'SELECT value FROM ' . DB_PREFIX . 'settings',
    'SELECT title FROM ' . DB_PREFIX . 'jobs',
    'SELECT location FROM ' . DB_PREFIX . 'jobs',
    'SELECT name FROM ' . DB_PREFIX . 'links',
    'SELECT name FROM ' . DB_PREFIX . 'brands',
    'SELECT name FROM ' . DB_PREFIX . 'albums',
    'SELECT name FROM ' . DB_PREFIX . 'banner_groups',
    'SELECT title FROM ' . DB_PREFIX . 'banners',
    'SELECT title FROM ' . DB_PREFIX . 'downloads',
    'SELECT name FROM ' . DB_PREFIX . 'download_categories',
    'SELECT name FROM ' . DB_PREFIX . 'roles',
    'SELECT name FROM ' . DB_PREFIX . 'content_models',
    'SELECT title FROM ' . DB_PREFIX . 'form_templates',
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
// 单页/文章的排版数据（blocks_data）是 JSON 里的正文，编辑器把它渲染进画布——
// 整块 JSON 当白名单条目匹配不上单个片段，得把里面的字符串逐个摊出来。
$collectStrings = static function ($node) use (&$collectStrings, &$whitelist): void {
    if (is_string($node)) {
        // 正文里混着标签（<strong> 加粗、alt="..." 等），整串比对会被标签切断——
        // 直接把每一段连续中文收进白名单：blocks_data 里的内容按定义就是数据。
        if (preg_match_all('/[\x{4e00}-\x{9fff}][^<>"\x{0000}-\x{001f}]*/u', $node, $cm)) {
            foreach ($cm[0] as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $whitelist[] = $piece;
                }
            }
        }
        return;
    }
    if (is_array($node)) {
        foreach ($node as $child) {
            $collectStrings($child);
        }
    }
};
try {
    foreach (db()->fetchAll('SELECT blocks_data FROM ' . DB_PREFIX . 'contents') as $row) {
        $collectStrings(json_decode((string) reset($row), true));
    }
} catch (Throwable) {
}
// 插件提供的元素标签由插件作者维护，不是本仓库的 UI 债
foreach (glob(ROOT_PATH . '/plugins/*/*.php') as $pf) {
    if (preg_match_all("/function label\(\): string.*?return '([^']+)'/s", (string) file_get_contents($pf), $lm)) {
        foreach ($lm[1] as $lbl) {
            if (preg_match('/[\x{4e00}-\x{9fff}]/u', $lbl)) {
                $whitelist[] = $lbl;
            }
        }
    }
}
// 出厂默认值（defaults.php）——DB 里没有该行时页面显示的就是它，同属数据
foreach (['basic', 'home', 'contact', 'seo'] as $g) {
    foreach (getDefaults($g) as $def) {
        $v = trim((string) ($def['value'] ?? ''));
        if ($v !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) {
            $whitelist[] = $v;
        }
    }
}
// 内容模型预置方案名：三语并列存在预置表里，新建弹窗要用它填 EN/JA 输入框，
// 中文出现在页面源码里是功能需要
foreach (require ROOT_PATH . '/includes/content_model_presets.php' as $preset) {
    foreach (['name', 'name_ja'] as $f) {
        $v = trim((string) ($preset[$f] ?? ''));
        if ($v !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) {
            $whitelist[] = $v;
        }
    }
}
// 插件与主题清单里的名称/描述由第三方作者提供，不是本仓库的 UI 债
foreach (array_merge(glob(ROOT_PATH . '/plugins/*/plugin.json'), glob(ROOT_PATH . '/themes/*/theme.json')) as $mf) {
    $j = json_decode((string) file_get_contents($mf), true);
    foreach (['name', 'description'] as $f) {
        $v = trim((string) ($j[$f] ?? ''));
        if ($v !== '' && preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) {
            $whitelist[] = $v;
        }
    }
}
// 长串优先剔除，避免短串先命中把长串切碎
$whitelist = array_unique($whitelist);
usort($whitelist, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

// 豁免清单：
//   前四个 = 未登录/升级流程页（扫描器登录态覆盖不到或有副作用）；
//   blox_editor = 付费文件不随公开仓库分发；
//   setting_translate / setting_channel_translate / setting_product_cat_translate =
//     翻译工作台，页面职能就是展示中文源文供翻译，命中的是数据不是 UI；
//   ai_assistant = Abilities 能力描述是 AI 提示词语料（includes/abilities/*），
//     由模型消费而非用户界面，中文是特性不是缺陷。
$skip = ['login.php', 'logout.php', 'upgrade.php', 'upgrade_online.php', 'blox_editor.php',
    'setting_translate.php', 'setting_channel_translate.php', 'setting_product_cat_translate.php',
    'ai_assistant.php'];
$report = [];
$errors = [];
foreach (glob(ROOT_PATH . '/admin/*.php') as $file) {
    $name = basename($file);
    if (in_array($name, $skip, true) || str_contains($name, '_api') || str_starts_with($name, 'api_')) {
        continue;
    }
    // 少数页面无参直接 302（必须带 id/type）——补默认查询串，否则永远扫不到
    $qs = [
        'album_photos.php'      => '?id=1',
        'page_edit.php'         => '?id=1',
        'page_edit_advance.php' => '?id=1',
    ][$name] ?? '';
    [$code, $html] = scan_req('/admin/' . $name . $qs);
    if ($code !== 200) {
        $errors[$name] = "HTTP $code";
        continue;
    }

    // 内联 JS：剥注释后抓引号串
    $jsText = '';
    preg_match_all('/<script(?![^>]*src)[^>]*>(.*?)<\/script>/s', $html, $scripts);
    foreach ($scripts[1] ?? [] as $js) {
        $js = preg_replace('~/\*.*?\*/~s', '', (string) $js);   // 块注释先剥
        $js = preg_replace('~//[^\n]*~', '', (string) $js);
        preg_match_all('/[\'"`]([^\'"`\n]*[\x{4e00}-\x{9fff}][^\'"`\n]*)[\'"`]/u', (string) $js, $jm);
        $jsText .= ' ' . implode(' ', $jm[1] ?? []);
    }

    $clean = preg_replace('/<script.*?<\/script>|<style.*?<\/style>|<!--.*?-->/s', ' ', $html);
    preg_match_all('/(?:placeholder|title|aria-label|alt)="([^"]*[\x{4e00}-\x{9fff}][^"]*)"/u', (string) $clean, $attrs);
    $text = strip_tags((string) $clean) . ' ' . implode(' ', $attrs[1] ?? []) . $jsText;

    // 先把数据串从文本里剔掉再抽片段——事后比对会漏掉「数据+标签同处一串」的
    // 情况（如 title="<文章标题> Translated: <译文标题>"，整串既不含于白名单
    // 任一条、也不被任一条包含，旧法必误报）。
    foreach ($whitelist as $w) {
        if (mb_strlen($w) >= 2) {
            $text = str_replace($w, ' ', $text);
        }
    }

    preg_match_all('/[\x{4e00}-\x{9fff}][\x{4e00}-\x{9fff}\x{ff01}-\x{ff5e}a-zA-Z0-9 ：ःः:，、．.]{0,24}/u', $text, $mm);
    $frags = [];
    foreach (array_unique($mm[0]) as $frag) {
        $frag = trim($frag);
        if ($frag === '') {
            continue;
        }
        // 剔除步骤按整串匹配，两种情况会漏：列表里被 cutStr() 截断（尾部省略号）、
        // 数据串里含片段正则不认的字符（斜杠等）被切成两半。都按「是白名单条目的
        // 一部分」再兜一次。
        $isData = false;
        $stem = rtrim($frag, '.…');
        foreach ($whitelist as $w) {
            if (mb_strlen($stem) >= 4 && str_contains($w, $stem)) {
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
