<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';
require_once __DIR__ . '/AiArticleDraftRules.php';
require_once dirname(__DIR__) . '/models/autoload.php';

/**
 * 文章草稿提案的唯一事务所有者（ADR-002）：提案写入、应用、撤销各开恰好一个事务。
 * 锁顺序固定为 令牌 → 用户 → 角色 → 提案项 → 变更日志 → 目标文章/栏目。
 * 原型依赖预建表；本类不建表、不调用模型生成、不访问网络。
 */
final class AiProposalService
{
    public const TOOL = 'article_draft_propose';
    private const TTL = 604800;
    /** 每个账号同时待审的提案项上限：外部客户端不能用提案把审核队列和数据库撑满。 */
    public const MAX_PENDING_ITEMS = 200;
    /** 拒绝/过期/冲突/取消的提案项保留 30 天后删除；已应用、已撤销的保留状态，正文快照 180 天后清空。 */
    private const PURGE_AFTER = 2592000;
    private const SNAPSHOT_KEEP = 15552000;
    private const PRUNE_BATCH = 500;

    /** @param (Closure(string):void)|null $checkpoint 仅供测试在固定位置观测/制造并发与故障 */
    public function __construct(private Database $db, private ?Closure $checkpoint = null)
    {
    }

    /** 外部来源：身份由 S1-02 的 AuthContext 提供，owner 与 token 都来自服务端。 */
    public function proposeFromToken(AiAccessAuthContext $context, string $idempotencyKey, array $items, int $now): array
    {
        return $this->propose(['owner' => $context->actorId, 'token' => $context->tokenId, 'ns' => 'token:' . $context->tokenId], $idempotencyKey, $items, $now);
    }

    /** 内部来源（后台 Agent）：没有令牌，命名空间仍为非空的 internal。 */
    public function proposeInternal(int $ownerId, string $idempotencyKey, array $items, int $now): array
    {
        return $this->propose(['owner' => $ownerId, 'token' => 0, 'ns' => 'internal'], $idempotencyKey, $items, $now);
    }

