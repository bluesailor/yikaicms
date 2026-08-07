<?php
/** Blox 文档统一输入管线：大小限制、解析、schema 迁移、结构校验、ID 归一化与稳定编码。 */

declare(strict_types=1);

final class BloxDocumentPipeline
{
    public const MAX_JSON_BYTES = 2_000_000;
    public const MAX_SECTIONS = 100;

    /**
     * 文档信封 schema 版本（r10 起写入）。历史两形态（裸 sections 数组 /
     * 无 schema 键的 {sections:[...]}）视为 v0，migrate() 载入时升级——
     * 渲染器与保存端只面对最新格式，旧格式在入口处显式升级（惰性迁移，无需刷库）。
     */
    public const SCHEMA_VERSION = 1;

    /** @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string} */
    public static function process(
        string $json,
        string $idPrefix = 'blox',
        int $maxBytes = self::MAX_JSON_BYTES,
        int $maxSections = self::MAX_SECTIONS
    ): array {
        if (strlen($json) > $maxBytes) {
            throw new RuntimeException(__('blox_doc_too_large'));
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(__('blox_doc_invalid_json'));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(__('blox_doc_invalid_json'));
        }
        if (array_key_exists('sections', $decoded) && !is_array($decoded['sections'])) {
            throw new RuntimeException(__('blox_doc_bad_sections'));
        }

        $document = self::migrate($decoded);
        $sections = $document['sections'];
        if (count($sections) > $maxSections) {
            throw new RuntimeException(__('blox_doc_too_many_sections', ['max' => $maxSections]));
        }

        BloxDocumentValidator::assertValidSections($sections);
        $normalized = self::normalizeSections($sections, $idPrefix);
        BloxDocumentValidator::assertValidSections($normalized);

        $envelope = [
            'schema' => self::SCHEMA_VERSION,
            'settings' => $document['settings'],
            'sections' => $normalized,
        ];
        return [
            'schema' => self::SCHEMA_VERSION,
            'settings' => $document['settings'],
            'sections' => $normalized,
            'json' => json_encode(
                $envelope,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ];
    }

    /**
     * schema 迁移管道（有序、纯函数式，Puck/Elementor V4/Gutenberg 同型）：
     * v0（无 schema 键的历史两形态）→ v1 信封；未来 v1→v2 迁移按序排在此处。
     * 高于当前版本一律拒绝（fail-closed：新版本文档在旧代码上报错，不静默丢数据）。
     *
     * @param array<string|int,mixed> $decoded
     * @return array{schema:int,settings:array<string,mixed>,sections:array<int,mixed>}
     */
    public static function migrate(array $decoded): array
    {
        if (isset($decoded['schema'])) {
            $schema = (int) $decoded['schema'];
            if ($schema > self::SCHEMA_VERSION) {
                throw new RuntimeException(__('blox_doc_schema_too_new', [
                    'found' => $schema,
                    'supported' => self::SCHEMA_VERSION,
                ]));
            }
        }
        return [
            'schema' => self::SCHEMA_VERSION,
            'settings' => self::normalizeDocSettings($decoded['settings'] ?? null),
            'sections' => self::extractSections($decoded),
        ];
    }

    /**
     * 文档级 settings 白名单（fail-closed：未知键丢弃，不入库）。
     * 当前仅 sticky（header 模板吸顶开关）。
     *
     * @return array<string,mixed>
     */
    public static function normalizeDocSettings(mixed $settings): array
    {
        if (!is_array($settings)) {
            return [];
        }
        $clean = [];
        if (array_key_exists('sticky', $settings)) {
            $clean['sticky'] = !empty($settings['sticky']);
        }
        return $clean;
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
