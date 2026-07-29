<?php
/**
 * Yikai CMS - 后台头部模板
 *
 * PHP 8.0+
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

$adminInfo = getAdminInfo();
$currentMenu = $currentMenu ?? '';

// Sidebar menu structure — see admin/includes/sidebar_menu.php for the
// canonical defaults and admin/includes/sidebar_menu_api.php for the
// register_admin_menu() helper plugins use to extend it.
require_once __DIR__ . '/sidebar_menu_api.php';
$sidebarMenu = resolveAdminSidebar();

// Compute which group should default-open by scanning every item's
// active_keys (or item.key as a fallback) for a match against $currentMenu.
$activeGroup = '';
foreach ($sidebarMenu as $groupKey => $navGroup) {
    foreach (($navGroup['items'] ?? []) as $item) {
        $candidates = $item['active_keys'] ?? [$item['key']];
        if (in_array($currentMenu, (array) $candidates, true)) {
            $activeGroup = $groupKey;
            break 2;
        }
    }
}

// 后台品牌文字（左上角 Logo / 页面标题）：默认「后台管理」按后台语言本地化，
// 英文→Admin Panel、日语→管理画面；管理员自定义了 admin_title 则原样显示。
$adminBrand = trim((string) config('admin_title', ''));
if ($adminBrand === '后台管理') {
    $adminBrand = __('admin_title_default');
} elseif ($adminBrand === '') {
    $adminBrand = config('site_name', 'YikaiCMS');
}
?>
<!DOCTYPE html>
<html lang="<?php echo getLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle ?? __('admin_title_default')); ?> - <?php echo e($adminBrand); ?></title>
    <link rel="icon" href="<?php echo e(config('site_favicon', '/favicon.ico')); ?>">
    <link rel="stylesheet" href="<?php echo assetVer('/assets/css/tailwind.css'); ?>">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <script defer src="/assets/alpinejs/collapse.min.js"></script>
    <script defer src="/assets/alpinejs/alpine.min.js"></script>
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <link rel="stylesheet" href="<?php echo assetVer('/assets/css/admin.css'); ?>">
    <?php do_action('ik_admin_head'); ?>
</head>
<?php
// 侧栏折叠态（图标栏）：状态存 Cookie，服务端据此预渲染宽度与主内容边距，
// 避免 Alpine 初始化前后布局跳动；前端切换时同步回写 Cookie + localStorage。
$_sbCollapsed = (($_COOKIE['sidebarCollapsed'] ?? '0') === '1');
?>
<body class="bg-gray-100" x-data="{
        mobileMenu: false,
        collapsed: <?= $_sbCollapsed ? 'true' : 'false' ?>,
        toggleCollapsed() {
            this.collapsed = !this.collapsed;
            try { localStorage.setItem('sidebarCollapsed', this.collapsed ? '1' : '0'); } catch (e) {}
            document.cookie = 'sidebarCollapsed=' + (this.collapsed ? '1' : '0') + ';path=/;max-age=' + (365 * 86400) + ';samesite=Lax';
            if (!this.collapsed) { this.fly = { key: '', label: '', items: [], top: 0 }; }
        },
        fly: { key: '', label: '', items: [], top: 0 },
        _flyTimer: null,
        flyOpen(e, key) {
            clearTimeout(this._flyTimer);
            var d = (window.__ykMenuFly || {})[key];
            if (!d) { return; }
            var r = e.currentTarget.getBoundingClientRect();
            var top = Math.min(r.top, Math.max(8, window.innerHeight - (d.items.length * 32 + 60)));
            this.fly = { key: key, label: d.label, items: d.items, top: top };
        },
        flyKeep() { clearTimeout(this._flyTimer); },
        flyLater() {
            var self = this;
            clearTimeout(this._flyTimer);
            this._flyTimer = setTimeout(function () { self.fly = { key: '', label: '', items: [], top: 0 }; }, 180);
        }
     }">
    <div class="flex min-h-screen">
        <!-- 移动端遮罩层 -->
        <div x-show="mobileMenu"
             x-transition:enter="transition-opacity ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="mobileMenu = false"
             class="fixed inset-0 z-40 bg-black/50 lg:hidden"
             x-cloak></div>

        <!-- 侧边栏 -->
        <aside class="fixed inset-y-0 left-0 z-50 bg-sidebar text-gray-300 transition-all duration-300 ease-in-out -translate-x-full lg:translate-x-0 overflow-y-auto overflow-x-visible w-64 <?= $_sbCollapsed ? 'lg:w-16' : 'lg:w-64' ?>"
               :class="[mobileMenu ? 'translate-x-0' : '', collapsed ? 'lg:w-16' : 'lg:w-64']">
            <!-- Logo -->
            <div class="h-16 flex items-center justify-center border-b border-white/5">
                <?php $adminLogo = config('admin_logo', ''); ?>
                <a href="/admin/" class="flex items-center gap-2 px-2 min-w-0">
                    <?php if ($adminLogo): ?>
                    <img src="<?php echo e($adminLogo); ?>" alt="" class="h-8 flex-shrink-0">
                    <?php else: ?>
                    <span class="text-xl font-bold text-white truncate" x-show="!collapsed"><?php echo e($adminBrand); ?></span>
                    <span class="text-xl font-bold text-white" x-show="collapsed" x-cloak><?php echo e(mb_substr($adminBrand, 0, 1)); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- 导航菜单 -->
            <script>
            // 单开手风琴（借鉴 WordPress）：同时只展开「当前所在组」，其余收起。
            // 菜单项近 40 个，全展开需滚动；单开后常驻约 15 行、一屏可见。
            // 不记忆上次展开项——服务端已按 activeGroup 预渲染折叠态，
            // 避免 Alpine 初始化前后出现「先全展开再塌陷」的闪烁。
            function sidebarNav() {
                return {
                    openGroup: '<?php echo $activeGroup; ?>',
                    isOpen: function (g) { return this.openGroup === g; },
                    toggle: function (g) { this.openGroup = (this.openGroup === g) ? '' : g; }
                };
            }
            </script>
            <nav class="mt-4 px-3" x-data="sidebarNav()">
                <!-- 控制台 -->
                <a href="/admin/" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'dashboard' ? 'active' : ''; ?>">
                    <i class="ti ti-home text-lg mr-3"></i>
                    <?php echo __('admin_dashboard'); ?>
                </a>

                <?php
                // ── 侧边栏菜单（数据驱动；通过 register_admin_menu() / 'admin_sidebar' filter 可扩展）──
                foreach ($sidebarMenu as $groupKey => $navGroup):
                    if (!empty($navGroup['super_only']) && !isSuperAdmin()) continue;
                    // 权限过滤：菜单项声明了 perm 而当前角色没有该权限 → 不显示；整组被滤空则组标题也不显示
                    $navGroup['items'] = array_filter(
                        (array) ($navGroup['items'] ?? []),
                        static fn($it) => empty($it['perm']) || hasPermission((string) $it['perm'])
                    );
                    if (empty($navGroup['items'])) continue;
                ?>
                <?php
                $_first    = reset($navGroup['items']);
                $_firstUrl = (string) ($_first['url'] ?? '');
                $_gKey     = htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8');
                $_gIcon    = (string) ($navGroup['icon'] ?? '');
                $_gOpen    = ($groupKey === $activeGroup);
                ?>
                <div @click="collapsed ? null : toggle('<?= $_gKey ?>')" data-group="<?= $_gKey ?>"
                     @mouseenter="collapsed && flyOpen($event, '<?= $_gKey ?>')" @mouseleave="collapsed && flyLater()"
                     :title="collapsed ? '<?= htmlspecialchars(strip_tags((string)$navGroup['label']), ENT_QUOTES, 'UTF-8') ?>' : ''"
                     class="sidebar-group mt-2 px-3 py-2 rounded-lg text-base flex items-center gap-2.5"
                     :class="collapsed ? 'lg:justify-center' : ''">
                    <?php if ($_gIcon !== ''): ?>
                    <svg class="w-5 h-5 flex-shrink-0 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $_gIcon ?></svg>
                    <?php endif; ?>
                    <?php if ($_firstUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($_firstUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex-1 min-w-0 truncate" x-show="!collapsed"><?= htmlspecialchars((string)$navGroup['label'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php else: ?>
                    <span class="flex-1 min-w-0 truncate" x-show="!collapsed"><?= htmlspecialchars((string)$navGroup['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <i class="ti ti-chevron-down text-sm opacity-60 transition-transform duration-200" x-show="!collapsed" :class="isOpen('<?= $_gKey ?>') ? 'rotate-180' : ''"></i>
                </div>
                <div class="pl-2" x-show="!collapsed && isOpen('<?= $_gKey ?>')" x-collapse.duration.200ms<?= $_gOpen ? '' : ' style="display:none"' ?>>
                    <?php foreach ($navGroup['items'] as $_item): ?>
                        <?= renderAdminMenuItem($_item, (string)$currentMenu) ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php do_action('admin_menu', $currentMenu); ?>
                <div class="mt-6 mb-4 px-1">
                    <a href="/admin/logout.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-gray-800 transition"
                       :class="collapsed ? 'lg:justify-center' : ''" :title="collapsed ? '<?php echo e(__('admin_safe_logout')); ?>' : ''">
                        <i class="ti ti-logout text-lg" :class="collapsed ? 'lg:mr-0 mr-3' : 'mr-3'"></i>
                        <span x-show="!collapsed"><?php echo __('admin_safe_logout'); ?></span>
                    </a>
                    <?php // 折叠为图标栏（借鉴 WordPress 的「折叠菜单」）——仅桌面端 ?>
                    <button type="button" @click="toggleCollapsed()"
                            class="sidebar-link hidden lg:flex items-center w-full px-4 py-2 rounded-lg text-gray-500 hover:text-gray-200 transition"
                            :class="collapsed ? 'justify-center' : ''"
                            :title="collapsed ? '<?php echo e(__('admin_sidebar_expand')); ?>' : '<?php echo e(__('admin_sidebar_collapse')); ?>'">
                        <i class="ti text-lg" :class="[collapsed ? 'ti-chevrons-right' : 'ti-chevrons-left', collapsed ? '' : 'mr-3']"></i>
                        <span x-show="!collapsed"><?php echo __('admin_sidebar_collapse'); ?></span>
                    </button>
                </div>
                <div style="height:20px"></div>
            </nav>
        </aside>

        <?php // 折叠态的二级飞出面板：侧栏是滚动容器会裁剪子元素，故用 fixed 定位单例，
              // 悬停分组图标时按其位置弹出。数据从同一份菜单生成，不重复维护。 ?>
        <div x-show="collapsed && fly.key" x-cloak
             @mouseenter="flyKeep()" @mouseleave="flyLater()"
             :style="'top:' + fly.top + 'px'"
             class="hidden lg:block fixed left-16 z-[60] w-52 bg-sidebar text-gray-300 rounded-r-lg shadow-2xl border border-white/10 border-l-0 py-2">
            <div class="px-4 py-1.5 text-sm text-gray-400 border-b border-white/5 mb-1" x-text="fly.label"></div>
            <template x-for="it in fly.items" :key="it.url">
                <a :href="it.url" class="sidebar-link block px-4 py-1.5 text-sm" x-text="it.label"></a>
            </template>
        </div>
        <script>
        window.__ykMenuFly = <?php
            $_flyData = [];
            foreach ($sidebarMenu as $_gk => $_gv) {
                if (!empty($_gv['super_only']) && !isSuperAdmin()) continue;
                $_its = [];
                foreach ((array) ($_gv['items'] ?? []) as $_it) {
                    if (!empty($_it['perm']) && !hasPermission((string) $_it['perm'])) continue;
                    $_its[] = ['label' => trim(strip_tags((string) ($_it['label'] ?? ''))), 'url' => (string) ($_it['url'] ?? '')];
                }
                if ($_its) {
                    $_flyData[$_gk] = ['label' => trim(strip_tags((string) ($_gv['label'] ?? $_gk))), 'items' => $_its];
                }
            }
            echo json_encode($_flyData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        ?>;
        </script>

        <!-- 主内容区 -->
        <div class="flex-1 transition-all duration-300 <?= $_sbCollapsed ? 'lg:ml-16' : 'lg:ml-64' ?>"
             :class="collapsed ? 'lg:ml-16' : 'lg:ml-64'">
            <!-- 顶部导航 -->
            <header class="h-16 bg-white shadow-sm flex items-center justify-between px-6 sticky top-0 z-40">
                <!-- 移动端菜单按钮 -->
                <button @click="mobileMenu = !mobileMenu" class="lg:hidden text-gray-500 hover:text-gray-700">
                    <i class="ti ti-menu-2 text-xl"></i>
                </button>

                <div class="flex-1 lg:flex-none">
                    <h1 class="text-lg font-semibold text-gray-800"><?php echo $pageTitle ?? __('admin_dashboard'); ?></h1>
                </div>

                <!-- 右侧工具栏 -->
                <div class="flex items-center gap-4">
                    <!-- 后台命令面板搜索 -->
                    <div class="relative" x-data="adminSearch()" x-init="init()" @click.away="open = false">
                        <div class="relative" style="display:inline-block;">
                            <input x-ref="input" id="adminSearchInput" name="admin_search" type="search"
                                   role="searchbox" autocomplete="off" aria-label="<?php echo __('admin_quick_search'); ?>"
                                   x-model="query"
                                   @input="search()" @focus="open = true"
                                   @keydown.escape="open = false"
                                   @keydown.down.prevent="moveSel(1)"
                                   @keydown.up.prevent="moveSel(-1)"
                                   @keydown.enter.prevent="goSelected()"
                                   placeholder="<?php echo __('admin_quick_search_ph'); ?>"
                                   style="padding-left:36px; padding-right:42px; width:220px; height:34px; box-sizing:border-box;"
                                   class="text-sm border border-gray-200 rounded-full focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                            <i class="ti ti-search text-sm text-gray-400" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); pointer-events:none;"></i>
                            <kbd style="position:absolute; right:8px; top:50%; transform:translateY(-50%); pointer-events:none; font-size:10px; padding:2px 4px; line-height:1;" class="font-mono text-gray-400 border border-gray-200 rounded bg-gray-50 hidden lg:inline-block" title="Ctrl/⌘+K">⌘K</kbd>
                        </div>
                        <div x-show="open && query.trim()" x-cloak
                             class="absolute right-0 mt-1 w-80 bg-white rounded-lg shadow-xl border border-gray-200 py-1 max-h-96 overflow-y-auto z-50">
                            <template x-if="results.length === 0">
                                <div class="px-3 py-4 text-center text-xs text-gray-400">没有匹配「<span x-text="query"></span>」的页面<br>试试：logo / 联系 / 邮件 / 主题 / 升级</div>
                            </template>
                            <template x-for="(r, i) in results" :key="r.url">
                                <a :href="r.url" :class="i === selected ? 'bg-blue-50' : 'hover:bg-gray-50'"
                                   class="block px-3 py-2 text-sm">
                                    <div class="flex items-center justify-between">
                                        <span class="font-medium text-gray-800" x-text="r.title"></span>
                                        <span class="text-[10px] text-gray-400 ml-2" x-text="r.group"></span>
                                    </div>
                                    <div class="text-[11px] text-gray-400 mt-0.5" x-text="r.url"></div>
                                </a>
                            </template>
                        </div>
                    </div>
                    <script>
                    function adminSearch() {
                        return {
                            open: false, query: '', results: [], selected: 0, _t: null,
                            init() {
                                window.addEventListener('keydown', (e) => {
                                    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                                        e.preventDefault();
                                        this.$refs.input.focus();
                                        this.$refs.input.select();
                                        this.open = true;
                                    }
                                });
                            },
                            moveSel(d) {
                                if (!this.results.length) return;
                                this.selected = (this.selected + d + this.results.length) % this.results.length;
                            },
                            goSelected() {
                                if (this.results[this.selected]) location.href = this.results[this.selected].url;
                            },
                            search() {
                                clearTimeout(this._t);
                                this._t = setTimeout(() => this._doSearch(), 150);
                            },
                            async _doSearch() {
                                const q = this.query.trim();
                                if (!q) { this.results = []; this.selected = 0; return; }
                                try {
                                    const r = await fetch('/admin/api_search.php?q=' + encodeURIComponent(q));
                                    const d = await r.json();
                                    this.results = d.code === 0 ? (d.data || []) : [];
                                    this.selected = 0;
                                } catch (e) {
                                    this.results = [];
                                }
                            },
                        };
                    }
                    </script>

                    <!-- 语言切换：动态基于 admin_languages 设置；为空时按 lang/*.php 文件自动检测 -->
                    <?php
                    $currentAdminLang = config('admin_lang', 'zh-CN');
                    $langLabels = ['zh-CN' => '中文', 'en' => 'EN', 'ja' => '日本語'];
                    $configured = trim((string)config('admin_languages', ''));
                    if ($configured !== '') {
                        $availableLangs = array_values(array_filter(array_map('trim', explode(',', $configured)),
                            fn($k) => isset($langLabels[$k])));
                    } else {
                        // 默认：按 lang/*.php 文件存在性推断
                        $availableLangs = [];
                        foreach (array_keys($langLabels) as $code) {
                            if (file_exists(ROOT_PATH . '/lang/' . $code . '.php')) $availableLangs[] = $code;
                        }
                    }
                    ?>
                    <?php if (count($availableLangs) >= 2): ?>
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center gap-1.5 text-sm text-gray-600 hover:text-primary px-2 py-1 rounded hover:bg-gray-50">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18"></path>
                            </svg>
                            <span class="hidden sm:inline"><?php echo $langLabels[$currentAdminLang] ?? $currentAdminLang; ?></span>
                            <i class="ti ti-chevron-down text-base"></i>
                        </button>
                        <div x-show="open" x-cloak @click.away="open = false"
                             class="absolute right-0 mt-2 w-36 bg-white rounded-lg shadow-lg py-1 z-50">
                            <?php foreach ($availableLangs as $code): ?>
                            <button onclick="switchAdminLang('<?php echo $code; ?>')" class="block w-full text-left px-4 py-2 text-sm <?php echo $currentAdminLang === $code ? 'text-primary font-medium bg-blue-50' : 'text-gray-700 hover:bg-gray-100'; ?>"><?php echo $langLabels[$code]; ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <a href="/" target="_blank" class="flex items-center gap-1.5 text-sm text-white bg-primary hover:bg-secondary px-3 py-1.5 rounded-full shadow-sm transition whitespace-nowrap" title="<?php echo __('admin_visit_frontend'); ?>">
                        <i class="ti ti-external-link text-base"></i>
                        <span class="hidden sm:inline"><?php echo __('admin_visit_frontend'); ?></span>
                    </a>

                    <!-- HTML 缓存设置 -->
                    <a href="/admin/setting_cache.php" class="flex items-center gap-1.5 text-sm text-gray-600 hover:text-primary px-2 py-1 rounded hover:bg-gray-50 <?php echo ($currentMenu ?? '') === 'setting_cache' ? 'text-primary' : ''; ?>" title="HTML 缓存">
                        <i class="ti ti-database text-lg"></i>
                        <span class="hidden sm:inline">缓存</span>
                    </a>

                    <!-- AI 助手浮窗（仅在已配置 AI 且不在助手大页时显示） -->
                    <?php if (class_exists('AiService') && aiService()->isConfigured() && $currentMenu !== 'ai_assistant'): ?>
                    <div class="relative" x-data="aiBubble()" x-init="init()">
                        <button @click="toggle()" class="flex items-center gap-1.5 text-sm text-gray-600 hover:text-primary relative px-2 py-1 rounded hover:bg-gray-50" title="<?php echo __('admin_ai_assistant'); ?>">
                            <i class="ti ti-message-dots text-lg"></i>
                            <span class="hidden sm:inline"><?php echo __('admin_ai_assistant'); ?></span>
                            <span x-show="busy" class="absolute -top-0.5 -right-0.5 w-2 h-2 bg-amber-400 rounded-full animate-pulse"></span>
                        </button>

                        <!-- 聊天面板 -->
                        <div x-show="open" x-cloak @click.away="open = false"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 -translate-y-2"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute right-0 mt-2 w-[380px] max-w-[calc(100vw-2rem)] h-[520px] max-h-[calc(100vh-5rem)] bg-white rounded-xl shadow-2xl border border-gray-200 flex flex-col z-50">

                            <div class="flex items-center justify-between px-3 py-2.5 border-b border-gray-100 bg-gradient-to-r from-blue-50 to-white rounded-t-xl flex-shrink-0">
                                <div class="flex items-center gap-2">
                                    <div class="w-7 h-7 rounded-full bg-primary flex items-center justify-center text-white">
                                        <i class="ti ti-message-dots text-sm"></i>
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-gray-800"><?php echo __('admin_ai_assistant'); ?></div>
                                        <div class="text-[11px]" :class="busy ? 'text-amber-600' : 'text-gray-400'" x-text="busy ? '思考中…' : '在线'"></div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-0.5">
                                    <button type="button" @click="clearChat()" class="p-1.5 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100" title="清空">
                                        <i class="ti ti-trash text-base"></i>
                                    </button>
                                    <a href="/admin/ai_assistant.php" class="p-1.5 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100" title="放大">
                                        <i class="ti ti-maximize text-base"></i>
                                    </a>
                                    <button type="button" @click="open = false" class="p-1.5 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100" title="关闭">
                                        <i class="ti ti-x text-base"></i>
                                    </button>
                                </div>
                            </div>

                            <div x-ref="msgArea" class="flex-1 overflow-y-auto p-3 space-y-2 bg-gray-50">
                                <template x-if="messages.length === 0">
                                    <div class="text-center text-gray-400 text-xs py-6 leading-relaxed">
                                        试试问我：<br>
                                        <button type="button" @click="quickPrompt('列出最近 5 篇草稿')" class="inline-block mt-2 px-2.5 py-1 bg-white border border-gray-200 rounded-full text-xs text-gray-600 hover:border-primary hover:text-primary cursor-pointer">列出最近 5 篇草稿</button><br>
                                        <button type="button" @click="quickPrompt('给文章 #1 生成 SEO 摘要')" class="inline-block mt-1 px-2.5 py-1 bg-white border border-gray-200 rounded-full text-xs text-gray-600 hover:border-primary hover:text-primary cursor-pointer">给文章 #1 生成 SEO 摘要</button>
                                    </div>
                                </template>
                                <template x-for="(m, i) in messages" :key="i">
                                    <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                                        <template x-if="m.role === 'tool'">
                                            <details class="border border-amber-200 bg-amber-50 rounded-lg px-2 py-1 max-w-full">
                                                <summary class="cursor-pointer text-[11px] font-mono text-amber-900">
                                                    <span x-text="m.ok ? '🔧' : '✗'"></span>
                                                    <span x-text="m.name"></span>
                                                </summary>
                                                <pre class="mt-1 text-[10px] text-amber-900 whitespace-pre-wrap break-all" x-text="m.body"></pre>
                                            </details>
                                        </template>
                                        <template x-if="m.role !== 'tool' && m.role !== 'proposal'">
                                            <div :class="m.role === 'user' ? 'bg-primary text-white' : (m.role === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-white border border-gray-200 text-gray-800')"
                                                 class="rounded-2xl px-3 py-2 max-w-[85%] text-sm whitespace-pre-wrap break-words"
                                                 x-html="m.role === 'user' ? escape(m.text) : linkify(m.text)">
                                            </div>
                                        </template>
                                        <!-- 待确认的写操作提案 -->
                                        <template x-if="m.role === 'proposal'">
                                            <div class="rounded-lg px-3 py-2 w-full max-w-[95%] border"
                                                 :class="m.applied ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'">
                                                <template x-if="!m.applied">
                                                    <div>
                                                        <div class="text-[11px] uppercase tracking-wide text-amber-700 mb-1" x-text="'待确认的改动 (' + m.proposals.length + ')'"></div>
                                                        <template x-for="(p, pi) in m.proposals" :key="pi">
                                                            <div class="flex items-start gap-1.5 py-0.5 text-sm text-gray-800"><span class="text-amber-600">✎</span><span x-text="p.summary || p.label"></span></div>
                                                        </template>
                                                        <div class="mt-2 flex gap-2">
                                                            <button type="button" @click="applyProposals(i)" :disabled="m.applying" class="px-3 py-1 bg-primary text-white text-xs rounded-lg cursor-pointer disabled:opacity-50" x-text="m.applying ? '应用中…' : '确认应用'"></button>
                                                            <button type="button" @click="messages.splice(i,1)" class="px-3 py-1 border border-gray-300 text-gray-500 text-xs rounded-lg cursor-pointer">忽略</button>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="m.applied">
                                                    <div>
                                                        <div class="text-[11px] uppercase tracking-wide text-green-700 mb-1">已应用 ✓</div>
                                                        <template x-for="(a, ai) in m.appliedItems" :key="ai">
                                                            <div class="flex items-center justify-between gap-2 py-0.5 text-sm text-gray-800">
                                                                <span x-text="a.summary"></span>
                                                                <button type="button" @click="undoChange(a.log_id, i, ai)" :disabled="a.undone" class="text-xs underline cursor-pointer" :class="a.undone ? 'text-green-600' : 'text-gray-500'" x-text="a.undone ? '已撤销' : '撤销'"></button>
                                                            </div>
                                                        </template>
                                                        <template x-if="m.errors && m.errors.length">
                                                            <div class="text-xs text-red-600 mt-1"><template x-for="(er, ei) in m.errors" :key="ei"><div x-text="er"></div></template></div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <form @submit.prevent="send()" class="p-2.5 border-t border-gray-100 flex gap-2 flex-shrink-0">
                                <input x-model="prompt" id="aiAssistantPrompt" name="ai_prompt" type="text"
                                       autocomplete="off" aria-label="<?php echo __('admin_ai_prompt_label'); ?>"
                                       placeholder="<?php echo __('admin_ai_prompt_ph'); ?>"
                                       :disabled="busy"
                                       class="flex-1 px-3 py-1.5 text-sm border border-gray-200 rounded-full focus:border-primary focus:ring-1 focus:ring-primary outline-none disabled:bg-gray-50">
                                <button type="submit" :disabled="busy || !prompt.trim()"
                                        class="w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center disabled:opacity-40 hover:opacity-90 cursor-pointer flex-shrink-0">
                                    <i class="ti ti-send text-base"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <script>
                    function aiBubble() {
                        const KEY = 'yikai_ai_bubble_v1';
                        return {
                            open: false, busy: false, prompt: '', messages: [],
                            init() {
                                try { const s = sessionStorage.getItem(KEY); if (s) this.messages = JSON.parse(s); } catch (e) {}
                                this.$watch('messages', () => {
                                    try { sessionStorage.setItem(KEY, JSON.stringify(this.messages.slice(-50))); } catch (e) {}
                                    this.$nextTick(() => this.scrollBottom());
                                });
                                this.$watch('open', v => { if (v) this.$nextTick(() => this.scrollBottom()); });
                            },
                            toggle() { this.open = !this.open; },
                            scrollBottom() { const el = this.$refs.msgArea; if (el) el.scrollTop = el.scrollHeight; },
                            clearChat() { this.messages = []; try { sessionStorage.removeItem(KEY); } catch (e) {} },
                            quickPrompt(t) { this.prompt = t; this.send(); },
                            escape(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },
                            linkify(s) {
                                const esc = this.escape(s);
                                // 自动识别 /admin/xxx.php 路径
                                return esc.replace(/(\/admin\/[a-z_]+\.php(?:\?[^\s<]*)?)/gi,
                                    '<a href="$1" class="text-primary underline">$1</a>');
                            },
                            async send() {
                                const text = this.prompt.trim();
                                if (!text || this.busy) return;
                                this.messages.push({ role: 'user', text });
                                this.prompt = ''; this.busy = true;
                                try {
                                    const fd = new FormData();
                                    fd.append('prompt', text);
                                    const r = await fetch('/admin/api_ai_agent.php', { method: 'POST', body: fd });
                                    const data = await r.json();
                                    if (data.tool_calls && data.tool_calls.length) {
                                        data.tool_calls.forEach(tc => this.messages.push({
                                            role: 'tool', name: tc.name,
                                            ok: tc.result && tc.result.success,
                                            body: JSON.stringify({ args: tc.args, result: tc.result }, null, 2),
                                        }));
                                    }
                                    if (data.success) this.messages.push({ role: 'ai', text: data.content || '(无回复内容)' });
                                    else this.messages.push({ role: 'error', text: data.error || '未知错误' });
                                    // 待确认的写操作提案
                                    if (data.proposals && data.proposals.length && data.proposal_set_id) {
                                        this.messages.push({ role: 'proposal', proposals: data.proposals, setId: data.proposal_set_id, applied: false, applying: false, appliedItems: [], errors: [] });
                                    }
                                } catch (e) {
                                    this.messages.push({ role: 'error', text: '网络错误：' + e.message });
                                } finally {
                                    this.busy = false;
                                }
                            },
                            async applyProposals(idx) {
                                const m = this.messages[idx];
                                if (!m || m.applying) return;
                                m.applying = true;
                                try {
                                    const fd = new FormData(); fd.append('set_id', m.setId);
                                    const r = await fetch('/admin/api_ai_apply.php', { method: 'POST', body: fd });
                                    const data = await r.json();
                                    if ((data.applied && data.applied.length) || data.success) {
                                        m.appliedItems = (data.applied || []).map(a => ({ summary: a.summary, log_id: a.log_id, undone: false }));
                                        m.errors = data.errors || [];
                                        m.applied = true;
                                    } else {
                                        this.messages.push({ role: 'error', text: data.error || (data.errors || []).join('；') || '应用失败' });
                                    }
                                } catch (e) {
                                    this.messages.push({ role: 'error', text: '网络错误：' + e.message });
                                } finally { m.applying = false; }
                            },
                            async undoChange(logId, mi, ai) {
                                try {
                                    const fd = new FormData(); fd.append('id', logId);
                                    const r = await fetch('/admin/api_ai_undo.php', { method: 'POST', body: fd });
                                    const data = await r.json();
                                    if (data.success) { this.messages[mi].appliedItems[ai].undone = true; }
                                    else { this.messages.push({ role: 'error', text: data.error || '撤销失败' }); }
                                } catch (e) {
                                    this.messages.push({ role: 'error', text: '网络错误：' + e.message });
                                }
                            },
                        };
                    }
                    </script>
                    <?php endif; ?>

                    <!-- 用户菜单 -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center gap-2 text-gray-700 hover:text-primary">
                            <?php $adminAvatar = $adminInfo['avatar'] ?? ''; ?>
                            <?php if ($adminAvatar): ?>
                            <img src="<?php echo e($adminAvatar); ?>" class="w-8 h-8 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center">
                                <i class="ti ti-user text-lg"></i>
                            </div>
                            <?php endif; ?>
                            <span class="hidden sm:inline"><?php echo e($adminInfo['nickname'] ?? ''); ?></span>
                            <i class="ti ti-chevron-down text-base"></i>
                        </button>

                        <div x-show="open" x-cloak @click.away="open = false"
                             class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg py-2 z-50">
                            <a href="/admin/profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100">
                                <?php echo __('admin_profile'); ?>
                            </a>
                            <hr class="my-2">
                            <a href="/admin/logout.php" class="block px-4 py-2 text-red-600 hover:bg-gray-100">
                                <?php echo __('admin_logout'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </header>

            <?php if (defined('DEMO_MODE') && DEMO_MODE): ?>
            <div class="bg-amber-500 text-white text-center py-2 text-sm font-medium">
                <?php echo __('admin_demo_mode'); ?>
            </div>
            <?php endif; ?>

            <!-- 页面内容 -->
            <main class="p-6">
            <?php
            // ── 数据库迁移待执行检测 ──────────────────────────────
            // 「文件已升级、数据库未升级」中间态会让软删除等写操作静默失效（如：删文章刷新又回来）。
            // migrations_ok_version 缓存 = 当前版本时跳过（每次版本变更后仅全量探测一次）；
            // 升级页自身不显示（那里有完整状态）。
            $__pendingMig = 0;
            if (hasPermission('*') && ($currentMenu ?? '') !== 'upgrade'
                && (string) config('migrations_ok_version', '') !== (defined('CMS_VERSION') ? CMS_VERSION : '')) {
                $__pendingMig = pendingMigrationsCount();
                if ($__pendingMig === 0 && defined('CMS_VERSION')) {
                    try { settingModel()->set('migrations_ok_version', CMS_VERSION, 'system'); } catch (\Throwable $e) {}
                }
            }
            ?>
            <?php if ($__pendingMig > 0): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 flex items-start gap-3">
                <i class="ti ti-alert-triangle text-lg text-red-500 mt-0.5"></i>
                <div class="text-sm text-red-800 flex-1">
                    <p class="font-bold">数据库有 <?php echo (int) $__pendingMig; ?> 项升级待执行 —— 程序文件已更新，但数据库结构还没跟上</p>
                    <p class="mt-1">在此状态下部分功能会异常（如删除内容不生效、新功能报错）。请立即执行数据库升级（几秒钟完成，不影响数据）。</p>
                </div>
                <a href="/admin/upgrade.php" class="flex-shrink-0 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm font-medium transition">立即升级数据库 →</a>
            </div>
            <?php endif; ?>
