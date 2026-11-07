<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';
require_once __DIR__ . '/AiArticleDraftRules.php';
require_once __DIR__ . '/AiPageContentRules.php';
require_once __DIR__ . '/AiPageDocumentSource.php';
require_once __DIR__ . '/AiProductContentRules.php';
require_once dirname(__DIR__) . '/models/autoload.php';

/**
 * 文章草稿提案、单页内容提案（S5-03）与产品内容提案（T05）的唯一事务所有者（ADR-002）：提案写入、应用、撤销各开恰好一个事务。
 * 锁顺序固定为 令牌 → 用户 → 角色 → 提案项 → 变更日志 → 目标文章/栏目。
 * 单页提案应用时锁栏目行（与编辑器保存 BloxDocumentWriteLock::channel 同一把锁），只写草稿行，不碰已发布内容。
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

    /**
     * 内部来源（后台 Agent）：没有令牌，命名空间仍为非空的 internal。
     * @psalm-api 站内调用方尚未接入；事务与协议套件（tests/ai-access，不在 Psalm 扫描范围）覆盖它
     */
    public function proposeInternal(int $ownerId, string $idempotencyKey, array $items, int $now): array
    {
        return $this->propose(['owner' => $ownerId, 'token' => 0, 'ns' => 'internal'], $idempotencyKey, $items, $now);
    }

    /**
     * 单页内容提案（S5-03）：一次提交 = 一页 = 一个提案项。提交时就核对页面当前修订号与每个元素，
     * 不合格直接拒绝，不进审核队列；通过的只写提案表，管理员应用前不写任何页面数据。
     * @param array<string,mixed> $args
     */
    public function proposePageFromToken(AiAccessAuthContext $context, string $idempotencyKey, array $args, int $now): array
    {
        $source = ['owner' => $context->actorId, 'token' => $context->tokenId, 'ns' => 'token:' . $context->tokenId];
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1 || $source['owner'] < 1) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        $item = AiPageContentRules::normalize($args);
        $hash = AiPageContentRules::payloadHash($item);
        $tool = AiPageContentRules::TOOL;
        try {
            return $this->transaction(function () use ($source, $idempotencyKey, $item, $hash, $now, $tool): array {
                $verdict = $this->lockSource($source['owner'], $source['token'], $now, $tool);
                $this->check('propose.locked');
                if ($verdict !== null) throw new RuntimeException('ai_proposal_source_denied', 403);
                $existing = $this->db->fetchOne(
                    'SELECT id, public_id, payload_hash FROM ' . DB_PREFIX . 'ai_proposals WHERE owner_id = ? AND source_ns = ? AND tool = ? AND idempotency_key = ?',
                    [$source['owner'], $source['ns'], $tool, $idempotencyKey]
                );
                if ($existing !== null) return $this->replay($existing, $hash);
                $pending = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "ai_proposal_items WHERE owner_id = ? AND status = 'pending'", [$source['owner']]);
                if ($pending + 1 > self::MAX_PENDING_ITEMS) throw new RuntimeException('ai_proposal_quota_exceeded', 429);
                $target = $this->pageTarget($item);
                if (isset($target['conflict'])) {
                    throw new RuntimeException($target['conflict'] === 'page_changed' ? 'ai_proposal_stale_revision' : 'ai_proposal_invalid_target', $target['conflict'] === 'page_changed' ? 409 : 422);
                }
                $setPublic = bin2hex(random_bytes(16));
                $this->db->execute(
                    'INSERT INTO ' . DB_PREFIX . 'ai_proposals (public_id, owner_id, source_ns, token_id, tool, idempotency_key, payload_hash, contract_version, created_at, expires_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$setPublic, $source['owner'], $source['ns'], $source['token'], $tool, $idempotencyKey, $hash, AiPageContentRules::CONTRACT, $now, $now + self::TTL]
                );
                $setId = (int) $this->db->getPdo()->lastInsertId();
                $itemPublic = bin2hex(random_bytes(16));
                $json = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $this->db->execute(
                    'INSERT INTO ' . DB_PREFIX . 'ai_proposal_items (public_id, proposal_id, seq, owner_id, operation, target_id, lang, channel_id, input_json, input_hash, base_revision, contract_version, status, created_at)'
                    . " VALUES (?, ?, 0, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?)",
                    [$itemPublic, $setId, $source['owner'], AiPageContentRules::OPERATION, $item['page_id'], $item['lang'], $json, hash('sha256', $json),
                        $item['base_revision'], AiPageContentRules::CONTRACT, $now]
                );
                return ['proposal_id' => $setPublic, 'items' => [['item_id' => $itemPublic, 'op' => AiPageContentRules::OPERATION, 'status' => 'pending']], 'replayed' => false];
            });
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $this->infrastructure($error);
            $existing = $this->db->fetchOne(
                'SELECT id, public_id, payload_hash FROM ' . DB_PREFIX . 'ai_proposals WHERE owner_id = ? AND source_ns = ? AND tool = ? AND idempotency_key = ?',
                [$source['owner'], $source['ns'], $tool, $idempotencyKey]
            );
            if ($existing === null) throw $this->infrastructure($error);
            return $this->replay($existing, $hash);
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
    }

    /**
     * 产品内容提案（T05）：只改草稿产品的摘要、详情与 SEO 文案。提交时就核对每个目标：
     * 必须是同语言草稿、base_revision 必须等于当前指纹（客户端按旧版本写的文案不入队）。
     * @param list<mixed> $items
     */
    public function proposeProductFromToken(AiAccessAuthContext $context, string $idempotencyKey, array $items, int $now): array
    {
        $source = ['owner' => $context->actorId, 'token' => $context->tokenId, 'ns' => 'token:' . $context->tokenId];
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idempotencyKey) !== 1 || $source['owner'] < 1) throw new RuntimeException('ai_proposal_invalid_input', 400);
        $normalized = AiProductContentRules::normalizeItems($items);
        $hash = AiProductContentRules::payloadHash($normalized);
        $tool = AiProductContentRules::TOOL;
        $find = fn (): ?array => $this->db->fetchOne(
            'SELECT id, public_id, payload_hash FROM ' . DB_PREFIX . 'ai_proposals WHERE owner_id = ? AND source_ns = ? AND tool = ? AND idempotency_key = ?',
            [$source['owner'], $source['ns'], $tool, $idempotencyKey]
        );
        try {
            return $this->transaction(function () use ($source, $idempotencyKey, $normalized, $hash, $now, $tool, $find): array {
                $verdict = $this->lockSource($source['owner'], $source['token'], $now, $tool);
                $this->check('propose.locked');
                if ($verdict !== null) throw new RuntimeException('ai_proposal_source_denied', 403);
                $existing = $find();
                if ($existing !== null) return $this->replay($existing, $hash);
                $pending = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "ai_proposal_items WHERE owner_id = ? AND status = 'pending'", [$source['owner']]);
                if ($pending + count($normalized) > self::MAX_PENDING_ITEMS) throw new RuntimeException('ai_proposal_quota_exceeded', 429);
                foreach ($normalized as $item) {
                    $row = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE id = ?', [(int) $item['id']]);
                    if (!AiProductContentRules::acceptsUpdate($this->db, $row, (string) $item['lang'])) throw new RuntimeException('ai_proposal_invalid_target', 422);
                    if (!hash_equals(AiProductContentRules::fingerprint((array) $row, AiProductContentRules::metas($this->db, (int) $item['id'])), (string) $item['base_revision'])) {
                        throw new RuntimeException('ai_proposal_stale_revision', 409);
                    }
                }
                $setPublic = bin2hex(random_bytes(16));
                $this->db->execute(
                    'INSERT INTO ' . DB_PREFIX . 'ai_proposals (public_id, owner_id, source_ns, token_id, tool, idempotency_key, payload_hash, contract_version, created_at, expires_at)'
                    . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$setPublic, $source['owner'], $source['ns'], $source['token'], $tool, $idempotencyKey, $hash, AiProductContentRules::CONTRACT, $now, $now + self::TTL]
                );
                $setId = (int) $this->db->getPdo()->lastInsertId();
                $out = [];
                foreach ($normalized as $seq => $item) {
                    $itemPublic = bin2hex(random_bytes(16));
                    $json = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $this->db->execute(
                        'INSERT INTO ' . DB_PREFIX . 'ai_proposal_items (public_id, proposal_id, seq, owner_id, operation, target_id, lang, channel_id, input_json, input_hash, base_revision, contract_version, status, created_at)'
                        . " VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'pending', ?)",
                        [$itemPublic, $setId, $seq, $source['owner'], AiProductContentRules::OPERATION, (int) $item['id'], $item['lang'], $json, hash('sha256', $json),
                            $item['base_revision'], AiProductContentRules::CONTRACT, $now]
                    );
                    $out[] = ['item_id' => $itemPublic, 'op' => AiProductContentRules::OPERATION, 'status' => 'pending'];
                }
                return ['proposal_id' => $setPublic, 'items' => $out, 'replayed' => false];
            });
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '23000') throw $this->infrastructure($error);
            $existing = $find();
            if ($existing === null) throw $this->infrastructure($error);
            return $this->replay($existing, $hash);
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
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
        $pre = $this->db->fetchOne('SELECT i.id, i.owner_id, p.token_id, p.source_ns, p.tool FROM ' . DB_PREFIX . 'ai_proposal_items i JOIN '
            . DB_PREFIX . 'ai_proposals p ON p.id = i.proposal_id WHERE i.public_id = ?', [$itemId]);
        // 他人提案与不存在同样处理，不泄漏存在性。
        if ($pre === null || (int) $pre['owner_id'] !== $adminId) throw new RuntimeException('ai_proposal_not_found', 404);
        $page = (string) $pre['tool'] === AiPageContentRules::TOOL;
        $product = (string) $pre['tool'] === AiProductContentRules::TOOL;
        $logId = 0;
        try {
            return $this->transaction(function () use ($pre, $itemId, $now, $page, $product, &$logId): array {
                $verdict = $this->lockSource((int) $pre['owner_id'], (int) $pre['token_id'], $now, (string) $pre['tool']);
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
                    || $item['contract_version'] !== ($page ? AiPageContentRules::CONTRACT : ($product ? AiProductContentRules::CONTRACT : AiArticleDraftRules::CONTRACT))
                    || ($input['op'] === AiPageContentRules::OPERATION) !== $page || ($input['op'] === AiProductContentRules::OPERATION) !== $product) {
                    return $this->decide($item, 'conflicted', 'contract_changed', $now);
                }
                $write = match (true) {
                    $page => $this->applyPage($item, $input, $now),
                    $product => $this->applyProduct($item, $input, $now),
                    $input['op'] === 'create' => $this->applyCreate($item, $input, $now),
                    default => $this->applyUpdate($item, $input, $now),
                };
                if (isset($write['conflict'])) return $this->decide($item, 'conflicted', $write['conflict'], $now);
                $this->check('apply.before_log');
                $owner = $this->db->fetchOne('SELECT username FROM ' . DB_PREFIX . 'users WHERE id = ?', [(int) $item['owner_id']]);
                $this->db->execute(
                    'INSERT INTO ' . DB_PREFIX . 'ai_change_log (admin_id, admin_name, ability, target, summary, before_json, input_json, created_at, undone, undone_at,'
                    . ' proposal_item_id, source_ns, target_type, target_id, operation, after_revision, undone_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, 0)',
                    [(int) $item['owner_id'], (string) ($owner['username'] ?? ''), (string) $pre['tool'], ($page ? 'page#' : ($product ? 'products#' : 'contents#')) . $write['id'],
                        mb_substr($input['op'] . ': ' . $write['title'], 0, 200),
                        $write['before'] === null ? null : json_encode($write['before'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        json_encode(['proposal_item' => $itemId], JSON_THROW_ON_ERROR), $now,
                        (int) $item['id'], (string) $pre['source_ns'], $page ? 'page' : ($product ? 'product' : 'article'), $write['id'], $input['op'], $write['after']]
                );
                $logId = (int) $this->db->getPdo()->lastInsertId();
                // 单页、产品的结果不是文章：result_content_id 留 0，页面/产品编号在 target_id 上
                $contentId = $page || $product ? 0 : $write['id'];
                $this->db->execute(
                    'UPDATE ' . DB_PREFIX . "ai_proposal_items SET status = 'applied', status_reason = '', result_content_id = ?, result_revision = ?, change_log_id = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'pending'",
                    [$contentId, $write['after'], $logId, (int) $item['owner_id'], $now, (int) $item['id']]
                );
                $this->check('apply.before_commit');
                return ['item_id' => $itemId, 'status' => 'applied', 'reason' => '', 'replayed' => false, 'content_id' => $contentId,
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
        $pre = $this->db->fetchOne('SELECT id, admin_id, proposal_item_id, target_type FROM ' . DB_PREFIX . 'ai_change_log WHERE id = ?', [$logId]);
        if ($pre === null || (int) $pre['admin_id'] !== $adminId || (int) $pre['proposal_item_id'] < 1) throw new RuntimeException('ai_proposal_not_found', 404);
        if ((string) $pre['target_type'] === 'page') return $this->undoPage($adminId, $pre, $now);
        if ((string) $pre['target_type'] === 'product') return $this->undoProduct($adminId, $pre, $now);
        // 未知目标类型绝不按文章处理：页面编号当文章编号回写会改错行。
        if ((string) $pre['target_type'] !== 'article') throw new RuntimeException('ai_proposal_undo_unsupported', 409);
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

    /**
     * 单页内容提案的写入：锁栏目行后重读页面，修订号必须仍等于提案基线，逐项改写后只写草稿行。
     * 任一项不满足就整项冲突、什么都不写；写入后的修订号必须能从库里原样读回。
     * @return array{id:int,title:string,before:?array,after:string}|array{conflict:string}
     */
    private function applyPage(array $item, array $input, int $now): array
    {
        $pageId = (int) $input['page_id'];
        $this->lock('channels', $pageId);
        $target = $this->pageTarget($input, (string) $item['base_revision']);
        if (isset($target['conflict'])) return ['conflict' => $target['conflict']];
        $document = $target['result']['document'];
        $json = json_encode(['schema' => $document['schema'], 'settings' => $document['settings'], 'sections' => $document['sections']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $draft = $this->db->fetchOne('SELECT id FROM ' . DB_PREFIX . 'blox_page_drafts WHERE page_id = ?', [$pageId]);
        if ($draft !== null) {
            $this->db->execute('UPDATE ' . DB_PREFIX . 'blox_page_drafts SET draft_data = ?, admin_id = ?, updated_at = ? WHERE id = ?',
                [$json, (int) $item['owner_id'], $now, (int) $draft['id']]);
        } else {
            $this->db->execute('INSERT INTO ' . DB_PREFIX . 'blox_page_drafts (page_id, draft_data, admin_id, created_at, updated_at, published_at) VALUES (?, ?, ?, ?, ?, 0)',
                [$pageId, $json, (int) $item['owner_id'], $now, $now]);
        }
        $this->check('apply.page_written');
        $source = new AiPageDocumentSource($this->db, true);
        $after = $source->load((array) $source->page($pageId))['base_revision'];
        if (!hash_equals(BloxDocumentPipeline::fingerprint($json), $after)) throw new RuntimeException('ai_proposal_unavailable', 503);
        return ['id' => $pageId, 'title' => (string) $target['page']['name'], 'before' => $target['result']['before'], 'after' => $after];
    }

    /**
     * 提交与应用共用的目标判定：页面可用、语言一致且启用、不是纯 HTML 老页、修订号等于基线、每个元素都可改。
     * @param array<string,mixed> $input 规范化后的提案项
     * @return array{page:array<string,mixed>,result:array{document:array<string,mixed>,before:array<string,array<string,string>>}}|array{conflict:string}
     */
    private function pageTarget(array $input, ?string $base = null): array
    {
        $source = new AiPageDocumentSource($this->db, true);
        $page = $source->page((int) $input['page_id']);
        if ($page === null || (int) ($page['status'] ?? 0) !== 1 || (string) $page['lang'] !== (string) $input['lang']) return ['conflict' => 'page_unavailable'];
        if (!AiArticleDraftRules::languageEnabled($this->db, (string) $input['lang'])) return ['conflict' => 'language_disabled'];
        $state = $source->load($page);
        if ($state['legacy_html'] !== null) return ['conflict' => 'page_legacy'];
        if (!hash_equals($base ?? (string) $input['base_revision'], $state['base_revision'])) return ['conflict' => 'page_changed'];
        $result = AiPageContentRules::apply(BloxDocumentPipeline::decode($state['document_json']), $input['changes']);
        if (!isset($result['document'], $result['before'])) return ['conflict' => (string) ($result['conflict'] ?? 'element_missing')];
        return ['page' => $page, 'result' => ['document' => $result['document'], 'before' => $result['before']]];
    }

    /**
     * 单页提案的撤销（S5-04）：只有草稿仍是 AI 应用后的那一版、且这一版没有被发布时才写回改前值。
     * 之后的人工编辑、发布、删除页面一律冲突，不覆盖；只写草稿行，已发布内容不动。
     * @param array<string,mixed> $pre
     */
    private function undoPage(int $adminId, array $pre, int $now): array
    {
        try {
            return $this->transaction(function () use ($adminId, $pre, $now): array {
                $verdict = $this->lockSource($adminId, 0, $now, AiPageContentRules::TOOL);
                $item = $this->lock('ai_proposal_items', (int) $pre['proposal_item_id']);
                $log = $this->lock('ai_change_log', (int) $pre['id']);
                $this->check('undo.locked');
                if ($verdict !== null) throw new RuntimeException('ai_proposal_actor_denied', 403);
                if ($item === null || $log === null) throw new RuntimeException('ai_proposal_not_found', 404);
                if ((int) $log['undone'] === 1) return ['log_id' => (int) $log['id'], 'status' => 'reverted', 'replayed' => true];
                $pageId = (int) $log['target_id'];
                $this->lock('channels', $pageId);
                $source = new AiPageDocumentSource($this->db, true);
                $page = $source->page($pageId);
                if ($page === null) throw new RuntimeException('ai_proposal_undo_conflict', 409);
                $state = $source->load($page);
                // 草稿与已发布相同说明 AI 的改动已经上线：撤销草稿会让后台与前台不一致，交给编辑器处理
                if ($state['legacy_html'] !== null || !hash_equals((string) $log['after_revision'], $state['base_revision'])
                    || ($state['has_draft'] && !$state['has_unpublished_changes'])) {
                    throw new RuntimeException('ai_proposal_undo_conflict', 409);
                }
                $before = json_decode((string) $log['before_json'], true, 8, JSON_THROW_ON_ERROR);
                $changes = [];
                foreach (is_array($before) ? $before : [] as $elementId => $fields) {
                    foreach (is_array($fields) ? $fields : [] as $field => $value) {
                        $changes[] = ['element_id' => (string) $elementId, 'field' => (string) $field, 'value' => (string) $value];
                    }
                }
                if ($changes === []) throw new RuntimeException('ai_proposal_undo_conflict', 409);
                $result = AiPageContentRules::apply(BloxDocumentPipeline::decode($state['document_json']), $changes, false);
                if (!isset($result['document'])) throw new RuntimeException('ai_proposal_undo_conflict', 409);
                $document = $result['document'];
                $json = json_encode(['schema' => $document['schema'], 'settings' => $document['settings'], 'sections' => $document['sections']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $this->db->execute('UPDATE ' . DB_PREFIX . 'blox_page_drafts SET draft_data = ?, admin_id = ?, updated_at = ? WHERE page_id = ?', [$json, $adminId, $now, $pageId]);
                $this->check('undo.page_written');
                $revision = $source->load((array) $source->page($pageId))['base_revision'];
                if (!hash_equals(BloxDocumentPipeline::fingerprint($json), $revision)) throw new RuntimeException('ai_proposal_unavailable', 503);
                $this->db->execute('UPDATE ' . DB_PREFIX . 'ai_change_log SET undone = 1, undone_at = ?, undone_by = ? WHERE id = ? AND undone = 0', [$now, $adminId, (int) $log['id']]);
                $this->db->execute('UPDATE ' . DB_PREFIX . "ai_proposal_items SET status = 'reverted', status_reason = 'undone' WHERE id = ? AND status = 'applied'", [(int) $item['id']]);
                $this->check('undo.before_commit');
                return ['log_id' => (int) $log['id'], 'status' => 'reverted', 'replayed' => false, 'revision' => $revision];
            });
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
    }

    /**
     * 产品内容提案的写入（T05）：锁产品行后重算指纹（含 SEO metas），必须仍等于提案基线且仍是同语言草稿；
     * 只写提案里出现的字段，不碰标题、型号、参数、价格、状态。改前值进变更日志，供条件撤销。
     * @return array{id:int,title:string,before:?array,after:string}|array{conflict:string}
     */
    private function applyProduct(array $item, array $input, int $now): array
    {
        $id = (int) $input['id'];
        $row = $this->lock('products', $id);
        if ($row === null || $row['deleted_at'] !== null) return ['conflict' => 'product_deleted'];
        $metas = AiProductContentRules::metas($this->db, $id);
        if (!hash_equals((string) $item['base_revision'], AiProductContentRules::fingerprint($row, $metas))) {
            return ['conflict' => (int) $row['status'] !== 0 ? 'product_published' : 'product_changed'];
        }
        if (!AiProductContentRules::acceptsUpdate($this->db, $row, (string) $input['lang'])) return ['conflict' => 'product_not_draft'];
        $rowFields = array_intersect_key($input, array_flip(AiProductContentRules::ROW_FIELDS));
        $metaFields = array_intersect_key($input, array_flip(AiProductContentRules::META_FIELDS));
        $before = array_map(static fn (mixed $value): string => (string) $value, array_intersect_key($row, $rowFields) + array_intersect_key($metas, $metaFields));
        $this->db->update('products', $rowFields + ['updated_at' => $now], 'id = ?', [$id]);
        foreach ($metaFields as $key => $value) $this->setProductMeta($id, (string) $key, (string) $value, $now);
        $after = $this->lock('products', $id);
        if ($after === null) throw new RuntimeException('ai_proposal_unavailable', 503);
        return ['id' => $id, 'title' => (string) $after['title'], 'before' => $before, 'after' => AiProductContentRules::fingerprint($after, AiProductContentRules::metas($this->db, $id))];
    }

    /**
     * 产品提案的撤销：只有产品仍等于应用后的版本（行与 SEO metas 都没再动过）才写回改前值；
     * 之后的人工编辑、发布、删除一律冲突，不覆盖。按后台当前身份要求 edit_product。
     * @param array<string,mixed> $pre
     */
    private function undoProduct(int $adminId, array $pre, int $now): array
    {
        try {
            return $this->transaction(function () use ($adminId, $pre, $now): array {
                $verdict = $this->lockSource($adminId, 0, $now, AiProductContentRules::TOOL);
                $item = $this->lock('ai_proposal_items', (int) $pre['proposal_item_id']);
                $log = $this->lock('ai_change_log', (int) $pre['id']);
                $this->check('undo.locked');
                if ($verdict !== null) throw new RuntimeException('ai_proposal_actor_denied', 403);
                if ($item === null || $log === null) throw new RuntimeException('ai_proposal_not_found', 404);
                if ((int) $log['undone'] === 1) return ['log_id' => (int) $log['id'], 'status' => 'reverted', 'replayed' => true];
                $id = (int) $log['target_id'];
                $row = $this->lock('products', $id);
                if ($row === null || !hash_equals((string) $log['after_revision'], AiProductContentRules::fingerprint($row, AiProductContentRules::metas($this->db, $id)))) {
                    throw new RuntimeException('ai_proposal_undo_conflict', 409);
                }
                $before = json_decode((string) $log['before_json'], true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($before) || $before === []) throw new RuntimeException('ai_proposal_undo_conflict', 409);
                $this->db->update('products', array_intersect_key($before, array_flip(AiProductContentRules::ROW_FIELDS)) + ['updated_at' => $now], 'id = ?', [$id]);
                foreach (array_intersect_key($before, array_flip(AiProductContentRules::META_FIELDS)) as $key => $value) $this->setProductMeta($id, (string) $key, (string) $value, $now);
                $this->db->execute('UPDATE ' . DB_PREFIX . 'ai_change_log SET undone = 1, undone_at = ?, undone_by = ? WHERE id = ? AND undone = 0', [$now, $adminId, (int) $log['id']]);
                $this->db->execute('UPDATE ' . DB_PREFIX . "ai_proposal_items SET status = 'reverted', status_reason = 'undone' WHERE id = ? AND status = 'applied'", [(int) $item['id']]);
                $this->check('undo.before_commit');
                return ['log_id' => (int) $log['id'], 'status' => 'reverted', 'replayed' => false];
            });
        } catch (Throwable $error) {
            throw $this->infrastructure($error);
        }
    }

    /** 产品 SEO meta 写入（metas 有 owner_type+owner_id+meta_key 唯一键）：有则改、无则建 */
    private function setProductMeta(int $productId, string $key, string $value, int $now): void
    {
        $where = "owner_type = 'product' AND owner_id = ? AND meta_key = ?";
        if ($this->db->fetchOne('SELECT id FROM ' . DB_PREFIX . "metas WHERE {$where}", [$productId, $key]) !== null) {
            $this->db->execute('UPDATE ' . DB_PREFIX . "metas SET meta_value = ?, updated_at = ? WHERE {$where}", [$value, $now, $productId, $key]);
            return;
        }
        $this->db->insert('metas', ['owner_type' => 'product', 'owner_id' => $productId, 'meta_key' => $key, 'meta_value' => $value, 'created_at' => $now, 'updated_at' => $now]);
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
    private function lockSource(int $ownerId, int $tokenId, int $now, string $tool = self::TOOL): ?string
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
            if (!in_array($tool, $scopes, true)) return 'source_token_scope_removed';
            if (!$context->allowsTool($tool)) return 'owner_permission_removed';
            return null;
        }
        if (in_array('*', $permissions, true)) return null;
        // 无令牌的后台动作（撤销）：单页提案要单页编辑权限，文章提案要文章编辑权限
        if ($tool === AiPageContentRules::TOOL) return in_array('blox_edit', $permissions, true) && in_array('edit_page', $permissions, true) ? null : 'owner_permission_removed';
        if ($tool === AiProductContentRules::TOOL) return in_array('edit_product', $permissions, true) ? null : 'owner_permission_removed';
        return in_array('edit_article', $permissions, true) ? null : 'owner_permission_removed';
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
        return $this->db->lockRow($table, $id);
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
