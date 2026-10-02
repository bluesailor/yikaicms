<?php
/**
 * 站点时区（2.0.4）：设置 site_timezone 在读设置时生效；不合法或没设置时保持默认。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use SiteTimezone;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/SiteTimezone.php';

#[RunTestsInSeparateProcesses]
final class SiteTimezoneTest extends TestCase
{
    protected function schemaSql(): array
    {
        return ["CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `group` TEXT DEFAULT 'basic', `key` TEXT, `value` TEXT,
            name TEXT DEFAULT '', type TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT DEFAULT '', sort_order INTEGER DEFAULT 0)"];
    }

    public function testOnlyRealIdentifiersAreAccepted(): void
    {
        self::assertTrue(SiteTimezone::valid('Asia/Tokyo'));
        self::assertTrue(SiteTimezone::valid('UTC'));
        foreach (['', '+08:00', 'Asia/Atlantis', 'GMT+8', ' Asia/Tokyo', 'asia/tokyo', null, 8] as $bad) {
            self::assertFalse(SiteTimezone::valid($bad), var_export($bad, true));
        }
    }

    public function testApplyChangesDefaultOnlyForValidValues(): void
    {
        date_default_timezone_set('Asia/Shanghai');
        SiteTimezone::apply('not a zone');
        self::assertSame('Asia/Shanghai', date_default_timezone_get());
        SiteTimezone::apply('Europe/Berlin');
        self::assertSame('Europe/Berlin', date_default_timezone_get());
        SiteTimezone::apply('');
        self::assertSame('Europe/Berlin', date_default_timezone_get(), '空值不动当前时区');
    }

    public function testReadingSettingsAppliesTheSiteTimezone(): void
    {
        date_default_timezone_set('Asia/Shanghai');
        $this->insertRow('settings', ['group' => 'system', 'key' => 'site_timezone', 'value' => 'America/New_York']);
        settingModel()->getAll();
        self::assertSame('America/New_York', date_default_timezone_get());
        // 同一个时间戳（2026-10-02 12:00 UTC），显示随站点时区：纽约夏令时 08:00
        self::assertSame('2026-10-02 08:00', date('Y-m-d H:i', 1_790_942_400));
    }

    public function testSitesWithoutTheSettingKeepTheDefault(): void
    {
        date_default_timezone_set('Asia/Shanghai');
        $this->insertRow('settings', ['group' => 'basic', 'key' => 'site_name', 'value' => 'Demo']);
        settingModel()->getAll();
        self::assertSame('Asia/Shanghai', date_default_timezone_get());
    }

    public function testOffsetsLocalTimeAndGroupedOptions(): void
    {
        $at = 1_790_942_400; // 2026-10-02 12:00 UTC
        self::assertSame('UTC+08:00', SiteTimezone::offsetLabel('Asia/Shanghai', $at));
        self::assertSame('UTC−04:00', SiteTimezone::offsetLabel('America/New_York', $at), '夏令时');
        self::assertSame('UTC+05:30', SiteTimezone::offsetLabel('Asia/Kolkata', $at));
        self::assertSame('2026-10-02 20:00', SiteTimezone::localTime('Asia/Shanghai', $at));

        $groups = SiteTimezone::groupedOptions($at);
        self::assertSame('Asia', array_key_first($groups));
        self::assertSame('UTC', array_key_last($groups));
        self::assertSame('Shanghai (UTC+08:00)', $groups['Asia']['Asia/Shanghai']);
        self::assertSame('Argentina / Buenos Aires (UTC−03:00)', $groups['America']['America/Argentina/Buenos_Aires']);
        $total = array_sum(array_map('count', $groups));
        self::assertSame(count(timezone_identifiers_list()), $total, '每个时区都在列表里');
        $asia = array_keys($groups['Asia']);
        self::assertLessThan(array_search('Asia/Shanghai', $asia, true), array_search('Asia/Kolkata', $asia, true), '组内按与 UTC 的差排序');
    }

    public function testSettingsPageValidatesAndInstallerOnlyWritesValidZones(): void
    {
        $setting = (string) file_get_contents(ROOT_PATH . '/admin/setting.php');
        self::assertStringContainsString("if (\$action === 'save_timezone') {", $setting);
        self::assertStringContainsString('if (!SiteTimezone::valid($timezone)) {', $setting);
        self::assertStringContainsString("settingModel()->set(SiteTimezone::KEY, \$timezone, 'system');", $setting);

        $install = (string) file_get_contents(ROOT_PATH . '/install/index.php');
        self::assertStringContainsString('if (SiteTimezone::valid($siteTimezone)) {', $install);
        self::assertSame(2, substr_count($install, "append('site_timezone', Intl.DateTimeFormat().resolvedOptions().timeZone"), '一键安装与正常安装都带上浏览器时区');
    }
}
