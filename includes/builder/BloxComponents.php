<?php
/**
 * Blox 组件（v2.1，设计见 yikaicms-docs/blox/RFC-2-components-v2.1.md）。
 *
 * 母版是 blox_templates 里 type=component 的一行：草稿 / 发布沿用模板的 draft_data / published_data，
 * 稳定标识 uuid、版本号、分类、归档状态存在 metadata.component。母版文档固定 1 段 1 栏 1 个根元素，
 * 属性定义存在文档 settings.component.props（随结构一起发布）。
 *
 * 页面里的实例是元素 `component`：data.component = uuid，data.props 只存覆盖过的属性；
 * 重置 = 删掉该键。默认渲染最新发布版；data.pin 固定某个已发布版本（2.1.0，浅层：只冻结这一层），
 * 被固定的版本发布时不清理（2.1.1）。改结构走「脱离」（展开成普通元素）。
 * 属性值可以直接写动态标签（{{loop.title}}），渲染时先解析再按属性类型清洗，然后写进目标元素字段。
 *
 * 授权：渲染、插入、改实例属性、脱离免费；母版的新建 / 编辑 / 发布属 components 作者端能力。
 */

declare(strict_types=1);

final class BloxComponents
{
    /** @var list<string> 本请求正在渲染的组件链（组件套组件的成环兜底） */
    private static array $renderStack = [];
    /** @var array<string,array<string,mixed>|null> 固定版本的定义（uuid@版本），本请求内缓存 */
    private static array $pinned = [];

    public const TYPE = 'component';
    public const UUID_PATTERN = '/^cmp_[a-f0-9]{16}$/';
    public const PROP_TYPES = ['text', 'richtext', 'image', 'icon', 'url', 'number', 'boolean', 'select', 'color'];
    public const CATEGORIES = ['cards', 'headers', 'footers', 'cta', 'products', 'content', 'navigation', 'custom'];
    public const MAX_COMPONENTS = 200;
    public const MAX_PROPS = 24;
    /** 组件套组件的最大层数（2.1.0）：发布时检查，渲染时再兜底（未发布草稿之间也可能临时成环） */
    public const MAX_NESTING = 5;
    /** 每个母版最多几个变体（2.1.0） */
    public const MAX_VARIANTS = 12;
    public const MAX_TARGETS = 8;
    public const KEEP_REVISIONS = 20;
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';
    /** 可按语言给值的属性类型（文案与随语言换的图片、链接） */
    public const LOCALIZABLE_TYPES = ['text', 'richtext', 'image', 'url'];
    private const LABEL_MAX = 40;
    private const DESCRIPTION_MAX = 200;
    private const SELECT_OPTIONS_MAX = 24;

    /** @var array<string,array<string,mixed>>|null 请求级目录：uuid => 已发布定义 */
    private static ?array $catalog = null;

