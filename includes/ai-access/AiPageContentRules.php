<?php

declare(strict_types=1);

require_once __DIR__ . '/AiBloxPageReader.php';
require_once dirname(__DIR__) . '/HtmlPolicy.php';

/**
 * S5-03 单页内容提案的输入规则与文档改写：只改标题文字、富文本正文、图片替代文字三种字段。
 * 图片替换、链接修改推迟；布局、样式、代码元素、可见条件与其它数据键一律不碰。
 *
 * 一个提案项对应一页：base_revision 必须等于提交时页面的当前修订号，应用时还要再等于一次
 * （人工改过就冲突）；元素按稳定 ID 定位，ID 缺失、重复、位于循环模板内或绑定站点数据都拒绝。
 */
final class AiPageContentRules
{
    public const TOOL = 'page_content_propose';
    public const CONTRACT = 'page_content.v1';
    public const OPERATION = 'page_edit';
    public const MAX_CHANGES = 50;
    /** 元素类型 => [字段 => 净化后最大字符数]。与 Blox 控件上限一致（标题 textarea 2000，图片 alt text 控件 2000 收紧到 500） */
    public const FIELDS = ['heading' => ['text' => 2000], 'text' => ['html' => AiBloxPageReader::MAX_FIELD_CHARS], 'image' => ['alt' => 500]];
    private const KEYS = ['page_id', 'lang', 'base_revision', 'changes'];
    private const CHANGE_KEYS = ['element_id', 'field', 'value'];
    private const RAW_VALUE_BYTES = 120000;

