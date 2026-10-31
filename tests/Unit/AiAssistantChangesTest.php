<?php
/**
 * v2.2 S3：后台 AI 助手的应用/撤销改为每条一个事务（ADR-002 内部来源适配）。
 * 覆盖：部分应用不再清空整组、失败整条回滚且留在暂存可重试、日志写失败时业务回退、
 * 撤销前核对应用后版本（人工改过就拒绝）、提案记录与旧记录的处理、补发缓存失效。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiAssistantChanges;
use AiStaging;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class AiAssistantChangesTest extends TestCase
{
    protected function schemaSql(): array
    {
        require_once ROOT_PATH . '/includes/Migrator.php';
        $migration = require ROOT_PATH . '/migrations/20261031_ai_proposals.php';
        $changeLog = $migration['sqls'];
        return array_merge([
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT, type TEXT DEFAULT 'list', status INTEGER DEFAULT 1)",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER DEFAULT 1, type TEXT DEFAULT 'article',
                title TEXT NOT NULL, slug TEXT DEFAULT '', summary TEXT DEFAULT '', content TEXT DEFAULT '', tags TEXT DEFAULT '',
                status INTEGER DEFAULT 0, publish_time INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0, lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL
            )",
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `group` TEXT DEFAULT 'basic', `key` TEXT NOT NULL, `value` TEXT DEFAULT '',
                type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INTEGER DEFAULT 0)",
        ], array_map(static fn(string $sql): string => (string) _sqlToSqlite($sql), $changeLog));
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('hasPermission')) {
            eval('function hasPermission(string $p): bool { return true; }
                  function isSuperAdmin(): bool { return true; }
                  function adminLog(...$args): void {}
                  function aiService() { throw new RuntimeException("AI must not be called on apply"); }');
        }
        // AiStaging 在没有会话时会 session_start()，那会换掉 $_SESSION；后台请求里会话总是已开启，这里先开好。
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION['admin_id'] = 1;
        $_SESSION['ai_staging'] = [];
        require_once ROOT_PATH . '/includes/Migrator.php';
        require_once ROOT_PATH . '/includes/permissions.php';
        require_once ROOT_PATH . '/includes/Abilities.php';
        require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
        require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
        require_once ROOT_PATH . '/includes/AiAssistantChanges.php';
        // 迁移的建表语句是旧结构，提案列由 php 回调补齐（与老站升级同一路径）。
        (require ROOT_PATH . '/migrations/20261031_ai_proposals.php')['php']();
        $this->insertRow('channels', ['name' => 'News', 'slug' => 'news']);
        $this->insertRow('contents', ['title' => 'Draft post', 'status' => 0, 'publish_time' => 0, 'tags' => 'old']);
        $this->insertRow('contents', ['title' => 'Second post', 'status' => 0]);
        $this->insertRow('settings', ['key' => 'site_icp', 'value' => 'ICP-1']);
        if (function_exists('settingModel')) settingModel()->clearCache();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['ai_staging']);
        parent::tearDown();
    }

    private function svc(): AiAssistantChanges
    {
        return new AiAssistantChanges(db());
    }

    /** @param list<array{0:string,1:array}> $items */
    private function stage(array $items): string
    {
        $set = AiStaging::newSet();
        foreach ($items as [$ability, $input]) AiStaging::add($set, $ability, $input, ['summary' => $ability . ' ' . json_encode($input)]);
        return $set;
    }

    private function content(int $id): array
    {
        return db()->fetchOne('SELECT * FROM contents WHERE id = ?', [$id]);
    }

    public function testSeoProposalPinsTextAndTracksWholeSourceThroughApplyAndUndo(): void
    {
        db()->execute("UPDATE contents SET summary='before SEO',content='<p>source</p>' WHERE id=1");
        $preview = \Abilities::previewChange('cms_generate_seo_summary', ['id'=>1,'summary'=>'fixed SEO']);
        $set = $this->stage([['cms_generate_seo_summary', $preview['preview']['input']]]);
        $applied = $this->svc()->applyStaged(1,'admin',$set,null);
        self::assertTrue($applied['success']);
        self::assertSame('fixed SEO', $this->content(1)['summary']);
        $log = (int) $applied['applied'][0]['log_id'];
        self::assertNotSame('', db()->fetchColumn('SELECT after_revision FROM ai_change_log WHERE id=?',[$log]));
        self::assertTrue($this->svc()->undo(1,$log)['success']);
        self::assertSame('before SEO', $this->content(1)['summary']);
        $preview = \Abilities::previewChange('cms_generate_seo_summary', ['id'=>1,'summary'=>'second SEO']);
        $set = $this->stage([['cms_generate_seo_summary', $preview['preview']['input']]]);
        $applied = $this->svc()->applyStaged(1,'admin',$set,null);
        db()->execute("UPDATE contents SET content='<p>human edit</p>' WHERE id=1");
        self::assertFalse($this->svc()->undo(1,(int)$applied['applied'][0]['log_id'])['success']);
        self::assertSame('second SEO', $this->content(1)['summary']);
        $old = $this->stage([['cms_generate_seo_summary',['id'=>1]]]);
        self::assertFalse($this->svc()->applyStaged(1,'admin',$old,null)['success'], 'incomplete staging is refused without calling a model');
    }

    public function testApplyWritesEachChangeWithItsLogAndRevisionThenClearsOnlyAppliedItems(): void
    {
        $set = $this->stage([['cms_publish_content', ['id' => 1]], ['cms_set_content_flags', ['id' => 2, 'is_top' => 1]]]);
        $result = $this->svc()->applyStaged(1, 'admin', $set, ['p0']);
        self::assertTrue($result['success']);
        self::assertSame(['p0'], array_column($result['applied'], 'proposal_id'), '只应用勾选的一条');
        self::assertSame(1, (int) $this->content(1)['status']);
        $log = db()->fetchOne('SELECT * FROM ai_change_log WHERE id = ?', [$result['applied'][0]['log_id']]);
        self::assertSame(['internal', 'content', 1, 'ability', 0], [$log['source_ns'], $log['target_type'], (int) $log['target_id'], $log['operation'], (int) $log['proposal_item_id']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $log['after_revision']);
        self::assertSame(['status' => 0, 'publish_time' => 0], json_decode((string) $log['before_json'], true), '快照是执行前一刻的值');
        self::assertSame(['p1'], array_column(AiStaging::items($set), 'id'), '未选的那条还在暂存里（旧实现会整组清空）');

        $rest = $this->svc()->applyStaged(1, 'admin', $set, null);
        self::assertSame(['p1'], array_column($rest['applied'], 'proposal_id'));
        self::assertSame(1, (int) $this->content(2)['is_top']);
        self::assertSame([], AiStaging::items($set), '全部应用后整组移除，不能重复应用');
    }

    public function testAFailedItemRollsBackAloneAndStaysStagedForRetry(): void
    {
        $set = $this->stage([['cms_publish_content', ['id' => 99]], ['cms_publish_content', ['id' => 1]]]);
        $result = $this->svc()->applyStaged(1, 'admin', $set, null);
        self::assertFalse($result['success']);
        self::assertSame(['p1'], array_column($result['applied'], 'proposal_id'));
        self::assertCount(1, $result['errors']);
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM ai_change_log'), '失败的那条不留日志');
        self::assertSame(['p0'], array_column(AiStaging::items($set), 'id'), '失败的那条留在暂存，可以再点应用');
    }

    public function testALogWriteFailureRollsBackTheBusinessChange(): void
    {
        db()->execute("CREATE TRIGGER fail_log BEFORE INSERT ON ai_change_log BEGIN SELECT RAISE(ABORT, 'log down'); END");
        $set = $this->stage([['cms_publish_content', ['id' => 1]]]);
        $result = $this->svc()->applyStaged(1, 'admin', $set, null);
        self::assertSame([], $result['applied']);
        self::assertStringContainsString('数据库错误，未做任何修改', $result['errors'][0], '不把 SQL 错误原文给用户');
        self::assertSame(0, (int) $this->content(1)['status'], '日志写不进去，发布也一起回滚（旧实现：已发布但没有撤销记录）');
        self::assertCount(1, AiStaging::items($set));
        self::assertFalse(db()->getPdo()->inTransaction());
    }

    public function testUndoRefusesAfterAHumanEditAndKeepsIt(): void
    {
        $set = $this->stage([['cms_set_content_flags', ['id' => 2, 'is_top' => 1]], ['cms_auto_tag_content', ['id' => 1, 'tags' => 'a,b']]]);
        [$flags, $tags] = array_column($this->svc()->applyStaged(1, 'admin', $set, null)['applied'], 'log_id');
        db()->execute('UPDATE contents SET is_top = 0 WHERE id = 2');
        $refused = $this->svc()->undo(1, $flags);
        self::assertFalse($refused['success']);
        self::assertStringContainsString('又被改过', $refused['error']);
        self::assertSame(0, (int) db()->fetchColumn('SELECT undone FROM ai_change_log WHERE id = ?', [$flags]));

        $ok = $this->svc()->undo(1, $tags);
        self::assertTrue($ok['success']);
        self::assertSame('old', $this->content(1)['tags']);
        self::assertSame([1, 1], array_map('intval', array_values(db()->fetchOne('SELECT undone, undone_by FROM ai_change_log WHERE id = ?', [$tags]))));
        self::assertSame('该记录已撤销', $this->svc()->undo(1, $tags)['error'] ?? '');
        self::assertFalse($this->svc()->undo(2, $flags)['success'], '别人的记录不能撤销');
    }

    /**
     * 设置的当前值经 config() 读取；单元测试的 config() 是读 $GLOBALS['_test_config'] 的桩，不随写库变化，
     * 所以这里只验证写入与快照。撤销核对（含清掉请求内快照）用真实 config() 在冒烟站脚本里验证。
     */
    public function testSettingApplyRecordsTheValueBeforeTheChange(): void
    {
        $GLOBALS['_test_config']['site_icp'] = 'ICP-1';
        $set = $this->stage([['cms_update_setting', ['key' => 'site_icp', 'value' => 'ICP-2']]]);
        $log = $this->svc()->applyStaged(1, 'admin', $set, null)['applied'][0]['log_id'];
        self::assertSame('ICP-2', db()->fetchColumn("SELECT value FROM settings WHERE `key` = 'site_icp'"));
        $row = db()->fetchOne('SELECT target, target_type, target_id, before_json FROM ai_change_log WHERE id = ?', [$log]);
        self::assertSame(['site_icp', 'setting', 0, '"ICP-1"'], [$row['target'], $row['target_type'], (int) $row['target_id'], $row['before_json']]);
    }

    public function testLegacyRowsUndoAsBeforeAndProposalRowsAreRefused(): void
    {
        db()->execute('UPDATE contents SET status = 1 WHERE id = 1');
        db()->execute("INSERT INTO ai_change_log (admin_id, admin_name, ability, target, summary, before_json, input_json, created_at) VALUES (1, 'admin', 'cms_publish_content', '1', 'legacy', ?, ?, 1)",
            [json_encode(['status' => 0, 'publish_time' => 0]), json_encode(['id' => 1])]);
        $legacy = (int) db()->getPdo()->lastInsertId();
        db()->execute("INSERT INTO ai_change_log (admin_id, admin_name, ability, target, summary, before_json, input_json, created_at, proposal_item_id, after_revision) VALUES (1, 'admin', 'article_draft_propose', 'contents#2', 'p', '{}', '{}', 2, 5, 'x')");
        $proposal = (int) db()->getPdo()->lastInsertId();
        self::assertStringContainsString('AI 草稿提案', $this->svc()->undo(1, $proposal)['error'] ?? '');
        self::assertTrue($this->svc()->undo(1, 0)['success'], '“撤销上一条”跳过提案记录，撤到助手的旧记录');
        self::assertSame(0, (int) $this->content(1)['status'], '升级前没有版本的旧记录按旧行为撤销');
        self::assertSame(1, (int) db()->fetchColumn('SELECT undone FROM ai_change_log WHERE id = ?', [$legacy]));
    }

    public function testContentChangesFireDataChangedForCacheInvalidation(): void
    {
        if (!function_exists('add_action')) self::markTestSkipped('hooks not loaded');
        $fired = [];
        add_action('data_changed', function (string $table = '', $id = null) use (&$fired): void { $fired[] = [$table, $id]; });
        $set = $this->stage([['cms_publish_content', ['id' => 1]]]);
        $log = $this->svc()->applyStaged(1, 'admin', $set, null)['applied'][0]['log_id'];
        $this->svc()->undo(1, $log);
        self::assertSame([['contents', 1], ['contents', 1]], array_values(array_filter($fired, static fn(array $f): bool => $f[0] === 'contents')));
    }

    public function testRefusesToRunInsideACallerTransaction(): void
    {
        $set = $this->stage([['cms_publish_content', ['id' => 1]]]);
        db()->beginTransaction();
        $result = $this->svc()->applyStaged(1, 'admin', $set, null);
        db()->rollback();
        self::assertSame([], $result['applied']);
        self::assertSame(0, (int) $this->content(1)['status']);
    }
}
