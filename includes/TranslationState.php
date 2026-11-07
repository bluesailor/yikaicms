<?php
/**
 * 译文来源与审阅状态（评审 T04）。
 *
 * 每条译文一行 translation_links：生成（或人审过）时源与译文的「可翻译字段指纹」，
 * 以及受保护译文上待人审阅的新译文。状态在读取时用当前指纹比出来，不另存：
 *   outdated = 源的可翻译字段在那之后改过；edited = 译文在机器写入之后被人改过。
 * 机器写入且没人改过的译文可以被重新翻译覆盖；其余（已审、已改、T04 之前的未追踪译文）受保护。
 * 设计：yikaicms-docs/T04-源变更追踪与译文保护-设计-20261107.md
 */

declare(strict_types=1);

final class TranslationState
{
    /** 模型会翻的字段（TranslationWrite::source() 的形状）：只有这些变了才算源变更 */
    public const TRACKED = [
        'title', 'summary', 'secondary_summary', 'content', 'specs', 'requirements', 'seo_title', 'seo_keywords', 'seo_description',
        'subtitle', 'tags', 'department', 'location', 'client_name', 'industry', 'duration', 'result_metric',
    ];

    public function __construct(private Database $db)
    {
    }

    /** @param array<string,mixed> $shape TranslationWrite::source() 的结果 */
    public static function fingerprint(array $shape): string
    {
        $values = [];
        foreach (self::TRACKED as $field) {
            $value = $shape[$field] ?? null;
            // 参数表一类的 JSON 按解析后的值算：保存时「{}」写成「[]」、键序或转义变了都不算内容改动
            $decoded = is_string($value) && preg_match('/^\s*[\[{]/', $value) ? json_decode($value, true) : $value;
            if (is_array($decoded)) {
                if ($decoded !== []) $values[$field] = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } elseif ($value !== null && (string) $value !== '') $values[$field] = (string) $value;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * 一行内容的可翻译字段指纹（单页的正文在栏目下的正文行里，传 $bodyRow）。
     * @param array<string,mixed> $row @param array<string,mixed>|null $bodyRow
     */
    public static function rowFingerprint(array $row, string $titleKey, string $summaryKey, ?array $bodyRow = null): string
    {
        require_once ROOT_PATH . '/includes/content_translation.php';
        require_once ROOT_PATH . '/includes/TranslationWrite.php';
        $body = $bodyRow ?? $row;
        return self::fingerprint(TranslationWrite::source($row, $titleKey, $summaryKey, $body, translationBodyField($body)));
    }

    /**
     * 读时状态。没有链接记录（或只为存待审结果建的）= untracked：不知道它从哪来、有没有人改过，按受保护处理。
     * @param array<string,mixed>|null $link
     * @return array{state:string,outdated:bool,edited:bool,reviewed:bool,proposal:bool,protected:bool}
     */
    public static function status(?array $link, string $sourceFp, string $targetFp): array
    {
        $proposal = $link !== null && (string) ($link['proposal_json'] ?? '') !== '';
        if ($link === null || (string) $link['state'] === 'untracked') {
            return ['state' => 'untracked', 'outdated' => false, 'edited' => false, 'reviewed' => false, 'proposal' => $proposal, 'protected' => true];
        }
        $outdated = (string) $link['source_fp'] !== $sourceFp;
        $edited = (string) $link['target_fp'] !== $targetFp;
        $reviewed = (string) $link['state'] === 'reviewed';
        return ['state' => $outdated ? 'outdated' : 'current', 'outdated' => $outdated, 'edited' => $edited,
            'reviewed' => $reviewed, 'proposal' => $proposal, 'protected' => $reviewed || $edited];
    }

    /** @return array<string,mixed>|null */
    public function link(string $table, int $targetId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'translation_links WHERE table_name = ? AND target_id = ?', [$table, $targetId]);
    }

    /**
     * @param list<int> $targetIds
     * @return array<int,array<string,mixed>> 按 target_id
     */
    public function links(string $table, array $targetIds): array
    {
        $targetIds = array_values(array_unique(array_filter(array_map('intval', $targetIds), static fn (int $id): bool => $id > 0)));
        if ($targetIds === []) return [];
        $rows = $this->db->fetchAll('SELECT * FROM ' . DB_PREFIX . 'translation_links WHERE table_name = ? AND target_id IN ('
            . implode(',', array_fill(0, count($targetIds), '?')) . ')', array_merge([$table], $targetIds));
        $byTarget = [];
        foreach ($rows as $row) $byTarget[(int) $row['target_id']] = $row;
        return $byTarget;
    }

    /** 机器写入了整份译文：记下这一刻源与译文的指纹，清掉旧的待审结果与审阅记录 */
    public function recordMachine(string $table, int $targetId, int $sourceId, string $lang, string $sourceFp, string $targetFp, int $now): void
    {
        $this->upsert($table, $targetId, [
            'source_id' => $sourceId, 'lang' => $lang, 'source_fp' => $sourceFp, 'target_fp' => $targetFp, 'state' => 'machine',
            'reviewed_by' => 0, 'reviewed_at' => 0, 'proposal_json' => null, 'proposal_source_fp' => '', 'proposal_target_fp' => '', 'proposal_at' => 0,
        ], $now);
    }

    /** 人确认这份译文对应当前原文：从现在的指纹重新算起 */
    public function markReviewed(string $table, int $targetId, int $sourceId, string $lang, string $sourceFp, string $targetFp, int $adminId, int $now): void
    {
        $this->upsert($table, $targetId, [
            'source_id' => $sourceId, 'lang' => $lang, 'source_fp' => $sourceFp, 'target_fp' => $targetFp, 'state' => 'reviewed',
            'reviewed_by' => $adminId, 'reviewed_at' => $now,
        ], $now);
    }

    /**
     * 受保护译文的新译文：只存，不写内容。未追踪的译文借这一行存放，状态仍是 untracked。
     * @param array<string,mixed> $translated
     */
    public function propose(string $table, int $targetId, int $sourceId, string $lang, array $translated, string $sourceFp, string $targetFp, int $now): void
    {
        $fields = [
            'source_id' => $sourceId, 'lang' => $lang,
            'proposal_json' => json_encode($translated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'proposal_source_fp' => $sourceFp, 'proposal_target_fp' => $targetFp, 'proposal_at' => $now,
        ];
        if ($this->link($table, $targetId) === null) $fields['state'] = 'untracked';
        $this->upsert($table, $targetId, $fields, $now);
    }

    public function discardProposal(string $table, int $targetId, int $now): void
    {
        $this->db->execute('UPDATE ' . DB_PREFIX . "translation_links SET proposal_json = NULL, proposal_source_fp = '', proposal_target_fp = '', proposal_at = 0, updated_at = ?"
            . ' WHERE table_name = ? AND target_id = ?', [$now, $table, $targetId]);
    }

    /** @param array<string,mixed> $fields */
    private function upsert(string $table, int $targetId, array $fields, int $now): void
    {
        // 不看 update 的影响行数：MySQL 值没变时报 0 行，会误走插入撞唯一键
        if ($this->link($table, $targetId) !== null) {
            $this->db->update('translation_links', $fields + ['updated_at' => $now], 'table_name = ? AND target_id = ?', [$table, $targetId]);
            return;
        }
        $this->db->insert('translation_links', $fields + [
            'table_name' => $table, 'target_id' => $targetId, 'source_fp' => '', 'target_fp' => '', 'state' => 'machine',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
