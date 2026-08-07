<?php
/** Blox 文档统一输入管线：大小限制、解析、结构校验、ID 归一化与稳定编码。 */

declare(strict_types=1);

final class BloxDocumentPipeline
{
    public const MAX_JSON_BYTES = 2_000_000;
    public const MAX_SECTIONS = 100;

    /** @return array{sections:array<int,array<string,mixed>>,json:string} */
    public static function process(
        string $json,
        string $idPrefix = 'blox',
        int $maxBytes = self::MAX_JSON_BYTES,
        int $maxSections = self::MAX_SECTIONS
    ): array {
        if (strlen($json) > $maxBytes) {
            throw new RuntimeException('排版数据超过允许大小');
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('排版数据不是有效 JSON');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('排版数据不是有效 JSON');
        }
        if (array_key_exists('sections', $decoded) && !is_array($decoded['sections'])) {
            throw new RuntimeException('排版文档 sections 结构无效');
        }

        $sections = self::extractSections($decoded);
        if (count($sections) > $maxSections) {
            throw new RuntimeException('排版区块数量不能超过 ' . $maxSections . ' 个');
        }

        BloxDocumentValidator::assertValidSections($sections);
        $normalized = self::normalizeSections($sections, $idPrefix);
        BloxDocumentValidator::assertValidSections($normalized);

        return [
            'sections' => $normalized,
            'json' => json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }

    /** @param array<string|int,mixed> $document @return array<int,mixed> */
    public static function extractSections(array $document): array
    {
        $sections = isset($document['sections']) && is_array($document['sections'])
            ? $document['sections']
            : $document;
        return array_values($sections);
    }

    /** @param array<int,mixed> $sections @return array<int,mixed> */
    public static function withoutNodeIds(array $sections): array
    {
        $stripElement = static function (array $element) use (&$stripElement): array {
            unset($element['id']);
            $data = is_array($element['data'] ?? null) ? $element['data'] : [];
            if (is_array($data['children'] ?? null)) {
                $children = [];
                foreach ($data['children'] as $child) {
                    if (is_array($child)) {
                        $children[] = $stripElement($child);
                    }
                }
                $data['children'] = $children;
            }
            $element['data'] = $data;
            return $element;
        };

        foreach ($sections as &$section) {
            if (!is_array($section)) {
                continue;
            }
            unset($section['id']);
            if (!is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as &$column) {
                if (!is_array($column)) {
                    continue;
                }
                unset($column['id']);
                if (!is_array($column['elements'] ?? null)) {
                    continue;
                }
                foreach ($column['elements'] as &$element) {
                    if (is_array($element)) {
                        $element = $stripElement($element);
                    }
                }
                unset($element);
            }
            unset($column);
        }
        unset($section);

        return $sections;
    }

    /** @param array<int,mixed> $sections @return array<int,array<string,mixed>> */
    public static function normalizeSections(array $sections, string $idPrefix = 'blox'): array
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $idPrefix) ?: 'blox';
        $usedIds = [];
        $normalized = [];

        foreach ($sections as $sectionIndex => $section) {
            if (!is_array($section)) {
                continue;
            }
            $columns = [];
            $rawColumns = is_array($section['columns'] ?? null) ? array_values($section['columns']) : [];
            foreach ($rawColumns as $columnIndex => $column) {
                if (!is_array($column)) {
                    continue;
                }
                $elements = [];
                $rawElements = $column['elements'] ?? [];
                if (!is_array($rawElements)) {
                    $rawElements = [];
                }
                /** @psalm-suppress NoValue 前置结构校验已保证元素列表可遍历。 */
                foreach (array_values($rawElements) as $elementIndex => $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $elements[] = self::normalizeElement(
                        $element,
                        $prefix . '_e_' . $sectionIndex . '_' . $columnIndex . '_' . $elementIndex,
                        $usedIds
                    );
                }

                $normalizedColumn = [
                    'id' => self::uniqueId(
                        $column['id'] ?? null,
                        $prefix . '_c_' . $sectionIndex . '_' . $columnIndex,
                        $usedIds
                    ),
                    'elements' => $elements,
                ];
                if (isset($column['span'])) {
                    $normalizedColumn['span'] = (int) $column['span'];
                }
                if (isset($column['card_bg'])) {
                    $normalizedColumn['card_bg'] = (string) $column['card_bg'];
                }
                $columns[] = $normalizedColumn;
            }

            $normalizedSection = [
                'id' => self::uniqueId(
                    $section['id'] ?? null,
                    $prefix . '_s_' . $sectionIndex,
                    $usedIds
                ),
                'type' => trim((string) ($section['type'] ?? 'section')) ?: 'section',
                'settings' => is_array($section['settings'] ?? null) ? $section['settings'] : [],
                'columns' => $columns,
            ];
            if (isset($section['name'])) {
                $normalizedSection['name'] = (string) $section['name'];
            }
            $normalized[] = $normalizedSection;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $element @param array<string,true> $usedIds @return array<string,mixed> */
    private static function normalizeElement(array $element, string $fallbackId, array &$usedIds): array
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        if (array_key_exists('children', $data) && is_array($data['children'])) {
            $children = [];
            foreach (array_values($data['children']) as $childIndex => $child) {
                if (is_array($child)) {
                    $children[] = self::normalizeElement($child, $fallbackId . '_' . $childIndex, $usedIds);
                }
            }
            $data['children'] = $children;
        }

        return [
            'id' => self::uniqueId($element['id'] ?? null, $fallbackId, $usedIds),
            'type' => trim((string) ($element['type'] ?? '')),
            'data' => $data,
        ];
    }

    /** @param array<string,true> $usedIds */
    private static function uniqueId(mixed $preferred, string $fallback, array &$usedIds): string
    {
        $candidate = is_string($preferred) || is_int($preferred) ? trim((string) $preferred) : '';
        $candidate = $candidate !== '' ? $candidate : $fallback;
        $base = $candidate;
        $suffix = 2;
        while (isset($usedIds[$candidate])) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }
        $usedIds[$candidate] = true;
        return $candidate;
    }
}
