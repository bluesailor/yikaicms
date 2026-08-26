<?php
/** Blox 文档统一输入管线：大小限制、解析、schema 迁移、结构校验、ID 归一化与稳定编码。 */

declare(strict_types=1);

final class BloxDocumentPipeline
{
    public const MAX_JSON_BYTES = 2_000_000;
    public const MAX_SECTIONS = 100;
    public const SECTION_NAME_MAX = 80;

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
        $document = self::decode($json, $maxBytes);
        $sections = $document['sections'];
        if (count($sections) > $maxSections) {
            throw new RuntimeException(__('blox_doc_too_many_sections', ['max' => $maxSections]));
        }

        BloxDocumentValidator::assertValidSections($sections);
        BloxElementPolicy::assertSectionsAllowed($sections);
        BloxQueryLoopPolicy::assertSectionsAllowed($sections);
        BloxDisplayConditions::assertSectionsAllowed($sections);
        BloxDesignSystem::assertSectionsAllowed($sections);
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
     * 只解析并迁移信封，不校验元素注册表、也不重建节点 ID。
     * 编辑器 boot 与前台渲染用它统一识别旧裸数组/v1 信封，并在未来版本上
     * fail-closed；保存入口继续用 process() 做完整安检。
     *
     * @return array{schema:int,settings:array<string,mixed>,sections:array<int,mixed>}
     */
    public static function decode(string $json, int $maxBytes = self::MAX_JSON_BYTES): array
    {
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

        return self::migrate($decoded);
    }

    /**
     * 为乐观并发生成稳定的文档版本令牌。令牌基于迁移后的标准信封，
     * 因此历史裸数组与等价的 v1 文档不会制造伪冲突。
     */
    public static function fingerprint(string $json): string
    {
        $document = self::decode($json);
        $canonical = json_encode([
            'schema' => self::SCHEMA_VERSION,
            'settings' => $document['settings'],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    public static function revisionMatches(string $json, string $revision): bool
    {
        $revision = strtolower(trim($revision));
        return preg_match('/^[a-f0-9]{64}$/', $revision) === 1
            && hash_equals(self::fingerprint($json), $revision);
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
        $hasSchema = array_key_exists('schema', $decoded);
        if ($hasSchema && (!is_int($decoded['schema']) || $decoded['schema'] < 1)) {
            throw new RuntimeException(__('blox_doc_schema_invalid'));
        }
        $schema = $hasSchema ? $decoded['schema'] : 0;
        if ($schema > self::SCHEMA_VERSION) {
            throw new RuntimeException(__('blox_doc_schema_too_new', [
                'found' => $schema,
                'supported' => self::SCHEMA_VERSION,
            ]));
        }

        $document = $decoded;
        while ($schema < self::SCHEMA_VERSION) {
            $document = match ($schema) {
                0 => self::migrateV0ToV1($document),
                default => throw new RuntimeException(__('blox_doc_schema_invalid')),
            };
            $schema = (int) $document['schema'];
        }
        if (!array_key_exists('sections', $document) || !is_array($document['sections'])) {
            throw new RuntimeException(__('blox_doc_bad_sections'));
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'settings' => self::normalizeDocSettings($document['settings'] ?? null),
            'sections' => array_values($document['sections']),
        ];
    }

    /** PHP 8.0-compatible list-array check. */
    public static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected) {
                return false;
            }
            $expected++;
        }
        return true;
    }

    /** @param array<string|int,mixed> $document @return array{schema:int,settings:array<string,mixed>,sections:array<int,mixed>} */
    private static function migrateV0ToV1(array $document): array
    {
        if (array_key_exists('sections', $document)) {
            if (!is_array($document['sections'])) {
                throw new RuntimeException(__('blox_doc_bad_sections'));
            }
            $sections = $document['sections'];
        } elseif (self::isList($document)) {
            $sections = $document;
        } else {
            throw new RuntimeException(__('blox_doc_bad_sections'));
        }

        return [
            'schema' => 1,
            'settings' => self::normalizeDocSettings($document['settings'] ?? null),
            'sections' => array_values($sections),
        ];
    }

