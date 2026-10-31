<?php
/**
 * S1-04 MCP 协议层的 HTTP 行为验证：自起 `php -S` 跑 mcp-dev-router.php，经真实 HTTP 发请求，
 * 不调用任何模型。验证两代协议、认证与权限、输入/输出边界、只读无写入和不泄漏。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=<临时目录>/s103-mcp-run.sqlite php tests/ai-access/mcp-protocol-run.php [--out=report.json]
 *
 * 退出码：0 = 全部通过；1 = 有断言失败；2 = 环境错误。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';

final class S104Run
{
    public Database $db;
    /** @var array<string,list<array{0:bool,1:string}>> */
    public array $results = [];
    public string $scenario = '';
    /** @var list<string> 全部响应正文，供泄漏检查 */
    public array $bodies = [];
    public string $editor = '';
    public string $reader = '';
    private string $url = '';
    /** @var resource|null */
    private $server = null;
    private string $tmp;
    private string $trace;
    private int $id = 0;

    public function __construct()
    {
        $this->tmp = sys_get_temp_dir() . '/s104-run-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700);
        $this->trace = $this->tmp . '/trace.jsonl';
        $this->db = s103Boot();
    }

    public function seed(): void
    {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/mcp-dev-seed.php') . ' --token-dir=' . escapeshellarg($this->tmp), $out, $code);
        if ($code !== 0) throw new RuntimeException('seed failed');
        $this->editor = (string) file_get_contents($this->tmp . '/editor.token');
        $this->reader = (string) file_get_contents($this->tmp . '/reader.token');
    }

    public function start(): void
    {
        $port = 0;
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe !== false) {
            $port = (int) substr((string) stream_socket_get_name($probe, false), strrpos((string) stream_socket_get_name($probe, false), ':') + 1);
            fclose($probe);
        }
        if ($port === 0) throw new RuntimeException('no free port');
        $env = array_merge(getenv(), ['YK_S104_TRACE' => $this->trace]);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/mcp-dev-router.php'];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->url = 'http://127.0.0.1:' . $port . '/mcp';
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('dev server did not start');
    }

    public function stop(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        foreach (glob($this->tmp . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->tmp);
    }

    public function traceText(): string
    {
        return is_file($this->trace) ? (string) file_get_contents($this->trace) : '';
    }

    public function check(bool $ok, string $label): void
    {
        $this->results[$this->scenario][] = [$ok, $label];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,raw_headers:list<string>,body:string,json:mixed}
     */
    public function http(string $method, string $body, array $headers, string $query = ''): array
    {
        $lines = [];
        foreach ($headers as $name => $value) $lines[] = $name . ': ' . $value;
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $lines), 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
        $response = @file_get_contents($this->url . $query, false, $context);
        $raw = $http_response_header ?? [];
        $status = preg_match('#^HTTP/\S+ (\d{3})#', $raw[0] ?? '', $m) === 1 ? (int) $m[1] : 0;
        $parsed = [];
        foreach (array_slice($raw, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $parsed[strtolower(trim($name))] = trim($value);
        }
        $text = $response === false ? '' : $response;
        $this->bodies[] = $text;
        return ['status' => $status, 'headers' => $parsed, 'raw_headers' => $raw, 'body' => $text, 'json' => json_decode($text, true)];
    }

    /** 2026-07-28 请求：_meta + MCP-Protocol-Version + Mcp-Method（tools/call 再带 Mcp-Name）。 */
    public function modern(string $method, array $params = [], ?string $token = null, array $override = []): array
    {
        $params['_meta'] = $params['_meta'] ?? ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) [],
            'io.modelcontextprotocol/clientInfo' => ['name' => 's104-runner', 'version' => '1']];
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . ($token ?? $this->editor),
            'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if ($method === 'tools/call') $headers['Mcp-Name'] = (string) ($params['name'] ?? '');
        $headers = array_filter(array_merge($headers, $override), static fn($v): bool => $v !== null);
        return $this->http('POST', (string) json_encode(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params]), $headers);
    }

    /** 旧式请求：initialize 后带协商版本头，无 _meta。 */
    public function legacy(string $method, array $params = [], ?string $version = '2025-11-25', ?string $token = null, bool $notification = false): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . ($token ?? $this->editor)];
        if ($version !== null) $headers['MCP-Protocol-Version'] = $version;
        $message = ['jsonrpc' => '2.0', 'method' => $method];
        if (!$notification) $message['id'] = ++$this->id;
        if ($params !== []) $message['params'] = $params;
        return $this->http('POST', (string) json_encode($message), $headers);
    }

    /** tools/call 的工具结果（structuredContent）；协议错误时返回 null。 */
    public function tool(string $name, array $arguments = [], ?string $token = null): array
    {
        $r = $this->modern('tools/call', ['name' => $name, 'arguments' => (object) $arguments], $token);
        $result = $r['json']['result'] ?? null;
        return ['http' => $r, 'error' => (bool) ($result['isError'] ?? false), 'data' => $result['structuredContent'] ?? null,
            'text' => $result['content'][0]['text'] ?? null, 'rpc_error' => $r['json']['error']['code'] ?? null];
    }

    /** 业务表指纹：只读工具前后必须一致。 */
    public function fingerprint(): string
    {
        $p = S103_PREFIX;
        $parts = [];
        foreach (array_merge(S103_CORE_TABLES, S103_PROTOTYPE_TABLES) as $t) {
            $parts[$t] = $this->db->fetchAll("SELECT * FROM {$p}{$t} ORDER BY 1");
        }
        return hash('sha256', serialize($parts));
    }

    public function setting(string $key, string $value): void
    {
        $this->db->execute('UPDATE ' . S103_PREFIX . 'settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
    }
}

