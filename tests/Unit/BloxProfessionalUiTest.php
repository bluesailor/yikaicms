<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once ROOT_PATH . '/includes/builder/BloxProfessionalUi.php';

final class BloxProfessionalUiTest extends TestCase
{
    public function testOwnedStatesNeverAskForAnotherPurchase(): void
    {
        $owned = ['owned' => true, 'key' => true, 'supported' => true, 'installed' => true, 'active' => true];
        foreach (['supported' => 'upgrade', 'installed' => 'install', 'active' => 'enable'] as $flag => $state) {
            self::assertSame($state, BloxProfessionalUi::state('licensed', false, array_replace($owned, [$flag => false])));
        }
        self::assertSame('check', BloxProfessionalUi::state('licensed', false, $owned));
        self::assertSame('available', BloxProfessionalUi::state('licensed', true, $owned + ['expired' => true]));
        self::assertSame('activate', BloxProfessionalUi::state('licensed', false, ['key' => true]));
        self::assertSame('license', BloxProfessionalUi::state('licensed', false, []));
        self::assertSame('unavailable', BloxProfessionalUi::state('disabled', false, []));
        self::assertSame('available', BloxProfessionalUi::state('free', true, []));
    }

    public function testFreeFeaturesHaveNoCommercialMessageOrAction(): void
    {
        foreach (BloxProfessionalUi::snapshot() as $feature) {
            self::assertTrue($feature['allowed']);
            self::assertSame('', $feature['message']);
            self::assertSame('', $feature['url']);
        }
    }

    public function testDiscoveryIsCollapsedAndNeverAnAutomaticPromotion(): void
    {
        $view = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/professional-features.php');
        self::assertStringContainsString('<details ', $view);
        self::assertStringNotContainsString(' open=', $view);
        self::assertStringNotContainsString('ti-lock', $view);
        self::assertStringNotContainsString('ti-crown', $view);
        self::assertStringNotContainsString('modal', $view);
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString("if (self.panelTab === 'professional' ? !c.advanced : !!c.advanced) return false;", $editor);
        self::assertStringContainsString('professionalControlAccessible(key)', $editor);
        foreach (['zh-CN', 'en', 'ja'] as $language) {
            $strings = require ROOT_PATH . '/lang/' . $language . '.php';
            foreach (['upgrade', 'install', 'enable', 'activate', 'license', 'check', 'unavailable'] as $state) {
                self::assertNotEmpty($strings['blox_professional_' . $state]);
            }
        }
    }
}
