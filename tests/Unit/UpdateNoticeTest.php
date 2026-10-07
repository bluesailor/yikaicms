<?php
/** 2.0.6 右上角铃铛的新版本 / 主题更新判断：只和本地版本比较，按「更新提醒级别」过滤，升级后自然消失。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpdateNotice.php';

final class UpdateNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    private function known(string $version, string $level = 'feature'): void
    {
        $GLOBALS['_test_config']['update_latest_known'] = json_encode(['version' => $version, 'level' => $level, 'checked_at' => 1]);
    }

    public function testNewerVersionShowsAndDisappearsAfterUpgrading(): void
    {
        $this->known('2.0.6');
        self::assertSame('2.0.6', UpdateNotice::available('2.0.5'));
        self::assertSame('', UpdateNotice::available('2.0.6'), '升级到这个版本后不再提醒');
        self::assertSame('', UpdateNotice::available('2.1.0'));
        $this->known('');
        self::assertSame('', UpdateNotice::available('2.0.5'), '检查结果是没有更新');
    }

    public function testNotifyLevelFiltersReminders(): void
    {
        $this->known('2.0.6', 'feature');
        $GLOBALS['_test_config']['update_notify_level'] = 'security';
        self::assertSame('', UpdateNotice::available('2.0.5'), '只提醒安全更新时，功能版不响');
        $this->known('2.0.6', 'security');
        self::assertSame('2.0.6', UpdateNotice::available('2.0.5'));
        $GLOBALS['_test_config']['update_notify_level'] = 'off';
        self::assertSame('', UpdateNotice::available('2.0.5'));

        // 旧的布尔开关：关掉 = off
        unset($GLOBALS['_test_config']['update_notify_level']);
        $GLOBALS['_test_config']['dashboard_update_check'] = '0';
        self::assertSame('off', UpdateNotice::notifyLevel());
    }

    public function testThemeUpdatesOnlyWhenAllRemindersAreOn(): void
    {
        $GLOBALS['_test_config']['theme_updates_known'] = json_encode(['count' => 2, 'checked_at' => 1]);
        self::assertSame(2, UpdateNotice::themeUpdates());
        $GLOBALS['_test_config']['update_notify_level'] = 'security';
        self::assertSame(0, UpdateNotice::themeUpdates());
    }
}
