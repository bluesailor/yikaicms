<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** E06：草稿键写入不清整页缓存；发布写正式键照常失效。 */
final class SettingModelPageCacheTest extends TestCase
{
    public function testDraftOnlyKeysDoNotInvalidateButPublishedKeysDo(): void
    {
        foreach (['blox_design_theme_draft', 'home_blox_data', 'page_hero_design_draft'] as $draftKey) {
            self::assertFalse(SettingModel::affectsPageCache([$draftKey => '{}']), $draftKey);
        }
        self::assertTrue(SettingModel::affectsPageCache(['blox_design_theme' => '{}']));
        self::assertTrue(SettingModel::affectsPageCache(['home_blox_published' => '{}']));
        self::assertTrue(SettingModel::affectsPageCache(['home_blox_data' => '{}', 'home_blox_published' => '{}']));
        self::assertTrue(SettingModel::affectsPageCache([]));
        self::assertFalse(SettingModel::affectsPageCache(['cron_backup_last' => '1']));
    }
}
