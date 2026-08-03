<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AbstractElement;
use HomeBloxBlockSchema;
use HomeBloxRenderContext;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBloxBlockSchemaTest extends TestCase
{
    public function testNormalizeClampsQueryFieldsAndSanitizesEmptyText(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => ' channel:12 ',
            'enabled' => 1,
            'limit' => 999,
            'per_row' => -3,
            'sort' => 'DROP TABLE',
            'empty_state' => 'message',
            'empty_text' => '<strong>No items</strong>',
            'override_layout' => 'image_left',
            'override_title' => '<b>Local title</b>',
            'override_content' => '<script>alert(1)</script>Body',
            'override_tag_title' => '<b>Trusted team</b>',
            'override_tag_description' => '<i>Quality first</i>',
            'override_image' => 'javascript:alert(1)',
            'override_button_url' => 'mailto:sales@example.com',
        ]);

        $this->assertSame('channel:12', $normalized['block_type']);
        $this->assertTrue($normalized['enabled']);
        $this->assertSame(HomeBloxBlockSchema::MAX_ITEMS, $normalized['limit']);
        $this->assertSame(0, $normalized['per_row']);
        $this->assertSame('inherit', $normalized['sort']);
        $this->assertSame('message', $normalized['empty_state']);
        $this->assertSame('No items', $normalized['empty_text']);
        $this->assertSame('image_left', $normalized['override_layout']);
        $this->assertSame('Local title', $normalized['override_title']);
        $this->assertSame('alert(1)Body', $normalized['override_content']);
        $this->assertSame('Trusted team', $normalized['override_tag_title']);
        $this->assertSame('Quality first', $normalized['override_tag_description']);
        $this->assertSame('', $normalized['override_image']);
        $this->assertSame('mailto:sales@example.com', $normalized['override_button_url']);
    }

    public function testCtaBackgroundSettingsAreNormalized(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'cta',
            'enabled' => true,
            'bg_image' => 'javascript:alert(1)',
            'bg_color' => '<b>#0f172a</b>',
            'bg_opacity' => 999,
            'text_light' => 1,
            'layout' => 'invalid',
        ]);

        $this->assertSame('', $normalized['bg_image']);
        $this->assertSame('#0f172a', $normalized['bg_color']);
        $this->assertSame(100, $normalized['bg_opacity']);
        $this->assertTrue($normalized['text_light']);
        $this->assertSame('container', $normalized['layout']);

        $valid = HomeBloxBlockSchema::normalize([
            'block_type' => 'cta',
            'bg_image' => 'https://example.com/cta.jpg',
            'bg_opacity' => 55,
            'layout' => 'full',
        ]);
        $this->assertSame('https://example.com/cta.jpg', $valid['bg_image']);
        $this->assertSame(55, $valid['bg_opacity']);
        $this->assertSame('full', $valid['layout']);
    }
    public function testControlsExposeConditionalQueryAndEmptyStateFields(): void
    {
        $controls = [];
        foreach (HomeBloxBlockSchema::controls() as $control) {
            $controls[(string) $control['key']] = $control;
        }

        $this->assertSame('select', $controls['block_type']['type']);
        $this->assertArrayHasKey('banner', $controls['block_type']['options']);
        $this->assertContains('banner', $controls['limit']['required'][2]);
        $this->assertSame(['empty_state', '=', 'message'], $controls['empty_text']['required']);
        $this->assertSame(0, $controls['limit']['default']);
        $this->assertSame(0, $controls['per_row']['default']);
        $this->assertSame('select', $controls['override_layout']['type']);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_layout']['required']);
        $this->assertSame('text', $controls['override_title']['type']);
        $this->assertContains('about', $controls['override_title']['required'][2]);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_content']['required']);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_tag_title']['required']);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_tag_description']['required']);
    }

    public function testAdvantageItemsAreNormalizedAndMapped(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'advantage',
            'advantage_items' => [
                ['icon' => 'Shield-Check', 'title' => '<b>Quality</b>', 'description' => '<i>Strict control</i>'],
            ],
        ]);

        $this->assertCount(4, $normalized['advantage_items']);
        $this->assertSame('shield-check', $normalized['advantage_items'][0]['icon']);
        $this->assertSame('Quality', $normalized['advantage_items'][0]['title']);
        $this->assertSame('Strict control', $normalized['advantage_items'][0]['description']);

        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($normalized);
        $this->assertSame('shield-check', $overrides['home_adv_1_icon']);
        $this->assertSame('Quality', $overrides['home_adv_1_title']);
        $this->assertSame('Quality', $overrides['home_adv_1_title_' . siteLang()]);
        $this->assertSame('Strict control', $overrides['home_adv_1_desc_' . siteLang()]);
    }

    public function testProductCarouselSettingsAreBoundedAndDeduplicated(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'product_carousel',
            'title' => '<b>Featured</b>',
            'per_row' => 99,
            'autoplay' => 1,
            'product_ids' => [3, '2', 3, 0, -1, 'bad'],
        ]);

        $this->assertSame('Featured', $normalized['title']);
        $this->assertSame(6, $normalized['per_row']);
        $this->assertSame(2, $normalized['autoplay']);
        $this->assertSame([3, 2], $normalized['product_ids']);
    }

    public function testStatsItemsAreBoundedNormalizedAndMapped(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'stats',
            'override_background' => 'https://example.com/stats.jpg',
            'stats_items' => [
                ['icon' => 'Award', 'number' => '<b>12+</b>', 'label' => '<i>Years</i>'],
                ['icon' => 'bad icon!', 'number' => '200', 'label' => 'Clients'],
                ['icon' => 'briefcase', 'number' => '30', 'label' => 'Team'],
                ['icon' => 'none', 'number' => '99%', 'label' => 'Satisfied'],
                ['icon' => 'star', 'number' => '5', 'label' => 'Ignored'],
            ],
        ]);

        $this->assertSame('https://example.com/stats.jpg', $normalized['override_background']);
        $this->assertCount(4, $normalized['stats_items']);
        $this->assertSame('award', $normalized['stats_items'][0]['icon']);
        $this->assertSame('12+', $normalized['stats_items'][0]['number']);
        $this->assertSame('Years', $normalized['stats_items'][0]['label']);
        $this->assertSame('users', $normalized['stats_items'][1]['icon']);

        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($normalized);
        $this->assertSame('https://example.com/stats.jpg', $overrides['home_stat_bg']);
        $this->assertSame('award', $overrides['home_stat_1_icon']);
        $this->assertSame('12+', $overrides['home_stat_1_num']);
        $this->assertSame('Years', $overrides['home_stat_1_text']);
        $this->assertSame('Years', $overrides['home_stat_1_text_' . siteLang()]);
    }

    public function testTestimonialItemsAreBoundedSanitizedAndRenderedLocally(): void
    {
        $items = array_fill(0, 14, [
            'avatar' => 'javascript:alert(1)',
            'name' => '<b>Customer</b>',
            'company' => '<i>Example Co.</i>',
            'content' => '<script>alert(1)</script>Reliable service',
        ]);
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'testimonials',
            'testimonial_items' => $items,
        ]);

        $this->assertCount(12, $normalized['testimonial_items']);
        $this->assertSame('', $normalized['testimonial_items'][0]['avatar']);
        $this->assertSame('Customer', $normalized['testimonial_items'][0]['name']);
        $this->assertSame('Example Co.', $normalized['testimonial_items'][0]['company']);
        $this->assertSame('alert(1)Reliable service', $normalized['testimonial_items'][0]['content']);

        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-testimonials-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, "<?php echo count(\$testimonials) . ':' . \$testimonials[0]['name'];");

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'testimonials', 'enabled' => true]],
                ['testimonials' => (string) $fixture],
                [],
                [],
                null,
                [['name' => 'Global customer']],
                false
            );
            $html = $context->renderLegacyBlock([
                'data' => [
                    'block_type' => 'testimonials',
                    'enabled' => true,
                    'testimonial_items' => [['name' => 'Draft customer']],
                ],
            ]);

            $this->assertSame('1:Draft customer', $html);
        } finally {
            @unlink((string) $fixture);
        }
    }
    public function testAboutImageBadgeOverridesIncludeCurrentLanguage(): void
    {
        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides([
            'block_type' => 'about',
            'override_tag_title' => 'Trusted team',
            'override_tag_description' => 'Quality first',
        ]);

        $this->assertSame('Trusted team', $overrides['home_about_tag_title']);
        $this->assertSame('Quality first', $overrides['home_about_tag_desc']);
        $this->assertSame('Trusted team', $overrides['home_about_tag_title_' . siteLang()]);
        $this->assertSame('Quality first', $overrides['home_about_tag_desc_' . siteLang()]);
    }

    public function testScopedContentOverridesReachLegacyTemplateAndAreRestored(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-content-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, <<<'PHP'
<?php echo implode('|', [
    config('home_cta_title', ''),
    config('home_cta_button', ''),
    config('home_cta_link', ''),
]);
PHP);
        $runtimeKey = 'yikai_config_runtime_overrides';
        $hadPrevious = array_key_exists($runtimeKey, $GLOBALS);
        $previous = $GLOBALS[$runtimeKey] ?? null;
        $GLOBALS[$runtimeKey] = ['home_cta_title' => 'Outer title'];

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'cta', 'enabled' => true]],
                ['cta' => (string) $fixture],
                [],
                [],
                null,
                [],
                false
            );
            $html = $context->renderLegacyBlock([
                'data' => [
                    'block_type' => 'cta',
                    'enabled' => true,
                    'override_title' => 'Local CTA',
                    'override_button_text' => 'Start now',
                    'override_button_url' => '/start.html',
                ],
            ]);

            $this->assertSame('Local CTA|Start now|/start.html', $html);
            $this->assertSame(['home_cta_title' => 'Outer title'], $GLOBALS[$runtimeKey]);
        } finally {
            if ($hadPrevious) {
                $GLOBALS[$runtimeKey] = $previous;
            } else {
                unset($GLOBALS[$runtimeKey]);
            }
            @unlink((string) $fixture);
        }
    }

    public function testChannelContentOverridesStayLocalToTheRenderedBlock(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-channel-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, <<<'PHP'
<?php echo implode('|', [$currentChannel['name'], $currentChannel['description'], $currentChannel['home_button_text'], $currentChannel['home_button_url']]);
PHP);

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'channel:9', 'enabled' => true]],
                ['channel' => (string) $fixture],
                [9 => [
                    'id' => 9,
                    'status' => 1,
                    'name' => 'Original',
                    'description' => 'Original description',
                    'type' => 'article',
                    'contents' => [['id' => 1]],
                    'per_row' => 4,
                ]],
                [],
                null,
                [],
                false
            );
            $html = $context->renderLegacyBlock([
                'data' => [
                    'block_type' => 'channel:9',
                    'enabled' => true,
                    'override_title' => 'Newsroom',
                    'override_description' => 'Latest company news',
                    'override_button_text' => 'All news',
                    'override_button_url' => '/news.html',
                ],
            ]);

            $this->assertSame('Newsroom|Latest company news|All news|/news.html', $html);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testBannerLimitIsAppliedBeforeTheSharedTemplateRuns(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-schema-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, "<?php echo count(\$banners) . ':' . (int) \$block['limit'];");

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'banner', 'enabled' => true]],
                ['banner' => (string) $fixture],
                [],
                [['id' => 1], ['id' => 2], ['id' => 3]],
                null,
                [],
                false
            );

            $html = $context->renderLegacyBlock([
                'data' => [
                    'block_type' => 'banner',
                    'enabled' => true,
                    'limit' => 2,
                    'empty_state' => 'hide',
                ],
            ]);

            $this->assertSame('2:2', $html);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testConfiguredEmptyStateRemainsSelectableInEditMode(): void
    {
        $context = HomeBloxRenderContext::fromHomePageData(
            [['type' => 'banner', 'enabled' => true]],
            [],
            [],
            [],
            null,
            [],
            true
        );

        $html = $context->renderLegacyBlock([
            'data' => [
                'block_type' => 'banner',
                'enabled' => true,
                'empty_state' => 'message',
                'empty_text' => '<strong>No items</strong>',
            ],
        ]);

        $this->assertStringContainsString('data-yk-home="banner"', $html);
        $this->assertStringContainsString('No items', $html);
        $this->assertStringNotContainsString('<strong>', $html);
    }
    public function testLegacyBannerWithoutQueryFieldsKeepsAllLoadedSlides(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-legacy-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, "<?php echo count(\$banners);");

        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'banner', 'enabled' => true]],
                ['banner' => (string) $fixture],
                [],
                [['id' => 1], ['id' => 2], ['id' => 3]],
                null,
                [],
                false
            );

            $html = $context->renderLegacyBlock([
                'data' => ['block_type' => 'banner', 'enabled' => true],
            ]);

            $this->assertSame('3', $html);
        } finally {
            @unlink((string) $fixture);
        }
    }

    public function testHiddenEmptySourceHasAnEditorPlaceholderButNoFrontendOutput(): void
    {
        $element = [
            'data' => [
                'block_type' => 'banner',
                'enabled' => true,
                'empty_state' => 'hide',
            ],
        ];
        $frontend = HomeBloxRenderContext::fromHomePageData(
            [['type' => 'banner', 'enabled' => true]],
            [],
            [],
            [],
            null,
            [],
            false
        );
        $editor = HomeBloxRenderContext::fromHomePageData(
            [['type' => 'banner', 'enabled' => true]],
            [],
            [],
            [],
            null,
            [],
            true
        );

        $this->assertSame('', $frontend->renderLegacyBlock($element));
        $editorHtml = $editor->renderLegacyBlock($element);
        $this->assertStringContainsString('data-yk-home="banner"', $editorHtml);
        $this->assertStringContainsString(__('blox_home_empty_hidden_preview'), $editorHtml);
    }
    public function testResponsiveGridClassesSupportAllConfiguredColumnCounts(): void
    {
        $this->assertSame('grid-cols-2 md:grid-cols-3', AbstractElement::gridClasses(0, 3));
        $this->assertSame('grid-cols-1 sm:grid-cols-2', AbstractElement::gridClasses(2));
        $this->assertSame('grid-cols-2 md:grid-cols-4 lg:grid-cols-8', AbstractElement::gridClasses(99));
    }

}
