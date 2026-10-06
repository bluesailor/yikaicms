<?php
/**
 * Blox 组件（v2.1，设计见 yikaicms-docs/blox/RFC-2-components-v2.1.md）。
 *
 * 母版是 blox_templates 里 type=component 的一行：草稿 / 发布沿用模板的 draft_data / published_data，
 * 稳定标识 uuid、版本号、分类、归档状态存在 metadata.component。母版文档固定 1 段 1 栏 1 个根元素，
 * 属性定义存在文档 settings.component.props（随结构一起发布）。
 *
 * 页面里的实例是元素 `component`：data.component = uuid，data.props 只存覆盖过的属性；
 * 重置 = 删掉该键。实例不存版本号——永远渲染最新发布版；改结构走「脱离」（展开成普通元素）。
 * 属性值可以直接写动态标签（{{loop.title}}），渲染时先解析再按属性类型清洗，然后写进目标元素字段。
 *
 * 授权：渲染、插入、改实例属性、脱离免费；母版的新建 / 编辑 / 发布属 components 作者端能力。
 */

declare(strict_types=1);

final class BloxComponents
{
    public const TYPE = 'component';
    public const UUID_PATTERN = '/^cmp_[a-f0-9]{16}$/';
    public const PROP_TYPES = ['text', 'richtext', 'image', 'icon', 'url', 'number', 'boolean', 'select', 'color'];
    public const CATEGORIES = ['cards', 'headers', 'footers', 'cta', 'products', 'content', 'navigation', 'custom'];
    public const MAX_COMPONENTS = 200;
    public const MAX_PROPS = 24;
    public const MAX_TARGETS = 8;
    public const KEEP_REVISIONS = 20;
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    private const LABEL_MAX = 40;
    private const DESCRIPTION_MAX = 200;
    private const SELECT_OPTIONS_MAX = 24;

    /** @var array<string,array<string,mixed>>|null 请求级目录：uuid => 已发布定义 */
    private static ?array $catalog = null;

    /** @psalm-suppress PossiblyUnusedMethod 测试专用 */
    public static function resetForTests(): void
    {
        self::$catalog = null;
    }

    public static function newUuid(): string
    {
        return 'cmp_' . bin2hex(random_bytes(8));
    }

    public static function validUuid(mixed $uuid): bool
    {
        return is_string($uuid) && preg_match(self::UUID_PATTERN, $uuid) === 1;
    }

    // ── 母版元数据 ────────────────────────────────────────────────────

    /**
     * metadata.component 的归一。非组件模板返回 null（调用方据此不写这个键）。
     *
     * @return array{uuid:string,version:int,category:string,description:string,archived:bool}|null
     */
    public static function normalizeMeta(mixed $raw): ?array
    {
        if (!is_array($raw) || !self::validUuid($raw['uuid'] ?? null)) {
            return null;
        }
        $category = (string) ($raw['category'] ?? '');
        return [
            'uuid' => (string) $raw['uuid'],
            'version' => max(0, (int) ($raw['version'] ?? 0)),
            'category' => in_array($category, self::CATEGORIES, true) ? $category : 'custom',
            'description' => mb_substr(trim(strip_tags((string) ($raw['description'] ?? ''))), 0, self::DESCRIPTION_MAX),
            'archived' => !empty($raw['archived']),
        ];
    }

    // ── 母版文档 ──────────────────────────────────────────────────────

    /**
     * 母版文档的根元素（1 段 1 栏 1 元素）；形状不对返回 null。
     *
     * @param array<int,mixed> $sections
     * @return array<string,mixed>|null
     */
    public static function rootElement(array $sections): ?array
    {
        if (count($sections) !== 1 || !is_array($sections[0] ?? null)) {
            return null;
        }
        $columns = is_array($sections[0]['columns'] ?? null) ? $sections[0]['columns'] : [];
        if (count($columns) !== 1 || !is_array($columns[0] ?? null)) {
            return null;
        }
        $elements = is_array($columns[0]['elements'] ?? null) ? $columns[0]['elements'] : [];
        if (count($elements) !== 1 || !is_array($elements[0] ?? null)) {
            return null;
        }
        return $elements[0];
    }

