<?php
declare(strict_types=1);

/** Compare protected authoring fields against server-owned document data only. */
final class BloxProtectedFields
{
    public static function forValidation(array $sections, array $trusted, array $denied): array
    {
        $before = [];
        $after = [];
        self::project($trusted, $denied, $before);
        $stripped = self::project($sections, $denied, $after);
        ksort($before);
        ksort($after);
        if ($before !== $after) {
            throw new RuntimeException(__('blox_protected_fields_changed'));
        }
        return $stripped;
    }

    private static function project(array $sections, array $denied, array &$protected): array
    {
        $seen = [];
        foreach ($sections as $si => &$section) {
            $sectionId = self::identity($section, $seen);
            if (in_array('display_conditions', $denied, true) && !empty($section['settings']['_conditions'])) {
                if ($sectionId === '') throw new RuntimeException(__('blox_protected_fields_changed'));
                $protected['section:' . $sectionId] = $section['settings']['_conditions'];
                unset($section['settings']['_conditions']);
            }
            foreach ($section['columns'] ?? [] as $ci => $column) {
                $columnId = (string) ($column['id'] ?? $ci);
                foreach ($column['elements'] ?? [] as $ei => $element) {
                    $section['columns'][$ci]['elements'][$ei] = self::element(
                        $element, $sectionId . '/' . $columnId, $denied, $protected, $seen
                    );
                }
            }
        }
        unset($section);
        return $sections;
    }

    private static function identity(array $node, array &$seen): string
    {
        $id = (string) ($node['id'] ?? '');
        if ($id === '') return '';
        if (isset($seen[$id])) {
            throw new RuntimeException(__('blox_protected_fields_changed'));
        }
        $seen[$id] = true;
        return $id;
    }

    private static function element(array $element, string $parent, array $denied, array &$protected, array &$seen): array
    {
        $id = self::identity($element, $seen);
        $data = $element['data'] ?? [];
        $fields = [];
        $keys = [];
        if (in_array('display_conditions', $denied, true)) $keys[] = '_conditions';
        if (in_array('style_presets', $denied, true)) $keys = array_merge($keys, ['_global_style', '_global_style_snapshot']);
        if (in_array('query_loop', $denied, true)) {
            $keys = array_merge($keys, ['site_field', 'site_image_field', 'site_text_field', 'site_url_field']);
            if (($element['type'] ?? '') === 'list-dynamic') $keys = array_merge($keys, ['children', 'template', 'pagination_mode']);
        }
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== [] && $value !== 'none') $fields[$key] = $value;
            unset($data[$key]);
        }
        if ($fields !== []) {
            if ($id === '' || $parent === '' || str_starts_with($parent, '/')) throw new RuntimeException(__('blox_protected_fields_changed'));
            ksort($fields);
            $protected['element:' . $id] = ['parent' => $parent, 'type' => $element['type'], 'fields' => $fields];
        }
        foreach ($data['children'] ?? [] as $index => $child) {
            $data['children'][$index] = self::element($child, $id, $denied, $protected, $seen);
        }
        $element['data'] = $data;
        return $element;
    }
}
