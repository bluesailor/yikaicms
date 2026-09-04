<?php

declare(strict_types=1);

/**
 * Installs a published N-1 full package, applies the current full package through
 * the real online-upgrade HTTP actions, then runs and verifies all migrations.
 * Run this file with the oldest PHP version supported by the release.
 */

/** @return array{code:int,body:string} */
function upgradeRequest(string $url, string $cookieJar, ?array $post = null): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize curl.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'YikaiCMS-NMinusOne-Upgrade-Test',
    ];
    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }
    return ['code' => $code, 'body' => (string) $body];
}

/** @return array<string, mixed> */
function upgradeJsonAction(string $base, string $cookieJar, string $csrf, string $action, array $extra = []): array
{
    $response = upgradeRequest(
        $base . '/admin/upgrade_online.php',
        $cookieJar,
        ['action' => $action, '_token' => $csrf] + $extra
    );
    $data = json_decode($response['body'], true);
    if ($response['code'] !== 200 || !is_array($data)) {
        throw new RuntimeException(
            $action . ' returned HTTP ' . $response['code'] . ': ' . substr($response['body'], 0, 400)
        );
    }
    return $data;
}

/** @return array{code:int,output:string} */
function upgradeRunCommand(array $command, ?string $cwd = null): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return ['code' => $code, 'output' => (string) $stdout . (string) $stderr];
}

function upgradeRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) && !is_link($child) ? upgradeRemoveTree($child) : @unlink($child);
    }
    @rmdir($path);
}

function upgradeExtractSite(string $package, string $target): string
{
    if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
        throw new RuntimeException('Unable to create package extraction directory.');
    }
    $zip = new ZipArchive();
    if ($zip->open($package) !== true || !$zip->extractTo($target)) {
        throw new RuntimeException('Unable to extract package: ' . $package);
    }
    $zip->close();
    if (is_file($target . '/index.php')) {
        return $target;
    }
    $children = array_values(array_filter(array_diff(scandir($target) ?: [], ['.', '..']), static function (string $item) use ($target): bool {
        return is_dir($target . DIRECTORY_SEPARATOR . $item);
    }));
    if (count($children) !== 1 || !is_file($target . '/' . $children[0] . '/index.php')) {
        throw new RuntimeException('Package does not contain one valid site root.');
    }
    return $target . '/' . $children[0];
}

function upgradeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "  OK  {$message}\n";
}

