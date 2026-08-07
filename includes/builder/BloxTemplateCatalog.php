<?php
/** Blox 编辑器模板目录：统一发布模板与插件模板的发现、解析和结构校验。 */

declare(strict_types=1);

final class BloxTemplateCatalog
{
    private const CONTEXTS = ['page', 'home'];
    private const EDITOR_TYPES = ['section', 'page'];
    private static string $remoteError = '';

    /** @return list<array{key:string,type:string,name:string,source:string,provider:string,updated_at:int}> */
    public static function items(
        string $context = 'page',
        bool $includeRemote = false,
        bool $refreshRemote = false
    ): array
    {
        self::assertContext($context);
        BuilderRegistry::boot();

        $items = [];
        if (db()->tableExists('blox_templates')) {
            foreach (bloxTemplateModel()->publishedEditorCatalog() as $row) {
                $type = (string) ($row['type'] ?? '');
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0 || !self::supportsEditorType($type)
                    || !self::requirementsAvailable($row['requirements'] ?? null)) {
                    continue;
                }
                $items[] = [
                    'key' => 'local:' . $id,
                    'type' => $type,
                    'name' => (string) ($row['name'] ?? ''),
                    'source' => 'local',
                    'provider' => (string) ($row['source'] ?? 'user'),
                    'updated_at' => (int) ($row['updated_at'] ?? 0),
                ];
            }
        }

        foreach (BloxPluginRegistry::templates($context) as $template) {
            $slug = trim((string) ($template['plugin'] ?? ''));
            $key = trim((string) ($template['key'] ?? ''));
            $type = trim((string) ($template['type'] ?? ''));
            if (!self::validProviderPart($slug) || !self::validProviderPart($key)
                || !self::supportsEditorType($type)) {
                continue;
            }
            $items[] = [
                'key' => 'plugin:' . $slug . ':' . $key,
                'type' => $type,
                'name' => trim((string) ($template['name'] ?? $key)),
                'source' => 'plugin',
                'provider' => $slug,
                'updated_at' => 0,
            ];
        }

        if ($includeRemote) {
            self::$remoteError = '';
            try {
                foreach ((new BloxRemoteTemplateProvider())->items($context, $refreshRemote) as $item) {
                    $items[] = $item;
                }
            } catch (Throwable $e) {
                self::$remoteError = $e->getMessage();
                error_log('[BloxTemplateCatalog] Remote provider: ' . $e->getMessage());
            }
        }

        return $items;
    }

    public static function remoteError(): string
    {
        return self::$remoteError;
    }

    public static function supportsEditorType(string $type): bool
    {
        return in_array($type, self::EDITOR_TYPES, true);
    }

    /** @return array{key:string,type:string,name:string,source:string,provider:string,sections:array<int,array<string,mixed>>} */
    public static function resolve(string $key, string $context = 'page'): array
    {
        self::assertContext($context);
        BuilderRegistry::boot();

        if (preg_match('/^local:(\d+)$/', $key, $match) === 1) {
            return self::resolveLocal((int) $match[1], $key);
        }
        if (preg_match('/^plugin:([a-z0-9][a-z0-9-]*):([a-zA-Z0-9][a-zA-Z0-9._-]*)$/', $key, $match) === 1) {
            return self::resolvePlugin($match[1], $match[2], $key, $context);
        }
        if (preg_match('/^remote:([a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?)$/', $key, $match) === 1) {
            return (new BloxRemoteTemplateProvider())->resolve($match[1], $context);
        }

        throw new RuntimeException(__('blox_tpl_bad_key'));
    }

    /** @return array{key:string,type:string,name:string,source:string,provider:string,sections:array<int,array<string,mixed>>} */
    private static function resolveLocal(int $id, string $key): array
    {
        if (!db()->tableExists('blox_templates')) {
            throw new RuntimeException(__('blox_tpl_lib_uninit'));
        }
        $row = bloxTemplateModel()->findPublishedForEditor($id);
        if (!$row) {
            throw new RuntimeException(__('blox_tpl_not_published'));
        }

        if (!self::requirementsAvailable($row['requirements'] ?? null)) {
            throw new RuntimeException(__('blox_tpl_deps_unavailable'));
        }

        $validated = BloxDocumentPipeline::process((string) ($row['published_data'] ?? ''), 'template_validate');
        $processed = self::processFresh(
            $validated['sections'],
            'template_' . $id . '_' . bin2hex(random_bytes(4))
        );

        return [
            'key' => $key,
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'source' => 'local',
            'provider' => (string) ($row['source'] ?? 'user'),
            'sections' => $processed['sections'],
        ];
    }

    /** @return array{key:string,type:string,name:string,source:string,provider:string,sections:array<int,array<string,mixed>>} */
    private static function resolvePlugin(string $slug, string $templateKey, string $key, string $context): array
    {
        foreach (BloxPluginRegistry::templates($context) as $template) {
            if ((string) ($template['plugin'] ?? '') !== $slug
                || (string) ($template['key'] ?? '') !== $templateKey) {
                continue;
            }
            $type = trim((string) ($template['type'] ?? ''));
            if (!self::supportsEditorType($type)) {
                break;
            }
            $sections = self::providerSections($template, $type);
            $processed = self::processFresh(
                $sections,
                'template_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $slug . '_' . $templateKey)
                    . '_' . bin2hex(random_bytes(4))
            );

            return [
                'key' => $key,
                'type' => $type,
                'name' => trim((string) ($template['name'] ?? $templateKey)),
                'source' => 'plugin',
                'provider' => $slug,
                'sections' => $processed['sections'],
            ];
        }

        throw new RuntimeException(__('blox_tpl_plugin_missing'));
    }

    /** @param array<string,mixed> $template @return array<int,mixed> */
    private static function providerSections(array $template, string $type): array
    {
        $document = $template['sections'] ?? $template['document'] ?? $template['data'] ?? [];
        if (!is_array($document)) {
            throw new RuntimeException(__('blox_tpl_plugin_invalid'));
        }
        if ($type === 'section' && array_key_exists('columns', $document)) {
            return [$document];
        }
        return BloxDocumentPipeline::extractSections($document);
    }

    /** @param array<int,mixed> $sections @return array{sections:array<int,array<string,mixed>>,json:string} */
    private static function processFresh(array $sections, string $prefix): array
    {
        $json = json_encode(
            BloxDocumentPipeline::withoutNodeIds($sections),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        return BloxDocumentPipeline::process($json, $prefix);
    }

    private static function assertContext(string $context): void
    {
        if (!in_array($context, self::CONTEXTS, true)) {
            throw new InvalidArgumentException(__('blox_tpl_bad_context'));
        }
    }

    private static function validProviderPart(string $value): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}$/', $value) === 1;
    }

    private static function requirementsAvailable(mixed $raw): bool
    {
        if (!is_string($raw) || trim($raw) === '') {
            return true;
        }
        try {
            $requirements = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($requirements)) {
            return false;
        }

        foreach (is_array($requirements['elements'] ?? null) ? $requirements['elements'] : [] as $type) {
            if (!is_string($type) || BuilderRegistry::get($type) === null) {
                return false;
            }
        }

        $requiredPlugins = is_array($requirements['plugins'] ?? null) ? $requirements['plugins'] : [];
        if ($requiredPlugins === []) {
            return true;
        }
        try {
            $activePlugins = array_fill_keys(pluginModel()->getActiveSlugs(), true);
        } catch (Throwable) {
            return false;
        }
        foreach ($requiredPlugins as $slug) {
            if (!is_string($slug) || !isset($activePlugins[$slug])) {
                return false;
            }
        }
        return true;
    }
}