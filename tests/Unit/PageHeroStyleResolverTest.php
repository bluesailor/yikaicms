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
        self::assertSame(['global', '/global.jpg'], [$crossLanguage['source'], $crossLanguage['background']]);
    }

    public function testCompactContactOnlyChangesWhenSharingIsExplicitlySelected(): void
    {
        $self = PageHeroStyleResolver::resolve(['hero_style_source' => 'self', 'image' => '/cover.jpg'], true, null, '/global.jpg');
        $global = PageHeroStyleResolver::resolve(['hero_style_source' => 'global', 'image' => '/cover.jpg'], true, null, '/global.jpg');

        self::assertSame(['builtin', ''], [$self['source'], $self['background']]);
        self::assertSame(['global', '/global.jpg'], [$global['source'], $global['background']]);
    }
}
