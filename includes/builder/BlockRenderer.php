<?php
/**
 * YikaiCMS 页面构建器 —— 递归渲染器。
 *
 * 解析 blocks_data（section → column → element），段/列包裹逐字节对齐旧 renderBlocksToHtml，
 * 元素渲染派发到 BuilderRegistry 里的元素类。未知 type 静默跳过（与旧 switch default 一致）。
 *
 * 迁移锚点：BlockRenderer::render(json) 必须与 renderBlocksToHtml(json) 输出完全一致（黄金对拍）。
 */

declare(strict_types=1);

final class BlockRenderer
{
    private const PADDING_MAP = ['none' => 'py-0', 'sm' => 'py-4', 'md' => 'py-8', 'lg' => 'py-12', 'xl' => 'py-16'];
    private const MAXWIDTH_MAP = ['default' => 'max-w-6xl', 'narrow' => 'max-w-4xl', 'wide' => 'max-w-7xl', 'full' => 'max-w-full'];
    private const GAP_MAP = ['none' => 'gap-0', 'sm' => 'gap-2', 'md' => 'gap-4', 'lg' => 'gap-8', 'xl' => 'gap-12'];
    private const ALIGN_ITEMS_MAP = ['start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end'];
    private const JUSTIFY_ITEMS_MAP = ['start' => 'justify-items-start', 'center' => 'justify-items-center', 'end' => 'justify-items-end'];

    public static function render(string $blocksJson): string
    {
        $sections = json_decode($blocksJson, true);
        if (!is_array($sections) || empty($sections)) {
            return '';
        }

        $html = '';
        foreach ($sections as $section) {
            $settings = $section['settings'] ?? [];
            $padding = self::PADDING_MAP[$settings['padding'] ?? 'md'] ?? 'py-8';
            $maxWidth = self::MAXWIDTH_MAP[$settings['max_width'] ?? 'default'] ?? 'max-w-6xl';

            $style = '';
            if (!empty($settings['bg_color'])) {
                $bgColor = htmlspecialchars($settings['bg_color']);
                $bgOpacity = isset($settings['bg_opacity']) ? (int) $settings['bg_opacity'] : 100;
                if ($bgOpacity < 100 && preg_match('/^#([0-9a-fA-F]{6})$/', $bgColor, $m)) {
                    $r = hexdec(substr($m[1], 0, 2));
                    $g = hexdec(substr($m[1], 2, 2));
                    $b = hexdec(substr($m[1], 4, 2));
                    $a = round($bgOpacity / 100, 2);
                    $style .= 'background-color:rgba(' . $r . ',' . $g . ',' . $b . ',' . $a . ');';
                } else {
                    $style .= 'background-color:' . $bgColor . ';';
                }
            }
            if (!empty($settings['bg_image'])) {
                $style .= 'background-image:url(' . htmlspecialchars($settings['bg_image']) . ');background-size:cover;background-position:center;';
            }
            $styleAttr = $style ? ' style="' . $style . '"' : '';

            $columns = $section['columns'] ?? [];
            $colCount = count($columns);
            if ($colCount < 1) {
                continue;
            }

            $gap = self::GAP_MAP[$settings['gap'] ?? 'lg'] ?? 'gap-8';
            $gridClass = '';
            if ($colCount > 1) {
                $gridClass = 'grid grid-cols-1 md:grid-cols-' . $colCount . ' ' . $gap;
                if (!empty(self::ALIGN_ITEMS_MAP[$settings['align_items'] ?? ''])) {
                    $gridClass .= ' ' . self::ALIGN_ITEMS_MAP[$settings['align_items']];
                }
                if (!empty(self::JUSTIFY_ITEMS_MAP[$settings['justify_items'] ?? ''])) {
                    $gridClass .= ' ' . self::JUSTIFY_ITEMS_MAP[$settings['justify_items']];
                }
            }

            $html .= '<section class="' . $padding . '"' . $styleAttr . '>';
            $html .= '<div class="' . $maxWidth . ' mx-auto px-4">';
            if ($gridClass) {
                $html .= '<div class="' . $gridClass . '">';
            }

            $colCard = $colCount > 1 && !empty($settings['col_card']);
            foreach ($columns as $col) {
                if ($colCount > 1) {
                    $html .= $colCard
                        ? '<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 h-full text-center">'
                        : '<div>';
                }
                foreach ($col['elements'] ?? [] as $el) {
                    $type = $el['type'] ?? '';
                    $element = BuilderRegistry::get($type);
                    if ($element !== null) {
                        $html .= $element->render($el['data'] ?? []);
                    }
                    // 未注册 type：静默跳过（与旧 switch default 行为一致）
                }
                if ($colCount > 1) {
                    $html .= '</div>';
                }
            }

            if ($gridClass) {
                $html .= '</div>';
            }
            $html .= '</div></section>';
        }

        return $html;
    }
}
