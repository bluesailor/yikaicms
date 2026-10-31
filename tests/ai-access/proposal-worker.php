<?php
/**
 * S1-03 并发子进程：独立 PHP 进程、独立数据库连接，执行一个动作后输出 JSON。
 * 只由 proposal-txn-run.php 调用；连接参数同 proposal-fixture.php，动作参数经 argv[1] 的 JSON 传入。
 *
 * hold：到达指定检查点（服务内）或取锁后（外部写入方）先写信号文件，再等待 hold_ms，
 * 用来确定性地制造“谁先取得锁”的交错，而不是依赖碰运气的时序。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';

// base64 传参：Windows 的 proc_open 参数转义对 JSON 引号不可靠。
$args = json_decode((string) base64_decode((string) ($argv[1] ?? ''), true), true, 16, JSON_THROW_ON_ERROR);
$db = s103Boot();
$p = S103_PREFIX;
$signal = static function () use ($args): void {
    if (($args['signal'] ?? '') !== '') file_put_contents($args['signal'], (string) microtime(true));
};
$hold = static function () use ($args, $signal): void {
    $signal();
    usleep((int) ($args['hold_ms'] ?? 0) * 1000);
};
// 共同起跑线：所有子进程都准备好连接后才同时开始。
if (($args['barrier'] ?? '') !== '') {
    $deadline = microtime(true) + 20;
    while (!is_file($args['barrier']) && microtime(true) < $deadline) usleep(2000);
}
$checkpoint = static function (string $point) use ($args, $hold): void {
    if ($point === ($args['hold_point'] ?? '')) $hold();
};
$service = new AiProposalService($db, Closure::fromCallable($checkpoint));
$now = (int) $args['now'];
$started = microtime(true);
$out = ['ok' => true];
try {
    $out['result'] = match ($args['action']) {
        'propose_token' => $service->proposeFromToken(s103TokenContext((int) ($args['owner'] ?? 7), (int) ($args['token'] ?? 9)), (string) $args['key'], $args['items'], $now),
        'propose_internal' => $service->proposeInternal((int) $args['owner'], (string) $args['key'], $args['items'], $now),
        'apply' => $service->apply((int) $args['admin'], (string) $args['item'], $now),
        'reject' => $service->reject((int) $args['admin'], (string) $args['item'], $now),
        'undo' => $service->undo((int) $args['admin'], (int) $args['log'], $now),
        // 外部变更方：按 ADR 只锁自己的一行；取锁后持有，模拟“先提交/后提交”。
        'revoke_token', 'disable_user', 'demote_role', 'human_edit' => (static function () use ($db, $p, $args, $now, $hold): array {
            $db->beginTransaction();
            try {
                match ($args['action']) {
                    'revoke_token' => $db->execute("UPDATE {$p}api_tokens SET revoked_at = ? WHERE id = ?", [$now, (int) $args['token']]),
                    'disable_user' => $db->execute("UPDATE {$p}users SET status = 0 WHERE id = ?", [(int) $args['user']]),
                    'demote_role' => $db->execute("UPDATE {$p}roles SET permissions = ? WHERE id = ?", ['["ai_access"]', (int) $args['role']]),
                    'human_edit' => $db->execute("UPDATE {$p}contents SET content = ?, updated_at = updated_at + 1 WHERE id = ?", [(string) $args['content'], (int) $args['id']]),
                };
                $hold();
                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                throw $error;
            }
            return ['committed' => true];
        })(),
        // S5-04 编辑器保存的同等行为（BloxDocumentWriteLock::channel）：锁栏目行 → 核对修订号 → 写草稿；修订号已变就不写。
        'editor_save_page' => (static function () use ($db, $p, $args, $hold): array {
            $db->beginTransaction();
            try {
                $id = (int) $args['page'];
                if ($db->isSqlite()) $db->execute("UPDATE {$p}channels SET id = id WHERE id = ?", [$id]);
                else $db->fetchOne("SELECT id FROM {$p}channels WHERE id = ? FOR UPDATE", [$id]);
                $source = new AiPageDocumentSource($db, true);
                $current = $source->load((array) $source->page($id))['base_revision'];
                $conflict = !hash_equals((string) $args['base'], $current);
                if (!$conflict) $db->execute("UPDATE {$p}blox_page_drafts SET draft_data = ?, updated_at = updated_at + 1 WHERE page_id = ?", [(string) $args['json'], $id]);
                $hold();
                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                throw $error;
            }
            return ['committed' => !$conflict, 'conflict' => $conflict];
        })(),
    };
} catch (RuntimeException $error) {
    $out = ['ok' => false, 'error' => $error->getMessage(), 'code' => $error->getCode()];
} catch (Throwable $error) {
    // 外部写入方的原始数据库错误只报类型与 SQLSTATE，不回显语句参数。
    $out = ['ok' => false, 'error' => get_class($error), 'code' => (string) $error->getCode()];
}
$out['started'] = $started;
$out['finished'] = microtime(true);
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
