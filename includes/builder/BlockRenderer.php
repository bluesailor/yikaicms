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
    /** 仅后台主动检查时开启；普通画布与前台不额外求值或输出诊断。 */
    public static bool $conditionDiagnostics = false;

    private const SECTION_LABEL_DECORATIVE_TYPES = [
        'heading', 'text', 'button', 'image', 'icon', 'code', 'divider', 'spacer', 'container', 'div',
    ];
    private const SECTION_LABEL_ELEMENT_TITLE_KEYS = ['title', 'name', 'label'];
    private const SECTION_LABEL_MAX = 120;

    /** 响应式映射（[基类, md:类, lg:类, wide:类]，字面量写全供 Tailwind 扫描；解析见 AbstractElement::respClasses） */
    private const PADDING_MAP = [
        'none' => ['py-0', 'md:py-0', 'lg:py-0', 'wide:py-0'],
        'xs'   => ['py-1', 'md:py-1', 'lg:py-1', 'wide:py-1'],
        'sm'   => ['py-4', 'md:py-4', 'lg:py-4', 'wide:py-4'],
        'md'   => ['py-8', 'md:py-8', 'lg:py-8', 'wide:py-8'],
        'lg'   => ['py-12', 'md:py-12', 'lg:py-12', 'wide:py-12'],
        'xl'   => ['py-16', 'md:py-16', 'lg:py-16', 'wide:py-16'],
    ];
    private const GAP_MAP = [
        'none' => ['gap-0', 'md:gap-0', 'lg:gap-0', 'wide:gap-0'],
        'sm'   => ['gap-2', 'md:gap-2', 'lg:gap-2', 'wide:gap-2'],
        'md'   => ['gap-4', 'md:gap-4', 'lg:gap-4', 'wide:gap-4'],
        'lg'   => ['gap-8', 'md:gap-8', 'lg:gap-8', 'wide:gap-8'],
        'xl'   => ['gap-12', 'md:gap-12', 'lg:gap-12', 'wide:gap-12'],
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
    // 0b：列跨度补 m/w 档。手机默认整行堆叠不变；显式 m 值用 max-md: 变体覆盖 grid-cols-1，
    // 未声明 m 的列在手机栅格区块里输出 col-span-12 兜底整行。
    private const COLSPAN_MOBILE_MAP = [
        1 => 'max-md:col-span-1', 2 => 'max-md:col-span-2', 3 => 'max-md:col-span-3', 4 => 'max-md:col-span-4',
        5 => 'max-md:col-span-5', 6 => 'max-md:col-span-6', 7 => 'max-md:col-span-7', 8 => 'max-md:col-span-8',
        9 => 'max-md:col-span-9', 10 => 'max-md:col-span-10', 11 => 'max-md:col-span-11', 12 => 'max-md:col-span-12',
    ];
    private const COLSPAN_WIDE_MAP = [
        1 => 'wide:col-span-1', 2 => 'wide:col-span-2', 3 => 'wide:col-span-3', 4 => 'wide:col-span-4',
        5 => 'wide:col-span-5', 6 => 'wide:col-span-6', 7 => 'wide:col-span-7', 8 => 'wide:col-span-8',
        9 => 'wide:col-span-9', 10 => 'wide:col-span-10', 11 => 'wide:col-span-11', 12 => 'wide:col-span-12',
    ];
    /** 断点隐藏类（前台输出；编辑态改打 data-yk-hide-on 标记以便画布仍可选中）。类名字面量供 Tailwind 扫描。 */
    // 桌面隐藏覆盖 ≥1024（含宽屏，与旧文档一致）；宽屏档另可单独隐藏 ≥1440
    private const HIDE_ON_MAP = ['m' => 'max-md:hidden', 't' => 'md:max-lg:hidden', 'd' => 'lg:hidden', 'w' => 'wide:hidden'];
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

    private static function backgroundTextTone(
        array $settings,
        ?string $bgColor,
        ?string $bgImage,
        string $bgVideo,
        string $bgGradient,
        ?string $overlayColor,
        int $overlayOpacity
    ): string {
        $requested = in_array(($settings['text_tone'] ?? null), ['auto', 'light', 'dark'], true)
            ? (string) $settings['text_tone']
            : 'auto';
        if ($requested !== 'auto') {
            return $requested;
        }

        if (($bgImage !== null || $bgVideo !== '') && $overlayColor !== null && $overlayOpacity >= 35) {
            $overlayTone = self::textToneForColor($overlayColor);
            if ($overlayTone !== '') {
                return $overlayTone;
            }
        }
        if ($bgImage !== null || $bgVideo !== '' || $bgGradient !== '') {
            return 'light';
        }

        return $bgColor !== null ? self::textToneForColor($bgColor) : '';
    }

    private static function textToneForColor(string $color): string
    {
        $red = $green = $blue = null;
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $matches) === 1) {
            $hex = $matches[1];
            if (strlen($hex) === 3) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            $red = hexdec(substr($hex, 0, 2));
            $green = hexdec(substr($hex, 2, 2));
            $blue = hexdec(substr($hex, 4, 2));
        } elseif (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/i', $color, $matches) === 1) {
            $red = min(255, (int) $matches[1]);
            $green = min(255, (int) $matches[2]);
            $blue = min(255, (int) $matches[3]);
        }
        if ($red === null || $green === null || $blue === null) {
            return '';
        }

        $brightness = ($red * 299 + $green * 587 + $blue * 114) / 1000;
        return $brightness > 150 ? 'dark' : 'light';
    }

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

    /**
     * 空输出守门（Site Builder 各运行时同源判定，外审 P2-4）：
     * "看起来成功其实空白"必须回落原生——纯包装 div（无文本、无媒体标签）不算有效输出。
     * $extraTags 供个别类型追加算数的标签（如搜索模板的 form）。
     *
     * @param list<string> $extraTags
     */
    public static function hasMeaningfulOutput(string $html, array $extraTags = []): bool
    {
        if (trim(strip_tags($html)) !== '') {
            return true;
        }
        $tags = array_merge(['img', 'video', 'iframe'], $extraTags);
        return (bool) preg_match('/<(?:' . implode('|', $tags) . ')\b/i', $html);
    }

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
        // 页面自定义 CSS（已净化）进样式区，与元素的自定义 CSS 一起输出
        BloxCustomCode::collectDocumentCss(is_array($document['settings'] ?? null) ? $document['settings'] : []);

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
            if (!isset($settings['padding']) && BloxDesignTheme::hasSectionSpacing()) {
                $padding .= ' yk-section-space-theme';
            }
            if (($settings['max_width'] ?? 'default') === 'default' && BloxDesignTheme::hasContentWidth()) {
                $maxWidth .= ' yk-width-theme';
            }

            $style = '';
            $bgColor = AbstractElement::cssColor($settings['bg_color'] ?? null);
            $bgImage = AbstractElement::cssImageUrl($settings['bg_image'] ?? null);
            $bgVideo = AbstractElement::backgroundVideoUrl(['bg_video' => $settings['bg_video'] ?? '']);
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
            $hasBackgroundMedia = $bgImage !== null || $bgVideo !== '';
            $hasOverlay = $hasBackgroundMedia && $overlayColor !== null && $overlayOpacity > 0;
            $textTone = self::backgroundTextTone(
                $settings,
                $bgColor,
                $bgImage,
                $bgVideo,
                $bgGrad,
                $hasOverlay ? $overlayColor : null,
                $overlayOpacity
            );
            $textToneAttr = $textTone !== '' ? ' data-blox-text-tone="' . $textTone . '"' : '';
            $styleAttr = $style ? ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"' : '';

            $columns = $section['columns'] ?? [];
            $colCount = count($columns);
            if ($colCount < 1) {
                continue;
            }
            $hasCustomSpans = false;
            $spanTotal = 0;
            $hasMobileSpans = false;
            foreach ($columns as $col) {
                if (is_array($col) && isset($col['span'])) {
                    $hasCustomSpans = true;
                    $spanTotal += max(0, self::spanValue($col['span'], 'd'));
                    if (self::spanValue($col['span'], 'm') >= 1) {
                        $hasMobileSpans = true;
                    }
                }
            }
            $useCustomSpans = $hasCustomSpans && $spanTotal > 0 && $spanTotal <= 12;
            // 0b：任一列显式声明手机跨度才启用手机栅格；否则维持整行堆叠（存量输出不变）。
            $useMobileSpans = $useCustomSpans && $hasMobileSpans;

            $gap = AbstractElement::respClasses($settings['gap'] ?? 'lg', self::GAP_MAP, 'lg');
            $gridClass = '';
            if ($colCount > 1) {
                $gridMap = !empty($settings['tablet_stack']) ? self::GRIDCOL_DESKTOP_MAP : self::GRIDCOL_MAP;
                $gridClass = 'grid grid-cols-1 ' . ($useCustomSpans ? $gridMap[12] : ($gridMap[$colCount] ?? $gridMap[12])) . ' ' . $gap;
                if ($useMobileSpans) {
                    $gridClass .= ' max-md:grid-cols-12';
                }
                if (!empty(self::ALIGN_ITEMS_MAP[$settings['align_items'] ?? ''])) {
                    $gridClass .= ' ' . self::ALIGN_ITEMS_MAP[$settings['align_items']];
                }
                if (!empty(self::JUSTIFY_ITEMS_MAP[$settings['justify_items'] ?? ''])) {
                    $gridClass .= ' ' . self::JUSTIFY_ITEMS_MAP[$settings['justify_items']];
                }
            }

            $editAttr = $editMode ? ' data-yk-sec="' . (int) $secIndex . '"' : '';
            if ($editMode) {
                $editAttr .= self::conditionDiagnosticAttribute($sectionConditions);
            }
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
            if ($hasOverlay || $bgVideo !== '') {
                $sectionLayoutClass .= ' relative overflow-hidden';
            }
            $html .= '<section class="' . $padding . $sectionLayoutClass . $secHideCls . $anchorClass . '"'
                . $anchorAttr . $textToneAttr . $styleAttr . $editAttr . $secHideAttr . '>';
            if ($bgVideo !== '') {
                BloxAssetCollector::addScript('/assets/js/blox-video-policy.js');
                BloxAssetCollector::addScript('/assets/js/blox-background-video.js');
                $mobileVideoMode = ($settings['bg_video_mobile_mode'] ?? 'poster') === 'video' ? 'video' : 'poster';
                $posterAttr = $bgImage !== null
                    ? ' poster="' . htmlspecialchars($bgImage, ENT_QUOTES) . '"'
                    : '';
                $html .= '<div class="blox-bg-media" aria-hidden="true"><video muted loop playsinline preload="none"'
                    . ' data-blox-background-video data-blox-mobile-video="' . $mobileVideoMode . '"'
                    . ' data-blox-video-src="' . htmlspecialchars($bgVideo, ENT_QUOTES) . '"'
                    . $posterAttr . '></video></div>';
            }
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
            if ($hasOverlay || $bgVideo !== '') {
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
                // 进场动画：与首页动态区块标题一致，默认滚动到视窗时向上淡入；选「无动画」可关闭
                $titleAnimation = (string) ($settings['title_animation'] ?? '');
                if ($titleAnimation === '') {
                    $titleAnimation = 'fade-up';
                }
                $titleAnimAttr = in_array($titleAnimation, self::SECTION_TITLE_ANIMATIONS, true)
                    ? ' data-animate="' . $titleAnimation . '"' : '';
                if ($titleAnimAttr !== '') BloxAssetCollector::addScript('/assets/js/scroll-anim.js');
                $html .= '<div class="' . $titleAlign . ' mb-10"' . $titleAnimAttr . '>';
                $titleEditAttr = $editMode ? ' data-yk-sec-field="' . (int) $secIndex . '.title"' : '';
                $subEditAttr = $editMode ? ' data-yk-sec-field="' . (int) $secIndex . '.subtitle"' : '';
                $html .= '<' . $titleTag . ' class="blk-title"' . $titleEditAttr . $titleStyle . '>' . htmlspecialchars($secTitle) . '</' . $titleTag . '>';
                $html .= self::sectionTitleDecor($settings);
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
                    ? self::colSpanClass($column['span'] ?? 0, !empty($settings['tablet_stack']), $useMobileSpans)
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
        if ($depth + 1 >= BloxDocumentValidator::MAX_ELEMENT_DEPTH) {
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
    /**
     * 区块标题下的装饰线/圆点。未设置任何装饰项时输出与旧版逐字节一致。
     * 对齐默认跟随标题对齐（旧版左对齐标题下装饰线仍居中）。
     * @param array<string,mixed> $settings
     */
    /** 区块标题可选的进场动画（与元素动画同一套 data-animate 效果，由 scroll-anim.js 驱动） */
    public const SECTION_TITLE_ANIMATIONS = ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in'];

    private static function sectionTitleDecor(array $settings): string
    {
        $style = (string) ($settings['title_decor_style'] ?? 'inherit');
        if ($style === 'none') {
            return '';
        }
        $class = $style === 'dot' ? 'section-title-dot' : 'section-title-bar';
        $inline = [];
        $align = (string) ($settings['title_decor_align'] ?? 'inherit');
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = in_array(($settings['title_align'] ?? ''), ['left', 'right'], true) ? (string) $settings['title_align'] : '';
        }
        if ($align === 'left') {
            $inline[] = 'margin-left:0;margin-right:auto';
        } elseif ($align === 'right') {
            $inline[] = 'margin-left:auto;margin-right:0';
        }
        $color = AbstractElement::cssColor($settings['title_decor_color'] ?? null);
        if ($color !== null) {
            $inline[] = 'background:' . $color;
        }
        $width = max(0, min(240, (int) ($settings['title_decor_width'] ?? 0)));
        if ($width > 0) {
            $inline[] = 'width:' . $width . 'px' . ($style === 'dot' ? ';height:' . $width . 'px' : '');
        }
        $gap = max(0, min(80, (int) ($settings['title_decor_gap'] ?? 0)));
        if ($gap > 0) {
            $inline[] = 'margin-top:' . $gap . 'px';
        }
        return '<span class="' . $class . '"'
            . ($inline === [] ? '' : ' style="' . htmlspecialchars(implode(';', $inline), ENT_QUOTES) . '"')
            . '></span>';
    }

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
     * 深度约束见 BloxDocumentValidator::MAX_ELEMENT_DEPTH（保存时显式拒绝，渲染只兜底）。
     * 未注册 type 静默跳过（与旧 switch default 行为一致）。
     */
    private static function colSpanClass(mixed $span, bool $desktopOnly = false, bool $mobileGrid = false): string
    {
        // 响应式跨度 {d,t,m,w}：t 缺省继承 d；m 缺省=整行堆叠（无 m 轴输出，既有产品决策的延续），
        // 仅当区块启用手机栅格（$mobileGrid）时补 max-md: 档；w 缺省继承桌面，仅差异且宽屏档开启时输出。
        // tablet_stack（平板堆叠）时平板档无意义，只输出桌面档。
        $mobile = '';
        if ($mobileGrid) {
            $m = self::spanValue($span, 'm');
            $mobile = self::COLSPAN_MOBILE_MAP[$m >= 1 ? $m : 12] ?? '';
        }
        $wide = '';
        if (is_array($span) && BloxResponsiveValue::wideEnabled()) {
            $w = self::spanValue($span, 'w');
            if ($w >= 1 && $w !== self::spanValue($span, 'd')) {
                $wide = self::COLSPAN_WIDE_MAP[$w] ?? '';
            }
        }
        $base = self::colSpanBaseClass($span, $desktopOnly);
        if ($mobile === '' && $wide === '') {
            return $base;
        }
        return trim($mobile . ' ' . trim($base . ' ' . $wide));
    }

    /** d/t 两档的既有输出，逐字节保持（0a 之前的黄金对拍基线）。 */
    private static function colSpanBaseClass(mixed $span, bool $desktopOnly): string
    {
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

    /**
     * {d,t,m,w} 或标量 → 指定断点的跨度值；t 缺省继承 d，m/w 缺省返回 0（继承标记）。超界返回 0。
     * @return int<0,12>
     */
    private static function spanValue(mixed $span, string $breakpoint): int
    {
        if (is_array($span)) {
            $d = (int) ($span['d'] ?? 0);
            $v = match ($breakpoint) {
                't' => (int) ($span['t'] ?? $d),
                'm' => (int) ($span['m'] ?? 0),
                'w' => (int) ($span['w'] ?? 0),
                default => $d,
            };
        } else {
            $v = $breakpoint === 'm' || $breakpoint === 'w' ? 0 : (int) $span;
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
            // 全站关闭宽屏档时，「宽屏隐藏」不再生效（桌面隐藏仍覆盖 ≥1024）
            if ($k === 'w' && !BloxResponsiveValue::wideEnabled()) {
                continue;
            }
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

    private static function applyElementSharedStyles(string $html, array $data, AbstractElement $element): string
    {
        // 服务端能力闸（2026-09-02）：supportsBoxStyles 此前只是编辑器显示开关，文档直填
        // style_* 仍会被应用。存量扫描（4 棵树 unknown-keys 观测日志 + 33 个仓库模板）
        // 确认关闭盒模型的元素零 style_* 使用，故收紧为渲染侧强制。原 'code' 特判由
        // CodeElement::supportsBoxStyles(): false 覆盖，不再单列。
        // 背景 root 策略（第 3 轮）：元素正常渲染后由此处把共享背景声明注入首标签；
        // native 元素（container/div）自行渲染背景，此处不重复注入。
        $bgStyle = $element->backgroundRenderStrategy() === 'root' ? AbstractElement::backgroundDeclarations($data) : '';
        $boxStyle = $element->supportsBoxStyles() ? AbstractElement::boxStyle($data) : '';
        $style = $bgStyle . $boxStyle;
        if ($html === '' || $style === '') {
            return $html;
        }

        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $existingStyle = $processor->getAttribute('style');
        $existing = is_string($existingStyle) ? trim($existingStyle) : '';
        if ($existing !== '' && !str_ends_with($existing, ';')) {
            $existing .= ';';
        }
        $processor->setAttribute('style', $existing . $style);
        return $processor->getUpdatedHtml();
    }

    /**
     * 声明式 CSS（E05）：属性与作用域来自可信 controls()，文档只提供值。
     * 输出写入元素根标签（内联声明或固定变量类），不产生额外节点，局部 SSR 单根协议不变。
     */
    private static function applyCompiledCss(string $html, array $data, AbstractElement $element): string
    {
        if ($html === '') {
            return $html;
        }
        try {
            $compiled = BloxCssCompiler::compile($element->controls(), $data);
        } catch (InvalidArgumentException $e) {
            // 插件 schema 声明错误只记录、不输出样式，不能让整页渲染失败。
            error_log('Blox declarative CSS skipped [' . $element->type() . ']: ' . $e->getMessage());
            return $html;
        }
        if ($compiled['style'] === '' && $compiled['classes'] === []) {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag($element->compiledCssTargetTag())) {
            return $html;
        }
        if ($compiled['style'] !== '') {
            $existing = $processor->getAttribute('style');
            $style = is_string($existing) ? trim($existing) : '';
            if ($style !== '' && !str_ends_with($style, ';')) {
                $style .= ';';
            }
            $processor->setAttribute('style', $style . $compiled['style']);
        }
        if ($compiled['classes'] !== []) {
            $existingClass = $processor->getAttribute('class');
            $processor->setAttribute('class', trim((is_string($existingClass) ? $existingClass : '') . ' ' . implode(' ', $compiled['classes'])));
        }
        return $processor->getUpdatedHtml();
    }

    private static function applyGlobalStyle(string $html, array $data, string $type): string
    {
        $id = trim((string) ($data['_global_style'] ?? ''));
        // 未绑定命名样式的元素占绝大多数：先短路，避免逐元素解析设计快照与回退快照。
        if ($html === '' || $id === '' || $type === 'code') {
            return $html;
        }
        $declarations = BloxDesignSystem::styleDeclarations($id, $data['_global_style_snapshot'] ?? null);
        if ($declarations === '') {
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

    /** 全局类（v1.23）：_classes 的 ID 数组 → 当前类名（yk-c- 前缀），追加到元素根标签。 */
    private static function applyGlobalClasses(string $html, array $data, string $type): string
    {
        // 绝大多数元素不挂类：先短路，目录查询本身有请求级缓存
        if ($html === '' || $type === 'code' || !is_array($data['_classes'] ?? null) || $data['_classes'] === []) {
            return $html;
        }
        $classes = BloxGlobalClasses::classAttributeFor($data);
        if ($classes === '') {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $existing = $processor->getAttribute('class');
        $processor->setAttribute('class', trim((is_string($existing) ? $existing : '') . $classes));
        return $processor->getUpdatedHtml();
    }

    /**
     * v1.25 容器 Loop：子树按查询行重复渲染。每行压入 TagEngine 上下文
     * （{{loop.*}} 与 {yk:field} 都指向当前行）；单子节点时该节点自身就是循环项
     * （grid_span 等子项设置直接生效），多子节点时用 yk-query-item 包裹保持行边界。
     * 空结果按 empty_mode 输出提示或整体隐藏（返回 null 表示不渲染容器本身）。
     *
     * @param array<int,mixed> $childNodes @param list<int> $path
     */
    private static function renderLoopChildren(array $query, array $childNodes, array $el, int $depth, array $path): ?string
    {
        $param = ($query['pagination'] ?? 'none') === 'numbers'
            ? BloxLoopQuery::paginationParam($query, (string) ($el['id'] ?? ''))
            : '';
        $result = BloxLoopQuery::run($query, $param);
        if ($result['rows'] === []) {
            if (($query['empty_mode'] ?? 'message') === 'hidden') {
                return null;
            }
            $empty = trim((string) ($query['empty'] ?? ''));
            if ($empty === '') {
                $empty = __('blox_dynamic_empty_default');
            }
            return '<p class="yk-query-empty text-sm text-gray-500">' . e($empty) . '</p>';
        }
        $html = '';
        $contextType = $result['is_product'] ? 'product' : 'content';
        $wrapItems = count($childNodes) > 1;
        foreach (array_values($result['rows']) as $index => $row) {
            $row['_type'] = $contextType;
            $row['_index'] = $index + 1;
            TagEngine::pushContext($row);
            try {
                $itemHtml = '';
                foreach ($childNodes as $childIndex => $child) {
                    if (is_array($child)) {
                        $childPath = $path;
                        $childPath[] = (int) $childIndex;
                        $itemHtml .= self::renderElement($child, $depth + 1, false, $childPath);
                    }
                }
                $html .= $wrapItems ? '<div class="yk-query-item">' . $itemHtml . '</div>' : $itemHtml;
            } finally {
                TagEngine::popContext();
            }
        }
        if ($result['pagination'] !== '') {
            // grid 容器里分页占满整行（col-span-full），flex-wrap 里独占一行（basis-full）
            $html .= '<div class="yk-query-pagination-wrap w-full basis-full col-span-full">' . $result['pagination'] . '</div>';
        }
        return $html;
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
        // 动态绑定为空时的处置（E10）。编辑器里始终保留占位，否则作者会以为元素丢了；
        // row 规则由容器渲染侧消费（见 renderChildren），这里只负责元素自身消失。
        if (!$editMode && !self::$showHidden && BloxEmptyBinding::decide($type, $data) !== BloxEmptyBinding::KEEP) {
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
                . '"' . $idAttr . $conditionAttr . self::conditionDiagnosticAttribute($conditions)
                . ' data-yk-el-type="' . $typeAttr . '"><div class="border-2 border-dashed border-amber-300'
                . ' bg-amber-50 px-4 py-5 text-center text-sm text-amber-800">'
                . '<strong>' . $label . '</strong><br>' . __('blox_plugin_missing_front') . '</div></div>';
        }
        BloxAssetCollector::collectElement($element, $data);

        $children = '';
        // 0b：深度约束改由 BloxDocumentValidator::MAX_ELEMENT_DEPTH 显式校验（顶层=第 1 层，
        // 此处 $depth 从 0 计），这里只作坏数据兜底，不再是静默截断点。
        if ($element->isContainer() && !$element->rendersOwnChildren()
            && $depth + 1 < BloxDocumentValidator::MAX_ELEMENT_DEPTH) {
            $childNodes = (array) ($el['data']['children'] ?? []);
            // v1.25 容器 Loop：编辑态渲染一次子树作模板（不跑查询）；前台按行循环
            $loopQuery = $editMode ? null : BloxLoopQuery::queryFrom($type, $data);
            if ($loopQuery !== null && $childNodes !== []) {
                $loopChildren = self::renderLoopChildren($loopQuery, $childNodes, $el, $depth, $path);
                if ($loopChildren === null) {
                    return '';   // 空结果且 empty_mode=hidden：整个循环容器不输出
                }
                $children = $loopChildren;
            } else {
                foreach ($childNodes as $childIndex => $child) {
                    if (!is_array($child)) {
                        continue;
                    }
                    // row 规则（E10）：子元素的动态绑定为空且要求"整行消失"时，
                    // 连这个容器一起不输出——否则留下的是一个带内边距与间距的空壳，
                    // 版面上照样是一道缝。编辑器里不生效，作者要看得见自己的结构。
                    if (!$editMode && !self::$showHidden
                        && BloxEmptyBinding::decide((string) ($child['type'] ?? ''),
                            is_array($child['data'] ?? null) ? $child['data'] : []) === BloxEmptyBinding::ROW) {
                        return '';
                    }
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
        if ($editMode && in_array($type, ['heading', 'text', 'button'], true)
            && preg_match('/\{[a-z_]+\}|\{\{[^{}]+\}\}/', (string) ($data[$type === 'text' ? 'html' : 'text'] ?? ''))) {
            $dynamicRoot = new HtmlTagRewriter($html);
            if ($dynamicRoot->nextTag()) $dynamicRoot->setAttribute('data-yk-dynamic-tags', '1');
            $html = $dynamicRoot->getUpdatedHtml();
        }
        $html = self::applyElementSharedStyles($html, $data, $element);
        $html = self::applyCompiledCss($html, $data, $element);
        $html = self::applyGlobalStyle($html, $data, $element->type());
        $html = self::applyGlobalClasses($html, $data, $element->type());
        if ($element->type() !== 'code') {
            $html = BloxCustomCode::applyToElement($html, $data, (string) ($el['id'] ?? ''));
        }
        $html = self::applyElementVisibility($html, $data['_hide_on'] ?? null, $editMode);
        $html = self::markCustomHomeElement($html, $element->type(), $path);
        // v1.28 元素交互：前台把已归一的 _interactions 序列化到首标签，runtime 按需加载
        //（编辑态不输出——画布里不执行交互；渲染已发布数据永远免费，不查能力位）
        if (!$editMode && BloxInteractions::hasInput($data['_interactions'] ?? null)) {
            $interactionItems = BloxInteractions::normalize($data['_interactions']);
            if ($interactionItems !== []) {
                $interactionRoot = new HtmlTagRewriter($html);
                if ($interactionRoot->nextTag()) {
                    $interactionRoot->setAttribute('data-yk-interactions', BloxInteractions::attributeValue($interactionItems));
                    $html = $interactionRoot->getUpdatedHtml();
                    BloxAssetCollector::addScript('/assets/js/blox-interactions.js');
                }
            }
        }
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
            . self::conditionDiagnosticAttribute($conditions)
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

    private static function conditionDiagnosticAttribute(mixed $conditions): string
    {
        if (!self::$conditionDiagnostics) {
            return '';
        }
        // 只带布尔判定与类型/操作符，不输出规则值、字段名或实际字段/参数内容。
        return ' data-yk-condition-report="' . htmlspecialchars(
            json_encode(BloxDisplayConditions::diagnose($conditions), JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        ) . '"';
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
