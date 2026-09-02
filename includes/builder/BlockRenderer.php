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
    private const SECTION_LABEL_DECORATIVE_TYPES = [
        'heading', 'text', 'button', 'image', 'icon', 'code', 'divider', 'spacer', 'container', 'div',
    ];
    private const SECTION_LABEL_ELEMENT_TITLE_KEYS = ['title', 'name', 'label'];
    private const SECTION_LABEL_MAX = 120;

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
    /** 断点隐藏类（前台输出；编辑态改打 data-yk-hide-on 标记以便画布仍可选中）。类名字面量供 Tailwind 扫描。 */
    private const HIDE_ON_MAP = ['m' => 'max-md:hidden', 't' => 'md:max-lg:hidden', 'd' => 'lg:hidden'];
    private const SECTION_ALIGN_MAP = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'];
    private const SECTION_TITLE_SIZE_MAP = ['sm' => '1.5rem', 'md' => '1.875rem', 'lg' => '2.25rem', 'xl' => '3rem'];
    private const SECTION_SUBTITLE_SIZE_MAP = ['sm' => '0.875rem', 'md' => '1rem', 'lg' => '1.25rem'];
    private const BG_POSITION_MAP = [
        'top-left' => 'left top', 'top' => 'center top', 'top-right' => 'right top',
        'left' => 'left center', 'center' => 'center', 'right' => 'right center',
        'bottom-left' => 'left bottom', 'bottom' => 'center bottom', 'bottom-right' => 'right bottom',
    ];
    private const SECTION_MIN_HEIGHT_MAP = [
        'sm' => '320px', 'md' => '480px', 'lg' => '640px', 'screen' => '100vh',
    ];
    private const SECTION_V_ALIGN_MAP = [
        'start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end',
    ];

    /**
     * 编辑定位上下文：>0 时输出编辑器内部索引与持久 section id。
     * 前台正文必须通过 renderFrontEditableContentBody() 短暂开启，避免页头/页尾误绑定到正文编辑器。
     */
    public static int $editChannelId = 0;

    /** 编辑器画布/预览专用：为 true 时隐藏的区块也渲染（前台永远不渲染，含登录管理员）。 */
    public static bool $showHidden = false;

    /**
     * 嵌套自定义首页区块的画布字段上下文。它输出 home-field 标记而非内部 Blox 坐标，
     * 防止内部 section 0 被父编辑器误认为首页 section 0。
     * @var array{path:string,type:string,locale:string}|null
     */
    public static ?array $homeFieldEditContext = null;

    public static function render(string $blocksJson): string
    {
        try {
            $document = BloxDocumentPipeline::decode($blocksJson);
        } catch (RuntimeException $e) {
            error_log('[BlockRenderer] Rejected Blox document: ' . $e->getMessage());
            return '';
        }
        // 渲染端不做元素注册表校验，允许停用插件元素走既有缺失态；但信封版本
        // 必须先经统一迁移门，避免未来版本被旧渲染器误读。
        $sections = $document['sections'];
        if (empty($sections)) {
            return '';
        }

        // 仅当显式开启编辑上下文且当前是登录管理员时，才输出定位标记（不污染公开 HTML/缓存）
        $editMode = self::$editChannelId > 0 && !empty($_SESSION['admin_id']);

        $html = '';
        $renderedAnchors = [];
        foreach ($sections as $secIndex => $section) {
            $sourceSection = $section;
            // 定位协议绑定文档中的引用节点，而不是展开后的块库副本。
            $sectionLocatorId = trim((string) ($section['id'] ?? ''));
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
            $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];

            // 隐藏区块：前台不输出，后台编辑器里仍可见可恢复（比删掉再重建友好）。
            // 可选键，缺省即显示——老数据没有此键，渲染结果不变。
            // 编辑态（后台预览/画布）照常渲染，否则隐藏的区块在编辑器里就成了空白。
            if (!empty($settings['hidden']) && !self::$showHidden) {
                continue;
            }

            $sectionConditions = $settings['_conditions'] ?? null;
            if (BloxDisplayConditions::hasInput($sectionConditions)
                && !self::$showHidden
                && !BloxDisplayConditions::matches($sectionConditions)) {
                continue;
            }

            $anchorId = BloxDocumentPipeline::normalizeSectionAnchorId($settings['anchor_id'] ?? '');
            $anchorKey = strtolower($anchorId);
            if ($anchorId !== '' && isset($renderedAnchors[$anchorKey])) {
                $anchorId = '';
            } elseif ($anchorId !== '') {
                $renderedAnchors[$anchorKey] = true;
            }

            $padding = AbstractElement::respClasses($settings['padding'] ?? 'md', self::PADDING_MAP, 'md');
            $maxWidth = self::MAXWIDTH_MAP[$settings['max_width'] ?? 'default'] ?? 'max-w-6xl';

            $style = '';
            $bgColor = AbstractElement::cssColor($settings['bg_color'] ?? null);
            $bgImage = AbstractElement::cssImageUrl($settings['bg_image'] ?? null);
            if ($bgColor !== null) {
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
                if ($bgImage !== null) {
                    $bgPosition = self::BG_POSITION_MAP[$settings['bg_position'] ?? 'center'] ?? self::BG_POSITION_MAP['center'];
                    $style .= 'background-image:' . $bgGrad . ',' . AbstractElement::cssUrlLiteral($bgImage)
                        . ';background-size:cover;background-position:' . $bgPosition . ';';
                } else {
                    $style .= 'background-image:' . $bgGrad . ';';
                }
            } elseif ($bgImage !== null) {
                $bgPosition = self::BG_POSITION_MAP[$settings['bg_position'] ?? 'center'] ?? self::BG_POSITION_MAP['center'];
                $style .= 'background-image:' . AbstractElement::cssUrlLiteral($bgImage)
                    . ';background-size:cover;background-position:' . $bgPosition . ';';
            }

            $minHeight = self::SECTION_MIN_HEIGHT_MAP[$settings['min_height'] ?? ''] ?? '';
            if ($minHeight !== '') {
                $style .= 'min-height:' . $minHeight . ';';
            }

            $overlayColor = AbstractElement::cssColor($settings['bg_overlay_color'] ?? null);
            $overlayOpacity = max(0, min(100, (int) ($settings['bg_overlay_opacity'] ?? 0)));
            // 旧编辑器曾把 bg_opacity 标成“遮罩”，但只把颜色画在图片后方。
            // 对尚未写入新字段的旧文档补回用户原本看到的设置语义。
            if ($bgImage !== null
                && !array_key_exists('bg_overlay_color', $settings)
                && !array_key_exists('bg_overlay_opacity', $settings)
                && $bgColor !== null
                && array_key_exists('bg_opacity', $settings)) {
                $overlayColor = $bgColor;
                $overlayOpacity = max(0, min(100, (int) $settings['bg_opacity']));
            }
            $hasOverlay = $bgImage !== null && $overlayColor !== null && $overlayOpacity > 0;
            $styleAttr = $style ? ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"' : '';

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
                    $spanTotal += max(0, self::spanValue($col['span'], 'd'));
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
            if ($editMode && $sectionLocatorId !== '') {
                $editAttr .= ' data-yk-sec-id="' . htmlspecialchars($sectionLocatorId, ENT_QUOTES) . '"';
            }
            if ($editMode) {
                $sectionLabel = self::sectionEditLabel($sourceSection, $section);
                if ($sectionLabel !== '') {
                    $editAttr .= ' data-yk-sec-label="' . htmlspecialchars($sectionLabel, ENT_QUOTES) . '"';
                }
            }
            if ($editMode && BloxDisplayConditions::hasInput($sectionConditions)) {
                $editAttr .= ' data-yk-conditions="'
                    . htmlspecialchars(BloxDisplayConditions::badge($sectionConditions), ENT_QUOTES) . '"';
            }
            [$secHideCls, $secHideAttr] = self::hideOn($settings['hide_on'] ?? null, $editMode);
            $anchorAttr = $anchorId !== '' ? ' id="' . htmlspecialchars($anchorId, ENT_QUOTES) . '"' : '';
            $anchorClass = $anchorId !== '' ? ' yk-blox-anchor' : '';
            $sectionLayoutClass = '';
            if ($minHeight !== '') {
                $sectionLayoutClass = ' flex '
                    . (self::SECTION_V_ALIGN_MAP[$settings['content_v_align'] ?? 'center'] ?? self::SECTION_V_ALIGN_MAP['center']);
            }
            if ($hasOverlay) {
                $sectionLayoutClass .= ' relative overflow-hidden';
            }
            $html .= '<section class="' . $padding . $sectionLayoutClass . $secHideCls . $anchorClass . '"'
                . $anchorAttr . $styleAttr . $editAttr . $secHideAttr . '>';
            if ($hasOverlay) {
                $overlayStyle = 'background-color:' . $overlayColor . ';opacity:' . round($overlayOpacity / 100, 2) . ';';
                $html .= '<div class="absolute inset-0 pointer-events-none" aria-hidden="true" style="'
                    . htmlspecialchars($overlayStyle, ENT_QUOTES) . '"></div>';
            }

            // ── 容器层：宽度自定义 px + 独立背景/内边距/圆角。全部是新增可选键，
            //    一个不设时输出仍为 <div class="max-w-* mx-auto px-4">（黄金对拍不破）──
            $containerGutter = ($settings['container_gutter'] ?? 'default') === 'none' ? '' : ' px-4';
            $innerCls = $maxWidth . ' mx-auto' . $containerGutter;
            if ($minHeight !== '') {
                $innerCls .= ' w-full';
            }
            if ($hasOverlay) {
                $innerCls .= ' relative z-10';
            }
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
            $containerBg = AbstractElement::cssColor($settings['container_bg'] ?? null);
            if ($containerBg !== null) {
                $innerStyle .= 'background-color:' . $containerBg . ';';
            }
            $containerBgImage = AbstractElement::cssImageUrl($settings['container_bg_image'] ?? null);
            if ($containerBgImage !== null) {
                $innerStyle .= 'background-image:' . AbstractElement::cssUrlLiteral($containerBgImage)
                    . ';background-size:cover;background-position:center;background-repeat:no-repeat;';
            }
            $containerOverlayColor = AbstractElement::cssColor($settings['container_bg_overlay_color'] ?? null);
            $containerOverlayOpacity = max(
                0,
                min(100, (int) ($settings['container_bg_overlay_opacity'] ?? 0))
            );
            $hasContainerOverlay = $containerBgImage !== null
                && $containerOverlayColor !== null
                && $containerOverlayOpacity > 0;
            if ($hasContainerOverlay) {
                $innerCls .= ($hasOverlay ? '' : ' relative') . ' overflow-hidden';
            }
            $containerEditAttr = $editMode ? ' data-yk-con="' . (int) $secIndex . '"' : '';
            $html .= '<div class="' . $innerCls . '"' . $containerEditAttr
                . ($innerStyle !== '' ? ' style="' . htmlspecialchars($innerStyle, ENT_QUOTES) . '"' : '') . '>';
            if ($hasContainerOverlay) {
                $containerOverlayStyle = 'background-color:' . $containerOverlayColor . ';opacity:'
                    . round($containerOverlayOpacity / 100, 2) . ';';
                $html .= '<div class="absolute inset-0 pointer-events-none" aria-hidden="true" style="'
                    . htmlspecialchars($containerOverlayStyle, ENT_QUOTES) . '"></div>';
                $html .= '<div class="relative z-10">';
            }
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
                $column = is_array($col) ? $col : [];
                $spanClass = $colCount > 1 && $useCustomSpans
                    ? self::colSpanClass($column['span'] ?? 0, !empty($settings['tablet_stack']))
                    : '';
                $editSpan = $colCount > 1
                    ? ($useCustomSpans
                        ? max(1, self::spanValue($column['span'] ?? 1, 'd'))
                        : intdiv(12, $colCount) + ($ci < (12 % $colCount) ? 1 : 0))
                    : 12;
                $colEditAttr = $editMode
                    ? ' data-yk-col="' . (int) $secIndex . '.' . (int) $ci . '" data-yk-col-span="' . $editSpan . '"'
                    : '';
                $customColumnField = self::customHomeFieldPath([(int) $secIndex, (int) $ci], 'card_bg');
                if ($customColumnField !== null) {
                    $colEditAttr .= self::customHomeFieldAttributes($customColumnField, false);
                }
                [$colHideCls, $colHideAttr] = self::hideOn($column['hide_on'] ?? null, $editMode);
                $spanClass = trim($spanClass . $colHideCls);
                $colEditAttr .= $colHideAttr;

                $columnBg = AbstractElement::cssColor($column['card_bg'] ?? null);
                $columnBgImage = AbstractElement::cssImageUrl($column['card_bg_image'] ?? null);
                $columnOverlayColor = AbstractElement::cssColor($column['card_bg_overlay_color'] ?? null);
                $columnOverlayOpacity = max(
                    0,
                    min(100, (int) ($column['card_bg_overlay_opacity'] ?? 0))
                );
                $hasColumnOverlay = $columnBgImage !== null
                    && $columnOverlayColor !== null
                    && $columnOverlayOpacity > 0;
                $hasColumnVisual = $columnBg !== null || $columnBgImage !== null || $hasColumnOverlay;
                $wrapColumn = $colCount > 1 || $editMode || $hasColumnVisual
                    || $colHideCls !== '' || $colHideAttr !== '';

                if ($wrapColumn) {
                    $columnClass = $spanClass;
                    if ($colCard) {
                        $columnClass = trim($columnClass
                            . ($hasColumnVisual
                                ? ' rounded-xl border border-gray-100 shadow-md p-6 h-full text-center flex flex-col yk-col-card'
                                : ' bg-white rounded-xl border border-gray-100 shadow-sm p-6 h-full text-center flex flex-col yk-col-card'));
                    }
                    if ($hasColumnOverlay) {
                        $columnClass = trim($columnClass . ' relative overflow-hidden');
                    }

                    $columnStyle = '';
                    if ($columnBg !== null) {
                        $columnStyle .= 'background-color:' . $columnBg . ';';
                    }
                    if ($columnBgImage !== null) {
                        $columnStyle .= 'background-image:' . AbstractElement::cssUrlLiteral($columnBgImage)
                            . ';background-size:cover;background-position:center;background-repeat:no-repeat;';
                    }
                    $html .= '<div'
                        . ($columnClass !== '' ? ' class="' . $columnClass . '"' : '')
                        . $colEditAttr
                        . ($columnStyle !== '' ? ' style="' . htmlspecialchars($columnStyle, ENT_QUOTES) . '"' : '')
                        . '>';
                    if ($hasColumnOverlay) {
                        $columnOverlayStyle = 'background-color:' . $columnOverlayColor . ';opacity:'
                            . round($columnOverlayOpacity / 100, 2) . ';';
                        $html .= '<div class="absolute inset-0 pointer-events-none" aria-hidden="true" style="'
                            . htmlspecialchars($columnOverlayStyle, ENT_QUOTES) . '"></div>';
                        $html .= '<div class="relative z-10' . ($colCard ? ' flex h-full flex-col' : '') . '">';
                    }
                }
                foreach (($column['elements'] ?? []) as $ei => $el) {
                    if (is_array($el)) {
                        $html .= self::renderElement($el, 0, $editMode, [$secIndex, (int) $ci, (int) $ei]);
                    }
                }
                if ($wrapColumn) {
                    if ($hasColumnOverlay) {
                        $html .= '</div>';
                    }
                    $html .= '</div>';
                }
            }

            if ($gridClass) {
                $html .= '</div>';
            }
            if ($hasContainerOverlay) {
                $html .= '</div>';
            }
            $html .= '</div></section>';
        }

        return $html;
    }

    /**
     * 编辑器读取同一套标签判定参数，避免前台与结构树随演进产生两套名称。
     *
     * @return array{decorativeTypes:list<string>,elementTitleKeys:list<string>,titleMax:int,labelMax:int}
     * @psalm-suppress PossiblyUnusedMethod 调用方在 admin/blox_editor.php（不在 Psalm projectFiles 内）。
     */
    public static function sectionLabelPolicy(): array
    {
        return [
            'decorativeTypes' => self::SECTION_LABEL_DECORATIVE_TYPES,
            'elementTitleKeys' => self::SECTION_LABEL_ELEMENT_TITLE_KEYS,
            'titleMax' => BloxDocumentPipeline::SECTION_NAME_MAX,
            'labelMax' => self::SECTION_LABEL_MAX,
        ];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $resolved */
    private static function sectionEditLabel(array $source, array $resolved): string
    {
        $elements = self::sectionElements($resolved);
        $semanticElement = null;
        foreach ($elements as $element) {
            $type = trim((string) ($element['type'] ?? ''));
            if ($type !== '' && !in_array($type, self::SECTION_LABEL_DECORATIVE_TYPES, true)) {
                $semanticElement = $element;
                break;
            }
        }

        $typeLabel = '';
        if ($semanticElement !== null) {
            $type = trim((string) ($semanticElement['type'] ?? ''));
            $registered = BuilderRegistry::get($type);
            $declared = $registered === null ? BloxPluginRegistry::declaration($type) : null;
            $typeLabel = self::sectionLabelText($registered?->label() ?? ($declared['label'] ?? ''));
        }

        $sourceSettings = is_array($source['settings'] ?? null) ? $source['settings'] : [];
        $resolvedSettings = is_array($resolved['settings'] ?? null) ? $resolved['settings'] : [];
        $title = array_key_exists('name', $source)
            ? BloxDocumentPipeline::normalizeSectionName($source['name'])
            : '';
        foreach ([
            $sourceSettings['title'] ?? '',
            $resolved['name'] ?? '',
            $resolvedSettings['title'] ?? '',
            $source['library_name'] ?? '',
        ] as $candidate) {
            if ($title !== '') {
                break;
            }
            $title = self::sectionLabelText($candidate);
            if ($title !== '') {
                break;
            }
        }

        if ($title === '') {
            foreach ($elements as $element) {
                if ((string) ($element['type'] ?? '') !== 'heading') {
                    continue;
                }
                $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                $title = self::sectionLabelText($data['text'] ?? '');
                if ($title !== '') {
                    break;
                }
            }
        }

        if ($title === '' && $semanticElement !== null) {
            $data = is_array($semanticElement['data'] ?? null) ? $semanticElement['data'] : [];
            $registered = BuilderRegistry::get((string) ($semanticElement['type'] ?? ''));
            $keys = array_values(array_unique(array_filter([
                $registered?->treeLabelField(), ...self::SECTION_LABEL_ELEMENT_TITLE_KEYS,
            ])));
            foreach ($keys as $key) {
                $title = self::sectionLabelText($data[$key] ?? '');
                if ($title !== '') {
                    break;
                }
            }
        }

        if ($typeLabel !== '' && $title !== '' && mb_strtolower($typeLabel) !== mb_strtolower($title)) {
            return self::sectionLabelText($typeLabel . ' · ' . $title, self::SECTION_LABEL_MAX);
        }
        return $title !== '' ? $title : $typeLabel;
    }

    /** @param array<string,mixed> $section @return list<array<string,mixed>> */
    private static function sectionElements(array $section): array
    {
        $result = [];
        foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
            if (!is_array($column)) {
                continue;
            }
            foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                if (is_array($element)) {
                    self::collectSectionElement($element, $result, 0);
                }
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $element @param list<array<string,mixed>> $result */
    private static function collectSectionElement(array $element, array &$result, int $depth): void
    {
        $result[] = $element;
        if ($depth >= 3) {
            return;
        }
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::collectSectionElement($child, $result, $depth + 1);
            }
        }
    }

    private static function sectionLabelText(
        mixed $value,
        int $maxLength = BloxDocumentPipeline::SECTION_NAME_MAX
    ): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_substr(trim($text), 0, $maxLength);
    }

    /** 标题字段只接受预设字号和 cssColor() 白名单颜色。 */
    private static function sectionFieldStyle(mixed $size, mixed $color, array $sizeMap): string
    {
        $style = '';
        $sizeKey = is_string($size) ? $size : '';
        if ($sizeKey !== '' && isset($sizeMap[$sizeKey])) {
            $style .= 'font-size:' . $sizeMap[$sizeKey] . ';';
        }
        $colorValue = AbstractElement::cssColor($color);
        if ($colorValue !== null) {
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
        // 响应式跨度：{d:桌面, t:平板}。手机始终单列是既有产品决策，故无 m 轴。
        // tablet_stack（平板堆叠）时平板档无意义，只输出桌面档。
        if (is_array($span)) {
            $d = self::spanValue($span, 'd');
            $t = self::spanValue($span, 't');
            if ($d < 1) {
                return '';
            }
            if ($desktopOnly) {
                return self::COLSPAN_DESKTOP_MAP[$d] ?? '';
            }
            if ($t === $d) {
                return self::COLSPAN_MAP[$d] ?? '';
            }
            return trim((self::COLSPAN_MAP[$t] ?? '') . ' ' . (self::COLSPAN_DESKTOP_MAP[$d] ?? ''));
        }
        $span = (int) $span;
        if ($span < 1 || $span > 12) {
            return '';
        }
        $map = $desktopOnly ? self::COLSPAN_DESKTOP_MAP : self::COLSPAN_MAP;
        return $map[$span] ?? '';
    }

    /** {d,t} 或标量 → 指定断点的跨度值；t 缺省继承 d。超界返回 0。 */
    private static function spanValue(mixed $span, string $breakpoint): int
    {
        if (is_array($span)) {
            $d = (int) ($span['d'] ?? 0);
            $v = $breakpoint === 't' ? (int) ($span['t'] ?? $d) : $d;
        } else {
            $v = (int) $span;
        }
        return $v >= 1 && $v <= 12 ? $v : 0;
    }

    /**
     * 断点可见性：hide_on = ['m','t','d'] 子集（数组或逗号串）。
     * 前台输出隐藏类；编辑态输出 data-yk-hide-on 标记（画布保持可见可选中）。
     * 返回 [附加类串（前导空格）, 附加属性串]。
     *
     * @return array{0:string,1:string}
     */
    private static function hideOn(mixed $hideOn, bool $editMode): array
    {
        $keys = self::hideOnKeys($hideOn);
        if ($keys === []) {
            return ['', ''];
        }
        if ($editMode) {
            return ['', ' data-yk-hide-on="' . implode(',', $keys) . '"'];
        }
        $classes = '';
        foreach ($keys as $k) {
            $classes .= ' ' . self::HIDE_ON_MAP[$k];
        }
        return [$classes, ''];
    }

    /** @return list<string> */
    private static function hideOnKeys(mixed $hideOn): array
    {
        $raw = is_string($hideOn) ? explode(',', $hideOn) : (is_array($hideOn) ? $hideOn : []);
        $keys = [];
        foreach ($raw as $k) {
            $k = trim((string) $k);
            if (isset(self::HIDE_ON_MAP[$k]) && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        return $keys;
    }

    private static function applyElementVisibility(string $html, mixed $hideOn, bool $editMode): string
    {
        $keys = self::hideOnKeys($hideOn);
        if ($html === '' || $keys === []) {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        if ($editMode) {
            $processor->setAttribute('data-yk-hide-on', implode(',', $keys));
        } else {
            $existing = $processor->getAttribute('class');
            $classes = is_string($existing) ? trim($existing) : '';
            foreach ($keys as $key) {
                $classes = trim($classes . ' ' . self::HIDE_ON_MAP[$key]);
            }
            $processor->setAttribute('class', $classes);
        }
        return $processor->getUpdatedHtml();
    }

    private static function applyElementBoxStyle(string $html, array $data, AbstractElement $element): string
    {
        // 服务端能力闸（2026-09-02）：supportsBoxStyles 此前只是编辑器显示开关，文档直填
        // style_* 仍会被应用。存量扫描（4 棵树 unknown-keys 观测日志 + 33 个仓库模板）
        // 确认关闭盒模型的元素零 style_* 使用，故收紧为渲染侧强制。原 'code' 特判由
        // CodeElement::supportsBoxStyles(): false 覆盖，不再单列。
        $boxStyle = $element->supportsBoxStyles() ? AbstractElement::boxStyle($data) : '';
        if ($html === '' || $boxStyle === '') {
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

    private static function applyGlobalStyle(string $html, array $data, string $type): string
    {
        $id = trim((string) ($data['_global_style'] ?? ''));
        $declarations = BloxDesignSystem::styleDeclarations($id, $data['_global_style_snapshot'] ?? null);
        if ($html === '' || $id === '' || $declarations === '' || $type === 'code') {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $existing = $processor->getAttribute('style');
        $style = is_string($existing) ? trim($existing) : '';
        if ($style !== '' && !str_ends_with($style, ';')) {
            $style .= ';';
        }
        $processor->setAttribute('style', $style . $declarations);
        $processor->setAttribute('data-yk-global-style', $id);
        return $processor->getUpdatedHtml();
    }

    public static function renderElementNode(array $el, int $depth = 0, bool $editMode = false, array $path = []): string
    {
        return self::renderElement($el, $depth, $editMode, $path);
    }

    private static function renderElement(array $el, int $depth = 0, bool $editMode = false, array $path = []): string
    {
        $type = trim((string) ($el['type'] ?? ''));
        $data = is_array($el['data'] ?? null) ? $el['data'] : [];
        $conditions = $data['_conditions'] ?? null;
        $hasConditions = BloxDisplayConditions::hasInput($conditions);
        if ($hasConditions && !$editMode && !self::$showHidden && !BloxDisplayConditions::matches($conditions)) {
            return '';
        }
        $element = BuilderRegistry::get($type);
        if ($element === null) {
            $missing = BloxPluginRegistry::declaration($type);
            if (!$editMode || $missing === null) {
                return '';
            }
            $pathAttr = htmlspecialchars(implode('.', array_map('strval', $path)), ENT_QUOTES);
            $nodeId = (string) ($el['id'] ?? '');
            $idAttr = $nodeId !== ''
                ? ' data-yk-el-id="' . htmlspecialchars($nodeId, ENT_QUOTES) . '"'
                : '';
            $typeAttr = htmlspecialchars($type, ENT_QUOTES);
            $label = htmlspecialchars($missing['label'], ENT_QUOTES);
            $conditionAttr = $hasConditions
                ? ' data-yk-conditions="' . htmlspecialchars(BloxDisplayConditions::badge($conditions), ENT_QUOTES) . '"'
                : '';
            return '<div class="yk-edit-el yk-missing-element" data-yk-el="' . $pathAttr
                . '"' . $idAttr . $conditionAttr
                . ' data-yk-el-type="' . $typeAttr . '"><div class="border-2 border-dashed border-amber-300'
                . ' bg-amber-50 px-4 py-5 text-center text-sm text-amber-800">'
                . '<strong>' . $label . '</strong><br>' . __('blox_plugin_missing_front') . '</div></div>';
        }
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
            'node_id' => (string) ($el['id'] ?? ''),
        ]);
        $html = BloxFrontendEditTarget::mark($html, $type, (string) ($el['id'] ?? ''));
        $html = self::applyElementBoxStyle($html, $data, $element);
        $html = self::applyGlobalStyle($html, $data, $element->type());
        $html = self::applyElementVisibility($html, $data['_hide_on'] ?? null, $editMode);
        $html = self::markCustomHomeElement($html, $element->type(), $path);
        if ($editMode && $hasConditions) {
            $html = self::markElementConditions($html, BloxDisplayConditions::badge($conditions));
        }
        if (!$editMode || $path === []) {
            return $html;
        }
        $storedPath = (string) ($data['_blox_path'] ?? '');
        $effectivePath = preg_match('/^\d+(?:\.\d+){2,3}$/', $storedPath) === 1
            ? $storedPath
            : implode('.', array_map('strval', $path));
        $pathAttr = htmlspecialchars($effectivePath, ENT_QUOTES);
        $nodeId = (string) ($el['id'] ?? '');
        $idAttr = $nodeId !== ''
            ? ' data-yk-el-id="' . htmlspecialchars($nodeId, ENT_QUOTES) . '"'
            : '';
        $typeAttr = htmlspecialchars($element->type(), ENT_QUOTES);
        $containerAttr = $element->isContainer() ? ' data-yk-el-container="1"' : '';
        return '<div class="yk-edit-el" data-yk-el="' . $pathAttr . '"' . $idAttr
            . ' data-yk-el-type="' . $typeAttr . '"' . $containerAttr
            . ' style="display:contents">' . $html . '</div>';
    }

    private static function markElementConditions(string $html, string $badge): string
    {
        if ($html === '' || $badge === '') {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $processor->setAttribute('data-yk-conditions', $badge);
        return $processor->getUpdatedHtml();
    }

    /** @param list<int> $path */
    private static function customHomeFieldPath(array $path, string $field): ?string
    {
        $context = self::$homeFieldEditContext;
        if ($context === null || count($path) < 2) {
            return null;
        }
        $base = 'custom_overrides.' . $context['locale'] . '.' . $path[0]
            . '.columns.' . $path[1];
        if (count($path) >= 3) {
            $base .= '.elements.' . $path[2] . '.data';
        }
        $fieldPath = $base . '.' . $field;
        return str_starts_with($context['type'], 'custom:')
            && HomeBloxBlockSchema::isCustomEditableFieldPath($fieldPath)
                ? $fieldPath : null;
    }

    private static function customHomeFieldAttributes(string $field, bool $inline): string
    {
        $context = self::$homeFieldEditContext;
        if ($context === null) {
            return '';
        }
        return ' data-yk-home-path="' . htmlspecialchars($context['path'], ENT_QUOTES)
            . '" data-yk-home-field="' . htmlspecialchars($field, ENT_QUOTES)
            . '" data-yk-home-inline="' . ($inline ? '1' : '0') . '"';
    }

    /** @param list<int> $path */
    private static function markCustomHomeElement(string $html, string $type, array $path): string
    {
        if ($type === 'accordion') {
            return self::markCustomHomeAccordion($html, $path);
        }
        $field = match ($type) {
            'heading', 'button' => 'text',
            'text' => 'html',
            default => '',
        };
        if ($field === '') {
            return $html;
        }
        $fieldPath = self::customHomeFieldPath($path, $field);
        if ($fieldPath === null) {
            return $html;
        }
        $tags = match ($type) {
            'heading' => ['H1', 'H2', 'H3', 'H4'],
            'button' => ['A'],
            default => ['DIV'],
        };
        foreach ($tags as $tag) {
            $rewriter = new HtmlTagRewriter($html);
            if (!$rewriter->nextTag($tag)) {
                continue;
            }
            $context = self::$homeFieldEditContext;
            if ($context === null) {
                return $html;
            }
            $rewriter->setAttribute('data-yk-home-path', $context['path']);
            $rewriter->setAttribute('data-yk-home-field', $fieldPath);
            $rewriter->setAttribute('data-yk-home-inline', $type === 'text' ? '0' : '1');
            return $rewriter->getUpdatedHtml();
        }
        return $html;
    }

    /** @param list<int> $path */
    private static function markCustomHomeAccordion(string $html, array $path): string
    {
        $context = self::$homeFieldEditContext;
        if ($context === null) {
            return $html;
        }
        $rewriter = new HtmlTagRewriter($html);
        $questionIndex = 0;
        $answerIndex = 0;
        while ($rewriter->nextTag()) {
            $tag = $rewriter->getTag();
            $fieldPath = null;
            if ($tag === 'SPAN' && $questionIndex < 30) {
                $fieldPath = self::customHomeFieldPath(
                    $path,
                    'accordion_items.' . $questionIndex++ . '.question'
                );
            } elseif ($tag === 'DIV' && $answerIndex < 30) {
                $classes = $rewriter->getAttribute('class');
                $tokens = is_string($classes) ? preg_split('/\s+/', trim($classes)) : [];
                if (in_array('pb-4', is_array($tokens) ? $tokens : [], true)) {
                    $fieldPath = self::customHomeFieldPath(
                        $path,
                        'accordion_items.' . $answerIndex++ . '.answer'
                    );
                }
            }
            if ($fieldPath === null) {
                continue;
            }
            $rewriter->setAttribute('data-yk-home-path', $context['path']);
            $rewriter->setAttribute('data-yk-home-field', $fieldPath);
            $rewriter->setAttribute('data-yk-home-inline', '1');
        }
        return $rewriter->getUpdatedHtml();
    }
}
