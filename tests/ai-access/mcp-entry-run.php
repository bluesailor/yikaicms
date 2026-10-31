<?php
/**
 * S2：正式入口 mcp/index.php 的端到端验证。在系统临时目录搭一个合成的已安装站点
 * （安装模板生成的 config.php、完整安装 SQL、后台准备好的配置桥接），用 `php -S` 以站点根为文档根
 * 访问 /mcp/，检查认证、HTTPS/可信代理、限流、审计、存储故障、总开关与配置变更。
 *
 *   php tests/ai-access/mcp-entry-run.php [--out=report.json]
 *
 * 退出码：0 = 全部通过；1 = 有断言失败；2 = 环境错误。不读写真实站点或数据库。
 */

declare(strict_types=1);

$repo = dirname(__DIR__, 2);

if (($argv[1] ?? '') === '--prepare') {
    // 子进程：模拟后台的受信准备动作——先记录配置指纹，再按常规加载 config.php，最后写桥接。
    $root = $argv[2];
    require $repo . '/includes/ai-access/AiAccessBootstrapConfig.php';
    AiAccessBootstrapConfig::beginPreparation($root);
    define('ROOT_PATH', $root);
    ini_set('session.save_path', $root . '/sessions');
    require $root . '/config/config.php';
    AiAccessBootstrapConfig::prepare($root);
    echo 'prepared';
    exit(0);
}

final class S2EntryRun
{
    /** 临时容器目录（清理范围）；$site 是站点根：php -S 模式等于容器，Apache 模式为容器下的 sub/（子目录部署） */
    public string $root;
    public string $site;
    public string $url = '';
    /** Dedicated test CA, without changing the OS trust store. */
    public ?string $caFile = null;
    /** @var array<string,list<array{0:bool,1:string}>> */
    public array $results = [];
    public string $scenario = '';
    public string $editor = '';
    public string $other = '';
    /** S3：带提案范围的令牌（同一账号 57）。 */
    public string $writer = '';
    public int $writerId = 0;
    public int $otherId = 0;
    /** @var list<string> 准备桥接时 config.php 自己开的会话文件（入口请求不得再新增或改动） */
    public array $sessionBaseline = [];
    /** @var list<string> */
    public array $raw = [];
    /** @var resource|null */
    private $server = null;
    private int $id = 0;

    public function __construct(private string $repo, private ?string $httpd = null)
    {
        $this->root = sys_get_temp_dir() . '/yk-mcp-entry-' . bin2hex(random_bytes(6));
        $this->site = $httpd === null ? $this->root : $this->root . '/sub';
    }

