<?php

declare(strict_types=1);

define('ROOT_PATH', __DIR__);
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/config/database.php';

$enabled = json_encode(['zh-CN', 'en'], JSON_UNESCAPED_SLASHES);
db()->execute(
    'UPDATE ' . DB_PREFIX . 'settings SET `value` = ? WHERE `key` = ?',
    [$enabled, 'enabled_languages']
);

fwrite(STDOUT, "Disabled-language fixture ready\n");
