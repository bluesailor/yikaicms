<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || realpath(dirname(ROOT_PATH)) !== realpath(sys_get_temp_dir())
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable runner site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/hooks.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite only');
}
$action = $argv[1] ?? '';
if ($action === 'errors') {
    foreach (glob(ROOT_PATH . '/storage/logs/error-*.log') ?: [] as $file) { echo file_get_contents($file); }
    exit;
}
$backup = ROOT_PATH . '/storage/about-language-backup.json';
$keys = ['site_lang', 'enabled_languages', 'html_cache_enabled', 'home_layout_active',
    'home_blox_data', 'home_blox_published', 'home_blox_history', 'home_blox_active',
    'home_about_title', 'home_about_content', 'home_about_tag_title', 'home_about_tag_desc', 'home_about_button', 'home_about_link'];
foreach (['en', 'ja', 'zh-CN'] as $language) {
    foreach (['title', 'content', 'tag_title', 'tag_desc', 'button', 'link'] as $name) {
        $keys[] = 'home_about_' . $name . '_' . $language;
    }
}
if ($action === 'hash') {
    $state = db()->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX . 'settings WHERE `key` IN (?, ?, ?, ?) ORDER BY `key`',
        ['home_blox_data', 'home_blox_published', 'home_blox_history', 'home_blox_active']);
    echo hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    exit;
}
if ($action === 'restore') {
    if (!is_file($backup)) { exit; }
    $rows = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    foreach ($keys as $key) { db()->delete('settings', '`key` = ?', [$key]); }
    foreach ($rows as $row) { db()->insert('settings', $row); }
    unlink($backup);
    exit;
}
if (!in_array($action, ['legacy', 'converted'], true) || is_file($backup)) {
    throw new RuntimeException('Invalid fixture action or unrestored state');
}
$rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'settings WHERE `key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
file_put_contents($backup, json_encode($rows, JSON_THROW_ON_ERROR));
foreach ($keys as $key) { db()->delete('settings', '`key` = ?', [$key]); }
$settings = ['site_lang' => 'zh-CN', 'enabled_languages' => '["zh-CN","en","ja"]',
    'html_cache_enabled' => '0', 'home_layout_active' => '0', 'home_blox_active' => '0', 'home_about_link' => '',
    'home_about_title' => '关于企业', 'home_about_content' => '为客户提供专业服务。',
    'home_about_tag_title' => '专业服务', 'home_about_tag_desc' => '品质与创新', 'home_about_button' => '了解更多',
    'home_about_title_en' => 'About our company', 'home_about_content_en' => 'Professional services for our customers.',
    'home_about_tag_title_en' => 'Professional service', 'home_about_tag_desc_en' => 'Quality and innovation', 'home_about_button_en' => 'Learn more',
    'home_about_title_ja' => '私たちについて', 'home_about_content_ja' => 'お客様に専門的なサービスを提供します。',
    'home_about_tag_title_ja' => '専門サービス', 'home_about_tag_desc_ja' => '品質と革新', 'home_about_button_ja' => '詳しく見る'];
settingModel()->saveBatch($settings);
$section = ['id' => 'home_s_1', 'type' => 'section', 'settings' => ['padding' => 'none'], 'columns' => [[
    'id' => 'home_c_1', 'elements' => [['id' => 'home_e_1', 'type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => true]]],
]]];
if ($action === 'converted') {
    $section = HomeAboutContent::toSection([], 'about_1f590307e82e', getChannelBySlug('about', true));
    $section['id'] = 'home_s_1';
    $section['columns'][0]['span'] = 7;
    $section['columns'][1]['span'] = 5;
    $strip = static function (array $element) use (&$strip): array {
        unset($element['data'][HomeAboutLocalization::KEY]);
        foreach ($element['data']['children'] ?? [] as $i => $child) {
            $element['data']['children'][$i] = $strip($child);
        }
        if (str_ends_with($element['id'], '_caption')) {
            $element['data']['html'] = str_replace('<p class="text-white m-0">', '<p class="text-white">', $element['data']['html']);
        }
        return $element;
    };
    foreach ($section['columns'] as &$column) { $column['elements'] = array_map($strip, $column['elements']); }
    unset($column);
}
$json = json_encode(['schema' => 1, 'sections' => [$section]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
HomeBloxDocument::saveDraft($json);
if ($action === 'converted') { HomeBloxDocument::publishDraft(); }
$channel = getChannelBySlug('about');
$urls = [];
foreach (['zh-CN', 'en', 'ja'] as $language) {
    $row = channelModel()->siblingForLang((int) $channel['id'], $language);
    $urls[$language] = [
        'href' => langPrefix($language) . channelUrl($row),
        'destination' => langPrefix($language) . channelUrl(pagePrimaryEditTarget($row)),
    ];
}
echo json_encode($urls, JSON_THROW_ON_ERROR);
