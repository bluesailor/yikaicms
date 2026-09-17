<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BloxAssetCollector;
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
            'media_type' => 'video',
            'video' => '/uploads/videos/launch.mp4',
            'video_mobile_mode' => 'video',
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
        $this->assertSame('video', $item['media_type']);
        $this->assertSame('/uploads/videos/launch.mp4', $item['video']);
        $this->assertSame('video', $item['video_mobile_mode']);
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
        $this->assertSame('image', $controls['media_type']['default']);
        $this->assertSame('', $controls['video']['default']);
        $this->assertSame('poster', $controls['video_mobile_mode']['default']);
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

    public function testResponsiveVideoKeepsPosterAndRejectsUnsafeOrNonVideoUrls(): void
    {
        $html = HomeBannerItemElement::responsiveMediaHtml([
            'title' => 'Launch',
            'media_type' => 'video',
            'video' => '/uploads/videos/launch.mp4',
            'image' => '/uploads/launch.jpg',
            'image_mobile' => '/uploads/launch-mobile.jpg',
            'video_mobile_mode' => 'poster',
        ]);

        $this->assertStringContainsString('data-blox-banner-poster', $html);
        $this->assertStringContainsString('data-blox-banner-video', $html);
        $this->assertStringContainsString('data-blox-mobile-video="poster"', $html);
        $this->assertStringContainsString('poster="/uploads/launch.jpg"', $html);
        $this->assertStringContainsString('data-blox-video-src="/uploads/videos/launch.mp4"', $html);
        $this->assertStringNotContainsString(' src="/uploads/videos/launch.mp4"', $html);
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertStringNotContainsString('autoplay', $html);

        foreach (['javascript:alert(1)', 'https://www.youtube.com/watch?v=x', '/uploads/readme.txt'] as $unsafe) {
            $fallback = HomeBannerItemElement::responsiveMediaHtml([
                'media_type' => 'video',
                'video' => $unsafe,
                'image' => '/uploads/fallback.jpg',
            ]);
            $this->assertStringNotContainsString('<video', $fallback);
            $this->assertStringContainsString('/uploads/fallback.jpg', $fallback);
        }
    }

    public function testLinkedMediaUsesNormalizedSafeLink(): void
    {
        $linked = HomeBannerItemElement::responsiveLinkedMediaHtml([
            'media_type' => 'video',
            'video' => '/uploads/videos/launch.mp4',
            'image' => '/uploads/launch.jpg',
            'link_url' => 'https://example.com/launch?from=banner&amp;lang=en',
            'link_target' => '_blank',
        ]);
        $unsafe = HomeBannerItemElement::responsiveLinkedMediaHtml([
            'image' => '/uploads/launch.jpg',
            'link_url' => 'javascript:alert(1)',
        ]);

        $this->assertStringStartsWith('<a href="https://example.com/launch?', $linked);
        $this->assertStringContainsString('target="_blank"', $linked);
        $this->assertStringContainsString('data-blox-banner-video', $linked);
        $this->assertStringNotContainsString('<a ', $unsafe);
    }

    public function testAllBundledBannerTemplatesRenderVideoMedia(): void
    {
        $banner = [
            'title' => 'Launch',
            'subtitle' => 'Video banner',
            'media_type' => 'video',
            'video' => '/uploads/videos/launch.webm',
            'image' => '/uploads/launch.jpg',
            'image_mobile' => '',
            'video_mobile_mode' => 'poster',
            'btn1_text' => '',
            'btn1_url' => '',
            'btn2_text' => '',
            'btn2_url' => '',
            'link_url' => '',
            'link_target' => '_self',
        ];

        foreach ([
            'default' => ROOT_PATH . '/themes/default/blocks/banner.php',
            'aurora' => ROOT_PATH . '/marketplace/themes/aurora/blocks/banner.php',
            'business' => ROOT_PATH . '/marketplace/themes/business/blocks/banner.php',
            'minimal' => ROOT_PATH . '/marketplace/themes/minimal/blocks/banner.php',
            'trade' => ROOT_PATH . '/marketplace/themes/trade/blocks/banner.php',
            'legacy-fallback' => ROOT_PATH . '/includes/blocks/banner.php',
        ] as $name => $template) {
            BloxAssetCollector::reset();
            $banners = [$banner];
            $block = [];
            $siteName = 'YikaiCMS';
            ob_start();
            include $template;
            $html = (string) ob_get_clean();

            $this->assertStringContainsString('data-blox-banner-video', $html, $name);
            $this->assertStringContainsString(
                'data-blox-video-src="/uploads/videos/launch.webm"',
                $html,
                $name
            );
            $this->assertStringContainsString('data-blox-banner-poster', $html, $name);
            $this->assertContains('/assets/css/blox-banner.css', BloxAssetCollector::styles(), $name);
            $this->assertContains('/assets/js/blox-video-policy.js', BloxAssetCollector::scripts(), $name);
            $this->assertContains('/assets/js/blox-banner.js', BloxAssetCollector::scripts(), $name);
        }
    }

    /**
     * 左右滑入必须走「内容宽度 + 50% 起始透明度」这套参数。
     *
     * 原先写死 44px，在 1920 宽的大图上几乎看不出文字在动；起始透明度 0 又让文字
     * 像是凭空出现。位移改为按元素自身宽度（translate3d 的百分比语义），两个值都
     * 抽成 CSS 变量，站点可覆盖。
     */
    public function testSlideMotionsTravelContentWidthFromHalfOpacity(): void
    {
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/blox-banner.css');

        self::assertMatchesRegularExpression(
            '/\[data-blox-banner\]\s*\{[^}]*--blox-banner-slide-distance:\s*100%;/s',
            $css,
            '滑入距离应默认为内容宽度（100%）'
        );
        self::assertMatchesRegularExpression(
            '/\[data-blox-banner\]\s*\{[^}]*--blox-banner-slide-opacity:\s*\.5;/s',
            $css,
            '滑入起始透明度应为 50%'
        );

        foreach (['left' => '', 'right' => 'calc(-1 * '] as $direction => $prefix) {
            self::assertMatchesRegularExpression(
                '/@keyframes blox-banner-slide-' . $direction . ' \{\s*from \{\s*opacity: var\(--blox-banner-slide-opacity, \.5\);/s',
                $css,
                "slide-{$direction} 的起始透明度没走变量"
            );
            self::assertStringContainsString(
                'translate3d(' . $prefix . 'var(--blox-banner-slide-distance, 100%)',
                $css,
                "slide-{$direction} 的位移没走变量"
            );
        }

        // 老的固定像素位移不该再出现
        self::assertStringNotContainsString('translate3d(44px, 0, 0)', $css);
        self::assertStringNotContainsString('translate3d(-44px, 0, 0)', $css);

        // 减少动效偏好仍然整体关掉动画
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }

    public function testBundledBannerTemplatesKeepContentAndPrimaryAction(): void
    {
        $banner = [
            'title' => 'A focused launch',
            'subtitle' => 'Clear supporting copy',
            'media_type' => 'image',
            'image' => '/uploads/launch.jpg',
            'image_mobile' => '',
            'video' => '',
            'video_mobile_mode' => 'poster',
            'btn1_text' => 'Explore now',
            'btn1_url' => '/contact.html',
            'btn2_text' => '',
            'btn2_url' => '',
            'link_url' => '',
            'link_target' => '_self',
        ];

        foreach ([
            'default' => ROOT_PATH . '/themes/default/blocks/banner.php',
            'business' => ROOT_PATH . '/marketplace/themes/business/blocks/banner.php',
            'minimal' => ROOT_PATH . '/marketplace/themes/minimal/blocks/banner.php',
        ] as $name => $template) {
            BloxAssetCollector::reset();
            $banners = [$banner];
            $block = [];
            $siteName = 'YikaiCMS';
            ob_start();
            include $template;
            $html = (string) ob_get_clean();

            if ($name === 'minimal') {
                $this->assertStringContainsString('data-blox-banner-content', $html, $name);
            }
            $this->assertStringContainsString('A focused launch', $html, $name);
            $this->assertStringContainsString('Clear supporting copy', $html, $name);
            $this->assertStringContainsString('href="/contact.html"', $html, $name);
            $this->assertStringContainsString('Explore now', $html, $name);
        }
    }

    public function testThemeMarketplaceBannerCompatibilityChecklistMatchesSources(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(ROOT_PATH . '/marketplace/banner-media-compatibility.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $marketThemes = array_map(
            'basename',
            glob(ROOT_PATH . '/marketplace/themes/*', GLOB_ONLYDIR) ?: []
        );
        sort($marketThemes);
        $listedThemes = array_values(array_diff(array_keys($manifest['themes']), ['default']));
        sort($listedThemes);

        $this->assertSame($marketThemes, $listedThemes);
        $this->assertSame(
            'HomeBannerItemElement responsive media',
            $manifest['contract']
        );

        foreach ($manifest['themes'] as $slug => $entry) {
            $template = ROOT_PATH . '/' . $entry['template'];
            $this->assertFileExists($template, $slug);
            if ($slug !== 'default') {
                $meta = json_decode(
                    (string) file_get_contents(ROOT_PATH . '/marketplace/themes/' . $slug . '/theme.json'),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $this->assertSame($meta['version'], $entry['version'], $slug);
                $this->assertTrue($meta['capabilities']['banner_video'] ?? false, $slug);
            }
            if ($entry['mode'] === 'default-fallback') {
                $this->assertFileDoesNotExist(
                    ROOT_PATH . '/marketplace/themes/' . $slug . '/blocks/banner.php',
                    $slug
                );
                continue;
            }
            $source = (string) file_get_contents($template);
            $this->assertStringContainsString('HomeBannerItemElement::registerRuntimeAssets()', $source, $slug);
            $renderer = (string) ($entry['renderer'] ?? '');
            $this->assertContains($renderer, ['responsiveMediaHtml', 'responsiveLinkedMediaHtml'], $slug);
            $this->assertStringContainsString('HomeBannerItemElement::' . $renderer . '(', $source, $slug);
        }
    }

    public function testBannerShortcodeUsesTheSharedVideoRenderer(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        $start = strpos($source, 'function renderBannerShortcode');
        $end = strpos($source, 'function isJsonFields', $start === false ? 0 : $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $shortcode = substr($source, (int) $start, (int) $end - (int) $start);
        $this->assertStringContainsString('HomeBannerItemElement::responsiveLinkedMediaHtml($b)', $shortcode);
        $this->assertStringNotContainsString('HomeBannerItemElement::responsiveImageHtml($b)', $shortcode);
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

    public function testEditorShowsLanguageBannersAndSavesEditsAsThatLanguageOnly(): void
    {
        $host = static fn (array $children, string $mode = 'custom'): array => [[
            'id' => 's', 'columns' => [['id' => 'c', 'elements' => [[
                'id' => 'e', 'type' => 'home-block',
                'data' => ['block_type' => 'banner', 'items_mode' => $mode, 'children' => $children],
            ]]]],
        ]];
        $children = static fn (array $sections): array => $sections[0]['columns'][0]['elements'][0]['data']['children'];
        $shared = $host([
            ['id' => 'a', 'type' => 'home-banner-item', 'data' => ['title' => '中文一', 'btn1_text' => '了解', 'image' => '/one.jpg', 'translation_group_id' => 10]],
        ]);
        $english = [['id' => 101, 'translation_group_id' => 10, 'lang' => 'en', 'title' => 'First', 'btn1_text' => 'More']];

        $view = HomeBannerItemElement::forEditor($shared, $english, 'en');
        $this->assertSame('First', $children($view)[0]['data']['title']);
        $this->assertSame('More', $children($view)[0]['data']['btn1_text']);
        $this->assertSame($shared, HomeBannerItemElement::fromEditor(HomeBannerItemElement::markEditorChanges($view)));

        $view[0]['columns'][0]['elements'][0]['data']['children'][0]['data']['title'] = 'Edited';
        $saved = HomeBannerItemElement::fromEditor(HomeBannerItemElement::markEditorChanges($view));
        $data = $children($saved)[0]['data'];
        $this->assertSame('中文一', $data['title']);
        $this->assertSame('了解', $data['btn1_text']);
        $this->assertSame(['en' => ['title' => 'Edited']], $data[HomeBannerItemElement::I18N_KEY]);
        $this->assertArrayNotHasKey(HomeBannerItemElement::EDIT_KEY, $data);

        $items = HomeBannerItemElement::applyLocalizedContent(HomeBannerItemElement::normalizeChildren($children($saved)), $english, 'en');
        $this->assertSame(['Edited', 'More', '/one.jpg'], [$items[0]['title'], $items[0]['btn1_text'], $items[0]['image']]);
        $this->assertArrayNotHasKey(HomeBannerItemElement::I18N_KEY, $items[0]);
        $this->assertSame('Edited', $children(HomeBannerItemElement::forEditor($saved, $english, 'en'))[0]['data']['title']);
        // 编辑中尚未保存：画布显示正在编辑的值
        $live = HomeBannerItemElement::applyLocalizedContent(HomeBannerItemElement::normalizeChildren($children($view)), $english, 'en');
        $this->assertSame('Edited', $live[0]['title']);

        // 默认语言：自定义轮播就是共享内容本身；继承模式显示该语言记录但不加标记
        $this->assertSame($shared, HomeBannerItemElement::forEditor($shared, $english, 'en', true));
        $inherit = HomeBannerItemElement::forEditor($host($children($shared), 'inherit'), $english, 'en', true);
        $this->assertSame('First', $children($inherit)[0]['data']['title']);
        $this->assertArrayNotHasKey(HomeBannerItemElement::EDIT_KEY, $children($inherit)[0]['data']);
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
