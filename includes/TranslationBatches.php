<?php
/**
 * 批量翻译任务（评审 T03，第一条切片：产品）。
 *
 * 一批 = 若干产品 × 若干目标语言，每个组合一项，逐项记状态：
 *   pending → generating（领取、带租约）→ generated（模型结果已落库）→ applied
 *   失败计次，满 3 次 failed；写库时源已变 → conflict；目标语言已有 → exists。
 * 生成结果先落库再写内容：写库失败或进程中断后重试不再调模型；租约过期的项可被重新领取。
 * 两种批次：「翻译缺失项」只新建缺的译文（草稿），已有译文记 exists；
 * 「更新过期译文」（T04）只收原文改过的已有译文：建批时就带上 target_id——pending 且 target_id>0 即「更新这一条」。
 * 更新时机器写入、没人改过的草稿直接覆盖；已发布、人改过或审过的不覆盖，存为待审译文（proposed），由人逐字段采用。
 * 设计：yikaicms-docs/T03-持久化翻译任务-设计-20261107.md、T04-源变更追踪与译文保护-设计-20261107.md
 */

declare(strict_types=1);

require_once __DIR__ . '/TranslationWrite.php';

final class TranslationBatches
{
    public const LEASE_SECONDS = 300;
    public const MAX_ATTEMPTS = 3;
    /** 还没做完、会被 step() 继续处理的状态 */
    public const OPEN = ['pending', 'generating', 'generated'];
    /** 写库时发现这一项已不归本请求（被取消或被重新领取） */
    private const LEASE_LOST = 'lease_lost';

    /** @var Closure(array<string,mixed>, string): array<string,mixed> */
    private Closure $generator;

    /** @param null|Closure(array<string,mixed>, string): array<string,mixed> $generator 测试注入；默认调模型 */
    public function __construct(private Database $db, ?Closure $generator = null)
    {
        $this->generator = $generator ?? static fn (array $source, string $lang): array => TranslationWrite::generate($source, $lang);
    }

