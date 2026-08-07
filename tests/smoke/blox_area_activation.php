<?php
/**
 * Blox 头尾激活生命周期冒烟（常驻版，r7.1）。
 *
 * 前身是 r6 的一次性验证脚本（跑完即删）——审计正确指出 exclude/单页/footer/
 * 旧主题回退在 CI 零覆盖。本脚本对着 tests/smoke/setup.php 装出的一次性
 * SQLite 库 + php -S 服务器跑完整生命周期，任一断言失败退出码 1。
 *
 * 覆盖：基线无泄漏 / home 条件接管+边界 / 单页条件（真实 page.php 上下文，
 * P1-a 回归）/ exclude 否决 / footer 区域 / 损坏条件 fail-closed（P1-b 回归）/
 * 非 default 主题旧链路回退 / 删除后基线恢复。
 *
 * 用法：php tests/smoke/setup.php && php -S 127.0.0.1:8080 -t . & 后执行本脚本。
 */

declare(strict_types=1);

$BASE = getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8080';

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

function fetch(string $path): string
{
    global $BASE;
    return (string) @file_get_contents($BASE . $path . (str_contains($path, '?') ? '&' : '?') . '_t=' . microtime(true));
}

function makeTemplate(string $type, string $marker, ?string $conditions): int
{
    $json = json_encode([
        'format' => BloxTemplateImporter::FORMAT,
        'version' => BloxTemplateImporter::VERSION,
        'type' => $type,
        'name' => 'smoke-' . $marker,
        'document' => [[
            'type' => 'section',
            'settings' => ['padding' => 'sm'],
            'columns' => [[ 'elements' => [[ 'type' => 'heading', 'data' => ['text' => $marker, 'level' => 'h2'] ]] ]],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $result = BloxTemplateImporter::importJson($json, 1);
    if ($conditions !== null) {
        db()->execute('UPDATE ' . DB_PREFIX . 'blox_templates SET conditions = ? WHERE id = ?', [$conditions, $result['id']]);
    }
    bloxTemplateModel()->publishDraft($result['id']);
    return (int) $result['id'];
}

function dropTemplate(int $id): void
{
    db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_templates WHERE id = ?', [$id]);
}

// 上下文素材：任一栏目页 + 任一单页（page 类型栏目）
$listChannel = (int) (db()->fetchOne('SELECT id FROM ' . DB_PREFIX . "channels WHERE type = 'list' LIMIT 1")['id'] ?? 0);
// 单页对象须是「真实渲染」的 page 栏目——auto/url 跳转型（如 about 跳子页）在
// php -S 下会 301 到伪静态路径然后 404，测不到头尾。优先 blox-sandbox。
$pageChannel = db()->fetchOne(
    'SELECT id, slug FROM ' . DB_PREFIX . "channels WHERE type = 'page'"
    . " AND (redirect_type IS NULL OR redirect_type = '' OR redirect_type = 'none')"
    . " ORDER BY CASE WHEN slug = 'blox-sandbox' THEN 0 ELSE 1 END, id LIMIT 1"
);
$pageId = (int) ($pageChannel['id'] ?? 0);
$pagePath = '/page.php?id=' . $pageId;
$listPath = '/list.php?id=' . $listChannel;

echo "Blox 头尾激活生命周期冒烟\n";

// 0) 基线
$home = fetch('/');
check('基线：首页无 blox 头尾', !str_contains($home, 'yk-blox-'));
check('基线：原生 siteHeader 在场', str_contains($home, 'id="siteHeader"'));

// 1) home 条件：接管首页、不泄漏到栏目/单页
$idHome = makeTemplate('header', 'SMK-HOME-HEADER', '[{"main":"home"}]');
check('home 条件：首页接管', str_contains(fetch('/'), 'SMK-HOME-HEADER'));
check('home 条件：siteHeader 恰 1 个（无双头）', substr_count(fetch('/'), 'id="siteHeader"') === 1);
check('home 条件：栏目页不受影响', !str_contains(fetch($listPath), 'SMK-HOME-HEADER'));
check('home 条件：单页不受影响', $pageId === 0 || !str_contains(fetch($pagePath), 'SMK-HOME-HEADER'));

// 2) 单页条件（P1-a 回归：真实 page.php 上下文必须取到 page_id）
if ($pageId > 0) {
    $idPage = makeTemplate('header', 'SMK-PAGE-HEADER', '[{"main":"page","ids":[' . $pageId . ']}]');
    check('单页条件：目标单页接管（page.php 上下文）', str_contains(fetch($pagePath), 'SMK-PAGE-HEADER'));
    check('单页条件：首页不受影响（home 模板仍胜出）', str_contains(fetch('/'), 'SMK-HOME-HEADER'));
    check('单页条件：栏目页不受影响', !str_contains(fetch($listPath), 'SMK-PAGE-HEADER'));
    dropTemplate($idPage);
} else {
    echo "  - 跳过单页条件（装机无 page 类型栏目）\n";
}

// 3) exclude 否决：全站模板 + 排除首页 → 首页回落 home 模板之外的原生（此处删掉 home 模板后验证）
dropTemplate($idHome);
$idAnyExcl = makeTemplate('header', 'SMK-ANY-EXCL', '[{"main":"any"},{"main":"home","exclude":true}]');
check('exclude：首页被否决（原生头）', !str_contains(fetch('/'), 'SMK-ANY-EXCL'));
check('exclude：栏目页正常接管', $listChannel === 0 || str_contains(fetch($listPath), 'SMK-ANY-EXCL'));
dropTemplate($idAnyExcl);

// 4) fail-closed（P1-b 回归）：损坏条件不得全站接管
$idBroken = makeTemplate('header', 'SMK-BROKEN', '[{"main":"bogus"}]');
check('fail-closed：损坏条件模板不激活', !str_contains(fetch('/'), 'SMK-BROKEN'));
dropTemplate($idBroken);

// 5) footer 区域
$idFooter = makeTemplate('footer', 'SMK-FOOTER', '[{"main":"any"}]');
$homeF = fetch('/');
check('footer：全站接管', str_contains($homeF, 'SMK-FOOTER') && str_contains($homeF, 'yk-blox-footer'));
check('footer：原生 footer 让位（data-yk-footer 消失）', !str_contains($homeF, 'data-yk-footer'));

// 6) 旧主题回退：非 default 主题未挂壳 → 即使有已发布模板也走原生（红线 5 实证）
$settings = new SettingModel();
$originalTheme = (string) $settings->get('current_theme', 'default');
$settings->set('current_theme', 'business');
$bizHome = fetch('/');
check('旧主题回退：business 主题无 blox 注入', !str_contains($bizHome, 'yk-blox-'));
check('旧主题回退：页面仍正常渲染', str_contains($bizHome, '</html>'));
$settings->set('current_theme', $originalTheme ?: 'default');
dropTemplate($idFooter);

// 7) 终态恢复
$final = fetch('/');
check('终态：基线恢复（无 blox 残留）', !str_contains($final, 'yk-blox-') && str_contains($final, 'id="siteHeader"'));

echo $failures === 0 ? "BLOX AREA ACTIVATION OK\n" : "FAILURES: {$failures}\n";
exit($failures === 0 ? 0 : 1);
