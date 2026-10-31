<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiAccessLog;
use AiAccessThrottle;
use AiAccessTokenStore;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/Migrator.php';
require_once ROOT_PATH . '/includes/ai-access/AiAccessTokenStore.php';
require_once ROOT_PATH . '/includes/ai-access/AiAccessThrottle.php';
require_once ROOT_PATH . '/includes/ai-access/AiAccessLog.php';

/** S2：令牌仓储、限流与访问日志。表结构直接取自 20261031_ai_access_tokens 迁移（经 SQLite 转译）。 */
final class AiAccessTokenStoreTest extends TestCase
{
    private const NOW = 1800000000;

    protected function schemaSql(): array
    {
        $migration = require ROOT_PATH . '/migrations/20261031_ai_access_tokens.php';
        $sqls = array_values(array_filter(array_map('_sqlToSqlite', $migration['sqls'])));
        $sqls[] = 'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, username TEXT NOT NULL DEFAULT \'\')';
        return $sqls;
    }

    protected function setUp(): void
    {
        parent::setUp();
        db()->execute('INSERT INTO users (id, username) VALUES (7, ?), (8, ?)', ['editor', 'other']);
    }

    private function store(): AiAccessTokenStore
    {
        return new AiAccessTokenStore(db());
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (RuntimeException $error) {
            return $error->getMessage();
        }
        return 'no error';
    }

    public function testIssueReturnsSecretOnceAndStoresOnlyItsDigest(): void
    {
        $issued = $this->store()->issue(7, 'Claude Code', ['article_read', 'site_info', 'article_read'], 30, self::NOW);
        self::assertMatchesRegularExpression('/^ykat_[A-Za-z0-9_-]{43}$/', $issued['secret']);
        self::assertSame(substr($issued['secret'], 0, 12), $issued['prefix']);
        self::assertSame(self::NOW + 30 * 86400, $issued['expires_at']);
        $row = db()->fetchOne('SELECT * FROM api_tokens WHERE id = ?', [$issued['id']]);
        self::assertSame(hash('sha256', $issued['secret']), $row['token_hash']);
        self::assertStringNotContainsString($issued['secret'], serialize($row));
        self::assertSame('["site_info","article_read"]', $row['scopes'], 'scopes 去重并按工具目录固定顺序保存');
        self::assertSame((int) $issued['id'], (int) $this->store()->findByDigest(hash('sha256', $issued['secret']))['id']);
        self::assertNull($this->store()->findByDigest($issued['secret']), '只按摘要查，明文查不到');
        foreach ($this->store()->listTokens(7) as $listed) self::assertArrayNotHasKey('token_hash', $listed);
    }

    public function testScopesRejectWildcardsAndUnknownNames(): void
    {
        foreach ([['*'], ['article_*'], ['cms_update_setting'], ['article_apply'], ['proposal_apply'], [], [1]] as $scopes) {
            self::assertSame('ai_token_invalid_scope', $this->code(fn() => $this->store()->issue(7, 'x', $scopes, 30, self::NOW)), json_encode($scopes));
        }
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM api_tokens'));
    }

    public function testProposalScopesAreIssuableButNotDefault(): void
    {
        $issued = $this->store()->issue(7, 'writer', ['proposal_status', 'article_draft_propose', 'article_read'], 30, self::NOW);
        self::assertSame('["article_read","article_draft_propose","proposal_status"]', db()->fetchColumn('SELECT scopes FROM api_tokens WHERE id = ?', [$issued['id']]));
        // page_list 与 page_read 共用 page_read 一个范围；除此之外范围与工具一一对应，没有 apply/undo/publish 之类的范围
        self::assertSame(array_values(array_diff(\AiAccessAuthContext::TOOLS, ['page_list'])), AiAccessTokenStore::ISSUABLE_SCOPES, '可签发范围就是外部工具目录（单页两工具共用一个范围）');
        self::assertSame([], array_intersect(['article_draft_propose', 'proposal_status'], AiAccessTokenStore::DEFAULT_SCOPES), '写入类范围要用户主动勾选');
    }

