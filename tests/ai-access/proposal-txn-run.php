<?php
/**
 * S1-03 事务/幂等/安全撤销原型的行为验证。每个场景重建合成测试库后执行真实读写与多进程并发。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=/tmp/s103-x.sqlite php tests/ai-access/proposal-txn-run.php [--out=report.json]
 *   YK_S103_DRIVER=mysql YK_S103_MYSQL_NAME=s103_txn_test YK_S103_MYSQL_USER=… YK_S103_MYSQL_PASSWORD=… php tests/ai-access/proposal-txn-run.php
 *
 * 退出码：0 = 全部断言通过；1 = 有断言失败；2 = 环境错误。报告列出每条断言，供执行报告引用。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';

const S103_SECRET_MARKER = 'S103-SECRET-BODY-MARKER';
const S103_TOKEN_PLAINTEXT = 's103-token-nine';

final class S103Run
{
    public Database $db;
    public int $now;
    /** @var array<string,list<array{0:bool,1:string}>> */
    public array $results = [];
    public string $scenario = '';
    /** @var list<string> 所有对外错误文本与子进程输出，用于泄漏检查 */
    public array $surfaced = [];
    private string $tmp;

    public function __construct()
    {
        $this->db = s103Boot();
        $this->now = 1800000000;
        $this->tmp = sys_get_temp_dir() . '/s103-run-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700);
    }

    public function reset(): void
    {
        s103ResetSchema($this->db);
        s103Seed($this->db, $this->now);
    }

    public function svc(?Closure $checkpoint = null): AiProposalService
    {
        return new AiProposalService($this->db, $checkpoint);
    }

    public function check(bool $ok, string $label): void
    {
        $this->results[$this->scenario][] = [$ok, $label];
    }

    /** 捕获服务异常为 [message, code]；成功返回结果数组。 */
    public function attempt(callable $fn): array
    {
        try {
            return ['ok' => true, 'result' => $fn()];
        } catch (Throwable $e) {
            $this->surfaced[] = $e->getMessage();
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->getCode()];
        }
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . S103_PREFIX . $table . ' WHERE ' . $where, $params);
    }

    public function row(string $table, int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . S103_PREFIX . $table . ' WHERE id = ?', [$id]);
    }

    public function item(string $publicId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . S103_PREFIX . 'ai_proposal_items WHERE public_id = ?', [$publicId]);
    }

    /** 不在当前进程持有任何事务时执行 DDL（触发器），模拟业务/日志写入的真实数据库故障。 */
    public function failOn(string $table, string $event): string
    {
        $name = S103_PREFIX . 'fail_' . $table . '_' . strtolower($event);
        $target = S103_PREFIX . $table;
        if ($this->db->isSqlite()) {
            $this->db->execute("CREATE TRIGGER {$name} BEFORE {$event} ON {$target} BEGIN SELECT RAISE(ABORT, 'forced failure " . S103_SECRET_MARKER . "'); END");
        } else {
            $this->db->execute("CREATE TRIGGER {$name} BEFORE {$event} ON {$target} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure " . S103_SECRET_MARKER . "'");
        }
        return $name;
    }

    public function dropTrigger(string $name): void
    {
        $this->db->execute('DROP TRIGGER IF EXISTS ' . $name);
    }

    /**
     * 并行启动子进程。$jobs 每项是 worker 参数；'after' 指向另一个任务的信号文件，等它出现再启动。
     * @param array<string,array<string,mixed>> $jobs
     * @return array<string,array<string,mixed>>
     */
    public function parallel(array $jobs): array
    {
        $barrier = $this->tmp . '/go-' . bin2hex(random_bytes(4));
        $procs = [];
        $order = array_keys($jobs);
        foreach (array_keys($jobs) as $name) $jobs[$name]['signal'] =$this->tmp . '/sig-' . $name . '-' . bin2hex(random_bytes(4));
        foreach ($order as $name) {
            $job = $jobs[$name] + ['now' => $this->now];
            if (isset($job['after'])) {
                $wait = $jobs[$job['after']]['signal'];
                $deadline = microtime(true) + 15;
                if (!is_file($barrier)) touch($barrier);
                while (!is_file($wait) && microtime(true) < $deadline) usleep(2000);
                unset($job['after']);
            } else {
                $job['barrier'] = $barrier;
            }
            $cmd = [PHP_BINARY, __DIR__ . '/proposal-worker.php', base64_encode(json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
            $procs[$name] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $procs[$name . ':pipes'] = $pipes;
        }
        if (!is_file($barrier)) touch($barrier);
        $out = [];
        foreach ($order as $name) {
            $pipes = $procs[$name . ':pipes'];
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($procs[$name]);
            $this->surfaced[] = $stdout . $stderr;
            $decoded = json_decode(trim((string) $stdout), true);
            $out[$name] = is_array($decoded) ? $decoded : ['ok' => false, 'error' => 'worker_crash', 'exit' => $exit, 'stderr' => substr((string) $stderr, 0, 400)];
        }
        return $out;
    }
}

function itemsCreate(string $title = 'Proposed draft', string $body = '<p>proposed body</p>'): array
{
    return [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 10, 'title' => $title, 'content' => $body, 'summary' => 'proposed summary']];
}

function itemsUpdate(int $id = 100, string $lang = 'zh-CN', string $title = 'Updated by proposal'): array
{
    return [['op' => 'update', 'id' => $id, 'lang' => $lang, 'title' => $title, 'content' => '<p>updated body</p>']];
}

$run = new S103Run();
$scenarios = [];

