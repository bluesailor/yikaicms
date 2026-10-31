<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessRuntime.php';
require_once __DIR__ . '/AiAccessTokenStore.php';
require_once __DIR__ . '/AiAccessLog.php';
require_once __DIR__ . '/AiMcpServer.php';
require_once dirname(__DIR__) . '/ClientIpResolver.php';

/**
 * mcp/index.php 的实现（ADR-001/003）：不加载 init.php、functions.php 或后台 auth，不启动 session。
 * 失败一律回固定 JSON，不带异常文本或路径。
 */
final class AiMcpEntry
{
    public static function run(string $root): void
    {
        $started = hrtime(true);
        $requestId = bin2hex(random_bytes(16));
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $headers = self::headers();
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $logged = null;
        try {
            $identity = AiAccessRuntime::boot($root);
            $db = db();
            $now = time();
            $trusted = (string) ($db->fetchColumn('SELECT `value` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', ['trusted_proxies']) ?: '');
            $ip = ClientIpResolver::resolve($_SERVER, $trusted);
            $throttle = new AiAccessThrottle($db);
            if (!self::secure($remote, $trusted, (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
                $response = self::fixed(403, 'HTTPS required');
                // 记一行审计，站长才能看出客户端为什么连不上（多半是反向代理没配可信代理）；
                // 与凭据失败共用来源计数，超限后不再写，明文请求不能无限写库。
                if ($throttle->hit(AiAccessThrottle::failBucket($ip), AiAccessThrottle::AUTH_FAIL_PER_MINUTE, $now)) {
                    $logged = self::audit(new AiAccessLog($db), $requestId, ['status' => 403, 'auth' => 'https_required', 'http' => $method], $ip, $started, $now);
                }
            } else {
                $store = new AiAccessTokenStore($db);
                $authenticate = static function (string $authorization) use ($identity, $store, $ip, $now): AiAccessAuthContext {
                    $context = $identity->authenticate($authorization, $store->findByDigest(...), $now);
                    try {
                        $store->touch($context->tokenId, $ip, $now);
                    } catch (Throwable) {
                        // 最近使用时间只是提示信息，写失败不影响本次授权结果。
                    }
                    return $context;
                };
                $server = new AiMcpServer($authenticate, new AiMcpTools($db, new AiProposalService($db), static fn(): int => time()),
                    throttle: $throttle, clientIp: $ip, now: $now);
                $body = self::body();
                $response = $body === null ? self::fixed(413, 'Request too large') : $server->handle($method, $headers, $body);
                // 旧式握手只在 initialize 里带 clientInfo，后续请求用 User-Agent 补客户端名。
                $trace = $server->trace;
                if (($trace['client'] ?? null) === null) $trace['client'] = $headers['user-agent'] ?? '';
                $logged = self::audit(new AiAccessLog($db), $requestId, $trace, $ip, $started, $now);
            }
        } catch (Throwable) {
            $response = self::fixed(503, 'Service unavailable');
        }
        if ($logged === false) $response = self::fixed(503, 'Service unavailable');

        header_remove('X-Powered-By');
        http_response_code($response['status']);
        foreach ($response['headers'] + ['X-Request-Id' => $requestId] as $name => $value) header($name . ': ' . $value);
        echo $response['body'];
    }

    /**
     * 已认证请求与认证失败各记一行；认证前就被拒的（方法/报文/超限）不记，免得无凭据请求能无限写日志。
     * 返回 false 表示审计写入失败：除已提交的提案外，这次结果不交给客户端。
     */
    private static function audit(AiAccessLog $log, string $requestId, array $trace, string $ip, int $started, int $now): ?bool
    {
        $authenticated = isset($trace['actor']);
        if (!$authenticated && !in_array($trace['status'] ?? 0, [401, 403], true)) return null;
        try {
            $log->record($requestId, $trace, $ip, intdiv(hrtime(true) - $started, 1000000), $now);
            return true;
        } catch (Throwable) {
            return ($trace['tool'] ?? '') === 'article_draft_propose' && ($trace['tool_result'] ?? '') === 'ok' ? null : false;
        }
    }

    /**
     * 直连 HTTPS；或来自可信代理且声明 https；或本机回环直连本机地址（本地客户端与测试）。
     * 回环豁免还要求 Host 是本机名：同机反向代理或内网穿透把外网请求以 http 转到 127.0.0.1 时，
     * Host 是公网域名，即使代理没加 X-Forwarded-For 也不会被当成本机客户端放过明文令牌。
     */
    private static function secure(string $remote, string $trusted, string $host): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
        if ($trusted !== '' && ClientIpResolver::matchesAny($remote, $trusted)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
        return in_array($remote, ['127.0.0.1', '::1'], true) && !isset($_SERVER['HTTP_X_FORWARDED_FOR']) && self::loopbackHost($host);
    }

    private static function loopbackHost(string $host): bool
    {
        $name = strtolower(preg_replace('/:\d+$/', '', trim($host)) ?? '');
        return in_array($name, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($name, '.localhost');
    }

    /** @return array<string,string> 小写头名；Apache CGI/FastCGI 下 Authorization 由改写规则放进 HTTP_AUTHORIZATION。 */
    private static function headers(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) continue;
            if (str_starts_with($key, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        if (($headers['authorization'] ?? '') === '' && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null)) {
            $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }

    /**
     * 最多读上限 + 1 字节，超限不把整个请求体读进内存。超限时把剩余部分读掉丢弃（有硬上限），
     * 让 FastCGI 前端能把请求体写完、拿到 413，而不是等写超时。
     */
    private static function body(): ?string
    {
        $stream = fopen('php://input', 'rb');
        if ($stream === false) return '';
        $body = (string) stream_get_contents($stream, AiMcpServer::MAX_REQUEST_BYTES + 1);
        if (strlen($body) > AiMcpServer::MAX_REQUEST_BYTES) {
            for ($drained = 0; $drained < 4 * 1048576 && !feof($stream); $drained += strlen((string) fread($stream, 65536)));
            fclose($stream);
            return null;
        }
        fclose($stream);
        return $body;
    }

    /** @return array{status:int,headers:array<string,string>,body:string} */
    private static function fixed(int $status, string $message): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            'body' => json_encode(['jsonrpc' => '2.0', 'error' => ['code' => $status === 503 ? -32603 : -32600, 'message' => $message]], JSON_THROW_ON_ERROR)];
    }
}