    /**
     * 建一批。源必须是站点默认语言的产品；已在别的未完成批次里的组合不再重复排队。
     * missing：已有该语言版本的项记 exists。update：只收已有且原文改过的译文，其余跳过（不建项）。
     * @param list<int> $productIds @param list<string> $langs 已校验过的目标语言
     * @return array{batch_id:int,total:int,queued_elsewhere:int,not_source:int,not_outdated:int}
     */
    public function create(int $adminId, array $productIds, array $langs, string $defaultLang, int $now, string $mode = 'missing'): array
    {
        $langs = array_values(array_unique(array_filter($langs, static fn (string $lang): bool => $lang !== '' && $lang !== $defaultLang)));
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn (int $id): bool => $id > 0)));
        if ($langs === [] || $productIds === []) throw new InvalidArgumentException('translation_batch_empty');

        $this->db->beginTransaction();
        try {
            $batchId = (int) $this->db->insert('translation_batches', [
                'admin_id' => $adminId, 'content_type' => 'product', 'langs' => json_encode($langs, JSON_THROW_ON_ERROR),
                'status' => 'running', 'total' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $total = $elsewhere = $notSource = $notOutdated = 0;
            foreach ($productIds as $id) {
                $src = $this->product($id);
                if ($src === null || (string) $src['lang'] !== $defaultLang) {
                    $notSource++;
                    continue;
                }
                // 源行先挂上自己的翻译组，生成时记下的指纹与写库时一致
                $this->db->execute('UPDATE ' . DB_PREFIX . 'products SET translation_group_id = id WHERE id = ? AND translation_group_id = 0', [$id]);
                foreach ($langs as $lang) {
                    if ($this->openElsewhere($id, $lang)) {
                        $elsewhere++;
                        continue;
                    }
                    $existing = $this->existingTarget($id, $lang);
                    if ($mode === 'update') {
                        if ($existing === 0 || !$this->outdated($src, $existing)) {
                            $notOutdated++;
                            continue;
                        }
                        $this->db->insert('translation_batch_items', [
                            'batch_id' => $batchId, 'source_id' => $id, 'lang' => $lang, 'status' => 'pending', 'target_id' => $existing, 'updated_at' => $now,
                        ]);
                        $total++;
                        continue;
                    }
                    $this->db->insert('translation_batch_items', [
                        'batch_id' => $batchId, 'source_id' => $id, 'lang' => $lang,
                        'status' => $existing > 0 ? 'exists' : 'pending', 'target_id' => $existing, 'updated_at' => $now,
                    ]);
                    $total++;
                }
            }
            $this->db->execute('UPDATE ' . DB_PREFIX . 'translation_batches SET total = ? WHERE id = ?', [$total, $batchId]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        $this->finishIfDone($batchId, $now);
        return ['batch_id' => $batchId, 'total' => $total, 'queued_elsewhere' => $elsewhere, 'not_source' => $notSource, 'not_outdated' => $notOutdated];
    }

    /**
     * 在时间预算内处理若干项；批次不在 running 时什么都不做。
     * @param null|Closure():int $clock 测试注入
     * @return array<string,int> 各状态计数
     */
    public function step(int $batchId, float $budgetSeconds, ?Closure $clock = null): array
    {
        $clock ??= static fn (): int => time();
        $deadline = microtime(true) + $budgetSeconds;
        $seen = [];
        while (microtime(true) < $deadline && $this->batchStatus($batchId) === 'running') {
            $item = $this->claim($batchId, $clock(), $seen);
            if ($item === null) break;
            $seen[(int) $item['id']] = true;
            if ($item['status'] === 'generating') $item = $this->generateItem($item, $clock());
            if ($item !== null && $item['status'] === 'generated') $this->applyItem($item, $clock());
        }
        $this->finishIfDone($batchId, $clock());
        return $this->counts($batchId);
    }

    /** failed 与 conflict 重新排队；conflict 的旧结果作废（源已变，必须重新生成） */
    public function retry(int $batchId, int $now): int
    {
        $n = $this->db->execute('UPDATE ' . DB_PREFIX . "translation_batch_items SET status = 'pending', attempts = 0, error = '', result_json = NULL, source_fp = '', lease_until = 0, updated_at = ?"
            . " WHERE batch_id = ? AND status IN ('failed', 'conflict')", [$now, $batchId]);
        if ($n > 0) $this->setStatus($batchId, 'running', $now);
        return $n;
    }

    public function pause(int $batchId, int $now): void
    {
        $this->db->execute('UPDATE ' . DB_PREFIX . "translation_batches SET status = 'paused', updated_at = ? WHERE id = ? AND status = 'running'", [$now, $batchId]);
    }

    public function resume(int $batchId, int $now): void
    {
        $this->db->execute('UPDATE ' . DB_PREFIX . "translation_batches SET status = 'running', updated_at = ? WHERE id = ? AND status = 'paused'", [$now, $batchId]);
        $this->finishIfDone($batchId, $now);
    }

    /** 取消：未开始的项不再处理；已经写进去的译文不撤（它们是普通草稿） */
    public function cancel(int $batchId, int $now): void
    {
        // 正在写库的那一项持有项目行锁（见 applyItem），这里的 UPDATE 会等它提交：
        // 它先写完就是 applied，不再改；它还没开始写，就看到 cancelled、一个字都不写（评审 F03）
        $this->db->beginTransaction();
        try {
            $this->db->execute('UPDATE ' . DB_PREFIX . "translation_batches SET status = 'cancelled', updated_at = ? WHERE id = ? AND status IN ('running', 'paused')", [$now, $batchId]);
            $this->db->execute('UPDATE ' . DB_PREFIX . "translation_batch_items SET status = 'cancelled', lease_until = 0, updated_at = ? WHERE batch_id = ? AND status IN ('pending', 'generating', 'generated')", [$now, $batchId]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->getPdo()->inTransaction()) $this->db->rollback();
            throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function batch(int $batchId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'translation_batches WHERE id = ?', [$batchId]);
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        return $this->db->fetchAll('SELECT * FROM ' . DB_PREFIX . 'translation_batches ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    /** @return list<array<string,mixed>> 每项带源产品标题（不含生成结果全文） */
    public function items(int $batchId): array
    {
        return $this->db->fetchAll('SELECT i.id, i.source_id, i.lang, i.status, i.attempts, i.target_id, i.error, p.title AS source_title'
            . ' FROM ' . DB_PREFIX . 'translation_batch_items i LEFT JOIN ' . DB_PREFIX . 'products p ON p.id = i.source_id'
            . ' WHERE i.batch_id = ? ORDER BY i.id', [$batchId]);
    }

    /** @return array<string,int> */
    public function counts(int $batchId): array
    {
        $counts = [];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM ' . DB_PREFIX . 'translation_batch_items WHERE batch_id = ? GROUP BY status', [$batchId]) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /**
     * 领一项：已生成待写库的优先（不调模型），其次待处理或租约已过期的生成中。
     * 用带条件的 UPDATE 抢：影响 1 行才算领到，两个页面同时跑也不会重复调模型。
     * @param array<int,true> $skip 本轮已处理过的项（写库失败的不在同一轮里空转）
     * @return array<string,mixed>|null
     */
    private function claim(int $batchId, int $now, array $skip): ?array
    {
        $t = DB_PREFIX . 'translation_batch_items';
        $candidates = $this->db->fetchAll("SELECT id, status FROM {$t} WHERE batch_id = ? AND lease_until < ?"
            . " AND status IN ('generated', 'pending', 'generating') ORDER BY CASE status WHEN 'generated' THEN 0 ELSE 1 END, id LIMIT 20", [$batchId, $now]);
        foreach ($candidates as $candidate) {
            $id = (int) $candidate['id'];
            if (isset($skip[$id])) continue;
            $lease = $now + self::LEASE_SECONDS;
            $won = $candidate['status'] === 'generated'
                ? $this->db->execute("UPDATE {$t} SET lease_until = ?, updated_at = ? WHERE id = ? AND status = 'generated' AND lease_until < ?", [$lease, $now, $id, $now])
                : $this->db->execute("UPDATE {$t} SET status = 'generating', attempts = attempts + 1, lease_until = ?, updated_at = ? WHERE id = ?"
                    . " AND (status = 'pending' OR (status = 'generating' AND lease_until < ?))", [$lease, $now, $id, $now]);
            if ($won === 1) return $this->db->fetchOne("SELECT * FROM {$t} WHERE id = ?", [$id]);
        }
        return null;
    }

    /** @param array<string,mixed> $item @return array<string,mixed>|null 生成成功返回更新后的项 */
    private function generateItem(array $item, int $now): ?array
    {
        $id = (int) $item['id'];
        $src = $this->product((int) $item['source_id']);
        if ($src === null) {
            $this->markHeld($item, 'failed', ['error' => 'source_missing'], $now);
            return null;
        }
        $existing = $this->existingTarget((int) $src['id'], (string) $item['lang']);
        if ((int) $item['target_id'] > 0) {
            // 更新项：译文在排队后被删掉了就不再生成
            if ($existing !== (int) $item['target_id']) {
                $this->markHeld($item, 'failed', ['error' => 'target_missing'], $now);
                return null;
            }
        } elseif ($existing > 0) {
            $this->markHeld($item, 'exists', ['target_id' => $existing], $now);
            return null;
        }
        require_once ROOT_PATH . '/includes/content_translation.php';
        try {
            $translated = ($this->generator)(TranslationWrite::source($src, 'title', 'summary', $src, translationBodyField($src)), (string) $item['lang']);
        } catch (Throwable $error) {
            $status = (int) $item['attempts'] >= self::MAX_ATTEMPTS ? 'failed' : 'pending';
            $this->markHeld($item, $status, ['error' => mb_substr($error->getMessage(), 0, 250)], $now);
            return null;
        }
        // 租约留着：本请求接着写库，别的页面这时领不走它。
        // 调模型期间被取消、或租约过期被别的页面重新领走，这份结果就作废，不落库（评审 F03）
        $kept = $this->markHeld($item, 'generated', [
            'result_json' => json_encode($translated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'source_fp' => TranslationWrite::fingerprint($src), 'error' => '',
        ], $now, (int) $item['lease_until']);
        if (!$kept) return null;
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'translation_batch_items WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $item */
    private function applyItem(array $item, int $now): void
    {
        $id = (int) $item['id'];
        $row = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'translation_batch_items WHERE id = ?', [$id]);
        if ($row === null || !self::stillHeld($row, $item)) return;
        $translated = json_decode((string) ($row['result_json'] ?? ''), true);
        if (!is_array($translated)) {
            $this->markHeld($item, 'pending', ['error' => 'result_unreadable'], $now);
            return;
        }
        $src = $this->product((int) $row['source_id']);
        if ($src === null) {
            $this->markHeld($item, 'failed', ['error' => 'source_missing'], $now);
            return;
        }
        if (TranslationWrite::fingerprint($src) !== (string) $row['source_fp']) {
            $this->markHeld($item, 'conflict', ['error' => 'source_changed'], $now);
            return;
        }
        require_once ROOT_PATH . '/includes/content_translation.php';
        $lang = (string) $row['lang'];
        $groupId = (int) ($src['translation_group_id'] ?: $src['id']);
        $bodyField = translationBodyField($src);
        $target = null;
        if ((int) $row['target_id'] > 0) {
            $target = $this->product((int) $row['target_id']);
            if ($target === null) {
                $this->markHeld($item, 'failed', ['error' => 'target_missing'], $now);
                return;
            }
        }
        $read = [
            'table' => 'products', 'model' => productModel(), 'lang' => $lang, 'group_id' => $groupId,
            'src' => $src, 'src_page' => null, 'target' => $target, 'target_page' => null,
            'title_key' => 'title', 'summary_key' => 'summary', 'body_field' => $bodyField,
        ];
        // 写内容与记「已写入」在同一事务里：先锁住这一项再确认没被取消、租约仍是自己的。
        // 取消要改这一行，会排在这次写库之后；只在写库前读一次状态不够，读完到写入之间仍可能被取消（评审 F03）
        $guard = function () use ($item): string {
            $locked = $this->db->lockRow('translation_batch_items', (int) $item['id']);
            return $locked !== null && self::stillHeld($locked, $item) ? '' : self::LEASE_LOST;
        };
        $settle = function (int $targetId, string $outcome) use ($id, $now): void {
            $this->db->update('translation_batch_items', ['status' => $outcome, 'target_id' => $targetId, 'error' => '', 'lease_until' => 0, 'updated_at' => $now], 'id = ?', [$id]);
        };
        try {
            $writer = new TranslationWrite($this->db);
            // 已有译文已发布、人改过或审过的不覆盖（写库层统一规则，见 TranslationWrite::apply），存为待审
            $written = $writer->apply($read, $translated, TranslationWrite::newRow('products', $src, $translated, $lang, $groupId, 'title', 'summary', $bodyField), $guard, $settle);
            if ($written['conflict'] === 'target_protected') {
                $written = $writer->propose($read, $translated, $guard, $settle);
            }
        } catch (Throwable $error) {
            // 结果还在，下一轮直接重试写库，不再调模型
            // 提交之后才抛错（例如提交后的回调）时这一项已是 applied，markHeld 对不上就不会把它改回去
            $this->markHeld($item, 'generated', ['error' => mb_substr($error->getMessage(), 0, 250)], $now);
            return;
        }
        match ($written['conflict']) {
            '', self::LEASE_LOST => null,   // 已在事务里记好；或这一项已不归本请求，什么都不动
            'target_exists' => $this->markHeld($item, 'exists', ['target_id' => $written['id'], 'error' => ''], $now),
            default => $this->markHeld($item, 'conflict', ['error' => $written['conflict']], $now),
        };
    }

    /**
     * 只改仍归本请求的项：状态、租约到期时间、尝试次数都与领取时一致（评审 F03）。
     * 被取消、租约过期后被别的页面重新领取（尝试次数或租约变了）的，一律不动，返回 false。
     * @param array<string,mixed> $held 领取（或生成完）时读到的项 @param array<string,mixed> $fields
     */
    private function markHeld(array $held, string $status, array $fields, int $now, int $leaseUntil = 0): bool
    {
        return $this->db->update('translation_batch_items', ['status' => $status, 'lease_until' => $leaseUntil, 'updated_at' => $now] + $fields,
            'id = ? AND status = ? AND lease_until = ? AND attempts = ?',
            [(int) $held['id'], (string) $held['status'], (int) $held['lease_until'], (int) $held['attempts']]) === 1;
    }

    /** @param array<string,mixed> $row 现在库里的项 @param array<string,mixed> $held */
    private static function stillHeld(array $row, array $held): bool
    {
        return (string) $row['status'] === (string) $held['status'] && (int) $row['lease_until'] === (int) $held['lease_until']
            && (int) $row['attempts'] === (int) $held['attempts'];
    }

    private function finishIfDone(int $batchId, int $now): void
    {
        $open = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "translation_batch_items WHERE batch_id = ? AND status IN ('pending', 'generating', 'generated')", [$batchId]);
        if ($open === 0) {
            $this->db->execute('UPDATE ' . DB_PREFIX . "translation_batches SET status = 'done', updated_at = ? WHERE id = ? AND status = 'running'", [$now, $batchId]);
        }
    }

    private function setStatus(int $batchId, string $status, int $now): void
    {
        $this->db->execute('UPDATE ' . DB_PREFIX . 'translation_batches SET status = ?, updated_at = ? WHERE id = ?', [$status, $now, $batchId]);
    }

    private function batchStatus(int $batchId): string
    {
        return (string) $this->db->fetchColumn('SELECT status FROM ' . DB_PREFIX . 'translation_batches WHERE id = ?', [$batchId]);
    }

    /** @return array<string,mixed>|null 未删除的产品 */
    private function product(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** 源产品在目标语言的现有译文 id（回收站里的不算），没有返回 0 */
    private function existingTarget(int $sourceId, string $lang): int
    {
        $groupId = (int) ($this->db->fetchColumn('SELECT translation_group_id FROM ' . DB_PREFIX . 'products WHERE id = ?', [$sourceId]) ?: $sourceId);
        return (int) ($this->db->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'products WHERE translation_group_id = ? AND lang = ? AND id <> ? AND deleted_at IS NULL LIMIT 1', [$groupId, $lang, $sourceId]) ?: 0);
    }

    /** 已有译文的原文在生成/审阅之后改过（未追踪的译文不知道来历，不算） @param array<string,mixed> $src */
    private function outdated(array $src, int $targetId): bool
    {
        $link = (new TranslationState($this->db))->link('products', $targetId);
        $target = $this->product($targetId);
        if ($link === null || $target === null) return false;
        return TranslationState::status($link, TranslationState::rowPrint($src, 'title', 'summary'), TranslationState::rowPrint($target, 'title', 'summary'))['outdated'];
    }

    private function openElsewhere(int $sourceId, string $lang): bool
    {
        return (bool) $this->db->fetchColumn('SELECT 1 FROM ' . DB_PREFIX . 'translation_batch_items i JOIN ' . DB_PREFIX . 'translation_batches b ON b.id = i.batch_id'
            . " WHERE i.source_id = ? AND i.lang = ? AND i.status IN ('pending', 'generating', 'generated') AND b.status IN ('running', 'paused') LIMIT 1", [$sourceId, $lang]);
    }
}
