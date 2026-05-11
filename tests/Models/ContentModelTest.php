<?php
/**
 * Tests for ContentModel — the unified content store powering article,
 * page, product, case, etc. detail views.
 *
 * Schema mirrors the production columns referenced by the model. We seed
 * mixed channel content + a couple of inactive rows so filters can be
 * verified.
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

class ContentModelTest extends TestCase
{
    private \ContentModel $m;

    protected function setUp(): void
    {
        parent::setUp();
        $this->m = new \ContentModel();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT,
                summary TEXT,
                cover TEXT,
                type TEXT DEFAULT 'article',
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0,
                is_recommend INTEGER DEFAULT 0,
                is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0,
                download_count INTEGER DEFAULT 0,
                created_at TEXT,
                lang TEXT DEFAULT 'zh-CN'
            )",
        ];
    }

    private function seedFixture(): void
    {
        // 2 channels, 5 content rows (4 published + 1 draft)
        $this->insertRow('channels', ['name'=>'News',  'slug'=>'news',  'type'=>'list']);
        $this->insertRow('channels', ['name'=>'Cases', 'slug'=>'cases', 'type'=>'list']);

        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'Hello',     'slug'=>'hello',     'status'=>1, 'publish_time'=>100, 'is_top'=>1]);
        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'World',     'slug'=>'world',     'status'=>1, 'publish_time'=>200, 'is_recommend'=>1]);
        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'Draft',     'slug'=>'draft',     'status'=>0, 'publish_time'=>300]);
        $this->insertRow('contents', ['channel_id'=>2, 'title'=>'Case A',    'slug'=>'case-a',    'status'=>1, 'publish_time'=>150, 'is_hot'=>1]);
        $this->insertRow('contents', ['channel_id'=>2, 'title'=>'Searchme',  'slug'=>'sx',        'status'=>1, 'summary'=>'unique-keyword', 'publish_time'=>250]);
    }

    public function testGetListReturnsOnlyPublished(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50);
        $this->assertCount(4, $rows);                        // draft excluded
        $titles = array_column($rows, 'title');
        $this->assertNotContains('Draft', $titles);
    }

    public function testGetListByChannel(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(1, 50);
        $this->assertCount(2, $rows);
        $titles = array_column($rows, 'title');
        sort($titles);
        $this->assertSame(['Hello', 'World'], $titles);
    }

    public function testGetListJoinsChannelInfo(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(1, 50);
        $this->assertSame('News',  $rows[0]['channel_name']);
        $this->assertSame('news',  $rows[0]['channel_slug']);
        $this->assertSame('list',  $rows[0]['channel_type']);
    }

    public function testGetListFiltersByRecommend(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['is_recommend' => 1]);
        $this->assertCount(1, $rows);
        $this->assertSame('World', $rows[0]['title']);
    }

    public function testGetListFiltersByHot(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['is_hot' => 1]);
        $this->assertCount(1, $rows);
        $this->assertSame('Case A', $rows[0]['title']);
    }

    public function testGetListKeywordSearch(): void
    {
        $this->seedFixture();
        $rows = $this->m->getList(0, 50, 0, ['keyword' => 'unique-keyword']);
        $this->assertCount(1, $rows);
        $this->assertSame('Searchme', $rows[0]['title']);
    }

    public function testGetCount(): void
    {
        $this->seedFixture();
        $this->assertSame(4, $this->m->getCount());
        $this->assertSame(2, $this->m->getCount(1));
    }

    public function testGetPublishedReturnsActive(): void
    {
        $this->seedFixture();
        $row = $this->m->getPublished(1);
        $this->assertSame('Hello', $row['title']);
        $this->assertSame('News',  $row['channel_name']);
    }

    public function testGetPublishedSkipsDrafts(): void
    {
        $this->seedFixture();
        $this->assertNull($this->m->getPublished(3));        // status=0
    }

    public function testFindBySlug(): void
    {
        $this->seedFixture();
        $row = $this->m->findBySlug('world');
        $this->assertSame('World', $row['title']);
    }

    public function testFindBySlugIgnoresDrafts(): void
    {
        $this->seedFixture();
        $this->assertNull($this->m->findBySlug('draft'));
    }

    public function testGetPrev(): void
    {
        $this->seedFixture();
        // current id=2 (World) in channel 1; prev is id=1 (Hello)
        $prev = $this->m->getPrev(1, 2);
        $this->assertSame('Hello', $prev['title']);
    }

    public function testGetPrevNoneAtStart(): void
    {
        $this->seedFixture();
        $this->assertNull($this->m->getPrev(1, 1));
    }

    public function testGetNext(): void
    {
        $this->seedFixture();
        // current id=1 in channel 1; next is id=2 (World)
        $next = $this->m->getNext(1, 1);
        $this->assertSame('World', $next['title']);
    }

    public function testGetNextSkipsDraft(): void
    {
        $this->seedFixture();
        // id=2 is last published in channel 1 (id=3 is draft) → no next
        $this->assertNull($this->m->getNext(1, 2));
    }

    public function testIncrementViews(): void
    {
        $this->seedFixture();
        $this->m->incrementViews(1);
        $this->m->incrementViews(1);
        $row = $this->m->getPublished(1);
        $this->assertSame(2, (int) $row['views']);
    }

    public function testIncrementDownloads(): void
    {
        $this->seedFixture();
        $this->m->incrementDownloads(1);
        $row = $this->m->getPublished(1);
        $this->assertSame(1, (int) $row['download_count']);
    }
}
