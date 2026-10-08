<?php
/**
 * 后台提醒铃铛（2.0.6）e2e 夹具：themes <n> 记一个「主题有更新」的检测结果；clear 清掉。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/UpdateNotice.php';

$action = (string) ($argv[1] ?? '');
if ($action === 'themes') {
    UpdateNotice::recordThemes((int) ($argv[2] ?? 1));
} elseif ($action === 'clear') {
    settingModel()->set('theme_updates_known', '', 'system');
} else {
    throw new RuntimeException('usage: themes <n>|clear');
}
cacheClear();
echo "ok\n";
