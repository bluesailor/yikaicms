<?php
/** 语言感知的站内搜索表单，提交到现有 search.php。 */

declare(strict_types=1);

final class SiteSearchElement extends AbstractElement
{
    public function type(): string { return 'site-search'; }
    public function label(): string { return __('blox_el_site_search'); }
    public function icon(): string { return 'search'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_search_layout'), 'default' => 'compact',
                'options' => ['compact' => __('blox_search_compact'), 'wide' => __('blox_search_wide')]],
            ['key' => 'show_label', 'type' => 'checkbox', 'label' => __('blox_search_show_label'), 'default' => false],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $wide = ($data['layout'] ?? 'compact') === 'wide';
        $light = ($data['tone'] ?? 'dark') === 'light';
        $showLabel = array_key_exists('show_label', $data)
            && !in_array($data['show_label'], [false, 0, '0', '', null], true);
        $prettyAction = function_exists('searchUrl') ? searchUrl() : ((function_exists('langPrefix') ? langPrefix() : '') . '/search.php');
        $action = function_exists('dynamicFormAction') ? dynamicFormAction($prettyAction) : $prettyAction;
        $routeInputs = function_exists('dynamicFormHiddenInputs') ? dynamicFormHiddenInputs('search') : '';
        $keyword = isset($_GET['keyword']) && is_string($_GET['keyword']) ? trim($_GET['keyword']) : '';
        $inputClass = $light
            ? 'border-white/30 bg-white/10 text-white placeholder:text-white/60 focus:border-white'
            : 'border-gray-300 bg-white text-gray-900 placeholder:text-gray-400 focus:border-primary';
        $buttonClass = $light
            ? 'bg-white text-gray-900 hover:bg-white/80'
            : 'bg-primary text-white hover:opacity-90';
        $widthClass = $wide ? 'w-full max-w-xl' : 'w-full max-w-xs';
        $label = __('blox_search_submit');

        return '<form action="' . htmlspecialchars($action, ENT_QUOTES) . '" method="get" role="search" class="flex ' . $widthClass . '">'
            . $routeInputs
            . '<input type="search" name="keyword" value="' . htmlspecialchars($keyword, ENT_QUOTES) . '"'
            . ' aria-label="' . htmlspecialchars(__('blox_search_placeholder'), ENT_QUOTES) . '"'
            . ' placeholder="' . htmlspecialchars(__('blox_search_placeholder'), ENT_QUOTES) . '"'
            . ' class="min-w-0 flex-1 rounded-l border px-3 py-2 text-sm outline-none ' . $inputClass . '">'
            . '<button type="submit" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-r px-3 py-2 text-sm font-medium transition ' . $buttonClass . '">'
            . '<i class="ti ti-search text-base" aria-hidden="true"></i>'
            . ($showLabel ? '<span>' . htmlspecialchars($label, ENT_QUOTES) . '</span>' : '')
            . '</button></form>';
    }
}
