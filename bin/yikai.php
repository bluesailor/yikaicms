<?php
/**
 * Yikai CMS - CLI 入口
 *
 * 用法：
 *   bin/yikai                  显示帮助
 *   bin/yikai <command> [...]  执行命令
 *
 * 命令通过扫描 includes/commands/*.php 注册（CLI::register()）。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

define('IK_CLI', true);
define('ROOT_PATH', dirname(__DIR__));

if (!file_exists(ROOT_PATH . '/config/config.php')) {
    fwrite(STDERR, "ERROR: config/config.php 不存在，先完成 /install/ 流程。\n");
    exit(1);
}

require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/CLI.php';

// 注册命令：扫 includes/commands/*.php，每个文件应调用 CLI::register()
foreach (glob(ROOT_PATH . '/includes/commands/*.php') ?: [] as $cmdFile) {
    require_once $cmdFile;
}

exit(CLI::dispatch($argv));
