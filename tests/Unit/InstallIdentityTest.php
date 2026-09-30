<?php
/**
 * 站点编号（2.0.3）：update 服务器按它建档、按它下发升级指令。
 *
 * 钉死三件事：编号稳定（不挪目录就不变）、拷贝出来的站拿到自己的编号、
 * 四处回访都带同一组标识（否则服务器上一处按编号、一处按域名各建一条）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class InstallIdentityTest extends TestCase
{
    private function boot(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, `name` TEXT, `tip` TEXT, sort_order INTEGER DEFAULT 0)');
        db()->execute("DELETE FROM settings WHERE `key` = 'install_identity'");
        settingModel()->clearCache();
        require_once ROOT_PATH . '/includes/InstallIdentity.php';
        \InstallIdentity::resetForTests();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testIdIsRandomPersistedAndStable(): void
    {
        $this->boot();
        $id = \InstallIdentity::id();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);

        \InstallIdentity::resetForTests();
        settingModel()->clearCache();
        self::assertSame($id, \InstallIdentity::id(), 'a later request reads the same id back');

        $saved = json_decode((string) settingModel()->get('install_identity', ''), true);
        self::assertIsArray($saved);
        self::assertSame(['id', 'root'], array_keys($saved), 'only a random id and a directory hash are stored');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCopiedSiteGetsItsOwnId(): void
    {
        $this->boot();
        // 从另一个目录整站拷来的库：编号跟着来了，但目录指纹对不上
        $copied = str_repeat('c', 32);
        settingModel()->set('install_identity', (string) json_encode(['id' => $copied, 'root' => '0123456789abcdef']), 'system');
        settingModel()->clearCache();

        $id = \InstallIdentity::id();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        self::assertNotSame($copied, $id);

        // 损坏的记录同样重新生成，而不是把坏值报上去
        settingModel()->set('install_identity', '{"id":"../../x","root":"z"}', 'system');
        settingModel()->clearCache();
        \InstallIdentity::resetForTests();
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', \InstallIdentity::id());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReportParamsCarrySubdirectoryWhenRunFromCron(): void
    {
        $this->boot();
        unset($_SERVER['HTTP_HOST']);
        $GLOBALS['_test_config']['site_url'] = 'https://demo.example.com/yikai-dental/';
        $p = \InstallIdentity::reportParams();
        self::assertSame(['domain', 'install_id', 'base'], array_keys($p));
        self::assertSame('https://demo.example.com/yikai-dental/', $p['domain']);
        self::assertSame('/yikai-dental', $p['base']);
        self::assertSame(\InstallIdentity::id(), $p['install_id']);

        $_SERVER['HTTP_HOST'] = 'www.example.com';
        $GLOBALS['_test_config']['site_url'] = 'https://www.example.com';
        $p = \InstallIdentity::reportParams();
        self::assertSame('www.example.com', $p['domain']);
        self::assertSame('', $p['base'], 'root installs report an empty base');
    }

    public function testEveryHeartbeatReportsTheSameIdentity(): void
    {
        foreach (['includes/AutoUpgrade.php', 'includes/SiteHealth.php', 'admin/upgrade.php', 'admin/upgrade_online.php'] as $file) {
            $src = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringContainsString('InstallIdentity::reportParams()', $src, $file);
            self::assertStringNotContainsString("'&domain='", $src, $file . ' must not build its own domain parameter');
            self::assertDoesNotMatchRegularExpression("/'domain'\\s*=>\\s*\\(string\\)\\s*\\(\\\$_SERVER\\['HTTP_HOST'\\]/", $src, $file);
        }
    }

    public function testIdentityNeverTravelsWithSiteTemplatesOrFlushesPageCache(): void
    {
        require_once ROOT_PATH . '/includes/SiteTemplateData.php';
        self::assertFalse(\SiteTemplateData::settingAllowed('install_identity'));
        self::assertFalse(\SettingModel::affectsPageCache(['install_identity' => '{}']));
    }
}
