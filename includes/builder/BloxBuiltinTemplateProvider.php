<?php
/** Read-only page templates shipped with YikaiCMS and shown in the local library. */

declare(strict_types=1);

final class BloxBuiltinTemplateProvider
{
    private const PRESETS = [
        '404-route-lost' => [
            'type' => 'page',
            'file' => '404-route-lost.json',
            'contexts' => ['page'],
            'name_key' => 'blox_builtin_404_name',
            'description_key' => 'blox_builtin_404_desc',
            'category' => 'page',
            'thumbnail' => '/assets/images/blox-templates/404-route-lost.png',
        ],
    ];

    /** @return list<array<string,mixed>> */
    public function items(string $context = 'page'): array
    {
        $items = [];
        foreach (self::PRESETS as $slug => $preset) {
            $path = self::packagePath((string) $preset['file']);
            if (!in_array($context, $preset['contexts'], true) || !is_file($path)) {
                continue;
            }
            $items[] = [
                'key' => 'builtin:' . $slug,
                'type' => (string) $preset['type'],
                'name' => __((string) $preset['name_key']),
                'description' => __((string) $preset['description_key']),
                'source' => 'builtin',
                'provider' => 'yikaicms',
                'category' => (string) $preset['category'],
                'thumbnail' => (string) $preset['thumbnail'],
                'updated_at' => (int) (filemtime($path) ?: 0),
            ];
        }
        return $items;
    }

    /** @return array{key:string,type:string,name:string,source:string,provider:string,sections:array<int,array<string,mixed>>} */
    public function resolve(string $slug, string $context = 'page'): array
    {
        $preset = self::PRESETS[$slug] ?? null;
        if ($preset === null || !in_array($context, $preset['contexts'], true)) {
            throw new RuntimeException(__('blox_builtin_template_not_found'));
        }
        $json = file_get_contents(self::packagePath((string) $preset['file']));
        if (!is_string($json)) {
            throw new RuntimeException(__('blox_builtin_template_unreadable'));
        }
        $prepared = BloxTemplateImporter::prepare($json);
        if ($prepared['type'] !== $preset['type']) {
            throw new RuntimeException(__('blox_builtin_template_invalid'));
        }

        return [
            'key' => 'builtin:' . $slug,
            'type' => (string) $preset['type'],
            'name' => __((string) $preset['name_key']),
            'source' => 'builtin',
            'provider' => 'yikaicms',
            'sections' => $prepared['sections'],
        ];
    }

    private static function packagePath(string $file): string
    {
        return dirname(__DIR__, 2) . '/templates/blox/pages/' . $file;
    }
}
