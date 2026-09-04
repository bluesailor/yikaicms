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

    public function testPackageInstallComparesTheFreshChineseHomepageContract(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');
        $contract = file_get_contents(dirname(__DIR__) . '/smoke/package_home_contract.php');

        self::assertIsString($script);
        self::assertIsString($contract);
        self::assertStringContainsString('package_home_contract.php', $script);
        self::assertStringContainsString('中文首页契约通过', $contract);
        self::assertStringContainsString("['business', 'default', 'minimal']", $contract);
        self::assertStringContainsString('数字化转型解决方案', $contract);
        self::assertStringContainsString("grep -Fq '数字化转型解决方案'", $script);
    }

    public function testPackageInstallExercisesAllSearchOnTheExtractedSite(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString('"$BASE/search.php"', $script);
        self::assertStringContainsString('"type=all"', $script);
        self::assertStringContainsString('前台全部搜索可用', $script);
        self::assertStringContainsString("Fatal error|Uncaught|PDOException", $script);
        self::assertStringContainsString('前台下载搜索无 summary 警告', $script);
        self::assertStringContainsString('Undefined array key "summary"', $script);
    }

    public function testPackageInstallRejectsADisabledLanguageAtRuntime(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');
        $contract = file_get_contents(dirname(__DIR__) . '/smoke/package_language_contract.php');

        self::assertIsString($script);
        self::assertIsString($contract);
        self::assertStringContainsString('package_language_contract.php', $script);
        self::assertStringContainsString('DISABLED_LANG_STATUS', $script);
        self::assertStringContainsString('X-Robots-Tag', $script);
        self::assertStringContainsString("['zh-CN', 'en']", $contract);
    }

    public function testPackageInstallExecutesSvgSecurityContractWithTheTargetPhp(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');
        $contract = file_get_contents(dirname(__DIR__) . '/smoke/package_security_contract.php');

        self::assertIsString($script);
        self::assertIsString($contract);
        self::assertStringContainsString('"$PHP_DIR/php.exe" "$UNPACK_WIN/package-security-contract.php"', $script);
        self::assertStringContainsString("javascript:alert(\\'x\\')", $contract);
        self::assertStringContainsString('java&#x73;cript:alert(1)', $contract);
    }

    public function testPackageInstallLintsEveryRuntimeFileWithTheTargetPhp(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString('find "$UNPACK_WSL" -type f -name \'*.php\' -print0', $script);
        self::assertStringContainsString('"$PHP_DIR/php.exe" -l "$UNPACK_WIN/$relative"', $script);
        self::assertStringContainsString('目标 PHP 全包语法通过', $script);
    }

    public function testPackageInstallProbesEmptyAdministratorPasswordBeforeInstallation(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/tools/package-install-test.sh');

        self::assertIsString($script);
        self::assertStringContainsString('"admin_pass="', $script);
        self::assertStringContainsString('admin_password_too_short', $script);
        self::assertStringContainsString('[ ! -f "$UNPACK_WSL/installed.lock" ]', $script);
    }
}
