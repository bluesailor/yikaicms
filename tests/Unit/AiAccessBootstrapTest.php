<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiAccessBootstrapTest extends TestCase
{
    private string $root;
    /** @var resource|null */
    private $server = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yk-ai-bootstrap-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0700, true);
        mkdir($this->root . '/sessions', 0700);
        copy(ROOT_PATH . '/config/database.php', $this->root . '/config/database.php');
        copy(ROOT_PATH . '/config/version.php', $this->root . '/config/version.php');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) { proc_terminate($this->server); proc_close($this->server); }
        // 只清理由当前测试创建、位于系统临时目录下的唯一目录。
        $resolved = realpath($this->root);
        $temporary = realpath(sys_get_temp_dir());
        if ($resolved === false || $temporary === false || dirname($resolved) !== $temporary || !str_starts_with(basename($resolved), 'yk-ai-bootstrap-')) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($resolved);
    }

    private function configure(bool $template = true, bool $overrides = false): void
    {
        if ($template) {
            $source = strtr((string) file_get_contents(ROOT_PATH . '/config/config.sample.php'), [
                '{{DB_DRIVER}}' => 'sqlite', '{{DB_HOST}}' => '127.0.0.1', '{{DB_PORT}}' => '3306',
                '{{DB_NAME}}' => 'fixture', '{{DB_USER}}' => 'fixture', '{{DB_PASS}}' => 'fixture-only',
                '{{SITE_NAME}}' => 'Fixture', '{{SITE_URL}}' => 'http://fixture.invalid', '{{SESSION_ID}}' => 'fixture', '{{ENCRYPT_KEY}}' => 'fixture-not-a-key',
            ]);
        } else {
            $source = "<?php\ndeclare(strict_types=1);\n";
            foreach (['DB_DRIVER' => 'sqlite', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => 3306, 'DB_NAME' => 'fixture', 'DB_USER' => 'fixture',
                'DB_PASS' => "fixture-only\\'\\\"", 'DB_CHARSET' => 'utf8mb4', 'DB_PREFIX' => 'legacy_', 'SESSION_NAME' => 'IK_fixture', 'DEBUG' => false] as $key => $value) {
                $source .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
            }
            $source .= "define('DB_PATH', ROOT_PATH . '/storage/database.sqlite');\nsession_name(SESSION_NAME);\nsession_start();\nrequire __DIR__ . '/database.php';\n";
        }
        $source .= "\nfile_put_contents(ROOT_PATH . '/config-executed.marker', 'normal-init');\n";
        file_put_contents($this->root . '/config/config.php', $source);
        if ($overrides) file_put_contents($this->root . '/config/overrides.php', "<?php return ['ai_access_enabled' => '0'];\n");
    }

    private function worker(string $mode): array
    {
        $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/fixtures/ai-access-bootstrap-worker.php', $mode, $this->root],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors . $output);
        self::assertSame('', $errors);
        $result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        return $result;
    }

    private function prepare(): void
    {
        $before = hash_file('sha256', $this->root . '/config/config.php');
        self::assertSame(['success' => true, 'normal_session_active' => true], $this->worker('prepare'));
        self::assertSame($before, hash_file('sha256', $this->root . '/config/config.php'));
        unlink($this->root . '/config-executed.marker');
    }

    #[DataProvider('configurationFormats')]
    public function testLegacyAndTemplateConfigsBridgeWithoutStartingSessionOrExecutingConfig(bool $template): void
    {
        $this->configure($template);
        $this->prepare();
        $before = hash_file('sha256', $this->root . '/storage/database.sqlite');
        $out = $this->worker('boot');
        self::assertTrue($out['success']);
        self::assertSame(PHP_SESSION_NONE, $out['session_status']);
        self::assertSame('', $out['session_id']);
        self::assertFalse($out['functions_loaded']);
        self::assertFalse($out['plugins_loaded']);
        self::assertFalse($out['scheduled_publish_loaded']);
        self::assertFileDoesNotExist($this->root . '/config-executed.marker');
        self::assertSame($before, hash_file('sha256', $this->root . '/storage/database.sqlite'));
    }

    public static function configurationFormats(): array { return [[true], [false]]; }

    public function testMissingBridgeAndContaminatedContextFailClosed(): void
    {
        $this->configure();
        self::assertSame('ai_access_config_not_prepared', $this->worker('boot')['error']);
        foreach (['contaminated', 'active-session'] as $mode) {
            $out = $this->worker($mode);
            self::assertSame(503, $out['status']);
            self::assertSame('ai_access_bootstrap_contaminated', $out['error']);
        }
        self::assertFileDoesNotExist($this->root . '/config-executed.marker');
    }

    #[DataProvider('changedSources')]
    public function testPrivateConfigurationOrOverridesChangesInvalidateBridge(string $file): void
    {
        $this->configure();
        $this->prepare();
        file_put_contents($this->root . '/' . $file, "\n// changed\n", FILE_APPEND);
        $out = $this->worker('boot');
        self::assertSame(503, $out['status']);
        self::assertSame('ai_access_config_stale_or_invalid', $out['error']);
        self::assertFileDoesNotExist($this->root . '/config-executed.marker');
    }

    public static function changedSources(): array { return [['config/config.php'], ['config/overrides.php']]; }

    public function testCorruptBridgeAndUnknownDatabaseFieldsAreRejectedWithoutExecution(): void
    {
        $this->configure(); $this->prepare();
        $path = $this->root . '/storage/security/ai-access/bootstrap.json';
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $data['database']['UNTRUSTED_CONSTANT'] = 'fixture';
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        self::assertSame('ai_access_config_invalid', $this->worker('read')['error']);
        file_put_contents($path, '<?php throw new RuntimeException("executed");');
        self::assertSame('ai_access_config_invalid', $this->worker('boot')['error']);
    }

    #[DataProvider('invalidBridgeFields')]
    public function testUntrustedBridgeShapeDoesNotDefineArbitraryRuntimeConfiguration(string $group, string $field, mixed $value): void
    {
        $this->configure(); $this->prepare();
        $path = $this->root . '/storage/security/ai-access/bootstrap.json';
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if ($group === '') $data[$field] = $value;
        else $data[$group][$field] = $value;
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        $out = $this->worker('boot');
        self::assertFalse($out['success']);
        self::assertSame(503, $out['status']);
        self::assertFileDoesNotExist($this->root . '/config-executed.marker');
    }

    public static function invalidBridgeFields(): array
    {
        return [['', 'root', '/another-site'], ['', 'version', 2], ['database', 'DB_PREFIX', 'bad;prefix'],
            ['database', 'DB_DRIVER', 'unknown'], ['database', 'DB_PASS', ['invalid']], ['overrides', 'unknown_policy', '1']];
    }

    #[DataProvider('invalidPreparationModes')]
    public function testPreparationRequiresPreloadSnapshotAndRejectsConfigurationRace(string $mode, string $error): void
    {
        $this->configure();
        $out = $this->worker($mode);
        self::assertSame(503, $out['status']);
        self::assertSame($error, $out['error']);
        self::assertFileDoesNotExist($this->root . '/storage/security/ai-access/bootstrap.json');
    }

    public static function invalidPreparationModes(): array
    {
        return [['prepare-without-capture', 'ai_access_prepare_context_required'], ['prepare-changed', 'ai_access_prepare_source_changed']];
    }

    private function startServer(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($socket, $message);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $environment = array_replace(getenv(), ['YK_AI_BOOTSTRAP_FIXTURE_ROOT' => $this->root]);
        $this->server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $this->root . '/sessions', '-S', $address,
            ROOT_PATH . '/tests/fixtures/ai-access-http-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->root . '/http.log', 'a'], 2 => ['file', $this->root . '/http.log', 'a']], $pipes, ROOT_PATH, $environment);
        self::assertIsResource($this->server);
        fclose($pipes[0]);
        $url = 'http://' . $address;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (@file_get_contents($url . '/health') !== false) return $url;
            usleep(20000);
        }
        self::fail('Fixture HTTP server did not become ready');
    }

    private function request(string $url, array $headers = []): array
    {
        $body = file_get_contents($url, false, stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]]));
        self::assertIsString($body);
        return ['headers' => $http_response_header, 'body' => json_decode($body, true, 16, JSON_THROW_ON_ERROR)];
    }

    private function assertNoCookie(array $response): void
    {
        foreach ($response['headers'] as $header) self::assertFalse(str_starts_with(strtolower($header), 'set-cookie:'), $header);
        self::assertSame(PHP_SESSION_NONE, $response['body']['session_status']);
        self::assertSame('', $response['body']['session_id']);
    }

    public function testHttpIgnoresActualAdminSessionAndProducesNoCookieOrSessionSideEffects(): void
    {
        $this->configure(); $this->prepare();
        $url = $this->startServer();
        $normal = $this->request($url . '/normal');
        self::assertTrue($normal['body']['normal_session_active']);
        $cookieHeaders = array_values(array_filter($normal['headers'], fn(string $header): bool => str_starts_with(strtolower($header), 'set-cookie:')));
        self::assertNotEmpty($cookieHeaders);
        preg_match('/^Set-Cookie: ([^;]+)/i', $cookieHeaders[0], $matches);
        $cookie = 'Cookie: ' . $matches[1];
        unlink($this->root . '/config-executed.marker');
        $sessionFiles = glob($this->root . '/sessions/*');
        $sessionHashes = array_map(fn(string $file): string => hash_file('sha256', $file), $sessionFiles);
        $databaseBefore = hash_file('sha256', $this->root . '/storage/database.sqlite');

        $valid = $this->request($url . '/?admin_id=999', [$cookie, 'Authorization: Bearer ' . str_repeat('a', 64)]);
        self::assertTrue($valid['body']['success']);
        self::assertSame(7, $valid['body']['actor_id']);
        self::assertTrue($valid['body']['article_read']);
        foreach (['session_array_exists', 'functions_loaded', 'plugins_loaded', 'scheduled_publish_loaded'] as $key) self::assertFalse($valid['body'][$key]);
        $this->assertNoCookie($valid);
        foreach ([[], ['Authorization: Bearer ' . str_repeat('b', 64)]] as $authorization) {
            $invalid = $this->request($url . '/?key=fixture&token=fixture&admin_id=999', array_merge([$cookie, 'X-API-Key: fixture'], $authorization));
            self::assertStringContainsString('401', $invalid['headers'][0]);
            self::assertSame('ai_access_invalid_token', $invalid['body']['error']);
            $this->assertNoCookie($invalid);
        }
        self::assertSame($sessionFiles, glob($this->root . '/sessions/*'));
        self::assertSame($sessionHashes, array_map(fn(string $file): string => hash_file('sha256', $file), $sessionFiles));
        self::assertSame($databaseBefore, hash_file('sha256', $this->root . '/storage/database.sqlite'));
        self::assertFileDoesNotExist($this->root . '/config-executed.marker');
    }

    public function testPreparedOverrideKillSwitchIsEnforcedByHttpRuntime(): void
    {
        $this->configure(true, true); $this->prepare();
        $response = $this->request($this->startServer() . '/', ['Authorization: Bearer ' . str_repeat('a', 64)]);
        self::assertStringContainsString('403', $response['headers'][0]);
        self::assertSame('ai_access_disabled', $response['body']['error']);
        $this->assertNoCookie($response);
    }
}
