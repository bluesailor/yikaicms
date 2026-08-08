<?php
/**
 * Mega Menu 导航 —— 顶级菜单 + 全宽多列面板（r12，借鉴 Avada / Bricks）。
 *
 * 树映射（Avada 语义）：顶级栏目=菜单项；有子级的项悬停出面板——子栏目=列
 * （列标题可点，Avada 的 column title 语义），孙栏目=列内链接；无孙级的子栏目
 * 是纯链接列。产品栏目吃动态产品分类树（getNavChannels 已构好）。
 *
 * 定位（Bricks 做法）：菜单 li 不做定位上下文，面板 absolute inset-x-0 相对
 * **元素根**展开 → 面板天然与导航容器同宽，零边界溢出（Avada 边界感知的
 * 零成本替代）。开闭：CSS hover + focus-within（键盘可达），无 JS。
 *
 * 内建 `hidden lg:flex`：mega menu 语义即桌面导航——移动端配 nav-drawer（r8），
 * 与 NavElement+hide_on 的组合同一模式。
 * 数据同源 getNavChannels()（含孙级，ChannelModel::getNav r12 起提供）。
 */

declare(strict_types=1);

final class NavMegaElement extends AbstractElement
{
    public function type(): string { return 'nav-mega'; }
    public function label(): string { return __('blox_el_nav_mega'); }
    public function icon(): string { return 'layout-navbar-expand'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'menu_group', 'type' => 'select', 'label' => __('blox_menu_source'), 'default' => 0,
                'options' => [0 => __('blox_menu_source_default')] + self::menuGroupOptions()],
            ['key' => 'show_desc', 'type' => 'checkbox', 'label' => __('blox_mega_show_desc'), 'default' => false],
            ['key' => 'full_width', 'type' => 'checkbox', 'label' => __('blox_mega_full_width'), 'default' => true],
        ];
    }

    /** 菜单组下拉（导航元素共用）；无表/无库环境安全空集 */
    public static function menuGroupOptions(): array
    {
        try {
            return function_exists('navMenuModel') ? navMenuModel()->asMap() : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** 数据源：选了菜单组走组树（NavMenuModel::treeFor，与栏目投影同构），否则栏目投影 */
    public static function navTree(array $data): array
    {
        $groupId = (int) ($data['menu_group'] ?? 0);
        if ($groupId > 0 && function_exists('navMenuModel')) {
            // 菜单组：内容用户全权自定义，不自动插首页（需要就在组里加）
            try {
                return navMenuModel()->treeFor($groupId);
            } catch (Throwable) {
                return [];
            }
        }
        $tree = function_exists('getNavChannels') ? getNavChannels() : [];
        // 首页项：与主题导航同源吃站点设置（nav_home_show 开关 + nav_home_text 文案），
        // 行为一致——主题头部显示首页，Blox 头部也显示；设置里关掉则都不显示
        if (function_exists('configRawLang') && configRawLang('nav_home_show', '1') !== '0') {
            array_unshift($tree, [
                'name' => function_exists('configLang') ? (string) configLang('nav_home_text', 'nav_home') : __('nav_home'),
                'url' => '/',
                '_url' => '/',
                'children' => [],
            ]);
        }
        return $tree;
    }

    /** 组树自定义链接可配 _blank（栏目投影节点无此键=空串） */
    public static function targetAttr(array $node): string
    {
        return ($node['link_target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '';
    }

    public function render(array $data, string $children = ''): string
    {
        $channels = self::navTree($data);
        if ($channels === []) {
            return '';
        }
        $showDesc = !empty($data['show_desc']);
        // full_width 关：面板收窄为内容自适应（少列时不出通栏空面板）
        $panelPos = !isset($data['full_width']) || !empty($data['full_width'])
            ? 'inset-x-0'
            : 'left-0 w-max max-w-3xl';

        $items = '';
        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $name = htmlspecialchars((string) ($channel['name'] ?? ''), ENT_QUOTES);
            $url = htmlspecialchars($this->channelHref($channel), ENT_QUOTES);
            $kids = is_array($channel['children'] ?? null) ? array_values(array_filter($channel['children'], 'is_array')) : [];

            if ($kids === []) {
                $items .= '<li><a href="' . $url . '"' . self::targetAttr($channel) . ' class="inline-flex items-center px-3 py-2 font-medium text-gray-700 hover:text-primary no-underline">' . $name . '</a></li>';
                continue;
            }

            // 列数自适应：1-4 封顶（Avada 常规上限；类名字面量供 Tailwind 扫描）
            $colsClass = ['', 'grid-cols-1', 'grid-cols-2', 'grid-cols-3', 'grid-cols-4'][min(4, count($kids))];

            $columns = '';
            foreach ($kids as $kid) {
                $columns .= $this->renderColumn($kid, $showDesc);
            }

            $items .= '<li class="group/mega">'
                . '<a href="' . $url . '" class="inline-flex items-center gap-1 px-3 py-2 font-medium text-gray-700 hover:text-primary no-underline" aria-haspopup="true">' . $name
                . '<svg class="h-3 w-3 opacity-60 transition-transform group-hover/mega:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></a>'
                // 面板：相对元素根全宽；hover/focus-within 展开；关闭态不拦截指针
                . '<div class="yk-mega-panel invisible absolute ' . $panelPos . ' top-full z-40 opacity-0 transition-all duration-150 pointer-events-none'
                . ' group-hover/mega:visible group-hover/mega:opacity-100 group-hover/mega:pointer-events-auto'
                . ' group-focus-within/mega:visible group-focus-within/mega:opacity-100 group-focus-within/mega:pointer-events-auto">'
                . '<div class="mt-2 rounded-2xl border border-gray-100 bg-white p-8 shadow-xl">'
                . '<div class="grid gap-8 ' . $colsClass . '">' . $columns . '</div>'
                . '</div></div></li>';
        }

        return '<nav class="yk-mega relative hidden lg:flex" aria-label="' . htmlspecialchars(__('blox_el_nav_mega'), ENT_QUOTES) . '">'
            . '<ul class="flex flex-wrap items-center gap-1">' . $items . '</ul></nav>';
    }

    /** @param array<string,mixed> $kid 面板列：子栏目标题（可点）+ 可选描述 + 孙级链接列表 */
    private function renderColumn(array $kid, bool $showDesc): string
    {
        $name = htmlspecialchars((string) ($kid['name'] ?? ''), ENT_QUOTES);
        $url = htmlspecialchars($this->channelHref($kid), ENT_QUOTES);
        $grand = is_array($kid['children'] ?? null) ? array_values(array_filter($kid['children'], 'is_array')) : [];

        $html = '<div class="min-w-0">'
            . '<a href="' . $url . '"' . self::targetAttr($kid) . ' class="block text-sm font-semibold text-gray-900 hover:text-primary no-underline">' . $name . '</a>';
        if ($showDesc && trim((string) ($kid['description'] ?? '')) !== '') {
            $html .= '<p class="mt-1 text-xs text-gray-400 line-clamp-2">'
                . htmlspecialchars((string) $kid['description'], ENT_QUOTES) . '</p>';
        }
        if ($grand !== []) {
            $html .= '<ul class="mt-3 space-y-2 border-t border-gray-50 pt-3">';
            foreach ($grand as $g) {
                $html .= '<li><a href="' . htmlspecialchars($this->channelHref($g), ENT_QUOTES)
                    . '"' . self::targetAttr($g) . ' class="block text-sm text-gray-500 hover:text-primary no-underline">'
                    . htmlspecialchars((string) ($g['name'] ?? ''), ENT_QUOTES) . '</a></li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }

    /** 与主题导航同一 URL 语义：动态注入的 _url（产品分类树）优先，回退 channelUrl() */
    private function channelHref(array $channel): string
    {
        if (!empty($channel['_url'])) {
            return (string) $channel['_url'];
        }
        return function_exists('channelUrl') ? channelUrl($channel) : '#';
    }
}
