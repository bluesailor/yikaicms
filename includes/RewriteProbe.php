<?php
declare(strict_types=1);

/** 无数据库、无写入的路由探针；安装前也能验证请求确实抵达 PHP。 */
final class RewriteProbe
{
    /**
     * 可应答的路径（均为挂载前缀剥掉之后的站内路径）。
     * 两个 .html 验证伪静态；/ 验证服务器「默认首页」确实交给了 index.php——
     * 面板把 index.html 排在前面或根本没写 index 时，首页会落到面板默认页或 404，
     * 而安装器本身在 /install/ 下照样能打开，不专门探一下站长直到装完才发现。
     */
    public const PATHS = ['/contact.html', '/en/contact.html', '/'];

    public static function respond(): void
    {
        // 只在安装期开放：装完之后它只剩一个作用——向扫描器确认"这是 YikaiCMS"，
        // 便于按版本筛目标。安装器在写 installed.lock 之前完成探测，故此门禁不损失功能。
        // 注意本方法在 init.php 顶部、语言初始化之前应答（未启用 en 的站点也能命中
        // /en/contact.html），调整调用位置前请确认 assets/js/rewrite-probe.js 的各探测点（见 PATHS）仍成立。
        if (file_exists(ROOT_PATH . '/installed.lock')) return;
        $nonce = $_GET['__yk_route_probe'] ?? null;
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !in_array($path, self::PATHS, true)
            || ($path === '/' && !self::servedByFrontController($_SERVER))) return;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['probe' => 'yikai-rewrite-v1', 'nonce' => $nonce, 'path' => $path]);
        exit;
    }

    /**
     * 首页必须由根目录 index.php 应答才算数。面板把 index.html 排前面时，nginx 会把 /
     * 内部改写成 /index.html 再被单页规则交给 page.php——REQUEST_URI 仍是 /，装前 page.php
     * 同样会走到这里；若不核对入口文件，探针报「正常」而装完首页 404。
     *
     * @param array<string,mixed> $server
     */
    public static function servedByFrontController(array $server): bool
    {
        $script = is_string($server['SCRIPT_FILENAME'] ?? null) ? realpath($server['SCRIPT_FILENAME']) : false;
        return $script !== false && $script === realpath(ROOT_PATH . '/index.php');
    }

    public static function installMode(mixed $result): string
    {
        // 未检测、超时和无 JavaScript 均采用可访问的保守默认值；旧站默认不变。
        return $result === '1' ? 'pretty' : 'query';
    }
}
