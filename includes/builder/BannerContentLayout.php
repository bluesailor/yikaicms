<?php

declare(strict_types=1);

/** Shared layout contract for theme Banner content, independent of slide animation. */
final class BannerContentLayout
{
    private const POSITIONS = [
        'top-left' => ['flex-start', 'flex-start', 'arrow-up-left'],
        'top-center' => ['flex-start', 'center', 'arrow-up'],
        'top-right' => ['flex-start', 'flex-end', 'arrow-up-right'],
        'center-left' => ['center', 'flex-start', 'arrow-left'],
        'center-center' => ['center', 'center', 'point'],
        'center-right' => ['center', 'flex-end', 'arrow-right'],
        'bottom-left' => ['flex-end', 'flex-start', 'arrow-down-left'],
        'bottom-center' => ['flex-end', 'center', 'arrow-down'],
        'bottom-right' => ['flex-end', 'flex-end', 'arrow-down-right'],
    ];

    /** @return list<array<string, mixed>> */
    public static function controls(bool $item = false): array
    {
        $controls = [];
        foreach (['desktop', 'mobile'] as $device) {
            $prefix = 'banner_layout_' . $device . '_';
            $required = $item ? [] : ['required' => ['block_type', '=', 'banner']];
            $controls[] = array_merge([
                'key' => $prefix . 'enabled', 'type' => 'checkbox', 'default' => false,
                'label' => __('blox_banner_layout_' . $device),
                'help' => __($item ? 'blox_banner_layout_item_help' : 'blox_banner_layout_group_help'),
            ], $required);
            $options = $icons = [];
            foreach (self::POSITIONS as $position => $values) {
                $options[$position] = __('blox_banner_position_' . str_replace('-', '_', $position));
                $icons[$position] = $values[2];
            }
            $fields = [
                ['key' => 'position', 'type' => 'select', 'label' => __('blox_banner_layout_position'),
                    'default' => 'center-center', 'options' => $options, 'option_icons' => $icons, 'option_columns' => 3],
                ['key' => 'x', 'type' => 'number', 'label' => __('blox_banner_layout_x'),
                    'default' => 0, 'min' => $device === 'mobile' ? -48 : -160, 'max' => $device === 'mobile' ? 48 : 160, 'step' => 4],
                ['key' => 'y', 'type' => 'number', 'label' => __('blox_banner_layout_y'),
                    'default' => 0, 'min' => $device === 'mobile' ? -48 : -160, 'max' => $device === 'mobile' ? 48 : 160, 'step' => 4],
                ['key' => 'width', 'type' => 'number', 'label' => __('blox_banner_layout_width'),
                    'default' => $device === 'mobile' ? 320 : 720, 'min' => 200, 'max' => 1200, 'step' => 20],
                ['key' => 'align', 'type' => 'select', 'label' => __('blox_banner_layout_align'),
                    'default' => 'center', 'options' => [
                        'left' => __('blox_banner_align_left'), 'center' => __('blox_banner_align_center'), 'right' => __('blox_banner_align_right')],
                    'option_icons' => ['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right']],
                ['key' => 'buttons', 'type' => 'select', 'label' => __('blox_banner_layout_buttons'),
                    'default' => 'center', 'options' => [
                        'left' => __('blox_banner_align_left'), 'center' => __('blox_banner_align_center'), 'right' => __('blox_banner_align_right')],
                    'option_icons' => ['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right']],
                ['key' => 'gap', 'type' => 'number', 'label' => __('blox_banner_layout_gap'),
                    'default' => 24, 'min' => 0, 'max' => 96, 'step' => 4],
            ];
            foreach ($fields as $field) {
                $field['key'] = $prefix . $field['key'];
                // Checkbox values can be true in the editor and 1 after persistence.
                $terms = [[$prefix . 'enabled', 'in', [true, 1, '1']]];
                if (!$item) {
                    $terms[] = ['block_type', '=', 'banner'];
                }
                $field['visible_when'] = ['terms' => $terms];
                $controls[] = array_merge($field, $required);
            }
        }
        return $controls;
    }

    /** @return array<string, int|string|bool> */
    public static function normalize(array $data): array
    {
        $result = [];
        foreach (['desktop', 'mobile'] as $device) {
            $prefix = 'banner_layout_' . $device . '_';
            $result[$prefix . 'enabled'] = in_array($data[$prefix . 'enabled'] ?? false, [true, 1, '1'], true);
            $position = $data[$prefix . 'position'] ?? null;
            $result[$prefix . 'position'] = is_string($position) && isset(self::POSITIONS[$position]) ? $position : 'center-center';
            foreach (['align', 'buttons'] as $key) {
                $value = $data[$prefix . $key] ?? null;
                $result[$prefix . $key] = in_array($value, ['left', 'center', 'right'], true) ? $value : 'center';
            }
            $maxOffset = $device === 'mobile' ? 48 : 160;
            foreach (['x' => [-$maxOffset, $maxOffset, 0], 'y' => [-$maxOffset, $maxOffset, 0],
                'width' => [200, 1200, $device === 'mobile' ? 320 : 720], 'gap' => [0, 96, 24]] as $key => $bounds) {
                $value = $data[$prefix . $key] ?? null;
                $result[$prefix . $key] = is_scalar($value) && is_numeric($value)
                    ? max($bounds[0], min($bounds[1], (int) $value)) : $bounds[2];
            }
        }
        return $result;
    }

    /**
     * Item overrides group per device; absent mobile overrides follow desktop.
     * @psalm-suppress PossiblyUnusedMethod Called by theme templates outside the analysis scope.
     */
    public static function attributes(array $item, array $group): string
    {
        $item = self::normalize($item);
        $group = self::normalize($group);
        $attributes = $style = '';
        $desktop = null;
        foreach (['desktop', 'mobile'] as $device) {
            $prefix = 'banner_layout_' . $device . '_';
            $source = $item[$prefix . 'enabled'] ? $item : ($group[$prefix . 'enabled'] ? $group : null);
            if ($source !== null) {
                $values = [];
                foreach (['position', 'x', 'y', 'width', 'align', 'buttons', 'gap'] as $key) {
                    $values[$key] = $source[$prefix . $key];
                }
            } else {
                $values = $device === 'mobile' ? $desktop : null;
            }
            if ($device === 'desktop') {
                $desktop = $values;
            }
            if ($values === null) {
                continue;
            }
            $attributes .= ' data-blox-layout-' . $device . '="1"';
            [$vertical, $horizontal] = self::POSITIONS[(string) $values['position']] ?? self::POSITIONS['center-center'];
            $variables = ['vertical' => $vertical, 'horizontal' => $horizontal,
                'align' => $values['align'], 'buttons' => match ($values['buttons']) {
                    'left' => 'flex-start', 'right' => 'flex-end', default => 'center',
                }];
            foreach (['x', 'y', 'width', 'gap'] as $key) {
                $value = (int) $values[$key];
                if ($device === 'mobile' && in_array($key, ['x', 'y'], true)) {
                    $value = max(-48, min(48, $value));
                }
                $variables[$key] = $value . 'px';
            }
            foreach ($variables as $key => $value) {
                $style .= '--blox-layout-' . $device . '-' . $key . ':' . $value . ';';
            }
        }
        return $attributes === '' ? '' : $attributes . ' style="' . htmlspecialchars($style, ENT_QUOTES) . '"';
    }
}