    public function testLifetimeNameAndPerUserLimitAreBounded(): void
    {
        foreach ([0, 91, -1] as $days) self::assertSame('ai_token_invalid_input', $this->code(fn() => $this->store()->issue(7, 'x', ['site_info'], $days, self::NOW)));
        foreach (['', '   ', str_repeat('长', 101)] as $name) self::assertSame('ai_token_invalid_input', $this->code(fn() => $this->store()->issue(7, $name, ['site_info'], 30, self::NOW)));
        self::assertSame('ai_token_invalid_input', $this->code(fn() => $this->store()->issue(0, 'x', ['site_info'], 30, self::NOW)));
        self::assertSame(self::NOW + 90 * 86400, $this->store()->issue(7, "a\nb", ['site_info'], 90, self::NOW)['expires_at']);
        self::assertSame('a b', db()->fetchColumn('SELECT name FROM api_tokens'), '控制字符不进入名称');
        for ($i = 1; $i < AiAccessTokenStore::MAX_ACTIVE_PER_USER; $i++) $this->store()->issue(7, 't' . $i, ['site_info'], 30, self::NOW);
        self::assertSame('ai_token_limit_reached', $this->code(fn() => $this->store()->issue(7, 'over', ['site_info'], 30, self::NOW)));
        self::assertSame(AiAccessTokenStore::MAX_ACTIVE_PER_USER, count($this->store()->listTokens(7)));
        $this->store()->issue(8, 'other user', ['site_info'], 30, self::NOW);
        $first = (int) db()->fetchColumn('SELECT MIN(id) FROM api_tokens WHERE admin_id = 7');
        $this->store()->revoke($first, 7, false, self::NOW);
        $this->store()->issue(7, 'after revoke', ['site_info'], 30, self::NOW);
        self::assertSame('ai_token_limit_reached', $this->code(fn() => $this->store()->issue(7, 'over', ['site_info'], 30, self::NOW)), '撤销一枚后只腾出一个名额');
        db()->execute('UPDATE api_tokens SET expires_at = ? WHERE admin_id = 7 AND revoked_at = 0 AND id = (SELECT MAX(id) FROM api_tokens WHERE admin_id = 7)', [self::NOW]);
        self::assertSame(self::NOW + 86400, $this->store()->issue(7, 'after expiry', ['site_info'], 1, self::NOW)['expires_at'], '过期的不占名额');
    }

    public function testOnlyOwnerOrSuperAdminCanRevokeAndRevokeIsIdempotent(): void
    {
        $id = $this->store()->issue(7, 'x', ['site_info'], 30, self::NOW)['id'];
        self::assertFalse($this->store()->revoke($id, 8, false, self::NOW), '他人不能撤销');
        self::assertSame(0, (int) db()->fetchColumn('SELECT revoked_at FROM api_tokens WHERE id = ?', [$id]));
        self::assertTrue($this->store()->revoke($id, 1, true, self::NOW + 5), '超管可以撤销他人的');
        self::assertTrue($this->store()->revoke($id, 7, false, self::NOW + 9), '重复撤销视为成功');
        $row = db()->fetchOne('SELECT revoked_at, revoked_by FROM api_tokens WHERE id = ?', [$id]);
        self::assertSame([self::NOW + 5, 1], [(int) $row['revoked_at'], (int) $row['revoked_by']], '首次撤销的时间与人不被覆盖');
        self::assertFalse($this->store()->revoke(9999, 1, true, self::NOW));
        $this->store()->issue(7, 'a', ['site_info'], 30, self::NOW);
        $this->store()->issue(7, 'b', ['site_info'], 30, self::NOW);
        self::assertSame(2, $this->store()->revokeAllFor(7, 1, self::NOW));
        self::assertCount(3, $this->store()->listTokens(null), '超管视图列出全部（含已撤销）');
    }

    public function testTouchWritesAtMostOncePerInterval(): void
    {
        $id = $this->store()->issue(7, 'x', ['site_info'], 30, self::NOW)['id'];
        $this->store()->touch($id, '203.0.113.9', self::NOW);
        $this->store()->touch($id, '198.51.100.1', self::NOW + 30);
        self::assertSame(['203.0.113.9', self::NOW], array_values(array_map(fn($v) => is_numeric($v) ? (int) $v : $v, db()->fetchOne('SELECT last_used_ip, last_used_at FROM api_tokens WHERE id = ?', [$id]))));
        $this->store()->touch($id, '198.51.100.1', self::NOW + 61);
        self::assertSame('198.51.100.1', db()->fetchColumn('SELECT last_used_ip FROM api_tokens WHERE id = ?', [$id]));
    }

    public function testThrottleCountsPerWindowAndFailsClosedWithoutStorage(): void
    {
        $throttle = new AiAccessThrottle(db());
        $window = self::NOW - self::NOW % 60;
        $allowed = 0;
        for ($i = 0; $i < 5; $i++) $allowed += $throttle->hit('token:9:read', 3, $window + 1) ? 1 : 0;
        self::assertSame(3, $allowed);
        self::assertSame(5, (int) db()->fetchColumn('SELECT hits FROM api_rate_limits WHERE bucket = ?', ['token:9:read']));
        self::assertSame('ip:203.0.113.9:authfail', AiAccessThrottle::failBucket('203.0.113.9'));
        self::assertFalse($throttle->hit('token:9:read', 3, $window + 30));
        self::assertTrue($throttle->hit('token:9:read', 3, $window + 60), '下一分钟重新计数');
        self::assertTrue($throttle->hit('token:10:read', 1, $window + 1), '不同令牌互不影响');
        self::assertSame(59, AiAccessThrottle::retryAfter($window + 1));
        $long = AiAccessThrottle::failBucket(str_repeat('f', 200));
        self::assertTrue($throttle->hit($long, 5, $window));
        self::assertLessThanOrEqual(100, strlen((string) db()->fetchColumn('SELECT bucket FROM api_rate_limits WHERE bucket LIKE ?', ['ip:%'])));
        self::assertSame(3, $throttle->prune($window + 3600 + 60), '一小时前的窗口被清理');
        db()->execute('DROP TABLE api_rate_limits');
        self::assertSame('ai_access_unavailable', $this->code(fn() => $throttle->hit('token:9:read', 3, $window)), '计数存储不可用时拒绝，不放行');
    }

