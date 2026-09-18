<?php
/**
 * 产品目录排版设置解析（纯函数，无副作用、不查询数据）。
 *
 * 复合元素 product-catalog 的“可组合排版模式”由这里统一归一化：
 * 结构树区域、编辑器控件、前台渲染三者都读同一份解析结果，避免各写一套判断。
 *
 * 兼容底线：旧文档没有 layout_mode 等新字段时 resolveMode() 返回 null，
 * 由 ProductCatalogElement 走旧的 sidebar.php 路径，输出与历史逐项一致。
 */

declare(strict_types=1);

final class ProductCatalogLayout
{
    /** 五种排版骨架；值为新枚举，不复用旧的 sidebar/grid。 */
    public const MODES = ['sidebar_grid', 'top_grid', 'toolbar_grid', 'media_list', 'featured'];

    /** 卡片样式：通用卡片 / 左图右文 / 大图重点。 */
    public const CARDS = ['card', 'media', 'featured'];

    /** 触发新渲染路径的字段：任一存在即视为“已使用新模式”。 */
    public const MODERN_KEYS = [
        'layout_mode', 'card_style', 'image_ratio', 'card_gap', 'content_align',
        'sidebar_width', 'nav_position', 'show_image', 'show_title', 'show_summary',
        'show_button', 'show_count', 'show_pagination', 'empty_text', 'button_text',
    ];

    /**
     * 显式选择的排版模式；未使用新模式返回 null（调用方据此走旧路径）。
     *
     * @param array<string,mixed> $data
     */
    public static function resolveMode(array $data): ?string
    {
        $mode = is_string($data['layout_mode'] ?? null) ? trim($data['layout_mode']) : '';
        if ($mode !== '' && in_array($mode, self::MODES, true)) {
            return $mode;
        }
        foreach (self::MODERN_KEYS as $key) {
            if ($key === 'layout_mode') {
                continue;
            }
            if (array_key_exists($key, $data)) {
                return self::legacyMode($data);
            }
        }
        return null;
    }

    /**
     * 由旧字段推导的等价模式：旧 grid（无分类）→ toolbar_grid，旧 sidebar → sidebar_grid。
     * 仅在用户已经动过新字段、必须走新路径时使用。
     *
     * @param array<string,mixed> $data
     */
    private static function legacyMode(array $data): string
    {
        $layout = is_string($data['layout'] ?? null) ? $data['layout'] : 'inherit';
        if ($layout === 'inherit') {
            $layout = (string) config('product_layout', 'sidebar') === 'top' ? 'grid' : 'sidebar';
        }
        return $layout === 'grid' ? 'toolbar_grid' : 'sidebar_grid';
    }

    /**
     * 归一化全部排版设置。布尔项默认开启，与旧元素“缺省即显示”的行为一致。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function settings(array $data, string $mode): array
    {
        $columns = (int) ($data['columns'] ?? 4);
        if (!in_array($columns, [2, 3, 4], true)) {
            $columns = 4;
        }

        $card = is_string($data['card_style'] ?? null) ? $data['card_style'] : '';
        if ($mode === 'media_list') {
            $card = 'media';
        } elseif ($mode === 'featured') {
            $card = 'featured';
        } elseif (!in_array($card, self::CARDS, true)) {
            $card = 'card';
        }

        return [
            'mode' => $mode,
            'columns' => $columns,
            'card' => $card,
            'nav' => self::navPosition($data, $mode),
            'show_search' => self::flag($data, 'show_search'),
            'show_categories' => self::flag($data, 'show_categories'),
            'show_sort' => self::flag($data, 'show_sort'),
            'show_count' => self::flag($data, 'show_count'),
            'show_pagination' => self::flag($data, 'show_pagination'),
            'show_image' => self::flag($data, 'show_image'),
            'show_title' => self::flag($data, 'show_title'),
            'show_summary' => self::flag($data, 'show_summary'),
            'show_button' => self::flag($data, 'show_button'),
            'image_ratio' => self::enum($data, 'image_ratio', ['square', 'landscape', 'portrait'], 'landscape'),
            'gap' => self::enum($data, 'card_gap', ['sm', 'md', 'lg'], 'md'),
            'align' => self::enum($data, 'content_align', ['left', 'center'], 'left'),
            'sidebar_width' => self::enum($data, 'sidebar_width', ['sm', 'md', 'lg'], 'md'),
            'empty_text' => self::text($data, 'empty_text', __('no_content')),
            'button_text' => self::text($data, 'button_text', __('read_more')),
        ];
    }

    /** 分类导航位置：显式 nav_position 优先，否则跟随排版模式。 */
    private static function navPosition(array $data, string $mode): string
    {
        $explicit = is_string($data['nav_position'] ?? null) ? $data['nav_position'] : '';
        if (in_array($explicit, ['sidebar', 'top', 'toolbar', 'hidden'], true)) {
            return $explicit;
        }
        return match ($mode) {
            'sidebar_grid', 'media_list' => 'sidebar',
            'top_grid', 'featured' => 'top',
            'toolbar_grid' => 'toolbar',
            default => 'sidebar',
        };
    }

    /** 缺省即显示：只有显式设为假值才隐藏。 @param array<string,mixed> $data */
    private static function flag(array $data, string $key): bool
    {
        return !array_key_exists($key, $data) || !empty($data[$key]);
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $allowed
     */
    private static function enum(array $data, string $key, array $allowed, string $default): string
    {
        $value = is_string($data[$key] ?? null) ? $data[$key] : '';
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** @param array<string,mixed> $data */
    private static function text(array $data, string $key, string $default): string
    {
        $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
        return $value !== '' ? $value : $default;
    }

    /**
     * 产品网格响应式类。字面量写死供 Tailwind 扫描（§10）。
     * 移动端固定单列（§二 移动端降级），平板两列，桌面按列数。
     */
    public static function gridClasses(int $columns): string
    {
        return [
            2 => 'grid grid-cols-1 md:grid-cols-2',
            3 => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
            4 => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4',
        ][$columns] ?? 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4';
    }

    /** 卡片间距。 */
    public static function gapClass(string $gap): string
    {
        return ['sm' => 'gap-4', 'md' => 'gap-6', 'lg' => 'gap-8'][$gap] ?? 'gap-6';
    }

    /** 侧栏宽度（仅侧栏骨架使用）。 */
    public static function sidebarWidthClass(string $width): string
    {
        return [
            'sm' => 'lg:w-48',
            'md' => 'lg:w-64',
            'lg' => 'lg:w-80',
        ][$width] ?? 'lg:w-64';
    }

    /** 图片比例 → Tailwind aspect 类。 */
    public static function imageRatioClass(string $ratio): string
    {
        return [
            'square' => 'aspect-square',
            'landscape' => 'aspect-[4/3]',
            'portrait' => 'aspect-[3/4]',
        ][$ratio] ?? 'aspect-[4/3]';
    }
}
