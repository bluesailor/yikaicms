<?php
/**
 * 移动抽屉导航 —— 汉堡按钮 + 侧滑抽屉 + 遮罩，自足渲染（含二级子栏目）。
 *
 * 头模板生态件（r8）：内建 lg:hidden（仅移动/平板显示）——桌面导航由 NavElement
 * 承担（配 hide_on=['m'] 隐藏移动端），两件组合即完整响应式头部导航。
 * 子栏目直接吃 getNavChannels() 树（与 default 主题同源），不经 TagEngine——
 * {yk:nav} 目前无子级循环能力（多级下拉排期 r9）。
 * 交互脚本走 BloxAssetCollector 按需注入前台；编辑画布内静态可见、不可交互（预期）。
 */

declare(strict_types=1);

final class NavDrawerElement extends AbstractElement
{
    public function type(): string { return 'nav-drawer'; }
    public function label(): string { return __('blox_el_nav_drawer'); }
    public function icon(): string { return 'menu-deep'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'side', 'type' => 'select', 'label' => __('blox_drawer_side'), 'default' => 'right',
                'options' => ['left' => __('blox_align_left'), 'right' => __('blox_align_right')]],
            ['key' => 'show_logo', 'type' => 'checkbox', 'label' => __('blox_drawer_show_logo'), 'default' => true],
            ['key' => 'menu_group', 'type' => 'select', 'label' => __('blox_menu_source'), 'default' => 0,
                'options' => [0 => __('blox_menu_source_default')] + NavMegaElement::menuGroupOptions()],
        ];
    }

    public function scripts(): array
    {
        return ['/assets/js/blox-nav-drawer.js'];
    }

    public function render(array $data, string $children = ''): string
    {
        $side = ($data['side'] ?? 'right') === 'left' ? 'left' : 'right';
        $channels = NavMegaElement::navTree($data);
        $siteName = function_exists('configRawLang') ? (string) configRawLang('site_name', '') : '';

        $items = '';
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $url = htmlspecialchars((string) ($channel['url'] ?? '#'), ENT_QUOTES);
            $name = htmlspecialchars((string) ($channel['name'] ?? ''), ENT_QUOTES);
            $kids = is_array($channel['children'] ?? null) ? $channel['children'] : [];
            $items .= '<li class="border-b border-gray-100">';
            $items .= '<a href="' . $url . '" class="block px-5 py-3 text-gray-800 hover:text-primary no-underline">' . $name . '</a>';
            if ($kids !== []) {
                $items .= '<ul class="pb-2">';
                foreach ($kids as $kid) {
                    if (!is_array($kid)) {
                        continue;
                    }
                    $items .= '<li><a href="' . htmlspecialchars((string) ($kid['url'] ?? '#'), ENT_QUOTES)
                        . '" class="block pl-10 pr-5 py-2 text-sm text-gray-500 hover:text-primary no-underline">'
                        . htmlspecialchars((string) ($kid['name'] ?? ''), ENT_QUOTES) . '</a></li>';
                }
                $items .= '</ul>';
            }
            $items .= '</li>';
        }

        $header = '';
        if (!isset($data['show_logo']) || (string) $data['show_logo'] !== '0') {
            $header = '<div class="px-5 py-4 border-b border-gray-100 font-bold text-gray-900">'
                . htmlspecialchars($siteName, ENT_QUOTES) . '</div>';
        }

        // 类名全部字面量（Tailwind 扫描）；抽屉初始 hidden，JS 切换
        $panelSide = $side === 'left' ? 'left-0' : 'right-0';
        return '<div class="lg:hidden" data-yk-nav-drawer data-side="' . $side . '"' . $this->animationAttrs($data) . '>'
            . '<button type="button" data-yk-drawer-open aria-label="' . htmlspecialchars(__('blox_drawer_open'), ENT_QUOTES) . '"'
            . ' class="inline-flex items-center justify-center w-10 h-10 rounded text-gray-700 hover:bg-gray-100">'
            . '<i class="ti ti-menu-2 text-2xl"></i></button>'
            . '<div data-yk-drawer-backdrop class="hidden fixed inset-0 bg-black/40 z-40"></div>'
            . '<nav data-yk-drawer-panel class="hidden fixed top-0 ' . $panelSide . ' bottom-0 w-72 max-w-[85vw] bg-white shadow-2xl z-50 overflow-y-auto">'
            . $header
            . '<ul class="list-none m-0 p-0">' . $items . '</ul>'
            . '</nav></div>';
    }
}
