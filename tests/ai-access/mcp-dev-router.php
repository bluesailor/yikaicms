<?php
/**
 * S1-04 隔离 MCP 原型入口：只给 `php -S 127.0.0.1:<port>` 用，连接 s103-* 合成 SQLite 测试库。
 * 不是产品入口，不随安装包发布，不读站点配置、不启动 session。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=<s103-*.sqlite> YK_S104_TRACE=<trace.jsonl> \
 *     php -S 127.0.0.1:38104 tests/ai-access/mcp-dev-router.php
 *
 * 每次请求在 trace 中追加一行协议观测（方法、版本头、_meta 版本、客户端名、工具、状态码），
 * 不记录 Authorization、令牌摘要、参数或正文。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';
require_once dirname(__DIR__, 2) . '/includes/ai-access/AiAccessIdentity.php';
require_once dirname(__DIR__, 2) . '/includes/ai-access/AiMcpServer.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/mcp') {
    http_response_code(404);
    return true;
}

$headers = [];
foreach (getallheaders() as $name => $value) $headers[strtolower((string) $name)] = (string) $value;
$started = hrtime(true);
try {
    $db = s103Boot();
    $identity = new AiAccessIdentity($db);
    $lookup = static fn(string $digest): ?array => $db->fetchOne('SELECT id, admin_id, token_hash, scopes, expires_at, revoked_at FROM ' . DB_PREFIX . 'api_tokens WHERE token_hash = ?', [$digest]);
    $clock = static fn(): int => time();
    $server = new AiMcpServer(
        static fn(string $authorization): AiAccessAuthContext => $identity->authenticate($authorization, $lookup, $clock()),
        new AiMcpTools($db, new AiProposalService($db), $clock(...)),
    );
    $response = $server->handle((string) ($_SERVER['REQUEST_METHOD'] ?? ''), $headers, (string) file_get_contents('php://input'));
    $trace = $server->trace;
} catch (Throwable) {
    $response = ['status' => 503, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"jsonrpc":"2.0","error":{"code":-32603,"message":"Service unavailable"}}'];
    $trace = ['status' => 503];
}

$tracePath = (string) getenv('YK_S104_TRACE');
if ($tracePath !== '') {
    $trace += ['accept' => $headers['accept'] ?? null, 'user_agent' => substr($headers['user-agent'] ?? '', 0, 80), 'ms' => intdiv(hrtime(true) - $started, 1000000), 'at' => gmdate('c')];
    file_put_contents($tracePath, json_encode($trace, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

header_remove('X-Powered-By');
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) header($name . ': ' . $value);
echo $response['body'];
return true;
