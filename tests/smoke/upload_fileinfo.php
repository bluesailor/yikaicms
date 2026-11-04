<?php
/** Real multipart requests, isolated SQLite smoke fixture, no production configuration. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);
$options = getopt('', ['report:']);
$reportPath = (string) ($options['report'] ?? '');
if ($reportPath === '' || !is_dir(dirname($reportPath))) throw new RuntimeException('Existing --report directory required');
if (!is_file($root . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable smoke setup required');
define('ROOT_PATH', $root);
require $root . '/config/config.php';
if (DB_DRIVER !== 'sqlite' || DB_PREFIX !== 'yikai_' || DB_PATH !== $root . '/storage/database.sqlite'
    || !preg_match('#^http://127\.0\.0\.1:([0-9]+)$#D', SITE_URL, $match)) throw new RuntimeException('Owned loopback SQLite only');
$port = (int) $match[1];
$socket = @stream_socket_server('tcp://127.0.0.1:' . $port);
if (!$socket) throw new RuntimeException('HTTP port already occupied');
fclose($socket);
$pdo = new PDO('sqlite:' . DB_PATH);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$initialIds = $pdo->query('SELECT id FROM yikai_media ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$temp = sys_get_temp_dir() . '/yk-upload-fileinfo-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
mkdir($temp . '/sessions', 0700);
$healthName = 'tests/smoke/upload-health-' . bin2hex(random_bytes(6)) . '.php';
$nonce = bin2hex(random_bytes(16));
$process = null;
$report = ['php' => PHP_VERSION, 'debug' => DEBUG, 'error_reporting' => error_reporting(), 'checks' => [], 'profiles' => [], 'passed' => false];
$check = static function (bool $ok, string $label) use (&$report): void {
    $report['checks'][] = ['ok' => $ok, 'label' => $label];
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
};
$files = static function () use ($root): array {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/uploads', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) $out[$file->getPathname()] = hash_file('sha256', $file->getPathname());
    }
    ksort($out); return $out;
};
$initialFiles = $files();
$snapshot = static fn(): string => hash('sha256', json_encode([$pdo->query('SELECT * FROM yikai_media ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), $files()]));
$request = static function (string $path, string $jar, ?array $form = null): array {
    $ch = curl_init(SITE_URL . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest']]);
    if ($form !== null) {
        $multipart = (bool) array_filter($form, static fn($v): bool => $v instanceof CURLFile);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart ? $form : http_build_query($form)]);
    }
    $body = (string) curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); unset($ch);
    return [$status, $body, json_decode($body, true)];
};
try {
    file_put_contents($root . '/' . $healthName, '<?php header("Content-Type: application/json"); echo json_encode(["nonce"=>' . var_export($nonce, true)
        . ',"php"=>PHP_VERSION,"fileinfo"=>extension_loaded("fileinfo"),"mime"=>function_exists("mime_content_type"),"open"=>function_exists("finfo_open"),"file"=>function_exists("finfo_file")]);');
    $image = imagecreatetruecolor(803, 601);
    imagefill($image, 0, 0, imagecolorallocate($image, 23, 83, 124));
    imagepng($image, $temp . '/image.png'); imagejpeg($image, $temp . '/image.jpg');
    imagegif($image, $temp . '/image.gif'); imagewebp($image, $temp . '/image.webp');
    unset($image);
    file_put_contents($temp . '/file.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
    file_put_contents($temp . '/fake.jpg', '<?php echo "not-an-image";');
    file_put_contents($temp . '/file.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="5" height="5"><rect width="5" height="5"/></svg>');
    $noFileinfo = ['-n', '-d', 'extension_dir=' . dirname(PHP_BINARY) . '/ext'];
    // The local Windows gate loads the actual DLLs, deliberately excluding Fileinfo.
    if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('This isolated no-extension profile currently supports Windows PHP distributions');
    foreach (['mbstring', 'pdo_sqlite', 'openssl', 'gd', 'curl', 'zip'] as $extension) {
        $dll = dirname(PHP_BINARY) . '/ext/php_' . $extension . '.dll';
        if (!is_file($dll)) throw new RuntimeException('Missing test extension: ' . $extension);
        array_push($noFileinfo, '-d', 'extension=' . $dll);
    }
    $profiles = ['normal' => [], 'mime-disabled' => ['-d', 'disable_functions=mime_content_type'],
        'finfo-functions-disabled' => ['-d', 'disable_functions=finfo_open,finfo_file,finfo_close'], 'no-fileinfo' => $noFileinfo];
    foreach ($profiles as $name => $flags) {
        $jar = $temp . '/' . $name . '.cookies'; $log = $temp . '/' . $name . '.log';
        $appLog = $root . '/storage/logs/error-' . date('Ym') . '.log';
        clearstatcache(true, $appLog);
        $appOffset = is_file($appLog) ? (int) filesize($appLog) : 0;
        $process = proc_open([PHP_BINARY, ...$flags, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'session.save_path=' . $temp . '/sessions',
            '-S', '127.0.0.1:' . $port, '-t', $root], [0 => ['file', 'NUL', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root);
        if (!is_resource($process)) throw new RuntimeException('Owned server start failed');
        $report['profiles'][$name]['pid'] = proc_get_status($process)['pid'];
        $ready = false;
        for ($i = 0; $i < 70; $i++) {
            if (!proc_get_status($process)['running']) throw new RuntimeException('Owned server exited: ' . substr((string) file_get_contents($log), -1500));
            [$status, , $health] = $request('/' . $healthName, $jar);
            if ($status === 200 && ($health['nonce'] ?? '') === $nonce) { $ready = true; break; }
            usleep(100000);
        }
        if (!$ready) throw new RuntimeException('Owned server readiness failed');
        unset($health['nonce']); $report['profiles'][$name]['runtime'] = $health;
        $capability = $health['php'] === PHP_VERSION && match ($name) {
            'normal' => $health['fileinfo'] && $health['mime'] && $health['open'] && $health['file'],
            'mime-disabled' => !$health['mime'] && $health['open'] && $health['file'],
            'finfo-functions-disabled' => $health['mime'] && !$health['open'] && !$health['file'],
            'no-fileinfo' => !$health['fileinfo'] && !$health['mime'] && !$health['open'] && !$health['file'],
        };
        if (!$capability) throw new RuntimeException('Test profile did not disable the intended capability');
        $check(true, $name . ': real PHP capability profile verified');
        [, $login] = $request('/admin/login.php', $jar);
        preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $login, $csrf);
        [$status] = $request('/admin/login.php', $jar, ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $csrf[1] ?? '']);
        if ($status !== 302) throw new RuntimeException('Synthetic login failed: ' . $name);
        [, $profile] = $request('/admin/profile.php', $jar);
        preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $profile, $csrf);
        if (empty($csrf[1])) throw new RuntimeException('No authenticated CSRF token');
        $token = $csrf[1];
        foreach (['/admin/upload.php', '/admin/media_api.php?action=upload'] as $endpoint) {
            foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'] as $ext => $mime) {
                [$status, , $json] = $request($endpoint, $jar, ['_token' => $token, 'type' => 'images', 'file' => new CURLFile($temp . '/image.' . $ext, 'application/pdf', 'test.' . $ext)]);
                $id = (int) ($json['data']['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT mime, width, height, path FROM yikai_media WHERE id=?'); $stmt->execute([$id]); $row = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
                $check($status === 200 && ($json['code'] ?? -1) === 0 && $row && $row['mime'] === $mime
                    && (int) $row['width'] === 803 && (int) $row['height'] === 601 && is_file($row['path']), $name . ': ' . $endpoint . ' actual ' . $ext . ' MIME stored; spoofed client MIME ignored');
            }
            foreach (['fake.jpg' => 'fake.jpg', 'image.png' => 'renamed.jpg', 'file.pdf' => 'file.pdf', 'file.svg' => 'file.svg'] as $file => $filename) {
                $before = $snapshot(); $document = in_array($file, ['file.pdf', 'file.svg'], true);
                [$status, , $json] = $request($endpoint, $jar, ['_token' => $token, 'type' => $file === 'file.pdf' ? 'files' : 'images',
                    'file' => new CURLFile($temp . '/' . $file, 'image/jpeg', $filename)]);
                if ($document && $name !== 'no-fileinfo') {
                    $check($status === 200 && ($json['code'] ?? -1) === 0, $name . ': ' . $endpoint . ' verified ' . $file . ' accepted');
                } else {
                    $message = (string) ($json['msg'] ?? '');
                    $check($status === 200 && ($json['code'] ?? 0) !== 0 && $before === $snapshot()
                        && (!$document || str_contains($message, 'Fileinfo')), $name . ': ' . $endpoint . ' rejects ' . $filename . ' clearly without file/DB changes');
                }
            }
            $before = $snapshot();
            [$status, , $json] = $request($endpoint, $jar, ['type' => 'images', 'file' => new CURLFile($temp . '/image.png', 'image/png', 'test.png')]);
            $check($status !== 500 && ($json['code'] ?? 0) !== 0 && $before === $snapshot(), $name . ': ' . $endpoint . ' CSRF rejection preserved');
            [$status, , $json] = $request($endpoint, $temp . '/anonymous.cookies', ['_token' => $token, 'type' => 'images', 'file' => new CURLFile($temp . '/image.png', 'image/png', 'test.png')]);
            $check($status !== 500 && ($json['code'] ?? 0) !== 0 && $before === $snapshot(), $name . ': ' . $endpoint . ' anonymous rejection preserved');
        }
        proc_terminate($process); proc_close($process); $process = null;
        $text = (string) file_get_contents($log);
        $newAppLog = is_file($appLog) ? substr((string) file_get_contents($appLog), $appOffset) : '';
        $report['profiles'][$name]['new_application_log_bytes'] = strlen($newAppLog);
        $text .= $newAppLog;
        $check(!preg_match('/Fatal error|Uncaught|Warning:|\[WARNING\]|\[ERROR\]/i', $text), $name . ': PHP and application logs have no warnings/errors');
    }
} catch (Throwable $e) {
    $report['error'] = $e->getMessage(); echo 'ERROR ' . $e->getMessage() . "\n";
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_file($root . '/' . $healthName)) unlink($root . '/' . $healthName);
    foreach (glob($temp . '/*.cookies') ?: [] as $cookie) unlink($cookie);
    foreach (glob($temp . '/sessions/*') ?: [] as $session) if (is_file($session)) unlink($session);
    rmdir($temp . '/sessions');
    foreach (array_diff_key($files(), $initialFiles) as $path => $hash) {
        if (is_link($path) || !str_starts_with(str_replace('\\', '/', (string) realpath($path)), str_replace('\\', '/', $root) . '/uploads/')) throw new RuntimeException('Refusing unowned upload cleanup');
        unlink($path);
    }
    foreach ($pdo->query('SELECT id FROM yikai_media')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (!in_array($id, $initialIds, true)) { $stmt = $pdo->prepare('DELETE FROM yikai_media WHERE id=?'); $stmt->execute([$id]); }
    }
    $report['checks'][] = ['ok' => $files() === $initialFiles, 'label' => 'Upload fixture files restored'];
    $report['passed'] = !isset($report['error']) && !array_filter($report['checks'], static fn($r): bool => !$r['ok']);
    $report['assertions'] = count($report['checks']);
    $report['failed'] = count(array_filter($report['checks'], static fn($r): bool => !$r['ok']));
    $report['diagnostics'] = $temp;
    foreach (['admin/upload.php', 'admin/media_api.php', 'includes/functions.php', 'includes/image.php', 'includes/MarketInstallOrigin.php', 'includes/i18n/LanguagePacks.php'] as $file) $report['source_sha256'][$file] = hash_file('sha256', $root . '/' . $file);
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo json_encode(['passed' => $report['passed'], 'assertions' => $report['assertions'], 'failed' => $report['failed'], 'error' => $report['error'] ?? null]) . "\n";
}
exit($report['passed'] ? 0 : 1);
