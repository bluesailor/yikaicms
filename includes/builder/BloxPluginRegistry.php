<?php
/** Blox 插件元素、模板来源与停用占位声明。 */

declare(strict_types=1);

final class BloxPluginRegistry
{
    /** @var array<string,array{plugin:string,label:string,container:bool,allowedChildren:list<string>,genericChild:bool}> */
    private static array $elements = [];
    /** @var array<string,callable(string):array<int,array<string,mixed>>> */
    private static array $templateProviders = [];
    /** @var array<string,true> */
    private static array $activeTypes = [];
    private static bool $discovered = false;

    /** @param array<string,mixed> $meta */
    public static function declareManifest(string $slug, array $meta): void
    {
        $blox = is_array($meta['blox'] ?? null) ? $meta['blox'] : [];
        foreach (is_array($blox['elements'] ?? null) ? $blox['elements'] : [] as $raw) {
            $item = is_string($raw) ? ['type' => $raw] : $raw;
            if (!is_array($item)) {
                continue;
            }
            $type = trim((string) ($item['type'] ?? ''));
            if (!self::validType($slug, $type)) {
                continue;
            }
            $allowed = is_array($item['allowedChildren'] ?? null)
                ? array_values(array_filter(array_map('strval', $item['allowedChildren'])))
                : [];
            self::$elements[$type] = [
                'plugin' => $slug,
                'label' => trim((string) ($item['label'] ?? $type)),
                'container' => !empty($item['container']),
                'allowedChildren' => $allowed,
                'genericChild' => !array_key_exists('genericChild', $item) || !empty($item['genericChild']),
            ];
        }
    }

    public static function registerElement(string $slug, AbstractElement $element): void
    {
        if (!self::validType($slug, $element->type())) {
            throw new InvalidArgumentException(__('blox_plugin_bad_type', ['slug' => $slug]));
        }
        self::$activeTypes[$element->type()] = true;
        self::$elements[$element->type()] = [
            'plugin' => $slug,
            'label' => $element->label(),
            'container' => $element->isContainer(),
            'allowedChildren' => $element->allowedChildren($element->defaults()),
            'genericChild' => $element->canBeGenericChild(),
        ];
        BuilderRegistry::register($element);
    }

    /** @param callable(string):array<int,array<string,mixed>> $provider */
    public static function registerTemplateProvider(string $slug, callable $provider): void
    {
        self::assertSlug($slug);
        self::$templateProviders[$slug] = $provider;
    }

    /** @return array<int,array<string,mixed>> */
    public static function templates(string $context = 'page'): array
    {
        $result = [];
        foreach (self::$templateProviders as $slug => $provider) {
            try {
                $items = $provider($context);
            } catch (Throwable $e) {
                error_log('Blox template provider failed [' . $slug . ']: ' . $e->getMessage());
                continue;
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    $item['source'] = 'plugin';
                    $item['plugin'] = $slug;
                    $result[] = $item;
                }
            }
        }
        return $result;
    }

    /** @return array{plugin:string,label:string,container:bool,allowedChildren:list<string>,genericChild:bool}|null */
    public static function declaration(string $type): ?array
    {
        self::discover();
        return self::$elements[$type] ?? null;
    }

    public static function ownerOf(string $type): ?string
    {
        return self::declaration($type)['plugin'] ?? null;
    }

    /** @param list<string> $registeredTypes @return array<string,array<string,mixed>> */
    public static function missingElementMeta(array $registeredTypes): array
    {
        self::discover();
        $registered = array_fill_keys($registeredTypes, true);
        $result = [];
        foreach (self::$elements as $type => $item) {
            if (isset($registered[$type]) || isset(self::$activeTypes[$type])) {
                continue;
            }
            $result[$type] = [
                'type' => $type, 'label' => $item['label'], 'category' => 'missing',
                'icon' => 'plug-off', 'controls' => [], 'defaults' => [], 'dynamic' => false,
                'container' => $item['container'], 'paletteVisible' => false,
                'allowedChildren' => $item['allowedChildren'], 'childRules' => [],
                'genericChild' => $item['genericChild'], 'supportsBoxStyles' => false,
                'scripts' => [], 'styles' => [], 'treeLabelField' => null,
                'deprecated' => false, 'missing' => true, 'plugin' => $item['plugin'],
            ];
        }
        return $result;
    }

    public static function discover(): void
    {
        if (self::$discovered || !defined('ROOT_PATH')) {
            return;
        }
        self::$discovered = true;
        foreach (glob(ROOT_PATH . '/plugins/*/plugin.json') ?: [] as $file) {
            $slug = basename(dirname($file));
            $raw = file_get_contents($file);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($meta)) {
                self::declareManifest($slug, $meta);
            }
        }
    }

    /** @psalm-suppress PossiblyUnusedMethod Used by isolated contract tests. */
    public static function resetForTests(): void
    {
        self::$elements = [];
        self::$templateProviders = [];
        self::$activeTypes = [];
        self::$discovered = false;
    }

    private static function validType(string $slug, string $type): bool
    {
        try {
            self::assertSlug($slug);
        } catch (InvalidArgumentException) {
            return false;
        }
        return preg_match('/^' . preg_quote($slug, '/') . '\/[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $type) === 1;
    }

    private static function assertSlug(string $slug): void
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug) !== 1) {
            throw new InvalidArgumentException(__('blox_plugin_bad_slug', ['slug' => $slug]));
        }
    }
}
