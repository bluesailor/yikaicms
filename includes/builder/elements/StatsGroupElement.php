<?php
/** 通用数据统计组：响应式网格 + 统一数字滚动设置。 */

declare(strict_types=1);

final class StatsGroupElement extends AbstractElement
{

    public function type(): string { return 'stats-group'; }
    public function label(): string { return __('blox_el_stats_group'); }
    public function icon(): string { return 'chart-bar'; }
    public function category(): string { return 'advanced'; }
    public function isContainer(): bool { return true; }
    public function allowedChildren(array $data = []): array { return ['stat-item']; }

    public function controls(): array
    {
        return [
            ['key' => 'mobile_columns', 'type' => 'select', 'label' => __('blox_cols_mobile'), 'default' => '2', 'tab' => 'style',
                'options' => ['1' => __('blox_n_cols', ['n' => 1]), '2' => __('blox_n_cols', ['n' => 2])],
                'option_icons' => ['1' => 'rectangle', '2' => 'columns-2']],
            ['key' => 'tablet_columns', 'type' => 'select', 'label' => __('blox_cols_tablet'), 'default' => '4', 'tab' => 'style',
                'options' => ['2' => __('blox_n_cols', ['n' => 2]), '3' => __('blox_n_cols', ['n' => 3]), '4' => __('blox_n_cols', ['n' => 4])]],
            ['key' => 'desktop_columns', 'type' => 'select', 'label' => __('blox_cols_desktop'), 'default' => '4', 'tab' => 'style',
                'options' => ['2' => __('blox_n_cols', ['n' => 2]), '3' => __('blox_n_cols', ['n' => 3]), '4' => __('blox_n_cols', ['n' => 4]), '5' => __('blox_n_cols', ['n' => 5]), '6' => __('blox_n_cols', ['n' => 6])]],
            ['key' => 'gap', 'type' => 'select', 'label' => __('blox_item_gap'), 'default' => 'md', 'tab' => 'style',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')]],
            ['key' => 'counter_enabled', 'type' => 'checkbox', 'label' => __('blox_counter_on_view'), 'default' => true],
            ['key' => 'counter_start', 'type' => 'number', 'label' => __('blox_counter_start'), 'default' => 0, 'min' => 0, 'max' => 999999],
            ['key' => 'counter_duration', 'type' => 'number', 'label' => __('blox_counter_duration'), 'default' => 0, 'min' => 0, 'max' => 5000, 'step' => 100,
                'placeholder' => __('blox_zero_auto')],
        ];
    }

    public function scripts(): array
    {
        return ['/assets/js/blox-counter.js'];
    }

    public function scriptsFor(array $data): array
    {
        return !array_key_exists('counter_enabled', $data) || !empty($data['counter_enabled'])
            ? $this->scripts()
            : [];
    }

    public function defaultChildren(): array
    {
        return [
            ['type' => 'stat-item', 'data' => ['icon' => 'award', 'number' => '10+', 'label' => __('blox_stat_seed_years')]],
            ['type' => 'stat-item', 'data' => ['icon' => 'users', 'number' => '1000+', 'label' => __('home_stat_1')]],
            ['type' => 'stat-item', 'data' => ['icon' => 'briefcase', 'number' => '50+', 'label' => __('blox_stat_seed_projects')]],
            ['type' => 'stat-item', 'data' => ['icon' => 'thumb-up', 'number' => '100%', 'label' => __('blox_stat_seed_service')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $mobile = (string) ($data['mobile_columns'] ?? '2');
        $tablet = (string) ($data['tablet_columns'] ?? '4');
        $desktop = (string) ($data['desktop_columns'] ?? '4');
        $gap = (string) ($data['gap'] ?? 'md');
        $enabled = filter_var($data['counter_enabled'] ?? true, FILTER_VALIDATE_BOOL);
        $start = max(0, min(999999, (int) ($data['counter_start'] ?? 0)));
        $duration = max(0, min(5000, (int) ($data['counter_duration'] ?? 0)));

        $mobileClass = match ($mobile) {
            '1' => 'grid-cols-1',
            default => 'grid-cols-2',
        };
        $tabletClass = match ($tablet) {
            '2' => 'md:grid-cols-2',
            '3' => 'md:grid-cols-3',
            default => 'md:grid-cols-4',
        };
        $desktopClass = match ($desktop) {
            '2' => 'lg:grid-cols-2',
            '3' => 'lg:grid-cols-3',
            '5' => 'lg:grid-cols-5',
            '6' => 'lg:grid-cols-6',
            default => 'lg:grid-cols-4',
        };
        $gapClass = match ($gap) {
            'sm' => 'gap-4',
            'lg' => 'gap-12',
            default => 'gap-8',
        };
        $class = 'yk-stats-group grid ' . $mobileClass . ' ' . $tabletClass . ' ' . $desktopClass . ' ' . $gapClass;
        $counter = htmlspecialchars(json_encode([
            'enabled' => $enabled,
            'start' => $start,
            'duration' => $duration,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ENT_QUOTES);

        return '<div class="' . $class . '" data-stagger data-blox-counter="' . $counter . '">'
            . $children . '</div>';
    }
}
