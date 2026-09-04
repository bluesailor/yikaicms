<?php
/**
 * 首页旧区块引用（P0）。
 *
 * 它只负责在首页 Blox 草稿中保留旧区块的顺序和身份，线上首页仍由旧模板渲染。
 * P1 会把它逐步替换为 Banner、栏目列表、评价等真正的动态元素。
 */

declare(strict_types=1);

final class HomeBlockElement extends AbstractElement
{
    public function type(): string { return 'home-block'; }
    public function label(): string { return __('blox_home_block_label'); }
    public function icon(): string { return 'layout-dashboard'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function isContainer(): bool { return true; }
    public function rendersOwnChildren(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'home'; }
    public function supportsBoxStyles(): bool { return false; }

    public function allowedChildren(array $data = []): array
    {
        return (string) ($data['block_type'] ?? '') === 'banner' ? ['home-banner-item'] : [];
    }

    public function childRules(): array
    {
        return [[
            'field' => 'block_type',
            'operator' => '=',
            'value' => 'banner',
            'allowedChildren' => ['home-banner-item'],
        ]];
    }

    public function scriptsFor(array $data): array
    {
        if ((string) ($data['block_type'] ?? '') === 'banner') {
            return ['/assets/js/blox-video-policy.js', '/assets/js/blox-banner.js'];
        }
        $counterEnabled = !array_key_exists('counter_enabled', $data) || !empty($data['counter_enabled']);

        return (string) ($data['block_type'] ?? '') === 'stats' && $counterEnabled
            ? ['/assets/js/blox-counter.js'] : [];
    }

    public function stylesFor(array $data): array
    {
        return (string) ($data['block_type'] ?? '') === 'banner'
            ? ['/assets/css/blox-banner.css'] : [];
    }

    public function controls(): array
    {
        $controls = HomeBloxBlockSchema::controls();
        $surfaceTypes = [];
        foreach (function_exists('getThemes') ? getThemes() : [] as $theme) {
            if (($theme['slug'] ?? '') === currentTheme()) {
                $surfaceTypes = (array) ($theme['home_surface_types'] ?? []);
                break;
            }
        }
        $sources = array_values(array_filter(
            array_keys(HomeBloxBlockSchema::sourceOptions()),
            static fn (string $type): bool => in_array(explode(':', $type)[0], $surfaceTypes, true)
        ));
        // 背景统一归外层区块设置。旧 CTA 字段继续由 schema 读取和渲染，
        // 这里只停止为新编辑暴露第二套入口；声明了主题 surface 的区块仍保留
        // 自身色调/图片能力。
        $surfaceStyleKeys = ['bg_image', 'bg_color', 'text_light'];
        $controls = array_values(array_filter(
            $controls,
            static function (array $control) use ($sources, $surfaceStyleKeys): bool {
                $key = (string) ($control['key'] ?? '');
                if (in_array($key, ['bg_overlay_color', 'bg_overlay_opacity'], true)) {
                    return false;
                }
                return $sources !== [] || !in_array($key, $surfaceStyleKeys, true);
            }
        ));
        if ($sources === []) {
            return $controls;
        }
        $surfaceControl = [
            'key' => 'home_surface', 'type' => 'select', 'tab' => 'style',
            'label' => __('blox_home_surface'), 'default' => 'auto',
            'options' => [
                'auto' => __('blox_home_surface_auto'),
                'light' => __('blox_home_surface_light'),
                'dark' => __('blox_home_surface_dark'),
                'custom' => __('blox_home_surface_custom'),
            ],
            'required' => ['block_type', '=', $sources],
        ];
        array_splice($controls, (int) array_search('bg_image', array_column($controls, 'key'), true), 0, [$surfaceControl]);
        foreach ($controls as &$control) {
            if (in_array($control['key'] ?? '', ['bg_image', 'bg_color', 'text_light'], true)) {
                $control['visible_when'] = ['relation' => 'and', 'terms' => [
                    ['block_type', 'in', $sources],
                    ['home_surface', 'not_in', ['light', 'dark']],
                ]];
                unset($control['required']);
                if ($control['key'] === 'bg_image') {
                    $control['label'] = __('blox_bg_image');
                }
            }
        }
        unset($control);
        return $controls;
    }

    public function render(array $data, string $children = ''): string
    {
        $type = trim((string) ($data['block_type'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            $label = HomeBloxDocument::legacyLabel($type !== '' ? $type : 'home-block');
        }
        $status = !empty($data['enabled']) ? __('blox_home_draft_block') : __('blox_home_disabled');

        return '<section class="yk-home-block-preview border-2 border-dashed border-slate-300 bg-slate-50 rounded-xl px-6 py-10 text-center" data-home-block="'
            . htmlspecialchars($type, ENT_QUOTES) . '">'
            . '<div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-white border border-slate-200 text-blue-500 mb-3">'
            . '<i class="ti ti-layout-dashboard text-2xl"></i></div>'
            . '<h2 class="text-lg font-semibold text-slate-700">' . htmlspecialchars($label, ENT_QUOTES) . '</h2>'
            . '<p class="text-xs text-slate-400 mt-2">' . htmlspecialchars($status, ENT_QUOTES) . ' · ' . __('blox_home_next_phase') . '</p>'
            . '</section>';
    }
}
