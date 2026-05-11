<?php
/**
 * Tests for ChannelModel — the navigation/channel tree backbone the
 * routing layer (list.php / detail.php) leans on.
 *
 * We focus on the behaviors list/detail views actually depend on:
 *   - findBySlug / getByParent / getHomeChannels
 *   - getChildIds (recursive, used by ContentModel.getList)
 *   - getTree (recursive nesting)
 *   - hasChildren
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

class ChannelModelTest extends TestCase
{
    private \ChannelModel $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new \ChannelModel();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT NOT NULL,
                slug TEXT,
                type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1,
                is_nav INTEGER DEFAULT 1,
                is_home INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN'
            )",
        ];
    }

    private function seedTree(): void
    {
        // Two root channels, one with a child, one disabled
        $this->insertRow('channels', ['name'=>'About',    'slug'=>'about',    'parent_id'=>0, 'is_nav'=>1, 'is_home'=>1, 'sort_order'=>1]);
        $this->insertRow('channels', ['name'=>'News',     'slug'=>'news',     'parent_id'=>0, 'is_nav'=>1, 'is_home'=>1, 'sort_order'=>2]);
        $this->insertRow('channels', ['name'=>'Sub-news', 'slug'=>'sub-news', 'parent_id'=>2, 'is_nav'=>1, 'sort_order'=>1]);
        $this->insertRow('channels', ['name'=>'Hidden',   'slug'=>'hidden',   'parent_id'=>0, 'is_nav'=>1, 'status'=>0]);
    }

    public function testFindBySlugReturnsActive(): void
    {
        $this->seedTree();
        $row = $this->m->findBySlug('about');
        $this->assertSame('About', $row['name']);
    }

    public function testFindBySlugSkipsInactive(): void
    {
        $this->seedTree();
        $this->assertNull($this->m->findBySlug('hidden'));
    }

    public function testGetByParentReturnsChildren(): void
    {
        $this->seedTree();
        $children = $this->m->getByParent(2);
        $this->assertCount(1, $children);
        $this->assertSame('Sub-news', $children[0]['name']);
    }

    public function testGetByParentExcludesInactiveByDefault(): void
    {
        $this->seedTree();
        $rootActive = $this->m->getByParent(0, true);
        $this->assertCount(2, $rootActive);                  // hidden excluded
    }

    public function testGetByParentIncludesInactiveWhenAsked(): void
    {
        $this->seedTree();
        $allRoots = $this->m->getByParent(0, false);
        $this->assertCount(3, $allRoots);                    // hidden included
    }

    public function testGetHomeChannelsOnlyActiveTopLevel(): void
    {
        $this->seedTree();
        $homes = $this->m->getHomeChannels();
        $this->assertCount(2, $homes);                       // About + News
        $names = array_column($homes, 'name');
        $this->assertContains('About', $names);
        $this->assertContains('News', $names);
    }

    public function testGetChildIdsIsRecursiveAndIncludesSelf(): void
    {
        $this->seedTree();
        // News(2) → Sub-news(3); calling on News should return [2, 3]
        $ids = $this->m->getChildIds(2);
        $this->assertSame([2, 3], $ids);
    }

    public function testGetChildIdsLeafReturnsSelf(): void
    {
        $this->seedTree();
        $ids = $this->m->getChildIds(1);                     // About has no children
        $this->assertSame([1], $ids);
    }

    public function testGetTreeNestsChildren(): void
    {
        $this->seedTree();
        $tree = $this->m->getTree(0);
        $this->assertCount(2, $tree);                        // About, News (Hidden excluded)
        // News should now carry a children array with one entry
        $news = array_values(array_filter($tree, fn($n) => $n['name'] === 'News'))[0];
        $this->assertCount(1, $news['children']);
        $this->assertSame('Sub-news', $news['children'][0]['name']);
    }

    public function testHasChildrenTrueFalse(): void
    {
        $this->seedTree();
        $this->assertTrue($this->m->hasChildren(2));         // News has Sub-news
        $this->assertFalse($this->m->hasChildren(1));        // About is leaf
    }
}
