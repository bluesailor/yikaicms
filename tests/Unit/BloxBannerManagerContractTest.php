<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxBannerManagerContractTest extends TestCase
{
    public function testManagerUsesDirectEditingWithoutTheLegacyTakeoverStep(): void
    {
        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/banner-manager.php');
        $editor = (string) file_get_contents(ROOT_PATH . '/assets/js/blox-banner-panel.js');
        self::assertStringNotContainsString('adoptBannerItems(', $workspace . $editor);
        self::assertStringNotContainsString('blox_home_banner_items_help', $workspace);
        self::assertStringContainsString('@click="selectBannerItem(bi)"', $workspace);
        self::assertStringContainsString('data-testid="blox-banner-add"', $workspace);
        self::assertStringContainsString('data-testid="blox-banner-source"', $workspace);
        self::assertStringContainsString('blox_home_banner_source_live', $workspace);
        self::assertStringContainsString('if (!this.hasCustomBannerItems()) this.adoptBannerData(host);', $editor);
        self::assertStringContainsString('!confirm(this.homeDynamicText.restoreConfirm)', $editor);
    }
}
