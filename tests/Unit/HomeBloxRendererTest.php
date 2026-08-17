<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxRenderContext;
use HomeBloxRenderer;
use BloxAssetCollector;
use BlockRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBloxRendererTest extends TestCase
{
    protected function tearDown(): void
    {
        BlockRenderer::$showHidden = false;
        BloxAssetCollector::reset();
        parent::tearDown();
    }

    public function testDynamicHomeBlocksKeepOrderAndColumnStructure(): void
    {
        $called = [];
        $sections = [[
            'settings' => [],
            'columns' => [
                ['elements' => [
                    ['type' => 'heading', 'data' => ['text' => 'Regular']],
                    ['type' => 'home-block', 'data' => ['block_type' => 'banner', 'enabled' => true]],
                ]],
                ['elements' => [
                    ['type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => true]],
                ]],
            ],
        ]];

        $html = HomeBloxRenderer::render($sections, static function (array $element) use (&$called): string {
            $type = (string) ($element['data']['block_type'] ?? '');
            $called[] = $type;
            return '<aside data-test="' . $type . '">' . ucfirst($type) . '</aside>';
        });

        $this->assertSame(['banner', 'about'], $called);
        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2', $html);
        $this->assertStringContainsString('<aside data-test="banner">Banner</aside>', $html);
        $this->assertStringContainsString('<aside data-test="about">About</aside>', $html);
        $this->assertGreaterThan(strpos($html, 'Regular'), strpos($html, 'Banner'));
    }

    public function testDisabledDynamicHomeBlocksAreNotDelegated(): void
    {
        $called = 0;
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'home-block',
                    'data' => ['block_type' => 'banner', 'enabled' => false],
                ]],
            ]],
        ]];

        $html = HomeBloxRenderer::render($sections, static function (array $element) use (&$called): string {
            $called++;
            return 'unexpected';
        });

        $this->assertSame(0, $called);
        $this->assertStringNotContainsString('unexpected', $html);
    }

    public function testDisabledDynamicHomeBlocksRemainVisibleInTheEditorCanvas(): void
    {
        BlockRenderer::$showHidden = true;
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'home-block',
                    'data' => [
                        'block_type' => 'testimonials',
                        'label' => 'Customer reviews',
                        'enabled' => false,
                    ],
                ]],
            ]],
        ]];

        $html = HomeBloxRenderer::render($sections, static fn (): string => 'unexpected');

        $this->assertStringNotContainsString('unexpected', $html);
        $this->assertStringContainsString('data-home-block="testimonials"', $html);
        $this->assertStringContainsString('Customer reviews', $html);
        $this->assertStringContainsString('blox_home_disabled', $html);
    }

    public function testHeaderOverlayRequiresCoverBannerAsFirstVisibleElement(): void
    {
        $coverBanner = [
            'type' => 'home-block',
            'data' => [
                'block_type' => 'banner',
                'enabled' => true,
                'banner_height_mode' => 'cover-header',
            ],
        ];
        $section = static fn (array $elements, array $settings = []): array => [
            'settings' => $settings,
            'columns' => [['elements' => $elements]],
        ];

        $this->assertTrue(HomeBloxRenderer::startsWithHeaderOverlayBanner([$section([$coverBanner])]));
        $this->assertTrue(HomeBloxRenderer::startsWithHeaderOverlayBanner([
            $section([['type' => 'heading', 'data' => ['text' => 'Hidden']]], ['hidden' => true]),
            $section([
                ['type' => 'home-block', 'data' => ['block_type' => 'about', 'enabled' => false]],
                $coverBanner,
            ]),
        ]));
        $this->assertFalse(HomeBloxRenderer::startsWithHeaderOverlayBanner([
            $section([['type' => 'heading', 'data' => ['text' => 'Before']], $coverBanner]),
        ]));
        $this->assertFalse(HomeBloxRenderer::startsWithHeaderOverlayBanner([
            $section([array_replace_recursive($coverBanner, ['data' => ['banner_height_mode' => 'screen']])]),
        ]));

        $inheritBanner = array_replace_recursive($coverBanner, ['data' => ['banner_height_mode' => 'inherit']]);
        $this->assertTrue(HomeBloxRenderer::startsWithVisibleBanner([$section([$inheritBanner])]));
        $this->assertTrue(HomeBloxRenderer::startsWithHeaderOverlayBanner(
            [$section([$inheritBanner])],
            ['height_mode' => 'cover-header']
        ));
        $this->assertFalse(HomeBloxRenderer::startsWithHeaderOverlayBanner(
            [$section([$inheritBanner])],
            ['height_mode' => 'screen']
        ));
    }

    public function testLegacyHeaderOverlayRequiresCoverGroupAndFirstEnabledBanner(): void
    {
        $coverGroup = ['height_mode' => 'cover-header'];

        $this->assertTrue(HomeBloxRenderer::legacyStartsWithHeaderOverlayBanner([
            ['type' => 'about', 'enabled' => false],
            ['type' => 'banner', 'enabled' => true],
        ], $coverGroup));
        $this->assertTrue(HomeBloxRenderer::legacyStartsWithVisibleBanner([
            ['type' => 'about', 'enabled' => false],
            ['type' => 'banner', 'enabled' => true],
        ]));
        $this->assertFalse(HomeBloxRenderer::legacyStartsWithHeaderOverlayBanner([
            ['type' => 'about', 'enabled' => true],
            ['type' => 'banner', 'enabled' => true],
        ], $coverGroup));
        $this->assertFalse(HomeBloxRenderer::legacyStartsWithHeaderOverlayBanner([
            ['type' => 'banner', 'enabled' => true],
        ], ['height_mode' => 'screen']));
    }

    public function testRenderedBannerCollectsRuntimeAssetsBeforeCodeConversion(): void
    {
        BloxAssetCollector::reset();
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'home-block',
                    'data' => ['block_type' => 'banner', 'enabled' => true],
                ]],
            ]],
        ]];

        HomeBloxRenderer::render($sections, static fn (array $element): string => '<div>Banner</div>');

        $this->assertSame(['/assets/css/blox-banner.css'], BloxAssetCollector::styles());
        $this->assertSame(['/assets/js/blox-banner.js'], BloxAssetCollector::scripts());
    }

    public function testEmptyDynamicBlockDoesNotCollectUnusedRuntimeAssets(): void
    {
        BloxAssetCollector::reset();
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'home-block',
                    'data' => ['block_type' => 'banner', 'enabled' => true],
                ]],
            ]],
        ]];

        HomeBloxRenderer::render($sections, static fn (array $element): string => '');

        $this->assertSame([], BloxAssetCollector::styles());
        $this->assertSame([], BloxAssetCollector::scripts());
    }
    public function testSharedContextDispatchesTheSameDynamicTemplateEntryPoint(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-render-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, "<?php echo 'dynamic-fixture';");

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'fixture', 'enabled' => true]],
                ['fixture' => (string) $fixture],
                [],
                [],
                null,
                [],
                false
            );

            $html = $context->renderLegacyBlock([
                'data' => ['block_type' => 'fixture', 'enabled' => true],
            ]);

            $this->assertSame('dynamic-fixture', $html);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testCustomBlockPreviewDoesNotLeakNestedEditorCoordinates(): void
    {
        $configKey = 'home_custom_99';
        if (!isset($GLOBALS['_test_config']) || !is_array($GLOBALS['_test_config'])) {
            $GLOBALS['_test_config'] = [];
        }
        $hadConfig = array_key_exists($configKey, $GLOBALS['_test_config']);
        $oldConfig = $GLOBALS['_test_config'][$configKey] ?? null;
        $oldEditChannelId = \BlockRenderer::$editChannelId;
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }
        $hadAdminId = array_key_exists('admin_id', $_SESSION);
        $oldAdminId = $_SESSION['admin_id'] ?? null;

        $GLOBALS['_test_config'][$configKey] = json_encode(['blocks' => [[
            'id' => 's_custom',
            'settings' => ['title' => 'Pricing', 'subtitle' => 'Pick a plan'],
            'columns' => [[
                'id' => 'c_custom',
                'elements' => [[
                    'id' => 'e_custom',
                    'type' => 'heading',
                    'data' => ['text' => 'Price plans'],
                ]],
            ]],
        ]]], JSON_UNESCAPED_UNICODE);
        \BlockRenderer::$editChannelId = 7;
        $_SESSION['admin_id'] = 1;

        try {
            $html = HomeBloxRenderContext::fromHomePageData([], [], [], [], null, [], true)
                ->renderLegacyBlock(['data' => [
                    'block_type' => 'custom:99',
                    'enabled' => true,
                    '_blox_path' => '7.0.0',
                ]]);

            $this->assertStringContainsString('data-yk-home="custom:99"', $html);
            $this->assertStringContainsString('Price plans', $html);
            $this->assertStringContainsString('data-yk-home-path="7.0.0"', $html);
            $this->assertStringContainsString('data-yk-home-field="custom_title"', $html);
            $this->assertStringContainsString('data-yk-home-field="custom_subtitle"', $html);
            $locale = \HomeBloxBlockSchema::customLocaleKey();
            $this->assertStringContainsString(
                'data-yk-home-field="custom_overrides.' . $locale . '.0.columns.0.elements.0.data.text"',
                $html
            );
            foreach (['data-yk-sec=', 'data-yk-con=', 'data-yk-col=', 'data-yk-el=', 'data-yk-sec-field='] as $marker) {
                $this->assertStringNotContainsString($marker, $html);
            }
            $this->assertSame(7, \BlockRenderer::$editChannelId);

            $overridden = HomeBloxRenderContext::fromHomePageData([], [], [], [], null, [], true)
                ->renderLegacyBlock(['data' => [
                    'block_type' => 'custom:99',
                    'enabled' => true,
                    '_blox_path' => '7.0.0',
                    'custom_title' => 'Edited pricing',
                    'custom_subtitle' => 'Edited subtitle',
                    'custom_overrides' => [
                        $locale => [0 => ['columns' => [0 => ['elements' => [
                            0 => ['data' => ['text' => 'Edited plan']],
                        ]]]]],
                    ],
                ]]);
            $this->assertStringContainsString('Edited pricing', $overridden);
            $this->assertStringContainsString('Edited subtitle', $overridden);
            $this->assertStringContainsString('Edited plan', $overridden);
            $this->assertStringNotContainsString('>Pricing<', $overridden);
            $this->assertStringNotContainsString('>Pick a plan<', $overridden);
        } finally {
            \BlockRenderer::$editChannelId = $oldEditChannelId;
            if ($hadAdminId) {
                $_SESSION['admin_id'] = $oldAdminId;
            } else {
                unset($_SESSION['admin_id']);
            }
            if ($hadConfig) {
                $GLOBALS['_test_config'][$configKey] = $oldConfig;
            } else {
                unset($GLOBALS['_test_config'][$configKey]);
            }
        }
    }

    public function testContainerGutterCanBeRemovedWithoutChangingLegacyDefault(): void
    {
        $section = static fn (array $settings): array => [[
            'settings' => $settings,
            'columns' => [[
                'elements' => [[
                    'type' => 'heading',
                    'data' => ['text' => 'Gutter test'],
                ]],
            ]],
        ]];

        $legacy = \BlockRenderer::render(json_encode($section(['max_width' => 'full']), JSON_THROW_ON_ERROR));
        $edgeToEdge = \BlockRenderer::render(json_encode($section([
            'max_width' => 'full',
            'container_gutter' => 'none',
        ]), JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('class="max-w-full mx-auto px-4"', $legacy);
        $this->assertStringContainsString('class="max-w-full mx-auto"', $edgeToEdge);
        $this->assertStringNotContainsString('max-w-full mx-auto px-4', $edgeToEdge);
    }

    public function testAboutEditModeAddsClickableFieldMarkersOnlyToPreview(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-about-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, <<<'PHP'
<section>
    <div>
        <h2>About title</h2>
        <p>About content</p>
        <a href="/about">Learn more</a>
        <div class="font-bold text-lg">Badge</div>
        <div class="text-sm opacity-90">Badge description</div>
        <img loading="lazy" src="/about.jpg" alt="">
    </div>
</section>
PHP);

        try {
            $element = ['data' => [
                'block_type' => 'about',
                'enabled' => true,
                '_blox_path' => '1.0.0',
            ]];
            $preview = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'about', 'enabled' => true]],
                ['about' => (string) $fixture],
                [],
                [],
                null,
                [],
                true
            )->renderLegacyBlock($element);
            $frontend = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'about', 'enabled' => true]],
                ['about' => (string) $fixture],
                [],
                [],
                null,
                [],
                false
            )->renderLegacyBlock($element);

            $this->assertSame(6, substr_count($preview, 'data-yk-home-field='));
            foreach ([
                'override_title',
                'override_content',
                'override_button_text',
                'override_tag_title',
                'override_tag_description',
                'override_image',
            ] as $field) {
                $this->assertStringContainsString('data-yk-home-field="' . $field . '"', $preview);
            }
            $this->assertSame(6, substr_count($preview, 'data-yk-home-path="1.0.0"'));
            $this->assertStringNotContainsString('data-yk-home-field=', $frontend);
            $this->assertStringNotContainsString('data-yk-home-path=', $frontend);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testEditFieldAttributeCallbackAllowsBlueprintPathsOnly(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-fields-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, <<<'PHP'
<?php echo '<div' . $ykHomeFieldAttr('stats_items.0.number') . '>42</div>'; ?>
<?php echo '<div' . $ykHomeFieldAttr('stats_items.9.number') . '>invalid</div>'; ?>
PHP);

        try {
            $element = ['data' => [
                'block_type' => 'stats',
                'enabled' => true,
                '_blox_path' => '2.0.0',
            ]];
            $preview = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'stats', 'enabled' => true]],
                ['stats' => (string) $fixture],
                [],
                [],
                null,
                [],
                true
            )->renderLegacyBlock($element);
            $frontend = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'stats', 'enabled' => true]],
                ['stats' => (string) $fixture],
                [],
                [],
                null,
                [],
                false
            )->renderLegacyBlock($element);

            $this->assertSame(1, substr_count($preview, 'data-yk-home-field='));
            $this->assertStringContainsString('data-yk-home-field="stats_items.0.number"', $preview);
            $this->assertStringContainsString('data-yk-home-path="2.0.0"', $preview);
            $this->assertStringNotContainsString('stats_items.9.number', $preview);
            $this->assertStringNotContainsString('data-yk-home-field=', $frontend);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testDefaultAboutTemplateDefinesWhitelistedRatioClasses(): void
    {
        $template = file_get_contents(ROOT_PATH . '/themes/default/blocks/about.php');
        $this->assertIsString($template);
        $this->assertStringContainsString(
            "'2_1' => ['grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center', 'lg:col-span-8', 'lg:col-span-4']",
            $template
        );
        $this->assertStringContainsString(
            "'1_1' => ['grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-14 items-center', '', '']",
            $template
        );
        $this->assertStringContainsString(
            "'1_1' => ['grid grid-cols-1 md:grid-cols-2 gap-10 md:gap-14 items-center', '', '']",
            $template
        );
        $this->assertStringContainsString("config('home_about_breakpoint', 'lg')", $template);
        $this->assertStringContainsString("\$aboutIsImageLeft = \$aboutLayout === 'image_left';", $template);
        $this->assertStringContainsString('$aboutTextSpanClass,', $template);
        $this->assertStringContainsString('$aboutImageSpanClass,', $template);
        $this->assertStringContainsString('data-yk-home-columns="2"', $template);
        $this->assertStringContainsString('data-yk-home-breakpoint=', $template);
        $this->assertStringContainsString('data-yk-home-layout-label=', $template);
        $this->assertStringContainsString('data-yk-home-column="text"', $template);
        $this->assertStringContainsString('data-yk-home-column="image"', $template);
        $this->assertStringContainsString('aspect-[4/3]', $template);
    }

    public function testDefaultStatsTemplateExposesConfigurableCounterAnimation(): void
    {
        $template = file_get_contents(ROOT_PATH . '/themes/default/blocks/stats.php');
        $footer = file_get_contents(ROOT_PATH . '/themes/default/layouts/footer.php');
        $counter = file_get_contents(ROOT_PATH . '/assets/js/blox-counter.js');
        $this->assertIsString($template);
        $this->assertIsString($footer);
        $this->assertIsString($counter);

        foreach ([
            "config('home_stat_counter_enabled', '1')",
            "config('home_stat_counter_start', 0)",
            "config('home_stat_counter_duration', 0)",
            'data-blox-counter=',
            "BloxAssetCollector::addScript('/assets/js/blox-counter.js')",
            '$statCounterEnabled',
            "config('home_stat_mobile_columns', '2')",
            "config('home_stat_tablet_columns', '4')",
            "'1_2' => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 text-center'",
            "'2_2' => 'grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-8 text-center'",
            "default => 'grid grid-cols-2 md:grid-cols-4 gap-8 text-center'",
            'data-yk-home-stats-columns=',
        ] as $token) {
            $this->assertStringContainsString($token, $template);
        }
        $this->assertStringContainsString('/assets/js/scroll-anim.js', $footer);
        foreach ([
            'config.start',
            'config.duration',
            'config.start + ((target - config.start) * eased)',
            'target >= config.start ? Math.floor(value) : Math.ceil(value)',
        ] as $token) {
            $this->assertStringContainsString($token, $counter);
        }
    }}
