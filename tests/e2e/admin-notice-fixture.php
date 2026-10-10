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
    // recordThemes() 吞掉写库异常（后台检测失败不该打断页面）；夹具要确认真的写进去了。
    // CI 上曾偶发没写进去、铃铛里没有主题项：数据库被占用时重试，最终失败就带着原因报错。
    $count = (int) ($argv[2] ?? 1);
    $deadline = microtime(true) + 10;
    $lastError = '';
    do {
        try {
            settingModel()->set('theme_updates_known', (string) json_encode(['count' => $count, 'checked_at' => time()]), 'system');
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
        }
        $stored = json_decode((string) db()->fetchColumn('SELECT value FROM ' . DB_PREFIX . "settings WHERE `key` = 'theme_updates_known'"), true);
        if (is_array($stored) && (int) ($stored['count'] ?? -1) === $count) {
            break;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    if (!is_array($stored) || (int) ($stored['count'] ?? -1) !== $count) {
        throw new RuntimeException('theme_updates_known not persisted: ' . ($lastError !== '' ? $lastError : 'no error'));
    }
    if (UpdateNotice::themeUpdates() !== $count) {
        throw new RuntimeException('theme notice hidden: update_notify_level=' . (string) config('update_notify_level', '') . ', dashboard_update_check=' . (string) config('dashboard_update_check', ''));
    }
} elseif ($action === 'clear') {
    settingModel()->set('theme_updates_known', '', 'system');
} else {
    throw new RuntimeException('usage: themes <n>|clear');
}
cacheClear();
echo "ok\n";
