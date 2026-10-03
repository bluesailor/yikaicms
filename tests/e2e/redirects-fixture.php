<?php
declare(strict_types=1);
// 301 跳转 e2e 的清理：删掉测试期间建的全部跳转（一次性测试站专用）
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
if (($argv[1] ?? '') !== 'restore') throw new RuntimeException('Invalid action');
foreach (Redirects::list('', 10000) as $row) Redirects::delete($row['id']);
settingModel()->rotateHtmlCacheGeneration();
