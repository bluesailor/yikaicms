<?php
/**
 * Tests for ContentDetailController — the data layer behind detail.php.
 *
 * Locks in:
 *   - id=0 / unpublished returns null
 *   - returned vars include all fields detail.php reads
 *   - incrementViews actually fires
 *   - download channels populate the sibling-sidebar list
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/detail/ContentDetailController.php';
require_once __DIR__ . '/_fixtures/helpers.php';

class ContentDetailControllerTest extends TestCase
{
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
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT,
                content TEXT, tags TEXT,
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0, download_count INTEGER DEFAULT 0,
                created_at TEXT, updated_at TEXT,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    private function seedFixture(): void
    {
        // Two channels: list (id=1) and download (id=2 with parent_id=3).
        $this->insertRow('channels', ['name'=>'News',     'slug'=>'news', 'type'=>'list',     'parent_id'=>0]);
        $this->insertRow('channels', ['name'=>'DLs',      'slug'=>'dls',  'type'=>'download', 'parent_id'=>0]);
        $this->insertRow('channels', ['name'=>'Sub-DL',   'slug'=>'sub',  'type'=>'download', 'parent_id'=>2]);

        // 3 articles in News (id=1 channel) so prev/next have data.
        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'A', 'slug'=>'a', 'status'=>1, 'publish_time'=>100]);
        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'B', 'slug'=>'b', 'status'=>1, 'publish_time'=>200]);
        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'C', 'slug'=>'c', 'status'=>1, 'publish_time'=>300]);
        // Draft — must not be returned.
        $this->insertRow('contents', ['channel_id'=>1, 'title'=>'Draft', 'status'=>0]);
        // A row in the sub-download channel.
        $this->insertRow('contents', ['channel_id'=>3, 'title'=>'Manual', 'status'=>1]);
    }

    public function testReturnsNullForMissingId(): void
    {
        $this->seedFixture();
        $this->assertNull((new \ContentDetailController())->prepare(0));
        $this->assertNull((new \ContentDetailController())->prepare(-1));
    }

    public function testReturnsNullForDraft(): void
    {
        $this->seedFixture();
        // id=4 is the draft we seeded
        $this->assertNull((new \ContentDetailController())->prepare(4));
    }

    public function testReturnsNullForNonexistentId(): void
    {
        $this->seedFixture();
        $this->assertNull((new \ContentDetailController())->prepare(9999));
    }

    public function testHappyPathReturnsAllExpectedKeys(): void
    {
        $this->seedFixture();
        $vars = (new \ContentDetailController())->prepare(2);

        $this->assertNotNull($vars);
        foreach (['content','channel','channelId','prevContent','nextContent',
                  'relatedContents','downloadSidebarCats'] as $k) {
            $this->assertArrayHasKey($k, $vars, "missing var: {$k}");
        }
        $this->assertSame('B',    $vars['content']['title']);
        $this->assertSame('News', $vars['channel']['name']);
        $this->assertSame(1,      $vars['channelId']);
    }

    public function testPrevAndNextSiblings(): void
    {
        $this->seedFixture();
        // Looking up id=2 (B): prev = lower id (A=1), next = higher id (C=3)
        $vars = (new \ContentDetailController())->prepare(2);
        $this->assertSame('A', $vars['prevContent']['title']);
        $this->assertSame('C', $vars['nextContent']['title']);
    }

    public function testIncrementsViewCounter(): void
    {
        $this->seedFixture();
        (new \ContentDetailController())->prepare(2);
        (new \ContentDetailController())->prepare(2);
        $row = db()->fetchOne('SELECT views FROM contents WHERE id = 2');
        $this->assertSame(2, (int) $row['views']);
    }

    public function testDownloadChannelPopulatesSiblingSidebar(): void
    {
        $this->seedFixture();
        // id=5 is "Manual" in channel 3 (sub-download under parent 2)
        $vars = (new \ContentDetailController())->prepare(5);
        $this->assertNotEmpty($vars['downloadSidebarCats']);
        // The sibling list comes from getChannels(parent_id=2)
        $names = array_column($vars['downloadSidebarCats'], 'name');
        $this->assertContains('Sub-DL', $names);
    }

    public function testNonDownloadChannelHasEmptySidebar(): void
    {
        $this->seedFixture();
        $vars = (new \ContentDetailController())->prepare(2);   // News article
        $this->assertSame([], $vars['downloadSidebarCats']);
    }
}
