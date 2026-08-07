<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxRenderContext;
use HomeBloxRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBloxRendererTest extends TestCase
{
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
