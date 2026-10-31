<?php
/** Isolated Windows nginx + PHP FastCGI + verified TLS. Never reloads an installed service. */
declare(strict_types=1);

require __DIR__ . '/mcp-entry-run.php';
$args = getopt('', ['nginx:', 'out:']);
$nginx = (string) ($args['nginx'] ?? '');
if (!is_file($nginx)) { fwrite(STDERR, "Supply --nginx=<nginx.exe>\n"); exit(2); }
$t = new S2EntryRun(dirname(__DIR__, 2));
$processes = [];
$failed = 0;
$port = static function (): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) substr($name, strrpos($name, ':') + 1);
};
$wait = static function (int $port): void {
    for ($i = 0; $i < 100; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $no, $error, .1);
        if ($s) { fclose($s); return; }
        usleep(100000);
    }
    throw new RuntimeException('test service did not start');
};
$quote = static fn(string $s): string => '"' . str_replace('\\', '/', $s) . '"';
try {
    $t->build();
    $http = $port(); $https = $port(); $fcgi = $port();
    foreach (['logs', 'temp', 'temp/client_body', 'temp/fastcgi'] as $dir) mkdir($t->root . '/' . $dir, 0700, true);
    // Self-contained fixture certificate with an IP SAN; PHP verifies both chain and hostname.
    $opensslConfig = $t->root . '/openssl.cnf';
    file_put_contents($opensslConfig, "[req]\ndistinguished_name=dn\nx509_extensions=ext\nprompt=no\n[dn]\nCN=127.0.0.1\n[ext]\nsubjectAltName=IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $sslOptions = ['config' => $opensslConfig, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($sslOptions);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, $sslOptions);
    $cert = openssl_csr_sign($csr, null, $key, 1, $sslOptions + ['x509_extensions' => 'ext']);
    if (!$cert || !openssl_pkey_export_to_file($key, $t->root . '/server.key', null, $sslOptions)
        || !openssl_x509_export_to_file($cert, $t->root . '/server.crt')) throw new RuntimeException('fixture certificate generation failed');
    $t->caFile = $t->root . '/server.crt';
    copy(dirname($nginx) . '/conf/fastcgi_params', $t->root . '/fastcgi_params');
    // Use the shipped full server snippet, changing only the isolated FastCGI port.
    $snippet = str_replace('127.0.0.1:9000', '127.0.0.1:' . $fcgi, (string) file_get_contents($repo . '/deploy/nginx-server.conf'));
    file_put_contents($t->root . '/site.conf', $snippet);
    $config = 'worker_processes 1; daemon off; pid logs/nginx.pid; error_log logs/error.log; events { worker_connections 128; } http {'
        . ' client_body_temp_path temp/client_body; fastcgi_temp_path temp/fastcgi; access_log off; server {'
        . ' listen 127.0.0.1:' . $http . '; listen 127.0.0.1:' . $https . ' ssl; server_name 127.0.0.1 localhost;'
        . ' ssl_certificate ' . $quote($t->root . '/server.crt') . '; ssl_certificate_key ' . $quote($t->root . '/server.key') . ';'
        . ' root ' . $quote($t->site) . '; include site.conf; }}';
    file_put_contents($t->root . '/nginx.conf', $config);
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $descriptors = [0 => ['file', $null, 'r'], 1 => ['file', $t->root . '/service.log', 'a'], 2 => ['file', $t->root . '/service.log', 'a']];
    $processes[] = proc_open([dirname(PHP_BINARY) . '/php-cgi.exe', '-b', '127.0.0.1:' . $fcgi, '-d', 'session.save_path=' . $t->root . '/sessions'], $descriptors, $pipes, $t->root);
    $wait($fcgi);
    $processes[] = proc_open([$nginx, '-p', str_replace('\\', '/', $t->root) . '/', '-c', 'nginx.conf'], $descriptors, $pipes, dirname($nginx));
    $wait($http);
    $t->url = 'http://127.0.0.1:' . $http . '/mcp/';
    require_once $repo . '/includes/ai-access/AiAccessThrottle.php';
    foreach (s2EntryScenarios($t) as $name => $fn) {
        $t->scenario = $name;
        try { $fn(); } catch (Throwable $e) { $t->check(false, 'scenario exception: ' . $e->getMessage()); }
    }
    $t->scenario = 'S4 nginx routing, verified TLS and large draft';
    db()->execute('DELETE FROM yikai_api_rate_limits');
    foreach (['', '/', '/index.php'] as $suffix) {
        $t->url = 'https://127.0.0.1:' . $https . '/mcp' . $suffix;
        $t->check($t->post('tools/list')['status'] === 200, 'verified HTTPS works for /mcp' . $suffix . ' without a redirect');
    }
    $t->url = 'https://127.0.0.1:' . $https . '/mcp/';
    $t->check($t->post('tools/list', [], null, ['Authorization' => null])['status'] === 401, 'TLS does not substitute for bearer authentication');
    $channel = (int) db()->fetchColumn("SELECT id FROM yikai_channels WHERE type = 'list' AND status = 1 AND lang = 'zh-CN' ORDER BY id LIMIT 1");
    $result = $t->tool('article_draft_propose', ['idempotency_key' => 's4-nginx-large-draft', 'items' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => $channel,
        'title' => 'S4 TLS large draft', 'content' => '<p>' . str_repeat('a', 180000) . '</p>']]], $t->writer);
    $proposal = $result['json']['result']['structuredContent'] ?? [];
    $t->check($result['status'] === 200 && isset($proposal['proposal_id']), 'a 180 KB article proposal passes real nginx TLS and FastCGI');
    $t->check((int) db()->fetchColumn("SELECT COUNT(*) FROM yikai_contents WHERE title = 'S4 TLS large draft'") === 0, 'submission alone creates no article');
    if (isset($proposal['items'][0]['item_id'])) {
        $applied = (new AiProposalService(db()))->apply(57, $proposal['items'][0]['item_id'], time());
        $t->check($applied['status'] === 'applied' && (int) db()->fetchColumn('SELECT status FROM yikai_contents WHERE id = ?', [$applied['content_id']]) === 0, 'review applies the large proposal strictly as a draft');
    } else $t->check(false, 'large proposal can be reviewed');
    $t->check($t->post('tools/list', [], null, [], str_repeat(' ', 256001))['status'] === 413, 'oversized HTTPS request gets application 413');
    $t->url = 'https://127.0.0.1:' . $https . '/mcp/';
    $t->setting('ai_access_enabled', '0');
    $t->check($t->post('tools/list')['status'] === 403, 'disabling external access takes effect over TLS');
} catch (Throwable $error) {
    $t->scenario = 'environment';
    $t->check(false, $error->getMessage());
} finally {
    foreach (array_reverse($processes) as $process) {
        if (!is_resource($process)) continue;
        $pid = (int) (proc_get_status($process)['pid'] ?? 0);
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
        proc_terminate($process); proc_close($process);
    }
    $total = 0;
    foreach ($t->results as $name => $checks) foreach ($checks as [$ok, $label]) {
        $total++; if (!$ok) { $failed++; echo "FAIL {$name}: {$label}\n"; }
    }
    $report = ['php' => PHP_VERSION, 'assertions' => $total, 'failed' => $failed, 'tls' => 'fixture CA with certificate and hostname verification; OS trust store unchanged', 'scenarios' => $t->results];
    if (isset($args['out'])) file_put_contents($args['out'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "assertions={$total} failed={$failed}\n";
    $t->stop();
}
exit($failed === 0 ? 0 : 1);
