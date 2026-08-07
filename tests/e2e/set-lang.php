<?php
/**
 * e2e 语言档辅助：切换一次性装机库的 admin_lang（zh-CN / en / ja）。
 * 仅 CLI 可执行；线上即使误分发也无法经 HTTP 触发。
 * 用法：php tests/e2e/set-lang.php en
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';

$lang = (string) ($argv[1] ?? '');
if (!in_array($lang, ['zh-CN', 'en', 'ja'], true)) {
    fwrite(STDERR, "usage: php tests/e2e/set-lang.php zh-CN|en|ja\n");
    exit(2);
}

$settings = new SettingModel();
$settings->set('admin_lang', $lang);
echo "admin_lang={$lang}\n";