    /**
     * 母版保存 / 发布前的结构校验：形状、不含组件实例。抛出的消息直接给作者看。
     *
     * @param array<int,mixed> $sections
     */
    public static function assertMasterShape(array $sections): void
    {
        $root = self::rootElement($sections);
        if ($root === null) {
            throw new RuntimeException(__('blox_component_shape'));
        }
        if (self::containsInstance([$root])) {
            throw new RuntimeException(__('blox_component_nested'));
        }
    }

    /**
     * 母版文档的保存管线：通用管线 → 形状校验 → 属性定义按归一后的节点逐项校验。
     * 返回与 BloxDocumentPipeline::process 相同的形状。
     *
     * @return array{schema:int,settings:array<string,mixed>,sections:array<int,mixed>,json:string}
     */
    public static function processMaster(string $json, string $idPrefix, ?string $trustedJson = null): array
    {
        $processed = BloxDocumentPipeline::process($json, $idPrefix, trustedJson: $trustedJson);
        self::assertMasterShape($processed['sections']);
        $root = self::rootElement($processed['sections']);
        $settings = $processed['settings'];
        $raw = is_array($settings['component'] ?? null) ? ($settings['component']['props'] ?? []) : [];
        $settings['component'] = ['props' => self::normalizePropsSchema($raw, (array) $root)];
        $processed['settings'] = $settings;
        $processed['json'] = json_encode(
            ['schema' => $processed['schema'], 'settings' => $settings, 'sections' => $processed['sections']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return $processed;
    }

    /**
     * 属性定义归一：键 / 类型 / 目标逐项校验，目标必须指向根元素子树里存在的节点、且字段是该元素声明过的控件键。
     *
     * @param array<string,mixed> $root
     * @return list<array<string,mixed>>
     */
    public static function normalizePropsSchema(mixed $raw, array $root): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $nodes = self::nodeIndex([$root]);
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $prop) {
            if (!is_array($prop) || count($out) >= self::MAX_PROPS) {
                continue;
            }
            $key = (string) ($prop['key'] ?? '');
            $type = (string) ($prop['type'] ?? '');
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || isset($seen[$key]) || !in_array($type, self::PROP_TYPES, true)) {
                continue;
            }
            $options = $type === 'select' ? self::selectOptions($prop['options'] ?? null) : [];
            if ($type === 'select' && $options === []) {
                continue;
            }
            $targets = [];
            foreach (is_array($prop['targets'] ?? null) ? array_values($prop['targets']) : [] as $target) {
                if (!is_array($target) || count($targets) >= self::MAX_TARGETS) {
                    continue;
                }
                $nodeId = (string) ($target['node'] ?? '');
                $field = (string) ($target['field'] ?? '');
                if (!isset($nodes[$nodeId]) || !self::fieldDeclared((string) ($nodes[$nodeId]['type'] ?? ''), $field)) {
                    continue;
                }
                $targets[$nodeId . '|' . $field] = ['node' => $nodeId, 'field' => $field];
            }
            if ($targets === []) {
                continue;
            }
            $normalized = [
                'key' => $key,
                'type' => $type,
                'label' => mb_substr(trim(strip_tags((string) ($prop['label'] ?? ''))), 0, self::LABEL_MAX) ?: $key,
                'targets' => array_values($targets),
            ];
            if ($options !== []) {
                $normalized['options'] = $options;
            }
            $normalized['default'] = self::sanitizePropValue($normalized, $prop['default'] ?? '');
            $out[] = $normalized;
            $seen[$key] = true;
        }
        return $out;
    }

    /** @return array<string,string> */
    private static function selectOptions(mixed $raw): array
    {
        $options = [];
        foreach (is_array($raw) ? $raw : [] as $value => $label) {
            if (count($options) >= self::SELECT_OPTIONS_MAX) {
                break;
            }
            $value = (string) $value;
            if ($value === '' || strlen($value) > 64) {
                continue;
            }
            $options[$value] = mb_substr(trim(strip_tags((string) $label)), 0, self::LABEL_MAX) ?: $value;
        }
        return $options;
    }

    private static function fieldDeclared(string $type, string $field): bool
    {
        if ($field === '' || str_starts_with($field, '_') || $field === 'children') {
            return false;
        }
        foreach (BuilderRegistry::get($type)?->controls() ?? [] as $control) {
            if ((string) ($control['key'] ?? '') === $field && empty($control['responsive'])) {
                return true;
            }
        }
        return false;
    }

    // ── 属性值 ────────────────────────────────────────────────────────

    /** 单个动态标签（url / image 允许整串是标签，渲染时解析后再清洗）。 */
    private static function isSingleTag(string $value): bool
    {
        return preg_match('/^\{\{[a-z_]+(?:\.[A-Za-z0-9_-]+){0,3}(?:\s*\|[^{}]*)?\}\}$/', $value) === 1;
    }

    /**
     * 存储时的清洗：与同类型控件同规则；url / image 允许整串是一个动态标签。
     *
     * @param array<string,mixed> $prop
     */
    public static function sanitizePropValue(array $prop, mixed $value): mixed
    {
        $type = (string) ($prop['type'] ?? 'text');
        if (in_array($type, ['url', 'image'], true) && is_string($value) && self::isSingleTag(trim($value))) {
            return trim($value);
        }
        return self::sanitizeResolved($prop, $value);
    }

    /** @param array<string,mixed> $prop */
    private static function sanitizeResolved(array $prop, mixed $value): mixed
    {
        $type = (string) ($prop['type'] ?? 'text');
        if ($type === 'color') {
            return AbstractElement::cssColor(is_scalar($value) ? (string) $value : '') ?? '';
        }
        $control = match ($type) {
            'boolean' => ['type' => 'checkbox'],
            'select' => ['type' => 'select', 'options' => $prop['options'] ?? [], 'default' => array_key_first((array) ($prop['options'] ?? []))],
            'number' => ['type' => 'number', 'default' => 0],
            default => ['type' => $type],
        };
        return BloxValueSanitizer::sanitize($control, $value);
    }

    /**
     * 渲染时的取值：实例覆盖 → 默认值；先解析动态标签，再按类型清洗。
     *
     * @param array<string,mixed> $prop @param array<string,mixed> $instanceProps
     */
    private static function resolvedPropValue(array $prop, array $instanceProps): mixed
    {
        $key = (string) $prop['key'];
        $value = array_key_exists($key, $instanceProps) ? $instanceProps[$key] : ($prop['default'] ?? '');
        if (is_string($value) && BloxDynamicTags::hasTags($value)) {
            $value = (string) $prop['type'] === 'richtext'
                ? BloxDynamicTags::resolveHtml($value)
                : BloxDynamicTags::resolveText($value);
        }
        return self::sanitizeResolved($prop, $value);
    }

    // ── 实例 ──────────────────────────────────────────────────────────

    /**
     * 文档保存时实例 data 的归一（管线 normalizeElement 调用）：
     * uuid 校验；props 只保留母版声明过的键并按类型清洗。母版暂不可用时原样保留标量值（不丢作者的覆盖）。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function normalizeInstanceData(array $data): array
    {
        $uuid = $data['component'] ?? '';
        $data['component'] = self::validUuid($uuid) ? (string) $uuid : '';
        $raw = is_array($data['props'] ?? null) ? $data['props'] : [];
        $definition = $data['component'] !== '' ? self::find($data['component']) : null;
        $props = [];
        if ($definition !== null) {
            foreach ($definition['props'] as $prop) {
                $key = (string) $prop['key'];
                if (array_key_exists($key, $raw)) {
                    $props[$key] = self::sanitizePropValue($prop, $raw[$key]);
                }
            }
        } else {
            foreach ($raw as $key => $value) {
                if (is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1 && is_scalar($value)) {
                    $props[$key] = BloxValueSanitizer::sanitize(['type' => 'text'], (string) $value);
                }
            }
        }
        $data['props'] = $props;
        return $data;
    }

    /**
     * 实例展开后的根元素：代入属性、节点 id 按实例加盐（同页多个实例的循环分页参数、锚点不撞）。
     *
     * @param array<string,mixed> $definition @param array<string,mixed> $instanceProps
     * @return array<string,mixed>
     */
    public static function expand(array $definition, array $instanceProps, string $instanceId): array
    {
        $values = [];
        foreach ($definition['props'] as $prop) {
            $value = self::resolvedPropValue($prop, $instanceProps);
            foreach ($prop['targets'] as $target) {
                $values[(string) $target['node']][(string) $target['field']] = $value;
            }
        }
        $salt = preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $instanceId) === 1 ? $instanceId : substr(sha1($instanceId), 0, 12);
        $apply = static function (array $node) use (&$apply, $values, $salt): array {
            $nodeId = (string) ($node['id'] ?? '');
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            foreach ($values[$nodeId] ?? [] as $field => $value) {
                $data[$field] = $value;
            }
            if (is_array($data['children'] ?? null)) {
                $data['children'] = array_map(
                    static fn (mixed $child): mixed => is_array($child) ? $apply($child) : $child,
                    array_values($data['children'])
                );
            }
            $node['id'] = $salt . '-' . substr(sha1($nodeId), 0, 10);
            $node['data'] = $data;
            return $node;
        };
        return $apply($definition['root']);
    }

    /**
     * 实例前台 / 画布渲染：内部不带编辑包装（实例作为整体被选中）。母版不可用时前台不输出、画布给占位。
     *
     * @param array<string,mixed> $data @param array<string,mixed> $context
     */
    public static function render(array $data, array $context = []): string
    {
        $editMode = !empty($context['edit_mode']);
        $uuid = (string) ($data['component'] ?? '');
        $definition = self::validUuid($uuid) ? self::find($uuid) : null;
        if ($definition === null) {
            return $editMode
                ? '<div class="yk-component-missing border-2 border-dashed border-amber-300 bg-amber-50 px-4 py-5 text-center text-sm text-amber-800">'
                    . e(__('blox_component_missing')) . '</div>'
                : '';
        }
        $root = self::expand($definition, is_array($data['props'] ?? null) ? $data['props'] : [], (string) ($context['node_id'] ?? 'cmp'));
        $html = BlockRenderer::renderElementNode($root, (int) ($context['depth'] ?? 0) + 1, false, []);
        if ($html === '') {
            return '';
        }
        $tag = new HtmlTagRewriter($html);
        if ($tag->nextTag()) {
            $tag->setAttribute('data-yk-component', $uuid);
            $html = $tag->getUpdatedHtml();
        }
        return $html;
    }

    /**
     * 脱离：把实例替换成母版当前发布结构（已代入属性），节点 id 重新生成。母版不可用返回 null。
     *
     * @param array<string,mixed> $element
     * @return array<string,mixed>|null
     */
    public static function detach(array $element): ?array
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $definition = self::validUuid($data['component'] ?? null) ? self::find((string) $data['component']) : null;
        if ($definition === null) {
            return null;
        }
        $instanceProps = is_array($data['props'] ?? null) ? $data['props'] : [];
        $values = [];
        foreach ($definition['props'] as $prop) {
            // 脱离后的结构是普通元素：保留动态标签原文，由元素自己解析（循环里照常工作）
            $key = (string) $prop['key'];
            $value = array_key_exists($key, $instanceProps) ? $instanceProps[$key] : ($prop['default'] ?? '');
            foreach ($prop['targets'] as $target) {
                $values[(string) $target['node']][(string) $target['field']] = $value;
            }
        }
        $prefix = 'e_' . substr(bin2hex(random_bytes(4)), 0, 7);
        $counter = 0;
        $apply = static function (array $node) use (&$apply, $values, $prefix, &$counter): array {
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            foreach ($values[(string) ($node['id'] ?? '')] ?? [] as $field => $value) {
                $data[$field] = $value;
            }
            if (is_array($data['children'] ?? null)) {
                $data['children'] = array_map(
                    static fn (mixed $child): mixed => is_array($child) ? $apply($child) : $child,
                    array_values($data['children'])
                );
            }
            $node['id'] = $prefix . '_' . ($counter++);
            $node['data'] = $data;
            return $node;
        };
        $root = $apply($definition['root']);
        // 实例外层的通用设置（显示条件、设备隐藏、全局类、交互）落到根元素上，外观与脱离前一致
        foreach (['_conditions', '_hide_on', '_interactions'] as $key) {
            if (array_key_exists($key, $data)) {
                $root['data'][$key] = $data[$key];
            }
        }
        if (is_array($data['_classes'] ?? null) && $data['_classes'] !== []) {
            $root['data']['_classes'] = array_values(array_unique(array_merge(
                is_array($root['data']['_classes'] ?? null) ? $root['data']['_classes'] : [],
                $data['_classes']
            )));
        }
        return $root;
    }

    /**
     * 整份文档的实例全部脱离（导出整站模板 / 模板包前、「全部脱离」工具）。母版不可用的实例原样保留。
     *
     * @param array<int,mixed> $sections
     * @return array{sections:array<int,mixed>,expanded:int}
     */
    public static function detachAll(array $sections): array
    {
        $expanded = 0;
        $walk = static function (array $element) use (&$walk, &$expanded): array {
            if (($element['type'] ?? '') === self::TYPE) {
                $replacement = self::detach($element);
                if ($replacement !== null) {
                    $expanded++;
                    return $replacement;
                }
                return $element;
            }
            if (is_array($element['data']['children'] ?? null)) {
                $element['data']['children'] = array_map(
                    static fn (mixed $child): mixed => is_array($child) ? $walk($child) : $child,
                    array_values($element['data']['children'])
                );
            }
            return $element;
        };
        foreach ($sections as $s => $section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as $c => $column) {
                if (!is_array($column) || !is_array($column['elements'] ?? null)) {
                    continue;
                }
                $sections[$s]['columns'][$c]['elements'] = array_map(
                    static fn (mixed $element): mixed => is_array($element) ? $walk($element) : $element,
                    $column['elements']
                );
            }
        }
        return ['sections' => $sections, 'expanded' => $expanded];
    }

    // ── 目录（请求级缓存） ─────────────────────────────────────────────

    /** @return array<string,mixed>|null 已发布定义：{id,uuid,name,version,category,archived,root,props} */
    public static function find(string $uuid): ?array
    {
        return self::catalog()[$uuid] ?? null;
    }

    /**
     * 全部已发布组件，一次查询（50 个实例 = 0 次额外查询）。
     *
     * @return array<string,array<string,mixed>>
     */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        self::$catalog = [];
        $rows = db()->fetchAll(
            'SELECT id, name, published_data, metadata FROM ' . DB_PREFIX . 'blox_templates'
            . ' WHERE type = ? AND status = 1 ORDER BY id ASC LIMIT ' . self::MAX_COMPONENTS,
            [self::TYPE]
        );
        foreach ($rows as $row) {
            $definition = self::definitionFromRow($row);
            if ($definition !== null) {
                self::$catalog[$definition['uuid']] = $definition;
            }
        }
        return self::$catalog;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function definitionFromRow(array $row, string $column = 'published_data'): ?array
    {
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        $meta = self::normalizeMeta(is_array($metadata) ? ($metadata['component'] ?? null) : null);
        $json = trim((string) ($row[$column] ?? ''));
        if ($meta === null || $json === '') {
            return null;
        }
        try {
            $document = BloxDocumentPipeline::decode($json);
        } catch (Throwable) {
            return null;
        }
        $root = self::rootElement($document['sections']);
        if ($root === null || self::containsInstance([$root])) {
            return null;
        }
        $settings = is_array($document['settings']['component'] ?? null) ? $document['settings']['component'] : [];
        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => $meta['uuid'],
            'name' => (string) ($row['name'] ?? ''),
            'version' => $meta['version'],
            'category' => $meta['category'],
            'archived' => $meta['archived'],
            'root' => $root,
            'props' => self::normalizePropsSchema($settings['props'] ?? [], $root),
        ];
    }

    // ── 用量索引 ──────────────────────────────────────────────────────

    public static function available(): bool
    {
        return db()->tableExists('blox_component_refs');
    }

    /**
     * @param array<int,mixed> $sections
     * @return array<string,int>
     */
    public static function collectReferences(array $sections): array
    {
        $counts = [];
        self::walkElements($sections, static function (array $element) use (&$counts): void {
            $uuid = $element['data']['component'] ?? null;
            if (($element['type'] ?? '') === self::TYPE && self::validUuid($uuid)) {
                $counts[$uuid] = ($counts[$uuid] ?? 0) + 1;
            }
        });
        return $counts;
    }

    /** @param array<string,int> $counts */
    public static function replaceDocumentRefs(string $docKey, array $counts): void
    {
        if (!self::available() || $docKey === '' || strlen($docKey) > 64) {
            return;
        }
        $now = time();
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_refs WHERE doc_key = ?', [$docKey]);
        foreach ($counts as $uuid => $count) {
            if (!self::validUuid($uuid) || (int) $count < 1) {
                continue;
            }
            db()->insert('blox_component_refs', [
                'component_uuid' => $uuid,
                'doc_key' => $docKey,
                'ref_count' => (int) $count,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string,array{docs:int,refs:int}> */
    public static function usage(): array
    {
        if (!self::available()) {
            return [];
        }
        $usage = [];
        foreach (db()->fetchAll(
            'SELECT component_uuid, COUNT(*) AS docs, SUM(ref_count) AS refs FROM ' . DB_PREFIX
            . 'blox_component_refs GROUP BY component_uuid'
        ) as $row) {
            $usage[(string) $row['component_uuid']] = ['docs' => (int) ($row['docs'] ?? 0), 'refs' => (int) ($row['refs'] ?? 0)];
        }
        return $usage;
    }

    /** @return list<string> 使用该组件的文档键（page:N / template:N / home …） */
    public static function usedIn(string $uuid): array
    {
        if (!self::available() || !self::validUuid($uuid)) {
            return [];
        }
        return array_map(
            static fn (array $row): string => (string) $row['doc_key'],
            db()->fetchAll('SELECT doc_key FROM ' . DB_PREFIX . 'blox_component_refs WHERE component_uuid = ? ORDER BY doc_key', [$uuid])
        );
    }

    // ── 发布与修订 ────────────────────────────────────────────────────

    /**
     * 母版发布后：版本号 +1、写修订（保留最近 KEEP_REVISIONS 份）。整页缓存由模板行更新触发的 data_changed 失效。
     *
     * @param array<string,mixed> $row 发布后的模板行
     */
    public static function afterPublish(array $row, int $adminId = 0): void
    {
        $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $meta = self::normalizeMeta($metadata['component'] ?? null);
        if ($meta === null) {
            return;
        }
        $meta['version']++;
        $metadata['component'] = $meta;
        db()->update('blox_templates', [
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ], 'id = ?', [(int) $row['id']]);
        if (db()->tableExists('blox_component_revisions')) {
            db()->insert('blox_component_revisions', [
                'component_uuid' => $meta['uuid'],
                'version' => $meta['version'],
                'snapshot' => (string) ($row['published_data'] ?? ''),
                'admin_id' => max(0, $adminId),
                'created_at' => time(),
            ]);
            $keep = db()->fetchAll(
                'SELECT id FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? ORDER BY version DESC, id DESC LIMIT ' . self::KEEP_REVISIONS,
                [$meta['uuid']]
            );
            $minId = $keep === [] ? 0 : min(array_map(static fn (array $r): int => (int) $r['id'], $keep));
            if (count($keep) >= self::KEEP_REVISIONS && $minId > 0) {
                db()->execute(
                    'DELETE FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? AND id < ?',
                    [$meta['uuid'], $minId]
                );
            }
        }
        self::$catalog = null;
    }

    // ── 工具 ──────────────────────────────────────────────────────────

    /** @param array<int,mixed> $elements */
    public static function containsInstance(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['type'] ?? '') === self::TYPE) {
                return true;
            }
            if (is_array($element['data']['children'] ?? null) && self::containsInstance($element['data']['children'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,mixed> $elements
     * @return array<string,array<string,mixed>> node id => 元素
     */
    private static function nodeIndex(array $elements): array
    {
        $index = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $id = (string) ($element['id'] ?? '');
            if ($id !== '') {
                $index[$id] = $element;
            }
            if (is_array($element['data']['children'] ?? null)) {
                $index += self::nodeIndex($element['data']['children']);
            }
        }
        return $index;
    }

    /**
     * @param array<int,mixed> $sections
     * @param callable(array<string,mixed>):void $visit
     */
    private static function walkElements(array $sections, callable $visit): void
    {
        $walk = static function (array $element) use (&$walk, $visit): void {
            $visit($element);
            foreach (is_array($element['data']['children'] ?? null) ? $element['data']['children'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        foreach ($sections as $section) {
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        $walk($element);
                    }
                }
            }
        }
    }
}