    /** @param array{owner:int,token:int,ns:string} $source */
    private function propose(array $source, string $idempotencyKey, array $items, int $now): array
    {
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1 || $source['owner'] < 1) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        // 规范化、净化、哈希都在事务外完成。
        $normalized = AiArticleDraftRules::normalizeItems($items);
        $hash = AiArticleDraftRules::payloadHash(self::TOOL, $normalized);
        try {
            return $this->transaction(function () use ($source, $idempotencyKey, $normalized, $hash, $now): array {
                $verdict = $this->lockSource($source['owner'], $source['token'], $now);
                $this->check('propose.locked');
                if ($verdict !== null) throw new RuntimeException('ai_proposal_source_denied', 403);
                // 用户行已锁住，同账号的提案串行。重试只返回已接受的结果：不能因队列已满、
                // 目标后来发布或删除而变成一次失败的新请求；来源权限仍须先按当前值重验。
                $existing = $this->db->fetchOne(
                    'SELECT id, public_id, payload_hash FROM ' . DB_PREFIX . 'ai_proposals WHERE owner_id = ? AND source_ns = ? AND tool = ? AND idempotency_key = ?',
                    [$source['owner'], $source['ns'], self::TOOL, $idempotencyKey]
                );
                if ($existing !== null) return $this->replay($existing, $hash);
                // 配额在锁住本账号用户行之后数：并发提案按账号串行，数到的一定含对方刚写入的。
                $pending = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "ai_proposal_items WHERE owner_id = ? AND status = 'pending'", [$source['owner']]);
                if ($pending + count($normalized) > self::MAX_PENDING_ITEMS) throw new RuntimeException('ai_proposal_quota_exceeded', 429);
                $bases = [];
                foreach ($normalized as $item) $bases[] = $this->proposalBase($item);
                $setPublic = bin2hex(random_bytes(16));
                $this->db->execute(
                    'INSERT INTO ' . DB_PREFIX . 'ai_proposals (public_id, owner_id, source_ns, token_id, tool, idempotency_key, payload_hash, contract_version, created_at, expires_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$setPublic, $source['owner'], $source['ns'], $source['token'], self::TOOL, $idempotencyKey, $hash, AiArticleDraftRules::CONTRACT, $now, $now + self::TTL]
                );
                $setId = (int) $this->db->getPdo()->lastInsertId();
                $out = [];
                foreach ($normalized as $seq => $item) {
                    $itemPublic = bin2hex(random_bytes(16));
                    $json = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $this->db->execute(
                        'INSERT INTO ' . DB_PREFIX . 'ai_proposal_items (public_id, proposal_id, seq, owner_id, operation, target_id, lang, channel_id, input_json, input_hash, base_revision, contract_version, status, created_at)'
                        . " VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                        [$itemPublic, $setId, $seq, $source['owner'], $item['op'], (int) ($item['id'] ?? 0), $item['lang'], (int) ($item['channel_id'] ?? 0),
                            $json, hash('sha256', $json), $bases[$seq], AiArticleDraftRules::CONTRACT, $now]
                    );
                    $out[] = ['item_id' => $itemPublic, 'op' => $item['op'], 'status' => 'pending'];
                }
                return ['proposal_id' => $setPublic, 'items' => $out, 'replayed' => false];
            });
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $this->infrastructure($error);
            // 唯一约束判重：同键同载荷返回原结果，同键不同载荷拒绝。
            $existing = $this->db->fetchOne(
                'SELECT id, public_id, payload_hash FROM ' . DB_PREFIX . 'ai_proposals WHERE owner_id = ? AND source_ns = ? AND tool = ? AND idempotency_key = ?',
                [$source['owner'], $source['ns'], self::TOOL, $idempotencyKey]
            );
            if ($existing === null) throw $this->infrastructure($error);
            return $this->replay($existing, $hash);
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
    }

    private function replay(array $existing, string $hash): array
    {
        if (!hash_equals((string) $existing['payload_hash'], $hash)) throw new RuntimeException('ai_proposal_idempotency_conflict', 409);
        $rows = $this->db->fetchAll('SELECT public_id, operation, status FROM ' . DB_PREFIX . 'ai_proposal_items WHERE proposal_id = ? ORDER BY seq', [(int) $existing['id']]);
        return ['proposal_id' => (string) $existing['public_id'], 'replayed' => true,
            'items' => array_map(static fn(array $r): array => ['item_id' => (string) $r['public_id'], 'op' => (string) $r['operation'], 'status' => (string) $r['status']], $rows)];
    }

    /**
     * 后台按项目应用：每项独立事务，未选项不动，单项失败不影响其他项。
     * @param list<string> $itemIds
     * @return list<array<string,mixed>>
     */
    public function applyMany(int $adminId, array $itemIds, int $now): array
    {
        $results = [];
        foreach ($itemIds as $itemId) {
            try {
                $results[] = $this->apply($adminId, $itemId, $now);
            } catch (RuntimeException $error) {
                $results[] = ['item_id' => $itemId, 'status' => 'error', 'error' => $error->getMessage(), 'code' => $error->getCode()];
            }
        }
        return $results;
    }

    public function apply(int $adminId, string $itemId, int $now): array
    {
        $pre = $this->db->fetchOne('SELECT i.id, i.owner_id, p.token_id, p.source_ns FROM ' . DB_PREFIX . 'ai_proposal_items i JOIN '
            . DB_PREFIX . 'ai_proposals p ON p.id = i.proposal_id WHERE i.public_id = ?', [$itemId]);
        // 他人提案与不存在同样处理，不泄漏存在性。
        if ($pre === null || (int) $pre['owner_id'] !== $adminId) throw new RuntimeException('ai_proposal_not_found', 404);
        $logId = 0;
        try {
            return $this->transaction(function () use ($pre, $itemId, $now, &$logId): array {
                $verdict = $this->lockSource((int) $pre['owner_id'], (int) $pre['token_id'], $now);
                $item = $this->lock('ai_proposal_items', (int) $pre['id']);
                $this->check('apply.locked');
                if ($item === null || (int) $item['owner_id'] !== (int) $pre['owner_id']) throw new RuntimeException('ai_proposal_not_found', 404);
                if ($item['status'] === 'applied') return $this->itemResult($item, true);
                if ($item['status'] !== 'pending') throw new RuntimeException('ai_proposal_not_pending', 409);
                $set = $this->db->fetchOne('SELECT expires_at FROM ' . DB_PREFIX . 'ai_proposals WHERE id = ?', [(int) $item['proposal_id']]);
                if ($set === null || (int) $set['expires_at'] <= $now) return $this->decide($item, 'expired', 'proposal_expired', $now);
                if ($verdict !== null) {
                    // 令牌失效是永久的：终止该项。账号/角色/开关问题可恢复：保持 pending 并记录原因。
                    return str_starts_with($verdict, 'source_token_')
                        ? $this->decide($item, 'cancelled', $verdict, $now)
                        : $this->decide($item, 'pending', $verdict, $now);
                }
                $input = json_decode((string) $item['input_json'], true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($input) || !hash_equals((string) $item['input_hash'], hash('sha256', (string) $item['input_json']))
                    || $item['contract_version'] !== AiArticleDraftRules::CONTRACT) {
                    return $this->decide($item, 'conflicted', 'contract_changed', $now);
                }
                $write = $input['op'] === 'create' ? $this->applyCreate($item, $input, $now) : $this->applyUpdate($item, $input, $now);
                if (isset($write['conflict'])) return $this->decide($item, 'conflicted', $write['conflict'], $now);
                $this->check('apply.before_log');
                $owner = $this->db->fetchOne('SELECT username FROM ' . DB_PREFIX . 'users WHERE id = ?', [(int) $item['owner_id']]);
                $this->db->execute(
                    'INSERT INTO ' . DB_PREFIX . 'ai_change_log (admin_id, admin_name, ability, target, summary, before_json, input_json, created_at, undone, undone_at,'
                    . ' proposal_item_id, source_ns, target_type, target_id, operation, after_revision, undone_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, 0)',
                    [(int) $item['owner_id'], (string) ($owner['username'] ?? ''), self::TOOL, 'contents#' . $write['id'],
                        mb_substr($input['op'] . ': ' . $write['title'], 0, 200),
                        $write['before'] === null ? null : json_encode($write['before'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        json_encode(['proposal_item' => $itemId], JSON_THROW_ON_ERROR), $now,
                        (int) $item['id'], (string) $pre['source_ns'], 'article', $write['id'], $input['op'], $write['after']]
                );
                $logId = (int) $this->db->getPdo()->lastInsertId();
                $this->db->execute(
                    'UPDATE ' . DB_PREFIX . "ai_proposal_items SET status = 'applied', status_reason = '', result_content_id = ?, result_revision = ?, change_log_id = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'",
                    [$write['id'], $write['after'], $logId, (int) $item['owner_id'], $now, (int) $item['id']]
                );
                $this->check('apply.before_commit');
                return ['item_id' => $itemId, 'status' => 'applied', 'reason' => '', 'replayed' => false, 'content_id' => $write['id'],
                    'revision' => $write['after'], 'log_id' => $logId, 'post_commit_error' => false];
            });
        } catch (Throwable $error) {
            // 提交后的回调失败不等于未保存：本次写下的日志已在项目上，就如实返回已提交结果。
            if ($logId > 0) {
                $after = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'ai_proposal_items WHERE id = ?', [(int) $pre['id']]);
                if ($after !== null && $after['status'] === 'applied' && (int) $after['change_log_id'] === $logId) {
                    return ['post_commit_error' => true] + $this->itemResult($after, false);
                }
            }
            throw $this->infrastructure($error);
        }
    }

    /**
     * 后台拒绝：每项独立事务，只把本人的 pending 项改为 rejected；重复拒绝返回原结果。
     * 拒绝是收回动作，不要求来源令牌或编辑权限仍然有效。
     * @param list<string> $itemIds
     * @return list<array<string,mixed>>
     */
    public function rejectMany(int $adminId, array $itemIds, int $now): array
    {
        $results = [];
        foreach ($itemIds as $itemId) {
            try {
                $results[] = $this->reject($adminId, $itemId, $now);
            } catch (RuntimeException $error) {
                $results[] = ['item_id' => $itemId, 'status' => 'error', 'error' => $error->getMessage(), 'code' => $error->getCode()];
            }
        }
        return $results;
    }

    public function reject(int $adminId, string $itemId, int $now): array
    {
        $pre = $this->db->fetchOne('SELECT id, owner_id FROM ' . DB_PREFIX . 'ai_proposal_items WHERE public_id = ?', [$itemId]);
        if ($pre === null || (int) $pre['owner_id'] !== $adminId) throw new RuntimeException('ai_proposal_not_found', 404);
        try {
            return $this->transaction(function () use ($pre, $adminId, $now): array {
                $item = $this->lock('ai_proposal_items', (int) $pre['id']);
                if ($item === null || (int) $item['owner_id'] !== $adminId) throw new RuntimeException('ai_proposal_not_found', 404);
                if ($item['status'] === 'rejected') return $this->itemResult($item, true);
                if ($item['status'] !== 'pending') throw new RuntimeException('ai_proposal_not_pending', 409);
                $this->db->execute(
                    'UPDATE ' . DB_PREFIX . "ai_proposal_items SET status = 'rejected', status_reason = 'rejected_by_editor', decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'",
                    [$adminId, $now, (int) $item['id']]
                );
                return ['item_id' => (string) $item['public_id'], 'status' => 'rejected', 'reason' => 'rejected_by_editor', 'replayed' => false,
                    'content_id' => 0, 'revision' => '', 'log_id' => 0, 'post_commit_error' => false];
            });
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
    }

    /**
     * 定时清理（cron，每批短语句、整轮有时间上限）：过期的待审项标为 expired；已终止且未应用的项
     * 30 天后删除；已应用/已撤销项 180 天后清空正文快照（状态、结果、变更日志关联保留）。
     * @return array{expired:int,purged:int,cleared:int}
     */
    public function prune(int $now, float $budgetSeconds = 10.0): array
    {
        $p = DB_PREFIX;
        $deadline = microtime(true) + $budgetSeconds;
        $batch = function (string $select, array $params, string $write, array $writeParams) use ($deadline): int {
            $total = 0;
            do {
                $ids = array_map('intval', array_column($this->db->fetchAll($select . ' LIMIT ' . self::PRUNE_BATCH, $params), 'id'));
                if ($ids === []) break;
                $total += $this->db->execute(str_replace('{ids}', implode(',', $ids), $write), $writeParams);
            } while (count($ids) === self::PRUNE_BATCH && microtime(true) < $deadline);
            return $total;
        };
        $expired = $batch(
            "SELECT i.id FROM {$p}ai_proposal_items i JOIN {$p}ai_proposals s ON s.id = i.proposal_id WHERE i.status = 'pending' AND s.expires_at <= ? ORDER BY i.id",
            [$now],
            "UPDATE {$p}ai_proposal_items SET status = 'expired', status_reason = 'proposal_expired', decided_at = ? WHERE id IN ({ids}) AND status = 'pending'",
            [$now]
        );
        $purged = $batch(
            "SELECT id FROM {$p}ai_proposal_items WHERE status IN ('rejected', 'expired', 'cancelled', 'conflicted') AND decided_at > 0 AND decided_at < ? ORDER BY id",
            [$now - self::PURGE_AFTER],
            "DELETE FROM {$p}ai_proposal_items WHERE id IN ({ids}) AND status IN ('rejected', 'expired', 'cancelled', 'conflicted')",
            []
        );
        // 项目都删光的提案集一并删除（幂等键随之释放，此时距提交已超过 30 天）。
        $purged += $batch(
            "SELECT s.id FROM {$p}ai_proposals s WHERE s.created_at < ? AND NOT EXISTS (SELECT 1 FROM {$p}ai_proposal_items i WHERE i.proposal_id = s.id) ORDER BY s.id",
            [$now - self::PURGE_AFTER],
            "DELETE FROM {$p}ai_proposals WHERE id IN ({ids})",
            []
        );
        $cleared = $batch(
            "SELECT id FROM {$p}ai_proposal_items WHERE status IN ('applied', 'reverted') AND decided_at > 0 AND decided_at < ? AND input_json <> '' ORDER BY id",
            [$now - self::SNAPSHOT_KEEP],
            "UPDATE {$p}ai_proposal_items SET input_json = '' WHERE id IN ({ids})",
            []
        );
        return ['expired' => $expired, 'purged' => $purged, 'cleared' => $cleared];
    }

    /** 撤销依据后台当前身份；不检查来源令牌，也不因此授予令牌任何权限。 */
    public function undo(int $adminId, int $logId, int $now): array
    {
        $pre = $this->db->fetchOne('SELECT id, admin_id, proposal_item_id FROM ' . DB_PREFIX . 'ai_change_log WHERE id = ?', [$logId]);
        if ($pre === null || (int) $pre['admin_id'] !== $adminId || (int) $pre['proposal_item_id'] < 1) throw new RuntimeException('ai_proposal_not_found', 404);
        try {
            return $this->transaction(function () use ($adminId, $pre, $now): array {
                $verdict = $this->lockSource($adminId, 0, $now);
                $item = $this->lock('ai_proposal_items', (int) $pre['proposal_item_id']);
                $log = $this->lock('ai_change_log', (int) $pre['id']);
                $this->check('undo.locked');
                if ($verdict !== null) throw new RuntimeException('ai_proposal_actor_denied', 403);
                if ($item === null || $log === null) throw new RuntimeException('ai_proposal_not_found', 404);
                if ((int) $log['undone'] === 1) return ['log_id' => (int) $log['id'], 'status' => 'reverted', 'replayed' => true];
                $contentId = (int) $log['target_id'];
                $content = $this->lock('contents', $contentId);
                // 只有目标仍等于应用后的版本才回退；之后的人工编辑、发布、删除一律不覆盖。
                if ($content === null || !hash_equals((string) $log['after_revision'], AiArticleDraftRules::contentFingerprint($content))) {
                    throw new RuntimeException('ai_proposal_undo_conflict', 409);
                }
                if ($log['operation'] === 'create') {
                    contentModel()->deleteById($contentId); // 软删除进回收站，可恢复
                } else {
                    $before = json_decode((string) $log['before_json'], true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($before)) throw new RuntimeException('ai_proposal_undo_conflict', 409);
                    $restore = array_intersect_key($before, array_flip(AiArticleDraftRules::EDITABLE));
                    // 撤销会覆盖 AI 写入的版本：先把它存进历史版本，撤销后仍可找回。
                    $this->recordRevision($content, array_intersect_key($content, $restore), $adminId);
                    contentModel()->updateById($contentId, $restore + ['updated_at' => $now]);
                }
                $this->db->execute('UPDATE ' . DB_PREFIX . 'ai_change_log SET undone = 1, undone_at = ?, undone_by = ? WHERE id = ? AND undone = 0', [$now, $adminId, (int) $log['id']]);
                $this->db->execute('UPDATE ' . DB_PREFIX . "ai_proposal_items SET status = 'reverted', status_reason = 'undone' WHERE id = ? AND status = 'applied'", [(int) $item['id']]);
                return ['log_id' => (int) $log['id'], 'status' => 'reverted', 'replayed' => false];
            });
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
    }

    /** @return array{id:int,title:string,before:?array,after:string}|array{conflict:string} */
    private function applyCreate(array $item, array $input, int $now): array
    {
        $channel = $this->lock('channels', (int) $input['channel_id']);
        if (!AiArticleDraftRules::channelAcceptsDraft($channel, (string) $input['lang'])) return ['conflict' => 'channel_invalid'];
        if (!hash_equals((string) $item['base_revision'], AiArticleDraftRules::channelFingerprint($channel))) return ['conflict' => 'channel_changed'];
        if (!AiArticleDraftRules::languageEnabled($this->db, (string) $input['lang'])) return ['conflict' => 'language_disabled'];
        $fields = array_intersect_key($input, array_flip(AiArticleDraftRules::EDITABLE));
        $id = (int) contentModel()->create($fields + [
            'lang' => $input['lang'], 'channel_id' => $input['channel_id'], 'type' => 'article',
            'status' => 0, 'publish_time' => 0, 'created_at' => $now, 'updated_at' => $now, 'admin_id' => (int) $item['owner_id'],
        ]);
        $row = $this->lock('contents', $id);
        if ($row === null) throw new RuntimeException('ai_proposal_unavailable', 503);
        return ['id' => $id, 'title' => (string) $row['title'], 'before' => null, 'after' => AiArticleDraftRules::contentFingerprint($row)];
    }

    /** @return array{id:int,title:string,before:?array,after:string}|array{conflict:string} */
    private function applyUpdate(array $item, array $input, int $now): array
    {
        $id = (int) $input['id'];
        $row = $this->lock('contents', $id);
        if ($row === null || $row['deleted_at'] !== null) return ['conflict' => 'target_deleted'];
        if (!hash_equals((string) $item['base_revision'], AiArticleDraftRules::contentFingerprint($row))) {
            return ['conflict' => (int) $row['status'] !== 0 ? 'target_published' : 'target_changed'];
        }
        if (!AiArticleDraftRules::contentAcceptsUpdate($row, (string) $input['lang'])) return ['conflict' => 'target_not_draft'];
        if (!AiArticleDraftRules::languageEnabled($this->db, (string) $input['lang'])) return ['conflict' => 'language_disabled'];
        $fields = array_intersect_key($input, array_flip(AiArticleDraftRules::EDITABLE));
        $before = array_intersect_key($row, $fields);
        // 编辑页「历史版本」里留一版改动前的正文（同一事务，失败整条回滚）。
        $this->recordRevision($row, $before, (int) $item['owner_id']);
        contentModel()->updateById($id, $fields + ['updated_at' => $now]);
        $after = $this->lock('contents', $id);
        if ($after === null) throw new RuntimeException('ai_proposal_unavailable', 503);
        return ['id' => $id, 'title' => (string) $after['title'], 'before' => $before, 'after' => AiArticleDraftRules::contentFingerprint($after)];
    }

    /**
     * 写一版文章历史（编辑页「历史版本」面板读取的 content_revisions）。操作人记为「账号 · AI」，
     * 与人工保存区分。用会抛错的 recordStrict：失败让整个应用/撤销回滚，而不是在事务外继续。
     * @param array<string,mixed> $row 当前文章行 @param array<string,mixed> $fields 要存档的字段
     */
    private function recordRevision(array $row, array $fields, int $actorId): void
    {
        if ($fields === []) return;
        $name = (string) ($this->db->fetchColumn('SELECT username FROM ' . DB_PREFIX . 'users WHERE id = ?', [$actorId]) ?: '');
        contentRevisionModel()->recordStrict('article', (int) $row['id'], (string) $row['lang'],
            [['table' => 'contents', 'id' => (int) $row['id'], 'fields' => $fields]], (string) $row['title'], $actorId, $name . ' · AI');
    }

    /** 提案时的基线：更新取目标文章指纹，新建取栏目指纹。 */
    private function proposalBase(array $item): string
    {
        if (!AiArticleDraftRules::languageEnabled($this->db, (string) $item['lang'])) throw new RuntimeException('ai_proposal_invalid_target', 422);
        if ($item['op'] === 'create') {
            $channel = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'channels WHERE id = ?', [(int) $item['channel_id']]);
            if (!AiArticleDraftRules::channelAcceptsDraft($channel, (string) $item['lang'])) throw new RuntimeException('ai_proposal_invalid_target', 422);
            return AiArticleDraftRules::channelFingerprint($channel);
        }
        $row = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE id = ?', [(int) $item['id']]);
        if (!AiArticleDraftRules::contentAcceptsUpdate($row, (string) $item['lang'])) throw new RuntimeException('ai_proposal_invalid_target', 422);
        return AiArticleDraftRules::contentFingerprint($row);
    }

    /**
     * 生效检查点：按 L1 令牌 → L2 用户 → L3 角色 取锁后，用锁定读判断当前有效性。
     * 返回 null 表示可用，否则返回稳定的原因代码。
     */
    private function lockSource(int $ownerId, int $tokenId, int $now): ?string
    {
        $token = $tokenId > 0 ? $this->lock('api_tokens', $tokenId) : null;
        $user = $this->lock('users', $ownerId);
        $role = $user === null ? null : $this->lock('roles', (int) $user['role_id']);
        $settings = [];
        foreach ($this->db->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX . 'settings WHERE `key` IN (?, ?, ?)', ['ai_access_enabled', 'demo_mode', 'support_access_user_id']) as $row) {
            $settings[(string) $row['key']] = (string) $row['value'];
        }
        if ($tokenId > 0) {
            if ($token === null || (int) $token['admin_id'] !== $ownerId || !in_array($token['revoked_at'], [null, 0, '0'], true)) return 'source_token_revoked';
            if ((int) $token['expires_at'] <= $now) return 'source_token_expired';
        }
        if ($user === null || (int) $user['status'] !== 1) return 'owner_disabled';
        if ($role === null || (int) $role['status'] !== 1) return 'owner_role_disabled';
        if (($settings['demo_mode'] ?? '0') === '1') return 'demo_mode';
        $permissions = json_decode((string) ($role['permissions'] ?? ''), true);
        if (!is_array($permissions) || !array_is_list($permissions)) return 'owner_role_disabled';
        $permissions = array_values(array_filter($permissions, 'is_string'));
        if ($tokenId > 0) {
            if (($settings['ai_access_enabled'] ?? '0') !== '1') return 'ai_access_disabled';
            if ((string) $user['username'] === 'yikai-support' || (int) $user['id'] === (int) ($settings['support_access_user_id'] ?? 0)) return 'owner_disabled';
            $scopes = json_decode((string) $token['scopes'], true);
            if (!is_array($scopes) || !array_is_list($scopes)) return 'source_token_scope_removed';
            $context = new AiAccessAuthContext($ownerId, $tokenId, $permissions, array_values(array_filter($scopes, 'is_string')));
            if (!in_array(self::TOOL, $scopes, true)) return 'source_token_scope_removed';
            if (!$context->allowsTool(self::TOOL)) return 'owner_permission_removed';
            return null;
        }
        return in_array('*', $permissions, true) || in_array('edit_article', $permissions, true) ? null : 'owner_permission_removed';
    }

    private function decide(array $item, string $status, string $reason, int $now): array
    {
        $this->db->execute(
            'UPDATE ' . DB_PREFIX . 'ai_proposal_items SET status = ?, status_reason = ?, decided_at = ? WHERE id = ? AND status = ?',
            [$status, $reason, $status === 'pending' ? 0 : $now, (int) $item['id'], 'pending']
        );
        return ['item_id' => (string) $item['public_id'], 'status' => $status, 'reason' => $reason, 'replayed' => false,
            'content_id' => 0, 'revision' => '', 'log_id' => 0, 'post_commit_error' => false];
    }

    private function itemResult(array $item, bool $replayed): array
    {
        return ['item_id' => (string) $item['public_id'], 'status' => (string) $item['status'], 'reason' => (string) $item['status_reason'],
            'replayed' => $replayed, 'content_id' => (int) $item['result_content_id'], 'revision' => (string) $item['result_revision'],
            'log_id' => (int) $item['change_log_id'], 'post_commit_error' => false];
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        // 不嵌套、不在“已有事务”里跳过检查：调用方持有事务即视为用法错误。
        if ($this->db->getPdo()->inTransaction()) throw new LogicException('ai_proposal_nested_transaction');
        $this->db->beginTransaction();
        try {
            $result = $work();
        } catch (Throwable $error) {
            if ($this->db->getPdo()->inTransaction()) $this->db->rollback();
            throw $error;
        }
        $this->db->commit();
        return $result;
    }

    /** MySQL 用锁定读；SQLite 用无害写取得库级写锁后再读最新值。 */
    private function lock(string $table, int $id): ?array
    {
        if ($this->db->isSqlite()) {
            $this->db->execute('UPDATE ' . DB_PREFIX . $table . ' SET id = id WHERE id = ?', [$id]);
            return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]);
        }
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE id = ? FOR UPDATE', [$id]);
    }

    private function check(string $point): void
    {
        if ($this->checkpoint !== null) ($this->checkpoint)($point);
    }

    /** 对外只给稳定代码，不带 SQL、参数值或正文。 */
    private function infrastructure(Throwable $error): RuntimeException
    {
        if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'ai_proposal_')) return $error;
        if ($error instanceof LogicException && $error->getMessage() === 'ai_proposal_nested_transaction') {
            return new RuntimeException('ai_proposal_nested_transaction', 500);
        }
        $info = $error instanceof PDOException ? ($error->errorInfo ?? []) : [];
        $driverCode = (int) ($info[1] ?? 0);
        // 死锁、锁等待超时、SQLite busy：未提交任何状态，可以重试。
        if (in_array($driverCode, [1213, 1205, 5, 6], true) || (string) $error->getCode() === '40001') {
            return new RuntimeException('ai_proposal_busy', 503);
        }
        return new RuntimeException('ai_proposal_unavailable', 503);
    }
}
