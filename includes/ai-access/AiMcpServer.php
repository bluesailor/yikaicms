<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';
require_once __DIR__ . '/AiAccessThrottle.php';
require_once __DIR__ . '/AiMcpTools.php';

/**
 * MCP Streamable HTTP 协议层（ADR-003）：无状态、只回 application/json，不发 SSE、不发会话 ID。
 * 同一入口按请求形态区分两代协议：带 _meta 协议版本的是 2026-07-28（逐请求元数据），
 * initialize 及其后续请求是 2025-11-25（旧式握手，本原型不保存会话，握手只用于协商版本）。
 * 本类不读超全局变量、不启动 session、不输出；入口负责把 HTTP 请求转成参数并原样写回结果。
 */
final class AiMcpServer
{
    public const MODERN = ['2026-07-28'];
    public const LEGACY = ['2025-11-25', '2025-06-18'];
    public const MAX_REQUEST_BYTES = 256000;
    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_CAPS = 'io.modelcontextprotocol/clientCapabilities';
    private const SERVER_INFO = ['name' => 'yikaicms', 'version' => '2.2.0'];

    /** 每次请求的协议观测（不含凭据/正文），供入口写诊断日志。 @var array<string,mixed> */
    public array $trace = [];

    /**
     * @param list<string> $allowedOrigins 浏览器来源白名单；空表示拒绝所有带 Origin 的请求
     * @param Closure(string):AiAccessAuthContext $authenticate 接收 Authorization 原值，失败抛 ai_access_* 异常
     * @param ?AiAccessThrottle $throttle 为 null 时不限流（仅隔离测试）；存储故障按 503 拒绝
     */
    public function __construct(
        private Closure $authenticate,
        private AiMcpTools $tools,
        private array $allowedOrigins = [],
        private ?AiAccessThrottle $throttle = null,
        private string $clientIp = '',
        private int $now = 0,
    ) {
    }

