<?php
/** Blox site-wide color tokens and named element style presets. */

declare(strict_types=1);

final class BloxDesignSystem
{
    public const SETTING_KEY = 'blox_design_system';
    public const SCHEMA_VERSION = 1;
    private const MAX_TOKENS = 48;
    private const MAX_STYLES = 32;
    private const ID_PATTERN = '/^[a-z][a-z0-9_-]{0,47}$/';
    private const RADIUS_MAP = [
        'none' => '',
        'sm' => '0.25rem',
        'md' => '0.5rem',
        'lg' => '0.75rem',
        'full' => '9999px',
    ];

    private static bool $booted = false;

    public static function bootstrap(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (function_exists('add_action')) {
            add_action('ik_head', static function (): void {
                echo self::styleTag();
            }, 4);
        }
    }

    /** @return array{schema:int,revision:int,tokens:list<array<string,mixed>>,styles:list<array<string,mixed>>} */
    public static function snapshot(): array
    {
        return self::fromRaw(
            (string) config(self::SETTING_KEY, ''),
            (string) config('primary_color', '#3B82F6'),
            (string) config('secondary_color', '#1D4ED8')
        );
    }

    /**
     * Pure decoder used by runtime and tests. System colors are projected into the
     * catalog instead of duplicated into JSON, so the existing site color setting
     * remains the single owner of primary/secondary.
     *
     * @return array{schema:int,revision:int,tokens:list<array<string,mixed>>,styles:list<array<string,mixed>>}
     */
    public static function fromRaw(string $raw, string $primary, string $secondary): array
    {
        $decoded = json_decode($raw, true);
        $state = is_array($decoded) ? $decoded : [];
        $tokens = [];
        foreach (is_array($state['tokens'] ?? null) ? $state['tokens'] : self::seedTokens() as $item) {
            $token = self::normalizeToken($item);
            if ($token !== null
                && !in_array($token['id'], ['primary', 'secondary'], true)
                && !isset($tokens[$token['id']])
                && count($tokens) < self::MAX_TOKENS) {
                $tokens[$token['id']] = $token;
            }
        }
        $styles = [];
        foreach (is_array($state['styles'] ?? null) ? $state['styles'] : [] as $item) {
            $style = self::normalizeStyle($item);
            if ($style !== null && !isset($styles[$style['id']]) && count($styles) < self::MAX_STYLES) {
                $styles[$style['id']] = $style;
            }
        }

        $primary = self::concreteColor($primary) ?? '#3b82f6';
        $secondary = self::concreteColor($secondary) ?? '#1d4ed8';
        $system = [
            self::systemToken('primary', __('blox_token_primary'), $primary),
            self::systemToken('secondary', __('blox_token_secondary'), $secondary),
        ];

        return [
            'schema' => self::SCHEMA_VERSION,
            'revision' => max(1, (int) ($state['revision'] ?? 1)),
            'tokens' => array_values(array_merge(array_column($system, null, 'id'), $tokens)),
            'styles' => array_values($styles),
        ];
    }

    public static function styleTag(): string
    {
        $declarations = '';
        foreach (self::snapshot()['tokens'] as $token) {
            $id = (string) ($token['id'] ?? '');
            $value = self::concreteColor($token['value'] ?? null);
            // Archived tokens remain emitted as tombstones. Existing documents keep
            // rendering while the token is hidden from new selections.
            if (preg_match(self::ID_PATTERN, $id) === 1 && $value !== null) {
                $declarations .= '--yk-color-' . $id . ':' . $value . ';';
            }
        }
        return $declarations === '' ? '' : '<style id="yk-blox-design-tokens">:root{' . $declarations . '}</style>';
    }

    public static function colorReference(string $id): string
    {
        return preg_match(self::ID_PATTERN, $id) === 1 ? 'var(--yk-color-' . $id . ')' : '';
    }

