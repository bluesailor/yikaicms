<?php

declare(strict_types=1);

/**
 * 渲染首页文档，保留旧动态区块的应用层渲染能力。
 * 最终文档树只交给 BlockRenderer 一次。
 */
final class HomeBloxRenderer
{
    /** @param array<int, array<string, mixed>> $blocks */
    public static function legacyStartsWithVisibleBanner(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (!is_array($block) || empty($block['enabled'])) {
                continue;
            }

            return ($block['type'] ?? '') === 'banner';
        }

        return false;
    }

    /**
     * 传统首页只有第一个启用区块是 Banner 时，才允许分组设置覆盖 Header。
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed> $bannerGroup
     */
    public static function legacyStartsWithHeaderOverlayBanner(array $blocks, array $bannerGroup): bool
    {
        $runtime = HomeBloxBlockSchema::bannerGroupRuntimeConfig($bannerGroup);
        if (($runtime['banner_height_mode'] ?? 'fixed') !== 'cover-header') {
            return false;
        }

        return self::legacyStartsWithVisibleBanner($blocks);
    }

    /** @param array<int, array<string, mixed>> $sections */
    public static function startsWithVisibleBanner(array $sections): bool
    {
        $data = self::firstVisibleHomeBlockData($sections);
        return is_array($data) && ($data['block_type'] ?? '') === 'banner';
    }

    /**
     * Header 只能覆盖文档的第一个可见元素，避免透明导航压在普通内容上。
     *
     * @param array<int, array<string, mixed>> $sections
     * @param array<string, mixed> $inheritedBannerGroup
     */
    public static function startsWithHeaderOverlayBanner(array $sections, array $inheritedBannerGroup = []): bool
    {
        $data = self::firstVisibleHomeBlockData($sections);
        if (!is_array($data) || ($data['block_type'] ?? '') !== 'banner') {
            return false;
        }

        $heightMode = (string) ($data['banner_height_mode'] ?? 'inherit');
        if ($heightMode === 'inherit' && $inheritedBannerGroup !== []) {
            $runtime = HomeBloxBlockSchema::bannerGroupRuntimeConfig($inheritedBannerGroup);
            $heightMode = (string) ($runtime['banner_height_mode'] ?? 'fixed');
        }

        return $heightMode === 'cover-header';
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array<string, mixed>|null
     */
    private static function firstVisibleHomeBlockData(array $sections): ?array
    {
        foreach ($sections as $section) {
            if (!is_array($section) || !empty($section['settings']['hidden'])) {
                continue;
            }

            foreach (($section['columns'] ?? []) as $column) {
                if (!is_array($column)) {
                    continue;
                }

                foreach (($column['elements'] ?? []) as $element) {
                    if (!is_array($element)) {
                        continue;
                    }

                    if (($element['type'] ?? '') !== 'home-block') {
                        return null;
                    }

                    $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                    if (empty($data['enabled'])) {
                        continue;
                    }

                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @param callable(array<string, mixed>): string $renderDynamic
     */
    public static function render(array $sections, callable $renderDynamic): string
    {
        $renderSections = [];

        foreach ($sections as $sectionIndex => $section) {
            if (!is_array($section)) {
                continue;
            }

            // 隐藏区块：与普通排版页同一约定（settings.hidden，可选键，缺省即显示）。
            // 首页走的是本渲染器，不加这段则编辑器里的隐藏按钮在首页失效。
            if (!empty($section['settings']['hidden']) && !BlockRenderer::$showHidden) {
                continue;
            }

            $renderSection = $section;
            $renderColumns = [];
            foreach (($section['columns'] ?? []) as $columnIndex => $column) {
                if (!is_array($column)) {
                    continue;
                }

                $renderColumn = $column;
                $renderElements = [];
                foreach (($column['elements'] ?? []) as $elementIndex => $element) {
                    if (!is_array($element)) {
                        continue;
                    }

                    $renderElement = $element;
                    $renderElement['data'] = is_array($element['data'] ?? null) ? $element['data'] : [];
                    $renderElement['data']['_blox_path'] = (int) $sectionIndex . '.' . (int) $columnIndex . '.' . (int) $elementIndex;

                    if (($element['type'] ?? '') !== 'home-block') {
                        $renderElements[] = $renderElement;
                        continue;
                    }

                    if (empty(($element['data'] ?? [])['enabled'])) {
                        if (!BlockRenderer::$showHidden) {
                            continue;
                        }

                        $homeBlockElement = BuilderRegistry::get('home-block');
                        $disabledHtml = $homeBlockElement?->render($renderElement['data']) ?? '';
                        if ($disabledHtml !== '') {
                            $renderElements[] = [
                                'id' => (string) ($renderElement['id'] ?? ''),
                                'type' => 'code',
                                'data' => [
                                    'html' => $disabledHtml,
                                    '_blox_path' => $renderElement['data']['_blox_path'],
                                ],
                            ];
                        }
                        continue;
                    }

                    $dynamicHtml = $renderDynamic($renderElement);
                    if ($dynamicHtml === '') {
                        continue;
                    }

                    // Collect before the dynamic block is converted to a code element below.
                    $homeBlockElement = BuilderRegistry::get('home-block');
                    if ($homeBlockElement !== null) {
                        BloxAssetCollector::collectElement($homeBlockElement, $renderElement['data']);
                    }

                    // 动态旧区块保持在原来的 section、column、element 位置。
                    $renderElements[] = [
                        'id' => (string) ($renderElement['id'] ?? ''),
                        'type' => 'code',
                        'data' => [
                            'html' => $dynamicHtml,
                            '_blox_path' => $renderElement['data']['_blox_path'],
                        ],
                    ];
                }

                $renderColumn['elements'] = $renderElements;
                $renderColumns[] = $renderColumn;
            }

            $renderSection['columns'] = $renderColumns;
            $renderSections[] = $renderSection;
        }

        $json = json_encode(
            $renderSections,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json)) {
            return '';
        }

        return BlockRenderer::render($json);
    }
}
