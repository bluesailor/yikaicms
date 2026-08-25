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
    /** @runInSeparateProcess 隔离全局函数定义（configRawLang stub 与其它测试互不污染） */
    private function renderLogo(array $data): string
    {
        if (!function_exists('configRawLang')) {
            eval('function configRawLang(string $key, string $default = ""): string {
                return ["site_logo" => "/uploads/brand/site.png", "site_name" => "Acme"][$key] ?? $default;
            }');
        }
        return (new LogoElement())->render($data);
    }

    public function testDefaultFollowsSiteLogoWithPresetHeight(): void
    {
        $out = $this->renderLogo(['display' => 'image']);
        $this->assertStringContainsString('src="/uploads/brand/site.png"', $out);
        $this->assertStringContainsString('class="h-10 w-auto"', $out);
    }

    public function testLegacyCustomLogoDoesNotOverrideSiteSetting(): void
    {
        $out = $this->renderLogo(['display' => 'image', 'custom_logo' => '/uploads/brand/white.png']);
        $this->assertStringContainsString('src="/uploads/brand/site.png"', $out);
        $this->assertStringNotContainsString('white.png', $out);
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