$options = getopt('', [
    'from-package:', 'to-package:', 'from:', 'to:', 'db::',
    'db-host::', 'db-port::', 'db-user::', 'db-pass::', 'keep',
]);
$fromPackage = realpath((string) ($options['from-package'] ?? ''));
$toPackage = realpath((string) ($options['to-package'] ?? ''));
$fromVersion = trim((string) ($options['from'] ?? ''));
$toVersion = trim((string) ($options['to'] ?? ''));
$dbKind = (string) ($options['db'] ?? 'mysql');
$keep = array_key_exists('keep', $options);
if ($fromPackage === false || $toPackage === false
    || !preg_match('/^1\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $fromVersion)
    || !preg_match('/^1\.[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $toVersion)
    || !in_array($dbKind, ['mysql', 'sqlite'], true)) {
    fwrite(STDERR, "Usage: php tests/smoke/package_upgrade.php --from-package=<zip> --to-package=<zip> --from=1.x.y --to=1.x.y [--db=mysql|sqlite]\n");
    exit(2);
}

$tempBase = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'yikaicms-nminus1-' . bin2hex(random_bytes(5));
$cookieJar = $tempBase . DIRECTORY_SEPARATOR . 'cookies.txt';
$serverLog = $tempBase . DIRECTORY_SEPARATOR . 'php-server.log';
$dbName = 'yikaicms_upgrade_test_' . bin2hex(random_bytes(4));
$server = null;
$pdo = null;
$failure = null;

try {
    if (!mkdir($tempBase, 0700, true) && !is_dir($tempBase)) {
        throw new RuntimeException('Unable to create temporary directory.');
    }
    $siteRoot = upgradeExtractSite($fromPackage, $tempBase . DIRECTORY_SEPARATOR . 'site');
    echo "N-1 package: v{$fromVersion}\nTarget package: v{$toVersion}\nRuntime: PHP " . PHP_VERSION . " / {$dbKind}\n";

    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if ($socket === false) {
        throw new RuntimeException('Unable to reserve HTTP port: ' . $socketMessage);
    }
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $base = 'http://127.0.0.1:' . $port;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $serverLog, 'a'],
        2 => ['file', $serverLog, 'a'],
    ];
    $pipes = [];
    $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $siteRoot], $descriptors, $pipes, $siteRoot);
    if (!is_resource($server)) {
        throw new RuntimeException('Unable to start PHP test server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $probe = upgradeRequest($base . '/install/index.php', $cookieJar);
            if ($probe['code'] === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable $exception) {
        }
        usleep(200000);
    }
    upgradeAssert($ready, 'published N-1 package starts under the target PHP runtime');

    $install = [
        'action' => 'install',
        'db_prefix' => 'yikai_',
        'admin_user' => 'admin',
        'admin_pass' => 'smoke@Test123',
        'admin_email' => 'upgrade@example.test',
        'site_name' => 'YikaiCMS N-1 Upgrade',
        'site_url' => $base,
        'site_lang' => 'zh-CN',
        'admin_lang' => 'zh-CN',
        'install_demo' => '1',
    ];
    if ($dbKind === 'mysql') {
        $dbHost = (string) ($options['db-host'] ?? '127.0.0.1');
        $dbPort = (string) ($options['db-port'] ?? '3306');
        $dbUser = (string) ($options['db-user'] ?? 'root');
        $dbPass = (string) ($options['db-pass'] ?? '123456');
        $pdo = new PDO('mysql:host=' . $dbHost . ';port=' . $dbPort . ';charset=utf8mb4', $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE `' . $dbName . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $install += [
            'db_driver' => 'mysql', 'db_host' => $dbHost, 'db_port' => $dbPort,
            'db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass,
        ];
    } else {
        $install['db_driver'] = 'sqlite';
    }
    $installResponse = upgradeRequest($base . '/install/index.php', $cookieJar, $install);
    $installData = json_decode($installResponse['body'], true);
    upgradeAssert($installResponse['code'] === 200 && is_array($installData) && !empty($installData['success']), 'published N-1 package installs through its real HTTP installer');

    $installedVersion = '';
    $versionSource = (string) @file_get_contents($siteRoot . '/config/version.php');
    if (preg_match("/CMS_VERSION'\s*,\s*'([^']+)'/", $versionSource, $versionMatch)) {
        $installedVersion = (string) $versionMatch[1];
    }
    upgradeAssert($installedVersion === $fromVersion, 'installed baseline is exactly v' . $fromVersion);

    $loginPage = upgradeRequest($base . '/admin/login.php', $cookieJar);
    if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $loginPage['body'], $csrfMatch)
        && !preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $loginPage['body'], $csrfMatch)) {
        throw new RuntimeException('Unable to read login CSRF token.');
    }
    $login = upgradeRequest($base . '/admin/login.php', $cookieJar, [
        'username' => 'admin', 'password' => 'smoke@Test123', '_token' => $csrfMatch[1],
    ]);
    upgradeAssert($login['code'] === 302, 'administrator login succeeds on the N-1 package');
    $adminPage = upgradeRequest($base . '/admin/index.php', $cookieJar);
    if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $adminPage['body'], $csrfMatch)) {
        throw new RuntimeException('Unable to read authenticated CSRF token.');
    }
    $csrf = (string) $csrfMatch[1];

    $upgradeDir = $siteRoot . '/storage/upgrade';
    if (!is_dir($upgradeDir) && !mkdir($upgradeDir, 0755, true) && !is_dir($upgradeDir)) {
        throw new RuntimeException('Unable to create upgrade directory.');
    }
    if (!copy($toPackage, $upgradeDir . '/package.zip')) {
        throw new RuntimeException('Unable to stage target package.');
    }
    file_put_contents($upgradeDir . '/package-meta.json', json_encode([
        'version' => $toVersion,
        'hash' => hash_file('sha256', $toPackage),
        'verified_at' => time(),
        'owner' => 'manual',
    ], JSON_UNESCAPED_SLASHES));

    $prepare = upgradeJsonAction($base, $cookieJar, $csrf, 'apply_prepare');
    upgradeAssert(($prepare['code'] ?? 1) === 0 && ($prepare['mode'] ?? '') === 'full', 'online upgrader prepares the real target full package');
    upgradeAssert(!empty($prepare['db_backup']), 'online upgrader creates a database backup before file replacement');
    $total = (int) ($prepare['total'] ?? 0);
    $offset = 0;
    $copied = 0;
    for ($round = 0; $round < 1000 && $offset < $total; $round++) {
        $batch = upgradeJsonAction($base, $cookieJar, $csrf, 'apply_batch', ['offset' => (string) $offset]);
        if (($batch['code'] ?? 1) !== 0 || !empty($batch['errors'])) {
            throw new RuntimeException('Upgrade batch failed: ' . json_encode($batch, JSON_UNESCAPED_UNICODE));
        }
        $next = (int) ($batch['next'] ?? -1);
        if ($next <= $offset) {
            throw new RuntimeException('Upgrade batch cursor did not advance.');
        }
        $offset = $next;
        $copied += (int) ($batch['copied'] ?? 0);
    }
    upgradeAssert($total > 0 && $offset === $total && $copied === $total, 'all package entries are applied in dependency-safe batches');

    $finalize = upgradeJsonAction($base, $cookieJar, $csrf, 'apply_finalize', ['note' => 'N-1 package upgrade gate']);
    upgradeAssert(($finalize['code'] ?? 1) === 0, 'online upgrade finalization and health check succeed');
    upgradeAssert(($finalize['new_version'] ?? '') === $toVersion, 'online upgrader reports target version v' . $toVersion);

    $migration = upgradeRunCommand([PHP_BINARY, $siteRoot . '/bin/yikai.php', 'migrate:run', '--yes'], $siteRoot);
    upgradeAssert($migration['code'] === 0, 'post-upgrade migrations complete under PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);

    $verifyFile = $siteRoot . '/n-minus-one-verify.php';
    file_put_contents($verifyFile, <<<'PHP'
<?php
declare(strict_types=1);
define('ROOT_PATH', __DIR__);
require ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/Migrator.php';
$pending = 0;
foreach (Migrator::loadAll() as $migration) {
    if (!Migrator::isApplied($migration)) {
        $pending++;
    }
}
echo json_encode(['version' => CMS_VERSION, 'pending' => $pending]);
PHP
    );
    $verify = upgradeRunCommand([PHP_BINARY, $verifyFile], $siteRoot);
    @unlink($verifyFile);
    $verifyData = json_decode(trim($verify['output']), true);
    upgradeAssert($verify['code'] === 0 && is_array($verifyData), 'upgraded package boots from CLI');
    upgradeAssert(($verifyData['version'] ?? '') === $toVersion && ($verifyData['pending'] ?? -1) === 0, 'target version has zero pending migrations');

    $front = upgradeRequest($base . '/', $cookieJar);
    $admin = upgradeRequest($base . '/admin/index.php', $cookieJar);
    upgradeAssert($front['code'] === 200 && $admin['code'] === 200, 'front end and authenticated admin render after upgrade');
    echo "\nPASS: real v{$fromVersion} package upgraded to v{$toVersion} under PHP " . PHP_VERSION . "\n";
} catch (Throwable $exception) {
    $failure = $exception;
    fwrite(STDERR, "\nFAIL: " . $exception->getMessage() . "\n");
    if (is_file($serverLog)) {
        $tail = array_slice(file($serverLog, FILE_IGNORE_NEW_LINES) ?: [], -30);
        fwrite(STDERR, "--- server log ---\n" . implode("\n", $tail) . "\n");
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($pdo instanceof PDO && !$keep) {
        try {
            $pdo->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        } catch (Throwable $exception) {
        }
    }
    if ($keep) {
        echo "Kept test site: {$tempBase}\n";
    } else {
        upgradeRemoveTree($tempBase);
    }
}

exit($failure === null ? 0 : 1);
