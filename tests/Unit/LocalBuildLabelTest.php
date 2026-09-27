<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ProductIdentity.php';

/**
 * build.sh --local=local1 keeps CMS_VERSION and only stamps the label into
 * config/build.php. The admin must tell such builds apart, and upgrades must
 * neither run automatically nor apply a delta on top of them.
 */
final class LocalBuildLabelTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yikai-local-build-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/config/build.php');
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    /** @return iterable<string, array{string, string}> */
    public static function buildIds(): iterable
    {
        yield 'local build' => ['2.0.0-local1-20260928021640', 'local1'];
        yield 'four-part version' => ['2.0.0.1-local12-20260928021640', 'local12'];
        yield 'official build' => ['2.0.0-20260925120000', ''];
        yield 'label without timestamp' => ['2.0.0-local1', ''];
        yield 'foreign label' => ['2.0.0-beta1-20260928021640', ''];
    }

    /** @dataProvider buildIds */
    public function testLabelIsReadFromTheBuildStamp(string $buildId, string $expected): void
    {
        file_put_contents($this->root . '/config/build.php', "<?php\n\nreturn '" . $buildId . "';\n");

        self::assertSame($expected, YikaiProductIdentity::localBuildLabel($this->root));
    }

    public function testMissingBuildStampIsNotALocalBuild(): void
    {
        self::assertSame('', YikaiProductIdentity::localBuildLabel($this->root));
    }

    public function testLocalBuildsNeverUpgradeAutomaticallyOrByDelta(): void
    {
        $auto = (string) file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
        $guard = strpos($auto, "YikaiProductIdentity::localBuildLabel() !== ''");
        $directive = strpos($auto, 'UpgradeDirective::verify(');
        self::assertIsInt($guard);
        self::assertIsInt($directive);
        self::assertLessThan($directive, $guard, 'signed directives must not bypass the local-build guard');

        $runner = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        $deltaMode = strpos($runner, "\$mode = 'delta';");
        $runnerGuard = strpos($runner, 'YikaiProductIdentity::localBuildLabel()', (int) $deltaMode);
        $firstWrite = strpos($runner, '$deleted = (array)', (int) $deltaMode);
        self::assertIsInt($runnerGuard);
        self::assertLessThan($firstWrite, $runnerGuard);

        $online = (string) file_get_contents(ROOT_PATH . '/admin/upgrade_online.php');
        self::assertStringContainsString("unset(\$data['data']['delta']);", $online);
    }

    public function testAdminVersionDisplaysCarryTheBadge(): void
    {
        foreach (['admin/index.php', 'admin/system.php', 'admin/upgrade.php', 'admin/upgrade_online.php'] as $page) {
            self::assertStringContainsString('adminLocalBuildBadge()', (string) file_get_contents(ROOT_PATH . '/' . $page), $page);
        }
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertStringContainsString(':label', (string) ($strings['local_build_badge'] ?? ''), $lang);
            self::assertNotSame('', (string) ($strings['local_build_hint'] ?? ''), $lang);
        }
    }
}
