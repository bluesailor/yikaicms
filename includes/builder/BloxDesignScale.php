<?php
/**
 * 设计系统的圆角与阴影 token（v2.1 token 类别扩展第一步，2.0.4 提前）。
 *
 * 与颜色 token 同存 blox_design_system 设置（radii / shadows 两个列表），由 BloxDesignSystem 读写；
 * 这里只放纯函数：取值校验、出厂刻度、引用解析与 :root 变量输出。
 * - 输出 --yk-radius-<id> / --yk-shadow-<id>；消费方一律写 var(--x, 回退值)，token 被删也不坏版面；
 * - token 可引用同类另一个 token（值写 {id}），只解析一层：被引用的若本身也是引用，则不输出（消费方走回退值）。
 * 与 BloxDesignTheme（排版 / 按钮 / 布局）不重叠，所以先于三源归一单独上线；全部免费。
 */

declare(strict_types=1);

final class BloxDesignScale
{
    public const KINDS = ['radius' => 'radii', 'shadow' => 'shadows'];
    public const MAX_ITEMS = 24;
    private const ID_PATTERN = '/^[a-z][a-z0-9_-]{0,47}$/';
    private const REF_PATTERN = '/^\{([a-z][a-z0-9_-]{0,47})\}$/';
    private const SHADOW_COLOR = '(?:#[0-9a-f]{3,8}|rgba?\([0-9.,\s%]+\)|var\(--yk-color-[a-z][a-z0-9_-]{0,47}\))';
    private const SHADOW_LENGTH = '(?:-?\d{1,3}(?:\.\d+)?px|0)';

    /** 出厂刻度（站点从未保存过该类时使用） @return list<array{id:string,value:string}> */
    public static function seeds(string $kind): array
    {
        return $kind === 'radius'
            ? [['id' => 'sm', 'value' => '4px'], ['id' => 'md', 'value' => '8px'], ['id' => 'lg', 'value' => '12px'],
                ['id' => 'xl', 'value' => '16px'], ['id' => 'full', 'value' => '9999px']]
            : [['id' => 'sm', 'value' => '0 1px 2px rgba(15,23,42,.06)'], ['id' => 'md', 'value' => '0 4px 12px rgba(15,23,42,.08)'],
                ['id' => 'lg', 'value' => '0 12px 32px rgba(15,23,42,.12)']];
    }

    /** 出厂项的名称（按后台语言） */
    public static function seedName(string $kind, string $id): string
    {
        return __('blox_scale_' . $kind . '_' . $id);
    }

    /** 单个取值校验；合法返回规范串，引用返回 {id}，不合法返回 null。 */
    public static function normalizeValue(string $kind, mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,4}$/', trim($value)) === 1)) {
            $value = $kind === 'radius' ? ((int) $value) . 'px' : (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
        if ($value === '' || strlen($value) > 200) {
            return null;
        }
        if (preg_match(self::REF_PATTERN, $value) === 1) {
            return $value;
        }
        if ($kind === 'radius') {
            if (preg_match('/^(\d{1,3}(?:\.\d{1,2})?)px$/', $value, $m) === 1) {
                return ((float) $m[1] <= 999 ? $m[1] : '999') . 'px';
            }
            if ($value === '9999px') {
                return $value;
            }
            if (preg_match('/^(\d{1,2}(?:\.\d{1,3})?)rem$/', $value, $m) === 1) {
                return $m[1] . 'rem';
            }
            return null;
        }
        if ($value === 'none') {
            return $value;
        }
        $layers = array_map('trim', self::splitLayers($value));
        if ($layers === [] || count($layers) > 3) {
            return null;
        }
        $layer = '/^(?:inset )?(?:' . self::SHADOW_LENGTH . ' ){1,3}' . self::SHADOW_LENGTH . ' ' . self::SHADOW_COLOR . '$/';
        foreach ($layers as $item) {
            if (preg_match($layer, $item) !== 1) {
                return null;
            }
        }
        return implode(',', $layers);
    }

    /**
     * 一组 token 的归一（读设置时用）。缺列表时给出厂刻度；系统不强制保留出厂项（站长可以改名、归档）。
     *
     * @return list<array{id:string,name:string,value:string,status:string,locked:bool,version:int}>
     */
    public static function normalizeList(string $kind, mixed $raw): array
    {
        $items = [];
        $source = is_array($raw) ? $raw : array_map(static fn(array $seed): array => $seed + ['name' => self::seedName($kind, $seed['id'])], self::seeds($kind));
        foreach ($source as $item) {
            $normalized = self::normalizeItem($kind, $item);
            if ($normalized !== null && !isset($items[$normalized['id']]) && count($items) < self::MAX_ITEMS) {
                $items[$normalized['id']] = $normalized;
            }
        }
        return array_values($items);
    }

    /** @return array{id:string,name:string,value:string,status:string,locked:bool,version:int}|null */
    public static function normalizeItem(string $kind, mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 60);
        $value = self::normalizeValue($kind, $item['value'] ?? null);
        if (preg_match(self::ID_PATTERN, $id) !== 1 || $name === '' || $value === null || $value === '{' . $id . '}') {
            return null;
        }
        return [
            'id' => $id,
            'name' => $name,
            'value' => $value,
            'status' => ($item['status'] ?? '') === 'archived' ? 'archived' : 'active',
            'locked' => !empty($item['locked']),
            'version' => max(1, (int) ($item['version'] ?? 1)),
        ];
    }

    /** 引用是否可用：目标存在、不是自己、本身不是引用。 @param list<array<string,mixed>> $items */
    public static function referenceValid(array $items, string $id, string $value): bool
    {
        if (preg_match(self::REF_PATTERN, $value, $m) !== 1) {
            return true;
        }
        if ($m[1] === $id) {
            return false;
        }
        foreach ($items as $item) {
            if (($item['id'] ?? '') === $m[1]) {
                return preg_match(self::REF_PATTERN, (string) $item['value']) !== 1;
            }
        }
        return false;
    }

    /**
     * :root 声明串。归档的照常输出（墓碑，存量引用不坏）；坏引用跳过。
     *
     * @param list<array<string,mixed>> $items
     */
    public static function declarations(string $kind, array $items): string
    {
        $css = '';
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $value = (string) ($item['value'] ?? '');
            if (preg_match(self::ID_PATTERN, $id) !== 1 || $value === '') {
                continue;
            }
            if (preg_match(self::REF_PATTERN, $value, $m) === 1) {
                if (!self::referenceValid($items, $id, $value)) {
                    continue;
                }
                $value = 'var(--yk-' . $kind . '-' . $m[1] . ')';
            }
            $css .= '--yk-' . $kind . '-' . $id . ':' . $value . ';';
        }
        return $css;
    }

    /** 消费方用的 CSS 值：var(--yk-<kind>-<id>, 回退)。id 不合法返回 null。 */
    public static function cssVar(string $kind, mixed $id, string $fallback): ?string
    {
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1 || !isset(self::KINDS[$kind])) {
            return null;
        }
        return 'var(--yk-' . $kind . '-' . $id . ',' . $fallback . ')';
    }

    /** 逗号分层，但不拆 rgba( … ) 里的逗号 @return list<string> */
    private static function splitLayers(string $value): array
    {
        $layers = [];
        $depth = 0;
        $current = '';
        foreach (str_split($value) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $layers[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $layers[] = $current;
        return $layers;
    }
}
