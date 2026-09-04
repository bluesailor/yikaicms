<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageInstallSmokeContractTest extends TestCase
{
    public function testPackageSmokeTargetsTheExtractedSite(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString(
            'php tests/smoke/admin_crud.php --base="$BASE" --root="$UNPACK_WIN"',
            $script
        );
        self::assertStringContainsString(
            'php tests/smoke/admin_pages.php --base="$BASE" --root="$UNPACK_WIN"',
            $script
        );
    }

    public function testSmokeClientsHonorTheConfiguredSiteRoot(): void
    {
        foreach (['admin_crud.php', 'admin_pages.php'] as $name) {
            $source = file_get_contents(dirname(__DIR__) . '/smoke/' . $name);
            self::assertIsString($source);
            self::assertStringContainsString("Option('root')", $source, $name);
            self::assertStringContainsString("Option('base')", $source, $name);
        }
    }

    public function testBuildCanCreateAnInstallOnlyCandidateWithoutDeltas(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/build.sh');

        self::assertIsString($script);
        self::assertStringContainsString('--no-delta', $script);
        self::assertStringContainsString('if [ "$BUILD_DELTAS" = "0" ]', $script);
        self::assertStringContainsString('deltas-v${VERSION}.json', $script);
    }

    public function testPackageServerHostCanBePinnedAndReadinessIsBounded(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString('--host=*) SERVE_HOST=', $script);
        self::assertStringContainsString('HOST_IP="${SERVE_HOST:-127.0.0.1}"', $script);
        self::assertStringContainsString('CURL_BIN="curl.exe"', $script);
        self::assertStringContainsString('"$CURL_BIN" -sf --connect-timeout 2 --max-time 5', $script);
    }

    public function testPackageInstallFailsClosedWhenTheOldTreeCannotBeRemoved(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString('kill_port', $script);
        self::assertStringContainsString('无法清理旧解包目录', $script);
        self::assertStringContainsString('旧解包目录仍然存在', $script);
    }
}
