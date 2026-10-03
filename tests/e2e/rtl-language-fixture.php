<?php
/** 从右到左语言（阿拉伯语）前台冒烟夹具：启用 ar 作为附加语言，输出首页与产品页的阿语网址；restore 还原。 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable runner site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/hooks.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite only');
}
$backup = ROOT_PATH . '/storage/rtl-language-backup.json';
$keys = ['enabled_languages', 'html_cache_enabled'];
$action = $argv[1] ?? '';
if ($action === 'restore') {
    if (is_file($backup)) {
        settingModel()->saveBatch(json_decode((string) file_get_contents($backup), true, 16, JSON_THROW_ON_ERROR));
        unlink($backup);
    }
    cacheClear();
    exit;
}
if ($action !== 'seed' || is_file($backup)) {
    throw new RuntimeException('usage: seed|restore');
}
$previous = [];
foreach ($keys as $key) {
    $previous[$key] = (string) config($key, '');
}
file_put_contents($backup, json_encode($previous, JSON_THROW_ON_ERROR));
$site = (string) config('site_lang', 'zh-CN');
settingModel()->saveBatch(['enabled_languages' => json_encode(array_values(array_unique([$site, 'en', 'ar'])), JSON_THROW_ON_ERROR), 'html_cache_enabled' => '0']);
cacheClear();
echo json_encode(['home' => langUrl('/', 'ar'), 'products' => langUrl('/product.html', 'ar')], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
