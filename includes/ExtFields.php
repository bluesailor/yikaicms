<?php
/**
 * 扩展字段（2.0.4 起对标 ACF 的「高级字段」）：类型注册、字段配置、值的校验/保存/读取都只走这一处。
 *
 * - 字段定义在 extfields 表；类型专属配置（子字段、关联目标、条件逻辑、挂载位置、行数限制）
 *   存 metas（owner_type=extfield、owner_id=字段 id、meta_key=config，JSON）——可移植表不加列。
 * - 字段值在 metas（owner_type=字段的 owner，owner_id=条目 id；全站选项页 owner=site、id=0）。
 * - 值的存储形态：link = JSON {url,title,target}；file = 网址；color = #hex；repeater = JSON 行数组；
 *   group = JSON 对象；relationship = 逗号分隔 id（保持选择顺序）；其余与 2.0.3 相同。
 * - 免费 / 专业版：渲染与编辑字段值永远免费；新建或修改「高级」字段定义（重复器、字段组、关联、
 *   条件逻辑、挂载位置、栏目 / 产品分类 / 选项页字段）需要 advanced_fields 授权（BloxFeaturePolicy）。
 */

declare(strict_types=1);

final class ExtFields
{
    /** 免费类型 => lang 键（顺序即后台下拉顺序） */
    public const CORE_TYPES = [
        'text' => 'ext_type_text',
        'textarea' => 'ext_type_textarea',
        'richtext' => 'ext_type_richtext',
        'number' => 'ext_type_number',
        'date' => 'ext_type_date',
        'switch' => 'ext_type_switch',
        'select' => 'ext_type_select',
        'multi_select' => 'ext_type_multi_select',
        'image' => 'ext_type_image',
        'images' => 'ext_type_images',
        'file' => 'ext_type_file',
        'link' => 'ext_type_link',
        'color' => 'ext_type_color',
    ];

    /** 专业版类型 */
    public const PRO_TYPES = [
        'repeater' => 'ext_type_repeater',
        'group' => 'ext_type_group',
        'relationship' => 'ext_type_relationship',
    ];

    /** 重复器 / 字段组里允许的子字段类型（不嵌套） */
    public const SUB_TYPES = ['text', 'textarea', 'number', 'date', 'switch', 'select', 'image', 'file', 'link', 'color'];

    /** 专业版字段归属：栏目、产品分类、全站选项页 */
    public const PRO_OWNERS = ['channel', 'product_category', 'site'];

    public const CONDITION_OPS = ['==', '!=', 'empty', 'not_empty'];
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';
    public const MAX_ROWS = 200;
    public const MAX_RELATED = 100;
    public const MAX_SUB_FIELDS = 30;

    /** @var array<string,list<array<string,mixed>>> 同请求复用的字段定义（按 owner） */
    private static array $fieldCache = [];

    // ── 类型与归属 ──────────────────────────────────────────────

    /** @return array<string,string> 类型 => 当前语言标签（含专业版类型） */
    public static function typeLabels(): array
    {
        $out = [];
        foreach (self::CORE_TYPES + self::PRO_TYPES as $type => $langKey) {
            $out[$type] = __($langKey);
        }
        return $out;
    }

    public static function isType(string $type): bool
    {
        return isset(self::CORE_TYPES[$type]) || isset(self::PRO_TYPES[$type]);
    }

    public static function isProType(string $type): bool
    {
        return isset(self::PRO_TYPES[$type]);
    }

    /** 高级字段的作者端授权（新建 / 修改高级字段定义）。 */
    public static function proAllowed(): bool
    {
        if (class_exists('BloxFeaturePolicy')) {
            return BloxFeaturePolicy::allows('advanced_fields');
        }
        return function_exists('license_owns_blox') && license_owns_blox();
    }

    /**
     * 合法归属：内容 / 产品 / 自定义模型 + 栏目 / 产品分类 / 全站选项页。
     * @return list<string>
     */
    public static function owners(): array
    {
        $owners = function_exists('extFieldOwnerTypes') ? extFieldOwnerTypes() : ['content', 'product'];
        return array_values(array_unique(array_merge($owners, self::PRO_OWNERS)));
    }

