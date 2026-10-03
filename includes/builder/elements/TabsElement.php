<?php
declare(strict_types=1);

final class TabsElement extends AbstractElement
{
    /** 自动轮播间隔（秒） */
    private const AUTOPLAY_RANGE = [2, 30];

    public function type(): string { return 'tabs'; }
    public function label(): string { return __('blox_el_tabs'); }
    public function icon(): string { return 'layout-navbar'; }

    public function controls(): array
    {
        return [
            ['key' => 'items', 'type' => 'faq_repeater', 'label' => __('blox_tabs_items'), 'max' => 12,
                'add_label' => __('blox_tabs_add'), 'title_label' => __('blox_tabs_title'),
                'body_label' => __('blox_tabs_content'), 'delete_label' => __('blox_tabs_delete'),
                'new_title' => __('blox_tabs_new'), 'new_body' => __('blox_tabs_seed_content'),
                'limit_label' => __('blox_tabs_limit', ['n' => 12]),
                'default' => [
                    ['question' => __('blox_tabs_seed_overview'), 'answer' => __('blox_tabs_seed_content')],
                    ['question' => __('blox_tabs_seed_details'), 'answer' => __('blox_tabs_seed_content')],
                    ['question' => __('blox_tabs_seed_service'), 'answer' => __('blox_tabs_seed_content')],
                ]],
            ['key' => 'active_tab', 'type' => 'number', 'label' => __('blox_tabs_active'), 'default' => 1, 'min' => 1, 'max' => 12],
            // 2.0.4 深链：网址带 #标识-第几个（或英文标题）即打开对应选项卡，点选项卡时网址同步，便于分享
            ['key' => 'deep_link', 'type' => 'checkbox', 'label' => __('blox_tabs_deep_link'), 'default' => false],
            ['key' => 'link_prefix', 'type' => 'text', 'label' => __('blox_tabs_link_prefix'), 'default' => 'tab',
                'help' => __('blox_tabs_link_prefix_help'), 'required' => ['deep_link', '=', true]],
            // 2.0.4 自动轮播：带进度条；鼠标悬停或键盘聚焦时暂停，访客点过选项卡后不再自动切换；「减少动态」时不轮播
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => __('blox_tabs_autoplay'), 'default' => false],
            ['key' => 'autoplay_interval', 'type' => 'number', 'label' => __('blox_tabs_autoplay_interval'), 'default' => 6,
                'min' => self::AUTOPLAY_RANGE[0], 'max' => self::AUTOPLAY_RANGE[1], 'required' => ['autoplay', '=', true]],
            ['key' => 'autoplay_button', 'type' => 'checkbox', 'label' => __('blox_tabs_autoplay_button'), 'default' => true,
                'required' => ['autoplay', '=', true]],
            ['key' => 'tabs_style', 'type' => 'select', 'label' => __('blox_tabs_style'), 'tab' => 'style', 'default' => 'underline',
                'options' => ['underline' => __('blox_tabs_underline'), 'segmented' => __('blox_tabs_segmented'), 'bordered' => __('blox_tabs_bordered')],
                'option_icons' => ['underline' => 'border-bottom', 'segmented' => 'layout-columns', 'bordered' => 'border-all']],
        ];
    }

    public function scripts(): array { return ['/assets/js/blox-tabs.js']; }
    public function styles(): array { return ['/assets/css/blox-tabs.css']; }

    public function render(array $data, string $children = ''): string
    {
        $items = array_slice((new AccordionElement())->items($data), 0, 12);
        if (!$items) return '';
        $style = in_array($data['tabs_style'] ?? '', ['segmented', 'bordered'], true) ? $data['tabs_style'] : 'underline';
        $active = max(0, min(count($items) - 1, (int) ($data['active_tab'] ?? 1) - 1));
        $prefix = self::prefix((string) ($data['link_prefix'] ?? 'tab'));
        $hashes = self::enabled($data, 'deep_link') ? self::hashes($prefix, array_column($items, 0)) : [];
        $autoplay = self::enabled($data, 'autoplay') && count($items) > 1;
        $interval = max(self::AUTOPLAY_RANGE[0], min(self::AUTOPLAY_RANGE[1], (int) ($data['autoplay_interval'] ?? 6)));
        // Per-render IDs stay unique for repeated elements and cached fragments.
        $id = 'yk-tabs-' . bin2hex(random_bytes(8));
        $html = '<div class="yk-tabs" data-blox-tabs data-tabs-style="' . $style . '" data-active-tab="' . $active . '"'
            . ($hashes !== [] ? ' data-tabs-deep-link' : '')
            . ($autoplay ? ' data-tabs-autoplay style="--yk-tabs-interval:' . $interval . 's"' : '') . '>';
        $html .= '<div class="yk-tabs-nav" role="tablist" aria-label="' . e($this->label()) . '">';
        foreach ($items as $i => [$title]) {
            $html .= '<button type="button" role="tab" class="yk-tabs-tab" id="' . $id . '-tab-' . $i . '"'
                . ' aria-controls="' . $id . '-panel-' . $i . '" aria-selected="' . ($active === $i ? 'true' : 'false') . '"'
                . ($hashes !== [] ? ' data-tab-hash="' . e($hashes[$i]) . '" data-tab-hash-alt="' . $prefix . '-' . ($i + 1) . '"' : '')
                . ' tabindex="' . ($active === $i ? '0' : '-1') . '">' . e($title)
                . ($autoplay ? '<span class="yk-tabs-progress" aria-hidden="true"></span>' : '') . '</button>';
        }
        $html .= '</div>';
        if ($autoplay && self::enabled($data, 'autoplay_button', true)) {
            $html .= '<button type="button" class="yk-tabs-autoplay-toggle" data-tabs-autoplay-toggle aria-pressed="false"'
                . ' data-label-pause="' . e(__('blox_tabs_autoplay_pause')) . '" data-label-play="' . e(__('blox_tabs_autoplay_play')) . '"'
                . ' aria-label="' . e(__('blox_tabs_autoplay_pause')) . '"><i class="ti ti-player-pause" aria-hidden="true"></i></button>';
        }
        foreach ($items as $i => [, $content]) {
            $rich = ($items[$i][2] ?? '') === 'html';
            $html .= '<div class="yk-tabs-panel yk-description" role="tabpanel" id="' . $id . '-panel-' . $i . '"'
                . ' aria-labelledby="' . $id . '-tab-' . $i . '" tabindex="0">'
                . ($rich ? $content : nl2br(e($content))) . '</div>';
        }
        return $html . '</div>';
    }

    /**
     * 每个选项卡的网址标识：前缀-英文标题别名；标题不是英文或重名时用前缀-序号（序号形式另外始终可用）。
     *
     * @param list<string> $titles
     * @return list<string>
     */
    public static function hashes(string $prefix, array $titles): array
    {
        $prefix = self::prefix($prefix);
        $out = [];
        foreach (array_values($titles) as $i => $title) {
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');
            $hash = strlen($slug) >= 2 ? $prefix . '-' . rtrim(substr($slug, 0, 40), '-') : $prefix . '-' . ($i + 1);
            $out[] = in_array($hash, $out, true) ? $prefix . '-' . ($i + 1) : $hash;
        }
        return $out;
    }

    private static function prefix(string $prefix): string
    {
        $prefix = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($prefix)), '-');
        return $prefix !== '' ? rtrim(substr($prefix, 0, 32), '-') : 'tab';
    }

    private static function enabled(array $data, string $key, bool $default = false): bool
    {
        return array_key_exists($key, $data) ? !in_array($data[$key], [false, 0, '0', '', null], true) : $default;
    }
}
