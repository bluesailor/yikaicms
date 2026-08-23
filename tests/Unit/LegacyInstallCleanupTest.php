<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/LegacyInstallCleanup.php';

final class LegacyInstallCleanupTest extends TestCase
{
    private string $root;
    /** @var array<string,mixed> */
    private array $sessionBackup;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yikai-legacy-cleanup-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/install', 0777, true));
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (['install/upgrade.php', 'install/run_upgrade.php', 'state/marker.txt'] as $relative) {
            $path = $this->root . '/' . $relative;
            if (is_file($path)) {
                unlink($path);
            }
        }
        foreach (['install', 'state'] as $relative) {
            $path = $this->root . '/' . $relative;
            if (is_dir($path)) {
                rmdir($path);
            }
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        $_SESSION = $this->sessionBackup;
    }

    public function testRunRemovesOnlyKnownLegacyEntrypoints(): void
    {
        file_put_contents($this->root . '/install/upgrade.php', '<?php');
        file_put_contents($this->root . '/install/run_upgrade.php', '<?php');
        file_put_contents($this->root . '/install/index.php', '<?php');

        $result = LegacyInstallCleanup::run($this->root);

        self::assertSame(['install/upgrade.php', 'install/run_upgrade.php'], $result['removed']);
        self::assertSame([], $result['failed']);
        self::assertFileExists($this->root . '/install/index.php');
        unlink($this->root . '/install/index.php');
    }

    public function testThrottledFailureLogsOnlyOncePerInterval(): void
    {
        file_put_contents($this->root . '/install/upgrade.php', '<?php');
        $logs = [];
        $unlinker = static fn(string $path): bool => false;
        $logger = static function (string $message) use (&$logs): void { $logs[] = $message; };
        $marker = $this->root . '/state/marker.txt';

        $first = LegacyInstallCleanup::runThrottled($this->root, $marker, 3600, $unlinker, $logger, 10000);
        $second = LegacyInstallCleanup::runThrottled($this->root, $marker, 3600, $unlinker, $logger, 10001);

        self::assertSame(['install/upgrade.php'], $first['failed']);
        self::assertFalse($first['skipped']);
        self::assertTrue($second['skipped']);
        self::assertCount(1, $logs);
    }
}