// 1. 业务失败、日志失败、提交前任何失败：业务/日志/项目状态全部回滚，可重试。
$scenarios['01_rollback_on_business_or_log_failure'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $create = $svc->proposeFromToken(s103TokenContext(), 'key-rollback-create', itemsCreate(), $r->now)['items'][0]['item_id'];
    $update = $svc->proposeFromToken(s103TokenContext(), 'key-rollback-update', itemsUpdate(), $r->now)['items'][0]['item_id'];
    $contents = $r->count('contents');
    $draftBefore = AiArticleDraftRules::contentFingerprint($r->row('contents', 100));

    $trigger = $r->failOn('contents', 'INSERT');
    $res = $r->attempt(fn() => $svc->apply(7, $create, $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_unavailable' && $res['code'] === 503, 'create: business INSERT failure surfaces stable 503 code');
    $r->check($r->count('contents') === $contents && $r->count('ai_change_log') === 0, 'create: no content and no log row after business failure');
    $r->check($r->item($create)['status'] === 'pending' && $r->item($create)['change_log_id'] == 0, 'create: item stays pending (no committed applying state)');
    $r->dropTrigger($trigger);

    $trigger = $r->failOn('contents', 'UPDATE');
    $res = $r->attempt(fn() => $svc->apply(7, $update, $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_unavailable', 'update: business UPDATE failure surfaces stable code');
    $r->check(AiArticleDraftRules::contentFingerprint($r->row('contents', 100)) === $draftBefore && $r->item($update)['status'] === 'pending', 'update: target unchanged and item pending');
    $r->dropTrigger($trigger);

    $trigger = $r->failOn('ai_change_log', 'INSERT');
    $res = $r->attempt(fn() => $svc->apply(7, $update, $r->now));
    $r->check(!$res['ok'], 'log INSERT failure fails the apply');
    $r->check(AiArticleDraftRules::contentFingerprint($r->row('contents', 100)) === $draftBefore, 'log failure rolls back the already-executed business UPDATE');
    $r->check($r->count('ai_change_log') === 0 && $r->item($update)['status'] === 'pending', 'log failure leaves no log row and item pending');
    $res = $r->attempt(fn() => $svc->apply(7, $create, $r->now));
    $r->check(!$res['ok'] && $r->count('contents') === $contents, 'log failure also rolls back a created draft (no orphan article)');
    $r->dropTrigger($trigger);

    // 项目状态已经写成 applied 之后才失败，同样整体回滚。
    $boom = $r->svc(function (string $point): void { if ($point === 'apply.before_commit') throw new RuntimeException('injected late failure'); });
    $res = $r->attempt(fn() => $boom->apply(7, $update, $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_unavailable', 'failure after status write surfaces stable code, not the raw message');
    $r->check($r->item($update)['status'] === 'pending' && $r->count('ai_change_log') === 0 && AiArticleDraftRules::contentFingerprint($r->row('contents', 100)) === $draftBefore,
        'failure after status write rolls back status, log and business row together');

    // 嵌套事务：拒绝，而不是跳过检查。
    $r->db->beginTransaction();
    $res = $r->attempt(fn() => $svc->apply(7, $update, $r->now));
    $r->db->rollback();
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_nested_transaction', 'apply inside a caller-owned transaction is refused');

    $ok1 = $svc->apply(7, $create, $r->now);
    $ok2 = $svc->apply(7, $update, $r->now);
    $r->check($ok1['status'] === 'applied' && $ok2['status'] === 'applied', 'after the faults are removed the same items apply normally (explicit retry)');
    $r->check($r->count('ai_change_log') === 2 && $r->count('contents') === $contents + 1, 'retry writes exactly one log per item and one new draft');
};

// 2. 同键同载荷并发只产生一份提案；不同载荷拒绝；没有幂等键拒绝。
$scenarios['02_idempotency_concurrent_and_conflicting'] = static function (S103Run $r): void {
    $jobs = [];
    for ($i = 0; $i < 4; $i++) $jobs["p{$i}"] = ['action' => 'propose_token', 'key' => 'key-concurrent-0001', 'items' => itemsCreate()];
    $out = $r->parallel($jobs);
    $ids = array_unique(array_map(static fn(array $o): string => (string) ($o['result']['proposal_id'] ?? 'error'), $out));
    $fresh = count(array_filter($out, static fn(array $o): bool => ($o['result']['replayed'] ?? true) === false));
    $r->check(count($ids) === 1 && !in_array('error', $ids, true), '4 concurrent same-key proposals all return the same proposal id');
    $r->check($fresh === 1, 'exactly one concurrent call created the proposal; the rest replayed');
    $r->check($r->count('ai_proposals') === 1 && $r->count('ai_proposal_items') === 1, 'one proposal row and one item row in the database');

    $svc = $r->svc();
    $res = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-concurrent-0001', itemsCreate('Different title'), $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_idempotency_conflict' && $res['code'] === 409, 'same key with a different payload is rejected with 409');
    $r->check($r->count('ai_proposals') === 1, 'conflicting payload wrote nothing');

    $res = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), '', itemsCreate(), $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_invalid_input', 'missing idempotency key is rejected (no fallback to request or JSON-RPC ids)');
    $params = array_map(static fn(ReflectionParameter $p): string => $p->getName(), (new ReflectionMethod(AiProposalService::class, 'proposeFromToken'))->getParameters());
    $r->check($params === ['context', 'idempotencyKey', 'items', 'now'], 'propose API has no request/JSON-RPC id parameter to derive a key from');

    // 并发应用同一草稿提案：只生成一篇草稿。
    $item = $out['p0']['result']['items'][0]['item_id'];
    $apply = $r->parallel(['a' => ['action' => 'apply', 'admin' => 7, 'item' => $item], 'b' => ['action' => 'apply', 'admin' => 7, 'item' => $item], 'c' => ['action' => 'apply', 'admin' => 7, 'item' => $item]]);
    $contentIds = array_unique(array_map(static fn(array $o): int => (int) ($o['result']['content_id'] ?? -1), $apply));
    $r->check(count($contentIds) === 1 && reset($contentIds) > 0, '3 concurrent applies of one item report the same draft id');
    $r->check(count(array_filter($apply, static fn(array $o): bool => ($o['result']['replayed'] ?? true) === false)) === 1, 'exactly one concurrent apply wrote; others replayed');
    $r->check($r->count('contents', 'title = ?', ['Proposed draft']) === 1 && $r->count('ai_change_log') === 1, 'one draft row and one change-log row exist');
};

// 3. 内部来源（无令牌）也有非空命名空间，同样防重；与令牌来源互不冲突。
$scenarios['03_internal_source_namespace'] = static function (S103Run $r): void {
    $out = $r->parallel(['i0' => ['action' => 'propose_internal', 'owner' => 7, 'key' => 'key-internal-0001', 'items' => itemsCreate()],
        'i1' => ['action' => 'propose_internal', 'owner' => 7, 'key' => 'key-internal-0001', 'items' => itemsCreate()],
        'i2' => ['action' => 'propose_internal', 'owner' => 7, 'key' => 'key-internal-0001', 'items' => itemsCreate()]]);
    $r->check(count(array_unique(array_map(static fn(array $o): string => (string) ($o['result']['proposal_id'] ?? 'x'), $out))) === 1, 'concurrent internal proposals with one key resolve to one proposal');
    $row = $r->db->fetchOne('SELECT source_ns, token_id FROM ' . S103_PREFIX . 'ai_proposals');
    $r->check($r->count('ai_proposals') === 1 && $row['source_ns'] === 'internal' && (int) $row['token_id'] === 0, 'internal source stored as non-NULL namespace "internal" with token_id 0');
    $r->check($r->count('ai_proposals', 'source_ns IS NULL OR idempotency_key IS NULL OR tool IS NULL OR owner_id IS NULL') === 0, 'no NULL in any idempotency column');
    $r->svc()->proposeFromToken(s103TokenContext(), 'key-internal-0001', itemsCreate(), $r->now);
    $r->check($r->count('ai_proposals') === 2, 'same key under token:9 namespace is a separate proposal');
    $r->svc()->proposeInternal(8, 'key-internal-0001', itemsCreate(), $r->now);
    $r->check($r->count('ai_proposals') === 3, 'same key for another owner is a separate proposal');
};

// 4. 重复应用不再次写入；提交后回调失败时如实返回已提交结果，重试读回同一结果。
$scenarios['04_repeat_apply_and_timeout_retry'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-repeat-0001', itemsCreate(), $r->now)['items'][0]['item_id'];
    $first = $svc->apply(7, $item, $r->now);
    $second = $svc->apply(7, $item, $r->now + 5);
    $r->check($first['status'] === 'applied' && !$first['replayed'], 'first apply writes');
    $r->check($second['replayed'] && $second['content_id'] === $first['content_id'] && $second['log_id'] === $first['log_id'], 'repeat apply returns the same draft/log ids without writing');
    $r->check($r->count('contents', 'title = ?', ['Proposed draft']) === 1 && $r->count('ai_change_log') === 1, 'still exactly one draft and one log row');

    // 模拟“已提交但响应丢失”：提交后回调抛错。
    $item2 = $svc->proposeFromToken(s103TokenContext(), 'key-repeat-0002', itemsCreate('Second draft'), $r->now)['items'][0]['item_id'];
    $flaky = $r->svc(function (string $point) use ($r): void {
        if ($point === 'apply.before_commit') $r->db->afterCommit(static function (): void { throw new RuntimeException('cache backend unavailable'); });
    });
    $res = $r->attempt(fn() => $flaky->apply(7, $item2, $r->now));
    $r->check($res['ok'] && $res['result']['status'] === 'applied' && $res['result']['post_commit_error'] === true, 'post-commit callback failure is reported as applied + post_commit_error, not "not saved"');
    $retry = $svc->apply(7, $item2, $r->now + 30);
    $r->check($retry['replayed'] && $retry['content_id'] === $res['result']['content_id'], 'client retry after the lost response reads back the committed result');
    $r->check($r->count('contents', 'title = ?', ['Second draft']) === 1, 'the retried item still has exactly one draft');
};

// 5. 部分选择：未选项目保留；单项失败不清空整组。
$scenarios['05_partial_selection'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $prop = $svc->proposeFromToken(s103TokenContext(), 'key-partial-0001', [
        itemsCreate('Partial new')[0], itemsUpdate(100)[0], itemsUpdate(103, 'en', 'English updated')[0],
    ], $r->now);
    [$a, $b, $c] = array_column($prop['items'], 'item_id');
    $r->db->execute('UPDATE ' . S103_PREFIX . 'contents SET content = ? WHERE id = 103', ['<p>edited by a human first</p>']);
    $results = $svc->applyMany(7, [$a, $c], $r->now);
    $r->check($results[0]['status'] === 'applied' && $results[1]['status'] === 'conflicted', 'selected items: one applied, one conflicted');
    $r->check($r->item($b)['status'] === 'pending', 'unselected item remains pending (set is not cleared)');
    $r->check(($svc->apply(7, $b, $r->now)['status'] ?? '') === 'applied', 'remaining item can be applied later');
    $r->check($r->row('contents', 103)['content'] === '<p>edited by a human first</p>', 'conflicted item did not overwrite the human edit');
};

// 6. 撤销令牌/禁用账号/降权与应用并发：按 ADR 的锁检查点界定先后。
$scenarios['06_revocation_vs_apply_ordering'] = static function (S103Run $r): void {
    $svc = $r->svc();
    // (a) 应用先取得锁：撤销等待到应用提交之后，应用保持 applied。
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-order-0001', itemsCreate('Applied before revoke'), $r->now)['items'][0]['item_id'];
    $out = $r->parallel(['apply' => ['action' => 'apply', 'admin' => 7, 'item' => $item, 'hold_point' => 'apply.locked', 'hold_ms' => 1500],
        'revoke' => ['action' => 'revoke_token', 'token' => 9, 'after' => 'apply']]);
    $r->check(($out['apply']['result']['status'] ?? '') === 'applied', 'apply that locked first commits as applied');
    $r->check(($out['revoke']['ok'] ?? false) && $out['revoke']['finished'] >= $out['apply']['finished'] - 0.05, 'revocation waited for the apply to commit (finished after it)');
    $r->check($r->item($item)['status'] === 'applied' && (int) $r->row('api_tokens', 9)['revoked_at'] > 0, 'final state: item applied, token revoked — the earlier commit is not erased');

    // (b) 撤销先取得锁：应用等待后看到撤销，终止该项且不写内容。
    $r->reset();
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-order-0002', itemsCreate('Never applied'), $r->now)['items'][0]['item_id'];
    $out = $r->parallel(['revoke' => ['action' => 'revoke_token', 'token' => 9, 'hold_ms' => 1500],
        'apply' => ['action' => 'apply', 'admin' => 7, 'item' => $item, 'after' => 'revoke']]);
    $r->check(($out['apply']['result']['status'] ?? '') === 'cancelled' && ($out['apply']['result']['reason'] ?? '') === 'source_token_revoked', 'apply blocked behind a committed revocation is cancelled (source_token_revoked)');
    $r->check($out['apply']['finished'] >= $out['revoke']['finished'] - 0.05, 'apply decision came after the revocation committed');
    $r->check($r->count('contents', 'title = ?', ['Never applied']) === 0 && $r->count('ai_change_log') === 0, 'no draft and no log were written');

    // (c) 禁用账号先提交：保持 pending 并记录原因。
    $r->reset();
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-order-0003', itemsCreate('Owner disabled'), $r->now)['items'][0]['item_id'];
    $out = $r->parallel(['disable' => ['action' => 'disable_user', 'user' => 7, 'hold_ms' => 1200],
        'apply' => ['action' => 'apply', 'admin' => 7, 'item' => $item, 'after' => 'disable']]);
    $r->check(($out['apply']['result']['status'] ?? '') === 'pending' && ($out['apply']['result']['reason'] ?? '') === 'owner_disabled', 'apply after a committed account disable is refused (pending, owner_disabled)');
    $r->check($r->count('contents', 'title = ?', ['Owner disabled']) === 0, 'disabled owner: nothing written');

    // (d) 降权先提交：移除 edit_article 后拒绝。
    $r->reset();
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-order-0004', itemsCreate('Owner demoted'), $r->now)['items'][0]['item_id'];
    $out = $r->parallel(['demote' => ['action' => 'demote_role', 'role' => 2, 'hold_ms' => 1200],
        'apply' => ['action' => 'apply', 'admin' => 7, 'item' => $item, 'after' => 'demote']]);
    $r->check(($out['apply']['result']['reason'] ?? '') === 'owner_permission_removed', 'apply after a committed demotion is refused (owner_permission_removed)');

    // (e) 应用先锁、随后禁用账号：禁用排在应用之后，结果仍是 applied。
    $r->reset();
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-order-0005', itemsCreate('Applied before disable'), $r->now)['items'][0]['item_id'];
    $out = $r->parallel(['apply' => ['action' => 'apply', 'admin' => 7, 'item' => $item, 'hold_point' => 'apply.locked', 'hold_ms' => 1200],
        'disable' => ['action' => 'disable_user', 'user' => 7, 'after' => 'apply']]);
    $r->check(($out['apply']['result']['status'] ?? '') === 'applied' && $out['disable']['finished'] >= $out['apply']['finished'] - 0.05, 'disable that arrives while apply holds the lock is ordered after the apply');

    // (f) 撤销先提交时，新提案也不能写入。
    $r->reset();
    $out = $r->parallel(['revoke' => ['action' => 'revoke_token', 'token' => 9, 'hold_ms' => 1200],
        'propose' => ['action' => 'propose_token', 'key' => 'key-order-0006', 'items' => itemsCreate(), 'after' => 'revoke']]);
    $r->check(($out['propose']['error'] ?? '') === 'ai_proposal_source_denied' && $r->count('ai_proposals') === 0, 'propose blocked behind a revocation is denied and writes nothing');

    // (g) 过期令牌、关闭总开关、演示模式。
    $r->reset();
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-order-0007', itemsCreate(), $r->now)['items'][0]['item_id'];
    $r->db->execute('UPDATE ' . S103_PREFIX . "settings SET `value` = '0' WHERE `key` = 'ai_access_enabled'");
    $r->check($svc->apply(7, $item, $r->now)['reason'] === 'ai_access_disabled', 'API switch off blocks applying token-sourced proposals');
    $r->db->execute('UPDATE ' . S103_PREFIX . "settings SET `value` = '1' WHERE `key` = 'ai_access_enabled'");
    $r->db->execute('UPDATE ' . S103_PREFIX . "settings SET `value` = '1' WHERE `key` = 'demo_mode'");
    $r->check($svc->apply(7, $item, $r->now)['reason'] === 'demo_mode', 'demo mode blocks applying');
    $r->db->execute('UPDATE ' . S103_PREFIX . "settings SET `value` = '0' WHERE `key` = 'demo_mode'");
    $r->check($svc->apply(7, $item, $r->now + 90000)['reason'] === 'source_token_expired', 'expired source token cancels the item');
    $r->check($r->item($item)['status'] === 'cancelled', 'expired token: item is terminal (cancelled)');
};

// 7. 人工编辑、发布、删除、换类型/语言/栏目导致冲突，旧提案不覆盖新状态。
$scenarios['07_conflicts_never_overwrite'] = static function (S103Run $r): void {
    $p = S103_PREFIX;
    $cases = [
        'target_changed' => "UPDATE {$p}contents SET title = 'Human title' WHERE id = 100",
        'target_published' => "UPDATE {$p}contents SET status = 1, publish_time = 1 WHERE id = 100",
        'target_deleted' => "UPDATE {$p}contents SET deleted_at = 1 WHERE id = 100",
        'type_changed' => "UPDATE {$p}contents SET type = 'case' WHERE id = 100",
        'lang_changed' => "UPDATE {$p}contents SET lang = 'en' WHERE id = 100",
        'channel_moved' => "UPDATE {$p}contents SET channel_id = 13 WHERE id = 100",
    ];
    foreach ($cases as $label => $sql) {
        $r->reset();
        $svc = $r->svc();
        $item = $svc->proposeFromToken(s103TokenContext(), 'key-conflict-' . $label, itemsUpdate(), $r->now)['items'][0]['item_id'];
        $r->db->execute($sql);
        $snapshot = $r->row('contents', 100);
        $res = $svc->apply(7, $item, $r->now);
        $r->check($res['status'] === 'conflicted' && $r->row('contents', 100) == $snapshot, "{$label}: conflicted and the newer row is untouched ({$res['reason']})");
    }
    foreach (['channel_disabled' => "UPDATE {$p}channels SET status = 0 WHERE id = 10", 'channel_lang' => "UPDATE {$p}channels SET lang = 'en' WHERE id = 10",
        'channel_type' => "UPDATE {$p}channels SET type = 'page' WHERE id = 10", 'language_disabled' => "UPDATE {$p}settings SET `value` = '[\"en\"]' WHERE `key` = 'enabled_languages'"] as $label => $sql) {
        $r->reset();
        $svc = $r->svc();
        $item = $svc->proposeFromToken(s103TokenContext(), 'key-conflict-' . $label, itemsCreate(), $r->now)['items'][0]['item_id'];
        $r->db->execute($sql);
        if ($label === 'language_disabled') $r->db->execute("UPDATE {$p}settings SET `value` = 'en' WHERE `key` = 'site_lang'");
        $count = $r->count('contents');
        $res = $svc->apply(7, $item, $r->now);
        $r->check($res['status'] === 'conflicted' && $r->count('contents') === $count, "create {$label}: conflicted, no draft created ({$res['reason']})");
    }
    // 人工保存与应用并发：应用持有目标行锁，人工保存排在其后；之后的撤销不覆盖人工内容（见场景 8）。
    $r->reset();
    $svc = $r->svc();
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-conflict-race', itemsUpdate(), $r->now)['items'][0]['item_id'];
    $out = $r->parallel(['apply' => ['action' => 'apply', 'admin' => 7, 'item' => $item, 'hold_point' => 'apply.before_log', 'hold_ms' => 1200],
        'human' => ['action' => 'human_edit', 'id' => 100, 'content' => '<p>human after apply</p>', 'after' => 'apply']]);
    $r->check(($out['apply']['result']['status'] ?? '') === 'applied' && $out['human']['finished'] >= $out['apply']['finished'] - 0.05, 'concurrent human save waits for the apply transaction');
    $r->check($r->row('contents', 100)['content'] === '<p>human after apply</p>', 'the later human save is the final content (not lost)');
    $undo = $r->attempt(fn() => $svc->undo(7, (int) $out['apply']['result']['log_id'], $r->now));
    $r->check(!$undo['ok'] && $undo['error'] === 'ai_proposal_undo_conflict' && $r->row('contents', 100)['content'] === '<p>human after apply</p>', 'undo after the human save is refused and keeps the human content');
};

// 8. 撤销只在目标仍等于应用后版本时成功；不物理删除。
$scenarios['08_safe_undo'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $before = $r->row('contents', 100);
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-undo-0001', itemsUpdate(), $r->now)['items'][0]['item_id'];
    $applied = $svc->apply(7, $item, $r->now);
    $res = $r->attempt(fn() => $svc->undo(8, $applied['log_id'], $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_not_found', 'another admin cannot undo (no existence leak)');
    $undo = $svc->undo(7, $applied['log_id'], $r->now + 10);
    $now = $r->row('contents', 100);
    $r->check($undo['status'] === 'reverted' && $now['title'] === $before['title'] && $now['content'] === $before['content'], 'update undo restores the pre-apply fields');
    $r->check((int) $r->row('ai_change_log', $applied['log_id'])['undone'] === 1 && $r->item($item)['status'] === 'reverted', 'log undone and item reverted in the same commit');
    $r->check($svc->undo(7, $applied['log_id'], $r->now + 20)['replayed'] === true, 'repeat undo is a safe no-op');

    $create = $svc->proposeFromToken(s103TokenContext(), 'key-undo-0002', itemsCreate('Undo me'), $r->now)['items'][0]['item_id'];
    $done = $svc->apply(7, $create, $r->now);
    $svc->undo(7, $done['log_id'], $r->now + 10);
    $row = $r->row('contents', $done['content_id']);
    $r->check($row !== null && $row['deleted_at'] !== null, 'create undo soft-deletes into the recycle bin (row still exists)');

    $create = $svc->proposeFromToken(s103TokenContext(), 'key-undo-0003', itemsCreate('Published later'), $r->now)['items'][0]['item_id'];
    $done = $svc->apply(7, $create, $r->now);
    $r->db->execute('UPDATE ' . S103_PREFIX . 'contents SET status = 1, publish_time = ? WHERE id = ?', [$r->now + 5, $done['content_id']]);
    $res = $r->attempt(fn() => $svc->undo(7, $done['log_id'], $r->now + 10));
    $row = $r->row('contents', $done['content_id']);
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_undo_conflict' && $row['deleted_at'] === null && (int) $row['status'] === 1, 'created draft later published: undo refused, nothing deleted');

    $create = $svc->proposeFromToken(s103TokenContext(), 'key-undo-0004', itemsCreate('Edited later'), $r->now)['items'][0]['item_id'];
    $done = $svc->apply(7, $create, $r->now);
    $r->db->execute('UPDATE ' . S103_PREFIX . 'contents SET content = ? WHERE id = ?', ['<p>kept editing</p>', $done['content_id']]);
    $res = $r->attempt(fn() => $svc->undo(7, $done['log_id'], $r->now + 10));
    $r->check(!$res['ok'] && $r->row('contents', $done['content_id'])['deleted_at'] === null, 'created draft edited further: undo refused, row kept');

    // 撤销失败（日志写入失败）时回退整体回滚。
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-undo-0005', itemsUpdate(103, 'en', 'EN proposal'), $r->now)['items'][0]['item_id'];
    $done = $svc->apply(7, $item, $r->now);
    $trigger = $r->failOn('ai_change_log', 'UPDATE');
    $res = $r->attempt(fn() => $svc->undo(7, $done['log_id'], $r->now + 10));
    $r->dropTrigger($trigger);
    $r->check(!$res['ok'] && $r->row('contents', 103)['title'] === 'EN proposal' && $r->item($item)['status'] === 'applied', 'undo whose log update fails leaves content, log and item unchanged');
};

// 9. 来源令牌撤销后不能应用待审项，但 owner 仍可撤销已应用的改动。
$scenarios['09_revoked_token_cannot_apply_but_owner_can_undo'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $prop = $svc->proposeFromToken(s103TokenContext(), 'key-revoked-0001', [itemsUpdate(100)[0], itemsCreate('Pending at revoke')[0]], $r->now);
    [$applied, $pending] = array_column($prop['items'], 'item_id');
    $done = $svc->apply(7, $applied, $r->now);
    $r->db->execute('UPDATE ' . S103_PREFIX . 'api_tokens SET revoked_at = ? WHERE id = 9', [$r->now + 1]);
    $res = $svc->apply(7, $pending, $r->now + 2);
    $r->check($res['status'] === 'cancelled' && $res['reason'] === 'source_token_revoked', 'pending item from a revoked token cannot be applied');
    $r->check($r->count('contents', 'title = ?', ['Pending at revoke']) === 0, 'nothing written for the cancelled item');
    $undo = $svc->undo(7, $done['log_id'], $r->now + 3);
    $r->check($undo['status'] === 'reverted' && $r->row('contents', 100)['title'] === 'Draft A', 'owner with current edit permission can still undo the applied change');
    // 撤销依据的是后台当前权限：owner 失去 edit_article 后不能撤销。
    $internal = $svc->proposeInternal(7, 'key-revoked-0002', itemsUpdate(103, 'en', 'Internal EN'), $r->now)['items'][0]['item_id'];
    $done = $svc->apply(7, $internal, $r->now + 4);
    $r->db->execute('UPDATE ' . S103_PREFIX . 'roles SET permissions = ? WHERE id = 2', ['["ai_access"]']);
    $res = $r->attempt(fn() => $svc->undo(7, $done['log_id'], $r->now + 5));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_actor_denied' && $r->row('contents', 103)['title'] === 'Internal EN', 'owner who lost edit_article cannot undo; content unchanged');
};

$scenarios['10_draft_guarantee_and_no_leakage'] = static function (S103Run $r): void {
    $p = S103_PREFIX;
    $fingerprint = static fn(): string => hash('sha256', json_encode([
        $r->db->fetchAll("SELECT * FROM {$p}contents WHERE status <> 0 ORDER BY id"),
        $r->db->fetchAll("SELECT * FROM {$p}settings ORDER BY id"),
        $r->db->fetchAll("SELECT * FROM {$p}channels ORDER BY id"),
    ]));
    $publicBefore = $fingerprint();
    $svc = $r->svc();
    $body = '<p>' . S103_SECRET_MARKER . ' full body</p><script>alert(1)</script>';
    $prop = $svc->proposeFromToken(s103TokenContext(), 'key-guarantee-0001', [
        itemsCreate('Guaranteed draft', $body)[0], itemsUpdate(100)[0], itemsUpdate(103, 'en', 'EN draft')[0],
    ], $r->now);
    $results = $svc->applyMany(7, array_column($prop['items'], 'item_id'), $r->now);
    $r->check(count(array_filter($results, static fn(array $x): bool => $x['status'] === 'applied')) === 3, 'three items applied');
    $ids = array_column($results, 'content_id');
    $rows = $r->db->fetchAll("SELECT id, status, publish_time, content FROM {$p}contents WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')');
    $r->check(count($rows) === 3 && array_sum(array_map(static fn(array $x): int => (int) $x['status'] + (int) $x['publish_time'], $rows)) === 0, 'every applied row is status=0 with publish_time=0');
    $r->check($fingerprint() === $publicBefore, 'published contents, settings and channels are byte-identical after applying');
    $created = $r->row('contents', (int) $results[0]['content_id']);
    $r->check(!str_contains((string) $created['content'], '<script'), 'proposal HTML was sanitized before storage');

    // 失败路径：故障信息里带标记，但对外只出现稳定代码。
    $item = $svc->proposeFromToken(s103TokenContext(), 'key-guarantee-0002', itemsCreate('Leak probe', $body), $r->now)['items'][0]['item_id'];
    $trigger = $r->failOn('contents', 'INSERT');
    $res = $r->attempt(fn() => $svc->apply(7, $item, $r->now));
    $r->dropTrigger($trigger);
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_unavailable', 'database error text is not surfaced');
    $logText = json_encode($r->db->fetchAll("SELECT admin_name, ability, target, summary, input_json FROM {$p}ai_change_log"));
    $itemText = json_encode($r->db->fetchAll("SELECT status, status_reason FROM {$p}ai_proposal_items"));
    $r->check(!str_contains((string) $logText, S103_SECRET_MARKER) && !str_contains((string) $logText, 'full body'), 'change-log summary/target/input columns carry no body text');
    $r->check(!str_contains((string) $itemText, S103_SECRET_MARKER), 'item status/reason carry no body text');
    $all = implode("\n", $r->surfaced) . $logText . $itemText;
    $r->check(!str_contains($all, S103_TOKEN_PLAINTEXT) && !str_contains($all, hash('sha256', S103_TOKEN_PLAINTEXT)), 'no token plaintext or token digest in any surfaced error, worker output or log column');
    $r->check(!str_contains(implode("\n", $r->surfaced), S103_SECRET_MARKER), 'no body marker in any surfaced error or worker output');
};

// 输入政策负例（S1-01 A04/A05）：未知字段、状态字段、错误栏目/类型/语言。
$scenarios['11_input_policy_negatives'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $bad = [
        'status field' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 10, 'title' => 'x', 'content' => 'y', 'status' => 1]],
        'publish_time field' => [['op' => 'update', 'id' => 100, 'lang' => 'zh-CN', 'title' => 'x', 'publish_time' => 1]],
        'type field' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 10, 'title' => 'x', 'content' => 'y', 'type' => 'product']],
        'string id' => [['op' => 'update', 'id' => '100', 'lang' => 'zh-CN', 'title' => 'x']],
        'negative id' => [['op' => 'update', 'id' => -1, 'lang' => 'zh-CN', 'title' => 'x']],
        'over-long title' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 10, 'title' => str_repeat('t', 256), 'content' => 'y']],
        'nested value' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 10, 'title' => ['x'], 'content' => 'y']],
        'unknown op' => [['op' => 'publish', 'id' => 100, 'lang' => 'zh-CN']],
        'no fields' => [['op' => 'update', 'id' => 100, 'lang' => 'zh-CN']],
        'too many items' => array_fill(0, 11, itemsCreate()[0]),
    ];
    foreach ($bad as $label => $items) {
        $res = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-bad-' . md5($label), $items, $r->now));
        $r->check(!$res['ok'] && $res['code'] === 400, "rejects {$label} (400)");
    }
    $targets = [
        'page channel' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 12, 'title' => 'x', 'content' => 'y']],
        'disabled channel' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 13, 'title' => 'x', 'content' => 'y']],
        'missing channel' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 999, 'title' => 'x', 'content' => 'y']],
        'channel language mismatch' => [['op' => 'create', 'lang' => 'zh-CN', 'channel_id' => 11, 'title' => 'x', 'content' => 'y']],
        'not enabled language' => [['op' => 'create', 'lang' => 'ja', 'channel_id' => 10, 'title' => 'x', 'content' => 'y']],
        'product row as article' => itemsUpdate(102),
        'published article' => itemsUpdate(101),
        'language mismatch' => itemsUpdate(103, 'zh-CN'),
    ];
    foreach ($targets as $label => $items) {
        $res = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-target-' . md5($label), $items, $r->now));
        $r->check(!$res['ok'] && $res['code'] === 422, "rejects {$label} (422)");
    }
    $r->check($r->count('ai_proposals') === 0, 'no proposal row was written by any rejected input');
    $other = $svc->proposeFromToken(s103TokenContext(8, 19), 'key-isolation-0001', itemsCreate(), $r->now)['items'][0]['item_id'];
    $res = $r->attempt(fn() => $svc->apply(7, $other, $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_not_found', "another owner's proposal is invisible to apply");
};

// 12. S3：拒绝、待审配额（含并发）、定时清理。
$scenarios['12_reject_quota_and_prune'] = static function (S103Run $r): void {
    $svc = $r->svc();
    [$a, $b] = array_column($svc->proposeFromToken(s103TokenContext(), 'key-reject-0001', [itemsCreate('Reject me')[0], itemsCreate('Keep me')[0]], $r->now)['items'], 'item_id');
    $rejected = $svc->rejectMany(7, [$a], $r->now);
    $row = $r->item($a);
    $r->check($rejected[0]['status'] === 'rejected' && $row['status'] === 'rejected' && $row['status_reason'] === 'rejected_by_editor' && (int) $row['decided_by'] === 7,
        'reject marks only the chosen item rejected, records who and why');
    $r->check($r->item($b)['status'] === 'pending', 'the other item in the set stays pending');
    $r->check(($svc->reject(7, $a, $r->now)['replayed'] ?? false) === true, 'rejecting again replays the earlier result');
    $res = $r->attempt(fn() => $svc->apply(7, $a, $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_not_pending', 'a rejected item can never be applied');
    $res = $r->attempt(fn() => $svc->reject(8, $b, $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_not_found' && $r->item($b)['status'] === 'pending', 'another owner cannot reject the item');
    $applied = $svc->apply(7, $b, $r->now);
    $res = $r->attempt(fn() => $svc->reject(7, $b, $r->now));
    $r->check($applied['status'] === 'applied' && !$res['ok'] && $res['error'] === 'ai_proposal_not_pending', 'an applied item cannot be rejected (undo is the way back)');
    $r->check($r->count('contents', 'title = ?', ['Reject me']) === 0, 'the rejected item wrote no article');

    // 配额：本人已有 MAX-1 个待审项时，再提 2 项被拒且不留痕；另一账号不受影响。
    $p = S103_PREFIX;
    $r->db->execute("INSERT INTO {$p}ai_proposals (public_id, owner_id, source_ns, token_id, tool, idempotency_key, payload_hash, contract_version, created_at, expires_at)"
        . " VALUES (?, 7, 'token:9', 9, 'article_draft_propose', 'key-quota-fill', ?, 'article_draft.v1', ?, ?)", [str_repeat('f', 32), str_repeat('0', 64), $r->now, $r->now + 86400]);
    $fill = (int) $r->db->getPdo()->lastInsertId();
    $pendingNow = $r->count('ai_proposal_items', "owner_id = 7 AND status = 'pending'");
    for ($i = $pendingNow; $i < AiProposalService::MAX_PENDING_ITEMS - 1; $i++) {
        $r->db->execute("INSERT INTO {$p}ai_proposal_items (public_id, proposal_id, seq, owner_id, operation, target_id, lang, channel_id, input_json, input_hash, base_revision, contract_version, status, created_at)"
            . " VALUES (?, ?, ?, 7, 'create', 0, 'zh-CN', 10, '{}', ?, ?, 'article_draft.v1', 'pending', ?)", [bin2hex(random_bytes(16)), $fill, $i, str_repeat('0', 64), str_repeat('0', 64), $r->now]);
    }
    $before = $r->count('ai_proposals');
    $res = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-quota-0001', [itemsCreate('q1')[0], itemsCreate('q2')[0]], $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_quota_exceeded' && $res['code'] === 429 && $r->count('ai_proposals') === $before,
        'over the pending quota: 429 and nothing written');
    $r->check($r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(8, 19), 'key-quota-other', itemsCreate(), $r->now))['ok'], 'the quota is per account: another editor is unaffected');
    // 并发两个各 1 项的提案只剩 1 个名额：恰好一个成功。
    $out = $r->parallel([
        'q1' => ['action' => 'propose_token', 'key' => 'key-quota-race-1', 'items' => itemsCreate('race one')],
        'q2' => ['action' => 'propose_token', 'key' => 'key-quota-race-2', 'items' => itemsCreate('race two')],
    ]);
    $won = count(array_filter($out, static fn(array $o): bool => ($o['ok'] ?? false) === true));
    $lost = count(array_filter($out, static fn(array $o): bool => ($o['error'] ?? '') === 'ai_proposal_quota_exceeded'));
    $r->check($won === 1 && $lost === 1 && $r->count('ai_proposal_items', "owner_id = 7 AND status = 'pending'") === AiProposalService::MAX_PENDING_ITEMS,
        "two concurrent proposals for the last slot: exactly one fits (won {$won}, refused {$lost})");

    // 清理：过期待审 → expired；30 天前终止项删除；180 天前已应用项清空正文快照但保留状态；新的待审不动。
    $r->db->execute("DELETE FROM {$p}ai_proposal_items WHERE proposal_id = ?", [$fill]);
    $r->db->execute("DELETE FROM {$p}ai_proposal_items WHERE owner_id = 7 AND status = 'pending'");
    $old = $svc->proposeFromToken(s103TokenContext(), 'key-prune-old', itemsCreate('old pending'), $r->now - 700000)['items'][0]['item_id'];
    $fresh = $svc->proposeFromToken(s103TokenContext(), 'key-prune-new', itemsCreate('fresh pending'), $r->now)['items'][0]['item_id'];
    $r->db->execute("UPDATE {$p}ai_proposal_items SET decided_at = ? WHERE public_id = ?", [$r->now - 31 * 86400, $a]);
    $r->db->execute("UPDATE {$p}ai_proposal_items SET decided_at = ? WHERE public_id = ?", [$r->now - 181 * 86400, $b]);
    $r->db->execute("UPDATE {$p}ai_proposals SET created_at = ? WHERE id = ?", [$r->now - 31 * 86400, $fill]);
    $counts = $svc->prune($r->now);
    $r->check($r->item($old)['status'] === 'expired' && $r->item($old)['status_reason'] === 'proposal_expired', 'a pending item past its expiry is marked expired');
    $r->check($r->item($a) === null, 'a rejected item decided over 30 days ago is deleted');
    $kept = $r->item($b);
    $r->check($kept !== null && $kept['status'] === 'applied' && $kept['input_json'] === '' && (int) $kept['change_log_id'] > 0,
        'an applied item past 180 days keeps its status and change-log link but drops the body snapshot');
    $r->check($r->row('ai_proposals', $fill) === null, 'an old proposal set with no items left is deleted');
    $r->check($r->item($fresh)['status'] === 'pending', 'recent pending items are untouched');
    $r->check($counts['expired'] === 1 && $counts['purged'] === 2 && $counts['cleared'] === 1, 'prune reports what it did (' . json_encode($counts) . ')');
    $r->check($svc->prune($r->now) === ['expired' => 0, 'purged' => 0, 'cleared' => 0], 'a second prune finds nothing left to do');
    $r->check(($svc->undo(7, (int) $kept['change_log_id'], $r->now)['status'] ?? '') === 'reverted', 'undo still works after the body snapshot was cleared (it uses the change log)');

    // 老站 enabled_languages 为空时与 enabledLanguages() 一样不按语言限制，但栏目语言仍须一致。
    $r->db->execute("UPDATE {$p}settings SET `value` = '' WHERE `key` = 'enabled_languages'");
    $ok = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-lang-empty-1', [['op' => 'create', 'lang' => 'en', 'channel_id' => 11, 'title' => 'x', 'content' => 'y']], $r->now));
    $mismatch = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-lang-empty-2', [['op' => 'create', 'lang' => 'ja', 'channel_id' => 11, 'title' => 'x', 'content' => 'y']], $r->now));
    $r->check($ok['ok'] && !$mismatch['ok'] && $mismatch['code'] === 422, 'empty enabled_languages: any language is allowed, but the channel language must still match');
};

// 13. 文章历史版本：应用修改前留一版，撤销前把 AI 版本也留下；历史写失败整条回滚；恢复旧版后 AI 撤销被拒。
$scenarios['13_article_history'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $p = S103_PREFIX;
    $revisions = static fn(int $id): array => $r->db->fetchAll("SELECT * FROM {$p}content_revisions WHERE target_type = 'article' AND target_id = ? ORDER BY id", [$id]);
    $original = $r->row('contents', 100);

    $created = $svc->apply(7, $svc->proposeFromToken(s103TokenContext(), 'key-history-new', itemsCreate('History new'), $r->now)['items'][0]['item_id'], $r->now);
    $r->check($revisions((int) $created['content_id']) === [], 'a newly created draft has no earlier version to record');

    $item = $svc->proposeFromToken(s103TokenContext(), 'key-history-0001', itemsUpdate(100, 'zh-CN', 'History title'), $r->now)['items'][0]['item_id'];
    $trigger = $r->failOn('content_revisions', 'INSERT');
    $failed = $r->attempt(fn() => $svc->apply(7, $item, $r->now));
    $r->dropTrigger($trigger);
    $r->check(!$failed['ok'] && $r->item($item)['status'] === 'pending' && $r->row('contents', 100)['title'] === $original['title']
        && $r->count('ai_change_log', 'proposal_item_id = ?', [(int) $r->item($item)['id']]) === 0,
        'a failed history write rolls the whole apply back (no silent half-saved state)');

    $applied = $svc->apply(7, $item, $r->now);
    $rows = $revisions(100);
    $snap = json_decode((string) ($rows[0]['snapshot'] ?? ''), true);
    $fields = $snap['targets'][0]['fields'] ?? [];
    $r->check(count($rows) === 1 && $fields['title'] === $original['title'] && $fields['content'] === $original['content'] && $rows[0]['admin_name'] === 's103-editor · AI',
        'applying an update stores the previous title and body as a history version, marked as an AI change');

    $undo = $svc->undo(7, (int) $applied['log_id'], $r->now);
    $rows = $revisions(100);
    $aiSnap = json_decode((string) ($rows[1]['snapshot'] ?? ''), true)['targets'][0]['fields'] ?? [];
    $r->check($undo['status'] === 'reverted' && count($rows) === 2 && $aiSnap['title'] === 'History title' && $r->row('contents', 100)['title'] === $original['title'],
        'undo stores the AI version as a history version first, so it stays recoverable');

    // 恢复历史版本属于人工修改：之后再撤销 AI 改动会被拒，不覆盖恢复结果。
    $second = $svc->apply(7, $svc->proposeFromToken(s103TokenContext(), 'key-history-0002', itemsUpdate(100, 'zh-CN', 'Second AI title'), $r->now)['items'][0]['item_id'], $r->now);
    $restoreFrom = $revisions(100);
    contentRevisionModel()->restoreRevision((int) end($restoreFrom)['id'], 1, 's103-admin');
    $res = $r->attempt(fn() => $svc->undo(7, (int) $second['log_id'], $r->now));
    $r->check(!$res['ok'] && $res['error'] === 'ai_proposal_undo_conflict' && $r->row('contents', 100)['title'] !== 'Second AI title',
        'after a history restore the AI undo is refused (the restore counts as a later edit)');
    $r->check(count($revisions(100)) <= 5, 'history keeps at most the configured number of versions');
};

// S4：幂等重试必须先于新请求的配额和目标校验。
$scenarios['14_replay_before_new_work'] = static function (S103Run $r): void {
    $svc = $r->svc();
    $p = S103_PREFIX;
    $items = itemsUpdate(100);
    $first = $svc->proposeFromToken(s103TokenContext(), 'key-s4-retry-target', $items, $r->now);
    $r->db->execute("UPDATE {$p}contents SET status = 1 WHERE id = 100");
    $retry = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-s4-retry-target', $items, $r->now));
    $r->check($retry['ok'] && $retry['result']['replayed'] && $retry['result']['proposal_id'] === $first['proposal_id'], 'same request replays after target publication without creating or writing content');
    $different = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-s4-retry-target', itemsUpdate(100, 'zh-CN', 'different'), $r->now));
    $r->check(!$different['ok'] && $different['error'] === 'ai_proposal_idempotency_conflict', 'changed payload under the same key conflicts even after the target changed');
    $r->reset();
    for ($i = 0; $i < 20; $i++) {
        $batch = array_fill(0, 10, itemsCreate('quota batch')[0]);
        $last = $svc->proposeFromToken(s103TokenContext(), 'key-s4-full-' . $i, $batch, $r->now);
    }
    $retry = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-s4-full-19', $batch, $r->now));
    $r->check($retry['ok'] && $retry['result']['replayed'] && $retry['result']['proposal_id'] === $last['proposal_id'], 'a full queue still replays an accepted request');
    $different = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-s4-full-19', itemsCreate('different'), $r->now));
    $r->check(!$different['ok'] && $different['error'] === 'ai_proposal_idempotency_conflict', 'reused key with different input returns conflict rather than quota exceeded');
    $new = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-s4-full-new', itemsCreate(), $r->now));
    $r->check(!$new['ok'] && $new['error'] === 'ai_proposal_quota_exceeded' && $r->count('ai_proposal_items') === 200, 'new requests enforce the quota and retries write no extra items');
    $r->db->execute("UPDATE {$p}api_tokens SET revoked_at = ? WHERE id = 9", [$r->now]);
    $denied = $r->attempt(fn() => $svc->proposeFromToken(s103TokenContext(), 'key-s4-full-19', $batch, $r->now));
    $r->check(!$denied['ok'] && $denied['error'] === 'ai_proposal_source_denied', 'replay still refuses a revoked source token');
};

// 运行
$out = null;
foreach ($argv as $arg) if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
$only = getenv('YK_S103_ONLY') ?: '';
foreach ($scenarios as $name => $fn) {
    if ($only !== '' && !str_contains($name, $only)) continue;
    $run->scenario = $name;
    $run->results[$name] = [];
    try {
        $run->reset();
        $fn($run);
    } catch (Throwable $e) {
        $run->check(false, 'scenario aborted: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
$version = (string) $run->db->fetchColumn($run->db->isSqlite() ? 'SELECT sqlite_version()' : 'SELECT VERSION()');
$failed = 0;
$total = 0;
$report = ['driver' => DB_DRIVER, 'server_version' => $version, 'php' => PHP_VERSION, 'scenarios' => []];
foreach ($run->results as $name => $checks) {
    foreach ($checks as [$ok, $label]) {
        $total++;
        if (!$ok) $failed++;
        $report['scenarios'][$name][] = ($ok ? 'PASS ' : 'FAIL ') . $label;
    }
}
$report['assertions'] = $total;
$report['failed'] = $failed;
s103ResetSchema($run->db);
foreach (array_merge(S103_PROTOTYPE_TABLES, S103_CORE_TABLES) as $table) $run->db->execute('DROP TABLE IF EXISTS ' . S103_PREFIX . $table);
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($out !== null) file_put_contents($out, $json . "\n");
echo $json, "\n";
exit($failed === 0 && $total > 0 ? 0 : 1);
