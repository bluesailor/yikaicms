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
            'document' => $sections,
        ];
    }

    /** @return array{elements:list<string>,plugins:list<string>} */
    private static function decodeStoredRequirements(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return ['elements' => [], 'plugins' => []];
        }
        try {
            $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['elements' => [], 'plugins' => []];
        }
        if (!is_array($decoded)) {
            return ['elements' => [], 'plugins' => []];
        }
        return [
            'elements' => self::stringList($decoded['elements'] ?? []),
            'plugins' => self::stringList($decoded['plugins'] ?? []),
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
     *   requirements:array{elements:list<string>,plugins:list<string>}
     * }
     */
    public static function prepare(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new RuntimeException('模板文件不能超过 2MB');
        }
        try {
            $package = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('模板文件不是有效 JSON');
        }
        if (!is_array($package)) {
            throw new RuntimeException('模板文件不是有效 JSON 对象');
        }
        self::assertEnvelope($package);

        $type = trim((string) $package['type']);
        $name = trim((string) $package['name']);
        if (!BloxTemplateModel::validType($type)) {
            throw new RuntimeException('模板类型只允许 section、page、header、footer');
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new RuntimeException('模板名称长度必须为 1-150 个字符');
        }
        if (!is_array($package['document'])) {
            throw new RuntimeException('模板 document 结构无效');
        }

        $rawSections = BloxDocumentPipeline::extractSections($package['document']);
        self::assertNoLibraryReferences($rawSections);
        $inferred = self::inferRequirements($rawSections);
        $foundTypes = $inferred['elements'];
        $declared = self::declaredRequirements($package['requires'] ?? []);
        $requiredTypes = array_values(array_unique(array_merge($foundTypes, $declared['elements'])));
        sort($requiredTypes);

        if (in_array('code', $requiredTypes, true)) {
            throw new RuntimeException('免费版模板导入不允许 code 元素');
        }
        $missingElements = [];
        foreach ($requiredTypes as $elementType) {
            if (BuilderRegistry::get($elementType) === null) {
                $missingElements[] = $elementType;
            }
        }
        if ($missingElements !== []) {
            throw new RuntimeException('模板缺少已启用元素：' . implode('、', $missingElements));
        }

        $pluginOwners = self::pluginOwners($requiredTypes);
        $activePlugins = array_values(array_unique(array_merge($pluginOwners, self::activePluginSlugs())));
        $missingPlugins = array_values(array_diff($declared['plugins'], $activePlugins));
        if ($missingPlugins !== []) {
            sort($missingPlugins);
            throw new RuntimeException('模板缺少已启用插件：' . implode('、', $missingPlugins));
        }

        $withoutIds = BloxDocumentPipeline::withoutNodeIds($rawSections);
        $documentJson = json_encode(
            $withoutIds,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $prefix = 'tpl_' . bin2hex(random_bytes(6));
        $processed = BloxDocumentPipeline::process($documentJson, $prefix);
        $requiredPlugins = array_values(array_unique(array_merge($declared['plugins'], $pluginOwners)));
        sort($requiredPlugins);

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
            ],
        ];
    }

    /** @param array<string|int,mixed> $package */
    private static function assertEnvelope(array $package): void
    {
        foreach (['php', 'javascript', 'scripts', 'files', 'server_path'] as $dangerousKey) {
            if (array_key_exists($dangerousKey, $package)) {
                throw new RuntimeException('模板包包含不允许的可执行字段：' . $dangerousKey);
            }
        }
        foreach (['format', 'version', 'type', 'name', 'document'] as $key) {
            if (!array_key_exists($key, $package)) {
                throw new RuntimeException('模板缺少必填字段：' . $key);
            }
        }
        if ((string) $package['format'] !== self::FORMAT) {
            throw new RuntimeException('不支持的模板格式');
        }
        if ((int) $package['version'] !== self::VERSION) {
            throw new RuntimeException('只支持 Blox 模板 JSON v1');
        }
    }

    /** @return array{elements:list<string>,plugins:list<string>} */
    private static function declaredRequirements(mixed $requires): array
    {
        if ($requires === null || $requires === []) {
            return ['elements' => [], 'plugins' => []];
        }
        if (!is_array($requires)) {
            throw new RuntimeException('模板 requires 结构无效');
        }
        return [
            'elements' => self::stringList($requires['elements'] ?? []),
            'plugins' => self::stringList($requires['plugins'] ?? []),
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $items): array
    {
        if (!is_array($items)) {
            throw new RuntimeException('模板依赖列表结构无效');
        }
        $result = [];
        foreach ($items as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException('模板依赖项必须是非空字符串');
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

    /** @param array<int,mixed> $sections @return array{elements:list<string>,plugins:list<string>} */
    private static function inferRequirements(array $sections): array
    {
        $elements = self::collectElementTypes($sections);
        return ['elements' => $elements, 'plugins' => self::pluginOwners($elements)];
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
     * @param array{elements:list<string>,plugins:list<string>} $left
     * @param array{elements:list<string>,plugins:list<string>} $right
     * @return array{elements:list<string>,plugins:list<string>}
     */
    private static function mergeRequirements(array $left, array $right): array
    {
        $elements = array_values(array_unique(array_merge($left['elements'], $right['elements'])));
        $plugins = array_values(array_unique(array_merge($left['plugins'], $right['plugins'])));
        sort($elements);
        sort($plugins);

        return ['elements' => $elements, 'plugins' => $plugins];
    }

    /** @param array<int,mixed> $sections */
    private static function assertNoLibraryReferences(array $sections): void
    {
        foreach ($sections as $section) {
            if (is_array($section) && (int) ($section['library_id'] ?? 0) > 0) {
                throw new RuntimeException('模板导入不支持跨站可复用区块引用');
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
