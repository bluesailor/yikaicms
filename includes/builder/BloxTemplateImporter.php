<?php
/** Blox 模板 JSON v1 安全导入器。 */

declare(strict_types=1);

final class BloxTemplateImporter
{
    public const FORMAT = 'yikaicms-blox-template';
    public const VERSION = 1;
    public const MAX_BYTES = 2_000_000;

    /** @return array{id:int,type:string,name:string,sections:int} */
    public static function importJson(
        string $json,
        int $adminId = 0,
        string $source = 'import',
        string $sourceRef = ''
    ): array
    {
        $prepared = self::prepare($json);
        db()->beginTransaction();
        try {
            $id = bloxTemplateModel()->createDraft(
                $prepared['type'],
                $prepared['name'],
                $prepared['draft_json'],
                $source,
                $prepared['schema_version'],
                $prepared['requirements'],
                $prepared['thumbnail'],
                $adminId,
                $sourceRef
            );
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return [
            'id' => $id,
            'type' => $prepared['type'],
            'name' => $prepared['name'],
            'sections' => count($prepared['sections']),
        ];
    }

    /** @param array<string,mixed> $template */
    public static function exportJson(array $template, bool $publishedOnly = false): string
    {
        $package = self::exportPackage($template, $publishedOnly);
        return json_encode(
            $package,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /** @param array<string,mixed> $template @return array<string,mixed> */
    public static function exportPackage(array $template, bool $publishedOnly = false): array
    {
        $type = trim((string) ($template['type'] ?? ''));
        if (!BloxTemplateModel::validType($type)) {
            throw new RuntimeException('Invalid Blox template type');
        }

        $name = trim((string) ($template['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException('Invalid Blox template name');
        }

        $publishedData = trim((string) ($template['published_data'] ?? ''));
        $draftData = trim((string) ($template['draft_data'] ?? ''));
        $documentJson = $publishedOnly ? $publishedData : ($publishedData !== '' ? $publishedData : $draftData);
        if ($documentJson === '') {
            throw new RuntimeException('Blox template has no exportable document');
        }

        try {
            $decoded = json_decode($documentJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Blox template document is not valid JSON');
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Blox template document is not valid JSON');
        }

        $sections = BloxDocumentPipeline::extractSections($decoded);
        $requirements = self::mergeRequirements(
            self::decodeStoredRequirements($template['requirements'] ?? null),
            self::inferRequirements($sections)
        );
        // 文档级 settings（如 sticky）随包导出——document 输出 v1 信封；
        // 无 settings 的存量文档仍导出裸 sections（包格式向后兼容，老版本可导入）
        $rawSettings = is_array($decoded) ? ($decoded['settings'] ?? null) : null;
        $docSettings = BloxAreaDocument::isArea($type)
            ? BloxAreaDocument::normalizeSettings($type, $rawSettings)
            : ($type === 'popup'
                ? BloxPopupDocument::normalizeSettings($rawSettings)
                : BloxDocumentPipeline::normalizeDocSettings($rawSettings));
        $document = $docSettings !== []
            ? ['schema' => BloxDocumentPipeline::SCHEMA_VERSION, 'settings' => $docSettings, 'sections' => $sections]
            : $sections;

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'type' => $type,
            'name' => $name,
            'thumbnail' => self::safeThumbnail((string) ($template['thumbnail'] ?? '')),
            'requires' => $requirements,
            'meta' => [
                'source' => (string) ($template['source'] ?? ''),
                'source_ref' => (string) ($template['source_ref'] ?? ''),
                'schema_version' => max(1, (int) ($template['schema_version'] ?? self::VERSION)),
                'exported_at' => time(),
            ],
            'document' => $document,
        ];
    }

    /** @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>} */
    private static function decodeStoredRequirements(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return self::emptyRequirements();
        }
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::emptyRequirements();
        }
        if (!is_array($decoded)) {
            return self::emptyRequirements();
        }
        return [
            'elements' => self::stringList($decoded['elements'] ?? []),
            'plugins' => self::stringList($decoded['plugins'] ?? []),
            'design_tokens' => self::stringList($decoded['design_tokens'] ?? []),
            'design_styles' => self::stringList($decoded['design_styles'] ?? []),
        ];
    }

    public static function exportFilename(array $template): string
    {
        $name = trim((string) ($template['name'] ?? 'blox-template'));
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name) ?: 'blox-template';
        $slug = trim($slug, '-_') ?: 'blox-template';
        return strtolower($slug) . '-' . max(0, (int) ($template['id'] ?? 0)) . '.json';
    }

    /**
     * @return array{
     *   type:string,name:string,schema_version:int,thumbnail:string,
     *   sections:array<int,array<string,mixed>>,draft_json:string,
     *   requirements:array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>}
     * }
     */
    public static function prepare(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException(__('blox_tpl_too_large'));
        }
        try {
            $package = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(__('blox_tpl_invalid_json'));
        }
        if (!is_array($package)) {
            throw new RuntimeException(__('blox_tpl_not_object'));
        }
        self::assertEnvelope($package);

        $type = trim((string) $package['type']);
        $name = trim((string) $package['name']);
        if (!BloxTemplateModel::validType($type)) {
            throw new RuntimeException(__('blox_tpl_bad_type'));
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException(__('blox_tpl_bad_name'));
        }
        if (!is_array($package['document'])) {
            throw new RuntimeException(__('blox_tpl_bad_document'));
        }

        $rawSections = BloxDocumentPipeline::extractSections($package['document']);
        self::assertNoLibraryReferences($rawSections);
        $inferred = self::inferRequirements($rawSections);
        $foundTypes = $inferred['elements'];
        $declared = self::declaredRequirements($package['requires'] ?? []);
        $requiredTypes = array_values(array_unique(array_merge($foundTypes, $declared['elements'])));
        sort($requiredTypes);

        if (in_array('code', $requiredTypes, true)) {
            throw new RuntimeException(__('blox_tpl_code_locked'));
        }
        $missingElements = [];
        foreach ($requiredTypes as $elementType) {
            if (BuilderRegistry::get($elementType) === null) {
                $missingElements[] = $elementType;
            }
        }
        if ($missingElements !== []) {
            throw new RuntimeException(__('blox_tpl_missing_elements', ['list' => implode('、', $missingElements)]));
        }

        $pluginOwners = self::pluginOwners($requiredTypes);
        $activePlugins = array_values(array_unique(array_merge($pluginOwners, self::activePluginSlugs())));
        $missingPlugins = array_values(array_diff($declared['plugins'], $activePlugins));
        if ($missingPlugins !== []) {
            sort($missingPlugins);
            throw new RuntimeException(__('blox_tpl_missing_plugins', ['list' => implode('、', $missingPlugins)]));
        }

        $withoutIds = BloxDocumentPipeline::withoutNodeIds($rawSections);
        // 文档级 settings（如 header 的 sticky）随模板包走 v1 信封进出，不在提取 sections 时丢失
        $rawSettings = is_array($package['document']) ? ($package['document']['settings'] ?? null) : null;
        $docSettings = BloxAreaDocument::isArea($type)
            ? BloxAreaDocument::normalizeSettings($type, $rawSettings)
            : ($type === 'popup'
                ? BloxPopupDocument::normalizeSettings($rawSettings)
                : BloxDocumentPipeline::normalizeDocSettings($rawSettings));
        $documentJson = json_encode(
            ['schema' => BloxDocumentPipeline::SCHEMA_VERSION, 'settings' => $docSettings, 'sections' => $withoutIds],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $prefix = 'tpl_' . bin2hex(random_bytes(6));
        $processed = BloxAreaDocument::isArea($type)
            ? BloxAreaDocument::process($type, $documentJson, $prefix)
            : ($type === 'popup'
                ? BloxPopupDocument::process($documentJson, $prefix)
                : BloxDocumentPipeline::process($documentJson, $prefix));
        $requiredPlugins = array_values(array_unique(array_merge($declared['plugins'], $pluginOwners)));
        sort($requiredPlugins);
        $requiredTokens = array_values(array_unique(array_merge($declared['design_tokens'], $inferred['design_tokens'])));
        $requiredStyles = array_values(array_unique(array_merge($declared['design_styles'], $inferred['design_styles'])));
        sort($requiredTokens);
        sort($requiredStyles);

        return [
            'type' => $type,
            'name' => $name,
            'schema_version' => self::VERSION,
            'thumbnail' => self::safeThumbnail((string) ($package['thumbnail'] ?? '')),
            'sections' => $processed['sections'],
            'draft_json' => $processed['json'],
            'requirements' => [
                'elements' => $requiredTypes,
                'plugins' => $requiredPlugins,
                'design_tokens' => $requiredTokens,
                'design_styles' => $requiredStyles,
            ],
        ];
    }

    /** @param array<string|int,mixed> $package */
    private static function assertEnvelope(array $package): void
    {
        foreach (['php', 'javascript', 'scripts', 'files', 'server_path'] as $dangerousKey) {
            if (array_key_exists($dangerousKey, $package)) {
                throw new RuntimeException(__('blox_tpl_dangerous_field', ['key' => $dangerousKey]));
            }
        }
        foreach (['format', 'version', 'type', 'name', 'document'] as $key) {
            if (!array_key_exists($key, $package)) {
                throw new RuntimeException(__('blox_tpl_missing_field', ['key' => $key]));
            }
        }
        if ((string) $package['format'] !== self::FORMAT) {
            throw new RuntimeException(__('blox_tpl_bad_format'));
        }
        if ((int) $package['version'] !== self::VERSION) {
            throw new RuntimeException(__('blox_tpl_v1_only'));
        }
    }

    /** @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>} */
    private static function declaredRequirements(mixed $requires): array
    {
        if ($requires === null || $requires === []) {
            return self::emptyRequirements();
        }
        if (!is_array($requires)) {
            throw new RuntimeException(__('blox_tpl_bad_requires'));
        }
        return [
            'elements' => self::stringList($requires['elements'] ?? []),
            'plugins' => self::stringList($requires['plugins'] ?? []),
            'design_tokens' => self::stringList($requires['design_tokens'] ?? []),
            'design_styles' => self::stringList($requires['design_styles'] ?? []),
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $items): array
    {
        if (!is_array($items)) {
            throw new RuntimeException(__('blox_tpl_bad_dep_list'));
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException(__('blox_tpl_bad_dep_item'));
            }
            $result[] = trim($item);
        }
        $result = array_values(array_unique($result));
        sort($result);
        return $result;
    }

    /** @param array<int,mixed> $sections @return list<string> */
    private static function collectElementTypes(array $sections): array
    {
        $types = [];
        $visit = static function (mixed $node) use (&$visit, &$types): void {
            if (!is_array($node)) {
                return;
            }
            $type = trim((string) ($node['type'] ?? ''));
            if ($type !== '') {
                $types[] = $type;
            }
            $data = $node['data'] ?? null;
            if (is_array($data) && is_array($data['children'] ?? null)) {
                foreach ($data['children'] as $child) {
                    $visit($child);
                }
            }
        };
        foreach ($sections as $section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as $column) {
                if (!is_array($column) || !is_array($column['elements'] ?? null)) {
                    continue;
                }
                foreach ($column['elements'] as $element) {
                    $visit($element);
                }
            }
        }
        $types = array_values(array_unique($types));
        sort($types);
        return $types;
    }

    /** @param array<int,mixed> $sections @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>} */
    /**
     * 从实际文档推导依赖（编辑器模板模式存草稿时刷新 requirements 用）。
     *
     * @param array<int,mixed> $sections
     * @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>}
     */
    public static function deriveRequirements(array $sections): array
    {
        return self::inferRequirements($sections);
    }

    private static function inferRequirements(array $sections): array
    {
        $elements = self::collectElementTypes($sections);
        $design = BloxDesignDependencies::referencesFromSections($sections);
        return [
            'elements' => $elements,
            'plugins' => self::pluginOwners($elements),
            'design_tokens' => $design['design_tokens'],
            'design_styles' => $design['design_styles'],
        ];
    }

    /** @param list<string> $elements @return list<string> */
    private static function pluginOwners(array $elements): array
    {
        $plugins = [];
        foreach ($elements as $type) {
            $owner = BloxPluginRegistry::ownerOf($type);
            if ($owner !== null) {
                $plugins[] = $owner;
            }
        }
        $plugins = array_values(array_unique($plugins));
        sort($plugins);
        return $plugins;
    }

    /**
     * @param array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>} $left
     * @param array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>} $right
     * @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>}
     */
    private static function mergeRequirements(array $left, array $right): array
    {
        $elements = array_values(array_unique(array_merge($left['elements'], $right['elements'])));
        $plugins = array_values(array_unique(array_merge($left['plugins'], $right['plugins'])));
        $designTokens = array_values(array_unique(array_merge($left['design_tokens'], $right['design_tokens'])));
        $designStyles = array_values(array_unique(array_merge($left['design_styles'], $right['design_styles'])));
        sort($elements);
        sort($plugins);
        sort($designTokens);
        sort($designStyles);

        return [
            'elements' => $elements,
            'plugins' => $plugins,
            'design_tokens' => $designTokens,
            'design_styles' => $designStyles,
        ];
    }

    /** @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>} */
    private static function emptyRequirements(): array
    {
        return ['elements' => [], 'plugins' => [], 'design_tokens' => [], 'design_styles' => []];
    }

    /** @param array<int,mixed> $sections */
    private static function assertNoLibraryReferences(array $sections): void
    {
        foreach ($sections as $section) {
            if (is_array($section) && (int) ($section['library_id'] ?? 0) > 0) {
                throw new RuntimeException(__('blox_tpl_cross_site_ref'));
            }
        }
    }

    /** @return list<string> */
    private static function activePluginSlugs(): array
    {
        try {
            return function_exists('pluginModel') ? array_values(pluginModel()->getActiveSlugs()) : [];
        } catch (Throwable) {
            return [];
        }
    }

    private static function safeThumbnail(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        return preg_match('#^/(?:uploads|assets|plugins)/[a-zA-Z0-9_./-]+$#', $path) === 1 ? $path : '';
    }
}
