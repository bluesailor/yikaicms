<?php
/**
 * 子目录部署夹具：用 php -S 模拟 nginx「每个一级子目录一个站」（deploy/nginx-subdirectories.conf）。
 *
 *   php -S 127.0.0.1:8791 tests/smoke/subdir-router.php
 *   文档根 = 本文件所在站点的上上上级目录，即放着 sub/、sub2/ 这类站点目录的那一层；
 *   也可用环境变量 YK_SUBDIR_DOCROOT 指定。
 *
 * 对每个请求：第一段路径是一个装好的站点目录（里面有 index.php）才受理；静态文件直出，
 * 真实 PHP 文件直接执行，其余交给该站的 index.php。SCRIPT_NAME / SCRIPT_FILENAME / PHP_SELF
 * 设成 nginx 会给的值——BasePath 靠它们推导挂载点。
 * 只用于本机测试（tests/smoke/subdir-link-audit.js、subdir-session-isolation.js）。
 */
$docroot = rtrim((string) (getenv('YK_SUBDIR_DOCROOT') ?: dirname(__DIR__, 3)), '/\\');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mount = preg_match('~^/([A-Za-z0-9._-]+)(?:/|$)~', $path, $m) && is_file($docroot . '/' . $m[1] . '/index.php') ? '/' . $m[1] : '';
if ($mount === '') {
    http_response_code(404);
    echo 'not a site directory';
    return true;
}
$file = $docroot . $path;
if (is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
if (str_ends_with($path, '/') && is_file($file . 'index.php')) {
    $path .= 'index.php';
    $file .= 'index.php';
}
$script = (is_file($file) && str_ends_with($file, '.php')) ? $path : $mount . '/index.php';
$_SERVER['SCRIPT_NAME'] = $script;
$_SERVER['PHP_SELF'] = $script;
$_SERVER['SCRIPT_FILENAME'] = $docroot . $script;
chdir(dirname($docroot . $script));
require $docroot . $script;