    /**
     * 文档级 settings 白名单（fail-closed：未知键丢弃，不入库）。
     * 通用信封当前只识别历史 sticky 键；Header/Footer 的类型归属与进一步收窄
     * 由 BloxAreaDocument 在区域入口统一执行。
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
        if (array_key_exists('sticky_behavior', $settings)) {
            $clean['sticky_behavior'] = BloxHeaderStates::normalizeStickyBehavior($settings['sticky_behavior']);
        }
        if (array_key_exists('sticky_devices', $settings)) {
            $clean['sticky_devices'] = BloxHeaderStates::normalizeStickyDevices($settings['sticky_devices']);
        }
        if (array_key_exists('header_overlay_enabled', $settings)) {
            $clean['header_overlay_enabled'] = !empty($settings['header_overlay_enabled']);
        }
        if (array_key_exists('header_states', $settings)) {
            $clean['header_states'] = BloxHeaderStates::normalize($settings['header_states']);
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
        $usedAnchors = [];
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
                    $spanChoices = array_fill_keys(range(0, 12), true);
                    $normalizedColumn['span'] = BloxResponsiveValue::normalizeStored(
                        $column['span'],
                        $spanChoices,
                        0
                    );
                }
                if (isset($column['card_bg'])) {
                    $normalizedColumn['card_bg'] = AbstractElement::cssColor($column['card_bg']) ?? '';
                }
                if (isset($column['card_bg_image'])) {
                    $normalizedColumn['card_bg_image'] = AbstractElement::cssImageUrl($column['card_bg_image']) ?? '';
                }
                if (isset($column['card_bg_overlay_color'])) {
                    $normalizedColumn['card_bg_overlay_color'] = AbstractElement::cssColor(
                        $column['card_bg_overlay_color']
                    ) ?? '';
                }
                if (isset($column['card_bg_overlay_opacity'])) {
                    $normalizedColumn['card_bg_overlay_opacity'] = max(
                        0,
                        min(100, (int) $column['card_bg_overlay_opacity'])
                    );
                }
                $columns[] = $normalizedColumn;
            }

            $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];
            foreach (['padding' => 'md', 'gap' => 'lg'] as $responsiveKey => $fallback) {
                if (array_key_exists($responsiveKey, $settings)) {
                    $settings[$responsiveKey] = BloxResponsiveValue::normalizeStored(
                        $settings[$responsiveKey],
                        array_fill_keys(['none', 'sm', 'md', 'lg', 'xl'], true),
                        $fallback
                    );
                }
            }
            foreach ([
                'bg_color',
                'bg_overlay_color',
                'container_bg',
                'container_bg_overlay_color',
                'title_color',
                'subtitle_color',
            ] as $colorKey) {
                if (array_key_exists($colorKey, $settings)) {
                    $settings[$colorKey] = AbstractElement::cssColor($settings[$colorKey]) ?? '';
                }
            }
            if (array_key_exists('bg_image', $settings)) {
                $settings['bg_image'] = AbstractElement::cssImageUrl($settings['bg_image']) ?? '';
            }
            if (array_key_exists('container_bg_image', $settings)) {
                $settings['container_bg_image'] = AbstractElement::cssImageUrl($settings['container_bg_image']) ?? '';
            }
            if (array_key_exists('bg_overlay_opacity', $settings)) {
                $settings['bg_overlay_opacity'] = max(0, min(100, (int) $settings['bg_overlay_opacity']));
            }
            if (array_key_exists('container_bg_overlay_opacity', $settings)) {
                $settings['container_bg_overlay_opacity'] = max(
                    0,
                    min(100, (int) $settings['container_bg_overlay_opacity'])
                );
            }
            foreach ([
                'bg_position' => ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'],
                'min_height' => ['', 'sm', 'md', 'lg', 'screen'],
                'content_v_align' => ['start', 'center', 'end'],
            ] as $settingKey => $allowedValues) {
                if (array_key_exists($settingKey, $settings)
                    && !in_array((string) $settings[$settingKey], $allowedValues, true)) {
                    $settings[$settingKey] = '';
                }
            }
            if (array_key_exists('anchor_id', $settings)) {
                $anchorId = self::normalizeSectionAnchorId($settings['anchor_id']);
                if ($anchorId !== '') {
                    $baseAnchor = $anchorId;
                    $suffix = 2;
                    while (isset($usedAnchors[strtolower($anchorId)])) {
                        $suffixText = '-' . $suffix;
                        $anchorId = substr($baseAnchor, 0, 64 - strlen($suffixText)) . $suffixText;
                        $suffix++;
                    }
                    $usedAnchors[strtolower($anchorId)] = true;
                }
                $settings['anchor_id'] = $anchorId;
            }

            $normalizedSection = [
                'id' => self::uniqueId(
                    $section['id'] ?? null,
                    $prefix . '_s_' . $sectionIndex,
                    $usedIds
                ),
                'type' => trim((string) ($section['type'] ?? 'section')) ?: 'section',
                'settings' => $settings,
                'columns' => $columns,
            ];
            if (array_key_exists('name', $section)) {
                $sectionName = self::normalizeSectionName($section['name']);
                if ($sectionName !== '') {
                    $normalizedSection['name'] = $sectionName;
                }
            }
            $normalized[] = $normalizedSection;
        }

        return $normalized;
    }

    public static function normalizeSectionAnchorId(mixed $value): string
    {
        $anchorId = is_string($value) || is_int($value) ? ltrim(trim((string) $value), '#') : '';
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $anchorId) === 1 ? $anchorId : '';
    }

    public static function normalizeSectionName(mixed $value): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }

        $name = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        return mb_substr($name, 0, self::SECTION_NAME_MAX);
    }

    /** @param array<string,mixed> $element @param array<string,true> $usedIds @return array<string,mixed> */
    private static function normalizeElement(array $element, string $fallbackId, array &$usedIds): array
    {
        $type = trim((string) ($element['type'] ?? ''));
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $data = BloxDesignSystem::normalizeElementData($data);
        $registered = BuilderRegistry::get($type);
        $declaredKeys = [];
        foreach ($registered?->controls() ?? [] as $control) {
            $key = (string) ($control['key'] ?? '');
            if ($key !== '') {
                $declaredKeys[] = $key;
            }
            if ($key === '' || !array_key_exists($key, $data)) {
                continue;
            }
            // responsive 值先行结构化归一，之后不再进标量清洗
            if (!empty($control['responsive'])) {
                $options = is_array($control['options'] ?? null) ? $control['options'] : [];
                if ($options !== []) {
                    $data[$key] = BloxResponsiveValue::normalizeStored(
                        $data[$key],
                        $options,
                        $control['default'] ?? array_key_first($options)
                    );
                }
                continue;
            }
            if (($control['type'] ?? '') === 'color') {
                $data[$key] = AbstractElement::cssColor($data[$key]) ?? '';
                continue;
            }
            // Typed Control Schema（v1.18.6）：其余类型统一走 BloxValueSanitizer——
            // text 截长 / richtext 净化 / url 与 image 与 video 拒伪协议 /
            // number 边界 / select 必属 options / checkbox 与 icon 归一。
            // 直接构造 blocks_data 提交同样过这一层。
            $data[$key] = BloxValueSanitizer::sanitize($control, $data[$key]);
        }
        // Unknown Data Key 策略 v1.18.6 为 dry-run：只观测记录，不丢弃（兼容优先）
        if ($registered !== null) {
            BloxUnknownKeys::observe($type, $declaredKeys, $data);
        }
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
            'type' => $type,
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
