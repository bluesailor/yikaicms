<?php
/** 通用数据统计组：响应式网格 + 统一数字滚动设置。 */

declare(strict_types=1);

final class StatsGroupElement extends AbstractElement
{

    public function type(): string { return 'stats-group'; }
    public function label(): string { return '数据统计'; }
    public function icon(): string { return 'chart-bar'; }
    public function category(): string { return 'advanced'; }
    public function isContainer(): bool { return true; }
    public function allowedChildren(array $data = []): array { return ['stat-item']; }

    public function controls(): array
    {
        return [
            ['key' => 'mobile_columns', 'type' => 'select', 'label' => '手机列数', 'default' => '2', 'tab' => 'style',
                'options' => ['1' => '1 列', '2' => '2 列'],
                'option_icons' => ['1' => 'rectangle', '2' => 'columns-2']],
            ['key' => 'tablet_columns', 'type' => 'select', 'label' => '平板列数', 'default' => '4', 'tab' => 'style',
                'options' => ['2' => '2 列', '3' => '3 列', '4' => '4 列']],
            ['key' => 'desktop_columns', 'type' => 'select', 'label' => '桌面列数', 'default' => '4', 'tab' => 'style',
                'options' => ['2' => '2 列', '3' => '3 列', '4' => '4 列', '5' => '5 列', '6' => '6 列']],
            ['key' => 'gap', 'type' => 'select', 'label' => '项目间距', 'default' => 'md', 'tab' => 'style',
                'options' => ['sm' => '小', 'md' => '中', 'lg' => '大']],
            ['key' => 'counter_enabled', 'type' => 'checkbox', 'label' => '进入屏幕时数字滚动', 'default' => true],
            ['key' => 'counter_start', 'type' => 'number', 'label' => '动画起始数字', 'default' => 0, 'min' => 0, 'max' => 999999],
            ['key' => 'counter_duration', 'type' => 'number', 'label' => '动画时长（毫秒）', 'default' => 0, 'min' => 0, 'max' => 5000, 'step' => 100,
                'placeholder' => '0 = 自动'],
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
            ['type' => 'stat-item', 'data' => ['icon' => 'award', 'number' => '10+', 'label' => '年行业经验']],
            ['type' => 'stat-item', 'data' => ['icon' => 'users', 'number' => '1000+', 'label' => '服务客户']],
            ['type' => 'stat-item', 'data' => ['icon' => 'briefcase', 'number' => '50+', 'label' => '完成项目']],
            ['type' => 'stat-item', 'data' => ['icon' => 'thumb-up', 'number' => '100%', 'label' => '专注服务']],
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
