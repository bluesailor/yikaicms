<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/HtmlPolicy.php';
require_once __DIR__ . '/AiArticleDraftRules.php';

/**
 * 产品内容提案的输入规范化与目标政策（评审 T05，契约 product_content.v1）。
 *
 * 外部客户端只能改写文案：摘要、详情、SEO 标题/关键词/描述。标题、型号、参数表、价格、分类、
 * 状态这些事实或商业字段只读——提案里出现就整次拒绝。目标只能是同语言、未删除的产品草稿；
 * 已发布产品可读不可提案（外部令牌不得改在线内容）。SEO 不在产品表上，存在 metas（product/seo_*）。
 */
final class AiProductContentRules
{
    public const TOOL = 'product_content_propose';
    public const CONTRACT = 'product_content.v1';
    public const OPERATION = 'product_update';
    public const MAX_ITEMS = 10;
    /** 产品表上可被提案改写的列 */
    public const ROW_FIELDS = ['summary', 'content'];
    /** metas 里可被提案改写的键（owner_type = product） */
    public const META_FIELDS = ['seo_title', 'seo_keywords', 'seo_description'];
    private const TEXT_LIMITS = ['summary' => 2000, 'seo_title' => 255, 'seo_keywords' => 255, 'seo_description' => 500];
    private const CONTENT_MAX_BYTES = 200000;
    private const KEYS = ['id', 'lang', 'base_revision', 'summary', 'content', 'seo_title', 'seo_keywords', 'seo_description'];
    /** 指纹覆盖的产品列：事实字段、可写字段、状态与删除——任何人工保存都会动 updated_at */
    private const FINGERPRINT = ['id', 'lang', 'translation_group_id', 'category_id', 'title', 'subtitle', 'slug', 'model', 'specs', 'tags',
        'material', 'scene', 'summary', 'content', 'status', 'deleted_at', 'updated_at'];

    /** @return list<string> 全部可提案字段 */
    public static function editable(): array
    {
        return [...self::ROW_FIELDS, ...self::META_FIELDS];
    }

    /**
     * @param list<mixed> $items
     * @return list<array<string,int|string>>
     */
    public static function normalizeItems(array $items): array
    {
        if ($items === [] || !array_is_list($items) || count($items) > self::MAX_ITEMS) throw new RuntimeException('ai_proposal_invalid_input', 400);
        $out = array_map(self::normalize(...), $items);
        $ids = array_map(static fn (array $item): int => (int) $item['id'], $out);
        // 一次提交里同一产品只能出现一次：两项互相覆盖时审核与撤销都说不清
        if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('ai_proposal_invalid_input', 400);
        return $out;
    }

    /** @return array<string,int|string> */
    public static function normalize(mixed $item): array
    {
        // 白名单之外的键（title、model、specs、price、status……）一律拒绝，不做静默丢弃
        if (!is_array($item) || array_is_list($item) || array_diff(array_keys($item), self::KEYS) !== []) throw new RuntimeException('ai_proposal_invalid_input', 400);
        $id = $item['id'] ?? null;
        $lang = $item['lang'] ?? null;
        $base = $item['base_revision'] ?? null;
        if (!is_int($id) || $id < 1 || $id > 4294967295 || !is_string($lang) || preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/D', $lang) !== 1
            || !is_string($base) || preg_match('/^[0-9a-f]{64}$/D', $base) !== 1) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        $out = ['op' => self::OPERATION, 'id' => $id, 'lang' => $lang, 'base_revision' => $base];
        $changed = 0;
        foreach (self::editable() as $field) {
            if (!array_key_exists($field, $item)) continue;
            $out[$field] = $field === 'content' ? self::content($item[$field]) : self::text($field, $item[$field]);
            $changed++;
        }
        if ($changed === 0) throw new RuntimeException('ai_proposal_invalid_input', 400);
        ksort($out);
        return $out;
    }

    public static function payloadHash(array $normalizedItems): string
    {
        return hash('sha256', json_encode([self::TOOL, self::CONTRACT, $normalizedItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 产品版本指纹：产品行 + 三个 SEO meta。人工保存、发布、删除、改 SEO 都会改变它。
     * @param array<string,mixed> $row @param array<string,string> $metas
     */
    public static function fingerprint(array $row, array $metas): string
    {
        $values = [];
        foreach (self::FINGERPRINT as $column) {
            $value = $row[$column] ?? null;
            $values[$column] = $value === null ? null : (string) $value;
        }
        foreach (self::META_FIELDS as $key) $values['meta:' . $key] = (string) ($metas[$key] ?? '');
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,string> 产品的三个 SEO meta（缺的为空串） */
    public static function metas(Database $db, int $productId): array
    {
        $out = array_fill_keys(self::META_FIELDS, '');
        $rows = $db->fetchAll('SELECT meta_key, meta_value FROM ' . DB_PREFIX . "metas WHERE owner_type = 'product' AND owner_id = ? AND meta_key IN ("
            . implode(',', array_fill(0, count(self::META_FIELDS), '?')) . ')', array_merge([$productId], self::META_FIELDS));
        foreach ($rows as $row) $out[(string) $row['meta_key']] = (string) ($row['meta_value'] ?? '');
        return $out;
    }

    /** 提案只能落在同语言、未删除、未发布的产品上，且语言仍启用 */
    public static function acceptsUpdate(Database $db, ?array $row, string $lang): bool
    {
        return $row !== null && $row['deleted_at'] === null && (int) $row['status'] === 0 && (string) $row['lang'] === $lang
            && AiArticleDraftRules::languageEnabled($db, $lang);
    }

    private static function text(string $field, mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        $value = trim(strip_tags($value));
        if (mb_strlen($value) > self::TEXT_LIMITS[$field]) throw new RuntimeException('ai_proposal_invalid_input', 400);
        return $value;
    }

    private static function content(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > self::CONTENT_MAX_BYTES || !mb_check_encoding($value, 'UTF-8')) throw new RuntimeException('ai_proposal_invalid_input', 400);
        return HtmlPolicy::richText($value, true);
    }
}
