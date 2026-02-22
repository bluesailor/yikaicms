<?php
/**
 * Yikai CMS - 配置文件模板
 *
 * 安装时复制为 config.php
 * PHP 8.0+
 */

declare(strict_types=1);

// 防止直接访问
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// ============================================================
// 调试模式（生产环境设为 false）
// ============================================================
define('DEBUG', true);

// ============================================================
// 数据库配置
// ============================================================
// 数据库类型：mysql 或 sqlite
define('DB_DRIVER', '{{DB_DRIVER}}');

// MySQL 配置（使用 SQLite 时忽略）
define('DB_HOST', '{{DB_HOST}}');
define('DB_PORT', '{{DB_PORT}}');
define('DB_NAME', '{{DB_NAME}}');
define('DB_USER', '{{DB_USER}}');
define('DB_PASS', '{{DB_PASS}}');
define('DB_CHARSET', 'utf8mb4');

// SQLite 配置（使用 MySQL 时忽略）
define('DB_PATH', ROOT_PATH . '/storage/database.sqlite');

// 表前缀
define('DB_PREFIX', 'yikai_');

// ============================================================
// 系统版本
// ============================================================
define('CMS_VERSION', '1.1.0');

// ============================================================
// 站点配置
// ============================================================
define('SITE_NAME', '{{SITE_NAME}}');
define('SITE_URL', '{{SITE_URL}}');

// ============================================================
// 路径配置
// ============================================================
define('CONFIG_PATH', ROOT_PATH . '/config/');
define('INCLUDES_PATH', ROOT_PATH . '/includes/');
define('ADMIN_PATH', ROOT_PATH . '/admin/');
define('THEMES_PATH', ROOT_PATH . '/themes/');
define('UPLOADS_PATH', ROOT_PATH . '/uploads/');
define('STORAGE_PATH', ROOT_PATH . '/storage/');

// ============================================================
// 上传配置
// ============================================================
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);  // 10MB
define('UPLOAD_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
define('UPLOAD_FILE_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z']);

// ============================================================
// 安全配置
// ============================================================
define('ADMIN_LOGIN_PATH', '/admin/login.php');
define('SESSION_NAME', 'IKAICMS_SESSION');
define('CSRF_TOKEN_NAME', '_token');

// ============================================================
// 时区
// ============================================================
date_default_timezone_set('Asia/Shanghai');

// ============================================================
// Session 配置
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Lax',
    ]);
}

// ============================================================
// 错误处理
// ============================================================
if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// ============================================================
// 加载数据库类
// ============================================================
require_once CONFIG_PATH . 'database.php';
