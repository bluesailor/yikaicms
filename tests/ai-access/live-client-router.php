<?php
/** Metadata-only trace around the unchanged formal MCP entry. Loopback tests only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !is_file(dirname(__DIR__,2).'/storage/.smoke-state-backup/manifest.json')) { http_response_code(404); exit; }
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path !== '/mcp/') return false;
$message = json_decode((string) file_get_contents('php://input'),true);
$trace = [
    'method'=>$message['method'] ?? null,'tool'=>$message['params']['name'] ?? null,
    'client'=>$message['params']['clientInfo']['name'] ?? null,
    'protocol'=>$message['params']['protocolVersion'] ?? $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? $message['params']['_meta']['MCP-Protocol-Version'] ?? null,
];
$tracePath = getenv('YK_LIVE_TRACE');
register_shutdown_function(static function () use ($trace,$tracePath): void {
    if (is_string($tracePath) && $tracePath !== '') file_put_contents($tracePath,json_encode($trace+['status'=>http_response_code(),'at'=>gmdate('c')])."\n",FILE_APPEND|LOCK_EX);
});
require dirname(__DIR__,2).'/mcp/index.php';
