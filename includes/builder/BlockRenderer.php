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

require_once __DIR__ . '/../HtmlTagRewriter.php';

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
    // 容器层（内容层）独立样式：区块=全宽背景层、内层 div=容器（Bricks 的 Section/Container 分层）
    private const CONTAINER_PAD_MAP = ['sm' => 'p-4', 'md' => 'p-6', 'lg' => 'p-10'];
    private const CONTAINER_RADIUS_MAP = ['md' => 'rounded-xl', 'xl' => 'rounded-3xl'];
    private const ALIGN_ITEMS_MAP = ['start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end'];
    private const JUSTIFY_ITEMS_MAP = ['start' => 'justify-items-start', 'center' => 'justify-items-center', 'end' => 'justify-items-end'];
    private const GRIDCOL_MAP = [
        2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3', 4 => 'md:grid-cols-4',
        5 => 'md:grid-cols-5', 6 => 'md:grid-cols-6', 12 => 'md:grid-cols-12',
    ];
    private const GRIDCOL_DESKTOP_MAP = [
        2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-4',
        5 => 'lg:grid-cols-5', 6 => 'lg:grid-cols-6', 12 => 'lg:grid-cols-12',
    ];
    private const COLSPAN_MAP = [
        1 => 'md:col-span-1', 2 => 'md:col-span-2', 3 => 'md:col-span-3', 4 => 'md:col-span-4',
        5 => 'md:col-span-5', 6 => 'md:col-span-6', 7 => 'md:col-span-7', 8 => 'md:col-span-8',
        9 => 'md:col-span-9', 10 => 'md:col-span-10', 11 => 'md:col-span-11', 12 => 'md:col-span-12',
    ];
    private const COLSPAN_DESKTOP_MAP = [
        1 => 'lg:col-span-1', 2 => 'lg:col-span-2', 3 => 'lg:col-span-3', 4 => 'lg:col-span-4',
        5 => 'lg:col-span-5', 6 => 'lg:col-span-6', 7 => 'lg:col-span-7', 8 => 'lg:col-span-8',
        9 => 'lg:col-span-9', 10 => 'lg:col-span-10', 11 => 'lg:col-span-11', 12 => 'lg:col-span-12',
    ];
    private const SECTION_ALIGN_MAP = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'];
    private const SECTION_TITLE_SIZE_MAP = ['sm' => '1.5rem', 'md' => '1.875rem', 'lg' => '2.25rem', 'xl' => '3rem'];
    private const SECTION_SUBTITLE_SIZE_MAP = ['sm' => '0.875rem', 'md' => '1rem', 'lg' => '1.25rem'];

    /**
     * 前台就地编辑上下文（P1）：>0 时给每个 section 输出 data-yk-sec 索引，供管理员悬停编辑覆盖层定位。
     * 仅由前台页面渲染在「管理员浏览」时设置（见 page.php）；保存快照/预览/黄金对拍均不设置 → 无标记。
     */
    public static int $editChannelId = 0;

    /** 编辑器画布/预览专用：为 true 时隐藏的区块也渲染（前台永远不渲染，含登录管理员）。 */
    public static bool $showHidden = false;

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

            // 隐藏区块：前台不输出，后台编辑器里仍可见可恢复（比删掉再重建友好）。
            // 可选键，缺省即显示——老数据没有此键，渲染结果不变。
            // 编辑态（后台预览/画布）照常渲染，否则隐藏的区块在编辑器里就成了空白。
            if (!empty($settings['hidden']) && !self::$showHidden) {
                continue;
            }

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
            // 渐变背景：白名单校验后才进 style（值会拼进 style 属性，不能放行任意 CSS）。
            // 与背景图共存时渐变叠在图上（半透明渐变即成遮罩）；bg_gradient 为空时
            // 走原分支，输出与旧版逐字节一致（黄金对拍不破）。
            $bgGrad = (string) ($settings['bg_gradient'] ?? '');
            if ($bgGrad !== '' && !preg_match('/^(linear|radial)-gradient\([a-zA-Z0-9#%.,()\s-]+\)$/', $bgGrad)) {
                $bgGrad = '';
            }
            if ($bgGrad !== '') {
                if (!empty($settings['bg_image'])) {
                    $style .= 'background-image:' . $bgGrad . ',url(' . htmlspecialchars($settings['bg_image']) . ');background-size:cover;background-position:center;';
                } else {
                    $style .= 'background-image:' . $bgGrad . ';';
                }
            } elseif (!empty($settings['bg_image'])) {
                $style .= 'background-image:url(' . htmlspecialchars($settings['bg_image']) . ');background-size:cover;background-position:center;';
            }
            $styleAttr = $style ? ' style="' . $style . '"' : '';

            $columns = $section['columns'] ?? [];
            $colCount = count($columns);
            if ($colCount < 1) {
                continue;
            }
            $hasCustomSpans = false;
            $spanTotal = 0;
            foreach ($columns as $col) {
                if (is_array($col) && isset($col['span'])) {
                    $hasCustomSpans = true;
                    $spanTotal += max(0, (int) $col['span']);
                }
            }
            $useCustomSpans = $hasCustomSpans && $spanTotal > 0 && $spanTotal <= 12;

            $gap = AbstractElement::respClasses($settings['gap'] ?? 'lg', self::GAP_MAP, 'lg');
            $gridClass = '';
            if ($colCount > 1) {
                $gridMap = !empty($settings['tablet_stack']) ? self::GRIDCOL_DESKTOP_MAP : self::GRIDCOL_MAP;
                $gridClass = 'grid grid-cols-1 ' . ($useCustomSpans ? $gridMap[12] : ($gridMap[$colCount] ?? $gridMap[12])) . ' ' . $gap;
                if (!empty(self::ALIGN_ITEMS_MAP[$settings['align_items'] ?? ''])) {
                    $gridClass .= ' ' . self::ALIGN_ITEMS_MAP[$settings['align_items']];
                }
                if (!empty(self::JUSTIFY_ITEMS_MAP[$settings['justify_items'] ?? ''])) {
                    $gridClass .= ' ' . self::JUSTIFY_ITEMS_MAP[$settings['justify_items']];
                }
            }

            $editAttr = $editMode ? ' data-yk-sec="' . (int) $secIndex . '"' : '';
            $html .= '<section class="' . $padding . '"' . $styleAttr . $editAttr . '>';

            // ── 容器层：宽度自定义 px + 独立背景/内边距/圆角。全部是新增可选键，
            //    一个不设时输出仍为 <div class="max-w-* mx-auto px-4">（黄金对拍不破）──
            $containerGutter = ($settings['container_gutter'] ?? 'default') === 'none' ? '' : ' px-4';
            $innerCls = $maxWidth . ' mx-auto' . $containerGutter;
            $innerStyle = '';
            if (($settings['max_width'] ?? '') === 'custom') {
                $px = (int) ($settings['max_width_px'] ?? 0);
                if ($px >= 320 && $px <= 3840) {
                    $innerCls = 'mx-auto' . $containerGutter;
                    $innerStyle .= 'max-width:' . $px . 'px;';
                }
            }
            if (!empty(self::CONTAINER_PAD_MAP[$settings['container_padding'] ?? ''])) {
                $innerCls .= ' ' . self::CONTAINER_PAD_MAP[$settings['container_padding']];
            }
            if (!empty(self::CONTAINER_RADIUS_MAP[$settings['container_radius'] ?? ''])) {
                $innerCls .= ' ' . self::CONTAINER_RADIUS_MAP[$settings['container_radius']];
            }
            if (!empty($settings['container_bg'])) {
                $innerStyle .= 'background-color:' . htmlspecialchars((string) $settings['container_bg'], ENT_QUOTES) . ';';
            }
            $containerEditAttr = $editMode ? ' data-yk-con="' . (int) $secIndex . '"' : '';
            $html .= '<div class="' . $innerCls . '"' . $containerEditAttr . ($innerStyle !== '' ? ' style="' . $innerStyle . '"' : '') . '>';
            // section 级标题（可选）：有 title 才渲染 —— 让"总标题 + 多列"在同一 section 内完成，
            // 无 title 的 section 输出与旧版完全一致（黄金对拍不变）。
            $secTitle = trim((string) ($settings['title'] ?? ''));
            if ($secTitle !== '') {
                $secSub = trim((string) ($settings['subtitle'] ?? ''));
                $titleAlign = self::SECTION_ALIGN_MAP[$settings['title_align'] ?? 'center'] ?? self::SECTION_ALIGN_MAP['center'];
                $titleTagValue = (string) ($settings['title_tag'] ?? 'h2');
                $titleTag = in_array($titleTagValue, ['h2', 'h3', 'h4'], true) ? $titleTagValue : 'h2';
                $titleStyle = self::sectionFieldStyle(
                    $settings['title_size'] ?? '',
                    $settings['title_color'] ?? '',
                    self::SECTION_TITLE_SIZE_MAP
                );
                $subtitleStyle = self::sectionFieldStyle(
                    $settings['subtitle_size'] ?? '',
                    $settings['subtitle_color'] ?? '',
                    self::SECTION_SUBTITLE_SIZE_MAP
                );
                $html .= '<div class="' . $titleAlign . ' mb-10">';
                $titleEditAttr = $editMode ? ' data-yk-sec-field="' . (int) $secIndex . '.title"' : '';
                $subEditAttr = $editMode ? ' data-yk-sec-field="' . (int) $secIndex . '.subtitle"' : '';
                $html .= '<' . $titleTag . ' class="blk-title"' . $titleEditAttr . $titleStyle . '>' . htmlspecialchars($secTitle) . '</' . $titleTag . '>';
                $html .= '<span class="section-title-bar"></span>';
                if ($secSub !== '') {
                    $html .= '<p class="blk-sub"' . $subEditAttr . $subtitleStyle . '>' . htmlspecialchars($secSub) . '</p>';
                }
                $html .= '</div>';
            }
            if ($gridClass) {
                $html .= '<div class="' . $gridClass . '">';
            }

            $colCard = $colCount > 1 && !empty($settings['col_card']);
            foreach ($columns as $ci => $col) {
                if ($colCount > 1) {
                    $spanClass = $useCustomSpans && is_array($col)
                        ? self::colSpanClass($col['span'] ?? 0, !empty($settings['tablet_stack']))
                        : '';
                    $editSpan = $useCustomSpans
                        ? max(1, (int) ($col['span'] ?? 1))
                        : intdiv(12, $colCount) + ($ci < (12 % $colCount) ? 1 : 0);
                    $colEditAttr = $editMode
                        ? ' data-yk-col="' . (int) $secIndex . '.' . (int) $ci . '" data-yk-col-span="' . $editSpan . '"'
                        : '';
                    if ($colCard) {
                        // Column card background highlights a specific column without affecting other columns.
                        $cbg = isset($col['card_bg']) && (string) $col['card_bg'] !== '' ? (string) $col['card_bg'] : '';
                        $html .= $cbg !== ''
                            ? '<div class="' . trim($spanClass . ' rounded-xl border border-gray-100 shadow-md p-6 h-full text-center flex flex-col yk-col-card') . '"' . $colEditAttr . ' style="background:' . htmlspecialchars($cbg, ENT_QUOTES) . '">'
                            : '<div class="' . trim($spanClass . ' bg-white rounded-xl border border-gray-100 shadow-sm p-6 h-full text-center flex flex-col yk-col-card') . '"' . $colEditAttr . '>';
                    } else {
                        $html .= '<div' . ($spanClass !== '' ? ' class="' . $spanClass . '"' : '') . $colEditAttr . '>';
                    }
                }
                foreach (($col['elements'] ?? []) as $ei => $el) {
                    if (is_array($el)) {
                        $html .= self::renderElement($el, 0, $editMode, [$secIndex, (int) $ci, (int) $ei]);
                    }
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

    /**
     * 标题字段只接受预设字号和十六进制颜色，避免任意设置值进入 style 属性。
     */
    private static function sectionFieldStyle(mixed $size, mixed $color, array $sizeMap): string
    {
        $style = '';
        $sizeKey = is_string($size) ? $size : '';
        if ($sizeKey !== '' && isset($sizeMap[$sizeKey])) {
            $style .= 'font-size:' . $sizeMap[$sizeKey] . ';';
        }
        $colorValue = is_string($color) ? trim($color) : '';
        if ($colorValue !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $colorValue)) {
            $style .= 'color:' . $colorValue . ';';
        }
        return $style !== '' ? ' style="' . $style . '"' : '';
    }

    /**
     * 渲染单个元素。容器元素（isContainer）先递归渲染 data.children 传入 $children；
     * 普通元素不看 children 键，输出与抽取前逐字节一致（黄金对拍不破）。
     * 深度上限 3 防坏数据画圈（编辑器只允许一层，这里是兜底不是约束）。
     * 未注册 type 静默跳过（与旧 switch default 行为一致）。
     */
    private static function colSpanClass(mixed $span, bool $desktopOnly = false): string
    {
        $span = (int) $span;
        if ($span < 1 || $span > 12) {
            return '';
        }
        $map = $desktopOnly ? self::COLSPAN_DESKTOP_MAP : self::COLSPAN_MAP;
        return $map[$span] ?? '';
    }

    private static function applyElementBoxStyle(string $html, array $data, string $type): string
    {
        $boxStyle = AbstractElement::boxStyle($data);
        if ($html === '' || $boxStyle === '' || $type === 'code') {
            return $html;
        }

        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $existingStyle = $processor->getAttribute('style');
        $style = is_string($existingStyle) ? trim($existingStyle) : '';
        if ($style !== '' && !str_ends_with($style, ';')) {
            $style .= ';';
        }
        $processor->setAttribute('style', $style . $boxStyle);
        return $processor->getUpdatedHtml();
    }

    public static function renderElementNode(array $el, int $depth = 0, bool $editMode = false, array $path = []): string
    {
        return self::renderElement($el, $depth, $editMode, $path);
    }

    private static function renderElement(array $el, int $depth = 0, bool $editMode = false, array $path = []): string
    {
        $type = trim((string) ($el['type'] ?? ''));
        $element = BuilderRegistry::get($type);
        if ($element === null) {
            $missing = BloxPluginRegistry::declaration($type);
            if (!$editMode || $missing === null) {
                return '';
            }
            $pathAttr = htmlspecialchars(implode('.', array_map('strval', $path)), ENT_QUOTES);
            $typeAttr = htmlspecialchars($type, ENT_QUOTES);
            $label = htmlspecialchars($missing['label'], ENT_QUOTES);
            return '<div class="yk-edit-el yk-missing-element" data-yk-el="' . $pathAttr
                . '" data-yk-el-type="' . $typeAttr . '"><div class="border-2 border-dashed border-amber-300'
                . ' bg-amber-50 px-4 py-5 text-center text-sm text-amber-800">'
                . '<strong>' . $label . '</strong><br>所需插件未启用，节点数据已保留</div></div>';
        }
        $data = is_array($el['data'] ?? null) ? $el['data'] : [];
        BloxAssetCollector::collectElement($element, $data);

        $children = '';
        if ($element->isContainer() && !$element->rendersOwnChildren() && $depth < 3) {
            foreach ((array) ($el['data']['children'] ?? []) as $childIndex => $child) {
                if (is_array($child)) {
                    $childPath = $path;
                    $childPath[] = (int) $childIndex;
                    $children .= self::renderElement($child, $depth + 1, $editMode, $childPath);
                }
            }
        }
        $html = $element->renderWithContext($data, $children, [
            'edit_mode' => $editMode,
            'path' => $path,
            'depth' => $depth,
        ]);
        $html = self::applyElementBoxStyle($html, $data, $element->type());
        if (!$editMode || $path === []) {
            return $html;
        }
        $storedPath = (string) ($data['_blox_path'] ?? '');
        $effectivePath = preg_match('/^\d+(?:\.\d+){2,3}$/', $storedPath) === 1
            ? $storedPath
            : implode('.', array_map('strval', $path));
        $pathAttr = htmlspecialchars($effectivePath, ENT_QUOTES);
        $typeAttr = htmlspecialchars($element->type(), ENT_QUOTES);
        return '<div class="yk-edit-el" data-yk-el="' . $pathAttr . '" data-yk-el-type="' . $typeAttr
            . '" style="display:contents">' . $html . '</div>';
    }
}
