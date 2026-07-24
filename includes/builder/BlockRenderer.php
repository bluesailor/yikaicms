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
    /** 响应式三档映射（[基类, md:类, lg:类]，字面量写全供 Tailwind 扫描；解析见 AbstractElement::respClasses） */
    private const PADDING_MAP = [
        'none' => ['py-0', 'md:py-0', 'lg:py-0'],
        'sm'   => ['py-4', 'md:py-4', 'lg:py-4'],
        'md'   => ['py-8', 'md:py-8', 'lg:py-8'],
        'lg'   => ['py-12', 'md:py-12', 'lg:py-12'],
        'xl'   => ['py-16', 'md:py-16', 'lg:py-16'],
    ];
    private const GAP_MAP = [
        'none' => ['gap-0', 'md:gap-0', 'lg:gap-0'],
        'sm'   => ['gap-2', 'md:gap-2', 'lg:gap-2'],
        'md'   => ['gap-4', 'md:gap-4', 'lg:gap-4'],
        'lg'   => ['gap-8', 'md:gap-8', 'lg:gap-8'],
        'xl'   => ['gap-12', 'md:gap-12', 'lg:gap-12'],
    ];
    private const MAXWIDTH_MAP = ['default' => 'max-w-6xl', 'narrow' => 'max-w-4xl', 'wide' => 'max-w-7xl', 'full' => 'max-w-full'];
    private const ALIGN_ITEMS_MAP = ['start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end'];
    private const JUSTIFY_ITEMS_MAP = ['start' => 'justify-items-start', 'center' => 'justify-items-center', 'end' => 'justify-items-end'];

    /**
     * 前台就地编辑上下文（P1）：>0 时给每个 section 输出 data-yk-sec 索引，供管理员悬停编辑覆盖层定位。
     * 仅由前台页面渲染在「管理员浏览」时设置（见 page.php）；保存快照/预览/黄金对拍均不设置 → 无标记。
     */
    public static int $editChannelId = 0;

    public static function render(string $blocksJson): string
    {
        $sections = json_decode($blocksJson, true);
        if (!is_array($sections) || empty($sections)) {
            return '';
        }

        // 仅当显式开启编辑上下文且当前是登录管理员时，才输出定位标记（不污染公开 HTML/缓存）
        $editMode = self::$editChannelId > 0 && !empty($_SESSION['admin_id']);

        $html = '';
        foreach ($sections as $secIndex => $section) {
            // 可复用块引用：{library_id: N} → 渲染时从块库展开（改库一处全站生效）。
            // 库块被删/表缺失 → 静默跳过；展开结果里再出现 library_id 一律忽略（防嵌套循环）。
            if (!empty($section['library_id'])) {
                $lib = BlocksLibrary::get((int) $section['library_id']);
                if ($lib === null) {
                    continue;
                }
                unset($lib['library_id']);
                $section = $lib;
            }
            $settings = $section['settings'] ?? [];
            $padding = AbstractElement::respClasses($settings['padding'] ?? 'md', self::PADDING_MAP, 'md');
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

            $gap = AbstractElement::respClasses($settings['gap'] ?? 'lg', self::GAP_MAP, 'lg');
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

            $editAttr = $editMode ? ' data-yk-sec="' . (int) $secIndex . '"' : '';
            $html .= '<section class="' . $padding . '"' . $styleAttr . $editAttr . '>';
            $html .= '<div class="' . $maxWidth . ' mx-auto px-4">';
            // section 级标题（可选）：有 title 才渲染 —— 让"总标题 + 多列"在同一 section 内完成，
            // 无 title 的 section 输出与旧版完全一致（黄金对拍不变）。
            $secTitle = trim((string) ($settings['title'] ?? ''));
            if ($secTitle !== '') {
                $secSub = trim((string) ($settings['subtitle'] ?? ''));
                $html .= '<div class="text-center mb-10">';
                $html .= '<h2 class="blk-title">' . htmlspecialchars($secTitle) . '</h2>';
                $html .= '<span class="section-title-bar"></span>';
                if ($secSub !== '') {
                    $html .= '<p class="blk-sub">' . htmlspecialchars($secSub) . '</p>';
                }
                $html .= '</div>';
            }
            if ($gridClass) {
                $html .= '<div class="' . $gridClass . '">';
            }

            $colCard = $colCount > 1 && !empty($settings['col_card']);
            foreach ($columns as $col) {
                if ($colCount > 1) {
                    if ($colCard) {
                        // 列级背景色（card_bg）：用于高亮某一列（如价格表「推荐」档），有则加重阴影
                        $cbg = isset($col['card_bg']) && (string) $col['card_bg'] !== '' ? (string) $col['card_bg'] : '';
                        $html .= $cbg !== ''
                            ? '<div class="rounded-xl border border-gray-100 shadow-md p-6 h-full text-center flex flex-col yk-col-card" style="background:' . htmlspecialchars($cbg, ENT_QUOTES) . '">'
                            : '<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-6 h-full text-center flex flex-col yk-col-card">';
                    } else {
                        $html .= '<div>';
                    }
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
