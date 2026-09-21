<?php
/**
 * 内容维护模式（E07）：允许换图改字，拒绝改结构。
 *
 * 计划的要求是"**保护结构必须在服务端生效，不只是隐藏按钮**"——隐藏按钮只能挡住
 * 照着界面操作的人，挡不住改一个请求体就提交的人。所以判定放在保存管线里，
 * 与 BloxProtectedFields 同一位置、同一手法：把两份文档投影成只剩结构的形状再比对。
 *
 * 结构 = 区块与元素的身份、类型、嵌套顺序，以及布局/样式设置。
 * 内容 = 文字、图片、链接、alt 这些维护人员本来就该改的字段。
 *
 * 维护模式**不删除**任何既有设置：被拒绝的是"这次提交改了结构"，
 * 文档里原有的结构与样式原样留着，切回设计模式即可继续编辑。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class BloxMaintenanceMode
{
    /**
     * 维护模式下可以改的内容字段。
     *
     * 只列真正属于"内容"的键：文案、媒体、链接与无障碍文本。凡是决定版式、间距、
     * 颜色、可见条件、循环查询的键都不在此列——它们变了就是结构变了。
     * 新增元素控件时，若它是内容性质的，要显式加进来；漏加的后果是维护模式下改不了，
     * 而不是意外放行，这个方向的失败是安全的。
     */
    private const CONTENT_KEYS = [
        'text', 'html', 'title', 'subtitle', 'summary', 'caption', 'label', 'content',
        'src', 'alt', 'image', 'images', 'cover', 'poster', 'video', 'file',
        'url', 'link_url', 'href', 'link_new_tab', 'button_label', 'button_url',
        'overlay_title', 'overlay_text', 'overlay_button_label',
        'lightbox_caption', 'placeholder', 'name', 'email', 'phone', 'address',
    ];

    /** 站点是否处于内容维护模式。 */
    public static function active(): bool
    {
        return (string) config('blox_maintenance_mode', '0') === '1';
    }

    /**
     * 维护模式下校验本次保存：结构有任何改动即拒绝。
     *
     * @param array<int,mixed> $sections 本次提交的区块
     * @param array<int,mixed> $trusted  服务端已存的区块（可信基线）
     */
    public static function assertContentOnly(array $sections, array $trusted): void
    {
        if (self::structure($sections) !== self::structure($trusted)) {
            throw new RuntimeException(__('blox_maintenance_structure_locked'));
        }
    }

    /**
     * 把文档投影成只剩结构的形状：留下身份、类型与非内容设置，丢掉内容字段。
     *
     * 丢内容而不是留内容，是因为字段集合会随元素演进而增长——漏掉一个新的内容键
     * 只会让它被当成结构（保守拒绝），而漏掉一个新的结构键则会放行结构改动。
     *
     * @param array<int,mixed> $sections
     * @return list<mixed>
     */
    public static function structure(array $sections): array
    {
        $shape = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                $shape[] = null;
                continue;
            }
            $shape[] = [
                'id' => (string) ($section['id'] ?? ''),
                'settings' => self::strip($section['settings'] ?? []),
                'columns' => self::columns($section['columns'] ?? []),
            ];
        }
        return $shape;
    }

    /** @param mixed $columns @return list<mixed> */
    private static function columns(mixed $columns): array
    {
        if (!is_array($columns)) {
            return [];
        }
        $shape = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                $shape[] = null;
                continue;
            }
            $elements = [];
            foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                $elements[] = self::element($element);
            }
            $shape[] = ['settings' => self::strip($column['settings'] ?? []), 'elements' => $elements];
        }
        return $shape;
    }

    /** @return array<string,mixed>|null */
    private static function element(mixed $element): ?array
    {
        if (!is_array($element)) {
            return null;
        }
        $children = [];
        foreach (is_array($element['data']['children'] ?? null) ? $element['data']['children'] : [] as $child) {
            $children[] = self::element($child);
        }
        return [
            // 身份与类型变了就是换了元素，不是改内容
            'id' => (string) ($element['id'] ?? ''),
            'type' => (string) ($element['type'] ?? ''),
            'data' => self::strip($element['data'] ?? [], true),
            'children' => $children,
        ];
    }

    /**
     * 去掉内容字段，保留其余一切。
     *
     * @param mixed $value
     * @param bool $dropChildren children 已单独投影，避免在 data 里重复比较
     * @return array<string,mixed>
     */
    private static function strip(mixed $value, bool $dropChildren = false): array
    {
        if (!is_array($value)) {
            return [];
        }
        $kept = [];
        foreach ($value as $key => $item) {
            $name = (string) $key;
            if (in_array($name, self::CONTENT_KEYS, true)) {
                continue;
            }
            if ($dropChildren && $name === 'children') {
                continue;
            }
            $kept[$name] = $item;
        }
        ksort($kept);
        return $kept;
    }
}
