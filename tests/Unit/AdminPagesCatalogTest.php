<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/admin_pages_catalog.php';

final class AdminPagesCatalogTest extends TestCase
{
    public function testSettingTabsHaveDirectQuickSearchEntries(): void
    {
        $urls = array_column(adminPagesCatalog(), 'url');

        self::assertContains('/admin/setting.php?tab=basic', $urls);
        self::assertContains('/admin/setting.php?tab=url', $urls);
        self::assertContains('/admin/setting.php?tab=header', $urls);
        self::assertContains('/admin/setting.php?tab=footer', $urls);
        self::assertContains('/admin/setting.php?tab=code', $urls);
        self::assertContains('/admin/setting.php?tab=lang', $urls);
    }

    public function testDeprecatedHomeSettingsEntryIsNotIndexed(): void
    {
        $urls = array_column(adminPagesCatalog(), 'url');

        self::assertNotContains('/admin/setting_home.php', $urls);
    }
}