    public function testAccessLogKeepsOnlyProtocolFactsAndPrunesInBoundedBatches(): void
    {
        $log = new AiAccessLog(db());
        $secret = 'ykat_' . str_repeat('Z', 43);
        $log->record(str_repeat('a', 40), ['actor' => 7, 'token_id' => 9, 'client' => "claude-code/2.1\x00<b>", 'meta_version' => '2026-07-28',
            'method' => 'tools/call', 'tool' => 'article_read', 'target' => 'article:101', 'status' => 200, 'tool_result' => 'ok',
            'authorization' => 'Bearer ' . $secret, 'arguments' => ['content' => 'BODY-MARKER']], '203.0.113.9', 12, self::NOW);
        $row = db()->fetchOne('SELECT * FROM api_access_log');
        self::assertSame(['7', '9', 'claude-code/2.1?<b>', '2026-07-28', 'tools/call', 'article_read', 'article:101', '200', 'ok', '12', '203.0.113.9'],
            array_map('strval', [$row['admin_id'], $row['token_id'], $row['client'], $row['protocol'], $row['method'], $row['tool'], $row['target'], $row['http_status'], $row['result'], $row['duration_ms'], $row['ip']]));
        self::assertSame(32, strlen((string) $row['request_id']));
        self::assertStringNotContainsString($secret, serialize($row));
        self::assertStringNotContainsString('BODY-MARKER', serialize($row));
        $log->record('b', ['status' => 401, 'auth' => 'ai_access_invalid_token'], '198.51.100.1', 1, self::NOW);
        self::assertSame('ai_access_invalid_token', db()->fetchColumn('SELECT result FROM api_access_log WHERE request_id = ?', ['b']));
        db()->execute('UPDATE api_access_log SET created_at = ? WHERE request_id = ?', [self::NOW - 31 * 86400, 'b']);
        self::assertSame(1, $log->prune(self::NOW));
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM api_access_log'));
    }

    public function testPruneClearsABacklogLargerThanOneBatchInOneRun(): void
    {
        $old = self::NOW - 31 * 86400;
        $backlog = 2 * AiAccessLog::PRUNE_BATCH + 7;
        db()->execute("WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < {$backlog})
            INSERT INTO api_access_log (request_id, created_at, http_status, result) SELECT 'r' || i, {$old}, 200, 'ok' FROM n");
        (new AiAccessLog(db()))->record('keep', ['status' => 200], '127.0.0.1', 1, self::NOW);
        self::assertSame($backlog, (new AiAccessLog(db()))->prune(self::NOW), '一次清理删到没有过期行为止，不再每小时只删一批');
        self::assertSame(['keep'], array_column(db()->fetchAll('SELECT request_id FROM api_access_log'), 'request_id'));
    }

    public function testRevokeAccountRevokesEveryLiveTokenAndToleratesMissingTable(): void
    {
        $this->store()->issue(7, 'a', ['site_info'], 30, self::NOW);
        $this->store()->issue(7, 'b', ['site_info'], 30, self::NOW);
        $this->store()->issue(8, 'other', ['site_info'], 30, self::NOW);
        self::assertSame(2, AiAccessTokenStore::revokeAccount(db(), 7, 1));
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM api_tokens WHERE admin_id = 7 AND revoked_at = 0'));
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM api_tokens WHERE admin_id = 8 AND revoked_at = 0'), '只撤销该账号的');
        self::assertSame(0, AiAccessTokenStore::revokeAccount(db(), 0, 1));
        db()->execute('DROP TABLE api_tokens');
        self::assertSame(0, AiAccessTokenStore::revokeAccount(db(), 7, 1), '未升级的站点（无令牌表）不报错');
    }

    public function testLimitCheckRollsBackTheRejectedInsert(): void
    {
        for ($i = 0; $i < AiAccessTokenStore::MAX_ACTIVE_PER_USER; $i++) $this->store()->issue(7, 't' . $i, ['site_info'], 30, self::NOW);
        self::assertSame('ai_token_limit_reached', $this->code(fn() => $this->store()->issue(7, 'over', ['site_info'], 30, self::NOW)));
        self::assertSame(AiAccessTokenStore::MAX_ACTIVE_PER_USER, (int) db()->fetchColumn('SELECT COUNT(*) FROM api_tokens'), '超额那枚连同事务一起回滚，不留记录');
        self::assertFalse(db()->getPdo()->inTransaction());
    }
}