    /** @return array<string,mixed>|null */
    public static function resolveStyle(string $id, mixed $fallback = null): ?array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return self::normalizeStyleSnapshot($fallback);
        }
        foreach (self::snapshot()['styles'] as $style) {
            if (($style['id'] ?? '') === $id) {
                return $style;
            }
        }
        return self::normalizeStyleSnapshot($fallback);
    }

    public static function styleDeclarations(string $id, mixed $fallback = null): string
    {
        $style = self::resolveStyle($id, $fallback);
        if ($style === null) {
            return '';
        }
        $css = '';
        foreach (['color' => 'color', 'background' => 'background-color', 'border_color' => 'border-color'] as $key => $property) {
            $value = AbstractElement::cssColor($style[$key] ?? null);
            if ($value !== null) {
                $css .= $property . ':' . $value . '!important;';
            }
        }
        if (AbstractElement::cssColor($style['border_color'] ?? null) !== null) {
            $css .= 'border-style:solid!important;border-width:1px!important;';
        }
        $radius = (string) ($style['radius'] ?? 'none');
        if (($value = self::RADIUS_MAP[$radius] ?? '') !== '') {
            $css .= 'border-radius:' . $value . '!important;';
        }
        return $css;
    }

    /** @param array<int,mixed> $sections */
    public static function assertSectionsAllowed(array $sections, ?bool $advanced = null): void
    {
        $hasPreset = false;
        foreach ($sections as $section) {
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        self::assertElement($element, $hasPreset);
                    }
                }
            }
        }
        if ($hasPreset && !($advanced ?? self::advancedEnabled())) {
            throw new RuntimeException(__('blox_global_style_license_required'));
        }
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function normalizeElementData(array $data): array
    {
        if (array_key_exists('_global_style', $data)) {
            $id = trim((string) $data['_global_style']);
            $data['_global_style'] = preg_match(self::ID_PATTERN, $id) === 1 ? $id : '';
        }
        if (array_key_exists('_global_style_snapshot', $data)) {
            $data['_global_style_snapshot'] = self::normalizeStyleSnapshot($data['_global_style_snapshot']) ?? [];
        }
        return $data;
    }

    /**
     * Apply one API mutation with optimistic revision checking.
     *
     * @param array<string,mixed> $input
     * @return array{schema:int,revision:int,tokens:list<array<string,mixed>>,styles:list<array<string,mixed>>}
     */
    public static function mutate(string $action, array $input, bool $advanced): array
    {
        $state = self::snapshot();
        $expected = (int) ($input['revision'] ?? 0);
        if ($expected > 0 && $expected !== $state['revision']) {
            throw new RuntimeException(__('blox_design_conflict'));
        }

        $isStyleAction = str_starts_with($action, 'style_');
        if ($isStyleAction && !$advanced) {
            throw new RuntimeException(__('blox_global_style_license_required'));
        }

        if (str_starts_with($action, 'token_')) {
            $state['tokens'] = self::mutateCollection($state['tokens'], substr($action, 6), $input, false);
        } elseif ($isStyleAction) {
            $state['styles'] = self::mutateCollection($state['styles'], substr($action, 6), $input, true);
        } else {
            throw new RuntimeException(__('blox_invalid_action'));
        }

        $state['revision']++;
        self::persist($state);
        return self::snapshot();
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $input @return list<array<string,mixed>> */
    private static function mutateCollection(array $items, string $operation, array $input, bool $style): array
    {
        // System colors are projected and never persisted or mutated here.
        $items = array_values(array_filter($items, static fn(array $item): bool => empty($item['system'])));
        if ($operation === 'add') {
            $limit = $style ? self::MAX_STYLES : self::MAX_TOKENS;
            if (count($items) >= $limit) {
                throw new RuntimeException(__('blox_design_limit'));
            }
            $input['id'] = ($style ? 's_' : 'c_') . bin2hex(random_bytes(6));
            $input['status'] = 'active';
            $input['locked'] = false;
            $input['version'] = 1;
            $item = $style ? self::normalizeStyle($input) : self::normalizeToken($input);
            if ($item === null) {
                throw new RuntimeException(__('blox_design_invalid'));
            }
            $items[] = $item;
            return $items;
        }

        $id = trim((string) ($input['id'] ?? ''));
        foreach ($items as $index => $item) {
            if (($item['id'] ?? '') !== $id) {
                continue;
            }
            if ($operation === 'lock') {
                $items[$index]['locked'] = !empty($input['locked']);
                $items[$index]['version'] = (int) $item['version'] + 1;
                return $items;
            }
            if ($operation === 'restore') {
                $items[$index]['status'] = 'active';
                $items[$index]['version'] = (int) $item['version'] + 1;
                return $items;
            }
            if (!empty($item['locked'])) {
                throw new RuntimeException(__('blox_design_locked'));
            }
            if ($operation === 'archive') {
                $items[$index]['status'] = 'archived';
                $items[$index]['version'] = (int) $item['version'] + 1;
                return $items;
            }
            if ($operation === 'update') {
                $candidate = array_merge($item, $input, [
                    'id' => $id,
                    'status' => $item['status'],
                    'locked' => $item['locked'],
                    'version' => (int) $item['version'] + 1,
                ]);
                $normalized = $style ? self::normalizeStyle($candidate) : self::normalizeToken($candidate);
                if ($normalized === null) {
                    throw new RuntimeException(__('blox_design_invalid'));
                }
                $items[$index] = $normalized;
                return $items;
            }
            throw new RuntimeException(__('blox_invalid_action'));
        }
        throw new RuntimeException(__('blox_design_not_found'));
    }

    /** @param array<string,mixed> $state */
    private static function persist(array $state): void
    {
        $payload = [
            'schema' => self::SCHEMA_VERSION,
            'revision' => max(1, (int) ($state['revision'] ?? 1)),
            'tokens' => array_values(array_filter(
                $state['tokens'],
                static fn(array $item): bool => empty($item['system'])
            )),
            'styles' => array_values($state['styles']),
        ];
        settingModel()->saveBatch([
            self::SETTING_KEY => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private static function seedTokens(): array
    {
        return [
            ['id' => 'heading', 'name' => __('blox_token_heading'), 'category' => 'neutral', 'value' => '#111827'],
            ['id' => 'text', 'name' => __('blox_token_text'), 'category' => 'neutral', 'value' => '#4b5563'],
            ['id' => 'surface', 'name' => __('blox_token_surface'), 'category' => 'surface', 'value' => '#ffffff'],
            ['id' => 'muted', 'name' => __('blox_token_muted'), 'category' => 'surface', 'value' => '#f3f4f6'],
        ];
    }

    /** @return array<string,mixed> */
    private static function systemToken(string $id, string $name, string $value): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'category' => 'brand',
            'value' => $value,
            'status' => 'active',
            'locked' => true,
            'system' => true,
            'version' => 1,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function normalizeToken(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 60);
        $value = self::concreteColor($item['value'] ?? null);
        if (preg_match(self::ID_PATTERN, $id) !== 1 || $name === '' || $value === null) {
            return null;
        }
        return [
            'id' => $id,
            'name' => $name,
            'category' => self::category($item['category'] ?? 'general'),
            'value' => $value,
            'status' => ($item['status'] ?? '') === 'archived' ? 'archived' : 'active',
            'locked' => !empty($item['locked']),
            'system' => !empty($item['system']),
            'version' => max(1, (int) ($item['version'] ?? 1)),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function normalizeStyle(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $id = trim((string) ($item['id'] ?? ''));
        $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 60);
        if (preg_match(self::ID_PATTERN, $id) !== 1 || $name === '') {
            return null;
        }
        $snapshot = self::normalizeStyleSnapshot($item) ?? [];
        return array_merge([
            'id' => $id,
            'name' => $name,
            'category' => self::category($item['category'] ?? 'general'),
        ], $snapshot, [
            'status' => ($item['status'] ?? '') === 'archived' ? 'archived' : 'active',
            'locked' => !empty($item['locked']),
            'version' => max(1, (int) ($item['version'] ?? 1)),
        ]);
    }

    /** @return array{color:string,background:string,border_color:string,radius:string}|null */
    public static function normalizeStyleSnapshot(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $out = [];
        foreach (['color', 'background', 'border_color'] as $key) {
            $raw = trim((string) ($item[$key] ?? ''));
            if ($raw !== '' && AbstractElement::cssColor($raw) === null) {
                return null;
            }
            $out[$key] = $raw === '' ? '' : (string) AbstractElement::cssColor($raw);
        }
        $radius = (string) ($item['radius'] ?? 'none');
        $out['radius'] = array_key_exists($radius, self::RADIUS_MAP) ? $radius : 'none';
        return $out;
    }

    private static function concreteColor(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $color = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/', $color, $match) === 1) {
            return '#' . $match[1][0] . $match[1][0] . $match[1][1] . $match[1][1] . $match[1][2] . $match[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : null;
    }

    private static function category(mixed $value): string
    {
        $value = mb_substr(trim((string) $value), 0, 32);
        return $value !== '' ? $value : 'general';
    }

    private static function advancedEnabled(): bool
    {
        return function_exists('bloxAdvancedFeaturesEnabled')
            ? bloxAdvancedFeaturesEnabled()
            : BloxQueryLoopPolicy::advancedEnabled();
    }

    /** @param array<string,mixed> $element */
    private static function assertElement(array $element, bool &$hasPreset): void
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $id = trim((string) ($data['_global_style'] ?? ''));
        if ($id !== '') {
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new RuntimeException(__('blox_design_invalid'));
            }
            $hasPreset = true;
        }
        if (array_key_exists('_global_style_snapshot', $data)
            && self::normalizeStyleSnapshot($data['_global_style_snapshot']) === null) {
            throw new RuntimeException(__('blox_design_invalid'));
        }
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::assertElement($child, $hasPreset);
            }
        }
    }
}
