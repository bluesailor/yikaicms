<?php

declare(strict_types=1);

/**
 * 渲染首页文档，保留旧动态区块的应用层渲染能力。
 * 最终文档树只交给 BlockRenderer 一次。
 */
final class HomeBloxRenderer
{
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
                        continue;
                    }

                    $dynamicHtml = $renderDynamic($renderElement);
                    if ($dynamicHtml === '') {
                        continue;
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