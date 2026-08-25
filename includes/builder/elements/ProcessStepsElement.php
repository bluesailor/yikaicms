<?php
/** 服务流程组：响应式步骤网格，子步骤可在结构树中独立编辑和排序。 */

declare(strict_types=1);

final class ProcessStepsElement extends AbstractElement
{
    public function type(): string { return 'process-steps'; }
    public function label(): string { return __('blox_el_process_steps'); }
    public function icon(): string { return 'route'; }
    public function category(): string { return 'advanced'; }
    public function isContainer(): bool { return true; }
    public function allowedChildren(array $data = []): array { return ['process-step']; }

    public function controls(): array
    {
        return [
            ['key' => 'tablet_columns', 'type' => 'select', 'label' => __('blox_cols_tablet'), 'default' => '2', 'tab' => 'style',
                'options' => ['1' => __('blox_n_cols', ['n' => 1]), '2' => __('blox_n_cols', ['n' => 2]), '3' => __('blox_n_cols', ['n' => 3])]],
            ['key' => 'desktop_columns', 'type' => 'select', 'label' => __('blox_cols_desktop'), 'default' => '3', 'tab' => 'style',
                'options' => ['2' => __('blox_n_cols', ['n' => 2]), '3' => __('blox_n_cols', ['n' => 3]), '4' => __('blox_n_cols', ['n' => 4])]],
            ['key' => 'gap', 'type' => 'select', 'label' => __('blox_item_gap'), 'default' => 'lg', 'tab' => 'style',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')]],
        ];
    }

    public function defaultChildren(): array
    {
        return [
            ['type' => 'process-step', 'data' => ['number' => '01', 'icon' => 'message-circle', 'title' => __('blox_process_seed_1_title'), 'text' => __('blox_process_seed_1_text')]],
            ['type' => 'process-step', 'data' => ['number' => '02', 'icon' => 'clipboard-check', 'title' => __('blox_process_seed_2_title'), 'text' => __('blox_process_seed_2_text')]],
            ['type' => 'process-step', 'data' => ['number' => '03', 'icon' => 'rocket', 'title' => __('blox_process_seed_3_title'), 'text' => __('blox_process_seed_3_text')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $tablet = match ((string) ($data['tablet_columns'] ?? '2')) {
            '1' => 'md:grid-cols-1',
            '3' => 'md:grid-cols-3',
            default => 'md:grid-cols-2',
        };
        $desktop = match ((string) ($data['desktop_columns'] ?? '3')) {
            '2' => 'lg:grid-cols-2',
            '4' => 'lg:grid-cols-4',
            default => 'lg:grid-cols-3',
        };
        $gap = match ((string) ($data['gap'] ?? 'lg')) {
            'sm' => 'gap-4',
            'md' => 'gap-6',
            default => 'gap-10',
        };

        return '<div class="yk-process-steps grid grid-cols-1 ' . $tablet . ' ' . $desktop . ' ' . $gap
            . '" data-stagger>' . $children . '</div>';
    }
}
