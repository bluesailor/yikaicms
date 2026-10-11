<?php

declare(strict_types=1);

/** 命令行夹具与网页诊断页共用：数据库文件、主题提醒原值、提醒级别、待执行迁移数。 @return array<string,mixed> */
function adminNoticeProbe(): array
{
    $path = defined('DB_PATH') ? (string) DB_PATH : '';
    return [
        'driver' => db()->getDriver(),
        'db' => $path !== '' ? (string) (realpath($path) ?: $path) : '',
        'size' => $path !== '' && is_file($path) ? filesize($path) : 0,
        'raw' => (string) db()->fetchColumn('SELECT value FROM ' . DB_PREFIX . "settings WHERE `key` = 'theme_updates_known'"),
        'notify' => UpdateNotice::notifyLevel(),
        'themes' => UpdateNotice::themeUpdates(),
        'pending' => pendingMigrationsCount(),
        'sapi' => PHP_SAPI,
    ];
}
