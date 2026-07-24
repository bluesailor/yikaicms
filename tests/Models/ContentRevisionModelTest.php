<?php
/**
 * Tests for ContentRevisionModel —— 内容版本历史「保存即存档 / 一键恢复」。
 *
 * 覆盖：存档+列表、按 keepCount() 自动清理、恢复写回并把当前状态先存一版、
 * 快照白名单（拒绝非法表/列，保留合法列）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

class ContentRevisionModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE content_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                target_type TEXT, target_id INTEGER, lang TEXT,
                snapshot TEXT, summary TEXT,
                admin_id INTEGER DEFAULT 0, admin_name TEXT,
                created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT, content TEXT, updated_at INTEGER DEFAULT 0
            )",
        ];
    }

    public function testRecordThenList(): void
    {
        contentRevisionModel()->record('article', 7, 'zh-CN', [[
            'table' => 'contents', 'id' => 7, 'fields' => ['title' => 'T', 'content' => 'C'],
        ]], 'T', 1, 'alice');

        $list = contentRevisionModel()->listFor('article', 7);
        $this->assertCount(1, $list);
        $this->assertSame('T', $list[0]['summary']);
        $this->assertSame('alice', $list[0]['admin_name']);
    }

    public function testPruneKeepsConfiguredCount(): void
    {
        $keep = contentRevisionModel()->keepCount();
        for ($i = 1; $i <= $keep + 3; $i++) {
            contentRevisionModel()->record('article', 7, '', [[
                'table' => 'contents', 'id' => 7, 'fields' => ['title' => "v{$i}"],
            ]], "v{$i}");
        }
        $list = contentRevisionModel()->listFor('article', 7);
        $this->assertCount($keep, $list);
        // id DESC：最新一版在前
        $this->assertSame('v' . ($keep + 3), $list[0]['summary']);
    }

    public function testRestoreWritesBackAndSnapshotsCurrent(): void
    {
        db()->insert('contents', ['title' => 'OLD', 'content' => 'oldbody', 'updated_at' => 1]);
        $cid = (int) db()->fetchColumn('SELECT id FROM contents ORDER BY id DESC LIMIT 1');

        // 旧版本存档
        contentRevisionModel()->record('article', $cid, '', [[
            'table' => 'contents', 'id' => $cid, 'fields' => ['title' => 'OLD', 'content' => 'oldbody'],
        ]], 'OLD');
        $revId = (int) contentRevisionModel()->listFor('article', $cid)[0]['id'];

        // 用户改成 NEW
        db()->update('contents', ['title' => 'NEW', 'content' => 'newbody'], 'id = ?', [$cid]);

        // 恢复到旧版
        $n = contentRevisionModel()->restoreRevision($revId, 2, 'bob');
        $this->assertSame(1, $n);

        $row = db()->fetchOne('SELECT title, content FROM contents WHERE id = ?', [$cid]);
        $this->assertSame('OLD', $row['title']);
        $this->assertSame('oldbody', $row['content']);

        // 恢复前把 NEW 也存了一版 → 现在共 2 版
        $this->assertCount(2, contentRevisionModel()->listFor('article', $cid));
    }

    public function testSnapshotRejectsIllegalTableAndColumn(): void
    {
        contentRevisionModel()->record('page', 3, '', [
            ['table' => 'users', 'id' => 1, 'fields' => ['password' => 'x']],                 // 非法表 → 整个丢弃
            ['table' => 'channels', 'id' => 3, 'fields' => ['bad col' => 1, 'content' => 'ok']], // 非法列丢弃、保留 content
        ], 'inj');

        $one = contentRevisionModel()->getOne((int) contentRevisionModel()->listFor('page', 3)[0]['id']);
        $snap = (string) $one['snapshot'];
        $this->assertStringNotContainsString('users', $snap);
        $this->assertStringNotContainsString('bad col', $snap);
        $this->assertStringContainsString('content', $snap);
    }
}
