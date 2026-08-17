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
            return ['/assets/js/blox-banner.js'];
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
        return HomeBloxBlockSchema::controls();
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
