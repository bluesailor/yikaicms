<?php
/** 随 CMS 提供的 Header/Footer 起步模板清单与幂等安装入口。 */

declare(strict_types=1);

final class BloxAreaTemplatePresets
{
    private const PRESETS = [
        'clean-site-header' => [
            'type' => 'header',
            'file' => 'clean-site-header.json',
            'name_key' => 'blox_area_preset_header_name',
            'description_key' => 'blox_area_preset_header_desc',
            'preview' => 'content-left',
        ],
        'full-width-site-header' => [
            'type' => 'header',
            'file' => 'full-width-site-header.json',
            'name_key' => 'blox_area_preset_full_width_header_name',
            'description_key' => 'blox_area_preset_full_width_header_desc',
            'preview' => 'viewport-left',
        ],
        'centered-site-header' => [
            'type' => 'header',
            'file' => 'centered-site-header.json',
            'name_key' => 'blox_area_preset_centered_header_name',
            'description_key' => 'blox_area_preset_centered_header_desc',
            'preview' => 'centered-brand',
        ],
        'corporate-site-header' => [
            'type' => 'header',
            'file' => 'corporate-site-header.json',
            'name_key' => 'blox_area_preset_corporate_header_name',
            'description_key' => 'blox_area_preset_corporate_header_desc',
            'preview' => 'corporate',
        ],
        'topbar-site-header' => [
            'type' => 'header',
            'file' => 'topbar-site-header.json',
            'name_key' => 'blox_area_preset_topbar_header_name',
            'description_key' => 'blox_area_preset_topbar_header_desc',
            'preview' => 'topbar',
        ],
        'search-site-header' => [
            'type' => 'header',
            'file' => 'search-site-header.json',
            'name_key' => 'blox_area_preset_search_header_name',
            'description_key' => 'blox_area_preset_search_header_desc',
            'preview' => 'search',
        ],
        'clean-site-footer' => [
            'type' => 'footer',
            'file' => 'clean-site-footer.json',
            'name_key' => 'blox_area_preset_footer_name',
            'description_key' => 'blox_area_preset_footer_desc',
            'preview' => 'footer-columns',
        ],
        'corporate-site-footer' => [
            'type' => 'footer',
            'file' => 'corporate-site-footer.json',
            'name_key' => 'blox_area_preset_corporate_footer_name',
            'description_key' => 'blox_area_preset_corporate_footer_desc',
            'preview' => 'footer-columns-dark',
        ],
    ];

    /** @return list<array{slug:string,type:string,name:string,description:string,preview:string}> */
    public static function catalog(): array
    {
        $items = [];
        foreach (self::PRESETS as $slug => $preset) {
            if (!is_file(self::packagePath($preset['file']))) {
                continue;
            }
            $items[] = [
                'slug' => $slug,
                'type' => $preset['type'],
                'name' => __($preset['name_key']),
                'description' => __($preset['description_key']),
                'preview' => (string) ($preset['preview'] ?? ''),
            ];
        }
        return $items;
    }

    /**
     * 编辑器直接读取随包预置，避免要求用户先把它们安装成数据库模板。
     *
     * @return list<array{
     *   slug:string,type:string,name:string,description:string,preview:string,
     *   settings:array<string,mixed>,sections:array<int,array<string,mixed>>
     * }>
     * @psalm-suppress PossiblyUnusedMethod 独立后台入口 admin/blox_editor.php 在 Psalm 扫描图之外调用
     */
    public static function editorCatalog(string $type): array
    {
        if (!in_array($type, ['header', 'footer'], true)) {
            return [];
        }

        $items = [];
        foreach (self::PRESETS as $slug => $preset) {
            if ($preset['type'] !== $type) {
                continue;
            }
            $json = file_get_contents(self::packagePath($preset['file']));
            if (!is_string($json)) {
                continue;
            }
            try {
                $prepared = BloxTemplateImporter::prepare($json);
                $document = BloxAreaDocument::decode($type, $prepared['draft_json']);
            } catch (Throwable $e) {
                error_log('[BloxAreaTemplatePresets] ' . $slug . ': ' . $e->getMessage());
                continue;
            }
            $items[] = [
                'slug' => $slug,
                'type' => $type,
                'name' => __($preset['name_key']),
                'description' => __($preset['description_key']),
                'preview' => (string) ($preset['preview'] ?? ''),
                'settings' => $document['settings'],
                'sections' => $document['sections'],
            ];
        }
        return $items;
    }

    /** @return array{id:int,type:string,name:string,sections:int,updated:bool} */
    public static function install(string $slug, int $adminId = 0): array
    {
        $preset = self::PRESETS[$slug] ?? null;
        if ($preset === null) {
            throw new RuntimeException(__('blox_area_preset_not_found'));
        }
        $json = file_get_contents(self::packagePath($preset['file']));
        if (!is_string($json)) {
            throw new RuntimeException(__('blox_area_preset_unreadable'));
        }
        $prepared = BloxTemplateImporter::prepare($json);
        if ($prepared['type'] !== $preset['type']) {
            throw new RuntimeException(__('blox_area_preset_type_mismatch'));
        }

        $existing = bloxTemplateModel()->findWhere(['source' => 'builtin', 'source_ref' => $slug]);
        if ($existing) {
            bloxTemplateModel()->updateDraft(
                (int) $existing['id'],
                $prepared['draft_json'],
                $prepared['requirements']
            );
            return [
                'id' => (int) $existing['id'],
                'type' => $prepared['type'],
                'name' => $prepared['name'],
                'sections' => count($prepared['sections']),
                'updated' => true,
            ];
        }

        $result = BloxTemplateImporter::importJson($json, $adminId, 'builtin', $slug);
        return $result + ['updated' => false];
    }

    private static function packagePath(string $file): string
    {
        return dirname(__DIR__, 2) . '/templates/blox/areas/' . $file;
    }
}
