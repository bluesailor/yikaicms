<?php

declare(strict_types=1);

define('ROOT_PATH', __DIR__);

require ROOT_PATH . '/config/config.php';

/**
 * @return never
 */
function contractFail(string $message): void
{
    fwrite(STDERR, "中文首页契约失败：{$message}\n");
    exit(1);
}

function contractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        contractFail($message);
    }
}

try {
    if (DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_PATH);
    } else {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
            DB_USER,
            DB_PASS
        );
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    contractFail('数据库连接失败：' . $e->getMessage());
}

$setting = $pdo->prepare('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ? LIMIT 1');
$setting->execute(['site_lang']);
$siteLang = (string) $setting->fetchColumn();
$setting->execute(['home_blox_published']);
$homeJson = (string) $setting->fetchColumn();
$home = json_decode($homeJson, true);

contractAssert(CMS_VERSION === '1.19.6', '安装包版本不是 1.19.6');
contractAssert($siteLang === 'zh-CN', '默认语言不是 zh-CN');
contractAssert(is_array($home), 'home_blox_published 不是有效 JSON');

$sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];
$sectionNames = [];
$blockTypes = [];
foreach ($sections as $section) {
    if (!is_array($section)) {
        continue;
    }
    $sectionNames[] = (string) ($section['name'] ?? '');
    foreach ((array) ($section['columns'] ?? []) as $column) {
        if (!is_array($column)) {
            continue;
        }
        foreach ((array) ($column['elements'] ?? []) as $element) {
            if (!is_array($element) || ($element['type'] ?? '') !== 'home-block') {
                continue;
            }
            $blockTypes[] = (string) ($element['data']['block_type'] ?? '');
        }
    }
}

contractAssert(count($sections) >= 7, '中文首页区块数量不足');
contractAssert(($blockTypes[0] ?? '') === 'banner', '首个首页区块不是 Banner');
foreach (['关于我们', '数据统计', '产品中心', '行动号召'] as $requiredSection) {
    contractAssert(in_array($requiredSection, $sectionNames, true), "缺少首页区块：{$requiredSection}");
}

$bannerQuery = $pdo->prepare(
    'SELECT title FROM ' . DB_PREFIX . 'banners WHERE position = ? AND lang = ? AND status = 1 ORDER BY sort_order, id'
);
$bannerQuery->execute(['home', 'zh-CN']);
$bannerTitles = array_map('strval', $bannerQuery->fetchAll(PDO::FETCH_COLUMN));
$expectedBanners = ['数字化转型解决方案', '专业的技术服务团队', '创新引领未来'];
contractAssert($bannerTitles === $expectedBanners, '中文 Banner 演示数据不一致');

$footerQuery = $pdo->query(
    "SELECT source_ref FROM " . DB_PREFIX . "blox_templates WHERE type = 'footer' AND source = 'builtin' ORDER BY source_ref"
);
$footerPresets = array_map('strval', $footerQuery->fetchAll(PDO::FETCH_COLUMN));
$expectedFooters = ['business-site-footer', 'clean-site-footer', 'minimal-site-footer'];
contractAssert($footerPresets === $expectedFooters, '内置网页尾模板不一致');

$themes = [];
foreach ((array) glob(ROOT_PATH . '/themes/*', GLOB_ONLYDIR) as $themeDir) {
    $themes[] = basename((string) $themeDir);
}
sort($themes, SORT_STRING);
$expectedThemes = ['business', 'default', 'minimal'];
contractAssert($themes === $expectedThemes, '安装包预置主题不一致');

$snapshot = [
    'version' => CMS_VERSION,
    'site_lang' => $siteLang,
    'home' => [
        'section_count' => count($sections),
        'section_names' => $sectionNames,
        'block_types' => $blockTypes,
    ],
    'banner_titles' => $bannerTitles,
    'footer_presets' => $footerPresets,
    'themes' => $themes,
];
$encoded = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
contractAssert(is_string($encoded), '无法生成首页数据快照');

echo '中文首页契约通过（' . DB_DRIVER . '，指纹 ' . hash('sha256', $encoded) . ")\n";
