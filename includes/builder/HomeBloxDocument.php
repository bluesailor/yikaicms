<?php
/**
 * 首页 Blox 文档。
 *
 * P0 只保存首页布局草稿，不改变线上首页的旧渲染链路。首页动态区块先以
 * home-block 引用节点进入 Blox 树，后续阶段再把这些引用替换为真正的动态元素。
 */

declare(strict_types=1);

final class HomeBloxDocument
{
    public const DATA_KEY = 'home_blox_data';
    public const ACTIVE_KEY = 'home_blox_active';
    public const PUBLISHED_KEY = 'home_blox_published';
    public const HISTORY_KEY = 'home_blox_history';
    private const VERSION = 1;
    private const MAX_JSON_BYTES = 2_000_000;

    /** @return array{schema:int,settings:array<string,mixed>,version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>} */
    public static function load(): array
    {
        $raw = trim((string) config(self::DATA_KEY, ''));
        if ($raw !== '' && strlen($raw) <= self::MAX_JSON_BYTES) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $migrated = BloxDocumentPipeline::decode($raw);
                $hasDocumentSections = isset($decoded['sections']) && is_array($decoded['sections']);
                if ($hasDocumentSections || BloxDocumentPipeline::isList($decoded)) {
                    return [
                        'schema'     => $migrated['schema'],
                        'settings'   => $migrated['settings'],
                        'version'    => (int) ($decoded['version'] ?? self::VERSION),
                        'source'     => (string) ($decoded['source'] ?? 'blox'),
                        'active'     => self::isActive(),
                        'updated_at' => (int) ($decoded['updated_at'] ?? 0),
                        'sections'   => self::normalizeSections($migrated['sections']),
                    ];
                }
            }
        }

        return [
            'schema'     => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings'   => [],
            'version'    => self::VERSION,
            'source'     => 'legacy',
            'active'     => self::isActive(),
            'updated_at' => 0,
            'sections'   => self::legacySections(),
        ];
    }