    public function build(): void
    {
        foreach (['config', 'storage', 'mcp', 'sessions'] as $dir) mkdir($this->site . '/' . $dir, 0700, true);
        foreach (['database.php', 'version.php'] as $file) copy($this->repo . '/config/' . $file, $this->site . '/config/' . $file);
        copy($this->repo . '/mcp/index.php', $this->site . '/mcp/index.php');
        copy($this->repo . '/.htaccess', $this->site . '/.htaccess');
        // 程序目录用目录联接/软链指回仓库；清理时只拆联接，不递归进去。
        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $this->site . '/includes')) . ' ' . escapeshellarg(str_replace('/', '\\', $this->repo . '/includes')) . ' >NUL', $o, $code);
            if ($code !== 0) throw new RuntimeException('mklink failed');
        } elseif (!symlink($this->repo . '/includes', $this->site . '/includes')) {
            throw new RuntimeException('symlink failed');
        }
        $config = strtr((string) file_get_contents($this->repo . '/config/config.sample.php'), [
            '{{DB_DRIVER}}' => 'sqlite', '{{DB_HOST}}' => '127.0.0.1', '{{DB_PORT}}' => '3306', '{{DB_NAME}}' => 'fixture', '{{DB_USER}}' => 'fixture',
            '{{DB_PASS}}' => 'fixture-only', '{{SITE_NAME}}' => 'S2 Entry Fixture', '{{SITE_URL}}' => 'http://fixture.invalid',
            '{{SESSION_ID}}' => 'fixture', '{{ENCRYPT_KEY}}' => 'fixture-not-a-key',
        ]);
        file_put_contents($this->site . '/config/config.php', $config);

        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --prepare ' . escapeshellarg($this->site) . ' 2>&1', $out, $code);
        if ($code !== 0 || implode('', $out) !== 'prepared') throw new RuntimeException('prepare failed: ' . implode("\n", $out));

        // 本进程直接连同一个 SQLite 文件灌入完整安装结构与测试数据。
        define('ROOT_PATH', $this->repo);
        define('DEBUG', false);
        foreach (['DB_DRIVER' => 'sqlite', 'DB_PATH' => $this->site . '/storage/database.sqlite', 'DB_HOST' => '', 'DB_PORT' => '0', 'DB_NAME' => '', 'DB_USER' => '', 'DB_PASS' => '', 'DB_CHARSET' => 'utf8mb4', 'DB_PREFIX' => 'yikai_'] as $k => $v) define($k, $v);
        require_once $this->repo . '/config/database.php';
        require_once $this->repo . '/includes/ai-access/AiAccessTokenStore.php';
        db()->getPdo()->exec((string) file_get_contents($this->repo . '/install/sql/sqlite.sql'));
        $hash = password_hash('synthetic-not-a-login', PASSWORD_DEFAULT);
        db()->execute("INSERT INTO yikai_roles (id, name, permissions, status, created_at) VALUES (52, 'S2 Editor', '[\"ai_access\",\"edit_article\"]', 1, 0)");
        foreach ([[57, 's2-editor'], [58, 's2-other']] as [$id, $name]) {
            db()->execute('INSERT INTO yikai_users (id, username, password, role_id, status, created_at, updated_at) VALUES (?, ?, ?, 52, 1, 0, 0)', [$id, $name, $hash]);
        }
        $this->setting('ai_access_enabled', '1');
        $this->setting('demo_mode', '0');
        $this->setting('trusted_proxies', '');
        $store = new AiAccessTokenStore(db());
        $this->editor = $store->issue(57, 'entry test', AiAccessTokenStore::DEFAULT_SCOPES, 30, time())['secret'];
        $writer = $store->issue(57, 'entry writer', AiAccessTokenStore::ISSUABLE_SCOPES, 30, time());
        $this->writer = $writer['secret'];
        $this->writerId = $writer['id'];
        $other = $store->issue(58, 'to revoke', ['site_info'], 30, time());
        $this->other = $other['secret'];
        $this->otherId = $other['id'];
        $this->sessionBaseline = self::sessionState($this->site);
    }

    /** @return list<string> */
    public static function sessionState(string $root): array
    {
        $files = glob($root . '/sessions/*') ?: [];
        return array_map(static fn(string $file): string => basename($file) . ':' . hash_file('sha256', $file), $files);
    }

    public function setting(string $key, string $value): void
    {
        $exists = db()->fetchColumn('SELECT COUNT(*) FROM yikai_settings WHERE `key` = ?', [$key]);
        if ((int) $exists > 0) db()->execute('UPDATE yikai_settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
        else db()->execute("INSERT INTO yikai_settings (`group`, `key`, `value`) VALUES ('s2', ?, ?)", [$key, $value]);
    }

    public function start(): void
    {
        if ($this->httpd !== null) {
            $this->startApache();
            return;
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/sessions', '-S', $name, '-t', $this->root],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $this->root);
        $this->url = 'http://' . $name . '/mcp/';
        [$host, $port] = explode(':', $name);
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('server did not start');
    }

    /**
     * 独立的临时 Apache：自己的配置、端口、PID 与日志，PHP 走 mod_fcgid（与 phpStudy 站点相同，正是会丢 Authorization 的方式）。
     * 不读写 phpStudy 的配置或服务。
     */
    private function startApache(): void
    {
        $serverRoot = dirname($this->httpd, 2);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $modules = [];
        foreach (file($serverRoot . '/conf/httpd.conf') ?: [] as $line) {
            if (preg_match('/^LoadModule\s+\S+\s+modules\/(mod_(authz_core|authz_host|dir|env|fcgid|log_config|mime|rewrite|setenvif|alias|headers))\.so/', trim($line), $m) === 1) $modules[] = trim($line);
        }
        $php = dirname(PHP_BINARY);
        $q = static fn(string $path): string => '"' . str_replace('\\', '/', $path) . '"';
        $conf = implode("\n", array_merge([
            'ServerRoot ' . $q($serverRoot), 'Listen 127.0.0.1:' . $port, 'ServerName 127.0.0.1', 'PidFile ' . $q($this->root . '/httpd.pid'),
            'ErrorLog ' . $q($this->root . '/httpd-error.log'), 'LogLevel warn', 'TypesConfig conf/mime.types',
        ], $modules, [
            // mod_fcgid 默认只收 128 KB 请求体，超出在 PHP 之前就 500；phpStudy 改成了 50 MB，这里同样放宽，
            // 让 256,000 字节上限由入口自己判定（413）。客户主机若沿用默认值见 S2 报告。
            'FcgidMaxRequestLen 1048576',
            'FcgidInitialEnv PHPRC ' . $q($php), 'FcgidIPCDir ' . $q($this->root . '/fcgid'), 'FcgidProcessTableFile ' . $q($this->root . '/fcgid/table'),
            'DocumentRoot ' . $q($this->root),
            '<Directory ' . $q($this->root) . '>', '  Options FollowSymLinks ExecCGI', '  AllowOverride All', '  Require all granted', '  DirectoryIndex index.php',
            '  AddHandler fcgid-script .php', '  FcgidWrapper ' . $q($php . '/php-cgi.exe') . ' .php', '</Directory>',
        ])) . "\n";
        mkdir($this->root . '/fcgid', 0700);
        file_put_contents($this->root . '/httpd.conf', $conf);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open([$this->httpd, '-f', $this->root . '/httpd.conf'], [0 => ['file', $null, 'r'], 1 => ['file', $this->root . '/httpd-out.log', 'a'], 2 => ['file', $this->root . '/httpd-out.log', 'a']], $pipes, $serverRoot);
        $this->url = 'http://127.0.0.1:' . $port . '/sub/mcp/';
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('apache did not start: ' . @file_get_contents($this->root . '/httpd-error.log') . @file_get_contents($this->root . '/httpd-out.log'));
    }

    /** 当前目标地址换成另一种写法（Apache 模式下测 /mcp、/mcp/、/mcp/index.php 三种）。 */
    public function at(string $suffix): void
    {
        $this->url = preg_replace('#/mcp.*$#', '/mcp' . $suffix, $this->url) ?? $this->url;
    }

    public function apache(): bool
    {
        return $this->httpd !== null;
    }

    public function stop(): void
    {
        if (is_resource($this->server)) {
            $pid = (int) (proc_get_status($this->server)['pid'] ?? 0);
            if ($this->httpd !== null && PHP_OS_FAMILY === 'Windows' && $pid > 0) exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
            proc_terminate($this->server);
            proc_close($this->server);
            if ($this->httpd !== null) sleep(1);
        }
        // Windows 上打开着的 SQLite 文件删不掉：先释放本进程的数据库连接。
        if (class_exists('Database', false)) (new ReflectionProperty('Database', 'instance'))->setValue(null, null);
        gc_collect_cycles();
        if (getenv('YK_S2_KEEP') === '1') {
            fwrite(STDERR, "kept: {$this->root}\n");
            return;
        }
        $link = $this->site . '/includes';
        // 先拆联接，再删其余文件，绝不顺着联接删到仓库。
        if (PHP_OS_FAMILY === 'Windows') {
            if (is_dir($link)) exec('cmd /c rmdir ' . escapeshellarg(str_replace('/', '\\', $link)));
        } elseif (is_link($link)) {
            unlink($link);
        }
        if (file_exists($link)) return;
        $resolved = realpath($this->root);
        if ($resolved === false || !str_starts_with(basename($resolved), 'yk-mcp-entry-')) return;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) @rmdir($file->getPathname());
            else @unlink($file->getPathname());
        }
        @rmdir($resolved);
    }

    public function check(bool $ok, string $label): void
    {
        $this->results[$this->scenario][] = [$ok, $label];
    }

    /** @param array<string,string|null> $headers */
    public function post(string $method, array $params = [], ?string $token = null, array $headers = [], ?string $rawBody = null, string $http = 'POST'): array
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => (object) []];
        $base = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . ($token ?? $this->editor),
            'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if ($method === 'tools/call') $base['Mcp-Name'] = (string) $params['name'];
        $lines = [];
        foreach (array_merge($base, $headers) as $name => $value) if ($value !== null) $lines[] = $name . ': ' . $value;
        $body = $rawBody ?? (string) json_encode(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params]);
        $options = ['http' => ['method' => $http, 'header' => implode("\r\n", $lines), 'content' => $body, 'ignore_errors' => true, 'timeout' => 20, 'follow_location' => 0]];
        if ($this->caFile !== null) $options['ssl'] = ['cafile' => $this->caFile, 'verify_peer' => true, 'verify_peer_name' => true];
        $context = stream_context_create($options);
        $text = (string) @file_get_contents($this->url, false, $context);
        $raw = $http_response_header ?? [];
        $this->raw[] = implode("\n", $raw) . "\n" . $text;
        $status = preg_match('#^HTTP/\S+ (\d{3})#', $raw[0] ?? '', $m) === 1 ? (int) $m[1] : 0;
        $parsed = [];
        foreach (array_slice($raw, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $parsed[strtolower(trim($name))] = trim($value);
        }
        return ['status' => $status, 'headers' => $parsed, 'json' => json_decode($text, true), 'body' => $text];
    }

    public function tool(string $name, array $args = [], ?string $token = null, array $headers = []): array
    {
        return $this->post('tools/call', ['name' => $name, 'arguments' => (object) $args], $token, $headers);
    }

    public function logCount(): int
    {
        return (int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_api_access_log');
    }

    /** 当前分钟窗口（离换窗不足 5 秒时先等过去，避免计数跨窗）。 */
    public function window(): int
    {
        if (time() % 60 > 54) sleep(61 - time() % 60);
        return time() - time() % 60;
    }
}

