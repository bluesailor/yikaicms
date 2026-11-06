<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

/**
 * 首页发布历史（最多 10 版、每版可到 2MB）不随普通设置读取进内存（评审 F02 / T02）；
 * 整表读取 getAll() 仍包含它，给导出、快照与还原用。
 */
final class SettingHistoryLazyLoadTest extends TestCase
{
    protected function schemaSql(): array
    {
        return ['CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE,
            `value` TEXT, `group` TEXT, name TEXT, tip TEXT, type TEXT)'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->raw('site_name', 'Demo');
        $this->raw('home_blox_history', 'blox-v1');
        $this->raw('home_layout_history', 'layout-v1');
        settingModel()->clearCache();
    }

    protected function tearDown(): void
    {
        settingModel()->clearCache();
        parent::tearDown();
    }

    private function raw(string $key, string $value): void
    {
        db()->execute('DELETE FROM settings WHERE `key` = ?', [$key]);
        db()->execute('INSERT INTO settings (`key`, `value`, `group`) VALUES (?, ?, ?)', [$key, $value, 'home']);
    }

    public function testOrdinaryReadsDoNotLoadHomepageHistory(): void
    {
        $this->raw('home_blox_history', str_repeat('x', 8_000_000));
        settingModel()->clearCache();
        gc_collect_cycles();
        $before = memory_get_usage();
        self::assertSame('Demo', settingModel()->get('site_name'));
        self::assertLessThan(1_000_000, memory_get_usage() - $before, 'config() 不应把 8MB 的发布历史读进内存');
    }

    public function testHistoryIsReadWhenAskedForAndIsNotAStaleSnapshot(): void
    {
        settingModel()->get('site_name');
        db()->execute('UPDATE settings SET `value` = ? WHERE `key` = ?', ['blox-v2', 'home_blox_history']);
        self::assertSame('blox-v2', settingModel()->get('home_blox_history'));
        self::assertSame('layout-v1', settingModel()->get('home_layout_history'));
        self::assertSame('none', settingModel()->get('home_missing_history', 'none'));
    }

    public function testFullReadStillIncludesHistory(): void
    {
        settingModel()->get('site_name');
        $all = settingModel()->getAll();
        self::assertSame('Demo', $all['site_name']);
        self::assertSame('blox-v1', $all['home_blox_history']);
        self::assertSame('layout-v1', $all['home_layout_history']);
    }

    public function testWritesInvalidateTheHistoryValue(): void
    {
        self::assertSame('blox-v1', settingModel()->get('home_blox_history'));
        settingModel()->set('home_blox_history', 'blox-v2', 'home');
        self::assertSame('blox-v2', settingModel()->get('home_blox_history'));
        settingModel()->saveBatch(['home_blox_history' => 'blox-v3']);
        self::assertSame('blox-v3', settingModel()->get('home_blox_history'));
        db()->execute('UPDATE settings SET `value` = ? WHERE `key` = ?', ['blox-v4', 'home_blox_history']);
        settingModel()->clearCache();
        self::assertSame('blox-v4', settingModel()->get('home_blox_history'));
        self::assertSame('blox-v4', settingModel()->getAll()['home_blox_history']);
    }
}
