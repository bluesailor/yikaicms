<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BuilderRegistry;
use HomeBannerItemElement;
use HomeBloxRenderContext;
use HomeBloxRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBannerItemElementTest extends TestCase
{
    public function testNormalizeSanitizesTextUrlsAndTarget(): void
    {
        $item = HomeBannerItemElement::normalize([
            'title' => '<b>Launch</b>',
            'subtitle' => '<script>alert(1)</script>Welcome',
            'image' => '/uploads/banner.jpg',
            'image_mobile' => 'https://example.com/banner-mobile.jpg',
            'btn1_url' => 'javascript:alert(1)',
            'btn2_url' => 'mailto:sales@example.com',
            'link_target' => 'popup',
            'content_motion' => 'javascript:alert(1)',
            'background_motion' => 'rotate',
        ]);

        $this->assertSame('Launch', $item['title']);
        $this->assertSame('alert(1)Welcome', $item['subtitle']);
        $this->assertSame('/uploads/banner.jpg', $item['image']);
        $this->assertSame('https://example.com/banner-mobile.jpg', $item['image_mobile']);
        $this->assertSame('', $item['btn1_url']);
        $this->assertSame('mailto:sales@example.com', $item['btn2_url']);
        $this->assertSame('_self', $item['link_target']);
        $this->assertSame('inherit', $item['content_motion']);
        $this->assertSame('inherit', $item['background_motion']);
    }

    public function testEachBannerItemCanOverrideOrInheritContentMotion(): void
    {
        $element = new HomeBannerItemElement();
        $controls = [];
        foreach ($element->controls() as $control) {
            $controls[$control['key']] = $control;
        }

        $this->assertSame('inherit', $controls['content_motion']['default']);
        $this->assertSame('inherit', $controls['background_motion']['default']);
        $this->assertSame('', $controls['image_mobile']['default']);
        $this->assertSame('settings', $controls['content_motion']['option_icons']['inherit']);
        $this->assertArrayHasKey('clip-reveal', $controls['content_motion']['options']);
        $this->assertArrayHasKey('blur-up', $controls['content_motion']['options']);
        $this->assertArrayHasKey('pop-in', $controls['content_motion']['options']);
        $this->assertSame('', HomeBannerItemElement::contentMotionAttribute([]));
        $this->assertSame(
            ' data-blox-slide-content-motion="slide-left"',
            HomeBannerItemElement::contentMotionAttribute(['content_motion' => 'slide-left'])
        );
        $this->assertSame(
            ' data-blox-slide-content-motion="none"',
            HomeBannerItemElement::contentMotionAttribute(['content_motion' => 'none'])
        );
        $this->assertSame(
            ' data-blox-slide-content-motion="clip-reveal"',
            HomeBannerItemElement::contentMotionAttribute(['content_motion' => 'clip-reveal'])
        );
        $this->assertSame(
            ' data-blox-slide-content-motion="fade-up" data-blox-slide-background-motion="zoom-out"',
            HomeBannerItemElement::motionAttributes([
                'content_motion' => 'fade-up',
                'background_motion' => 'zoom-out',
            ])
        );
    }

    public function testResponsiveImageUsesMobileSourceWithSafeFallback(): void
    {
        $html = HomeBannerItemElement::responsiveImageHtml([
            'title' => 'Launch',
            'image' => '/uploads/desktop.jpg',
            'image_mobile' => '/uploads/mobile.jpg',
        ]);

        $this->assertStringContainsString('<picture class="block w-full h-full" data-blox-banner-bg>', $html);
        $this->assertStringContainsString('media="(max-width: 767px)"', $html);
        $this->assertStringContainsString('srcset="/uploads/mobile.jpg"', $html);
        $this->assertStringContainsString('src="/uploads/desktop.jpg"', $html);
        $this->assertStringNotContainsString('<source', HomeBannerItemElement::responsiveImageHtml([
            'image' => '/uploads/desktop.jpg',
        ]));
    }

    public function testLocalizedContentKeepsCustomPresentationAndMatchesTranslationGroups(): void
    {
        $customItems = [
            HomeBannerItemElement::normalize([
                'title' => '中文第二张',
                'image' => '/custom-second.jpg',
                'content_motion' => 'clip-reveal',
                'translation_group_id' => 20,
            ]),
            HomeBannerItemElement::normalize([
                'title' => '中文第一张',
                'image' => '/custom-first.jpg',
                'background_motion' => 'zoom-out',
                'translation_group_id' => 10,
            ]),
        ];
        $localized = [
            ['id' => 101, 'translation_group_id' => 10, 'lang' => 'en', 'title' => 'First', 'subtitle' => 'First subtitle'],
            ['id' => 102, 'translation_group_id' => 20, 'lang' => 'en', 'title' => 'Second', 'btn1_text' => 'Learn more'],
        ];

        $items = HomeBannerItemElement::applyLocalizedContent($customItems, $localized);

        $this->assertSame(['Second', 'First'], array_column($items, 'title'));
        $this->assertSame('/custom-second.jpg', $items[0]['image']);
        $this->assertSame('clip-reveal', $items[0]['content_motion']);
        $this->assertSame('/custom-first.jpg', $items[1]['image']);
        $this->assertSame('zoom-out', $items[1]['background_motion']);
        $this->assertSame('Learn more', $items[0]['btn1_text']);
    }

    public function testLocalizedContentFallsBackToDocumentOrderForLegacyChildren(): void
    {
        $customItems = [
            HomeBannerItemElement::normalize(['title' => '中文一', 'image' => '/one.jpg']),
            HomeBannerItemElement::normalize(['title' => '中文二', 'image' => '/two.jpg']),
        ];
        $localized = [
            ['id' => 101, 'translation_group_id' => 10, 'lang' => 'ja', 'title' => '日本語一'],
            ['id' => 102, 'translation_group_id' => 20, 'lang' => 'ja', 'title' => '日本語二'],
        ];

        $items = HomeBannerItemElement::applyLocalizedContent($customItems, $localized);

        $this->assertSame(['日本語一', '日本語二'], array_column($items, 'title'));
        $this->assertSame(['/one.jpg', '/two.jpg'], array_column($items, 'image'));
    }

    public function testCustomChildrenReplaceLiveBannersInDocumentOrder(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-banner-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, '<?php echo json_encode($banners, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);');

        $children = [
            ['type' => 'home-banner-item', 'data' => [
                'title' => 'Second',
                'image' => '/second.jpg',
                'image_mobile' => '/second-mobile.jpg',
                'content_motion' => 'zoom-in',
                'background_motion' => 'zoom-out',
            ]],
            ['type' => 'heading', 'data' => ['text' => 'Ignored']],
            ['type' => 'home-banner-item', 'data' => ['title' => 'First', 'image' => '/first.jpg']],
        ];

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'banner', 'enabled' => true]],
                ['banner' => (string) $fixture],
                [],
                [['title' => 'Live banner']],
                null,
                [],
                false
            );
            $html = $context->renderLegacyBlock([
                'data' => [
                    'block_type' => 'banner',
                    'enabled' => true,
                    'items_mode' => 'custom',
                    'children' => $children,
                    '_blox_path' => '2.1.3',
                ],
            ]);
            $items = json_decode($html, true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(['Second', 'First'], array_column($items, 'title'));
            $this->assertSame('zoom-in', $items[0]['content_motion']);
            $this->assertSame('zoom-out', $items[0]['background_motion']);
            $this->assertSame('/second-mobile.jpg', $items[0]['image_mobile']);
            $this->assertSame('inherit', $items[1]['content_motion']);
            $this->assertSame('inherit', $items[1]['background_motion']);
            $this->assertArrayNotHasKey('_blox_path', $items[0]);

            $editContext = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'banner', 'enabled' => true]],
                ['banner' => (string) $fixture],
                [],
                [],
                null,
                [],
                true
            );
            $editItems = json_decode($editContext->renderLegacyBlock([
                'data' => [
                    'block_type' => 'banner',
                    'enabled' => true,
                    'items_mode' => 'custom',
                    'children' => $children,
                    '_blox_path' => '2.1.3',
                ],
            ]), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('2.1.3.0', $editItems[0]['_blox_path']);
            $this->assertSame('2.1.3.2', $editItems[1]['_blox_path']);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testHomeRendererCarriesOriginalElementPathToDynamicCallback(): void
    {
        $capturedPath = '';
        HomeBloxRenderer::render([[
            'columns' => [[
                'elements' => [
                    ['type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => false]],
                    ['type' => 'home-block', 'data' => ['block_type' => 'banner', 'enabled' => true]],
                ],
            ]],
        ]], static function (array $element) use (&$capturedPath): string {
            $capturedPath = (string) ($element['data']['_blox_path'] ?? '');
            return '<section>Banner</section>';
        });

        $this->assertSame('0.0.1', $capturedPath);
    }

    public function testRegistryMarksHomeBlockAsSelfRenderingContainer(): void
    {
        $homeBlock = BuilderRegistry::get('home-block');

        $this->assertNotNull(BuilderRegistry::get('home-banner-item'));
        $this->assertNotNull($homeBlock);
        $this->assertTrue($homeBlock->isContainer());
        $this->assertTrue($homeBlock->rendersOwnChildren());

        $html = BlockRenderer::renderElementNode(
            ['type' => 'code', 'data' => ['html' => '<span>Banner</span>', '_blox_path' => '0.0.4']],
            0,
            true,
            [0, 0, 0]
        );
        $this->assertStringContainsString('data-yk-el="0.0.4"', $html);
    }
}
