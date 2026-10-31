<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/Migrator.php';
require_once ROOT_PATH . '/includes/AiChangeLog.php';

/**
 * S3 迁移：已用过后台 AI 助手的站点已有旧结构的 ai_change_log（助手按需建表），
 * 迁移要逐列补齐且保留旧记录；没用过助手的站点一次建好完整结构。两种情况都能重复执行。
 */
final class AiProposalMigrationTest extends TestCase
{
    /** 只建旧版助手按需建的那张表，模拟升级前的老站。 */
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE ai_change_log (id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL DEFAULT 0, admin_name TEXT NOT NULL DEFAULT '',
                ability TEXT NOT NULL, target TEXT NOT NULL DEFAULT '', summary TEXT NOT NULL DEFAULT '', before_json TEXT, input_json TEXT,
                created_at INTEGER NOT NULL DEFAULT 0, undone INTEGER NOT NULL DEFAULT 0, undone_at INTEGER NOT NULL DEFAULT 0)",
        ];
    }

    private function migrate(): array
    {
        $migration = require ROOT_PATH . '/migrations/20261031_ai_proposals.php';
        foreach ($migration['sqls'] as $sql) {
            $sql = _sqlToSqlite($sql);
            if ($sql !== null) db()->execute($sql);
        }
        ($migration['php'])();
        return $migration;
    }

    public function testExistingAssistantLogGainsProposalColumnsAndKeepsItsRows(): void
    {
        db()->execute("INSERT INTO ai_change_log (admin_id, admin_name, ability, target, summary, before_json, input_json, created_at) VALUES (3, 'editor', 'cms_update_article', 'contents#5', 'old change', '{}', '{}', 100)");
        $migration = require ROOT_PATH . '/migrations/20261031_ai_proposals.php';
        self::assertFalse(($migration['check'])(), '升级前 check 为 false，迁移会被执行');

        $this->migrate();
        self::assertTrue(($migration['check'])());
        foreach (['proposal_item_id', 'source_ns', 'target_type', 'target_id', 'operation', 'after_revision', 'undone_by'] as $column) {
            self::assertTrue(_columnExists('ai_change_log', $column), $column);
        }
        $row = db()->fetchOne('SELECT * FROM ai_change_log WHERE id = 1');
        self::assertSame(['old change', 0, ''], [$row['summary'], (int) $row['proposal_item_id'], $row['after_revision']], '旧记录保留，新列取默认值（仍是助手记录）');
        self::assertFalse(\AiChangeLog::isProposalChange($row));

        $this->migrate();
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM ai_change_log'), '重复执行不改数据');
        $indexes = array_column(db()->fetchAll("SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'ai_proposal_items'"), 'name');
        self::assertContains('idx_ai_proposal_items_owner', $indexes);
    }

    public function testFreshSiteGetsTheFullStructureInOneRun(): void
    {
        db()->execute('DROP TABLE ai_change_log');
        $migration = $this->migrate();
        self::assertTrue(($migration['check'])());
        self::assertTrue(_columnExists('ai_change_log', 'after_revision'));
        db()->execute("INSERT INTO ai_proposals (public_id, owner_id, source_ns, token_id, tool, idempotency_key, payload_hash, contract_version, created_at, expires_at) VALUES ('a', 1, 'internal', 0, 't', 'k', 'h', 'v', 1, 2)");
        $this->expectException(\PDOException::class);
        db()->execute("INSERT INTO ai_proposals (public_id, owner_id, source_ns, token_id, tool, idempotency_key, payload_hash, contract_version, created_at, expires_at) VALUES ('b', 1, 'internal', 0, 't', 'k', 'h2', 'v', 1, 2)");
    }
}
