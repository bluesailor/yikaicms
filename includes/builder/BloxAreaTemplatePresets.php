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
            'feature_keys' => ['blox_header_feature_content_width', 'blox_header_feature_mega_menu'],
        ],
        'full-width-site-header' => [
            'type' => 'header',
            'file' => 'full-width-site-header.json',
            'name_key' => 'blox_area_preset_full_width_header_name',
            'description_key' => 'blox_area_preset_full_width_header_desc',
            'preview' => 'viewport-left',
            'feature_keys' => ['blox_header_feature_full_width', 'blox_header_feature_mega_menu'],
        ],
        'centered-site-header' => [
            'type' => 'header',
            'file' => 'centered-site-header.json',
            'name_key' => 'blox_area_preset_centered_header_name',
            'description_key' => 'blox_area_preset_centered_header_desc',
            'preview' => 'centered-brand',
            'feature_keys' => ['blox_header_feature_centered_brand', 'blox_header_feature_single_row'],
        ],
        'corporate-site-header' => [
            'type' => 'header',
            'file' => 'corporate-site-header.json',
            'name_key' => 'blox_area_preset_corporate_header_name',
            'description_key' => 'blox_area_preset_corporate_header_desc',
            'preview' => 'corporate',
            'feature_keys' => ['blox_header_feature_dark_topbar', 'blox_header_feature_sticky', 'blox_header_feature_search'],
        ],
        'topbar-site-header' => [
            'type' => 'header',
            'file' => 'topbar-site-header.json',
            'name_key' => 'blox_area_preset_topbar_header_name',
            'description_key' => 'blox_area_preset_topbar_header_desc',
            'preview' => 'topbar',
            'feature_keys' => ['blox_header_feature_light_topbar', 'blox_header_feature_language', 'blox_header_feature_mobile_compact'],
        ],
        'search-site-header' => [
            'type' => 'header',
            'file' => 'search-site-header.json',
            'name_key' => 'blox_area_preset_search_header_name',
            'description_key' => 'blox_area_preset_search_header_desc',
            'preview' => 'search',
            'feature_keys' => ['blox_header_feature_search', 'blox_header_feature_two_rows', 'blox_header_feature_language'],
        ],
        'clean-site-footer' => [
            'type' => 'footer',
            'file' => 'clean-site-footer.json',
            'name_key' => 'blox_area_preset_footer_name',
            'description_key' => 'blox_area_preset_footer_desc',
            'preview' => 'footer-columns',
            'feature_keys' => ['blox_footer_feature_light', 'blox_footer_feature_navigation', 'blox_footer_feature_legal'],
        ],
        'business-site-footer' => [
            'type' => 'footer',
            'file' => 'business-site-footer.json',
            'name_key' => 'blox_area_preset_business_footer_name',
            'description_key' => 'blox_area_preset_business_footer_desc',
            'preview' => 'footer-columns-dark',
            'feature_keys' => ['blox_footer_feature_dark', 'blox_footer_feature_navigation', 'blox_footer_feature_legal'],
        ],
        'minimal-site-footer' => [
            'type' => 'footer',
            'file' => 'minimal-site-footer.json',
            'name_key' => 'blox_area_preset_minimal_footer_name',
            'description_key' => 'blox_area_preset_minimal_footer_desc',
            'preview' => 'footer-compact',
            'feature_keys' => ['blox_footer_feature_light', 'blox_footer_feature_compact', 'blox_footer_feature_legal'],
        ],
        'corporate-site-footer' => [
            'type' => 'footer',
            'file' => 'corporate-site-footer.json',
            'name_key' => 'blox_area_preset_corporate_footer_name',
            'description_key' => 'blox_area_preset_corporate_footer_desc',
            'preview' => 'footer-columns-dark',
            'feature_keys' => ['blox_footer_feature_dark', 'blox_footer_feature_contact', 'blox_footer_feature_social'],
        ],
        'compact-site-footer' => [
            'type' => 'footer',
            'file' => 'compact-site-footer.json',
            'name_key' => 'blox_area_preset_compact_footer_name',
            'description_key' => 'blox_area_preset_compact_footer_desc',
            'preview' => 'footer-compact',
            'feature_keys' => ['blox_footer_feature_compact', 'blox_footer_feature_social', 'blox_footer_feature_legal'],
        ],
        'contact-site-footer' => [
            'type' => 'footer',
            'file' => 'contact-site-footer.json',
            'name_key' => 'blox_area_preset_contact_footer_name',
            'description_key' => 'blox_area_preset_contact_footer_desc',
            'preview' => 'footer-contact',
            'feature_keys' => ['blox_footer_feature_contact', 'blox_footer_feature_mobile_stack', 'blox_footer_feature_social'],
        ],
        'search-site-footer' => [
            'type' => 'footer',
            'file' => 'search-site-footer.json',
            'name_key' => 'blox_area_preset_search_footer_name',
            'description_key' => 'blox_area_preset_search_footer_desc',
            'preview' => 'footer-search',
            'feature_keys' => ['blox_footer_feature_search', 'blox_footer_feature_navigation', 'blox_footer_feature_contact'],
        ],
    ];

    /** @return list<array{slug:string,type:string,name:string,description:string,preview:string,features:list<string>}> */
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
                'features' => array_map(
                    static fn(string $key): string => __($key),
                    $preset['feature_keys'] ?? []
                ),
            ];
        }
        return $items;
    }

    /**
     * 返回内置区域模板的当前语言显示名称；用户自建/远程模板保留原名。
     *
     * 数据库中的内置模板名称是导入包的稳定英文标识，不能直接当作界面文案。
     * 只按 source + source_ref + type 命中，避免误翻译用户恰好使用相同 source_ref 的模板。
     *
     * @param array<string,mixed> $template
     */
    public static function displayName(array $template): string
    {
        $fallback = trim((string) ($template['name'] ?? ''));
        $slug = trim((string) ($template['source_ref'] ?? ''));
        $preset = self::PRESETS[$slug] ?? null;
        if (($template['source'] ?? '') !== 'builtin'
            || !is_array($preset)
            || (string) ($preset['type'] ?? '') !== (string) ($template['type'] ?? '')) {
            return $fallback;
        }

        return __((string) $preset['name_key']);
    }

    /**
     * 编辑器直接读取随包预置，避免要求用户先把它们安装成数据库模板。
     *
     * @return list<array{
     *   slug:string,type:string,name:string,description:string,preview:string,features:list<string>,
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
                'features' => array_map(
                    static fn(string $key): string => __($key),
                    $preset['feature_keys'] ?? []
                ),
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