function s104Scenarios(S104Run $t): array
{
    return [
        '01 HTTP 方法、来源与报文边界' => function () use ($t): void {
            $get = $t->http('GET', '', ['Accept' => 'text/event-stream', 'Authorization' => 'Bearer ' . $t->editor]);
            $t->check($get['status'] === 405 && ($get['headers']['allow'] ?? '') === 'POST', 'GET 返回 405 且 Allow: POST（无 SSE 流）');
            $del = $t->http('DELETE', '', ['Authorization' => 'Bearer ' . $t->editor, 'Mcp-Session-Id' => 'abc']);
            $t->check($del['status'] === 405, 'DELETE（旧式会话终止）返回 405');
            $evil = $t->modern('server/discover', [], null, ['Origin' => 'https://evil.example']);
            $t->check($evil['status'] === 403, '带非白名单 Origin 返回 403（DNS 重绑定防护）');
            $t->check($t->modern('server/discover')['status'] === 200, '无 Origin 的非浏览器客户端可正常访问');
            $t->check($t->modern('server/discover', [], null, ['Content-Type' => 'text/plain'])['status'] === 415, 'Content-Type 非 JSON 返回 415');
            $t->check($t->modern('server/discover', [], null, ['Accept' => 'text/html'])['status'] === 406, 'Accept 不含 application/json 返回 406');
            $big = $t->http('POST', str_repeat(' ', 256001), ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $t->editor]);
            $t->check($big['status'] === 413, '请求体超过 256000 字节返回 413');
            $parse = $t->http('POST', '{"jsonrpc":', ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $t->editor]);
            $t->check($parse['status'] === 400 && ($parse['json']['error']['code'] ?? 0) === -32700, '非法 JSON 返回 400 / -32700');
            $batch = $t->http('POST', '[{"jsonrpc":"2.0","id":1,"method":"ping"}]', ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $t->editor]);
            $t->check($batch['status'] === 400 && ($batch['json']['error']['code'] ?? 0) === -32600, 'JSON-RPC 批量数组被拒绝（-32600）');
            $deep = $t->http('POST', '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"a":' . str_repeat('[', 40) . str_repeat(']', 40) . '}}',
                ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $t->editor]);
            $t->check($deep['status'] === 400 && ($deep['json']['error']['code'] ?? 0) === -32700, '嵌套超过 32 层的 JSON 被拒绝');
        },
        '02 认证：只认 Authorization Bearer' => function () use ($t): void {
            $none = $t->modern('server/discover', [], null, ['Authorization' => null]);
            $t->check($none['status'] === 401 && str_starts_with($none['headers']['www-authenticate'] ?? '', 'Bearer '), '无凭据 401 且带 WWW-Authenticate: Bearer');
            $cookie = $t->modern('server/discover', [], null, ['Authorization' => null, 'Cookie' => 'PHPSESSID=s104fake; yikai_admin=1']);
            $t->check($cookie['status'] === 401, '后台 cookie 不能替代令牌');
            $query = $t->http('POST', '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}', ['Content-Type' => 'application/json', 'Accept' => 'application/json'], '?token=' . rawurlencode($t->editor) . '&access_token=' . rawurlencode($t->editor));
            $t->check($query['status'] === 401, '查询串里的令牌不被接受');
            $t->check($t->modern('server/discover', [], 'ykat_' . str_repeat('A', 43))['status'] === 401, '未知令牌 401');
            $t->check($t->modern('server/discover', [], null, ['Authorization' => 'Basic ' . base64_encode('a:b')])['status'] === 401, 'Basic 认证 401');
            $p = S103_PREFIX;
            $t->db->execute("UPDATE {$p}api_tokens SET expires_at = ? WHERE id = 39", [time() - 1]);
            $t->check($t->modern('server/discover', [], $t->reader)['status'] === 401, '过期令牌 401');
            $t->db->execute("UPDATE {$p}api_tokens SET expires_at = ? WHERE id = 39", [time() + 3600]);
            $t->check($t->modern('server/discover', [], $t->reader)['status'] === 200, '恢复有效期后立即可用（逐请求重读）');
            $t->setting('ai_access_enabled', '0');
            $t->check($t->modern('server/discover')['status'] === 403, '总开关关闭 403');
            $t->setting('ai_access_enabled', '1');
            $t->setting('demo_mode', '1');
            $t->check($t->modern('server/discover')['status'] === 403, '演示模式 403');
            $t->setting('demo_mode', '0');
        },
        '03 权限与范围逐请求生效' => function () use ($t): void {
            $p = S103_PREFIX;
            $names = static fn(array $r): array => array_column($r['json']['result']['tools'] ?? [], 'name');
            $full = $names($t->modern('tools/list'));
            $t->check($full === array_slice(AiAccessAuthContext::TOOLS, 0, 6), '编辑令牌：六个文章工具，顺序固定（无 blox_edit/edit_page 时单页工具不出现）');
            $reader = $names($t->modern('tools/list', [], $t->reader));
            $t->check($reader === ['site_info', 'article_channel_list', 'article_list', 'article_read'], '只读令牌：只列出授予的四个读取工具');
            $denied = $t->tool('article_draft_propose', ['idempotency_key' => 'reader-0001', 'items' => []], $t->reader);
            $t->check($denied['rpc_error'] === -32602, '未授予的工具按未知工具处理（-32602），不暴露存在');
            $t->check($t->tool('cms_translate_text', ['text' => 'x'])['rpc_error'] === -32602, 'Abilities 中存在但未进外部目录的能力不可调用');
            $t->db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 2", ['["ai_access"]']);
            $t->check($names($t->modern('tools/list')) === ['site_info'], '角色去掉 edit_article 后下一次请求只剩 site_info');
            $t->check($t->tool('article_list', ['lang' => 'zh-CN'])['rpc_error'] === -32602, '降权后文章工具立即不可调用');
            $t->db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 2", ['[]']);
            $t->check($t->modern('tools/list')['status'] === 403, '去掉 ai_access 后 403');
            $t->db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 2", ['["ai_access","edit_article"]']);
            $t->db->execute("UPDATE {$p}users SET status = 0 WHERE id = 7");
            $t->check($t->modern('tools/list')['status'] === 401, '账号停用后 401');
            $t->db->execute("UPDATE {$p}users SET status = 1 WHERE id = 7");
            $t->db->execute("UPDATE {$p}api_tokens SET revoked_at = ? WHERE id = 39", [time()]);
            $t->check($t->tool('site_info', [], $t->reader)['http']['status'] === 401, '会话中途吊销：下一次工具调用即 401');
            $t->db->execute("UPDATE {$p}api_tokens SET revoked_at = 0 WHERE id = 39");
        },
        '04 2026-07-28 逐请求元数据' => function () use ($t): void {
            $d = $t->modern('server/discover');
            $r = $d['json']['result'] ?? [];
            $t->check(($r['resultType'] ?? '') === 'complete' && in_array('2026-07-28', $r['supportedVersions'] ?? [], true) && in_array('2025-06-18', $r['supportedVersions'] ?? [], true),
                'server/discover 返回 resultType=complete 与两代版本列表');
            $t->check(($r['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '') === 'yikaicms', '结果 _meta 带 serverInfo');
            $l = $t->modern('tools/list')['json']['result'] ?? [];
            $t->check(($l['cacheScope'] ?? '') === 'private' && ($l['ttlMs'] ?? -1) === 0, 'tools/list 带 cacheScope=private、ttlMs=0（按身份过滤，不可共享缓存）');
            $bad = $t->modern('tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '1900-01-01', 'io.modelcontextprotocol/clientCapabilities' => (object) []]], null, ['MCP-Protocol-Version' => '1900-01-01']);
            $t->check($bad['status'] === 400 && ($bad['json']['error']['code'] ?? 0) === -32022 && in_array('2026-07-28', $bad['json']['error']['data']['supported'] ?? [], true), '不支持的版本 400 / -32022 并列出支持版本');
            $mismatch = $t->modern('tools/list', [], null, ['MCP-Protocol-Version' => '2025-11-25']);
            $t->check($mismatch['status'] === 400 && ($mismatch['json']['error']['code'] ?? 0) === -32020, '版本头与 _meta 不一致 400 / -32020');
            $method = $t->modern('tools/list', [], null, ['Mcp-Method' => 'tools/call']);
            $t->check(($method['json']['error']['code'] ?? 0) === -32020, 'Mcp-Method 与正文不一致 -32020');
            $missing = $t->modern('tools/list', [], null, ['Mcp-Method' => null]);
            $t->check(($missing['json']['error']['code'] ?? 0) === -32020, '缺少 Mcp-Method -32020');
            $name = $t->modern('tools/call', ['name' => 'site_info', 'arguments' => (object) []], null, ['Mcp-Name' => 'article_list']);
            $t->check(($name['json']['error']['code'] ?? 0) === -32020, 'Mcp-Name 与工具名不一致 -32020');
            $b64 = $t->modern('tools/call', ['name' => 'site_info', 'arguments' => (object) []], null, ['Mcp-Name' => '=?base64?' . base64_encode('site_info') . '?=']);
            $t->check(($b64['json']['result']['isError'] ?? true) === false, 'Base64 形式的 Mcp-Name 可解码匹配');
            $caps = $t->modern('tools/list', ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]);
            $t->check($caps['status'] === 400, '缺少 clientCapabilities 400');
            $unknown = $t->modern('resources/list');
            $t->check($unknown['status'] === 404 && ($unknown['json']['error']['code'] ?? 0) === -32601, '未实现的方法 404 / -32601（不声明 resources）');
            $t->check(!isset($d['headers']['mcp-session-id']), '不签发 Mcp-Session-Id');
        },
        '05 旧式握手 2025-11-25 / 2025-06-18' => function () use ($t): void {
            foreach (['2025-11-25', '2025-06-18'] as $v) {
                $init = $t->legacy('initialize', ['protocolVersion' => $v, 'capabilities' => (object) [], 'clientInfo' => ['name' => 's104-legacy', 'version' => '1']], null);
                $t->check(($init['json']['result']['protocolVersion'] ?? '') === $v && !isset($init['headers']['mcp-session-id']), "initialize {$v}：回同一版本，不发会话 ID");
            }
            $old = $t->legacy('initialize', ['protocolVersion' => '2024-11-05', 'capabilities' => (object) []], null);
            $t->check(($old['json']['result']['protocolVersion'] ?? '') === '2025-11-25', '请求不支持的旧版本时回本端最新旧版本，由客户端决定');
            $note = $t->legacy('notifications/initialized', [], '2025-11-25', null, true);
            $t->check($note['status'] === 202 && $note['body'] === '', 'notifications/initialized 202 无正文');
            $t->check($t->legacy('tools/list', [], null)['status'] === 400, '握手后缺 MCP-Protocol-Version 头被拒（不猜 2025-03-26）');
            $t->check($t->legacy('tools/list', [], '2024-11-05')['status'] === 400, '握手后版本头不受支持被拒');
            $list = $t->legacy('tools/list', [], '2025-06-18');
            $t->check(count($list['json']['result']['tools'] ?? []) === 6 && !isset($list['json']['result']['resultType']), '旧式 tools/list 正常且不混入 resultType');
            $ping = $t->legacy('ping', [], '2025-11-25');
            $t->check(($ping['json']['result'] ?? null) === [] && $ping['body'] !== '' && str_contains($ping['body'], '"result":{}'), '旧式 ping 返回空对象');
            $call = $t->legacy('tools/call', ['name' => 'site_info', 'arguments' => (object) []], '2025-11-25');
            $t->check(($call['json']['result']['isError'] ?? true) === false, '旧式 tools/call 正常');
            $t->check($t->legacy('resources/list', [], '2025-11-25')['json']['error']['code'] === -32601, '旧式未实现方法 -32601');
        },
        '06 读取工具：范围、分页、分段' => function () use ($t): void {
            $p = S103_PREFIX;
            $t->db->execute("UPDATE {$p}contents SET deleted_at = ? WHERE id = 100", [time()]);
            $before = $t->fingerprint();
            $ids = [];
            $cursor = null;
            for ($i = 0; $i < 10; $i++) {
                $args = ['lang' => 'zh-CN', 'limit' => 1];
                if ($cursor !== null) $args['cursor'] = $cursor;
                $page = $t->tool('article_list', $args)['data'] ?? [];
                foreach ($page['items'] ?? [] as $item) $ids[] = $item['id'];
                $cursor = $page['next_cursor'] ?? null;
                if ($cursor === null) break;
            }
            $t->check($ids === [104, 101], '游标逐页遍历：只含中文文章，无重复；排除回收站(100)、产品(102)、英文(103)');
            $t->check($t->tool('article_list', ['lang' => 'zh-CN', 'limit' => 51])['error'], 'limit 超过 50 是工具错误');
            $t->check(($t->tool('article_list', ['lang' => 'fr'])['data']['error'] ?? '') === 'ai_mcp_language_unavailable', '未启用语言被拒');
            $t->check(($t->tool('article_list', ['lang' => 'zh-CN', 'cursor' => "a1' OR 1=1"])['data']['error'] ?? '') === 'ai_mcp_invalid_arguments', '伪造游标被拒');
            $t->check(($t->tool('article_list', ['lang' => 'zh-CN', 'order' => 'id'])['data']['error'] ?? '') === 'ai_mcp_invalid_arguments', '未声明参数被拒（additionalProperties=false）');
            $t->check(($t->tool('article_read', ['id' => 100])['data']['error'] ?? '') === 'ai_mcp_not_found', '回收站文章读取为不存在');
            $t->check(($t->tool('article_read', ['id' => 102])['data']['error'] ?? '') === 'ai_mcp_not_found', '产品不能经文章工具读取');
            $fields = array_keys($t->tool('article_read', ['id' => 101])['data'] ?? []);
            $t->check(array_diff($fields, ['id', 'lang', 'channel_id', 'title', 'subtitle', 'summary', 'tags', 'status', 'updated_at', 'content', 'content_offset', 'content_bytes']) === [], '读取字段白名单：无 admin_id/slug/seo/blocks 等');
            // 病态正文：引号和反斜杠在两层 JSON 中各膨胀，仍须整条响应 ≤ 256000 字节并可无损拼回。
            $nasty = str_repeat('"\\', 70000) . '中文结尾';
            $t->db->execute("INSERT INTO {$p}contents (id, lang, channel_id, type, title, summary, content, tags, status, publish_time, created_at, updated_at, admin_id) VALUES (105, 'zh-CN', 10, 'article', 'Nasty', '', ?, '', 0, 0, 1, 1, 1)", [$nasty]);
            foreach ([104 => null, 105 => $nasty] as $id => $expected) {
                $expected ??= (string) $t->db->fetchColumn("SELECT content FROM {$p}contents WHERE id = ?", [$id]);
                $joined = '';
                $offset = 0;
                $maxBody = 0;
                $calls = 0;
                do {
                    $r = $t->tool('article_read', ['id' => $id, 'content_offset' => $offset]);
                    $maxBody = max($maxBody, strlen($r['http']['body']));
                    $joined .= (string) ($r['data']['content'] ?? '');
                    $offset = $r['data']['content_next_offset'] ?? null;
                    $calls++;
                } while ($offset !== null && $calls < 50);
                $t->check($joined === $expected && $maxBody <= 256000 && mb_check_encoding($joined, 'UTF-8'), "文章 {$id} 分 {$calls} 段无损拼回，单次响应最大 {$maxBody} 字节 ≤ 256000");
            }
            $t->db->execute("DELETE FROM {$p}contents WHERE id = 105");
            $t->check($t->fingerprint() === $before, '只读工具调用前后全部业务/令牌/提案表不变');
        },
        '07 提案工具：幂等键独立于 JSON-RPC id' => function () use ($t): void {
            $p = S103_PREFIX;
            $contents = $t->fingerprint();
            $item = ['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 10, 'title' => 'S104', 'content' => '<p>ok</p><script>alert(1)</script>'];
            $a = $t->tool('article_draft_propose', ['idempotency_key' => 's104-key-0001', 'items' => [$item]]);
            $b = $t->tool('article_draft_propose', ['idempotency_key' => 's104-key-0001', 'items' => [$item]]);
            $t->check(($a['data']['replayed'] ?? null) === false && ($b['data']['replayed'] ?? null) === true && ($a['data']['proposal_id'] ?? 'x') === ($b['data']['proposal_id'] ?? 'y'),
                '不同 JSON-RPC id、同幂等键同载荷：回放同一提案');
            $c = $t->tool('article_draft_propose', ['idempotency_key' => 's104-key-0001', 'items' => [['title' => 'changed'] + $item]]);
            $t->check(($c['data']['error'] ?? '') === 'ai_proposal_idempotency_conflict', '同键不同载荷：工具错误 ai_proposal_idempotency_conflict');
            $t->check(($t->tool('article_draft_propose', ['items' => [$item]])['data']['error'] ?? '') === 'ai_mcp_invalid_arguments', '缺少幂等键被拒');
            $t->check(($t->tool('article_draft_propose', ['idempotency_key' => 's104-key-0002', 'items' => [['status' => 1] + $item]])['data']['error'] ?? '') === 'ai_proposal_invalid_input', 'items 带 status 字段被拒');
            $t->check(($t->tool('article_draft_propose', ['idempotency_key' => 's104-key-0003', 'items' => [['op' => 'update', 'id' => 101, 'lang' => 'zh-CN', 'title' => 'x']]])['data']['error'] ?? '') !== '',
                '针对已发布文章的更新提案被拒');
            $count = (int) $t->db->fetchColumn("SELECT COUNT(*) FROM {$p}contents");
            $t->check($count === 5, '提案不写 contents（文章数不变）');
            $mine = $t->tool('proposal_status', ['proposal_id' => (string) ($a['data']['proposal_id'] ?? '')]);
            $t->check(($mine['data']['items'][0]['status'] ?? '') === 'pending', 'proposal_status 读到自己的待审项');
            $p8 = (new AiProposalService($t->db))->proposeInternal(8, 's104-other-0001', [$item], time());
            $other = $t->tool('proposal_status', ['proposal_id' => $p8['proposal_id']]);
            $t->check(($other['data']['error'] ?? '') === 'ai_mcp_not_found', '他人提案与不存在相同');
            $t->check($contents !== $t->fingerprint(), '（对照）提案确实写入了提案表');
        },
        '09 单页只读工具：page_read 范围、权限、分页与版本' => function () use ($t): void {
            $p = S103_PREFIX;
            $t->db->execute("DROP TABLE IF EXISTS {$p}blox_page_drafts");
            foreach (s103CoreTableSql($t->db->isSqlite(), 'blox_page_drafts') as $sql) $t->db->execute($sql);
            $t->db->execute("INSERT INTO {$p}roles (id, name, permissions, status, created_at) VALUES (4, 'PageEditor', '[\"ai_access\",\"blox_edit\",\"edit_page\"]', 1, 0)");
            $t->db->execute("INSERT INTO {$p}users (id, username, password, role_id, status, created_at, updated_at) VALUES (9, 's104-pager', 'x', 4, 1, 0, 0)");
            $tokens = [];
            foreach (['pager' => [49, 9, ['site_info', 'page_read']], 'pager_noscope' => [59, 9, ['site_info', 'article_read']], 'editor_pages' => [69, 7, ['site_info', 'page_read']]] as $name => [$id, $owner, $scopes]) {
                $tokens[$name] = 'ykat_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $t->db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (?, ?, ?, ?, ?, 0)",
                    [$id, $owner, hash('sha256', $tokens[$name]), json_encode($scopes), time() + 3600]);
            }
            $doc = static fn(array $elements): string => json_encode(['schema' => 1, 'settings' => ['custom_css' => 'SECRET_DOC_CSS'],
                'sections' => [['id' => 's1', 'settings' => ['_custom_css' => 'SECRET_SECTION_CSS'], 'columns' => [['id' => 'c1', 'elements' => $elements]]]]], JSON_THROW_ON_ERROR);
            $about = [
                ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'About us', 'url' => 'javascript:alert(1)', '_conditions' => ['SECRET_COND']]],
                ['id' => 't1', 'type' => 'text', 'data' => ['html' => '<p onclick="steal()">Story<script>SECRET_SCRIPT()</script></p>']],
                ['id' => 'code1', 'type' => 'code', 'data' => ['code' => 'SECRET_CODE']],
                ['type' => 'heading', 'data' => ['text' => 'No id']],
                ['id' => 'dup', 'type' => 'button', 'data' => ['text' => 'A', 'url' => '/a']],
                ['id' => 'dup', 'type' => 'button', 'data' => ['text' => 'B', 'url' => '/b']],
            ];
            $many = [];
            for ($i = 0; $i < 130; $i++) $many[] = ['id' => 'm' . $i, 'type' => 'heading', 'data' => ['text' => 'Item ' . $i]];
            foreach ([[14, 'en', 'about-en-s104', 1], [15, 'zh-CN', 'hidden-s104', 0], [16, 'zh-CN', 'legacy-s104', 1], [17, 'zh-CN', 'many-s104', 1]] as [$id, $lang, $slug, $status]) {
                $t->db->execute("INSERT INTO {$p}channels (id, lang, name, slug, type, status, content, created_at, updated_at) VALUES (?, ?, ?, ?, 'page', ?, ?, 0, 0)",
                    [$id, $lang, 'S104 ' . $slug, $slug, $status, $id === 16 ? '<p>Legacy body</p>' : '']);
            }
            foreach ([12 => $about, 14 => $about, 15 => $about, 17 => $many] as $pageId => $elements) {
                $t->db->execute("INSERT INTO {$p}blox_page_drafts (page_id, draft_data) VALUES (?, ?)", [$pageId, $doc($elements)]);
            }
            $snapshot = fn(): string => hash('sha256', serialize([$t->fingerprint(), $t->db->fetchAll("SELECT * FROM {$p}blox_page_drafts ORDER BY id")]));
            $before = $snapshot();
            $names = static fn(array $r): array => array_column($r['json']['result']['tools'] ?? [], 'name');

            $t->check($names($t->modern('tools/list', [], $tokens['pager'])) === ['site_info', 'page_list', 'page_read'], 'page_read 范围 + blox_edit/edit_page：列出 page_list 与 page_read');
            $t->check($names($t->modern('tools/list', [], $tokens['pager_noscope'])) === ['site_info'], '有页面权限但无 page_read 范围：单页工具不出现');
            $t->check($names($t->modern('tools/list', [], $tokens['editor_pages'])) === ['site_info'], '有 page_read 范围但账号无 blox_edit/edit_page：单页工具不出现');
            $t->check($t->tool('page_read', ['id' => 12, 'lang' => 'zh-CN'], $tokens['editor_pages'])['rpc_error'] === -32602, '无页面权限调用 page_read：按未知工具处理');
            $t->check($t->tool('page_read', ['id' => 12, 'lang' => 'zh-CN'])['rpc_error'] === -32602, '编辑令牌（无页面权限）不能调用 page_read');

            $list = $t->tool('page_list', ['lang' => 'zh-CN'], $tokens['pager'])['data'] ?? [];
            $t->check(array_column($list['items'] ?? [], 'id') === [12, 16, 17], 'page_list：只列启用的中文普通单页（排除停用 15、英文 14、文章栏目）');
            $first = $t->tool('page_list', ['lang' => 'zh-CN', 'limit' => 2], $tokens['pager'])['data'] ?? [];
            $second = $t->tool('page_list', ['lang' => 'zh-CN', 'limit' => 2, 'cursor' => (string) ($first['next_cursor'] ?? '')], $tokens['pager'])['data'] ?? [];
            $t->check(array_column($first['items'] ?? [], 'id') === [12, 16] && array_column($second['items'] ?? [], 'id') === [17] && !isset($second['next_cursor']), 'page_list 游标分页无重复无遗漏');
            $t->check(($t->tool('page_list', ['lang' => 'zh-CN', 'cursor' => "p1' OR 1=1"], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_invalid_arguments', 'page_list 伪造游标被拒');

            $read = $t->tool('page_read', ['id' => 12, 'lang' => 'zh-CN'], $tokens['pager']);
            $data = $read['data'] ?? [];
            $byPath = array_column($data['elements'] ?? [], null, 'path');
            $t->check(($data['page']['document'] ?? '') === 'page:12' && strlen((string) ($data['page']['base_revision'] ?? '')) === 64 && ($data['total'] ?? 0) === 6, 'page_read：返回文档地址、64 位 base_revision 与元素总数');
            $leak = (string) $read['http']['body'];
            $t->check(!preg_match('/SECRET_|<script|onclick|javascript:/', $leak), '响应不含代码、CSS、可见条件、脚本、事件属性或 javascript: 链接');
            $t->check(($byPath['s0/c0/e0']['fields']['url']['sanitized'] ?? false) === true && ($byPath['s0/c0/e1']['fields']['html']['sanitized'] ?? false) === true, '不安全的链接与富文本被净化并标 sanitized');
            $t->check(($byPath['s0/c0/e2']['reason'] ?? '') === 'type_not_supported' && !isset($byPath['s0/c0/e2']['fields']), '代码元素只列类型，不给字段');
            $t->check(($byPath['s0/c0/e3']['reason'] ?? '') === 'missing_id' && ($byPath['s0/c0/e4']['reason'] ?? '') === 'duplicate_id' && ($byPath['s0/c0/e5']['addressable'] ?? true) === false, '缺失与重复 ID 标为不可寻址');
            $t->check(($t->tool('page_read', ['id' => 14, 'lang' => 'zh-CN'], $tokens['pager'])['data']['error'] ?? '') === 'ai_page_language_mismatch', '语言与页面不符被拒');
            $t->check(($t->tool('page_read', ['id' => 14, 'lang' => 'fr'], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_language_unavailable', '未启用语言被拒');
            $t->check(($t->tool('page_read', ['id' => 15, 'lang' => 'zh-CN'], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_not_found', '停用页对外与不存在相同');
            $t->check(($t->tool('page_read', ['id' => 10, 'lang' => 'zh-CN'], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_not_found', '文章栏目不能经单页工具读取');
            $t->check(($t->tool('page_read', ['id' => 999, 'lang' => 'zh-CN'], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_not_found', '不存在的页面');
            $t->check(($t->tool('page_read', ['id' => 12, 'lang' => 'zh-CN', 'fields' => 'all'], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_invalid_arguments', '未声明参数被拒');
            $legacy = $t->tool('page_read', ['id' => 16, 'lang' => 'zh-CN'], $tokens['pager'])['data'] ?? [];
            $t->check(($legacy['page']['uses_legacy_html'] ?? false) === true && ($legacy['elements'][0]['reason'] ?? '') === 'legacy_html' && str_starts_with((string) ($legacy['page']['base_revision'] ?? ''), 'html-'), '纯 HTML 老页只读，revision 带 html- 前缀');

            $ids = [];
            $cursor = 0;
            $revision = null;
            for ($i = 0; $i < 5; $i++) {
                $args = ['id' => 17, 'lang' => 'zh-CN', 'limit' => 50, 'cursor' => $cursor] + ($revision !== null ? ['revision' => $revision] : []);
                $chunk = $t->tool('page_read', $args, $tokens['pager'])['data'] ?? [];
                $revision ??= $chunk['page']['base_revision'] ?? null;
                foreach ($chunk['elements'] ?? [] as $e) $ids[] = $e['id'];
                if (!isset($chunk['next_cursor'])) break;
                $cursor = $chunk['next_cursor'];
            }
            $t->check(count($ids) === 130 && count(array_unique($ids)) === 130, '130 个元素按 50 条分三页读完，无重复无遗漏');
            $t->check(($t->tool('page_read', ['id' => 17, 'lang' => 'zh-CN', 'cursor' => 50], $tokens['pager'])['data']['error'] ?? '') === 'ai_page_revision_mismatch', '翻页不带 revision 被拒');
            $t->check(($t->tool('page_read', ['id' => 17, 'lang' => 'zh-CN', 'limit' => 101], $tokens['pager'])['data']['error'] ?? '') === 'ai_mcp_invalid_arguments', 'limit 超过 100 被拒');
            $t->check($snapshot() === $before, '单页只读调用前后业务表与草稿表不变');
            $many[] = ['id' => 'late', 'type' => 'heading', 'data' => ['text' => 'Edited meanwhile']];
            $t->db->execute("UPDATE {$p}blox_page_drafts SET draft_data = ? WHERE page_id = 17", [$doc($many)]);
            $t->check(($t->tool('page_read', ['id' => 17, 'lang' => 'zh-CN', 'cursor' => 50, 'revision' => (string) $revision], $tokens['pager'])['data']['error'] ?? '') === 'ai_page_revision_mismatch', '两页之间页面被改：旧 revision 翻页被拒');
            $t->db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","edit_page"]']);
            $t->check($names($t->modern('tools/list', [], $tokens['pager'])) === ['site_info'], '角色去掉 blox_edit 后下一次请求单页工具即消失');
            $t->db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = 4", ['["ai_access","blox_edit","edit_page"]']);

            // S5-03：单页内容提案经 /mcp/ 提交，只进提案表，不改草稿
            $proposer = 'ykat_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $t->db->execute("INSERT INTO {$p}api_tokens (id, admin_id, token_hash, scopes, expires_at, revoked_at) VALUES (89, 9, ?, ?, ?, 0)",
                [hash('sha256', $proposer), json_encode(['site_info', 'page_read', 'page_content_propose', 'proposal_status']), time() + 3600]);
            $t->check($names($t->modern('tools/list', [], $proposer)) === ['site_info', 'proposal_status', 'page_list', 'page_read', 'page_content_propose'], '提案令牌：列出 page_content_propose');
            $t->check($t->tool('page_content_propose', ['idempotency_key' => 's503-mcp-0000', 'page_id' => 12, 'lang' => 'zh-CN', 'base_revision' => str_repeat('a', 64), 'changes' => []], $tokens['pager'])['rpc_error'] === -32602, '没有提案范围的令牌：按未知工具处理');
            $drafts = $t->db->fetchAll("SELECT * FROM {$p}blox_page_drafts ORDER BY id");
            $base = (string) ($t->tool('page_read', ['id' => 12, 'lang' => 'zh-CN'], $proposer)['data']['page']['base_revision'] ?? '');
            $propose = ['idempotency_key' => 's503-mcp-0001', 'page_id' => 12, 'lang' => 'zh-CN', 'base_revision' => $base,
                'changes' => [['element_id' => 'h1', 'field' => 'text', 'value' => 'Proposed title'], ['element_id' => 't1', 'field' => 'html', 'value' => '<p>Proposed<script>x()</script></p>']]];
            $first = $t->tool('page_content_propose', $propose, $proposer)['data'] ?? [];
            $again = $t->tool('page_content_propose', $propose, $proposer)['data'] ?? [];
            $t->check(($first['items'][0]['status'] ?? '') === 'pending' && ($first['replayed'] ?? null) === false && ($again['replayed'] ?? null) === true && ($again['proposal_id'] ?? '') === ($first['proposal_id'] ?? 'x'),
                'page_content_propose：待审；同键重试回放同一提案');
            $status = $t->tool('proposal_status', ['proposal_id' => (string) ($first['proposal_id'] ?? '')], $proposer)['data'] ?? [];
            $t->check(($status['items'][0]['op'] ?? '') === 'page_edit' && ($status['items'][0]['target_id'] ?? 0) === 12 && ($status['items'][0]['status'] ?? '') === 'pending', 'proposal_status：单页提案待审，目标为页面 12');
            $t->check(($t->tool('page_content_propose', ['base_revision' => str_repeat('0', 64), 'idempotency_key' => 's503-mcp-0002'] + $propose, $proposer)['data']['error'] ?? '') === 'ai_proposal_stale_revision', '旧修订号：ai_proposal_stale_revision');
            $t->check(($t->tool('page_content_propose', ['idempotency_key' => 's503-mcp-0003', 'changes' => [['element_id' => 'dup', 'field' => 'text', 'value' => 'x']]] + $propose, $proposer)['data']['error'] ?? '') === 'ai_proposal_invalid_target', '重复 ID：ai_proposal_invalid_target');
            $t->check(($t->tool('page_content_propose', ['idempotency_key' => 's503-mcp-0004', 'changes' => [['element_id' => 'h1', 'field' => 'url', 'value' => '/x']]] + $propose, $proposer)['data']['error'] ?? '') === 'ai_mcp_invalid_arguments' || ($t->tool('page_content_propose', ['idempotency_key' => 's503-mcp-0005', 'changes' => [['element_id' => 'h1', 'field' => 'url', 'value' => '/x']]] + $propose, $proposer)['data']['error'] ?? '') === 'ai_proposal_invalid_input', '链接字段（推迟）被拒');
            $t->check($t->db->fetchAll("SELECT * FROM {$p}blox_page_drafts ORDER BY id") === $drafts, '提交提案不改任何草稿');
        },
        '08 无会话与不泄漏' => function () use ($t): void {
            $cookies = 0;
            foreach ([$t->modern('server/discover'), $t->legacy('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) []], null)] as $r) {
                foreach ($r['raw_headers'] as $h) if (stripos($h, 'set-cookie') === 0 || stripos($h, 'x-powered-by') === 0) $cookies++;
            }
            $t->check($cookies === 0, '响应不含 Set-Cookie / X-Powered-By');
            $haystack = implode("\n", $t->bodies) . "\n" . $t->traceText();
            $t->check(!str_contains($haystack, $t->editor) && !str_contains($haystack, $t->reader), '响应与诊断日志均无令牌明文');
            $t->check(!str_contains($haystack, hash('sha256', $t->editor)) && !str_contains($haystack, hash('sha256', $t->reader)), '响应与诊断日志均无令牌摘要');
            $trace = $t->traceText();
            $t->check($trace !== '' && !str_contains(strtolower($trace), 'authorization') && !str_contains($trace, '长文测试段落') && !str_contains($trace, 's104-key-0001'),
                '诊断日志不含 Authorization、正文或工具参数');
            $t->check(!str_contains($haystack, 'SQLSTATE') && !str_contains($haystack, dirname(__DIR__, 2)), '错误输出不含 SQL 错误或服务器路径');
        },
    ];
}

$out = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
$t = new S104Run();
try {
    $t->seed();
    $t->start();
    foreach (s104Scenarios($t) as $name => $fn) {
        $t->scenario = $name;
        try {
            $fn();
        } catch (Throwable $e) {
            $t->check(false, '场景异常：' . get_class($e) . ' ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'environment error: ' . $e->getMessage() . "\n");
    $t->stop();
    exit(2);
}
$t->stop();

$total = 0;
$failed = 0;
foreach ($t->results as $scenario => $checks) {
    $ok = count(array_filter($checks, static fn(array $c): bool => $c[0]));
    printf("%s  %d/%d\n", $scenario, $ok, count($checks));
    foreach ($checks as [$pass, $label]) {
        if (!$pass) printf("    FAIL %s\n", $label);
        $total++;
        $failed += $pass ? 0 : 1;
    }
}
printf("php=%s sqlite=%s assertions=%d failed=%d\n", PHP_VERSION, (string) $t->db->fetchColumn('SELECT sqlite_version()'), $total, $failed);
if ($out !== null) {
    file_put_contents($out, json_encode(['php' => PHP_VERSION, 'assertions' => $total, 'failed' => $failed,
        'scenarios' => array_map(static fn(array $c): array => array_map(static fn(array $x): array => ['ok' => $x[0], 'label' => $x[1]], $c), $t->results)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
exit($failed === 0 ? 0 : 1);