function s2EntryScenarios(S2EntryRun $t): array
{
    return [
        '01 入口与无会话' => function () use ($t): void {
            $list = $t->post('tools/list');
            $names = array_column($list['json']['result']['tools'] ?? [], 'name');
            $t->check($list['status'] === 200 && $names === ['site_info', 'article_channel_list', 'article_list', 'article_read'], '经 /mcp/ 正式入口：只读令牌只列出四个读取工具');
            $t->check(preg_match('/^[0-9a-f]{32}$/', $list['headers']['x-request-id'] ?? '') === 1, '响应带 X-Request-Id');
            $site = $t->tool('site_info');
            $t->check(($site['json']['result']['structuredContent']['site_name'] ?? '') !== '', 'site_info 读到真实安装 SQL 的站点名');
            $article = (int) db()->fetchColumn("SELECT id FROM yikai_contents WHERE type = 'article' AND deleted_at IS NULL AND lang = 'zh-CN' ORDER BY id LIMIT 1");
            $read = $t->tool('article_read', ['id' => $article]);
            $t->check(($read['json']['result']['structuredContent']['id'] ?? 0) === $article, '读取安装数据里的一篇文章');
            $legacy = $t->post('initialize', [], null, ['MCP-Protocol-Version' => null, 'Mcp-Method' => null],
                (string) json_encode(['jsonrpc' => '2.0', 'id' => 'i', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) []]]));
            $t->check(($legacy['json']['result']['protocolVersion'] ?? '') === '2025-06-18', '旧式握手（Codex 0.165）经正式入口可用');
            $t->check($t->post('tools/list', [], null, [], null, 'GET')['status'] === 405, 'GET 405');
            $t->check($t->post('tools/list', [], null, [], str_repeat(' ', 256001))['status'] === 413, '入口按上限读取请求体，超限 413');
            $cookies = 0;
            foreach ($t->raw as $response) $cookies += preg_match('/^set-cookie:/mi', $response);
            $t->check($cookies === 0, '所有响应无 Set-Cookie');
            $t->check(S2EntryRun::sessionState($t->site) === $t->sessionBaseline, '入口请求不新增、不改动任何会话文件');
            $t->check((int) db()->fetchColumn('SELECT last_used_at FROM yikai_api_tokens WHERE admin_id = 57') > 0, '令牌记录最近使用时间');
        },
        '02 认证与审计' => function () use ($t): void {
            $before = $t->logCount();
            $t->tool('site_info');
            $row = db()->fetchOne('SELECT * FROM yikai_api_access_log ORDER BY id DESC LIMIT 1');
            $t->check($t->logCount() === $before + 1 && (int) $row['admin_id'] === 57 && $row['tool'] === 'site_info' && $row['result'] === 'ok' && $row['ip'] === '127.0.0.1',
                '已认证请求写一行审计：actor、工具、结果、IP');
            $none = $t->post('tools/list', [], null, ['Authorization' => null]);
            $t->check($none['status'] === 401 && str_starts_with($none['headers']['www-authenticate'] ?? '', 'Bearer'), '无凭据 401 + WWW-Authenticate');
            $t->check(db()->fetchColumn('SELECT result FROM yikai_api_access_log ORDER BY id DESC LIMIT 1') === 'ai_access_invalid_token', '认证失败也记审计');
            $t->check($t->post('tools/list', [], $t->other)['status'] === 200, '另一令牌可用');
            (new AiAccessTokenStore(db()))->revoke($t->otherId, 58, false, time());
            $t->check($t->post('tools/list', [], $t->other)['status'] === 401, '撤销后下一次请求即 401');
            $t->check($t->post('tools/list', [], null, ['Authorization' => null, 'Cookie' => 'IK_fixture=abc; yk_admin=1'])['status'] === 401, '后台 cookie 不能替代令牌');
            $failCount = static fn(): int => (int) db()->fetchColumn("SELECT COALESCE(SUM(hits), 0) FROM yikai_api_rate_limits WHERE bucket = 'ip:127.0.0.1:authfail'");
            $failsBefore = $failCount();
            $t->setting('ai_access_enabled', '0');
            $t->check($t->post('tools/list')['status'] === 403, '总开关关闭 403');
            $t->check($failCount() === $failsBefore, '403（配置原因）不计入来源认证失败次数');
            $t->setting('ai_access_enabled', '1');
            $logs = implode("\n", array_map('serialize', db()->fetchAll('SELECT * FROM yikai_api_access_log')));
            $t->check(!str_contains($logs, $t->editor) && !str_contains($logs, hash('sha256', $t->editor)) && !str_contains($logs, $t->other), '审计表无令牌明文或摘要');
        },
        '03 HTTPS 与可信代理' => function () use ($t): void {
            $forwarded = $t->post('tools/list', [], null, ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https']);
            $t->check($forwarded['status'] === 403 && str_contains($forwarded['body'], 'HTTPS required'), '未配置可信代理时，带转发头的回环请求不算安全连接（403）');
            $row = db()->fetchOne('SELECT http_status, result, admin_id FROM yikai_api_access_log ORDER BY id DESC LIMIT 1');
            $t->check((int) $row['http_status'] === 403 && $row['result'] === 'https_required' && (int) $row['admin_id'] === 0, '非 HTTPS 被拒也写审计（https_required），站长能看出原因');
            $proxyHost = $t->post('tools/list', [], null, ['Host' => 'www.example.com']);
            $t->check($proxyHost['status'] === 403 && str_contains($proxyHost['body'], 'HTTPS required'), '同机反向代理（回环、无转发头、Host 是公网域名）以 http 转来的请求不算安全连接');
            $t->check($t->post('tools/list', [], null, ['Host' => 'localhost'])['status'] === 200, '本机客户端用 localhost 访问仍可走 http');
            $t->setting('trusted_proxies', '127.0.0.1');
            $proxied = $t->post('tools/list', [], null, ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https']);
            $t->check($proxied['status'] === 200, '可信代理声明 https 时放行');
            $t->check(db()->fetchColumn('SELECT ip FROM yikai_api_access_log ORDER BY id DESC LIMIT 1') === '203.0.113.7', '审计 IP 取可信代理转发的客户端地址');
            $plain = $t->post('tools/list', [], null, ['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'http']);
            $t->check($plain['status'] === 403, '可信代理声明 http 时拒绝');
            $t->setting('trusted_proxies', '');
        },
        '04 限流' => function () use ($t): void {
            $window = $t->window();
            db()->execute('DELETE FROM yikai_api_rate_limits');
            $tokenId = (int) db()->fetchColumn('SELECT id FROM yikai_api_tokens WHERE admin_id = 57');
            db()->execute('INSERT INTO yikai_api_rate_limits (bucket, window_start, hits) VALUES (?, ?, ?)', ['token:' . $tokenId . ':read', $window, AiAccessThrottle::READ_PER_MINUTE - 1]);
            $t->check($t->post('tools/list')['status'] === 200, '读取额度内最后一次放行');
            $limited = $t->post('tools/list');
            $t->check($limited['status'] === 429 && (int) ($limited['headers']['retry-after'] ?? 0) >= 1 && ($limited['json']['error']['code'] ?? 0) === -32005, '超过每分钟读取额度 429 + Retry-After');
            db()->execute('DELETE FROM yikai_api_rate_limits');
            $window = $t->window();
            db()->execute('INSERT INTO yikai_api_rate_limits (bucket, window_start, hits) VALUES (?, ?, ?)', ['ip:127.0.0.1:authfail', $window, AiAccessThrottle::AUTH_FAIL_PER_MINUTE]);
            $before = $t->logCount();
            $t->check($t->post('tools/list', [], 'ykat_' . str_repeat('x', 43))['status'] === 429, '同一来源认证失败过多：再用错误令牌 429');
            $t->check($t->logCount() === $before, '超限的失败请求不写审计（无凭据请求不能无限写库）');
            $t->check($t->post('tools/list')['status'] === 200, '同一来源失败超限时，有效令牌照常可用（共用出口不会被他人刷错误令牌挡住）');
            db()->execute('DELETE FROM yikai_api_rate_limits');
        },
        '07 提案经正式入口（S3）' => function () use ($t): void {
            require_once dirname(__DIR__, 2) . '/includes/ai-access/AiProposalService.php';
            $names = array_column($t->post('tools/list', [], $t->writer)['json']['result']['tools'] ?? [], 'name');
            $t->check($names === AiAccessTokenStore::ISSUABLE_SCOPES, '带提案范围的令牌列出六个工具，没有 apply/undo/publish');
            $channel = (int) db()->fetchColumn("SELECT id FROM yikai_channels WHERE type = 'list' AND status = 1 AND lang = 'zh-CN' ORDER BY id LIMIT 1");
            $articles = (int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_contents');
            $args = ['idempotency_key' => 'entry-s3-key-0001', 'items' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => $channel, 'title' => 'S3 entry draft', 'content' => '<p>entry body</p>']]];
            $first = $t->tool('article_draft_propose', $args, $t->writer);
            $proposal = (string) ($first['json']['result']['structuredContent']['proposal_id'] ?? '');
            $t->check($first['status'] === 200 && preg_match('/^[0-9a-f]{32}$/', $proposal) === 1 && ($first['json']['result']['isError'] ?? true) === false, '经入口提交提案：返回提案 ID');
            $again = $t->tool('article_draft_propose', $args, $t->writer);
            $t->check(($again['json']['result']['structuredContent']['proposal_id'] ?? '') === $proposal && ($again['json']['result']['structuredContent']['replayed'] ?? false) === true, '同一幂等键重试返回同一提案（replayed）');
            $t->check((int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_contents') === $articles, '提交提案不写任何文章');
            $readOnly = $t->tool('article_draft_propose', $args);
            $t->check(($readOnly['json']['error']['code'] ?? 0) === -32602, '只读令牌调用提案工具：未知工具（不暴露存在）');
            $status = $t->tool('proposal_status', ['proposal_id' => $proposal], $t->writer);
            $t->check(($status['json']['result']['structuredContent']['items'][0]['status'] ?? '') === 'pending', 'proposal_status 查到待审');
            $row = db()->fetchOne("SELECT tool, target, result FROM yikai_api_access_log WHERE tool = 'article_draft_propose' ORDER BY id DESC LIMIT 1");
            $t->check(($row['target'] ?? '') === 'items:1' && ($row['result'] ?? '') === 'ok', '审计记提案条数，不记标题或正文');
            // 后台应用（与审核页同一服务）后，客户端查到已应用与草稿 ID，草稿未发布。
            $item = (string) db()->fetchColumn('SELECT i.public_id FROM yikai_ai_proposal_items i JOIN yikai_ai_proposals p ON p.id = i.proposal_id WHERE p.public_id = ?', [$proposal]);
            $applied = (new AiProposalService(db()))->apply(57, $item, time());
            $status = $t->tool('proposal_status', ['proposal_id' => $proposal], $t->writer);
            $draft = (int) ($status['json']['result']['structuredContent']['items'][0]['draft_id'] ?? 0);
            $t->check(($status['json']['result']['structuredContent']['items'][0]['status'] ?? '') === 'applied' && $draft === $applied['content_id'] && $draft > 0, '应用后 proposal_status 返回 applied 与草稿 ID');
            $t->check((int) db()->fetchColumn('SELECT status FROM yikai_contents WHERE id = ?', [$draft]) === 0, '应用结果是草稿（status=0），未发布');
            $t->check($t->tool('proposal_status', ['proposal_id' => $proposal])['json']['error']['code'] === -32602, '只读令牌不能查提案状态');
            $largeBody = '<p>' . str_repeat('x', 180000) . '</p>';
            $largeArgs = ['idempotency_key' => 'entry-s4-large-0001', 'items' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => $channel, 'title' => 'S4 large draft', 'content' => $largeBody]]];
            $large = $t->tool('article_draft_propose', $largeArgs, $t->writer);
            $largeItem = (string) ($large['json']['result']['structuredContent']['items'][0]['item_id'] ?? '');
            $t->check($large['status'] === 200 && $largeItem !== '', '180 KB 正文经正式入口成功进入提案队列');
            $t->check((int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_contents WHERE title = 'S4 large draft'") === 0, '大提案提交不写文章');
            if ($largeItem !== '') {
                $saved = (new AiProposalService(db()))->apply(57, $largeItem, time());
                $draftRow = db()->fetchOne('SELECT status, publish_time, content FROM yikai_contents WHERE id = ?', [$saved['content_id']]);
                $t->check($saved['status'] === 'applied' && (int) $draftRow['status'] === 0 && (int) $draftRow['publish_time'] === 0 && $draftRow['content'] === $largeBody, '大正文审核后完整保存为未发布草稿');
            } else $t->check(false, '大正文审核后完整保存为未发布草稿');
            // 提案额度每分钟 10 次，与读取额度分开计。
            $window = $t->window();
            db()->execute('DELETE FROM yikai_api_rate_limits');
            db()->execute('INSERT INTO yikai_api_rate_limits (bucket, window_start, hits) VALUES (?, ?, ?)', ['token:' . $t->writerId . ':propose', $window, AiAccessThrottle::PROPOSE_PER_MINUTE]);
            $limited = $t->tool('article_draft_propose', ['idempotency_key' => 'entry-s3-key-0002'] + $args, $t->writer);
            $t->check($limited['status'] === 429 && $t->post('tools/list', [], $t->writer)['status'] === 200, '提案超额 429，同令牌的读取不受影响');
            db()->execute('DELETE FROM yikai_api_rate_limits');
        },
        '06 Apache 改写与 Authorization 传递（仅 --apache）' => function () use ($t): void {
            if (!$t->apache()) {
                $t->check(true, '（php -S 模式跳过）');
                return;
            }
            foreach (['/', '/index.php'] as $suffix) {
                $t->at($suffix);
                $r = $t->post('tools/list');
                $t->check($r['status'] === 200 && count($r['json']['result']['tools'] ?? []) === 4, "子目录站 /sub/mcp{$suffix}：mod_fcgid 下令牌送达");
            }
            // 不带斜杠时 mod_dir 先于 .htaccess 改写补斜杠（301）；客户端地址必须写成 /mcp/（ADR-003 §3）。
            $t->at('');
            $t->check($t->post('tools/list')['status'] === 301, '已知限制：Apache 下 /sub/mcp（无斜杠）被 mod_dir 301，客户端须配 /mcp/');
            $t->at('/');
            $htaccess = $t->site . '/.htaccess';
            $original = (string) file_get_contents($htaccess);
            file_put_contents($htaccess, preg_replace('/^\s*RewriteRule \^mcp.*$/m', '', $original));
            $t->check($t->post('tools/list')['status'] === 401, '对照：去掉 .htaccess 的 mcp 改写后，mod_fcgid 丢弃 Authorization（401）');
            file_put_contents($htaccess, $original);
            $t->check($t->post('tools/list')['status'] === 200, '恢复改写规则后正常');
        },
        '05 故障时拒绝' => function () use ($t): void {
            db()->execute('ALTER TABLE yikai_api_rate_limits RENAME TO yikai_api_rate_limits_off');
            $t->check($t->post('tools/list')['status'] === 503, '限流存储不可用 503（不放行）');
            db()->execute('ALTER TABLE yikai_api_rate_limits_off RENAME TO yikai_api_rate_limits');
            db()->execute('ALTER TABLE yikai_api_access_log RENAME TO yikai_api_access_log_off');
            $failed = $t->post('tools/list');
            $t->check($failed['status'] === 503 && !str_contains($failed['body'], 'tools'), '审计写入失败时不交付读取结果（503）');
            db()->execute('ALTER TABLE yikai_api_access_log_off RENAME TO yikai_api_access_log');
            $config = $t->site . '/config/config.php';
            $original = (string) file_get_contents($config);
            file_put_contents($config, $original . "\n// changed after preparation\n");
            $t->check($t->post('tools/list')['status'] === 503, '配置文件变更后桥接失效（503），不连旧库');
            file_put_contents($config, $original);
            $t->check($t->post('tools/list')['status'] === 200, '还原配置后恢复');
            $errors = implode("\n", $t->raw);
            $t->check(!str_contains($errors, 'SQLSTATE') && !str_contains($errors, str_replace('\\', '/', $t->root)) && !str_contains($errors, $t->root), '错误响应不含 SQL 错误或服务器路径');
        },
    ];
}

// Allow the S4 environment runner to reuse the exact entry assertions and synthetic site.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

$out = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
$httpd = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--apache=')) $httpd = substr($arg, 9);
$t = new S2EntryRun($repo, $httpd);
try {
    $t->build();
    $t->start();
    require_once $repo . '/includes/ai-access/AiAccessThrottle.php';
    foreach (s2EntryScenarios($t) as $name => $fn) {
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
printf("php=%s assertions=%d failed=%d\n", PHP_VERSION, $total, $failed);
if ($out !== null) {
    file_put_contents($out, json_encode(['php' => PHP_VERSION, 'assertions' => $total, 'failed' => $failed,
        'scenarios' => array_map(static fn(array $c): array => array_map(static fn(array $x): array => ['ok' => $x[0], 'label' => $x[1]], $c), $t->results)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
exit($failed === 0 ? 0 : 1);
