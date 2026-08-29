<?php
/** 页面标题区样式来源解析。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PageHeroStyleResolver;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/PageHeroStyleResolver.php';

final class PageHeroStyleResolverTest extends TestCase
{
    public function testSelfModePreservesLegacyFallbackOrder(): void
    {
        $custom = PageHeroStyleResolver::resolve(['hero_bg' => '/hero.jpg', 'image' => '/cover.jpg'], false, null, '/global.jpg');
        $cover = PageHeroStyleResolver::resolve(['hero_bg' => '', 'image' => '/cover.jpg'], false, null, '/global.jpg');
        $global = PageHeroStyleResolver::resolve(['hero_bg' => '', 'image' => ''], false, null, '/global.jpg');

        self::assertSame(['custom', '/hero.jpg'], [$custom['source'], $custom['background']]);
        self::assertSame(['cover', '/cover.jpg'], [$cover['source'], $cover['background']]);
        self::assertSame(['global', '/global.jpg'], [$global['source'], $global['background']]);
        self::assertSame([
            'background_color' => '',
            'overlay_opacity' => 60,
            'height' => 'standard',
            'mobile_height' => 'inherit',
            'focal_x' => 50,
            'focal_y' => 50,
            'alignment' => 'center',
            'text_tone' => 'auto',
        ], $custom['options']);
    }

    public function testParentModeUsesNearestAncestorWithAnExplicitBackground(): void
    {
        $rows = [
            10 => ['id' => 10, 'parent_id' => 5, 'name' => 'Middle', 'lang' => 'zh-CN', 'hero_style_source' => 'parent', 'hero_bg' => '/stale.jpg', 'image' => ''],
            5 => ['id' => 5, 'parent_id' => 0, 'name' => 'About', 'lang' => 'zh-CN', 'hero_bg' => '/about.jpg', 'image' => ''],
        ];
        $loader = static fn(int $id): ?array => $rows[$id] ?? null;
        $resolved = PageHeroStyleResolver::resolve([
            'id' => 20,
            'parent_id' => 10,
            'lang' => 'zh-CN',
            'hero_style_source' => 'parent',
            'hero_bg' => '/ignored.jpg',
        ], false, $loader, '/global.jpg');

        self::assertSame('parent', $resolved['source']);
        self::assertSame('/about.jpg', $resolved['background']);
        self::assertSame(5, $resolved['source_channel_id']);
        self::assertSame('About', $resolved['source_channel_name']);
        self::assertSame(['Middle', 'About'], $resolved['inheritance_path']);
    }

    public function testParentModeInheritsLayoutOptionsEvenWithoutAPageSpecificBackground(): void
    {
        $resolved = PageHeroStyleResolver::resolve([
            'id' => 20,
            'parent_id' => 10,
            'lang' => 'zh-CN',
            'hero_style_source' => 'parent',
        ], false, static fn(int $id): ?array => $id === 10 ? [
            'id' => 10,
            'parent_id' => 0,
            'name' => 'Services',
            'lang' => 'zh-CN',
            'hero_style_source' => 'self',
            'hero_bg' => '',
            'image' => '',
            'hero_style_options' => '{"background_color":"#f8fafc","overlay_opacity":0,"height":"compact","alignment":"left","text_tone":"dark"}',
        ] : null, '/global.jpg', '');

        self::assertSame('parent', $resolved['source']);
        self::assertSame('/global.jpg', $resolved['background']);
        self::assertSame('compact', $resolved['options']['height']);
        self::assertSame('#f8fafc', $resolved['options']['background_color']);
    }

    public function testGlobalModeIgnoresPageAndCoverBackgrounds(): void
    {
        $resolved = PageHeroStyleResolver::resolve([
            'parent_id' => 2,
            'hero_style_source' => 'global',
            'hero_bg' => '/page.jpg',
            'image' => '/cover.jpg',
        ], false, null, '/global.jpg');

        self::assertSame('global', $resolved['source']);
        self::assertSame('/global.jpg', $resolved['background']);
        self::assertTrue($resolved['can_inherit']);
    }

    public function testParentCyclesAndCrossLanguageParentsFailClosedToGlobal(): void
    {
        $cycleRows = [
            2 => ['id' => 2, 'parent_id' => 3, 'lang' => 'zh-CN'],
            3 => ['id' => 3, 'parent_id' => 2, 'lang' => 'zh-CN'],
        ];
        $cycle = PageHeroStyleResolver::resolve(
            ['id' => 1, 'parent_id' => 2, 'lang' => 'zh-CN', 'hero_style_source' => 'parent'],
            false,
            static fn(int $id): ?array => $cycleRows[$id] ?? null,
            '/global.jpg'
        );
        $crossLanguage = PageHeroStyleResolver::resolve(
            ['id' => 1, 'parent_id' => 2, 'lang' => 'zh-CN', 'hero_style_source' => 'parent'],
            false,
            static fn(int $id): ?array => ['id' => $id, 'parent_id' => 0, 'lang' => 'en', 'hero_bg' => '/wrong.jpg'],
            '/global.jpg'
        );

        self::assertSame(['global', '/global.jpg'], [$cycle['source'], $cycle['background']]);
        self::assertSame(['About'], PageHeroStyleResolver::resolve(
            ['id' => 20, 'parent_id' => 5, 'lang' => 'zh-CN', 'hero_style_source' => 'parent'],
            false,
            static fn(int $id): ?array => $id === 5 ? ['id' => 5, 'parent_id' => 0, 'name' => 'About', 'lang' => 'zh-CN', 'hero_style_source' => 'global'] : null,
            '/global.jpg'
        )['inheritance_path']);
        self::assertSame(['global', '/global.jpg'], [$crossLanguage['source'], $crossLanguage['background']]);
    }

    public function testCompactContactOnlyChangesWhenSharingIsExplicitlySelected(): void
    {
        $self = PageHeroStyleResolver::resolve(['hero_style_source' => 'self', 'image' => '/cover.jpg'], true, null, '/global.jpg');
        $global = PageHeroStyleResolver::resolve(['hero_style_source' => 'global', 'image' => '/cover.jpg'], true, null, '/global.jpg');

        self::assertSame(['builtin', ''], [$self['source'], $self['background']]);
        self::assertSame(['global', '/global.jpg'], [$global['source'], $global['background']]);
        self::assertSame('compact', $self['options']['height']);
        self::assertSame('left', $self['options']['alignment']);
        self::assertSame('auto', $self['options']['text_tone']);
    }

    public function testOptionsAreWhitelistedAndEncodedCanonically(): void
    {
        $normalized = PageHeroStyleResolver::normalizeOptions([
            'background_color' => 'red;display:none',
            'overlay_opacity' => 999,
            'height' => 'fullscreen',
            'mobile_height' => 'cinema',
            'focal_x' => -20,
            'focal_y' => 140,
            'alignment' => 'right',
            'text_tone' => 'invisible',
        ]);
        $token = PageHeroStyleResolver::normalizeOptions([
            'background_color' => 'var(--yk-color-primary-500)',
            'overlay_opacity' => -5,
            'height' => 'large',
            'alignment' => 'left',
            'text_tone' => 'light',
        ]);

        self::assertSame('', $normalized['background_color']);
        self::assertSame(90, $normalized['overlay_opacity']);
        self::assertSame('standard', $normalized['height']);
        self::assertSame('inherit', $normalized['mobile_height']);
        self::assertSame(0, $normalized['focal_x']);
        self::assertSame(100, $normalized['focal_y']);
        self::assertSame('center', $normalized['alignment']);
        self::assertSame('auto', $normalized['text_tone']);
        self::assertSame('var(--yk-color-primary-500)', $token['background_color']);
        self::assertSame(0, $token['overlay_opacity']);
        self::assertSame('', PageHeroStyleResolver::encodeOptions(PageHeroStyleResolver::defaultOptions()));
        self::assertStringContainsString('"height":"large"', PageHeroStyleResolver::encodeOptions($token));
        self::assertSame('py-20 md:py-24', PageHeroStyleResolver::heightClasses(['height' => 'large']));
        self::assertSame('py-10 md:py-24', PageHeroStyleResolver::heightClasses(['height' => 'large', 'mobile_height' => 'compact']));
        self::assertSame('25% 80%', PageHeroStyleResolver::backgroundPosition(['focal_x' => 25, 'focal_y' => 80]));
    }

    public function testGlobalModeUsesGlobalLayoutOptions(): void
    {
        $resolved = PageHeroStyleResolver::resolve([
            'hero_style_source' => 'global',
        ], false, null, '/global.jpg', [
            'background_color' => '#0f172a',
            'overlay_opacity' => 35,
            'height' => 'large',
            'alignment' => 'left',
            'text_tone' => 'light',
        ]);

        self::assertSame('large', $resolved['options']['height']);
        self::assertSame(35, $resolved['options']['overlay_opacity']);
    }
}
