<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Two disposable source installations. Never reuse an installed directory or an existing database.
$source = dirname(__DIR__, 2);
$temp = sys_get_temp_dir() . '/yk-rewrite-install-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
$copy = static function(string $from, string $to) use (&$copy): void {
    if (is_link($from)) return;
    if (is_file($from)) { copy($from, $to); return; }
    if (!is_dir($to)) mkdir($to, 0700, true);
    foreach (scandir($from) ?: [] as $name) {
        if ($name[0] === '.' || $name === 'config.php') continue;
        $copy($from . '/' . $name, $to . '/' . $name);
    }
};
$delete = static function(string $path) use (&$delete, $temp): void {
    $resolved = realpath($path);
    $boundary = realpath($temp);
    if ($resolved === false || $boundary === false || ($resolved !== $boundary && !str_starts_with($resolved, $boundary . DIRECTORY_SEPARATOR))) throw new RuntimeException('Cleanup boundary violated');
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $delete($path . '/' . $entry);
        rmdir($path);
    } else unlink($path);
};
$request = static function(int $port, string $path, ?array $data = null): array {
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$code, $body];
};
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    foreach (['query', 'pretty'] as $mode) {
        $root = $temp . '/' . $mode;
        mkdir($root);
        foreach (['config', 'includes', 'install', 'migrations', 'lang'] as $directory) $copy($source . '/' . $directory, $root . '/' . $directory);
        mkdir($root . '/themes');
        $copy($source . '/themes/default', $root . '/themes/default');
        foreach (glob($source . '/*.php') ?: [] as $file) copy($file, $root . '/' . basename($file));
        mkdir($root . '/storage');
        mkdir($root . '/uploads');
        mkdir($root . '/plugins');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) throw new RuntimeException('Cannot allocate test port');
        $address = (string) stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $router = $source . ($mode === 'pretty' ? '/tests/e2e/router.php' : '/tests/fixtures/no-rewrite-router.php');
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'extension=pdo_sqlite', '-S', '127.0.0.1:' . $port, '-t', $root, $router],
            [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
        if (!is_resource($process)) throw new RuntimeException('Server start failed');
        try {
            fclose($pipes[0]);
            for ($attempt = 0; $attempt < 30; $attempt++) {
                [$status] = $request($port, '/install/index.php');
                if ($status === 200) break;
                usleep(100000);
            }
            $nonce = bin2hex(random_bytes(16));
            foreach (['/contact.html', '/en/contact.html'] as $path) {
                [$status, $body] = $request($port, $path . '?__yk_route_probe=' . $nonce);
                $assert($mode === 'pretty' ? ($status === 200 && (json_decode((string) $body, true)['nonce'] ?? '') === $nonce) : $status === 404, 'Pre-install route probe failed');
            }
            $input = ['action' => 'install', 'db_driver' => 'sqlite', 'db_prefix' => 'yikai_', 'admin_user' => 'admin',
                'admin_pass' => 'isolated@Test123', 'site_name' => 'Rewrite install test', 'site_url' => 'http://127.0.0.1:' . $port,
                'site_lang' => 'zh-CN', 'admin_lang' => 'zh-CN', 'install_demo' => '0'];
            // Omit the result on the query leg: absent JS / direct installer POST must be safe too.
            if ($mode === 'pretty') $input['rewrite_supported'] = '1';
            [, $body] = $request($port, '/install/index.php', $input);
            $assert((json_decode((string) $body, true)['success'] ?? false) === true, 'Fresh installation failed: ' . (string) $body);
            $pdo = new PDO('sqlite:' . $root . '/storage/database.sqlite');
            $row = $pdo->query("SELECT value, options FROM yikai_settings WHERE `key` = 'url_mode'")->fetch(PDO::FETCH_ASSOC);
            $assert($row['value'] === $mode && str_contains($row['options'], 'pretty'), 'Wrong installed mode or erased metadata');
            [, $body] = $request($port, '/install/index.php?step=4', $input);
            $assert((json_decode((string) $body, true)['success'] ?? true) === false, 'Installed lock bypassed');
            $pdo = null;
            echo 'PASS: fresh ' . $mode . ' installation, preserved options, pre-install probe and installed lock on PHP ' . PHP_VERSION . "\n";
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
} finally {
    $delete($temp);
}
