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
?>
<!DOCTYPE html>
<html lang="<?php echo getLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? '后台管理'; ?> - <?php echo e((config('admin_title') ?: config('site_name', 'YikaiCMS'))); ?></title>
    <link rel="icon" href="<?php echo e(config('site_favicon', '/favicon.ico')); ?>">
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <script defer src="/assets/alpinejs/collapse.min.js"></script>
    <script defer src="/assets/alpinejs/alpine.min.js"></script>
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <?php do_action('ik_admin_head'); ?>
</head>
<body class="bg-gray-100" x-data="{ mobileMenu: false }">
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
        <aside class="fixed inset-y-0 left-0 z-50 w-64 bg-sidebar text-gray-300 transition-transform duration-300 ease-in-out -translate-x-full lg:translate-x-0 overflow-y-auto"
               :class="mobileMenu ? 'translate-x-0' : ''">
            <!-- Logo -->
            <div class="h-16 flex items-center justify-center border-b border-gray-700">
                <?php $adminLogo = config('admin_logo', ''); ?>
                <a href="/admin/" class="flex items-center gap-2">
                    <?php if ($adminLogo): ?>
                    <img src="<?php echo e($adminLogo); ?>" alt="" class="h-8">
                    <?php else: ?>
                    <span class="text-xl font-bold text-white"><?php echo e((config('admin_title') ?: config('site_name', 'YikaiCMS'))); ?></span>
                    <?php endif; ?>
                </a>
            </div>

            <!-- 导航菜单 -->
            <script>
            function sidebarNav() {
                var activeGroup = '<?php echo $activeGroup; ?>';
                var allGroups = ['content','product','article','media','data','site','appearance','system'];
                var saved = {};
                try { saved = JSON.parse(localStorage.getItem('sidebarState') || '{}'); } catch(e) {}
                var open = {};
                allGroups.forEach(function(g) {
                    open[g] = g === activeGroup ? true : (saved.hasOwnProperty(g) ? saved[g] : true);
                });
                return {
                    open: open,
                    toggle: function(g) {
                        this.open[g] = !this.open[g];
                        localStorage.setItem('sidebarState', JSON.stringify(this.open));
                    }
                };
            }
            </script>
            <nav class="mt-4 px-3" x-data="sidebarNav()">
                <!-- 控制台 -->
                <a href="/admin/" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'dashboard' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    <?php echo __('admin_dashboard'); ?>
                </a>

                <?php
                // ── 侧边栏菜单（数据驱动；通过 register_admin_menu() / 'admin_sidebar' filter 可扩展）──
                foreach ($sidebarMenu as $groupKey => $navGroup):
                    if (!empty($navGroup['super_only']) && !isSuperAdmin()) continue;
                    if (empty($navGroup['items'])) continue;
                ?>
                <div @click="toggle('<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span><?= htmlspecialchars((string)$navGroup['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>" x-collapse>
                    <?php foreach ($navGroup['items'] as $_item): ?>
                        <?= renderAdminMenuItem($_item, (string)$currentMenu) ?>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php do_action('admin_menu', $currentMenu); ?>
                <div class="mt-6 mb-4 px-1">
                    <a href="/admin/logout.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg text-gray-400 hover:text-red-400 hover:bg-gray-800 transition">
                        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        <?php echo __('admin_safe_logout'); ?>
                    </a>
                </div>
                <div style="height:20px"></div>
            </nav>
        </aside>

        <!-- 主内容区 -->
        <div class="flex-1 lg:ml-64">
            <!-- 顶部导航 -->
            <header class="h-16 bg-white shadow-sm flex items-center justify-between px-6 sticky top-0 z-40">
                <!-- 移动端菜单按钮 -->
                <button @click="mobileMenu = !mobileMenu" class="lg:hidden text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
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
                            <svg style="position:absolute; left:12px; top:50%; transform:translateY(-50%); width:14px; height:14px; pointer-events:none;" class="text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
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
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        <div x-show="open" x-cloak @click.away="open = false"
                             class="absolute right-0 mt-2 w-36 bg-white rounded-lg shadow-lg py-1 z-50">
                            <?php foreach ($availableLangs as $code): ?>
                            <button onclick="switchAdminLang('<?php echo $code; ?>')" class="block w-full text-left px-4 py-2 text-sm <?php echo $currentAdminLang === $code ? 'text-primary font-medium bg-blue-50' : 'text-gray-700 hover:bg-gray-100'; ?>"><?php echo $langLabels[$code]; ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <a href="/" target="_blank" class="flex items-center justify-center w-9 h-9 rounded-full text-white bg-primary hover:bg-secondary shadow-sm transition" title="<?php echo __('admin_visit_frontend'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                    </a>

                    <!-- HTML 缓存设置 -->
                    <a href="/admin/setting_cache.php" class="flex items-center gap-1.5 text-sm text-gray-600 hover:text-primary px-2 py-1 rounded hover:bg-gray-50 <?php echo ($currentMenu ?? '') === 'setting_cache' ? 'text-primary' : ''; ?>" title="HTML 缓存">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                        </svg>
                        <span class="hidden sm:inline">缓存</span>
                    </a>

                    <!-- AI 助手浮窗（仅在已配置 AI 且不在助手大页时显示） -->
                    <?php if (class_exists('AiService') && aiService()->isConfigured() && $currentMenu !== 'ai_assistant'): ?>
                    <div class="relative" x-data="aiBubble()" x-init="init()">
                        <button @click="toggle()" class="flex items-center gap-1.5 text-sm text-gray-600 hover:text-primary relative px-2 py-1 rounded hover:bg-gray-50" title="<?php echo __('admin_ai_assistant'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
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
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                                    </div>
                                    <div>
                                        <div class="text-sm font-bold text-gray-800"><?php echo __('admin_ai_assistant'); ?></div>
                                        <div class="text-[11px]" :class="busy ? 'text-amber-600' : 'text-gray-400'" x-text="busy ? '思考中…' : '在线'"></div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-0.5">
                                    <button type="button" @click="clearChat()" class="p-1.5 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100" title="清空">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22"/></svg>
                                    </button>
                                    <a href="/admin/ai_assistant.php" class="p-1.5 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100" title="放大">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                                    </a>
                                    <button type="button" @click="open = false" class="p-1.5 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100" title="关闭">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
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
                                        <template x-if="m.role !== 'tool'">
                                            <div :class="m.role === 'user' ? 'bg-primary text-white' : (m.role === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-white border border-gray-200 text-gray-800')"
                                                 class="rounded-2xl px-3 py-2 max-w-[85%] text-sm whitespace-pre-wrap break-words"
                                                 x-html="m.role === 'user' ? escape(m.text) : linkify(m.text)">
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
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
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
                                } catch (e) {
                                    this.messages.push({ role: 'error', text: '网络错误：' + e.message });
                                } finally {
                                    this.busy = false;
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
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            </div>
                            <?php endif; ?>
                            <span class="hidden sm:inline"><?php echo e($adminInfo['nickname'] ?? ''); ?></span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
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
