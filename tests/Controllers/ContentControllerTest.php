<?php
/**
 * Tests for ContentController — replaces the legacy default branch in
 * list.php that handles list / article / case channels via the unified
 * `contents` table.
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/ContentController.php';
require_once __DIR__ . '/_fixtures/helpers.php';

class ContentControllerTest extends TestCase
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
                type TEXT DEFAULT 'article',
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN'
            )",
        ];
    }

    private function seedFixture(): void
    {
        $this->insertRow('channels', ['name' => 'News', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'A', 'slug' => 'a', 'status' => 1, 'publish_time' => 100]);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'B', 'slug' => 'b', 'status' => 1, 'publish_time' => 200, 'summary' => 'matchme']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Draft', 'status' => 0]);
    }

    public function testListsActiveContentForChannel(): void
    {
        $this->seedFixture();
        $vars = (new \ContentController())->prepare(
            ['id' => 1, 'type' => 'list', 'parent_id' => 0],
            $this->req()
        );
        $this->assertSame(2, $vars['total']);
        $titles = array_column($vars['contents'], 'title');
        sort($titles);
        $this->assertSame(['A', 'B'], $titles);
    }

    public function testKeywordSearchAppliesToSummary(): void
    {
        $this->seedFixture();
        $vars = (new \ContentController())->prepare(
            ['id' => 1, 'type' => 'list', 'parent_id' => 0],
            $this->req(['keyword' => 'matchme'])
        );
        $this->assertSame(1, $vars['total']);
        $this->assertSame('B', $vars['contents'][0]['title']);
    }

    public function testReturnsRequiredViewKeys(): void
    {
        $this->seedFixture();
        $vars = (new \ContentController())->prepare(
            ['id' => 1, 'type' => 'list', 'parent_id' => 0],
            $this->req()
        );
        foreach (['channel','channelId','page','perPage','keyword','contents','total','subChannels'] as $k) {
            $this->assertArrayHasKey($k, $vars, "missing var: {$k}");
        }
    }

    /** @return array<string,mixed> */
    private function req(array $overrides = []): array
    {
        return array_merge(
            ['channelId'=>0,'slug'=>'','page'=>1,'perPage'=>12,'keyword'=>'','cat'=>'','sort'=>''],
            $overrides
        );
    }
}
