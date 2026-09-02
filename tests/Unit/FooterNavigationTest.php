<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/FooterNavigation.php';

final class FooterNavigationTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT DEFAULT "",
                slug TEXT DEFAULT "",
                type TEXT DEFAULT "page",
                status INTEGER DEFAULT 1,
                lang TEXT DEFAULT "zh-CN",
                translation_group_id INTEGER DEFAULT 0
            )',
        ];
    }

    public function testDisabledInternalChannelLinksAreHiddenWithoutMutatingFooterNav(): void
    {
        $this->insertRow('channels', [
            'id' => 16,
            'name' => '隐私政策',
            'slug' => 'privacy',
            'status' => 0,
            'translation_group_id' => 16,
        ]);
        $this->insertRow('channels', [
            'id' => 17,
            'name' => '服务条款',
            'slug' => 'terms',
            'status' => 1,
            'translation_group_id' => 17,
        ]);

        $raw = json_encode([[
            'title' => '',
            'links' => [
                ['name' => '隐私政策', 'url' => '/privacy.html'],
                ['name' => '服务条款', 'url' => '/terms.html'],
                ['name' => '外部链接', 'url' => 'https://example.com/legal'],
            ],
        ]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $groups = footerNavigationGroups($raw);

        self::assertCount(1, $groups);
        self::assertSame(['服务条款', '外部链接'], array_column($groups[0]['links'], 'name'));
    }

    public function testNestedChannelUrlsUseParentSlugWhenMatchingFooterLinks(): void
    {
        $this->insertRow('channels', ['id' => 10, 'name' => '关于我们', 'slug' => 'about', 'status' => 1]);
        $this->insertRow('channels', [
            'id' => 11,
            'parent_id' => 10,
            'name' => '团队',
            'slug' => 'team',
            'status' => 0,
        ]);

        $raw = '[{"title":"","links":[{"name":"团队","url":"/about/team.html"},{"name":"同名外链","url":"https://example.com/team.html"}]}]';
        $groups = footerNavigationGroups($raw);

        self::assertCount(1, $groups);
        self::assertSame(['同名外链'], array_column($groups[0]['links'], 'name'));
    }
}
