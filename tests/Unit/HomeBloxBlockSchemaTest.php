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
            'override_ratio' => '2_1',
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
        $this->assertSame('2_1', $normalized['override_ratio']);
        $this->assertSame('Local title', $normalized['override_title']);
        $this->assertSame('alert(1)Body', $normalized['override_content']);
        $this->assertSame('Trusted team', $normalized['override_tag_title']);
        $this->assertSame('Quality first', $normalized['override_tag_description']);
        $this->assertSame('', $normalized['override_image']);
        $this->assertSame('mailto:sales@example.com', $normalized['override_button_url']);
    }

    public function testTitleDecorationSettingsAreNormalizedAndScopedToTheBlock(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'cta',
            'title_decor_style' => 'dot',
            'title_decor_align' => 'right',
            'title_decor_color' => '#12ABEF',
            'title_decor_width' => 999,
            'title_decor_gap' => -10,
        ]);

        $this->assertSame('dot', $normalized['title_decor_style']);
        $this->assertSame('right', $normalized['title_decor_align']);
        $this->assertSame('#12abef', $normalized['title_decor_color']);
        $this->assertSame(240, $normalized['title_decor_width']);
        $this->assertSame(0, $normalized['title_decor_gap']);

        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($normalized);
        $this->assertSame('dot', $overrides['home_title_decor_style']);
        $this->assertSame('right', $overrides['home_title_decor_align']);
        $this->assertSame('#12abef', $overrides['home_title_decor_color']);
        $this->assertSame('240', $overrides['home_title_decor_width']);
        $this->assertSame('0', $overrides['home_title_decor_gap']);

        $invalid = HomeBloxBlockSchema::normalize([
            'block_type' => 'about',
            'title_decor_style' => '<script>',
            'title_decor_align' => 'fixed',
            'title_decor_color' => 'red;position:fixed',
        ]);
        $this->assertSame('inherit', $invalid['title_decor_style']);
        $this->assertSame('inherit', $invalid['title_decor_align']);
        $this->assertSame('', $invalid['title_decor_color']);
        $this->assertArrayNotHasKey(
            'home_title_decor_style',
            HomeBloxBlockSchema::runtimeConfigOverrides(['block_type' => 'stats'])
        );
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
        $this->assertSame('#0f172a', $normalized['bg_overlay_color']);
        $this->assertSame(100, $normalized['bg_overlay_opacity']);
        $this->assertTrue($normalized['text_light']);
        $this->assertSame('container', $normalized['layout']);

        $valid = HomeBloxBlockSchema::normalize([
            'block_type' => 'cta',
            'bg_image' => 'https://example.com/cta.jpg',
            'bg_overlay_color' => '#334155',
            'bg_overlay_opacity' => 55,
            'layout' => 'full',
        ]);
        $this->assertSame('https://example.com/cta.jpg', $valid['bg_image']);
        $this->assertSame('#334155', $valid['bg_overlay_color']);
        $this->assertSame(55, $valid['bg_overlay_opacity']);
        $this->assertSame('full', $valid['layout']);

        $legacyWithoutColor = HomeBloxBlockSchema::normalize([
            'block_type' => 'cta',
            'bg_image' => '/uploads/legacy-cta.jpg',
            'bg_opacity' => 55,
        ]);
        $this->assertSame('#000000', $legacyWithoutColor['bg_overlay_color']);
        $this->assertSame(45, $legacyWithoutColor['bg_overlay_opacity']);
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
        $this->assertSame('checkbox', $controls['counter_enabled']['type']);
        $this->assertTrue($controls['counter_enabled']['default']);
        $this->assertSame(0, $controls['counter_start']['default']);
        $this->assertSame(5000, $controls['counter_duration']['max']);
        $this->assertSame('2', $controls['stats_mobile_columns']['default']);
        $this->assertSame('4', $controls['stats_tablet_columns']['default']);
        $this->assertSame('layout-columns', $controls['stats_mobile_columns']['option_icons']['2']);
        $this->assertSame('grid-dots', $controls['stats_tablet_columns']['option_icons']['4']);
        $this->assertSame('about_layout', $controls['override_layout']['type']);
        $this->assertSame('about_breakpoint', $controls['override_breakpoint']['type']);
        $this->assertSame('lg', $controls['override_breakpoint']['default']);
        $this->assertArrayNotHasKey('options', $controls['override_layout']);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_layout']['required']);
        $this->assertSame('image', $controls['bg_image']['type']);
        $this->assertSame('color', $controls['bg_color']['type']);
        $this->assertSame('color', $controls['bg_overlay_color']['type']);
        $this->assertSame('range', $controls['bg_overlay_opacity']['type']);
        $this->assertSame(55, $controls['bg_overlay_opacity']['default']);
        $this->assertSame(100, $controls['bg_overlay_opacity']['max']);
        $this->assertSame('checkbox', $controls['text_light']['type']);
        $this->assertSame(['block_type', '=', 'cta'], $controls['bg_image']['required']);
        $this->assertSame('style', $controls['bg_image']['tab']);
        $this->assertSame('style', $controls['bg_color']['tab']);
        $this->assertSame('style', $controls['bg_overlay_color']['tab']);
        $this->assertSame('style', $controls['bg_overlay_opacity']['tab']);
        $this->assertSame('style', $controls['text_light']['tab']);
        $this->assertSame('text', $controls['override_title']['type']);
        $this->assertContains('about', $controls['override_title']['required'][2]);
        $this->assertSame('select', $controls['title_decor_style']['type']);
        $this->assertSame('inherit', $controls['title_decor_style']['default']);
        $this->assertSame('minus', $controls['title_decor_style']['option_icons']['line']);
        $this->assertSame('select', $controls['title_decor_align']['type']);
        $this->assertSame('color', $controls['title_decor_color']['type']);
        $this->assertSame(240, $controls['title_decor_width']['max']);
        $this->assertSame(80, $controls['title_decor_gap']['max']);
        $this->assertContains('product_categories', $controls['title_decor_style']['required'][2]);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_content']['required']);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_tag_title']['required']);
        $this->assertSame(['block_type', '=', 'about'], $controls['override_tag_description']['required']);
        $this->assertSame(['block_type', '=', 'banner'], $controls['banner_effect']['required']);
        $this->assertSame('fade', $controls['banner_effect']['default']);
        $this->assertSame(5, $controls['banner_autoplay']['default']);
        $this->assertSame(2000, $controls['banner_speed']['max']);
        $this->assertSame(600, $controls['banner_stagger']['max']);
        $this->assertSame('checkbox', $controls['banner_navigation']['type']);
        $this->assertSame('inherit', $controls['banner_height_mode']['default']);
        $this->assertSame('maximize', $controls['banner_height_mode']['option_icons']['screen']);
        $this->assertSame('layout-navbar-expand', $controls['banner_height_mode']['option_icons']['cover-header']);
        $this->assertSame(1600, $controls['banner_height_pc']['max']);
        $this->assertSame(1200, $controls['banner_height_mobile']['max']);
        $this->assertSame(
            ['terms' => [['banner_height_mode', '=', 'fixed']]],
            $controls['banner_height_pc']['visible_when']
        );
    }

    public function testAboutLayoutRatioIsWhitelistedAndMappedToRuntimeConfig(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'about',
            'enabled' => true,
            'override_layout' => 'image_left',
            'override_ratio' => '5_7',
            'override_breakpoint' => 'md',
        ]);

        $this->assertSame('image_left', $normalized['override_layout']);
        $this->assertSame('5_7', $normalized['override_ratio']);
        $this->assertSame('md', $normalized['override_breakpoint']);
        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($normalized);
        $this->assertSame('image_left', $overrides['home_about_layout']);
        $this->assertSame('5_7', $overrides['home_about_ratio']);
        $this->assertSame('md', $overrides['home_about_breakpoint']);

        $invalid = HomeBloxBlockSchema::normalize([
            'block_type' => 'about',
            'override_ratio' => 'calc(100%)',
            'override_breakpoint' => 'xl',
        ]);
        $this->assertSame('1_1', $invalid['override_ratio']);
        $this->assertSame('lg', $invalid['override_breakpoint']);
    }

    public function testAdvantageItemsAreNormalizedAndMapped(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'advantage',
            'advantage_items' => [
                ['icon' => 'BI:Shield-Check', 'title' => '<b>Quality</b>', 'description' => '<i>Strict control</i>'],
            ],
        ]);

        $this->assertCount(4, $normalized['advantage_items']);
        $this->assertSame('bi:shield-check', $normalized['advantage_items'][0]['icon']);
        $this->assertSame('Quality', $normalized['advantage_items'][0]['title']);
        $this->assertSame('Strict control', $normalized['advantage_items'][0]['description']);

        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($normalized);
        $this->assertSame('bi:shield-check', $overrides['home_adv_1_icon']);
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

    public function testBannerMotionSettingsAreWhitelistedBoundedAndBackwardCompatible(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'banner',
            'banner_height_mode' => 'unsafe-mode',
            'banner_height_pc' => 9999,
            'banner_height_mobile' => -5,
            'banner_effect' => 'cube',
            'banner_content_motion' => 'javascript:alert(1)',
            'banner_background_motion' => 'zoom-out',
            'banner_autoplay' => 1,
            'banner_speed' => 9999,
            'banner_stagger' => -20,
            'banner_navigation' => false,
            'banner_pagination' => false,
            'banner_pause_hover' => false,
        ]);

        $this->assertSame('inherit', $normalized['banner_height_mode']);
        $this->assertSame(1600, $normalized['banner_height_pc']);
        $this->assertSame(180, $normalized['banner_height_mobile']);
        $this->assertSame('fade', $normalized['banner_effect']);
        $this->assertSame('none', $normalized['banner_content_motion']);
        $this->assertSame('zoom-out', $normalized['banner_background_motion']);

        $impactMotion = HomeBloxBlockSchema::normalize([
            'block_type' => 'banner',
            'banner_content_motion' => 'pop-in',
        ]);
        $this->assertSame('pop-in', $impactMotion['banner_content_motion']);
        $this->assertSame(2, $normalized['banner_autoplay']);
        $this->assertSame(2000, $normalized['banner_speed']);
        $this->assertSame(0, $normalized['banner_stagger']);
        $this->assertFalse($normalized['banner_navigation']);
        $this->assertFalse($normalized['banner_pagination']);
        $this->assertFalse($normalized['banner_pause_hover']);

        $coverHeader = HomeBloxBlockSchema::normalize([
            'block_type' => 'banner',
            'banner_height_mode' => 'cover-header',
        ]);
        $this->assertSame('cover-header', $coverHeader['banner_height_mode']);
        $this->assertStringContainsString(
            'data-blox-height-mode="cover-header"',
            HomeBloxBlockSchema::bannerRuntimeAttributes($coverHeader)
        );

        $legacy = HomeBloxBlockSchema::normalize(['block_type' => 'banner']);
        $this->assertSame('inherit', $legacy['banner_height_mode']);
        $this->assertSame(650, $legacy['banner_height_pc']);
        $this->assertSame(300, $legacy['banner_height_mobile']);
        $this->assertSame('fade', $legacy['banner_effect']);
        $this->assertSame(5, $legacy['banner_autoplay']);
        $this->assertSame(700, $legacy['banner_speed']);
        $this->assertTrue($legacy['banner_navigation']);
        $this->assertTrue($legacy['banner_pagination']);
        $this->assertTrue($legacy['banner_pause_hover']);

        $attributes = HomeBloxBlockSchema::bannerRuntimeAttributes($normalized);
        $this->assertStringContainsString(' data-blox-banner', $attributes);
        $this->assertStringContainsString('data-blox-effect="fade"', $attributes);
        $this->assertStringContainsString('data-blox-height-mode="inherit"', $attributes);
        $this->assertStringContainsString('data-blox-navigation="0"', $attributes);
        $this->assertStringContainsString('--blox-banner-speed:2000ms', $attributes);
        $this->assertStringContainsString('--blox-banner-height-pc:1600px', $attributes);
    }

    public function testHomeBannerBlockCollectsOnlyItsPublishedRuntimeAssets(): void
    {
        $element = new \HomeBlockElement();
        $this->assertSame(['/assets/js/blox-banner.js'], $element->scriptsFor(['block_type' => 'banner']));
        $this->assertSame(['/assets/css/blox-banner.css'], $element->stylesFor(['block_type' => 'banner']));
        $this->assertSame([], $element->scriptsFor(['block_type' => 'about']));
        $this->assertSame([], $element->stylesFor(['block_type' => 'about']));
    }

    public function testTraditionalBannerGroupMapsToSharedRuntimeWithoutChangingBloxDefaults(): void
    {
        $runtime = HomeBloxBlockSchema::bannerGroupRuntimeConfig([
            'height_pc' => 880,
            'height_mobile' => 360,
            'fullscreen' => 1,
            'autoplay_delay' => 6500,
            'effect' => 'slide',
            'speed' => 900,
            'content_motion' => 'fade-up',
            'background_motion' => 'zoom-out',
            'stagger' => 180,
            'navigation' => 0,
            'pagination' => 1,
            'pause_hover' => 0,
        ]);

        $this->assertSame('screen', $runtime['banner_height_mode']);
        $this->assertSame(880, $runtime['banner_height_pc']);
        $this->assertSame(360, $runtime['banner_height_mobile']);
        $this->assertSame(7, $runtime['banner_autoplay']);
        $this->assertSame('slide', $runtime['banner_effect']);
        $this->assertSame(900, $runtime['banner_speed']);
        $this->assertSame('fade-up', $runtime['banner_content_motion']);
        $this->assertSame('zoom-out', $runtime['banner_background_motion']);
        $this->assertSame(180, $runtime['banner_stagger']);
        $this->assertFalse($runtime['banner_navigation']);
        $this->assertTrue($runtime['banner_pagination']);
        $this->assertFalse($runtime['banner_pause_hover']);

        $coverHeader = HomeBloxBlockSchema::bannerGroupRuntimeConfig([
            'height_mode' => 'cover-header',
            'fullscreen' => 1,
        ]);
        $this->assertSame('cover-header', $coverHeader['banner_height_mode']);

        $invalidMode = HomeBloxBlockSchema::bannerGroupRuntimeConfig([
            'height_mode' => 'unsafe',
            'fullscreen' => 1,
        ]);
        $this->assertSame('screen', $invalidMode['banner_height_mode']);

        $legacy = HomeBloxBlockSchema::bannerGroupRuntimeConfig([]);
        $this->assertSame('fixed', $legacy['banner_height_mode']);
        $this->assertSame('fade', $legacy['banner_effect']);
        $this->assertSame(5, $legacy['banner_autoplay']);
        $this->assertTrue($legacy['banner_navigation']);
        $this->assertTrue($legacy['banner_pagination']);
        $this->assertTrue($legacy['banner_pause_hover']);
    }

    public function testStatsItemsAreBoundedNormalizedAndMapped(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'stats',
            'override_background' => 'https://example.com/stats.jpg',
            'counter_enabled' => false,
            'counter_start' => 1,
            'counter_duration' => 1200,
            'stats_mobile_columns' => '1',
            'stats_tablet_columns' => '2',
            'stats_items' => [
                ['icon' => 'Award', 'number' => '<b>12+</b>', 'label' => '<i>Years</i>'],
                ['icon' => 'bad icon!', 'number' => '200', 'label' => 'Clients'],
                ['icon' => 'BI:Briefcase', 'number' => '30', 'label' => 'Team'],
                ['icon' => 'none', 'number' => '99%', 'label' => 'Satisfied'],
                ['icon' => 'star', 'number' => '5', 'label' => 'Ignored'],
            ],
        ]);

        $this->assertSame('https://example.com/stats.jpg', $normalized['override_background']);
        $this->assertFalse($normalized['counter_enabled']);
        $this->assertSame(1, $normalized['counter_start']);
        $this->assertSame(1200, $normalized['counter_duration']);
        $this->assertSame('1', $normalized['stats_mobile_columns']);
        $this->assertSame('2', $normalized['stats_tablet_columns']);
        $this->assertCount(4, $normalized['stats_items']);
        $this->assertSame('award', $normalized['stats_items'][0]['icon']);
        $this->assertSame('12+', $normalized['stats_items'][0]['number']);
        $this->assertSame('Years', $normalized['stats_items'][0]['label']);
        $this->assertSame('users', $normalized['stats_items'][1]['icon']);
        $this->assertSame('bi:briefcase', $normalized['stats_items'][2]['icon']);

        $overrides = HomeBloxBlockSchema::runtimeConfigOverrides($normalized);
        $this->assertSame('https://example.com/stats.jpg', $overrides['home_stat_bg']);
        $this->assertSame('0', $overrides['home_stat_counter_enabled']);
        $this->assertSame('1', $overrides['home_stat_counter_start']);
        $this->assertSame('1200', $overrides['home_stat_counter_duration']);
        $this->assertSame('1', $overrides['home_stat_mobile_columns']);
        $this->assertSame('2', $overrides['home_stat_tablet_columns']);
        $legacyOverrides = HomeBloxBlockSchema::runtimeConfigOverrides(['block_type' => 'stats']);
        $this->assertSame('1', $legacyOverrides['home_stat_counter_enabled']);
        $this->assertSame('2', $legacyOverrides['home_stat_mobile_columns']);
        $this->assertSame('4', $legacyOverrides['home_stat_tablet_columns']);
        $this->assertSame('award', $overrides['home_stat_1_icon']);
        $this->assertSame('12+', $overrides['home_stat_1_num']);
        $this->assertSame('Years', $overrides['home_stat_1_text']);
        $this->assertSame('Years', $overrides['home_stat_1_text_' . siteLang()]);
        $this->assertSame('bi:briefcase', $overrides['home_stat_3_icon']);
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

    public function testTitleDecorationRendersCustomStylesAndKeepsThemeDefault(): void
    {
        $fixture = tempnam(sys_get_temp_dir(), 'yk-home-title-decor-');
        $this->assertNotFalse($fixture);
        file_put_contents((string) $fixture, <<<'PHP'
<?php
echo '<section><h2>Title</h2>';
if (config('home_title_decor_style', 'inherit') === 'inherit') {
    echo '<img class="theme-divider" alt="">';
} else {
    echo '<span class="section-title-dot section-title-bar-light st-left" style="'
        . 'margin-left:auto;margin-right:0'
        . ';background:' . config('home_title_decor_color', '')
        . ';width:' . config('home_title_decor_width', 0) . 'px'
        . ';height:' . config('home_title_decor_width', 0) . 'px'
        . ';margin-top:' . config('home_title_decor_gap', 0) . 'px"></span>';
}
echo '</section>';
PHP);

        try {
            $frontend = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'cta', 'enabled' => true]],
                ['cta' => (string) $fixture],
                [],
                [],
                null,
                [],
                false
            );
            $inherited = $frontend->renderLegacyBlock([
                'data' => ['block_type' => 'cta', 'enabled' => true],
            ]);
            $this->assertStringContainsString('<img class="theme-divider" alt="">', $inherited);
            $this->assertStringNotContainsString('section-title-dot', $inherited);

            $editor = HomeBloxRenderContext::fromHomePageData(
                [['type' => 'cta', 'enabled' => true]],
                ['cta' => (string) $fixture],
                [],
                [],
                null,
                [],
                true
            );
            $custom = $editor->renderLegacyBlock([
                'data' => [
                    '_blox_path' => '0.0.0',
                    'block_type' => 'cta',
                    'enabled' => true,
                    'title_decor_style' => 'dot',
                    'title_decor_align' => 'right',
                    'title_decor_color' => '#12abef',
                    'title_decor_width' => 18,
                    'title_decor_gap' => 7,
                ],
            ]);

            $this->assertStringContainsString(
                'class="section-title-dot section-title-bar-light st-left"',
                $custom
            );
            $this->assertStringContainsString(
                'style="margin-left:auto;margin-right:0;background:#12abef;width:18px;height:18px;margin-top:7px"',
                $custom
            );
            $this->assertStringContainsString('data-yk-home-field="title_decor_style"', $custom);
            $this->assertStringContainsString('data-yk-home-path="0.0.0"', $custom);
        } finally {
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


    public function testEditorBlueprintsExposeOnlyWhitelistedNestedFieldPaths(): void
    {
        $blueprints = HomeBloxBlockSchema::editorBlueprints();

        $this->assertArrayHasKey('about', $blueprints);
        $this->assertArrayHasKey('stats', $blueprints);
        $this->assertArrayHasKey('advantage', $blueprints);
        $this->assertArrayHasKey('cta', $blueprints);
        $this->assertSame('columns', $blueprints['about']['projection']['type']);
        $this->assertSame('override_ratio', $blueprints['about']['projection']['ratio_key']);
        $this->assertSame(['text' => 5, 'image' => 7], $blueprints['about']['projection']['ratios']['5_7']);
        $statsIconField = $blueprints['stats']['groups'][0]['fields'][0];
        $this->assertSame('icon', $statsIconField['control']);
        $this->assertGreaterThanOrEqual(40, count($statsIconField['options']));
        $this->assertContains('user-star', $statsIconField['options']);
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('stats', 'stats_items.0.number'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('advantage', 'advantage_items.3.description'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('cta', 'override_button_url'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('cta', 'bg_image'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('cta', 'bg_opacity'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('cta', 'bg_overlay_color'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('cta', 'bg_overlay_opacity'));
        $this->assertSame('background', $blueprints['cta']['groups'][1]['key']);
        $this->assertFalse($blueprints['cta']['groups'][1]['tree']);
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('about', 'title_decor_style'));
        $this->assertTrue(HomeBloxBlockSchema::isEditableFieldPath('testimonials', 'title_decor_style'));
        $this->assertFalse(HomeBloxBlockSchema::isEditableFieldPath('stats', 'stats_items.4.number'));
        $this->assertFalse(HomeBloxBlockSchema::isEditableFieldPath('stats', 'stats_items.0.__proto__'));
        $this->assertFalse(HomeBloxBlockSchema::isEditableFieldPath('unknown', 'override_title'));
        $this->assertCount(4, HomeBloxBlockSchema::statsSeedItems());
        $this->assertCount(4, HomeBloxBlockSchema::advantageSeedItems());
    }

    public function testCustomColumnContractExposesEditablePricingFieldsByLocale(): void
    {
        $blocks = [[
            'columns' => [[
                'card_bg' => '#fffbeb',
                'elements' => [
                    ['type' => 'heading', 'data' => ['text' => 'Professional']],
                    ['type' => 'text', 'data' => ['html' => '<p>$299 / month</p>']],
                    ['type' => 'button', 'data' => ['text' => 'Choose', 'url' => '/contact.html']],
                ],
            ]],
        ]];

        $contract = HomeBloxBlockSchema::customEditorContract('custom:1', $blocks, 'ja');

        $this->assertCount(1, $contract['groups']);
        $this->assertSame('Professional', $contract['groups'][0]['label']);
        $fields = $contract['groups'][0]['fields'];
        $this->assertSame('color', $fields[0]['control']);
        $this->assertSame('richtext', $fields[2]['control']);
        $this->assertSame(
            'custom_overrides.ja.0.columns.0.elements.2.data.url',
            $fields[4]['key']
        );
        $this->assertSame(
            '$299 / month',
            strip_tags($contract['seeds']['custom_overrides']['ja'][0]['columns'][0]['elements'][1]['data']['html'])
        );
    }

    public function testCustomAccordionContractExposesEachQuestionAndAnswer(): void
    {
        $blocks = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'accordion',
                    'data' => ['items' => "How do I buy?|Contact our team.\nIs support included?|Yes, for one year."],
                ]],
            ]],
        ]];

        $contract = HomeBloxBlockSchema::customEditorContract('custom:2', $blocks, 'en');

        $this->assertCount(0, $contract['groups']);
        $this->assertCount(1, $contract['repeaters']);
        $repeater = $contract['repeaters'][0];
        $this->assertSame('help-circle', $repeater['icon']);
        $this->assertSame('text', $repeater['fields'][0]['control']);
        $this->assertSame('textarea', $repeater['fields'][1]['control']);
        $this->assertSame(
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items',
            $repeater['items_key']
        );
        $this->assertSame(
            'Yes, for one year.',
            $contract['seeds']['custom_overrides']['en'][0]['columns'][0]['elements'][0]
                ['data']['accordion_items'][1]['answer']
        );
        $this->assertTrue(HomeBloxBlockSchema::isCustomEditableFieldPath(
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items.1.question'
        ));
        $this->assertFalse(HomeBloxBlockSchema::isCustomEditableFieldPath(
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items.1.open_first'
        ));
    }

    public function testPricingColumnsCanOptIntoSanitizedStructuralOverrides(): void
    {
        $blocks = [[
            'columns' => [[
                'elements' => [
                    ['type' => 'heading', 'data' => ['text' => 'Basic', 'level' => 'h3']],
                    ['type' => 'button', 'data' => ['text' => 'Choose', 'url' => '/contact.html']],
                ],
            ], [
                'elements' => [
                    ['type' => 'heading', 'data' => ['text' => 'Pro', 'level' => 'h3']],
                    ['type' => 'text', 'data' => ['html' => '<p>$299</p>']],
                ],
            ]],
        ]];
        $contract = HomeBloxBlockSchema::customEditorContract('custom:1', $blocks, 'en');

        $this->assertCount(1, $contract['column_repeaters']);
        $this->assertSame(
            'custom_overrides.en.0.columns',
            $contract['column_repeaters'][0]['items_key']
        );
        $this->assertSame('custom-columns-0', $contract['groups'][0]['columnRepeaterKey']);
        $this->assertSame(
            'heading',
            $contract['seeds']['custom_overrides']['en'][0]['columns'][0]['elements'][0]['type']
        );

        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'custom:1',
            'custom_overrides' => ['en' => [0 => [
                'columns_mode' => 'custom',
                'columns' => [[
                    'card_bg' => '#FFFBEB',
                    'elements' => [[
                        'type' => 'heading',
                        'data' => ['text' => '<b>Enterprise</b>', 'level' => 'bad', 'align' => 'center'],
                    ], [
                        'type' => 'button',
                        'data' => ['text' => 'Contact', 'url' => 'javascript:bad()', 'new_tab' => true],
                    ], [
                        'type' => 'code',
                        'data' => ['code' => '<script>bad()</script>'],
                    ]],
                ]],
            ]]],
        ]);
        $result = HomeBloxBlockSchema::applyCustomOverrides($blocks, $normalized, 'en');

        $this->assertCount(1, $result[0]['columns']);
        $this->assertSame('#fffbeb', $result[0]['columns'][0]['card_bg']);
        $this->assertSame('Enterprise', $result[0]['columns'][0]['elements'][0]['data']['text']);
        $this->assertSame('h3', $result[0]['columns'][0]['elements'][0]['data']['level']);
        $this->assertSame('', $result[0]['columns'][0]['elements'][1]['data']['url']);
        $this->assertCount(2, $result[0]['columns'][0]['elements']);
    }

    public function testCustomAccordionOverridesStaySparseAndWriteStructuredItems(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'custom:2',
            'custom_overrides' => [
                'zh_CN' => [0 => ['columns' => [0 => ['elements' => [0 => ['data' => [
                    'accordion_items' => [
                        0 => ['question' => '<b>怎样购买|产品？</b>'],
                        1 => ['answer' => "<i>包含售后</i>\n与技术支持|服务"],
                        8 => ['question' => '不存在的条目'],
                    ],
                ]]]]]]],
            ],
        ]);

        $items = $normalized['custom_overrides']['zh_CN'][0]['columns'][0]['elements'][0]
            ['data']['accordion_items'];
        $this->assertSame('怎样购买|产品？', $items[0]['question']);
        $this->assertSame("包含售后\n与技术支持|服务", $items[1]['answer']);

        $source = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'accordion',
                    'data' => ['items' => "如何购买？|联系销售。\n提供售后吗？|提供一年质保。"],
                ]],
            ]],
        ]];
        $result = HomeBloxBlockSchema::applyCustomOverrides($source, $normalized, 'zh-CN');

        $this->assertSame([
            ['question' => '怎样购买|产品？', 'answer' => '联系销售。'],
            ['question' => '提供售后吗？', 'answer' => "包含售后\n与技术支持|服务"],
        ], $result[0]['columns'][0]['elements'][0]['data']['items']);
    }

    public function testCustomAccordionStructuralOverrideCanAddDeleteAndClearItems(): void
    {
        $source = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'accordion',
                    'data' => ['items' => "Question 1|Answer 1\nQuestion 2|Answer 2"],
                ]],
            ]],
        ]];
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'custom:2',
            'custom_overrides' => [
                'en' => [0 => ['columns' => [0 => ['elements' => [0 => ['data' => [
                    'accordion_mode' => 'custom',
                    'accordion_items' => [
                        ['question' => 'Added question', 'answer' => 'Added answer'],
                    ],
                ]]]]]]],
            ],
        ]);

        $result = HomeBloxBlockSchema::applyCustomOverrides($source, $normalized, 'en');
        $this->assertSame([
            ['question' => 'Added question', 'answer' => 'Added answer'],
        ], $result[0]['columns'][0]['elements'][0]['data']['items']);
        $this->assertSame(
            'custom',
            $normalized['custom_overrides']['en'][0]['columns'][0]['elements'][0]['data']['accordion_mode']
        );

        $empty = HomeBloxBlockSchema::normalize([
            'block_type' => 'custom:2',
            'custom_overrides' => [
                'en' => [0 => ['columns' => [0 => ['elements' => [0 => ['data' => [
                    'accordion_mode' => 'custom',
                    'accordion_items' => [],
                ]]]]]]],
            ],
        ]);
        $cleared = HomeBloxBlockSchema::applyCustomOverrides($source, $empty, 'en');
        $this->assertSame([], $cleared[0]['columns'][0]['elements'][0]['data']['items']);
    }

    public function testCustomOverridesAreSparseSanitizedAndLanguageScoped(): void
    {
        $normalized = HomeBloxBlockSchema::normalize([
            'block_type' => 'custom:1',
            'custom_overrides' => [
                'zh_CN' => [0 => ['columns' => [1 => [
                    'card_bg' => '#FFFBEB',
                    'elements' => [
                        0 => ['data' => ['text' => '<b>专业版</b>']],
                        1 => ['data' => ['html' => '<p onclick="bad()">¥299</p><script>bad()</script>']],
                        2 => ['data' => ['url' => 'javascript:bad()']],
                    ],
                ]]]],
                'ja' => [0 => ['columns' => [1 => [
                    'elements' => [0 => ['data' => ['text' => 'プロ']]],
                ]]]],
            ],
        ]);

        $overrides = $normalized['custom_overrides'];
        $this->assertSame('#fffbeb', $overrides['zh_CN'][0]['columns'][1]['card_bg']);
        $this->assertSame('专业版', $overrides['zh_CN'][0]['columns'][1]['elements'][0]['data']['text']);
        $this->assertSame('<p>¥299</p>', $overrides['zh_CN'][0]['columns'][1]['elements'][1]['data']['html']);
        $this->assertSame('', $overrides['zh_CN'][0]['columns'][1]['elements'][2]['data']['url']);
        $this->assertSame('プロ', $overrides['ja'][0]['columns'][1]['elements'][0]['data']['text']);

        $source = [[
            'columns' => [[
                'elements' => [['type' => 'heading', 'data' => ['text' => 'Base']]],
            ], [
                'elements' => [['type' => 'heading', 'data' => ['text' => 'Original']]],
            ]],
        ]];
        $zh = HomeBloxBlockSchema::applyCustomOverrides($source, $normalized, 'zh-CN');
        $ja = HomeBloxBlockSchema::applyCustomOverrides($source, $normalized, 'ja');
        $this->assertSame('Base', $zh[0]['columns'][0]['elements'][0]['data']['text']);
        $this->assertSame('专业版', $zh[0]['columns'][1]['elements'][0]['data']['text']);
        $this->assertSame('プロ', $ja[0]['columns'][1]['elements'][0]['data']['text']);
    }

}
