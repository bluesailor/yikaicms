<?php
/** Query Loop 免费预设与高级能力的服务端边界。 */

declare(strict_types=1);

final class BloxQueryLoopPolicy
{
    public static function advancedEnabled(): bool
    {
        if (function_exists('bloxAdvancedFeaturesEnabled')) {
            return bloxAdvancedFeaturesEnabled();
        }
        if (defined('DEBUG') && DEBUG) {
            return true;
        }
        if (!function_exists('license_valid') || !function_exists('license_has_module')) {
            return false;
        }
        return license_has_module('blox') || license_valid();
    }

    /** @param array<int,mixed> $sections */
    public static function assertSectionsAllowed(array $sections, ?bool $advanced = null): void
    {
        if ($advanced ?? self::advancedEnabled()) {
            return;
        }
        foreach ($sections as $section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        self::assertElementAllowed($element);
                    }
                }
            }
        }
    }

    public static function assertJsonAllowed(string $json, ?bool $advanced = null): void
    {
        if ($advanced ?? self::advancedEnabled()) {
            return;
        }
        $document = BloxDocumentPipeline::decode($json);
        self::assertSectionsAllowed($document['sections'], false);
    }

    /** @param array<string,mixed> $element */
    private static function assertElementAllowed(array $element): void
    {
        $type = (string) ($element['type'] ?? '');
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        if ($type === 'list-dynamic') {
            $hasCustomTemplate = !empty($data['children']) || !empty($data['template']);
            $hasPagination = (string) ($data['pagination_mode'] ?? 'none') !== 'none';
            if ($hasCustomTemplate || $hasPagination) {
                throw new RuntimeException(__('blox_query_loop_license_required'));
            }
        }
        if (DynamicSiteData::usesBinding($data)) {
            throw new RuntimeException(__('blox_query_loop_license_required'));
        }
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::assertElementAllowed($child);
            }
        }
    }
}
