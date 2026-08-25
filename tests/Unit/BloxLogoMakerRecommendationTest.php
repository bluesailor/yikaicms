<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxLogoMakerRecommendationTest extends TestCase
{
    public function testEditorResolvesLogoMakerActionByPluginStateAndPermission(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        self::assertStringContainsString("\$canManageLogoMaker = hasPermission('*');", $editor);
        self::assertStringContainsString("is_dir(ROOT_PATH . '/plugins/logo-maker')", $editor);
        self::assertStringContainsString("isPluginAvailable('logo-maker')", $editor);
        self::assertStringContainsString('/admin/plugin_page.php?plugin=logo-maker#logo', $editor);
        self::assertStringContainsString('/admin/plugin.php#plugin-logo-maker', $editor);
        self::assertStringContainsString('/admin/plugin.php?tab=market&q=logo-maker', $editor);
    }

    public function testRecommendationIsScopedToTheLogoCustomImageControl(): void
    {
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        self::assertStringContainsString("selEl.type === 'logo' && ctrl.key === 'custom_logo'", $workspace);
        self::assertStringContainsString('data-testid="blox-logo-maker-recommendation"', $workspace);
        self::assertStringContainsString('data-testid="blox-logo-maker-action"', $workspace);
        self::assertStringContainsString('data-logo-maker-state=', $workspace);
        self::assertStringContainsString('data-testid="blox-logo-lab-action"', $workspace);
        self::assertStringContainsString('https://logo.yikaicms.com/#icon', $workspace);
        self::assertStringContainsString(
            'href="<?= e($logoMakerActionUrl) ?>" target="_blank" rel="noopener"',
            $workspace
        );
        self::assertStringContainsString(
            'href="https://logo.yikaicms.com/#icon" target="_blank" rel="noopener"',
            $workspace
        );
    }

    private function source(string $path): string
    {
        $file = ROOT_PATH . '/' . $path;
        if (!is_file($file)) {
            self::markTestSkipped('Blox source is not available: ' . $path);
        }
        return (string) file_get_contents($file);
    }
}
