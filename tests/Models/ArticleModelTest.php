<?php
/**
 * Tests for ArticleModel — the legacy article store (older sibling of
 * ContentModel). Detail/list views still depend on it for the `articles`
 * route so we cover the public surface here.
 *
 * ArticleModel.getList() recurses through articleCategoryModel()->getChildIds()
 * when include_children is true (default). Tests pass include_children=false
 * to keep the schema scope tight.
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

class ArticleModelTest extends TestCase
{
    private \ArticleModel $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new \ArticleModel();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE article_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT,
                status INTEGER DEFAULT 1
            )",
            "CREATE TABLE articles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT,
                summary TEXT,
                cover TEXT,
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0,
                is_recommend INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0
            )",
        ];
    }

    private function seedFixture(): void
    {
        $this->insertRow('article_categories', ['name' => 'Tech', 'slug' => 'tech']);
        $this->insertRow('article_categories', ['name' => 'Biz',  'slug' => 'biz']);

        $this->insertRow('articles', ['category_id'=>1, 'title'=>'Tech-One',  'slug'=>'tech-one', 'status'=>1, 'publish_time'=>100, 'is_top'=>1]);
        $this->insertRow('articles', ['category_id'=>1, 'title'=>'Tech-Two',  'slug'=>'tech-two', 'status'=>1, 'publish_time'=>200, 'is_recommend'=>1]);
        $this->insertRow('articles', ['category_id'=>1, 'title'=>'Tech-Draft','slug'=>'td',       'status'=>0, 'publish_time'=>300]);
        $this->insertRow('articles', ['category_id'=>2, 'title'=>'Biz-One',   'slug'=>'biz-one',  'status'=>1, 'publish_time'=>150]);
    }

    public function testGetListExcludesDrafts(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['include_children' => false]);
        $this->assertCount(3, $rows);
        $this->assertNotContains('Tech-Draft', array_column($rows, 'title'));
    }

    public function testGetListByCategoryWithoutChildren(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(1, 50, 0, ['include_children' => false]);
        $titles = array_column($rows, 'title');
        sort($titles);
        $this->assertSame(['Tech-One', 'Tech-Two'], $titles);
    }

    public function testGetListJoinsCategoryName(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(1, 50, 0, ['include_children' => false]);
        $this->assertSame('Tech', $rows[0]['category_name']);
    }

    public function testGetListFilterRecommend(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['include_children' => false, 'is_recommend' => 1]);
        $this->assertCount(1, $rows);
        $this->assertSame('Tech-Two', $rows[0]['title']);
    }

    public function testGetListFilterTop(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['include_children' => false, 'is_top' => 1]);
        $this->assertCount(1, $rows);
        $this->assertSame('Tech-One', $rows[0]['title']);
    }

    public function testGetListKeywordSearch(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['include_children' => false, 'keyword' => 'Biz-One']);
        $this->assertCount(1, $rows);
        $this->assertSame('Biz-One', $rows[0]['title']);
    }

    public function testGetCount(): void
    {
        $this->seedFixture();
        $this->assertSame(3, $this->m->getCount(0, ['include_children' => false]));
        $this->assertSame(2, $this->m->getCount(1, ['include_children' => false]));
    }

    public function testGetPublished(): void
    {
        $this->seedFixture();
        $row = $this->m->getPublished(1);
        $this->assertSame('Tech-One', $row['title']);
        $this->assertSame('Tech',     $row['category_name']);
    }

    public function testGetPublishedSkipsDrafts(): void
    {
        $this->seedFixture();
        $this->assertNull($this->m->getPublished(3));
    }

    public function testFindBySlug(): void
    {
        $this->seedFixture();
        $row = $this->m->findBySlug('tech-two');
        $this->assertSame('Tech-Two', $row['title']);
    }

    public function testGetPrevReturnsNewerArticle(): void
    {
        $this->seedFixture();
        // Tech-One @100 → prev (newer) is Tech-Two @200
        $prev = $this->m->getPrev(1, 100, 1);
        $this->assertSame('Tech-Two', $prev['title']);
    }

    public function testGetNextReturnsOlderArticle(): void
    {
        $this->seedFixture();
        // Tech-Two @200 → next (older) is Tech-One @100
        $next = $this->m->getNext(1, 200, 2);
        $this->assertSame('Tech-One', $next['title']);
    }

    public function testGetRelatedExcludesSelf(): void
    {
        $this->seedFixture();
        $rows = $this->m->getRelated(1, 1, 5);
        $titles = array_column($rows, 'title');
        $this->assertNotContains('Tech-One', $titles);
        $this->assertContains('Tech-Two', $titles);
    }

    public function testGetByCategoryIds(): void
    {
        $this->seedFixture();
        $rows = $this->m->getByCategoryIds([1, 2], 10);
        $this->assertCount(3, $rows);                        // 3 published across both cats
    }

    public function testGetByCategoryIdsEmptyArrayReturnsEmpty(): void
    {
        $this->seedFixture();
        $this->assertSame([], $this->m->getByCategoryIds([], 10));
    }

    public function testIncrementViews(): void
    {
        $this->seedFixture();
        $this->m->incrementViews(1);
        $this->m->incrementViews(1);
        $this->m->incrementViews(1);
        $row = $this->m->getPublished(1);
        $this->assertSame(3, (int) $row['views']);
    }

    public function testGetAdminListWithKeyword(): void
    {
        $this->seedFixture();
        $result = $this->m->getAdminList(['keyword' => 'Tech-One'], 50, 0);
        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Tech-One', $result['items'][0]['title']);
    }

    public function testGetAdminListIncludesDrafts(): void
    {
        $this->seedFixture();
        $result = $this->m->getAdminList([], 50, 0);
        $this->assertSame(4, $result['total']);              // includes draft
    }
}
