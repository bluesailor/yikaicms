<?php
/**
 * Tests for DownloadController — the first list-controller carved off
 * from list.php's inline branches.
 *
 * Verifies the contract:
 *   - returns required view variables
 *   - paginates correctly via downloadModel()->getList()
 *   - resolves parent-channel sidebar when applicable
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/DownloadController.php';
require_once __DIR__ . '/_fixtures/helpers.php';

class DownloadControllerTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1
            )",
            "CREATE TABLE downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER DEFAULT 0,
                title TEXT, description TEXT, file_url TEXT,
                status INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0,
                created_at TEXT
            )",
            "CREATE TABLE download_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT, slug TEXT, status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0
            )",
        ];
    }

    private function seedFixture(): void
    {
        // Top-level "Downloads" channel + a sub-channel
        $this->insertRow('channels', ['name'=>'Downloads', 'slug'=>'dl', 'type'=>'download', 'parent_id'=>0]);
        $this->insertRow('channels', ['name'=>'Sub-DL',    'slug'=>'sub-dl', 'type'=>'download', 'parent_id'=>1]);

        $this->insertRow('download_categories', ['name'=>'PDFs', 'slug'=>'pdf']);
        $this->insertRow('download_categories', ['name'=>'Bins', 'slug'=>'bin']);

        $this->insertRow('downloads', ['title'=>'Manual A',  'file_url'=>'a.pdf', 'category_id'=>1, 'status'=>1]);
        $this->insertRow('downloads', ['title'=>'Manual B',  'file_url'=>'b.pdf', 'category_id'=>1, 'status'=>1]);
        $this->insertRow('downloads', ['title'=>'Tool X',    'file_url'=>'x.bin', 'category_id'=>2, 'status'=>1]);
        $this->insertRow('downloads', ['title'=>'Draft',     'file_url'=>'d.pdf', 'category_id'=>1, 'status'=>0]);
    }

    public function testReturnsRequiredViewKeys(): void
    {
        $this->seedFixture();
        $channel = ['id' => 1, 'name' => 'Downloads', 'type' => 'download', 'parent_id' => 0];
        $vars = (new \DownloadController())->prepare($channel, $this->req());

        foreach (['channel','channelId','page','perPage','keyword','dlCatId',
                  'dlCategories','downloads','total','contents',
                  'parentChannel','rightSidebarTitle','rightSidebarChannels','subChannels'] as $key) {
            $this->assertArrayHasKey($key, $vars, "missing view var: {$key}");
        }
    }

    public function testListsActiveDownloadsOnly(): void
    {
        $this->seedFixture();
        $channel = ['id' => 1, 'name' => 'Downloads', 'type' => 'download', 'parent_id' => 0];
        $vars = (new \DownloadController())->prepare($channel, $this->req());

        $this->assertSame(3, $vars['total']);                 // Draft excluded
        $titles = array_column($vars['downloads'], 'title');
        $this->assertNotContains('Draft', $titles);
    }

    public function testFiltersByCategoryViaCatParam(): void
    {
        $this->seedFixture();
        $channel = ['id' => 1, 'type' => 'download', 'parent_id' => 0];
        $vars = (new \DownloadController())->prepare($channel, $this->req(['cat' => '1']));

        $this->assertSame(2, $vars['total']);                 // category_id=1 → Manual A/B
        $this->assertSame(1, $vars['dlCatId']);
    }

    public function testKeywordSearch(): void
    {
        $this->seedFixture();
        $channel = ['id' => 1, 'type' => 'download', 'parent_id' => 0];
        $vars = (new \DownloadController())->prepare($channel, $this->req(['keyword' => 'Tool X']));

        $this->assertSame(1, $vars['total']);
        $this->assertSame('Tool X', $vars['downloads'][0]['title']);
    }

    public function testReturnsAllActiveCategories(): void
    {
        $this->seedFixture();
        $vars = (new \DownloadController())->prepare(
            ['id' => 1, 'type' => 'download', 'parent_id' => 0],
            $this->req()
        );
        $this->assertCount(2, $vars['dlCategories']);
    }

    public function testSubChannelExposesParentSidebar(): void
    {
        $this->seedFixture();
        // child channel id=2 with parent_id=1
        $vars = (new \DownloadController())->prepare(
            ['id' => 2, 'name' => 'Sub-DL', 'type' => 'download', 'parent_id' => 1],
            $this->req()
        );
        $this->assertSame('Downloads', $vars['rightSidebarTitle']);
        $this->assertCount(1, $vars['rightSidebarChannels']);  // just Sub-DL itself
    }

    public function testTopLevelHasNoSidebar(): void
    {
        $this->seedFixture();
        $vars = (new \DownloadController())->prepare(
            ['id' => 1, 'type' => 'download', 'parent_id' => 0],
            $this->req()
        );
        $this->assertSame('', $vars['rightSidebarTitle']);
        $this->assertSame([], $vars['rightSidebarChannels']);
    }

    /** @return array<string,mixed> */
    private function req(array $overrides = []): array
    {
        return array_merge(
            ['channelId' => 0, 'slug' => '', 'page' => 1, 'perPage' => 12,
             'keyword' => '', 'cat' => '', 'sort' => ''],
            $overrides
        );
    }
}
