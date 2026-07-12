<?php
/**
 * Tests for AdminLogModel — 后台审计日志的写入 / 搜索 / 清理路径。
 *
 * 后台每个写操作都经 adminLog() → AdminLogModel::log() 落库，是 admin 侧
 * 唯一被广泛调用的写路径；此前无测试覆盖。
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

class AdminLogModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE admin_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_id INTEGER DEFAULT 0,
                admin_name TEXT,
                module TEXT,
                action TEXT,
                description TEXT,
                url TEXT, method TEXT, request_data TEXT,
                ip TEXT, user_agent TEXT,
                created_at INTEGER DEFAULT 0
            )",
        ];
    }

    private function seed(): void
    {
        adminLogModel()->log(['admin_id' => 1, 'admin_name' => 'alice', 'module' => 'content', 'action' => 'create', 'description' => '新建文章', 'created_at' => 1000]);
        adminLogModel()->log(['admin_id' => 1, 'admin_name' => 'alice', 'module' => 'content', 'action' => 'delete', 'description' => '删除文章', 'created_at' => 2000]);
        adminLogModel()->log(['admin_id' => 2, 'admin_name' => 'bob',   'module' => 'channel', 'action' => 'update', 'description' => '改栏目', 'created_at' => 3000]);
    }

    public function testLogWritesRow(): void
    {
        $id = adminLogModel()->log(['admin_name' => 'alice', 'module' => 'auth', 'action' => 'login', 'created_at' => 500]);
        $this->assertGreaterThan(0, (int) $id);
        $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM admin_logs'));
    }

    public function testSearchFiltersByModule(): void
    {
        $this->seed();
        $r = adminLogModel()->search(['module' => 'content']);
        $this->assertSame(2, $r['total']);
        foreach ($r['items'] as $row) {
            $this->assertSame('content', $row['module']);
        }
    }

    public function testSearchFiltersByAdminNameLike(): void
    {
        $this->seed();
        $r = adminLogModel()->search(['admin_name' => 'ali']);
        $this->assertSame(2, $r['total']);
    }

    public function testSearchOrdersNewestFirstAndPaginates(): void
    {
        $this->seed();
        $r = adminLogModel()->search([], 2, 0);
        $this->assertSame(3, $r['total']);          // 总数不受 limit 影响
        $this->assertCount(2, $r['items']);         // 当前页 2 条
        // id DESC：最新（bob/channel）在前
        $this->assertSame('channel', $r['items'][0]['module']);
    }

    public function testClearBeforeDeletesOldEntries(): void
    {
        $this->seed();
        $deleted = adminLogModel()->clearBefore(2500);   // 删除 created_at < 2500 的两条
        $this->assertSame(2, $deleted);
        $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM admin_logs'));
    }

    public function testGetModulesReturnsDistinct(): void
    {
        $this->seed();
        $mods = array_column(adminLogModel()->getModules(), 'module');
        sort($mods);
        $this->assertSame(['channel', 'content'], $mods);
    }
}
