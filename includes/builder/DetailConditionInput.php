<?php
/**
 * 完整条件提交的**严格校验**（TASK-006 第一批）。
 *
 * 为什么不能只用 DetailTemplateResolver::normalizeScope()：它是**归一化**工具，对非法规则
 * 采取 fail-closed 静默丢弃；而完整条件面板的提交必须让用户知道"哪一条不合法"，不能丢完还报保存成功。
 * 因此这里逐条严格校验，只有全部合法才返回可直接落库的 v2 作用域；非法一律带原因失败。
 *
 * 允许的类型与上限**完全以 resolver 为准**（KINDS / MAX_RULES / MAX_IDS_PER_RULE / MAX_PRIORITY），
 * 不另立一套匹配或规则语义；exclude 沿用 resolver 的"不支持 all"。
 */
declare(strict_types=1);

final class DetailConditionInput
{
    /**
     * @param mixed $raw 已解码的提交内容（数组）
     * @param string $expectedContentType 'product' | 'article'（由真实模板类型推导）
     * @param array<string,mixed> $allowedLanguages availableLanguages()
     * @return array{ok:bool,error:string,scope:array<string,mixed>}
     */
    public static function validate(mixed $raw, string $expectedContentType, array $allowedLanguages): array
    {
        if (!is_array($raw)) {
            return self::fail('not_object');
        }
        if (!in_array($expectedContentType, DetailTemplateResolver::CONTENT_TYPES, true)) {
            return self::fail('bad_template_type');
        }

        $version = $raw['version'] ?? null;
        if ((string) $version !== (string) DetailTemplateResolver::VERSION) {
            return self::fail('bad_version');
        }

        $contentType = is_string($raw['content_type'] ?? null) ? trim((string) $raw['content_type']) : '';
        if (!in_array($contentType, DetailTemplateResolver::CONTENT_TYPES, true)) {
            return self::fail('bad_content_type');
        }
        if ($contentType !== $expectedContentType) {
            // 真实模板类型与提交类型必须一致（产品模板不接受 article 条件）
            return self::fail('content_type_mismatch');
        }

        $lang = is_string($raw['lang'] ?? null) ? trim((string) $raw['lang']) : '';
        if ($lang === '' || !array_key_exists($lang, $allowedLanguages)) {
            return self::fail('bad_lang');
        }

        $source = (string) ($raw['source'] ?? DetailTemplateResolver::SOURCE_CUSTOM);
        if (!in_array($source, [DetailTemplateResolver::SOURCE_CUSTOM, DetailTemplateResolver::SOURCE_NATIVE], true)) {
            return self::fail('bad_source');
        }

        $priority = $raw['priority'] ?? 0;
        // 先判"是不是整数"，再判范围：负数与超限都归到 priority_out_of_range，非数字才是 bad_priority
        if (!is_int($priority) && !(is_string($priority) && preg_match('/^-?\d+$/D', $priority) === 1)) {
            return self::fail('bad_priority');
        }
        $priority = (int) $priority;
        if ($priority < 0 || $priority > DetailTemplateResolver::MAX_PRIORITY) {
            // 严格校验：超出范围要报错，不能静默夹取成边界值（用户提交的和落库的必须一致）
            return self::fail('priority_out_of_range');
        }

        $include = self::rules($raw['include'] ?? [], true);
        if (isset($include['error'])) {
            return self::fail('include_' . $include['error']);
        }
        $exclude = self::rules($raw['exclude'] ?? [], false);
        if (isset($exclude['error'])) {
            return self::fail('exclude_' . $exclude['error']);
        }

        return [
            'ok' => true,
            'error' => '',
            'scope' => [
                'version' => DetailTemplateResolver::VERSION,
                'content_type' => $contentType,
                'lang' => $lang,
                'source' => $source,
                'priority' => $priority,
                'include' => $include['rules'],
                'exclude' => $exclude['rules'],
            ],
        ];
    }

    /**
     * 规则数组严格校验：结构、kind、ids、上限，逐条给出可读原因（不静默丢弃）。
     *
     * @return array{rules?:list<array{kind:string,ids:list<int>,include_children:bool}>,error?:string}
     */
    private static function rules(mixed $raw, bool $allowAll): array
    {
        if (!is_array($raw) || ($raw !== [] && array_keys($raw) !== range(0, count($raw) - 1))) {
            return ['error' => 'not_list'];
        }
        if (count($raw) > DetailTemplateResolver::MAX_RULES) {
            return ['error' => 'too_many_rules'];
        }

        $rules = [];
        foreach (array_values($raw) as $index => $item) {
            if (!is_array($item)) {
                return ['error' => 'rule_not_object_at_' . $index];
            }
            $kind = is_string($item['kind'] ?? null) ? (string) $item['kind'] : '';
            if (!in_array($kind, DetailTemplateResolver::KINDS, true)) {
                return ['error' => 'unknown_kind_at_' . $index];
            }
            if ($kind === 'all') {
                if (!$allowAll) {
                    return ['error' => 'all_not_allowed_at_' . $index];
                }
                $rules[] = ['kind' => 'all', 'ids' => [], 'include_children' => false];
                continue;
            }

            $ids = $item['ids'] ?? null;
            if (!is_array($ids) || $ids === []) {
                return ['error' => 'missing_ids_at_' . $index];
            }
            if (count($ids) > DetailTemplateResolver::MAX_IDS_PER_RULE) {
                return ['error' => 'too_many_ids_at_' . $index];
            }
            $clean = [];
            foreach (array_values($ids) as $id) {
                if (!is_int($id) && !is_string($id)) {
                    return ['error' => 'bad_id_at_' . $index];
                }
                // 与 resolver 的 normalizeIds 同一口径（正整数、无前导零、最多 10 位）：
                // 整数分支也必须走这条规则，否则 0 与负数会被"直接赋值"放过去
                if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $id) !== 1) {
                    return ['error' => 'bad_id_at_' . $index];
                }
                $clean[] = (int) $id;
            }
            $clean = array_values(array_unique($clean));

            $children = $item['include_children'] ?? false;
            if (!is_bool($children)) {
                return ['error' => 'bad_children_flag_at_' . $index];
            }
            $rules[] = [
                'kind' => $kind,
                'ids' => $clean,
                // 子级只对分类有意义；item 一律 false（与 resolver 归一后的形状一致）
                'include_children' => $kind === 'category' ? $children : false,
            ];
        }

        return ['rules' => $rules];
    }

    /** @return array{ok:bool,error:string,scope:array<string,mixed>} */
    private static function fail(string $reason): array
    {
        return ['ok' => false, 'error' => $reason, 'scope' => []];
    }
}
