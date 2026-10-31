<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';
require_once __DIR__ . '/AiPageDocumentSource.php';
require_once dirname(__DIR__) . '/HtmlPolicy.php';

/**
 * 普通单页 Blox 文档的只读投影（S5-01；S5-02 起经 MCP 的 page_list/page_read 工具对外，不写库）。
 *
 * 只输出白名单内容字段；布局/样式、可见条件、查询、自定义 CSS 与代码元素一律不出现在结果里。
 * 元素 ID 缺失、重复或格式不合法时只读列出、标记不可寻址，不为它补造 ID（补造会改文档）。
 */
final class AiBloxPageReader
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 100;
    /** 与保存管线的元素上限一致；超出视为异常文档，整页拒绝而不是截断后假装完整 */
    public const MAX_ELEMENTS = 2000;
    public const MAX_DEPTH = 8;
    public const MAX_FIELD_CHARS = 20000;
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /** 元素类型 => [数据键 => 字段类别]。是 BloxMaintenanceMode 内容键的严格子集 */
    private const FIELDS = [
        'heading' => ['text' => 'text', 'url' => 'url'],
        'text' => ['html' => 'richtext'],
        'image' => [
            'src' => 'image', 'alt' => 'text', 'link_url' => 'url',
            'overlay_title' => 'text', 'overlay_text' => 'text', 'overlay_button_label' => 'text',
            'lightbox_caption' => 'text',
        ],
        'button' => ['text' => 'text', 'url' => 'url'],
    ];
    /** 站点数据绑定：非空时渲染的是站点数据，字段里的值只是兜底，不能当内容编辑 */
    public const SITE_BINDINGS = ['site_field', 'site_image_field', 'site_text_field', 'site_url_field'];
    private const CONTAINERS = ['container', 'div'];

    /**
     * 翻页（cursor > 0）必须带上第一页返回的 base_revision；文档在两页之间被改过就拒绝，
     * 避免把两个版本的元素拼成一份结果。
     *
     * @return array{ok:true,page:array<string,mixed>,elements:list<array<string,mixed>>,total:int,next_cursor:?int}|array{ok:false,error:string}
     */
    public static function read(AiAccessAuthContext $context, int $pageId, string $lang, int $cursor = 0, int $limit = self::DEFAULT_LIMIT, ?string $revision = null, ?AiPageDocumentSource $source = null): array
    {
        // 与后台单页编辑器入口相同：blox_edit + edit_page，外加外部访问权限；超管通配由 hasPermission 处理
        foreach (['ai_access', 'blox_edit', 'edit_page'] as $permission) {
            if (!$context->hasPermission($permission)) return ['ok' => false, 'error' => 'forbidden'];
        }
        // MCP 运行时不加载函数库；该总开关自 2026-08-28 起恒为 true，后台加载时仍照常判定
        if (function_exists('bloxPageEditorEnabled') && !bloxPageEditorEnabled()) return ['ok' => false, 'error' => 'forbidden'];

        $source = $source ?? new AiPageDocumentSource(db());
        $page = $source->page($pageId);
        // 只开放普通单页：产品页、列表栏目、链接等其它栏目类型一律当作不存在
        if ($page === null) return ['ok' => false, 'error' => 'not_found'];
        if ($lang === '' || (string) ($page['lang'] ?? '') !== $lang) return ['ok' => false, 'error' => 'language_mismatch'];
        if ((int) ($page['status'] ?? 1) !== 1) return ['ok' => false, 'error' => 'unavailable'];

        try {
            $state = $source->load($page);
            $document = $state['legacy_html'] === null ? BloxDocumentPipeline::decode($state['document_json']) : ['sections' => []];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'document_unreadable'];
        }
        if (($cursor > 0 || $revision !== null) && $revision !== $state['base_revision']) {
            return ['ok' => false, 'error' => 'revision_mismatch'];
        }

        $ids = [];
        $nodes = [];
        $scanned = 0;
        if ($state['legacy_html'] !== null) {
            // 纯 HTML 老页：整页作为一个只读文本元素；这个 ID 不在库里，不能作为修改地址
            $ids['e_legacy'] = 1;
            $nodes[] = ['element' => ['id' => 'e_legacy', 'type' => 'text', 'data' => ['html' => $state['legacy_html']]],
                'path' => 's0/c0/e0', 'depth' => 1, 'children_omitted' => false];
        }
        foreach ($document['sections'] as $sectionIndex => $section) {
            if (!is_array($section)) continue;
            self::countId($section, $ids);
            foreach (self::listOf($section['columns'] ?? null) as $columnIndex => $column) {
                if (!is_array($column)) continue;
                self::countId($column, $ids);
                foreach (self::listOf($column['elements'] ?? null) as $elementIndex => $element) {
                    if (!is_array($element)) continue;
                    if (!self::collect($element, "s{$sectionIndex}/c{$columnIndex}/e{$elementIndex}", 1, $ids, $nodes, $scanned)) {
                        return ['ok' => false, 'error' => 'document_too_large'];
                    }
                }
            }
        }

        // 旧富文本页的 ID 是读取时临时包装的，库里并不存在，不能作为后续修改的地址
        $legacy = $state['legacy_html'] !== null;
        $elements = [];
        foreach ($nodes as $node) {
            $elements[] = self::project($node, $ids, $legacy);
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $cursor = max(0, $cursor);
        $total = count($elements);
        return [
            'ok' => true,
            'page' => [
                'document' => 'page:' . $pageId,
                'id' => $pageId,
                'type' => 'page',
                'lang' => (string) $page['lang'],
                'name' => (string) ($page['name'] ?? ''),
                'base_revision' => $state['base_revision'],
                'has_draft' => $state['has_draft'],
                'has_published' => $state['has_published'],
                'has_unpublished_changes' => $state['has_unpublished_changes'],
                'uses_legacy_html' => $legacy,
            ],
            'elements' => array_slice($elements, $cursor, $limit),
            'total' => $total,
            'next_cursor' => $cursor + $limit < $total ? $cursor + $limit : null,
        ];
    }

    /** @param array<string,int> $ids */
    private static function countId(array $node, array &$ids): void
    {
        $id = $node['id'] ?? null;
        if ((is_string($id) || is_int($id)) && (string) $id !== '') {
            $ids[(string) $id] = ($ids[(string) $id] ?? 0) + 1;
        }
    }

    /**
     * 文档顺序展开元素；循环宿主的子元素是模板，不展开。
     * 展开与未展开的元素合计受 MAX_ELEMENTS 约束；返回 false 表示整页超限。
     *
     * @param array<string,int> $ids
     * @param list<array{element:array<string,mixed>,path:string,depth:int,children_omitted:bool}> $nodes
     */
    private static function collect(array $element, string $path, int $depth, array &$ids, array &$nodes, int &$scanned): bool
    {
        if (++$scanned > self::MAX_ELEMENTS) return false;
        self::countId($element, $ids);
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $type = (string) ($element['type'] ?? '');
        $children = self::listOf($data['children'] ?? null);
        $descend = in_array($type, self::CONTAINERS, true) && !isset($data['_query']) && $depth < self::MAX_DEPTH;
        $nodes[] = ['element' => $element, 'path' => $path, 'depth' => $depth, 'children_omitted' => $children !== [] && !$descend];
        if (!$descend) {
            // 不展开的子树仍要计入 ID，才能发现与外部元素重复的 ID；扫不完整就整页拒绝，不漏判重复
            return self::countNested($children, $ids, $scanned);
        }
        foreach ($children as $childIndex => $child) {
            if (is_array($child) && !self::collect($child, $path . '/' . $childIndex, $depth + 1, $ids, $nodes, $scanned)) return false;
        }
        return true;
    }

    /**
     * @param list<mixed> $children
     * @param array<string,int> $ids
     */
    private static function countNested(array $children, array &$ids, int &$scanned, int $depth = 0): bool
    {
        if ($children !== [] && $depth > self::MAX_DEPTH * 4) return false;
        foreach ($children as $child) {
            if (!is_array($child)) continue;
            if (++$scanned > self::MAX_ELEMENTS) return false;
            self::countId($child, $ids);
            $data = is_array($child['data'] ?? null) ? $child['data'] : [];
            if (!self::countNested(self::listOf($data['children'] ?? null), $ids, $scanned, $depth + 1)) return false;
        }
        return true;
    }

    /** 与 BloxValueSanitizer 相同的策略：richtext 只留安全标签（排版只用 class），链接与图片去掉伪协议 */
    private static function safeValue(string $kind, string $value): string
    {
        return match ($kind) {
            'richtext' => HtmlPolicy::richText($value),
            'url' => UrlPolicy::href($value, true, true),   // 与 AbstractElement::safeHref 同一实现
            'image' => UrlPolicy::storedImage($value),
            default => $value,
        };
    }

    /** @return list<mixed> 文档里的数组字段可能是任意 JSON 值；非数组按空列表处理 */
    private static function listOf(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param array{element:array<string,mixed>,path:string,depth:int,children_omitted:bool} $node
     * @param array<string,int> $ids
     * @return array<string,mixed>
     */
    private static function project(array $node, array $ids, bool $legacy): array
    {
        $element = $node['element'];
        $type = (string) ($element['type'] ?? '');
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $rawId = $element['id'] ?? null;
        $id = (is_string($rawId) || is_int($rawId)) ? (string) $rawId : '';

        $reason = null;
        if ($id === '') $reason = 'missing_id';
        elseif (!preg_match(self::ID_PATTERN, $id)) $reason = 'invalid_id';
        elseif (($ids[$id] ?? 0) > 1) $reason = 'duplicate_id';
        elseif ($legacy) $reason = 'legacy_html';
        elseif (!isset(self::FIELDS[$type])) $reason = 'type_not_supported';

        $out = [
            'id' => $reason === 'missing_id' || $reason === 'invalid_id' ? null : $id,
            'type' => $type,
            'path' => $node['path'],
            'depth' => $node['depth'],
            'addressable' => !in_array($reason, ['missing_id', 'invalid_id', 'duplicate_id', 'legacy_html'], true),
        ];
        if ($node['children_omitted']) $out['children_omitted'] = true;

        $fields = [];
        $bound = false;
        foreach (self::SITE_BINDINGS as $bindingKey) {
            if (trim((string) (is_scalar($data[$bindingKey] ?? null) ? $data[$bindingKey] : '')) !== '') $bound = true;
        }
        foreach (self::FIELDS[$type] ?? [] as $key => $kind) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) continue;
            if ($bound) {
                $fields[$key] = ['kind' => $kind, 'editable' => false, 'reason' => 'site_binding'];
                continue;
            }
            if (mb_strlen($data[$key]) > self::MAX_FIELD_CHARS) {
                $fields[$key] = ['kind' => $kind, 'editable' => false, 'reason' => 'too_large'];
                continue;
            }
            // 保存管线已按同一策略净化；导入或旧文档可能绕过它，输出前再净化一次，改动过就标出
            $value = self::safeValue($kind, $data[$key]);
            $fields[$key] = ['kind' => $kind, 'editable' => $reason === null, 'value' => $value];
            if ($value !== $data[$key]) $fields[$key]['sanitized'] = true;
        }
        if ($fields !== []) $out['fields'] = $fields;
        $out['editable'] = $reason === null && array_filter($fields, static fn(array $f): bool => $f['editable']) !== [];
        if ($reason !== null) $out['reason'] = $reason;
        return $out;
    }
}
