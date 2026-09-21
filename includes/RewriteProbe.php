<?php
declare(strict_types=1);

/** 无数据库、无写入的路由探针；安装前也能验证请求确实抵达 PHP。 */
final class RewriteProbe
{
    public static function respond(): void
    {
        $nonce = $_GET['__yk_route_probe'] ?? null;
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !in_array($path, ['/contact.html', '/en/contact.html'], true)) return;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['probe' => 'yikai-rewrite-v1', 'nonce' => $nonce, 'path' => $path]);
        exit;
    }

    public static function installMode(mixed $result): string
    {
        // 未检测、超时和无 JavaScript 均采用可访问的保守默认值；旧站默认不变。
        return $result === '1' ? 'pretty' : 'query';
    }
}
