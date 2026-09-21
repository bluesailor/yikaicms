<?php
declare(strict_types=1);

/** 无数据库、无写入的路由探针；安装前也能验证请求确实抵达 PHP。 */
final class RewriteProbe
{
    public static function respond(): void
    {
        // 只在安装期开放：装完之后它只剩一个作用——向扫描器确认"这是 YikaiCMS"，
        // 便于按版本筛目标。安装器在写 installed.lock 之前完成探测，故此门禁不损失功能。
        // 注意本方法在 init.php 顶部、语言初始化之前应答（未启用 en 的站点也能命中
        // /en/contact.html），调整调用位置前请确认 assets/js/rewrite-probe.js 的两点探测仍成立。
        if (file_exists(ROOT_PATH . '/installed.lock')) return;
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