    /**
     * 规范化工具参数（不含幂等键）。值在这里一次性净化，哈希与入库都基于净化后的结果。
     * @param array<string,mixed> $args
     * @return array{op:string,page_id:int,lang:string,base_revision:string,changes:list<array{element_id:string,field:string,value:string}>}
     */
    public static function normalize(array $args): array
    {
        if (array_diff(array_keys($args), self::KEYS) !== [] || !is_int($args['page_id'] ?? null) || $args['page_id'] < 1
            || !is_string($args['lang'] ?? null) || preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/D', $args['lang']) !== 1
            || !is_string($args['base_revision'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $args['base_revision']) !== 1
            || !is_array($args['changes'] ?? null) || $args['changes'] === [] || !array_is_list($args['changes']) || count($args['changes']) > self::MAX_CHANGES) {
            throw new RuntimeException('ai_proposal_invalid_input', 400);
        }
        $changes = [];
        $seen = [];
        foreach ($args['changes'] as $change) {
            if (!is_array($change) || array_is_list($change) || array_diff(array_keys($change), self::CHANGE_KEYS) !== []
                || !is_string($change['element_id'] ?? null) || preg_match(AiBloxPageReader::ID_PATTERN, $change['element_id']) !== 1
                || !in_array($change['field'] ?? null, ['text', 'html', 'alt'], true) || !is_string($change['value'] ?? null)
                || strlen($change['value']) > self::RAW_VALUE_BYTES || !mb_check_encoding($change['value'], 'UTF-8')) {
                throw new RuntimeException('ai_proposal_invalid_input', 400);
            }
            $key = $change['element_id'] . "\0" . $change['field'];
            if (isset($seen[$key])) throw new RuntimeException('ai_proposal_invalid_input', 400);
            $seen[$key] = true;
            $changes[] = ['element_id' => $change['element_id'], 'field' => $change['field'], 'value' => self::cleanValue($change['field'], $change['value'])];
        }
        return ['op' => self::OPERATION, 'page_id' => $args['page_id'], 'lang' => $args['lang'], 'base_revision' => $args['base_revision'], 'changes' => $changes];
    }

    public static function payloadHash(array $normalized): string
    {
        return hash('sha256', json_encode([self::TOOL, self::CONTRACT, $normalized], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 在已解码的文档上逐项改写；任一项不满足就整体返回冲突原因，不产生部分结果。
     * @param array<string,mixed> $document BloxDocumentPipeline::decode() 的结果
     * 撤销时写回的是改前原值（本来就在文档里），$limits = false 不再套提案长度上限。
     * @param list<array{element_id:string,field:string,value:string}> $changes
     * @return array{document:array<string,mixed>,before:array<string,array<string,string>>}|array{conflict:string}
     */
    public static function apply(array $document, array $changes, bool $limits = true): array
    {
        $index = [];
        $sections = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        foreach ($sections as $s => $section) {
            if (!is_array($section)) continue;
            self::note($index, $section['id'] ?? null, null);
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $c => $column) {
                if (!is_array($column)) continue;
                self::note($index, $column['id'] ?? null, null);
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $e => $element) {
                    if (is_array($element)) self::walk($index, $element, ['sections', $s, 'columns', $c, 'elements', $e], false, 1);
                }
            }
        }
        $before = [];
        foreach ($changes as $change) {
            $hits = $index[$change['element_id']] ?? [];
            if ($hits === []) return ['conflict' => 'element_missing'];
            if (count($hits) > 1) return ['conflict' => 'element_duplicate'];
            $hit = $hits[0];
            if ($hit === null) return ['conflict' => 'element_missing'];   // 区块/列的 ID，不是元素
            if ($hit['template']) return ['conflict' => 'element_in_template'];
            $element = self::at($document, $hit['path']);
            $type = (string) ($element['type'] ?? '');
            if (!isset(self::FIELDS[$type][$change['field']])) return ['conflict' => 'element_type_changed'];
            $data = is_array($element['data'] ?? null) ? $element['data'] : [];
            foreach (AiBloxPageReader::SITE_BINDINGS as $binding) {
                if (is_scalar($data[$binding] ?? null) && trim((string) $data[$binding]) !== '') return ['conflict' => 'element_bound'];
            }
            if ($limits && mb_strlen($change['value']) > self::FIELDS[$type][$change['field']]) return ['conflict' => 'value_too_long'];
            $before[$change['element_id']][$change['field']] = is_string($data[$change['field']] ?? null) ? $data[$change['field']] : '';
            $data[$change['field']] = $change['value'];
            $element['data'] = $data;
            $document = self::put($document, $hit['path'], $element);
        }
        return ['document' => $document, 'before' => $before];
    }


    private static function cleanValue(string $field, string $value): string
    {
        // 动态标签与服务端标签会在前台代入站点数据，属于设计能力，不经 AI 提案写入
        if (str_contains($value, '{{') || str_contains($value, '{yk:')) throw new RuntimeException('ai_proposal_invalid_input', 400);
        if ($field === 'html') return trim(HtmlPolicy::richText($value));
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        return $field === 'alt' ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : trim(str_replace("\r\n", "\n", $value));
    }

    /** @param array<string,list<array{path:list<int|string>,template:bool}|null>> $index */
    private static function note(array &$index, mixed $id, ?array $hit): void
    {
        if ((is_string($id) || is_int($id)) && (string) $id !== '') $index[(string) $id][] = $hit;
    }

    /**
     * 记录元素及其全部后代的 ID；循环宿主（带 _query 的容器）的后代是模板，标记后不可改。
     * @param array<string,list<array{path:list<int|string>,template:bool}|null>> $index
     * @param list<int|string> $path
     */
    private static function walk(array &$index, array $element, array $path, bool $template, int $depth): void
    {
        self::note($index, $element['id'] ?? null, ['path' => $path, 'template' => $template]);
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        if ($depth > AiBloxPageReader::MAX_DEPTH * 4 || !is_array($data['children'] ?? null)) return;
        $loop = isset($data['_query']);
        foreach ($data['children'] as $i => $child) {
            if (is_array($child)) self::walk($index, $child, array_merge($path, ['data', 'children', $i]), $template || $loop, $depth + 1);
        }
    }

    /** @param list<int|string> $path @return array<string,mixed> */
    private static function at(array $document, array $path): array
    {
        $node = $document;
        foreach ($path as $key) $node = is_array($node[$key] ?? null) ? $node[$key] : [];
        return $node;
    }

    /** @param list<int|string> $path */
    private static function put(array $document, array $path, array $value): array
    {
        if ($path === []) return $value;
        $key = array_shift($path);
        $child = is_array($document[$key] ?? null) ? $document[$key] : [];
        $document[$key] = self::put($child, $path, $value);
        return $document;
    }
}