    public static function isProOwner(string $owner): bool
    {
        return in_array($owner, self::PRO_OWNERS, true);
    }

    /** 归属的显示名（后台标签页）。 */
    public static function ownerLabel(string $owner): string
    {
        return match ($owner) {
            'content' => __('extfield_content'),
            'product' => __('extfield_product'),
            'channel' => __('ef_owner_channel'),
            'product_category' => __('ef_owner_product_category'),
            'site' => __('ef_owner_site'),
            default => self::modelName($owner),
        };
    }

    private static function modelName(string $key): string
    {
        try {
            foreach (contentModelModel()->allActive() as $model) {
                if ((string) ($model['model_key'] ?? '') === $key) {
                    return (string) ($model['name'] ?? $key);
                }
            }
        } catch (Throwable) {
            // 表未建
        }
        return $key;
    }

    // ── 字段定义与配置 ──────────────────────────────────────────

    /**
     * 某归属的字段（带解码后的 config）。
     * @return list<array<string,mixed>>
     */
    public static function fields(string $owner, bool $onlyEnabled = true): array
    {
        $cacheKey = $owner . ($onlyEnabled ? ':1' : ':0');
        if (isset(self::$fieldCache[$cacheKey])) {
            return self::$fieldCache[$cacheKey];
        }
        try {
            if (!db()->tableExists('extfields')) {
                return self::$fieldCache[$cacheKey] = [];
            }
            $rows = extFieldModel()->getByOwner($owner, $onlyEnabled);
        } catch (Throwable) {
            return self::$fieldCache[$cacheKey] = [];
        }
        $configs = [];
        if ($rows !== []) {
            try {
                $configs = metaModel()->getAllByOwnerIds('extfield', array_map(static fn (array $r): int => (int) $r['id'], $rows));
            } catch (Throwable) {
                $configs = [];
            }
        }
        foreach ($rows as &$row) {
            $raw = $configs[(int) $row['id']]['config'] ?? '';
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $row['config'] = self::normalizeConfig((string) $row['field_type'], is_array($decoded) ? $decoded : []);
        }
        unset($row);
        return self::$fieldCache[$cacheKey] = array_values($rows);
    }

    /** 按键取字段定义；没有返回 null。 */
    public static function field(string $owner, string $key): ?array
    {
        foreach (self::fields($owner, false) as $field) {
            if ($field['field_key'] === $key) {
                return $field;
            }
        }
        return null;
    }

    public static function flushCache(): void
    {
        self::$fieldCache = [];
    }

