<?php
/**
 * 移动抽屉导航 —— 汉堡按钮 + 侧滑抽屉 + 遮罩，自足渲染（含二级子栏目）。
 *
 * 头模板生态件（r8）：内建 xl:hidden（移动/平板及窄桌面显示）——桌面导航由 NavElement
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
            ...NavMegaElement::ctaControls(),
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
        $drawerSuffix = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['id'] ?? 'menu')) ?: 'menu';
        $drawerId = 'yk-nav-drawer-' . $drawerSuffix;

        $items = '';
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $url = htmlspecialchars(NavMegaElement::nodeHref($channel), ENT_QUOTES);
            $name = htmlspecialchars((string) ($channel['name'] ?? ''), ENT_QUOTES);
            $kids = is_array($channel['children'] ?? null) ? $channel['children'] : [];
            $items .= '<li class="border-b border-gray-100">';
            $items .= '<a href="' . $url . '"' . NavMegaElement::targetAttr($channel) . ' class="flex min-h-11 items-center px-5 text-gray-800 transition hover:bg-gray-50 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40 no-underline">' . $name . '</a>';
            if ($kids !== []) {
                $items .= '<ul class="pb-2">';
                foreach ($kids as $kid) {
                    if (!is_array($kid)) {
                        continue;
                    }
                    $items .= '<li><a href="' . htmlspecialchars(NavMegaElement::nodeHref($kid), ENT_QUOTES)
                        . '"' . NavMegaElement::targetAttr($kid) . ' class="flex min-h-11 items-center pl-10 pr-5 text-sm text-gray-500 transition hover:bg-gray-50 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40 no-underline">'
                        . htmlspecialchars((string) ($kid['name'] ?? ''), ENT_QUOTES) . '</a></li>';
                }
                $items .= '</ul>';
            }
            $items .= '</li>';
        }

        $showLogo = !isset($data['show_logo']) || (string) $data['show_logo'] !== '0';
        $header = '<div class="flex min-h-16 items-center justify-between gap-3 border-b border-gray-100 px-5">'
            . ($showLogo ? '<span class="min-w-0 truncate font-bold text-gray-900">' . htmlspecialchars($siteName, ENT_QUOTES) . '</span>' : '<span></span>')
            . '<button type="button" data-yk-drawer-close aria-label="' . htmlspecialchars(__('blox_drawer_close'), ENT_QUOTES) . '"'
            . ' class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-gray-600 transition hover:bg-gray-100 hover:text-gray-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">'
            . '<i class="ti ti-x text-2xl" aria-hidden="true"></i></button></div>';

        // 尾部 CTA：菜单列表之后、工具区之前的通栏按钮（与桌面导航的胶囊 CTA 同一配置组）
        $cta = NavMegaElement::ctaHtml($data, 'block');
        $ctaBlock = $cta !== '' ? '<div class="px-5 pt-4">' . $cta . '</div>' : '';

        $utilities = '<div class="space-y-4 border-t border-gray-100 px-5 py-5">'
            . (new SiteSearchElement())->render(['layout' => 'wide', 'show_label' => false, 'tone' => 'dark'])
            . (new LanguageSwitcherElement())->render(['layout' => 'inline', 'display' => 'name', 'show_flag' => false, 'tone' => 'dark'])
            . '</div>';

        // 类名全部字面量（Tailwind 扫描）；抽屉初始 hidden，JS 切换
        $panelSide = $side === 'left' ? 'left-0' : 'right-0';
        return '<div class="xl:hidden" data-yk-nav-drawer data-side="' . $side . '"' . $this->animationAttrs($data) . '>'
            . '<button type="button" data-yk-drawer-open aria-controls="' . $drawerId . '" aria-expanded="false" aria-label="' . htmlspecialchars(__('blox_drawer_open'), ENT_QUOTES) . '"'
            . ' class="inline-flex h-11 w-11 items-center justify-center rounded-md text-gray-700 transition hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">'
            . '<i class="ti ti-menu-2 text-2xl" aria-hidden="true"></i></button>'
            . '<button type="button" data-yk-drawer-backdrop aria-label="' . htmlspecialchars(__('blox_drawer_close'), ENT_QUOTES) . '" class="hidden fixed inset-0 bg-black/40 z-40"></button>'
            . '<nav id="' . $drawerId . '" data-yk-drawer-panel aria-hidden="true" aria-label="' . htmlspecialchars(__('menu_label'), ENT_QUOTES) . '" class="hidden fixed top-0 ' . $panelSide . ' bottom-0 w-80 max-w-[88vw] bg-white shadow-2xl z-50 overflow-y-auto overscroll-contain [padding-top:env(safe-area-inset-top)] [padding-bottom:env(safe-area-inset-bottom)]">'
            . $header
            . '<ul class="list-none m-0 p-0">' . $items . '</ul>'
            . $ctaBlock
            . $utilities
            . '</nav></div>';
    }
}
