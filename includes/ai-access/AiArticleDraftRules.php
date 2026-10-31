<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/HtmlPolicy.php';

/**
 * 文章草稿提案的输入规范化与目标政策（契约 article_draft.v1）。
 * 只做确定性校验；不调用模型、不访问网络。数据库读取由调用方决定是否在锁内进行。
 */
final class AiArticleDraftRules
{
    public const CONTRACT = 'article_draft.v1';
    public const MAX_ITEMS = 10;

    private const TEXT_LIMITS = ['title' => 255, 'subtitle' => 255, 'summary' => 2000, 'tags' => 255];
    private const CONTENT_MAX_BYTES = 200000;
    private const CREATE_KEYS = ['op', 'lang', 'channel_id', 'title', 'subtitle', 'summary', 'content', 'tags'];
    private const UPDATE_KEYS = ['op', 'id', 'lang', 'title', 'subtitle', 'summary', 'content', 'tags'];
    /** 被提案修改、撤销时恢复的字段 */
    public const EDITABLE = ['title', 'subtitle', 'summary', 'content', 'tags'];
    private const FINGERPRINT = ['id', 'type', 'lang', 'channel_id', 'translation_group_id', 'status', 'deleted_at',
        'title', 'subtitle', 'summary', 'content', 'tags', 'slug', 'publish_time', 'updated_at'];

    /**
     * @param list<mixed> $items
     * @return list<array<string,int|string>>
     */
    public static function normalizeItems(array $items): array
    {
        if ($items === [] || !array_is_list($items) || count($items) > self::MAX_ITEMS) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        return array_map(self::normalize(...), $items);
    }

    /** @return array<string,int|string> */
    public static function normalize(mixed $item): array
    {
        if (!is_array($item) || array_is_list($item) || !is_string($item['op'] ?? null)) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        $op = $item['op'];
        $allowed = match ($op) {
            'create' => self::CREATE_KEYS,
            'update' => self::UPDATE_KEYS,
            default => throw new RuntimeException('ai_proposal_invalid_input', 400),
        };
        // 未知字段一律拒绝：status、publish_time、type 等不能经由提案改变。
        if (array_diff(array_keys($item), $allowed) !== []) throw new RuntimeException('ai_proposal_invalid_input', 400);

        $out = ['op' => $op, 'lang' => self::lang($item['lang'] ?? null)];
        if ($op === 'create') {
            $out['channel_id'] = self::positiveId($item['channel_id'] ?? null);
            foreach (['title', 'content'] as $required) {
                if (!array_key_exists($required, $item)) throw new RuntimeException('ai_proposal_invalid_input', 400);
            }
        } else {
            $out['id'] = self::positiveId($item['id'] ?? null);
        }
        $changed = 0;
        foreach (self::EDITABLE as $field) {
            if (!array_key_exists($field, $item)) continue;
            $out[$field] = $field === 'content' ? self::content($item[$field]) : self::text($field, $item[$field]);
            $changed++;
        }
        if ($changed === 0 || (isset($out['title']) && $out['title'] === '')) throw new RuntimeException('ai_proposal_invalid_input', 400);
        ksort($out);
        return $out;
    }

    /** 规范化项目列表的哈希；同一幂等键必须对应同一哈希。 */
    public static function payloadHash(string $tool, array $normalizedItems): string
    {
        return hash('sha256', json_encode([$tool, self::CONTRACT, $normalizedItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** 目标文章行的版本指纹：任何人工保存、发布、删除、换栏目/语言/类型都会改变它。 */
    public static function contentFingerprint(array $row): string
    {
        $values = [];
        foreach (self::FINGERPRINT as $column) {
            $value = $row[$column] ?? null;
            $values[$column] = $value === null ? null : (string) $value;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function channelFingerprint(array $row): string
    {
        $values = [];
        foreach (['id', 'type', 'lang', 'status', 'parent_id'] as $column) $values[$column] = (string) ($row[$column] ?? '');
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /** 新建只能落在启用语言下、启用中的 list 文章栏目。 */
    public static function channelAcceptsDraft(?array $channel, string $lang): bool
    {
        return $channel !== null && (string) $channel['type'] === 'list' && (int) $channel['status'] === 1
            && (string) $channel['lang'] === $lang;
    }

    /** 更新只能针对同语言、未删除的文章草稿。 */
    public static function contentAcceptsUpdate(?array $content, string $lang): bool
    {
        return $content !== null && (string) $content['type'] === 'article' && (int) $content['status'] === 0
            && $content['deleted_at'] === null && (string) $content['lang'] === $lang;
    }

    /**
     * 语言政策与 enabledLanguages() 一致：settings.enabled_languages（JSON 列表）加上 site_lang；
     * 设置为空或无效时不按语言限制（老站兼容，同 enabledLanguages() 退化到全部语言）。
     * 不加载 functions.php（入口不能带后台运行时）；栏目/文章的语言必须与提案一致这一条另行校验。
     */
    public static function languageEnabled(Database $database, string $lang): bool
    {
        $rows = $database->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX . 'settings WHERE `key` IN (?, ?)', ['enabled_languages', 'site_lang']);
        $enabled = [];
        $configured = false;
        foreach ($rows as $row) {
            if ($row['key'] === 'site_lang') $enabled[] = (string) $row['value'];
            if ($row['key'] === 'enabled_languages') {
                $list = json_decode((string) $row['value'], true);
                if (is_array($list)) {
                    foreach ($list as $code) {
                        if (!is_string($code)) continue;
                        $enabled[] = $code;
                        $configured = true;
                    }
                }
            }
        }
        return !$configured || in_array($lang, $enabled, true);
    }

    private static function lang(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/D', $value) !== 1) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        return $value;
    }

    private static function positiveId(mixed $value): int
    {
        if (!is_int($value) || $value < 1 || $value > 4294967295) throw new RuntimeException('ai_proposal_invalid_input', 400);
        return $value;
    }

    private static function text(string $field, mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        $value = trim($value);
        if (mb_strlen($value) > self::TEXT_LIMITS[$field]) throw new RuntimeException('ai_proposal_invalid_input', 400);
        return strip_tags($value);
    }

    private static function content(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > self::CONTENT_MAX_BYTES || !mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        return HtmlPolicy::richText($value, true);
    }
}