    public static function saveConfig(int $fieldId, string $type, array $config): void
    {
        $config = self::normalizeConfig($type, $config);
        if ($config === []) {
            delMeta('extfield', $fieldId, 'config');
        } else {
            setMeta('extfield', $fieldId, 'config', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        self::flushCache();
    }

    /** 配置是否用到专业版能力（条件逻辑、挂载位置——子字段与关联目标随专业版类型而来）。 */
    public static function configUsesPro(array $config): bool
    {
        return !empty($config['conditions']) || !empty($config['location']);
    }

    /**
     * 配置归一（唯一白名单入口）：只保留该类型认识的键，值收紧到合法形状。
     * @return array<string,mixed>
     */
    public static function normalizeConfig(string $type, array $raw): array
    {
        $out = [];
        if ($type === 'repeater' || $type === 'group') {
            $subs = [];
            $seen = [];
            foreach (array_slice(is_array($raw['sub_fields'] ?? null) ? $raw['sub_fields'] : [], 0, self::MAX_SUB_FIELDS) as $sub) {
                if (!is_array($sub)) {
                    continue;
                }
                $key = strtolower(trim((string) ($sub['key'] ?? '')));
                $subType = (string) ($sub['type'] ?? 'text');
                if (!preg_match(self::KEY_PATTERN, $key) || isset($seen[$key]) || !in_array($subType, self::SUB_TYPES, true)) {
                    continue;
                }
                $seen[$key] = true;
                $name = trim((string) ($sub['name'] ?? ''));
                $item = ['key' => $key, 'name' => mb_substr($name !== '' ? $name : $key, 0, 100), 'type' => $subType];
                if (in_array($subType, ['select'], true) && trim((string) ($sub['options'] ?? '')) !== '') {
                    $item['options'] = mb_substr(trim((string) $sub['options']), 0, 5000);
                }
                $subs[] = $item;
            }
            $out['sub_fields'] = $subs;
            if ($type === 'repeater') {
                $min = max(0, min(self::MAX_ROWS, (int) ($raw['min'] ?? 0)));
                $max = max(0, min(self::MAX_ROWS, (int) ($raw['max'] ?? 0)));
                if ($min > 0) {
                    $out['min'] = $min;
                }
                if ($max > 0) {
                    $out['max'] = max($max, $min);
                }
                $label = trim((string) ($raw['button_label'] ?? ''));
                if ($label !== '') {
                    $out['button_label'] = mb_substr($label, 0, 60);
                }
            }
        }
        if ($type === 'relationship') {
            $target = (string) ($raw['target'] ?? 'product');
            $out['target'] = preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $target) ? $target : 'product';
            $max = max(0, min(self::MAX_RELATED, (int) ($raw['max'] ?? 0)));
            if ($max > 0) {
                $out['max'] = $max;
            }
        }
        $conditions = [];
        foreach (array_slice(is_array($raw['conditions'] ?? null) ? $raw['conditions'] : [], 0, 10) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? '==');
            if (!preg_match(self::KEY_PATTERN, $field) || !in_array($op, self::CONDITION_OPS, true)) {
                continue;
            }
            $conditions[] = ['field' => $field, 'op' => $op, 'value' => mb_substr((string) ($rule['value'] ?? ''), 0, 200)];
        }
        if ($conditions !== []) {
            $out['conditions'] = $conditions;
        }
        $location = array_values(array_unique(array_filter(array_map('intval', is_array($raw['location'] ?? null) ? $raw['location'] : []), static fn (int $id): bool => $id > 0)));
        if ($location !== []) {
            $out['location'] = array_slice($location, 0, 200);
        }
        return $out;
    }

