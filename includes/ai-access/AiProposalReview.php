<?php

declare(strict_types=1);

require_once __DIR__ . '/AiArticleDraftRules.php';
require_once __DIR__ . '/AiPageContentRules.php';
require_once __DIR__ . '/AiPageDocumentSource.php';
require_once __DIR__ . '/AiProductContentRules.php';

/**
 * 提案审核页的只读视图（S3）：列出本人的提案集与项目，算出“当前 → 提案”的逐字段对照。
 * 只读，不写库、不开事务；应用/拒绝/撤销一律走 AiProposalService。
 */
final class AiProposalReview
{
    public const STATUSES = ['pending', 'applied', 'rejected', 'conflicted', 'cancelled', 'expired', 'reverted'];
    private const TEXT_FIELDS = ['title', 'subtitle', 'summary', 'tags'];
    private const MAX_PARAGRAPHS = 400;

    public function __construct(private Database $db)
    {
    }

    /**
     * 本人的提案集，按创建倒序；$pending 为 true 只取含待审项目的集合，否则只取全部已处理的。
     * @return list<array<string,mixed>>
     */
    public function sets(int $ownerId, bool $pending, int $limit = 20): array
    {
        $p = DB_PREFIX;
        $limit = max(1, min(50, $limit));
        $sets = $this->db->fetchAll(
            "SELECT s.id, s.public_id, s.source_ns, s.token_id, s.created_at, s.expires_at, t.name AS token_name"
            . " FROM {$p}ai_proposals s LEFT JOIN {$p}api_tokens t ON t.id = s.token_id"
            . " WHERE s.owner_id = ? AND " . ($pending ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM {$p}ai_proposal_items i WHERE i.proposal_id = s.id AND i.status = 'pending')"
            . " ORDER BY s.id DESC LIMIT {$limit}",
            [$ownerId]
        );
        if ($sets === []) return [];
        $ids = array_map(static fn(array $s): int => (int) $s['id'], $sets);
        $items = $this->db->fetchAll(
            "SELECT * FROM {$p}ai_proposal_items WHERE owner_id = ? AND proposal_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY proposal_id DESC, seq',
            array_merge([$ownerId], $ids)
        );
        $byProposal = [];
        foreach ($items as $item) $byProposal[(int) $item['proposal_id']][] = $this->itemView($item);
        foreach ($sets as &$set) $set['items'] = $byProposal[(int) $set['id']] ?? [];
        return $sets;
    }

    /** 待审项目数（菜单角标与配额提示用）。 */
    public function pendingCount(int $ownerId): int
    {
        return (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "ai_proposal_items WHERE owner_id = ? AND status = 'pending'", [$ownerId]);
    }

