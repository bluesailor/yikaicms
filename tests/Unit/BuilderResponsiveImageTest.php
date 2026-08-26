<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use CardElement;
use DynamicListItemSchema;
use DynamicLoopTemplateRenderer;
use HomeBannerItemElement;
use ImageElement;
use PHPUnit\Framework\TestCase;
use TagEngine;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BuilderResponsiveImageTest extends TestCase
{
    private string $directory;
    private string $urlBase;

    protected function setUp(): void
    {
        parent::setUp();
        $name = 'builder-responsive-' . getmypid();
        $this->directory = ROOT_PATH . '/storage/cache/' . $name;
        $this->urlBase = '/storage/cache/' . $name;
        mkdir($this->directory, 0775, true);
        $this->writeCandidates('desktop', 1600, 900, 800, 450);
        $this->writeCandidates('mobile', 900, 1200, 450, 600);
        TagEngine::setItem(null);
    }

    protected function tearDown(): void
    {
        TagEngine::setItem(null);
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testImageElementKeepsOriginalFallbackAndLightboxHref(): void
    {
        $url = $this->urlBase . '/desktop.png';
        $html = (new ImageElement())->render([
            'src' => $url,
            'alt' => 'Example',
            'click_action' => 'lightbox',
        ]);

        $this->assertStringContainsString('href="' . $url . '"', $html);
        $this->assertStringContainsString('src="' . $this->urlBase . '/desktop_medium.png"', $html);
        $this->assertStringContainsString('desktop_medium.png 800w', $html);
        $this->assertStringContainsString('desktop.png 1600w', $html);
        $this->assertStringContainsString('sizes="100vw"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
    }

    public function testCardAndBannerUseResponsiveCandidatesWithoutLosingMobileSource(): void
    {
        $desktop = $this->urlBase . '/desktop.png';
        $mobile = $this->urlBase . '/mobile.png';
        $card = (new CardElement())->render(['image' => $desktop, 'title' => 'Card']);
        $banner = HomeBannerItemElement::responsiveImageHtml([
            'image' => $desktop,
            'image_mobile' => $mobile,
            'title' => 'Banner',
        ]);

        $this->assertStringContainsString('src="' . $this->urlBase . '/desktop_medium.png"', $card);
        $this->assertStringContainsString('sizes="(min-width: 1280px) 384px, (min-width: 768px) 50vw, 100vw"', $card);
        $this->assertStringContainsString('decoding="async"', $card);
        $this->assertStringContainsString('type="image/webp"', $banner);
        $this->assertStringContainsString('mobile_medium.webp 450w', $banner);
        $this->assertStringContainsString('mobile_medium.png 450w', $banner);
        $this->assertStringContainsString('src="' . $this->urlBase . '/desktop_medium.png"', $banner);
        $this->assertStringContainsString('sizes="100vw"', $banner);
    }

    public function testDynamicCardAndControlledImageResolveAfterItemContextExists(): void
    {
        $url = $this->urlBase . '/desktop.png';
        TagEngine::setItem(['cover' => $url, 'title' => 'Dynamic']);

        $card = TagEngine::render(DynamicListItemSchema::render(['link_field' => 'none']));
        $controlledTemplate = DynamicLoopTemplateRenderer::render([
            ['type' => 'image', 'data' => [
                'loop_field' => 'cover',
                'loop_alt_field' => 'title',
                'loop_link_field' => 'none',
            ]],
        ], ['query_source' => 'type:article']);
        $controlled = TagEngine::render($controlledTemplate);

        foreach ([$card, $controlled] as $html) {
            $this->assertStringContainsString('src="' . $this->urlBase . '/desktop_medium.png"', $html);
            $this->assertStringContainsString('desktop.png 1600w', $html);
            $this->assertStringContainsString('decoding="async"', $html);
        }
        $this->assertStringContainsString('alt="Dynamic"', $controlled);
    }

    public function testImageAttributesTagRejectsUnsafeFieldValue(): void
    {
        TagEngine::setItem(['cover' => 'javascript:alert(1)']);

        $html = TagEngine::render('<img {yk:image-attrs name=cover size=invalid sizes="50vw" /} alt="">');

        $this->assertSame('<img src="" alt="">', $html);
    }

    public function testLegacyStoredImageShapesRemainVisible(): void
    {
        $data = 'data:image/png;base64,iVBORw0KGgo=';
        foreach ([
            '//cdn.example.com/legacy.png' => 'https://cdn.example.com/legacy.png',
            'uploads/legacy.png' => '/uploads/legacy.png',
            $data => $data,
        ] as $stored => $expected) {
            $image = (new ImageElement())->render(['src' => $stored, 'alt' => 'Legacy']);
            $card = (new CardElement())->render(['image' => $stored, 'title' => 'Legacy']);
            $this->assertStringContainsString('src="' . $expected . '"', $image, $stored);
            $this->assertStringContainsString('src="' . $expected . '"', $card, $stored);
        }
    }

    private function writeCandidates(string $name, int $width, int $height, int $mediumWidth, int $mediumHeight): void
    {
        file_put_contents($this->directory . '/' . $name . '.png', self::pngWithDimensions($width, $height));
        file_put_contents($this->directory . '/' . $name . '_medium.png', self::pngWithDimensions($mediumWidth, $mediumHeight));
        file_put_contents($this->directory . '/' . $name . '.webp', 'webp-fixture');
        file_put_contents($this->directory . '/' . $name . '_medium.webp', 'webp-fixture');
    }

    private static function pngWithDimensions(int $width, int $height): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png);
        $png = substr_replace($png, pack('N', $width), 16, 4);
        return substr_replace($png, pack('N', $height), 20, 4);
    }
}