    /** 选项：JSON 对象或每行 "键|显示名"。@return array<string,string> */
    public static function parseOptions(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        if ($raw[0] === '{') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return [];
            }
            $out = [];
            foreach ($decoded as $key => $label) {
                if (is_scalar($label)) {
                    $out[(string) $key] = (string) $label;
                }
            }
            return $out;
        }
        $out = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_contains($line, '|')) {
                [$key, $label] = explode('|', $line, 2);
                $out[trim($key)] = trim($label);
            } else {
                $out[$line] = $line;
            }
        }
        return $out;
    }

    // ── 挂载位置与条件逻辑 ──────────────────────────────────────

    /**
     * 挂载位置是否命中：产品字段按产品分类（含子分类），内容字段按栏目（含子栏目）。
     * 没设位置 = 处处显示。$termId 为 0（新建还没选分类）时只显示没设位置的字段。
     */
    public static function locationMatches(array $field, string $owner, int $termId): bool
    {
        $location = (array) ($field['config']['location'] ?? []);
        if ($location === []) {
            return true;
        }
        if ($termId <= 0) {
            return false;
        }
        foreach (self::locationTerms($location, $owner) as $id) {
            if ($id === $termId) {
                return true;
            }
        }
        return false;
    }

    /** 位置展开成含子级的 id 列表（后台脚本切换分类时同样用到）。@return list<int> */
    public static function locationTerms(array $location, string $owner): array
    {
        $ids = [];
        foreach ($location as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            try {
                if ($owner === 'product' && function_exists('getChildProductCategoryIds')) {
                    $ids = array_merge($ids, array_map('intval', getChildProductCategoryIds($id)));
                    continue;
                }
                if ($owner !== 'product' && function_exists('channelModel')) {
                    $ids = array_merge($ids, array_map('intval', channelModel()->getChildIds($id)));
                    continue;
                }
            } catch (Throwable) {
                // 缺表：只认本身
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /** 条件逻辑（全部满足才显示）。$values 是同一表单里其它字段的存储值。 */
    public static function conditionsMatch(array $field, array $values): bool
    {
        foreach ((array) ($field['config']['conditions'] ?? []) as $rule) {
            $value = (string) ($values[$rule['field']] ?? '');
            $ok = match ($rule['op']) {
                'empty' => trim($value) === '',
                'not_empty' => trim($value) !== '',
                '!=' => !in_array((string) $rule['value'], explode(',', $value), true),
                default => in_array((string) $rule['value'], explode(',', $value), true),
            };
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    // ── 值：校验与保存 ──────────────────────────────────────────

    /**
     * 提交值 → 存储字符串（按类型收紧；非法值存空）。
     * @param array<string,mixed> $field 字段定义（含 config）或子字段 {key,type,options}
     */
    public static function sanitize(array $field, mixed $raw): string
    {
        $type = (string) ($field['field_type'] ?? $field['type'] ?? 'text');
        $options = (string) ($field['options'] ?? '');
        switch ($type) {
            case 'textarea':
            case 'text':
                return is_scalar($raw) ? self::cleanText((string) $raw, $type === 'text' ? 2000 : 20000) : '';
            case 'richtext':
                return is_scalar($raw) && function_exists('sanitizeHtml') ? sanitizeHtml((string) $raw) : '';
            case 'number':
                $raw = is_scalar($raw) ? trim((string) $raw) : '';
                return $raw !== '' && is_numeric($raw) ? (string) (0 + $raw) : '';
            case 'date':
                $raw = is_scalar($raw) ? trim((string) $raw) : '';
                $date = DateTime::createFromFormat('!Y-m-d', $raw);
                return $date !== false && $date->format('Y-m-d') === $raw ? $raw : '';
            case 'switch':
                return in_array($raw, [true, 1, '1', 'on', 'true'], true) ? '1' : '0';
            case 'select':
                $raw = is_scalar($raw) ? (string) $raw : '';
                return array_key_exists($raw, self::parseOptions($options)) ? $raw : '';
            case 'multi_select':
                $choices = self::parseOptions($options);
                $picked = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
                $picked = array_values(array_unique(array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $picked),
                    static fn (string $v): bool => $v !== '' && array_key_exists($v, $choices))));
                return implode(',', $picked);
            case 'image':
            case 'file':
                return is_scalar($raw) ? self::cleanUrl((string) $raw) : '';
            case 'images':
                $urls = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
                $urls = array_values(array_filter(array_map(static fn ($v): string => is_scalar($v) ? self::cleanUrl((string) $v) : '', $urls)));
                return implode(',', array_slice($urls, 0, self::MAX_ROWS));
            case 'color':
                $raw = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';
                return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/D', $raw) ? $raw : '';
            case 'link':
                $link = self::decodeJson($raw);
                $url = self::cleanUrl((string) ($link['url'] ?? ''));
                if ($url === '') {
                    return '';
                }
                $value = ['url' => $url, 'title' => self::cleanText((string) ($link['title'] ?? ''), 200)];
                if (($link['target'] ?? '') === '_blank') {
                    $value['target'] = '_blank';
                }
                return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case 'repeater':
                $rows = [];
                $subs = (array) ($field['config']['sub_fields'] ?? []);
                $max = (int) ($field['config']['max'] ?? 0) ?: self::MAX_ROWS;
                foreach (self::decodeJson($raw) as $row) {
                    if (count($rows) >= $max) {
                        break;
                    }
                    if (!is_array($row)) {
                        continue;
                    }
                    $clean = self::sanitizeSubFields($subs, $row);
                    if (self::hasContent($clean)) {
                        $rows[] = $clean;
                    }
                }
                return $rows === [] ? '' : (string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            case 'group':
                $clean = self::sanitizeSubFields((array) ($field['config']['sub_fields'] ?? []), self::decodeJson($raw));
                return self::hasContent($clean) ? (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            case 'relationship':
                $ids = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
                $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
                $max = (int) ($field['config']['max'] ?? 0) ?: self::MAX_RELATED;
                return implode(',', array_slice($ids, 0, $max));
        }
        return '';
    }

    /** @return array<string,string> */
    private static function sanitizeSubFields(array $subs, array $row): array
    {
        $clean = [];
        foreach ($subs as $sub) {
            $key = (string) ($sub['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $clean[$key] = self::sanitize($sub, $row[$key] ?? '');
        }
        return $clean;
    }

    /**
     * 保存一个条目的字段值。只写该归属已定义的字段；没提交的字段（被条件 / 挂载隐藏、
     * 或别的语言版本没有）保持原值。
     * @param array<array-key,mixed> $posted ext_fields[...]
     */
    public static function save(string $owner, int $ownerId, array $posted): void
    {
        if ($ownerId < 0 || !in_array($owner, self::owners(), true)) {
            return;
        }
        foreach (self::fields($owner, true) as $field) {
            $key = (string) $field['field_key'];
            if (!array_key_exists($key, $posted)) {
                // 多选 / 开关全不勾时浏览器不提交：用占位隐藏域区分「没提交」和「清空」
                if (!array_key_exists('__present_' . $key, $posted)) {
                    continue;
                }
                $posted[$key] = $field['field_type'] === 'switch' ? '0' : '';
            }
            setMeta($owner, $ownerId, $key, self::sanitize($field, $posted[$key]));
        }
    }

    /**
     * 必填校验：返回第一个没填的字段名；全部填了返回 null。被条件逻辑或挂载位置隐藏的字段不校验。
     * @param array<array-key,mixed> $posted
     */
    public static function missingRequired(string $owner, array $posted, int $termId = 0): ?string
    {
        $values = [];
        $fields = self::fields($owner, true);
        foreach ($fields as $field) {
            $values[$field['field_key']] = array_key_exists($field['field_key'], $posted) ? self::sanitize($field, $posted[$field['field_key']]) : '';
        }
        foreach ($fields as $field) {
            if (!(int) $field['is_required'] || $field['field_type'] === 'switch') {
                continue;
            }
            if (!self::locationMatches($field, $owner, $termId) || !self::conditionsMatch($field, $values)) {
                continue;
            }
            if (trim($values[$field['field_key']]) === '') {
                return (string) $field['field_name'];
            }
        }
        return null;
    }

    /** 翻译新建语言版本时，把原文的字段值复制过去（之后各自维护）。 */
    public static function copyValues(string $owner, int $fromId, int $toId): void
    {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            return;
        }
        $values = getAllMeta($owner, $fromId);
        foreach (self::fields($owner, false) as $field) {
            $key = (string) $field['field_key'];
            if (isset($values[$key]) && $values[$key] !== '' && getMeta($owner, $toId, $key) === null) {
                setMeta($owner, $toId, $key, $values[$key]);
            }
        }
    }

    // ── 值：读取 ────────────────────────────────────────────────

    /** 原始存储值；没有返回 ''。 */
    public static function raw(string $owner, int $ownerId, string $key): string
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return '';
        }
        $value = getMeta($owner, $ownerId, $key);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * 解码后的值：重复器 = 行数组，组 / 链接 = 关联数组，多选 / 多图 / 关联 = 列表，其余字符串。
     */
    public static function decode(string $type, string $stored): mixed
    {
        switch ($type) {
            case 'repeater':
                $rows = json_decode($stored, true);
                return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
            case 'group':
            case 'link':
                $value = json_decode($stored, true);
                return is_array($value) ? $value : [];
            case 'multi_select':
            case 'images':
                return $stored === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $stored)), static fn (string $v): bool => $v !== ''));
            case 'relationship':
                return $stored === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $stored))));
        }
        return $stored;
    }

    /**
     * 动态标签 / 模板标签用的纯文本：链接 = 网址（.title 取文字），组 = 指定子键，多选 = 显示名，
     * 开关 = 1 / 空，重复器 / 关联 = 行数 / 条数（多行内容请用循环）。
     */
    public static function text(array $field, string $stored, string $sub = ''): string
    {
        $type = (string) ($field['field_type'] ?? $field['type'] ?? 'text');
        $value = self::decode($type, $stored);
        switch ($type) {
            case 'link':
                return is_array($value) ? (string) ($value[$sub !== '' ? $sub : 'url'] ?? '') : '';
            case 'group':
                if (!is_array($value) || $sub === '') {
                    return '';
                }
                foreach ((array) ($field['config']['sub_fields'] ?? []) as $subField) {
                    if (($subField['key'] ?? '') === $sub) {
                        return self::text($subField, (string) ($value[$sub] ?? ''));
                    }
                }
                return '';
            case 'select':
                return self::parseOptions((string) ($field['options'] ?? ''))[$stored] ?? $stored;
            case 'multi_select':
                $choices = self::parseOptions((string) ($field['options'] ?? ''));
                return implode(', ', array_map(static fn (string $v): string => $choices[$v] ?? $v, (array) $value));
            case 'switch':
                return $stored === '1' ? '1' : '';
            case 'images':
                return (string) (((array) $value)[0] ?? '');
            case 'repeater':
            case 'relationship':
                return $value === [] ? '' : (string) count((array) $value);
            case 'richtext':
                return trim(html_entity_decode(strip_tags($stored), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return $stored;
    }

    /**
     * 前台展示用 HTML（字段表格元素）：图片出缩略图、链接 / 文件出链接、颜色出色块、开关出是 / 否，
     * 富文本原样（保存时已净化），其余转义后输出。空值返回 ''。
     * @param array<string,mixed> $def 字段定义或子字段 {key,type,options}
     */
    public static function html(array $def, string $stored): string
    {
        $type = (string) ($def['field_type'] ?? $def['type'] ?? 'text');
        if ($stored === '' || ($type !== 'switch' && trim($stored) === '')) {
            return '';
        }
        switch ($type) {
            case 'image':
                return '<img src="' . e($stored) . '" alt="" loading="lazy" decoding="async" class="h-16 w-auto rounded">';
            case 'images':
                $out = '';
                foreach ((array) self::decode('images', $stored) as $url) {
                    $out .= '<img src="' . e((string) $url) . '" alt="" loading="lazy" decoding="async" class="h-16 w-auto rounded">';
                }
                return '<span class="inline-flex flex-wrap gap-2">' . $out . '</span>';
            case 'file':
                $name = rawurldecode(basename((string) parse_url($stored, PHP_URL_PATH)));
                return '<a href="' . e($stored) . '" class="inline-flex items-center gap-1 text-primary hover:underline" download><i class="ti ti-download" aria-hidden="true"></i>' . e($name !== '' ? $name : $stored) . '</a>';
            case 'link':
                $link = (array) self::decode('link', $stored);
                $url = (string) ($link['url'] ?? '');
                if ($url === '') {
                    return '';
                }
                $title = trim((string) ($link['title'] ?? ''));
                return '<a href="' . e($url) . '" class="text-primary hover:underline"' . (($link['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '') . '>' . e($title !== '' ? $title : $url) . '</a>';
            case 'color':
                return '<span class="inline-flex items-center gap-2"><span class="inline-block h-4 w-4 rounded border" style="background:' . e($stored) . '"></span>' . e($stored) . '</span>';
            case 'switch':
                return e($stored === '1' ? __('setting_yes') : __('setting_no'));
            case 'richtext':
                return $stored;
            case 'textarea':
                return nl2br(e($stored));
            case 'repeater':
            case 'group':
            case 'relationship':
                return '';
        }
        return e(self::text($def, $stored));
    }

    /** 条目字段的纯文本（动态标签入口）：未定义的键按原样读 metas（兼容导入的参数）。 */
    public static function textFor(string $owner, int $ownerId, string $key, string $sub = ''): string
    {
        $stored = self::raw($owner, $ownerId, $key);
        if ($stored === '') {
            return '';
        }
        $field = self::field($owner, $key);
        return $field === null ? trim($stored) : trim(self::text($field, $stored, $sub));
    }

    /**
     * 重复器的行（子键 => 存储值），供循环与字段表格用。
     * @return list<array<string,string>>
     */
    public static function rows(string $owner, int $ownerId, string $key): array
    {
        $field = self::field($owner, $key);
        if ($field === null || $field['field_type'] !== 'repeater') {
            return [];
        }
        $rows = [];
        foreach ((array) self::decode('repeater', self::raw($owner, $ownerId, $key)) as $row) {
            $clean = [];
            foreach ($row as $subKey => $value) {
                if (is_string($subKey) && is_scalar($value)) {
                    $clean[$subKey] = (string) $value;
                }
            }
            $rows[] = $clean;
        }
        return $rows;
    }

    /**
     * 关联字段的 id 换成指定语言的版本（2.0.5 稳定回归）：翻译条目时 copyValues 照搬原文的关联 id，
     * 它们指向原文语言的条目；在译文页面上按翻译组换成同语言的兄弟，保持选择顺序，没有该语言版本的去掉。
     * $table：products 或 contents（不带前缀）。
     * @param list<int> $ids
     * @return list<int>
     */
    public static function localizeIds(string $table, array $ids, string $lang): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === [] || !in_array($table, ['products', 'contents'], true)) {
            return $ids;
        }
        $t = (defined('DB_PREFIX') ? DB_PREFIX : '') . $table;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = array_column(db()->fetchAll("SELECT id, lang, translation_group_id FROM {$t} WHERE id IN ({$marks})", $ids), null, 'id');
        $groups = [];
        foreach ($rows as $row) {
            if ((string) $row['lang'] !== $lang) {
                $groups[] = (int) ($row['translation_group_id'] ?: $row['id']);
            }
        }
        $siblings = [];
        if ($groups !== []) {
            $groups = array_values(array_unique($groups));
            $marks = implode(',', array_fill(0, count($groups), '?'));
            // 原文行的 translation_group_id 可能是 0（老数据：只有译文指向原文 id），所以也按 id 认
            $sql = "SELECT id, translation_group_id FROM {$t} WHERE lang = ? AND (translation_group_id IN ({$marks}) OR id IN ({$marks})) ORDER BY id";
            foreach (db()->fetchAll($sql, array_merge([$lang], $groups, $groups)) as $row) {
                $siblings[(int) ($row['translation_group_id'] ?: $row['id'])] ??= (int) $row['id'];
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $mapped = (string) $row['lang'] === $lang ? $id : ($siblings[(int) ($row['translation_group_id'] ?: $row['id'])] ?? 0);
            if ($mapped > 0 && !in_array($mapped, $out, true)) {
                $out[] = $mapped;
            }
        }
        return $out;
    }

    // ── 小工具 ──────────────────────────────────────────────────

    /** 行 / 组里是否有实际内容（开关默认的 0 不算）。 */
    private static function hasContent(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== '' && $value !== '0') {
                return true;
            }
        }
        return false;
    }

    private static function cleanText(string $value, int $max): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        return mb_substr(trim($value), 0, $max);
    }

    private static function cleanUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return function_exists('safeUrl') ? safeUrl($url) : (preg_match('#^(https?://|/)#i', $url) ? $url : '');
    }

    /** JSON 字符串或数组 → 数组（其他一律空数组）。 */
    private static function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

/**
 * 主题模板取扩展字段（2.0.4）：解码后的值——重复器 = 行数组、字段组 / 链接 = 关联数组、
 * 多选 / 多图 / 关联 = 列表，其余字符串。不传 id 时取当前详情页条目（或循环里的当前行）。
 * 例：foreach (ykField('specs') as $row) { echo e($row['model']); }
 */
function ykField(string $key, ?int $id = null, ?string $owner = null): mixed
{
    if ($id === null || $owner === null) {
        [$autoOwner, $autoId] = class_exists('BloxLoopQuery') ? BloxLoopQuery::fieldOwnerItem() : ['', 0];
        $owner ??= $autoOwner;
        $id ??= $autoId;
    }
    if ($owner === '' || ($id <= 0 && $owner !== 'site')) {
        return '';
    }
    $field = ExtFields::field($owner, $key);
    $stored = ExtFields::raw($owner, (int) $id, $key);
    return $field === null ? $stored : ExtFields::decode((string) $field['field_type'], $stored);
}

/** 全站选项（2.0.4）：ykOption('factory_area')；重复器返回行数组。 */
function ykOption(string $key): mixed
{
    return ykField($key, 0, 'site');
}
