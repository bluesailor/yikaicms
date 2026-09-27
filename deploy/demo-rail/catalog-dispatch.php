<?php
declare(strict_types=1);

// 共享目录入口补足子站伪静态回退：Nginx 的 /index.php 回退仍需进入各演示站。
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (is_string($requestPath) && preg_match('~^/(yikai-[a-z0-9-]+)/(.*)$~', $requestPath, $match)) {
    $segments = explode('/', rawurldecode($match[2]));
    $blocked = ['admin', 'api', 'member', 'install', 'bin', 'config', 'storage', 'migrations', 'overrides', 'recipes', '.git'];
    foreach ($segments as $segment) {
        if ($segment === '..' || str_contains($segment, "\0") || in_array(strtolower($segment), $blocked, true)) {
            http_response_code(404);
            exit;
        }
    }
    $entry = __DIR__ . '/../' . $match[1] . '/index.php';
    if (is_file($entry)) {
        $_SERVER['SCRIPT_NAME'] = '/' . $match[1] . '/index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        $_SERVER['SCRIPT_FILENAME'] = $entry;
        require $entry;
        exit;
    }
}
