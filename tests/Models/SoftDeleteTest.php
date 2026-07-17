<?php
/**
 * Tests for soft delete / recycle bin — ContentModel 上验证 Model 基类的软删除语义：
 * 删除进回收站、读方法排除已删、还原、彻底删除、按天清理。
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';

final class SoftDeleteTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT,
                type TEXT DEFAULT 'list', status INTEGER DEFAULT 1
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT, content TEXT,
                type TEXT DEFAULT 'article', status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN', translation_group_id INTEGER DEFAULT 0,
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    private function seed(): void
    {
        $this->insertRow('channels', ['name' => 'News', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'A', 'slug' => 'a', 'status' => 1, 'publish_time' => 100]);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'B', 'slug' => 'b', 'status' => 1, 'publish_time' => 200]);
    }

    public function testDeleteMovesToTrashNotPhysical(): void
    {
        $this->seed();
        contentModel()->deleteById(1);

        // 物理行还在，只是 deleted_at 被写
        $raw = db()->fetchOne('SELECT * FROM contents WHERE id = 1');
        $this->assertNotNull($raw);
        $this->assertNotNull($raw['deleted_at']);

        // 回收站计数 = 1，列表含它
        $this->assertSame(1, contentModel()->trashedCount());
        $trashed = contentModel()->getTrashed();
        $this->assertCount(1, $trashed);
        $this->assertSame('A', $trashed[0]['title']);
    }

    public function testTrashedExcludedFromReads(): void
    {
        $this->seed();
        contentModel()->deleteById(1);

        // getList / getCount 不返回已删项
        $list = contentModel()->getList(1, 10, 0, ['_skip_lang' => 1]);
        $this->assertSame(['B'], array_column($list, 'title'));
        $this->assertSame(1, contentModel()->getCount(1, ['_skip_lang' => 1]));

        // 前台 detail / slug 读取也排除已删
        $this->assertNull(contentModel()->getPublished(1));
        $this->assertNull(contentModel()->findBySlug('a'));
        $this->assertNotNull(contentModel()->getPublished(2));
    }

    public function testRestore(): void
    {
        $this->seed();
        contentModel()->deleteById(1);
        contentModel()->restore(1);

        $this->assertSame(0, contentModel()->trashedCount());
        $this->assertNotNull(contentModel()->getPublished(1));
        $this->assertSame(2, contentModel()->getCount(1, ['_skip_lang' => 1]));
    }

    public function testForceDeleteRemovesPhysically(): void
    {
        $this->seed();
        contentModel()->deleteById(1);
        contentModel()->forceDeleteById(1);

        $this->assertNull(db()->fetchOne('SELECT * FROM contents WHERE id = 1'));
        $this->assertSame(0, contentModel()->trashedCount());
    }

    public function testPurgeOlderThan(): void
    {
        $this->seed();
        // 两条都删，手动改一条的 deleted_at 到很久以前
        contentModel()->deleteById(1);
        contentModel()->deleteById(2);
        db()->update('contents', ['deleted_at' => time() - 40 * 86400], 'id = ?', [1]);

        $purged = contentModel()->purgeTrashedOlderThan(30);
        $this->assertSame(1, $purged);
        // id=1（40 天前）被清，id=2（刚删）仍在回收站
        $this->assertNull(db()->fetchOne('SELECT * FROM contents WHERE id = 1'));
        $this->assertSame(1, contentModel()->trashedCount());
    }

    public function testBatchDeleteSoftDeletes(): void
    {
        $this->seed();
        contentModel()->deleteByIds([1, 2]);
        $this->assertSame(2, contentModel()->trashedCount());
        // 物理行仍在
        $this->assertNotNull(db()->fetchOne('SELECT * FROM contents WHERE id = 1'));
        $this->assertNotNull(db()->fetchOne('SELECT * FROM contents WHERE id = 2'));
    }

    /**
     * 基类读方法（find / all / where）在软删模型上自动排除回收站行——
     * 用 jobs 表验证（JobModel 用基类 find()，不像 contents 自带守卫）。
     */
    public function testBaseReadMethodsExcludeTrashed(): void
    {
        db()->execute("CREATE TABLE jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL, status INTEGER DEFAULT 1,
            is_top INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
            lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL
        )");
        $this->insertRow('jobs', ['title' => 'Engineer']);
        $this->insertRow('jobs', ['title' => 'Designer']);

        jobModel()->deleteById(1);

        // find() 排除已删
        $this->assertNull(jobModel()->find(1));
        $this->assertNotNull(jobModel()->find(2));
        // all() 排除已删
        $this->assertSame(['Designer'], array_column(jobModel()->all(), 'title'));
        // getList 排除已删
        $list = jobModel()->getList([], 20, 0);
        $this->assertSame(1, $list['total']);
        // 回收站仍能取到、可还原
        $this->assertSame(1, jobModel()->trashedCount());
        jobModel()->restore(1);
        $this->assertNotNull(jobModel()->find(1));
    }
}