public static function isActive(): bool
    {
        return (string) config(self::ACTIVE_KEY, '0') === '1';
    }

    public static function hasPublished(): bool
    {
        return self::readStoredDocument(self::PUBLISHED_KEY) !== null;
    }

    public static function hasDraft(): bool
    {
        return self::readStoredDocument(self::DATA_KEY) !== null;
    }

    /**
     * 将当前经典首页固化为 Blox 草稿，不启用 Blox，也不覆盖已有草稿。
     *
     * @psalm-suppress PossiblyUnusedReturnValue 返回值由 migrations/ 与隔离测试消费（不在 Psalm projectFiles 内）
     * @return array{schema:int,settings:array<string,mixed>,version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>}
     */
    public static function createDraftFromLegacy(): array
    {
        $existing = self::readStoredDocument(self::DATA_KEY);
        if ($existing !== null) {
            return $existing;
        }

        $payload = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => [],
            'sections' => self::legacySections(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $document = self::prepareDocument($payload, false);
        $document['source'] = 'legacy-import';

        settingModel()->set(
            self::DATA_KEY,
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'home'
        );

        return $document;
    }

    /**
     * Whether the automatic legacy draft can still be rebuilt without
     * overwriting user work or an existing publication.
     */
    public static function canRefreshLegacyImportDraft(): bool
    {
        $draft = self::readStoredDocument(self::DATA_KEY);

        return $draft !== null
            && in_array((string) ($draft['source'] ?? ''), ['legacy-import', 'legacy-import-complete'], true)
            && !self::isActive()
            && !self::hasPublished();
    }

    /**
     * Rebuilds only an untouched automatic import from the current classic
     * homepage settings. Returns null once the user has saved or published.
     *
     * @return array{schema:int,settings:array<string,mixed>,version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>}|null
     */
    public static function refreshLegacyImportDraft(): ?array
    {
        if (!self::canRefreshLegacyImportDraft()) {
            return null;
        }

        $payload = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => [],
            'sections' => self::legacySections(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $document = self::prepareDocument($payload, false);
        $document['source'] = 'legacy-import-complete-v2';

        settingModel()->set(
            self::DATA_KEY,
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'home'
        );

        return $document;
    }

    /** @return array{schema:int,settings:array<string,mixed>,version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>} */
    /** @psalm-suppress PossiblyUnusedMethod The public homepage entry point is invoked from the root index script. */
    public static function loadPublished(): array
    {
        return self::readStoredDocument(self::PUBLISHED_KEY) ?? self::load();
    }
/**
     * Publishes the current draft atomically and keeps the previous publication
     * as a bounded rollback point.
     *
     * @return array{active:bool,has_published:bool,sections:int}
     */
    public static function publishDraft(): array
    {
        $draft = self::load();
        $previous = self::readStoredDocument(self::PUBLISHED_KEY);
        $history = self::readHistory();

        if ($previous !== null) {
            array_unshift($history, $previous);
            $history = array_slice($history, 0, 10);
        }

        $db = db();
        $db->beginTransaction();
        try {
            settingModel()->set(
                self::PUBLISHED_KEY,
                json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'home'
            );
            settingModel()->set(
                self::HISTORY_KEY,
                json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'home'
            );
            settingModel()->set(self::ACTIVE_KEY, '1', 'home');
            if (class_exists(HomeLayoutDocument::class)) {
                settingModel()->set(HomeLayoutDocument::ACTIVE_KEY, '0', 'home');
            }
            $db->commit();
            do_action('data_changed', DB_PREFIX . 'settings', 0);
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [
            'active' => true,
            'has_published' => true,
            'sections' => count($draft['sections']),
        ];
    }

    /**
     * 校验当前编辑内容，并在同一事务中更新草稿、发布快照和回滚历史。
     *
     * @return array{active:bool,has_published:bool,sections:int,base_revision:string}
     */
    public static function saveAndPublish(string $blocksJson): array
    {
        $document = self::prepareDocument($blocksJson, true);
        $previous = self::readStoredDocument(self::PUBLISHED_KEY);
        $history = self::readHistory();

        if ($previous !== null) {
            array_unshift($history, $previous);
            $history = array_slice($history, 0, 10);
        }

        $documentJson = json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $db = db();
        $db->beginTransaction();
        try {
            settingModel()->set(self::DATA_KEY, $documentJson, 'home');
            settingModel()->set(self::PUBLISHED_KEY, $documentJson, 'home');
            settingModel()->set(
                self::HISTORY_KEY,
                json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'home'
            );
            settingModel()->set(self::ACTIVE_KEY, '1', 'home');
            if (class_exists(HomeLayoutDocument::class)) {
                settingModel()->set(HomeLayoutDocument::ACTIVE_KEY, '0', 'home');
            }
            $db->commit();
            do_action('data_changed', DB_PREFIX . 'settings', 0);
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        $revisionJson = json_encode([
            'schema' => $document['schema'],
            'settings' => $document['settings'],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'active' => true,
            'has_published' => true,
            'sections' => count($document['sections']),
            'base_revision' => BloxDocumentPipeline::fingerprint($revisionJson),
        ];
    }

    /**
     * Disables the Blox homepage publication and restores the legacy homepage
     * without deleting the draft or the last published document.
     *
     * @return array{active:bool,has_published:bool,sections:int}
     */
    public static function rollbackToLegacy(): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            settingModel()->set(self::ACTIVE_KEY, '0', 'home');
            $db->commit();
            do_action('data_changed', DB_PREFIX . 'settings', 0);
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [
            'active' => false,
            'has_published' => self::hasPublished(),
            'sections' => count(self::load()['sections']),
        ];
    }

    /**
     * 保存编辑器提交的 sections。P0 不启用首页，只写入草稿。
     *
     * @return array{schema:int,settings:array<string,mixed>,version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>}
     */
    public static function saveDraft(string $blocksJson): array
    {
        $document = self::prepareDocument($blocksJson, self::isActive());

        settingModel()->set(
            self::DATA_KEY,
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'home'
        );

        return $document;
    }

    /** @return array{schema:int,settings:array<string,mixed>,version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>} */
    private static function prepareDocument(string $blocksJson, bool $active): array
    {
        $processed = BloxDocumentPipeline::process($blocksJson, 'home');

        return [
            'schema' => $processed['schema'],
            'settings' => $processed['settings'],
            'version' => self::VERSION,
            'source' => 'blox',
            'active' => $active,
            'updated_at' => time(),
            'sections' => $processed['sections'],
        ];
    }

    /** @return array<string,mixed>|null */
    private static function readStoredDocument(string $key): ?array
    {
        $raw = trim((string) config($key, ''));
        if ($raw === '' || strlen($raw) > self::MAX_JSON_BYTES) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $migrated = BloxDocumentPipeline::decode($raw);
        if (!isset($decoded['sections']) && !BloxDocumentPipeline::isList($decoded)) {
            return null;
        }

        return [
            'schema' => $migrated['schema'],
            'settings' => $migrated['settings'],
            'version' => (int) ($decoded['version'] ?? self::VERSION),
            'source' => (string) ($decoded['source'] ?? 'blox'),
            'active' => self::isActive(),
            'updated_at' => (int) ($decoded['updated_at'] ?? 0),
            'sections' => self::normalizeSections($migrated['sections']),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function readHistory(): array
    {
        $raw = trim((string) config(self::HISTORY_KEY, ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $item): bool => is_array($item) && isset($item['sections']) && is_array($item['sections'])
        ));
    }


    /** @param array<int,array<string,mixed>> $sections @return array<int,array<string,mixed>> */
    private static function normalizeSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $si => $section) {
            $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
            $normalizedColumns = [];
            foreach ($columns as $ci => $column) {
                if (!is_array($column)) {
                    continue;
                }
                $elements = is_array($column['elements'] ?? null) ? $column['elements'] : [];
                $normalizedElements = [];
                foreach ($elements as $ei => $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $normalizedElements[] = [
                        'id'   => (string) ($element['id'] ?? 'home_e_' . $si . '_' . $ci . '_' . $ei),
                        'type' => (string) ($element['type'] ?? 'home-block'),
                        'data' => is_array($element['data'] ?? null) ? $element['data'] : [],
                    ];
                }
                $normalizedColumn = [
                    'id'       => (string) ($column['id'] ?? 'home_c_' . $si . '_' . $ci),
                    'elements' => $normalizedElements,
                ];
                if (isset($column['span'])) {
                    $normalizedColumn['span'] = BloxResponsiveValue::normalizeStored(
                        $column['span'],
                        array_fill_keys(range(0, 12), true),
                        0
                    );
                }
                if (isset($column['card_bg'])) {
                    $normalizedColumn['card_bg'] = (string) $column['card_bg'];
                }
                $normalizedColumns[] = $normalizedColumn;
            }

            $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];
            if (!array_key_exists('container_gutter', $settings)
                && self::isLegacyFullWidthDynamicSection($section, $settings, $normalizedColumns)) {
                $settings['container_gutter'] = 'none';
            }

            $normalizedSection = [
                'id'       => (string) ($section['id'] ?? 'home_s_' . $si),
                'type'     => (string) ($section['type'] ?? 'section'),
                'settings' => $settings,
                'columns'  => $normalizedColumns,
            ];
            if (isset($section['name'])) {
                $normalizedSection['name'] = (string) $section['name'];
            }
            $out[] = $normalizedSection;
        }
        return $out;
    }

    /**
     * 识别由旧首页配置懒生成的单区块包装层，为已保存草稿补齐无左右留白设置。
     *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $settings
     * @param array<int,array<string,mixed>> $columns
     */
    private static function isLegacyFullWidthDynamicSection(array $section, array $settings, array $columns): bool
    {
        if (!str_starts_with((string) ($section['id'] ?? ''), 'home_s_')
            || ($settings['max_width'] ?? '') !== 'full'
            || ($settings['padding'] ?? '') !== 'none'
            || trim((string) ($settings['title'] ?? '')) !== ''
            || trim((string) ($settings['subtitle'] ?? '')) !== ''
            || count($columns) !== 1) {
            return false;
        }

        $elements = $columns[0]['elements'] ?? [];
        return count($elements) === 1
            && ($elements[0]['type'] ?? '') === 'home-block'
            && str_starts_with((string) ($elements[0]['id'] ?? ''), 'home_e_');
    }

    /** @return array<int,array<string,mixed>> */
    private static function legacySections(): array
    {
        return HomeLayoutDocument::legacySectionsForImport();
    }

    public static function legacyLabel(string $type): string
    {
        $labels = [
            'banner'       => __('blox_hb_banner'),
            'about'        => __('blox_hb_about'),
            'stats'        => __('blox_hb_stats'),
            'channels'     => __('blox_hb_channels'),
            'testimonials' => __('blox_hb_testimonials'),
            'advantage'    => __('blox_hb_advantage'),
            'cta'          => __('blox_el_cta'),
            'partners'     => __('blox_hb_partners'),
            'product_categories' => __('blox_hb_product_categories'),
        ];
        if (isset($labels[$type])) {
            return $labels[$type];
        }
        if (str_starts_with($type, 'channel:')) {
            return __('blox_hb_channels') . ' #' . substr($type, 8);
        }
        if (str_starts_with($type, 'custom:')) {
            return __('blox_hb_custom') . ' #' . substr($type, 7);
        }
        return $type;
    }
}
