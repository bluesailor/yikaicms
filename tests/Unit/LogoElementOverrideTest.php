<?php
/**
 * LogoElement 始终读取站点 Logo，同时保留元素级精确像素高度。
 */

declare(strict_types=1);

namespace {
    require_once ROOT_PATH . '/includes/builder/bootstrap.php';
}

namespace Yikai\Tests\Unit {

use LogoElement;
use PHPUnit\Framework\TestCase;

final class LogoElementOverrideTest extends TestCase
{
    /** 通过全局测试值切换站点 Logo，configRawLang 桩只定义一次。 */
    private function renderLogo(array $data, string $logo = '/images/logo.png'): string
    {
        $GLOBALS['yikai_logo_test_url'] = $logo;
        if (!function_exists('configRawLang')) {
            eval('function configRawLang(string $key, string $default = ""): string {
                return ["site_logo" => (string) ($GLOBALS["yikai_logo_test_url"] ?? ""), "site_name" => "Acme"][$key] ?? $default;
            }');
        }
        return (new LogoElement())->render($data);
    }

    public function testDefaultFollowsSiteLogoWithPresetHeight(): void
    {
        $out = $this->renderLogo(['display' => 'image']);
        $this->assertStringContainsString('<div class="shrink-0">', $out);
        $this->assertStringContainsString('src="/images/logo.png"', $out);
        $this->assertStringContainsString('class="h-10 w-auto"', $out);
    }

    public function testLegacyCustomLogoDoesNotOverrideSiteSetting(): void
    {
        $out = $this->renderLogo(['display' => 'image', 'custom_logo' => '/uploads/brand/white.png']);
        $this->assertStringContainsString('src="/images/logo.png"', $out);
        $this->assertStringNotContainsString('white.png', $out);
    }

    public function testMissingLocalLogoFallsBackToSiteName(): void
    {
        $out = $this->renderLogo(['display' => 'both'], '/uploads/brand/missing-logo.png');

        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('>Acme</span>', $out);
    }

    public function testControlsDoNotExposePerHeaderLogoOverride(): void
    {
        $keys = array_column((new LogoElement())->controls(), 'key');

        $this->assertNotContains('custom_logo', $keys);
    }

    public function testCustomHeightUsesPixelMaxHeight(): void
    {
        $out = $this->renderLogo(['display' => 'image', 'custom_height' => 56]);
        $this->assertStringContainsString('style="max-height:56px"', $out);
        $this->assertStringNotContainsString('h-10', $out);
    }

    public function testOutOfRangeCustomHeightFallsBackToPreset(): void
    {
        $out = $this->renderLogo(['display' => 'image', 'custom_height' => 8]);
        $this->assertStringContainsString('class="h-10 w-auto"', $out);
        $this->assertStringNotContainsString('max-height', $out);
    }

}

}