    /** @psalm-suppress PossiblyUnusedMethod 测试专用 */
    public static function resetForTests(): void
    {
        self::$pinned = [];
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
     * 母版保存 / 发布前的结构校验：形状对、根元素不是另一个组件。抛出的消息直接给作者看。
     * 2.1.0 起组件里可以放组件（根元素之下）；会不会成环在发布时查（assertNoCycle）。
     *
     * @param array<int,mixed> $sections
     */
    public static function assertMasterShape(array $sections): void
    {
        $root = self::rootElement($sections);
        if ($root === null) {
            throw new RuntimeException(__('blox_component_shape'));
        }
        if (($root['type'] ?? '') === self::TYPE) {
            throw new RuntimeException(__('blox_component_root_instance'));
        }
    }

    /**
     * 元素树里直接用到的组件 uuid（不展开下一层）。
     *
     * @param array<int,mixed> $elements
     * @return list<string>
     */
    public static function directDependencies(array $elements): array
    {
        $out = [];
        $walk = static function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['type'] ?? '') === self::TYPE) {
                    $uuid = $node['data']['component'] ?? null;
                    if (self::validUuid($uuid)) {
                        $out[(string) $uuid] = true;
                    }
                    continue;   // 实例内部是它自己母版的事
                }
                if (is_array($node['data']['children'] ?? null)) {
                    $walk($node['data']['children']);
                }
            }
        };
        $walk($elements);
        return array_keys($out);
    }

    /**
     * 首次发布一个母版前（2.1.1）：组件目录一次只取 MAX_COMPONENTS 个已发布母版（归档的也算），
     * 再多出来的不会显示也插不进页面——到了上限就拒绝发布，而不是发布后悄悄失效。
     */
    public static function assertCatalogRoom(): void
    {
        $published = (int) db()->fetchColumn(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_templates WHERE type = ? AND status = 1',
            [self::TYPE]
        );
        if ($published >= self::MAX_COMPONENTS) {
            throw new RuntimeException(__('blox_component_too_many', ['max' => (string) self::MAX_COMPONENTS]));
        }
    }

    /**
     * 发布前的依赖检查：$selfUuid 的母版里用到的组件（按各自已发布的版本往下找）不能绕回它自己，
     * 层数不超过 MAX_NESTING。不通过抛给作者看的消息。
     *
     * @param array<int,mixed> $sections 待发布的母版文档
     */
    public static function assertNoCycle(string $selfUuid, array $sections): void
    {
        $root = self::rootElement($sections);
        $level = $root === null ? [] : self::directDependencies([$root]);
        $seen = [];
        for ($depth = 1; $level !== []; $depth++) {
            if (in_array($selfUuid, $level, true)) {
                throw new RuntimeException(__('blox_component_cycle'));
            }
            if ($depth >= self::MAX_NESTING) {
                throw new RuntimeException(__('blox_component_too_deep', ['max' => (string) self::MAX_NESTING]));
            }
            $next = [];
            foreach ($level as $uuid) {
                if (isset($seen[$uuid])) {
                    continue;
                }
                $seen[$uuid] = true;
                $definition = self::find($uuid);
                if ($definition !== null) {
                    $next = array_merge($next, self::directDependencies([$definition['root']]));
                }
            }
            $level = array_values(array_unique($next));
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
        $props = self::normalizePropsSchema($raw, (array) $root);
        $settings['component'] = ['props' => $props];
        $rawVariants = is_array($processed['settings']['component'] ?? null) ? ($processed['settings']['component']['variants'] ?? []) : [];
        $variants = self::normalizeVariants($rawVariants, $props);
        if ($variants !== []) {
            $settings['component']['variants'] = $variants;
        }
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
            // 多语言（规划 §14）：文案类属性可按语言给默认值；结构只有一份
            if (in_array($type, self::LOCALIZABLE_TYPES, true) && !empty($prop['localizable'])) {
                $normalized['localizable'] = true;
                $i18n = [];
                foreach (is_array($prop['default_i18n'] ?? null) ? $prop['default_i18n'] : [] as $lang => $value) {
                    if (self::validLang($lang) && is_scalar($value) && (string) $value !== '') {
                        $i18n[(string) $lang] = self::sanitizePropValue($normalized, $value);
                    }
                }
                if ($i18n !== []) {
                    $normalized['default_i18n'] = $i18n;
                }
            }
            $out[] = $normalized;
            $seen[$key] = true;
        }
        return $out;
    }

    /**
     * 变体（2.1.0）：母版里具名的一组属性值，实例选了变体就用这组值，仍可逐项覆盖。
     * 只收已声明属性的键，值按属性类型清洗；空值不存（= 用母版默认）。
     * 开关属性的 false / '0' 是「关」，不算空（2.1.1：布尔 false 转字符串是空串，以前会被当成没设）。
     *
     * @param list<array<string,mixed>> $props 已归一的属性定义
     * @return list<array{key:string,label:string,values:array<string,mixed>}>
     */
    public static function normalizeVariants(mixed $raw, array $props): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $byKey = [];
        foreach ($props as $prop) {
            $byKey[(string) $prop['key']] = $prop;
        }
        $out = [];
        $seen = [];
        foreach (array_values($raw) as $variant) {
            if (!is_array($variant) || count($out) >= self::MAX_VARIANTS) {
                continue;
            }
            $key = (string) ($variant['key'] ?? '');
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || isset($seen[$key])) {
                continue;
            }
            $values = [];
            foreach (is_array($variant['values'] ?? null) ? $variant['values'] : [] as $propKey => $value) {
                $prop = $byKey[$propKey] ?? null;
                if ($prop === null) {
                    continue;
                }
                $set = (string) $prop['type'] === 'boolean'
                    ? is_bool($value) || (is_scalar($value) && trim((string) $value) !== '')
                    : is_scalar($value) && (string) $value !== '';
                if ($set) {
                    $values[(string) $propKey] = self::sanitizePropValue($prop, $value);
                }
            }
            $out[] = [
                'key' => $key,
                'label' => mb_substr(trim(strip_tags((string) ($variant['label'] ?? ''))), 0, self::LABEL_MAX) ?: $key,
                'values' => $values,
            ];
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

    /** 自动绑定：属性键 / 类型 → 循环标签（键名优先，其次按类型兜底）。 */
    private const AUTO_BIND_KEYS = [
        'title' => '{{loop.title}}', 'name' => '{{loop.title}}', 'heading' => '{{loop.title}}',
        'subtitle' => '{{loop.subtitle}}', 'summary' => '{{loop.summary}}', 'description' => '{{loop.summary}}',
        'excerpt' => '{{loop.summary}}',
        'image' => '{{loop.cover}}', 'cover' => '{{loop.cover}}', 'photo' => '{{loop.cover}}', 'thumbnail' => '{{loop.cover}}',
        'url' => '{{loop.url}}', 'link' => '{{loop.url}}', 'href' => '{{loop.url}}',
        'price' => '{{loop.price}}', 'model' => '{{loop.model}}', 'date' => '{{loop.date}}',
        'category' => '{{loop.category}}', 'author' => '{{loop.author}}',
    ];
    private const AUTO_BIND_TYPES = ['image' => '{{loop.cover}}', 'url' => '{{loop.url}}'];

    /**
     * 组件放进查询循环时，给每个属性建议一个循环标签；作者确认后写进实例 props。没有合适的就不建议。
     *
     * @param list<array<string,mixed>> $props
     * @return array<string,string> prop key => tag
     */
    public static function suggestLoopBindings(array $props): array
    {
        $suggestions = [];
        foreach ($props as $prop) {
            $key = (string) ($prop['key'] ?? '');
            $type = (string) ($prop['type'] ?? '');
            if (!in_array($type, ['text', 'richtext', 'image', 'url'], true)) {
                continue;
            }
            $tag = self::AUTO_BIND_KEYS[$key] ?? null;
            if ($tag === null) {
                foreach (self::AUTO_BIND_KEYS as $hint => $candidate) {
                    if (str_contains($key, $hint)) {
                        $tag = $candidate;
                        break;
                    }
                }
            }
            $tag ??= self::AUTO_BIND_TYPES[$type] ?? null;
            // 类型要对得上：图片属性只吃 cover，链接属性只吃 url
            if ($tag === null
                || ($type === 'image' && $tag !== '{{loop.cover}}')
                || ($type === 'url' && $tag !== '{{loop.url}}')
                || (in_array($type, ['text', 'richtext'], true) && in_array($tag, ['{{loop.cover}}', '{{loop.url}}'], true))) {
                continue;
            }
            $suggestions[$key] = $tag;
        }
        return $suggestions;
    }

    // ── 实例 ──────────────────────────────────────────────────────────

    private static function validLang(mixed $lang): bool
    {
        if (!is_string($lang) || preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $lang) !== 1) {
            return false;
        }
        return !class_exists('LanguageRegistry') || LanguageRegistry::has($lang);
    }

    private static function currentLang(): string
    {
        return function_exists('contentLang') ? contentLang() : (function_exists('siteLang') ? siteLang() : '');
    }

    /**
     * 某语言下实例实际生效的属性原值（未解析标签）：
     * 可多语言的属性 = 实例本语言值 → 实例值 → 母版本语言默认 → 母版默认；其余 = 实例值 → 母版默认。
     * 只返回有来源的键，交给 expand / detach 按普通实例属性处理。
     *
     * @param array<string,mixed> $definition @param array<string,mixed> $data 实例 data
     * @return array<string,mixed>
     */
    public static function effectiveProps(array $definition, array $data, ?string $lang = null): array
    {
        $lang ??= self::currentLang();
        $props = is_array($data['props'] ?? null) ? $data['props'] : [];
        $byLang = is_array($data['props_i18n'][$lang] ?? null) ? $data['props_i18n'][$lang] : [];
        // 变体（2.1.0）：实例的覆盖 → 选中变体的值 → 母版默认（含按语言的默认）
        $variantValues = [];
        $variantKey = (string) ($data['variant'] ?? '');
        foreach ($variantKey !== '' && is_array($definition['variants'] ?? null) ? $definition['variants'] : [] as $variant) {
            if ($variant['key'] === $variantKey) {
                $variantValues = $variant['values'];
                break;
            }
        }
        $out = [];
        foreach ($definition['props'] as $prop) {
            $key = (string) $prop['key'];
            if (!empty($prop['localizable'])) {
                if (array_key_exists($key, $byLang)) {
                    $out[$key] = $byLang[$key];
                    continue;
                }
                if (array_key_exists($key, $props)) {
                    $out[$key] = $props[$key];
                    continue;
                }
                if (array_key_exists($key, $variantValues)) {
                    $out[$key] = $variantValues[$key];
                    continue;
                }
                if (isset($prop['default_i18n'][$lang])) {
                    $out[$key] = $prop['default_i18n'][$lang];
                }
                continue;
            }
            if (array_key_exists($key, $props)) {
                $out[$key] = $props[$key];
            } elseif (array_key_exists($key, $variantValues)) {
                $out[$key] = $variantValues[$key];
            }
        }
        return $out;
    }

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
        // 锁定版本（2.1.0）：正整数版本号；不锁 = 跟随最新发布版
        $pin = is_numeric($data['pin'] ?? null) ? max(0, (int) $data['pin']) : 0;
        if ($pin > 0) {
            $data['pin'] = $pin;
        } else {
            unset($data['pin']);
        }
        $raw = is_array($data['props'] ?? null) ? $data['props'] : [];
        $definition = $data['component'] !== '' ? self::definitionFor($data['component'], $pin) : null;
        // 固定的版本快照已不在（2.1.1 前被清理）：不按最新版筛，与母版暂不可用一样原样保留作者的覆盖
        if ($definition !== null && self::pinMissing($data['component'], $pin)) {
            $definition = null;
        }
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
        // 变体：母版里有这个键才保留；母版暂不可用时按键名格式原样保留
        $variant = (string) ($data['variant'] ?? '');
        $known = $definition === null
            ? preg_match(self::KEY_PATTERN, $variant) === 1
            : in_array($variant, array_column($definition['variants'] ?? [], 'key'), true);
        if ($variant !== '' && $known) {
            $data['variant'] = $variant;
        } else {
            unset($data['variant']);
        }
        // 共享文档（多语言首页）里实例的按语言覆盖：只收可多语言的属性
        $i18n = [];
        if ($definition !== null && is_array($data['props_i18n'] ?? null)) {
            foreach ($data['props_i18n'] as $lang => $values) {
                if (!self::validLang($lang) || !is_array($values)) {
                    continue;
                }
                foreach ($definition['props'] as $prop) {
                    $key = (string) $prop['key'];
                    if (!empty($prop['localizable']) && array_key_exists($key, $values)) {
                        $i18n[(string) $lang][$key] = self::sanitizePropValue($prop, $values[$key]);
                    }
                }
            }
        } elseif (is_array($data['props_i18n'] ?? null)) {
            $i18n = $data['props_i18n'];   // 母版暂不可用：原样保留，不丢作者的译文
        }
        if ($i18n !== []) {
            $data['props_i18n'] = $i18n;
        } else {
            unset($data['props_i18n']);
        }
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
        // 组件套组件：正在渲染的链里已经有它（成环）或超过层数，就不再往下渲染
        if (in_array($uuid, self::$renderStack, true) || count(self::$renderStack) >= self::MAX_NESTING) {
            return $editMode
                ? '<div class="yk-component-missing border-2 border-dashed border-amber-300 bg-amber-50 px-4 py-5 text-center text-sm text-amber-800">'
                    . e(__('blox_component_cycle')) . '</div>'
                : '';
        }
        $definition = self::validUuid($uuid) ? self::definitionFor($uuid, (int) ($data['pin'] ?? 0)) : null;
        if ($definition === null) {
            return $editMode
                ? '<div class="yk-component-missing border-2 border-dashed border-amber-300 bg-amber-50 px-4 py-5 text-center text-sm text-amber-800">'
                    . e(__('blox_component_missing')) . '</div>'
                : '';
        }
        $root = self::expand($definition, self::effectiveProps($definition, $data), (string) ($context['node_id'] ?? 'cmp'));
        self::$renderStack[] = $uuid;
        try {
            $html = BlockRenderer::renderElementNode($root, (int) ($context['depth'] ?? 0) + 1, false, []);
        } finally {
            array_pop(self::$renderStack);
        }
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
        $definition = self::validUuid($data['component'] ?? null) ? self::definitionFor((string) $data['component'], (int) ($data['pin'] ?? 0)) : null;
        if ($definition === null) {
            return null;
        }
        // 脱离按当前语言取值（共享首页里其余语言的覆盖不随结构走，界面确认框已说明）
        $instanceProps = self::effectiveProps($definition, $data);
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
                    // 组件里的组件一并脱离（层数有上限，成环的母版发布不了）
                    return self::detachNested($replacement, $expanded);
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

    /**
     * 脱离出来的结构里还有实例（组件套组件）：逐层脱离，最多 MAX_NESTING 层；更深的原样保留。
     *
     * @param array<string,mixed> $element
     * @return array<string,mixed>
     */
    private static function detachNested(array $element, int &$count, int $depth = 1): array
    {
        $children = $element['data']['children'] ?? null;
        if ($depth >= self::MAX_NESTING || !is_array($children)) {
            return $element;
        }
        $out = [];
        foreach ($children as $child) {
            if (is_array($child) && ($child['type'] ?? '') === self::TYPE) {
                $replacement = self::detach($child);
                if ($replacement !== null) {
                    $count++;
                    $child = self::detachNested($replacement, $count, $depth + 1);
                }
            } elseif (is_array($child)) {
                $child = self::detachNested($child, $count, $depth);
            }
            $out[] = $child;
        }
        $element['data']['children'] = $out;
        return $element;
    }

    /**
     * 任意 JSON 树里的实例全部脱离（首页、页头页尾、历史快照的格式各不相同，按节点形状识别，不按文档格式）。
     */
    public static function detachInTree(mixed $tree, int &$count): mixed
    {
        if (!is_array($tree)) {
            return $tree;
        }
        if (($tree['type'] ?? null) === self::TYPE && is_array($tree['data'] ?? null)) {
            $replacement = self::detach($tree);
            if ($replacement !== null) {
                $count++;
                return self::detachNested($replacement, $count);
            }
            return $tree;
        }
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::detachInTree($value, $count);
            }
        }
        return $tree;
    }

    /**
     * 「全部脱离」（降级到 2.0.5 前运行；先备份）：页面草稿与发布版、单页正文、模板、首页设置里的实例全部展开成普通元素。
     * 母版已不可用的实例原样保留（旧版前台本来也显示不出来）。页面历史版本里的旧快照不改。
     *
     * @return array{documents:int,instances:int}
     */
    public static function detachSite(): array
    {
        $needle = '%"type":"' . self::TYPE . '"%';
        $documents = 0;
        $instances = 0;
        $rewrite = static function (string $json) use (&$documents, &$instances): ?string {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return null;
            }
            $count = 0;
            $detached = self::detachInTree($decoded, $count);
            if ($count === 0) {
                return null;
            }
            $documents++;
            $instances += $count;
            return json_encode($detached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        };
        $stores = [
            ['blox_page_drafts', 'id', ['draft_data', 'published_data'], ''],
            ['contents', 'id', ['blocks_data'], ''],
            ['blox_templates', 'id', ['draft_data', 'published_data'], " AND type <> '" . self::TYPE . "'"],
            ['settings', 'id', ['value'], ''],
        ];
        db()->beginTransaction();
        try {
            foreach ($stores as [$table, $pk, $columns, $extra]) {
                if (!db()->tableExists($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    $rows = db()->fetchAll(
                        'SELECT ' . $pk . ', ' . $column . ' AS doc FROM ' . DB_PREFIX . $table
                        . ' WHERE ' . $column . ' LIKE ?' . $extra,
                        [$needle]
                    );
                    foreach ($rows as $row) {
                        $json = $rewrite((string) ($row['doc'] ?? ''));
                        if ($json !== null) {
                            db()->update($table, [$column => $json], $pk . ' = ?', [$row[$pk]]);
                        }
                    }
                }
            }
            if (self::available()) {
                db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_refs');
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        if (function_exists('do_action')) {
            do_action('data_changed', 'blox_templates', null);
        }
        return ['documents' => $documents, 'instances' => $instances];
    }

    /** 任意 JSON 树里的实例个数（按 uuid）。 */
    private static function countInTree(mixed $tree, array &$counts): void
    {
        if (!is_array($tree)) {
            return;
        }
        if (($tree['type'] ?? null) === self::TYPE && self::validUuid($tree['data']['component'] ?? null)) {
            $uuid = (string) $tree['data']['component'];
            $counts[$uuid] = ($counts[$uuid] ?? 0) + 1;
            return;
        }
        foreach ($tree as $value) {
            if (is_array($value)) {
                self::countInTree($value, $counts);
            }
        }
    }

    /**
     * 重建全站用量索引（整站模板导入后：用量表随替换被清空，母版行随模板表原样导入）。
     * 文档键与各保存链路一致：单页 page:N、栏目落地页 channel:N、模板 template:N、首页 home / home-layout；均按草稿统计。
     *
     * @return int 写入的文档数
     */
    public static function reindexSite(): int
    {
        if (!self::available()) {
            return 0;
        }
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_refs');
        $needle = '%"type":"' . self::TYPE . '"%';
        $docs = [];
        if (db()->tableExists('blox_page_drafts')) {
            foreach (db()->fetchAll(
                'SELECT d.page_id, d.draft_data, c.type FROM ' . DB_PREFIX . 'blox_page_drafts d LEFT JOIN ' . DB_PREFIX
                . 'channels c ON c.id = d.page_id WHERE d.draft_data LIKE ?',
                [$needle]
            ) as $row) {
                $prefix = in_array((string) ($row['type'] ?? ''), ['page', 'product', ''], true) ? 'page:' : 'channel:';
                $docs[$prefix . (int) $row['page_id']] = (string) $row['draft_data'];
            }
        }
        foreach (db()->fetchAll(
            'SELECT id, draft_data, published_data FROM ' . DB_PREFIX . "blox_templates WHERE type <> '" . self::TYPE . "'"
            . ' AND (draft_data LIKE ? OR published_data LIKE ?)',
            [$needle, $needle]
        ) as $row) {
            $docs['template:' . (int) $row['id']] = trim((string) ($row['draft_data'] ?? '')) !== ''
                ? (string) $row['draft_data'] : (string) ($row['published_data'] ?? '');
        }
        foreach (['home' => 'home_blox_data', 'home-layout' => 'home_layout_data'] as $docKey => $settingKey) {
            $value = db()->fetchColumn('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$settingKey]);
            if (is_string($value) && str_contains($value, '"type":"' . self::TYPE . '"')) {
                $docs[$docKey] = $value;
            }
        }
        $written = 0;
        foreach ($docs as $docKey => $json) {
            $counts = [];
            self::countInTree(json_decode($json, true), $counts);
            if ($counts !== []) {
                self::replaceDocumentRefs($docKey, $counts);
                $written++;
            }
        }
        return $written;
    }

    // ── 目录（请求级缓存） ─────────────────────────────────────────────

    /** @return array<string,mixed>|null 已发布定义：{id,uuid,name,version,category,archived,root,props} */
    public static function find(string $uuid): ?array
    {
        return self::catalog()[$uuid] ?? null;
    }

    /**
     * 实例实际用的定义（2.1.0 锁定版本）：$pin > 0 且早于最新发布版时，取那个版本的修订快照。
     * 被锁定的版本发布时不清理（2.1.1）；快照仍缺失（2.1.1 前被清掉）就退回最新版，
     * 编辑器提示、站点健康列出（missingPins），保存时不按最新版筛覆盖（pinMissing）。
     *
     * 锁定是浅层的：固定的快照里没单独固定的内层组件，仍按各自的设置跟随最新版。
     *
     * @return array<string,mixed>|null
     */
    public static function definitionFor(string $uuid, int $pin = 0): ?array
    {
        $latest = self::find($uuid);
        if ($latest === null || $pin <= 0 || $pin >= (int) $latest['version']) {
            return $latest;
        }
        return self::pinnedDefinition($uuid, $pin, $latest) ?? $latest;
    }

    /** 固定的版本早于最新发布版、且它的修订快照已不在（2.1.1）。 */
    public static function pinMissing(string $uuid, int $pin): bool
    {
        $latest = self::validUuid($uuid) ? self::find($uuid) : null;
        return $latest !== null && $pin > 0 && $pin < (int) $latest['version']
            && self::pinnedDefinition($uuid, $pin, $latest) === null;
    }

    /**
     * @param array<string,mixed> $latest
     * @return array<string,mixed>|null
     */
    private static function pinnedDefinition(string $uuid, int $pin, array $latest): ?array
    {
        $key = $uuid . '@' . $pin;
        if (!array_key_exists($key, self::$pinned)) {
            $snapshot = self::revisionSnapshot($uuid, $pin);
            self::$pinned[$key] = $snapshot === null ? null : self::definitionFromRow([
                'id' => $latest['id'],
                'name' => $latest['name'],
                'metadata' => json_encode(['component' => ['uuid' => $uuid, 'version' => $pin, 'category' => $latest['category'], 'archived' => $latest['archived']]]),
                'published_data' => $snapshot,
            ]);
        }
        return self::$pinned[$key];
    }

    /**
     * 模板包要带的母版（2.1.0）：文档里用到的组件连同它们里面的组件，按「被依赖的在前」排序；
     * 只收本站已发布的。层数受 MAX_NESTING 限制（成环的母版发布不了）。
     *
     * @param array<int,mixed> $sections
     * @return list<array<string,mixed>> 已发布定义
     */
    public static function bundleDefinitions(array $sections): array
    {
        $ordered = [];
        $visit = static function (string $uuid, int $depth) use (&$visit, &$ordered): void {
            if (isset($ordered[$uuid]) || $depth > self::MAX_NESTING) {
                return;
            }
            $definition = self::find($uuid);
            if ($definition === null) {
                return;
            }
            foreach (self::directDependencies([$definition['root']]) as $inner) {
                $visit($inner, $depth + 1);
            }
            $ordered[$uuid] = $definition;
        };
        $roots = [];
        self::walkElements($sections, static function (array $element) use (&$roots): void {
            if (($element['type'] ?? '') === self::TYPE && self::validUuid($element['data']['component'] ?? null)) {
                $roots[(string) $element['data']['component']] = true;
            }
        });
        foreach (array_keys($roots) as $uuid) {
            $visit($uuid, 1);
        }
        return array_values($ordered);
    }

    /**
     * 临时把一组定义并进组件目录执行 $fn（导入模板包时，用包里带的母版展开实例）。同 uuid 以 $definitions 为准。
     *
     * @template T
     * @param array<string,array<string,mixed>> $definitions uuid => 定义
     * @param callable():T $fn
     * @return T
     */
    public static function withDefinitions(array $definitions, callable $fn): mixed
    {
        $previous = self::$catalog;
        self::$catalog = $definitions + self::catalog();
        try {
            return $fn();
        } finally {
            self::$catalog = $previous;
        }
    }

    /**
     * 导出保持当前效果（2.1.1）：固定在旧版本的实例按固定的那一版展开成普通结构——目标站拿到的母版是最新发布版，
     * 留着引用就会变样。未固定、固定在最新版、或固定的快照已不在（本站显示的就是最新版）的保持引用。
     * 展开出来的结构里还有固定在旧版本的内层实例，照样处理。
     */
    public static function expandBehindPins(mixed $tree, int &$count, int $depth = 0): mixed
    {
        if (!is_array($tree) || $depth > self::MAX_NESTING) {
            return $tree;
        }
        if (($tree['type'] ?? null) === self::TYPE && is_array($tree['data'] ?? null)) {
            $uuid = (string) ($tree['data']['component'] ?? '');
            $pin = (int) ($tree['data']['pin'] ?? 0);
            $latest = self::validUuid($uuid) ? self::find($uuid) : null;
            if ($latest === null || $pin <= 0 || $pin >= (int) $latest['version'] || self::pinMissing($uuid, $pin)) {
                return $tree;
            }
            $replacement = self::detach($tree);
            if ($replacement === null) {
                return $tree;
            }
            $count++;
            return self::expandBehindPins($replacement, $count, $depth + 1);
        }
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::expandBehindPins($value, $count, $depth);
            }
        }
        return $tree;
    }

    /**
     * 实例上去掉锁定的版本号（导出到别的站：版本号只在本站有意义）。先用 expandBehindPins 保住固定在旧版本的效果。
     *
     * @param array<int,mixed> $sections
     * @return array<int,mixed>
     */
    public static function stripPins(array $sections): array
    {
        $strip = static function (mixed $node) use (&$strip): mixed {
            if (!is_array($node)) {
                return $node;
            }
            if (($node['type'] ?? null) === self::TYPE && is_array($node['data'] ?? null)) {
                unset($node['data']['pin']);
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $strip($value);
                }
            }
            return $node;
        };
        return (array) $strip($sections);
    }

    /** 还留着修订快照的版本号（编辑器判断固定的版本还在不在）。 @return list<int> */
    public static function availableVersions(string $uuid): array
    {
        if (!self::validUuid($uuid) || !db()->tableExists('blox_component_revisions')) {
            return [];
        }
        return array_map('intval', array_column(db()->fetchAll(
            'SELECT DISTINCT version FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? ORDER BY version DESC',
            [$uuid]
        ), 'version'));
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
        if ($root === null || ($root['type'] ?? '') === self::TYPE) {   // 2.1.0：根之下可以有实例（组件套组件）
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
            'props' => $props = self::normalizePropsSchema($settings['props'] ?? [], $root),
            'variants' => self::normalizeVariants($settings['variants'] ?? [], $props),
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

    /**
     * @return array<string,array{docs:int,refs:int}>
     */
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

    /**
     * @return list<string> 使用该组件的文档键（page:N / template:N / home …）
     */
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

    /**
     * 使用位置的显示名与编辑入口（组件库「查看使用位置」）。
     *
     * @return array{doc_key:string,label:string,edit_url:string}
     */
    public static function describeDocKey(string $docKey): array
    {
        $label = $docKey;
        $url = '';
        if (preg_match('/^(page|channel|template):(\d+)$/', $docKey, $m) === 1) {
            $id = (int) $m[2];
            if ($m[1] === 'template') {
                $name = db()->fetchColumn('SELECT name FROM ' . DB_PREFIX . 'blox_templates WHERE id = ?', [$id]);
                $label = __('blox_component_used_template', ['name' => is_string($name) ? $name : '#' . $id]);
                $url = 'blox_editor.php?template=' . $id;
            } else {
                $name = db()->fetchColumn('SELECT name FROM ' . DB_PREFIX . 'channels WHERE id = ?', [$id]);
                $label = __('blox_component_used_page', ['name' => is_string($name) ? $name : '#' . $id]);
                $url = $m[1] === 'page' ? 'blox_editor.php?id=' . $id : '';
            }
        } elseif ($docKey === 'home' || $docKey === 'home-layout') {
            $label = __('blox_component_used_home');
            $url = 'blox_editor.php?home=1';
        }
        return ['doc_key' => $docKey, 'label' => $label, 'edit_url' => $url];
    }

    // ── 发布与修订 ────────────────────────────────────────────────────

    /** @return list<array{version:int,admin_id:int,created_at:int}> 新的在前 */
    public static function revisions(string $uuid): array
    {
        if (!self::validUuid($uuid) || !db()->tableExists('blox_component_revisions')) {
            return [];
        }
        return array_map(static fn (array $row): array => [
            'version' => (int) $row['version'],
            'admin_id' => (int) $row['admin_id'],
            'created_at' => (int) $row['created_at'],
        ], db()->fetchAll(
            'SELECT version, admin_id, created_at FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? ORDER BY version DESC, id DESC',
            [$uuid]
        ));
    }

    public static function revisionSnapshot(string $uuid, int $version): ?string
    {
        if (!self::validUuid($uuid) || $version < 1 || !db()->tableExists('blox_component_revisions')) {
            return null;
        }
        $snapshot = db()->fetchColumn(
            'SELECT snapshot FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? AND version = ? ORDER BY id DESC LIMIT 1',
            [$uuid, $version]
        );
        return is_string($snapshot) && $snapshot !== '' ? $snapshot : null;
    }

    // ── 发布与修订（写） ──────────────────────────────────────────────

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
            $older = $minId > 0 && count($keep) >= self::KEEP_REVISIONS && (int) db()->fetchColumn(
                'SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? AND id < ?',
                [$meta['uuid'], $minId]
            ) > 0;
            if ($older) {
                // 被页面锁定的版本不清理（2.1.1）：清掉了，锁定的页面就只能显示最新版
                $pinned = self::pinnedVersions($meta['uuid']);
                db()->execute(
                    'DELETE FROM ' . DB_PREFIX . 'blox_component_revisions WHERE component_uuid = ? AND id < ?'
                    . ($pinned === [] ? '' : ' AND version NOT IN (' . implode(', ', array_fill(0, count($pinned), '?')) . ')'),
                    array_merge([$meta['uuid'], $minId], $pinned)
                );
            }
        }
        self::$catalog = null;
        self::$pinned = [];
    }

    /**
     * 存着 Blox 文档、可能有固定版本实例的地方（2.1.1）：表 => 列。按文档扫描而不建索引表（小版本不带迁移）。
     * 页面历史版本不算——恢复旧历史时编辑器会提示固定的版本已不在。
     *
     * @return array<string,list<string>>
     */
    private static function pinStores(): array
    {
        return [
            'blox_page_drafts' => ['draft_data', 'published_data'],
            'contents' => ['blocks_data'],
            'blox_templates' => ['draft_data', 'published_data'],   // 含组件母版：组件里的组件也能固定
            'settings' => ['value'],
            'blox_component_revisions' => ['snapshot'],              // 外层留下来的版本里固定的内层版本
        ];
    }

    /**
     * 本站文档里固定在 $uuid 某个版本的实例（2.1.1）：清修订时这些版本要留着。
     *
     * @return list<int>
     */
    public static function pinnedVersions(string $uuid): array
    {
        if (!self::validUuid($uuid)) {
            return [];
        }
        $versions = [];
        foreach (self::pinStores() as $table => $columns) {
            if (!db()->tableExists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $rows = db()->fetchAll(
                    'SELECT ' . $column . ' AS doc FROM ' . DB_PREFIX . $table
                    . ' WHERE ' . $column . ' LIKE ? AND ' . $column . ' LIKE ?',
                    ['%' . $uuid . '%', '%"pin"%']
                );
                foreach ($rows as $row) {
                    self::walkPins(json_decode((string) ($row['doc'] ?? ''), true), static function (string $found, int $pin) use ($uuid, &$versions): void {
                        if ($found === $uuid) {
                            $versions[$pin] = true;
                        }
                    });
                }
            }
        }
        $versions = array_keys($versions);
        sort($versions);
        return $versions;
    }

    /**
     * 固定的版本已不在的实例（2.1.1 站点健康）：只看在用的文档（页面草稿与发布、正文、非组件模板、首页设置、组件母版）。
     *
     * @return array{instances:int,documents:int}
     */
    public static function missingPins(): array
    {
        $instances = 0;
        $documents = 0;
        foreach (self::pinStores() as $table => $columns) {
            if ($table === 'blox_component_revisions' || !db()->tableExists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                $rows = db()->fetchAll(
                    'SELECT ' . $column . ' AS doc FROM ' . DB_PREFIX . $table
                    . ' WHERE ' . $column . ' LIKE ? AND ' . $column . ' LIKE ?',
                    ['%"type":"' . self::TYPE . '"%', '%"pin"%']
                );
                foreach ($rows as $row) {
                    $missing = 0;
                    self::walkPins(json_decode((string) ($row['doc'] ?? ''), true), static function (string $uuid, int $pin) use (&$missing): void {
                        if (self::pinMissing($uuid, $pin)) {
                            $missing++;
                        }
                    });
                    if ($missing > 0) {
                        $instances += $missing;
                        $documents++;
                    }
                }
            }
        }
        return ['instances' => $instances, 'documents' => $documents];
    }

    /**
     * 任意 JSON 树里固定了版本的实例（按节点形状识别：首页、页头页尾、快照的格式各不相同）。
     *
     * @param callable(string,int):void $visit uuid, 固定的版本
     */
    private static function walkPins(mixed $tree, callable $visit): void
    {
        if (!is_array($tree)) {
            return;
        }
        if (($tree['type'] ?? null) === self::TYPE && self::validUuid($tree['data']['component'] ?? null)) {
            $pin = (int) ($tree['data']['pin'] ?? 0);
            if ($pin > 0) {
                $visit((string) $tree['data']['component'], $pin);
            }
        }
        foreach ($tree as $value) {
            if (is_array($value)) {
                self::walkPins($value, $visit);
            }
        }
    }

    // ── 工具 ──────────────────────────────────────────────────────────

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
