<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php'; // BloxIcon + 元素/资产收集器（单跑本文件也可用）

final class BloxIconTest extends TestCase
{
    public function testLegacyValueRemainsTabler(): void
    {
        $this->assertSame('home', BloxIcon::normalize('home'));
        $this->assertSame('ti ti-home', BloxIcon::classes('home'));
        $this->assertNull(BloxIcon::stylesheet('home'));
    }

    public function testBootstrapValueKeepsNamespace(): void
    {
        $this->assertSame('bi:house-door', BloxIcon::normalize('BI:House-Door'));
        $this->assertSame('bi bi-house-door', BloxIcon::classes('bi:house-door'));
        $this->assertSame(
            '/assets/bootstrap-icons/bootstrap-icons.min.css',
            BloxIcon::stylesheet('bi:house-door')
        );
    }

    public function testUnsupportedNamespaceAndUnsafeValueFallBack(): void
    {
        $this->assertSame('star', BloxIcon::normalize('fa:house'));
        $this->assertSame('ti ti-script', BloxIcon::classes('"><script>'));
        $this->assertSame('ti ti-chart-bar', BloxIcon::classes(null, 'chart-bar'));
    }

    public function testNoneIsOnlyTheLegacySentinel(): void
    {
        $this->assertTrue(BloxIcon::isNone('none'));
        $this->assertFalse(BloxIcon::isNone('bi:none'));
    }

    public function testElementRenderersUseBootstrapClassesAndAsset(): void
    {
        BloxAssetCollector::reset();
        $icon = BuilderRegistry::get('icon');
        $this->assertNotNull($icon);
        $html = BlockRenderer::renderElementNode([
            'type' => 'icon',
            'data' => ['icon' => 'bi:house-door', 'size' => 'md'],
        ]);

        $this->assertStringContainsString('class="bi bi-house-door inline-block"', $html);
        $this->assertContains('/assets/bootstrap-icons/bootstrap-icons.min.css', BloxAssetCollector::styles());
    }
}
