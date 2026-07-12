<?php
/**
 * Tests for JobDetailController — 招聘详情控制器（job_detail.php 从内联取数迁到此）。
 *
 * 覆盖：已发布职位正常返回 + 招聘栏目解析、未发布/不存在返回 null（→ 404）、
 * 浏览量自增副作用、id<=0 防御。
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/detail/JobDetailController.php';

class JobDetailControllerTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0
            )",
            "CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lang TEXT DEFAULT 'zh-CN',
                title TEXT, summary TEXT, content TEXT,
                location TEXT, salary TEXT,
                status INTEGER DEFAULT 1,
                views INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0
            )",
        ];
    }

    private function seedFixture(): void
    {
        $this->insertRow('channels', ['name' => 'Careers', 'slug' => 'careers', 'type' => 'job', 'status' => 1]);

        $this->insertRow('jobs', ['title' => 'PHP Engineer', 'status' => 1, 'views' => 5, 'publish_time' => 200]);
        $this->insertRow('jobs', ['title' => 'Closed Role',  'status' => 0, 'views' => 0, 'publish_time' => 50]);
    }

    public function testReturnsPublishedJobWithChannel(): void
    {
        $this->seedFixture();
        $vars = (new \JobDetailController())->prepare(1);

        $this->assertNotNull($vars);
        $this->assertSame('PHP Engineer', $vars['job']['title']);
        $this->assertNotNull($vars['channel']);
        $this->assertSame('Careers', $vars['channel']['name']);
    }

    public function testIncrementsViews(): void
    {
        $this->seedFixture();
        (new \JobDetailController())->prepare(1);

        $views = (int) db()->fetchColumn('SELECT views FROM jobs WHERE id = 1');
        $this->assertSame(6, $views, '浏览量应自增一次（5 → 6）');
    }

    public function testReturnsNullForUnpublishedJob(): void
    {
        $this->seedFixture();
        $this->assertNull((new \JobDetailController())->prepare(2), '未发布职位应 404');
    }

    public function testReturnsNullForMissingJob(): void
    {
        $this->seedFixture();
        $this->assertNull((new \JobDetailController())->prepare(999));
    }

    public function testReturnsNullForNonPositiveId(): void
    {
        $this->seedFixture();
        $this->assertNull((new \JobDetailController())->prepare(0));
        $this->assertNull((new \JobDetailController())->prepare(-3));
    }

    public function testChannelIsNullWhenNoJobChannel(): void
    {
        // 只种职位、不种招聘栏目 → channel 应为 null，但职位仍正常返回。
        $this->insertRow('jobs', ['title' => 'Solo Job', 'status' => 1, 'views' => 0, 'publish_time' => 10]);
        $vars = (new \JobDetailController())->prepare(1);

        $this->assertNotNull($vars);
        $this->assertNull($vars['channel']);
    }
}