    /**
     * @param array<string,string> $headers 小写头名
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function handle(string $httpMethod, array $headers, string $body): array
    {
        $this->trace = ['http' => $httpMethod, 'header_version' => $headers['mcp-protocol-version'] ?? null,
            'header_method' => $headers['mcp-method'] ?? null, 'session_header' => isset($headers['mcp-session-id'])];
        if ($httpMethod !== 'POST') {
            // 2026-07-28 无 GET 流与会话终止；旧客户端的 GET/DELETE 按规范回 405。
            return $this->reply(405, null, ['Allow' => 'POST']);
        }
        $origin = $headers['origin'] ?? null;
        if ($origin !== null && !in_array($origin, $this->allowedOrigins, true)) {
            return $this->reply(403, self::error(null, -32600, 'Origin not allowed'));
        }
        if (strlen($body) > self::MAX_REQUEST_BYTES) return $this->reply(413, self::error(null, -32600, 'Request too large'));
        if (preg_match('#^application/json\s*(;|$)#i', $headers['content-type'] ?? '') !== 1) {
            return $this->reply(415, self::error(null, -32600, 'Content-Type must be application/json'));
        }
        $accept = strtolower($headers['accept'] ?? '');
        if (!str_contains($accept, 'application/json') && !str_contains($accept, '*/*')) {
            return $this->reply(406, self::error(null, -32600, 'Accept must include application/json'));
        }

        // 认证先于解析：未认证请求看不到任何协议细节。没有 cookie/查询串/旧 API Key 回退。
        // 来源失败计数只在凭据无效（401）时累加、超限时把这次失败改回 429；有效令牌永远不看这个计数，
        // 否则共用出口（CDN、NAT、未配置可信代理）后面任何人刷错误令牌都能挡住所有正常客户端。
        // 403（总开关关闭、角色无权限）是配置问题，不算猜测凭据，不计数。
        try {
            $context = ($this->authenticate)($headers['authorization'] ?? '');
        } catch (RuntimeException $error) {
            $code = $error->getCode();
            $this->trace['auth'] = $error->getMessage();
            if ($code === 401) {
                try {
                    if ($this->throttle !== null && !$this->throttle->hit(AiAccessThrottle::failBucket($this->clientIp), AiAccessThrottle::AUTH_FAIL_PER_MINUTE, $this->clock())) {
                        return $this->limited();
                    }
                } catch (RuntimeException) {
                    return $this->reply(503, self::error(null, -32603, 'Service unavailable'));
                }
                return $this->reply(401, self::error(null, -32001, 'Unauthorized'), ['WWW-Authenticate' => 'Bearer realm="yikaicms-mcp", error="invalid_token"']);
            }
            if ($code === 403) return $this->reply(403, self::error(null, -32003, 'Forbidden'));
            return $this->reply(503, self::error(null, -32603, 'Service unavailable'));
        }
        $this->trace['actor'] = $context->actorId;
        $this->trace['token_id'] = $context->tokenId;

        try {
            $message = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return $this->reply(400, self::error(null, -32700, 'Parse error'));
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0' || !is_string($message['method'] ?? null)) {
            // 也拒绝 JSON-RPC 批量数组：两代协议都已移除批量。
            return $this->reply(400, self::error(null, -32600, 'Invalid request'));
        }
        $method = $message['method'];
        $this->trace['method'] = $method;
        $hasId = array_key_exists('id', $message);
        $id = $hasId ? $message['id'] : null;
        if ($hasId && !is_string($id) && !is_int($id)) return $this->reply(400, self::error(null, -32600, 'Invalid request id'));
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) return $this->reply(400, self::error($id, -32602, 'Invalid params'));
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        // 每个已认证请求都计入令牌的读取额度；提案另有更严的额度。
        $kind = $method === 'tools/call' && ($params['name'] ?? null) === 'article_draft_propose' ? 'propose' : 'read';
        try {
            $limit = $kind === 'propose' ? AiAccessThrottle::PROPOSE_PER_MINUTE : AiAccessThrottle::READ_PER_MINUTE;
            if ($this->throttle !== null && !$this->throttle->hit('token:' . $context->tokenId . ':' . $kind, $limit, $this->clock())) return $this->limited($id);
        } catch (RuntimeException) {
            return $this->reply(503, self::error($id, -32603, 'Service unavailable'));
        }

        if (!$hasId) {
            // 通知：接受即 202 无正文；不处理任何通知内容。
            return str_starts_with($method, 'notifications/') ? $this->reply(202, null) : $this->reply(400, self::error(null, -32600, 'Invalid request'));
        }
        if (array_key_exists(self::META_VERSION, $meta)) return $this->modern($id, $method, $params, $meta, $headers, $context);
        return $this->legacy($id, $method, $params, $headers, $context);
    }

    /** @param array<string,mixed> $params @param array<string,mixed> $meta @param array<string,string> $headers */
    private function modern(string|int $id, string $method, array $params, array $meta, array $headers, AiAccessAuthContext $context): array
    {
        $version = $meta[self::META_VERSION];
        $this->trace['era'] = 'modern';
        $this->trace['meta_version'] = is_string($version) ? $version : null;
        $this->trace['client'] = self::clientInfo($meta['io.modelcontextprotocol/clientInfo'] ?? null);
        if (!is_string($version) || !in_array($version, self::MODERN, true)) {
            return $this->reply(400, self::error($id, -32022, 'Unsupported protocol version',
                ['supported' => array_merge(self::MODERN, self::LEGACY), 'requested' => is_string($version) ? $version : null]));
        }
        if (($headers['mcp-protocol-version'] ?? null) !== $version || ($headers['mcp-method'] ?? null) !== $method) {
            return $this->reply(400, self::error($id, -32020, 'Header mismatch'));
        }
        if (!is_array($meta[self::META_CAPS] ?? null)) return $this->reply(400, self::error($id, -32602, 'Missing client capabilities'));
        if ($method === 'tools/call' && self::decodeName($headers['mcp-name'] ?? null) !== ($params['name'] ?? null)) {
            return $this->reply(400, self::error($id, -32020, 'Header mismatch'));
        }
        $result = match ($method) {
            'server/discover' => ['supportedVersions' => array_merge(self::MODERN, self::LEGACY), 'capabilities' => ['tools' => (object) []],
                'instructions' => 'YikaiCMS article drafts: proposals are reviewed by a site editor before anything is saved.'],
            'tools/list' => ['tools' => $this->tools->definitions($context), 'ttlMs' => 0, 'cacheScope' => 'private'],
            'tools/call' => $this->call($params, $context),
            default => null,
        };
        if ($result === null) return $this->reply(404, self::error($id, -32601, 'Method not found'));
        if ($result instanceof AiMcpProtocolError) return $this->reply(200, self::error($id, $result->getCode(), $result->getMessage()));
        $result['resultType'] = 'complete';
        $result['_meta'] = ['io.modelcontextprotocol/serverInfo' => self::SERVER_INFO];
        return $this->result($id, $result);
    }

    /** @param array<string,mixed> $params @param array<string,string> $headers */
    private function legacy(string|int $id, string $method, array $params, array $headers, AiAccessAuthContext $context): array
    {
        $this->trace['era'] = 'legacy';
        if ($method === 'initialize') {
            $requested = $params['protocolVersion'] ?? null;
            $this->trace['meta_version'] = is_string($requested) ? $requested : null;
            $this->trace['client'] = self::clientInfo($params['clientInfo'] ?? null);
            // 客户端请求的版本受支持则回同一版本，否则回本端最新旧版本，由客户端决定是否断开。不发 Mcp-Session-Id。
            $version = is_string($requested) && in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0];
            return $this->result($id, ['protocolVersion' => $version, 'capabilities' => ['tools' => (object) []], 'serverInfo' => self::SERVER_INFO,
                'instructions' => 'YikaiCMS article drafts: proposals are reviewed by a site editor before anything is saved.']);
        }
        // 握手之后的请求必须带协商好的版本头；缺头不按 2025-03-26 猜测，直接拒绝。
        $version = $headers['mcp-protocol-version'] ?? '';
        if (!in_array($version, self::LEGACY, true)) {
            return $this->reply(400, self::error($id, -32600, 'Unsupported or missing MCP-Protocol-Version',
                ['supported' => array_merge(self::MODERN, self::LEGACY), 'requested' => $version === '' ? null : $version]));
        }
        $result = match ($method) {
            'ping' => [],
            'tools/list' => ['tools' => $this->tools->definitions($context)],
            'tools/call' => $this->call($params, $context),
            default => null,
        };
        if ($result === null) return $this->reply(200, self::error($id, -32601, 'Method not found'));
        if ($result instanceof AiMcpProtocolError) return $this->reply(200, self::error($id, $result->getCode(), $result->getMessage()));
        return $this->result($id, $result === [] ? (object) [] : $result);
    }

    /**
     * 未在当前身份的工具清单里出现的名称一律按“未知工具”处理，不暴露其存在与否。
     * @param array<string,mixed> $params
     * @return array<string,mixed>|AiMcpProtocolError
     */
    private function call(array $params, AiAccessAuthContext $context): array|AiMcpProtocolError
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || !$context->allowsTool($name)) return new AiMcpProtocolError('Unknown tool', -32602);
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) return new AiMcpProtocolError('Invalid arguments', -32602);
        $this->trace['tool'] = $name;
        $this->trace['target'] = self::target($name, $arguments);
        try {
            $data = $this->tools->call($name, $arguments, $context);
            $this->trace['tool_result'] = 'ok';
            return self::toolResult($data, false);
        } catch (RuntimeException $error) {
            // 业务失败是工具结果（isError），不是协议错误；只返回稳定代码，不带底层异常文本。
            $code = str_starts_with($error->getMessage(), 'ai_') ? $error->getMessage() : 'ai_mcp_unavailable';
            $this->trace['tool_result'] = $code;
            return self::toolResult(['error' => $code], true);
        }
    }

    /** 审计用的目标摘要：只记 ID/语言/条数，不记标题、正文或幂等键。 */
    private static function target(string $tool, array $arguments): string
    {
        return match ($tool) {
            'article_read' => 'article:' . (is_int($arguments['id'] ?? null) ? $arguments['id'] : '?'),
            'article_list', 'article_channel_list' => 'lang:' . (is_string($arguments['lang'] ?? null) ? substr($arguments['lang'], 0, 10) : '?'),
            'article_draft_propose' => 'items:' . (is_array($arguments['items'] ?? null) ? count($arguments['items']) : 0),
            default => '',
        };
    }

    private function limited(string|int|null $id = null): array
    {
        $this->trace['auth'] ??= 'rate_limited';
        return $this->reply(429, self::error($id, -32005, 'Rate limit exceeded'), ['Retry-After' => (string) AiAccessThrottle::retryAfter($this->clock())]);
    }

    private function clock(): int
    {
        return $this->now > 0 ? $this->now : time();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function toolResult(array $data, bool $isError): array
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['content' => [['type' => 'text', 'text' => $json]], 'structuredContent' => $data, 'isError' => $isError];
    }

    private function result(string|int $id, array|object $result): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > AiMcpTools::MAX_RESULT_BYTES) {
            // 工具层已分段；超限说明分段约束失效，宁可报错也不截断 JSON/UTF-8。
            return $this->reply(200, self::error($id, -32603, 'Result too large'));
        }
        $this->trace['status'] = 200;
        return ['status' => 200, 'headers' => self::baseHeaders(), 'body' => $body];
    }

    /** @param array<string,mixed>|null $payload @param array<string,string> $extra */
    private function reply(int $status, ?array $payload, array $extra = []): array
    {
        $this->trace['status'] = $status;
        if ($payload !== null && isset($payload['error']['code'])) $this->trace['error'] = $payload['error']['code'];
        $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = array_merge($payload === null ? ['Cache-Control' => 'no-store'] : self::baseHeaders(), $extra);
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /** @return array<string,string> */
    private static function baseHeaders(): array
    {
        return ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
    }

    /** @return array<string,mixed> */
    private static function error(string|int|null $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) $error['data'] = $data;
        $out = ['jsonrpc' => '2.0', 'error' => $error];
        if ($id !== null) $out = ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
        return $out;
    }

    /** Mcp-Name 非安全字符按 =?base64?...?= 编码。 */
    private static function decodeName(?string $value): ?string
    {
        if ($value === null) return null;
        if (preg_match('/^=\?base64\?([A-Za-z0-9+\/=]*)\?=$/D', $value, $m) === 1) {
            $decoded = base64_decode($m[1], true);
            return $decoded === false ? null : $decoded;
        }
        return $value;
    }

    private static function clientInfo(mixed $info): ?string
    {
        if (!is_array($info) || !is_string($info['name'] ?? null)) return null;
        $label = $info['name'] . (is_string($info['version'] ?? null) ? '/' . $info['version'] : '');
        return substr(preg_replace('/[^\x20-\x7E]/', '?', $label) ?? '', 0, 80);
    }
}

/** 协议层错误（JSON-RPC error），区别于工具业务失败。 */
final class AiMcpProtocolError extends RuntimeException
{
}