    /** @return array<string,mixed> */
    private function itemView(array $item): array
    {
        $input = json_decode((string) $item['input_json'], true);
        $input = is_array($input) ? $input : [];
        if ($item['operation'] === AiPageContentRules::OPERATION) return $this->pageView($item, $input);
        if ($item['operation'] === AiProductContentRules::OPERATION) return $this->productView($item, $input);
        $current = null;
        $channel = null;
        if ($item['operation'] === 'update') {
            $current = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE id = ?', [(int) $item['target_id']]);
        }
        $channelId = $item['operation'] === 'create' ? (int) $item['channel_id'] : (int) ($current['channel_id'] ?? 0);
        if ($channelId > 0) $channel = $this->db->fetchOne('SELECT id, name, lang FROM ' . DB_PREFIX . 'channels WHERE id = ?', [$channelId]);
        // 基线已变：应用时必然判为冲突，审核前就提示。
        $stale = $item['status'] === 'pending' && $item['operation'] === 'update'
            && ($current === null || !hash_equals((string) $item['base_revision'], AiArticleDraftRules::contentFingerprint($current)));

        $fields = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $input)) continue;
            $before = $current === null ? '' : (string) ($current[$field] ?? '');
            $fields[] = ['field' => $field, 'before' => $before, 'after' => (string) $input[$field], 'changed' => $before !== (string) $input[$field]];
        }
        $content = null;
        if (array_key_exists('content', $input)) {
            $before = self::paragraphs($current === null ? '' : (string) ($current['content'] ?? ''));
            $after = self::paragraphs((string) $input['content']);
            $content = ['diff' => self::diff($before, $after), 'changed' => $before !== $after];
        }
        return [
            'item' => $item,
            'title' => (string) ($input['title'] ?? $current['title'] ?? ''),
            'channel' => $channel,
            'stale' => $stale,
            'fields' => $fields,
            'content' => $content,
        ];
    }

    /**
     * 待审单页提案的预览文档：当前草稿套上提案改动，只在内存里算，不写库。
     * 页面已变或元素不再可改时返回 null（应用必然冲突，预览没有意义）。
     * @return array{page_id:int,lang:string,json:string}|null
     */
    public function previewDocument(int $ownerId, string $itemId): ?array
    {
        $item = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'ai_proposal_items WHERE public_id = ? AND owner_id = ?', [$itemId, $ownerId]);
        if ($item === null || $item['operation'] !== AiPageContentRules::OPERATION || $item['status'] !== 'pending') return null;
        $input = json_decode((string) $item['input_json'], true);
        $source = new AiPageDocumentSource($this->db);
        $page = $source->page((int) $item['target_id']);
        if (!is_array($input) || $page === null) return null;
        $state = $source->load($page);
        if ($state['legacy_html'] !== null || !hash_equals((string) $item['base_revision'], $state['base_revision'])) return null;
        $result = AiPageContentRules::apply(BloxDocumentPipeline::decode($state['document_json']), is_array($input['changes'] ?? null) ? $input['changes'] : []);
        if (!isset($result['document'])) return null;
        $document = $result['document'];
        return ['page_id' => (int) $page['id'], 'lang' => (string) $page['lang'], 'json' => json_encode(
            ['schema' => $document['schema'], 'settings' => $document['settings'], 'sections' => $document['sections']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        )];
    }

    /**
     * 单页内容提案：逐元素逐字段的“当前 → 提案”。当前值取页面现在的文档；页面已变或元素不再可改时标为过期。
     * @return array<string,mixed>
     */
    private function pageView(array $item, array $input): array
    {
        $source = new AiPageDocumentSource($this->db);
        $page = $source->page((int) $item['target_id']);
        $changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];
        $before = [];
        $stale = true;
        if ($page !== null) {
            try {
                $state = $source->load($page);
                if ($state['legacy_html'] === null) {
                    $result = AiPageContentRules::apply(BloxDocumentPipeline::decode($state['document_json']), $changes);
                    $before = $result['before'] ?? [];
                    $stale = isset($result['conflict']) || !hash_equals((string) $item['base_revision'], $state['base_revision']);
                }
            } catch (Throwable) {
                $stale = true;
            }
        }
        $text = static fn(string $field, string $value): string => $field === 'html' ? implode("\n", self::paragraphs($value)) : $value;
        $fields = [];
        foreach ($changes as $change) {
            if (!is_array($change)) continue;
            $field = (string) ($change['field'] ?? '');
            $id = (string) ($change['element_id'] ?? '');
            $old = $text($field, (string) ($before[$id][$field] ?? ''));
            $new = $text($field, (string) ($change['value'] ?? ''));
            $fields[] = ['field' => 'page_' . $field, 'label' => $id . ' · ' . __('aipr_field_page_' . $field), 'before' => $old, 'after' => $new, 'changed' => $old !== $new];
        }
        return [
            'item' => $item,
            'title' => (string) ($page['name'] ?? ('#' . (int) $item['target_id'])),
            'channel' => null,
            'stale' => $item['status'] === 'pending' && $stale,
            'fields' => $fields,
            'content' => null,
        ];
    }

    /**
     * 产品文案提案（T05）：摘要与 SEO 逐字段对照，详情按段落对照；当前值取产品行与 SEO metas。
     * 产品在提案后被改过、发布或删除时标为过期（应用必然冲突）。
     * @return array<string,mixed>
     */
    private function productView(array $item, array $input): array
    {
        $id = (int) $item['target_id'];
        $row = $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE id = ?', [$id]);
        $metas = AiProductContentRules::metas($this->db, $id);
        $current = $row === null ? [] : ['summary' => (string) ($row['summary'] ?? '')] + $metas;
        $fields = [];
        foreach (['summary', ...AiProductContentRules::META_FIELDS] as $field) {
            if (!array_key_exists($field, $input)) continue;
            $before = (string) ($current[$field] ?? '');
            $fields[] = ['field' => $field, 'label' => __('aipr_field_product_' . $field), 'before' => $before, 'after' => (string) $input[$field], 'changed' => $before !== (string) $input[$field]];
        }
        $content = null;
        if (array_key_exists('content', $input)) {
            $before = self::paragraphs((string) ($row['content'] ?? ''));
            $after = self::paragraphs((string) $input['content']);
            $content = ['diff' => self::diff($before, $after), 'changed' => $before !== $after];
        }
        return [
            'item' => $item,
            'title' => (string) ($row['title'] ?? ('#' . $id)),
            'channel' => null,
            'stale' => $item['status'] === 'pending' && ($row === null || !hash_equals((string) $item['base_revision'], AiProductContentRules::fingerprint($row, $metas))),
            'fields' => $fields,
            'content' => $content,
        ];
    }

    /**
     * 正文按段落切成纯文本（块级标签与 <br> 当分段），只用于对照显示，不回写。
     * @return list<string>
     */
    public static function paragraphs(string $html): array
    {
        $text = preg_replace('#<(?:br|/p|/div|/h[1-6]|/li|/blockquote|/tr|/pre|/figure)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }

    /**
     * 段落级对照（最长公共子序列）。超过上限的长文只比前 MAX_PARAGRAPHS 段，其余按新增/删除列出，不做平方级运算。
     * @param list<string> $a @param list<string> $b
     * @return list<array{op:string,text:string}>
     */
    public static function diff(array $a, array $b): array
    {
        $headA = array_slice($a, 0, self::MAX_PARAGRAPHS);
        $headB = array_slice($b, 0, self::MAX_PARAGRAPHS);
        $n = count($headA);
        $m = count($headB);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $headA[$i] === $headB[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $headA[$i] === $headB[$j]) {
                $out[] = ['op' => 'same', 'text' => $headA[$i]];
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $out[] = ['op' => 'add', 'text' => $headB[$j++]];
            } else {
                $out[] = ['op' => 'del', 'text' => $headA[$i++]];
            }
        }
        foreach (array_slice($a, self::MAX_PARAGRAPHS) as $text) $out[] = ['op' => 'del', 'text' => $text];
        foreach (array_slice($b, self::MAX_PARAGRAPHS) as $text) $out[] = ['op' => 'add', 'text' => $text];
        return $out;
    }
}
