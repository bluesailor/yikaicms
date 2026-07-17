<?php
/**
 * Tests for JobController — second list-controller carved off from
 * list.php. JobModel.getList already returns ['items','total'].
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/JobController.php';
require_once __DIR__ . '/_fixtures/helpers.php';

class JobControllerTest extends TestCase
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
            "CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lang TEXT DEFAULT 'zh-CN',
                title TEXT, location TEXT, salary TEXT,
                experience TEXT, education TEXT, work_type TEXT,
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    private function seedFixture(): void
    {
        $this->insertRow('channels', ['name'=>'Careers',  'slug'=>'careers', 'type'=>'job', 'parent_id'=>0]);

        $this->insertRow('jobs', ['title'=>'PHP Engineer',     'location'=>'Tokyo', 'status'=>1, 'publish_time'=>200]);
        $this->insertRow('jobs', ['title'=>'Frontend Engineer','location'=>'Tokyo', 'status'=>1, 'publish_time'=>100]);
        $this->insertRow('jobs', ['title'=>'Closed Role',      'location'=>'Osaka', 'status'=>0, 'publish_time'=>50]);
    }

    public function testReturnsActiveJobsOnly(): void
    {
        $this->seedFixture();
        $vars = (new \JobController())->prepare(
            ['id' => 1, 'type' => 'job', 'parent_id' => 0],
            $this->req()
        );
        $this->assertSame(2, $vars['total']);                 // Closed Role excluded
        $titles = array_column($vars['jobs'], 'title');
        $this->assertNotContains('Closed Role', $titles);
    }

    public function testKeywordSearch(): void
    {
        $this->seedFixture();
        $vars = (new \JobController())->prepare(
            ['id' => 1, 'type' => 'job', 'parent_id' => 0],
            $this->req(['keyword' => 'Frontend'])
        );
        $this->assertSame(1, $vars['total']);
        $this->assertSame('Frontend Engineer', $vars['jobs'][0]['title']);
    }

    public function testReturnsRequiredViewKeys(): void
    {
        $this->seedFixture();
        $vars = (new \JobController())->prepare(
            ['id' => 1, 'type' => 'job', 'parent_id' => 0],
            $this->req()
        );
        foreach (['channel','channelId','jobs','total','contents',
                  'parentChannel','rightSidebarTitle','rightSidebarChannels',
                  'subChannels'] as $k) {
            $this->assertArrayHasKey($k, $vars, "missing var: {$k}");
        }
    }

    public function testEmptyContentsArrayReturned(): void
    {
        $this->seedFixture();
        $vars = (new \JobController())->prepare(
            ['id' => 1, 'type' => 'job', 'parent_id' => 0],
            $this->req()
        );
        // The job branch sets contents=[] so the shared layout doesn't try
        // to render content items. Guard against accidental drift.
        $this->assertSame([], $vars['contents']);
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
