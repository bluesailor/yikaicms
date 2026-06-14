<?php
/**
 * 验证 SettingModel::saveBatch 插入"新设置项"时，
 * 会从 config/defaults.php 取正确的 group / name / type，
 * 而不是一律塞进 group='basic'、name=裸key（否则会污染基础设置页）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class SettingSaveGroupTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', "key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)',
        ];
    }

    public function testNewKeyDefinedInDefaultsGetsCorrectGroupAndName(): void
    {
        // cs_enabled 在 defaults.php 的 customer_service 组里
        settingModel()->saveBatch(['cs_enabled' => '1']);

        $row = db()->fetchOne('SELECT "group", name, type FROM settings WHERE "key" = ?', ['cs_enabled']);
        $this->assertIsArray($row);
        $this->assertSame('customer_service', $row['group'], '应落到 defaults 里定义的组');
        $this->assertSame('启用在线客服', $row['name'], '应取 defaults 里的中文 name');
        $this->assertSame('switch', $row['type']);
    }

    public function testUnknownKeyFallsBackToBasic(): void
    {
        settingModel()->saveBatch(['totally_unknown_xyz' => 'v']);

        $row = db()->fetchOne('SELECT "group", name FROM settings WHERE "key" = ?', ['totally_unknown_xyz']);
        $this->assertIsArray($row);
        $this->assertSame('basic', $row['group'], '未在 defaults 定义的键退回 basic');
        $this->assertSame('totally_unknown_xyz', $row['name']);
    }

    public function testExistingKeyValueUpdatedNotReinserted(): void
    {
        settingModel()->saveBatch(['cs_button_text' => '联系我们']);
        settingModel()->saveBatch(['cs_button_text' => '在线咨询']);

        $rows = db()->fetchAll('SELECT value FROM settings WHERE "key" = ?', ['cs_button_text']);
        $this->assertCount(1, $rows, '同 key 应更新而非重复插入');
        $this->assertSame('在线咨询', $rows[0]['value']);
    }
}
