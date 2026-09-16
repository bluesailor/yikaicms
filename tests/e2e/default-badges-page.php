<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$language = in_array($_GET['lang'] ?? '', ['en', 'ja'], true) ? $_GET['lang'] : 'zh-CN';
$labels = ['zh-CN'=>['15 年经验', '为企业提供可靠的数字化服务'],
    'en'=>['15 years of experience', 'Reliable digital services for your business'],
    'ja'=>['15 年の実績', '企業の成長を支える信頼できるデジタルサービス']][$language];
$GLOBALS['yikai_config_runtime_overrides'] = [
    'current_theme'=>'default', 'html_cache_enabled'=>'0', 'site_lang'=>$language,
    'home_about_tag_title'=>$labels[0], 'home_about_tag_desc'=>$labels[1],
    'blox_header_enabled'=>'0', 'blox_footer_enabled'=>'0',
];
require $root . '/includes/init.php';
require $root . '/themes/default/layouts/header.php';
echo '<main>';
$aboutChannel = null;
require $root . '/themes/default/blocks/about.php';
echo '<div class="container mx-auto px-4 grid grid-cols-1 md:grid-cols-3 gap-6">';
$item = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE lang = ? ORDER BY id LIMIT 1', [$language]);
if (!$item) throw new RuntimeException('Missing product fixture');
$item['is_recommend'] = 1;
$isProductType = true;
require $root . '/themes/default/partials/product-card.php';
echo '</div></main>';
require $root . '/themes/default/layouts/footer.php';
