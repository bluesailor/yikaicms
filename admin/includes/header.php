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

// 侧栏分组折叠：判断当前菜单所属分组
$sidebarGroups = [
    'content' => ['channel', 'page'],
    'product' => ['product', 'product_setting', 'case'],
    'article' => ['article', 'download', 'job'],
    'media'   => ['media', 'album', 'banner', 'timeline', 'link'],
    'data'    => ['form', 'form_design', 'member', 'setting_member'],
    'system'  => ['setting', 'setting_home', 'setting_contact', 'setting_email', 'user', 'role', 'log', 'system', 'upgrade', 'online_upgrade', 'plugin', 'system_log'],
];
$activeGroup = '';
foreach ($sidebarGroups as $group => $_menuItems) {
    if (in_array($currentMenu, $_menuItems)) {
        $activeGroup = $group;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? '后台管理'; ?> - Yikai CMS</title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <script defer src="/assets/alpinejs/collapse.min.js"></script>
    <script defer src="/assets/alpinejs/alpine.min.js"></script>
    <meta name="csrf-token" content="<?php echo csrfToken(); ?>">
    <link rel="stylesheet" href="/assets/wangeditor/style.css">
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
                <a href="/admin/" class="text-xl font-bold text-white">Yikai CMS</a>
            </div>

            <!-- 导航菜单 -->
            <script>
            function sidebarNav() {
                var activeGroup = '<?php echo $activeGroup; ?>';
                var allGroups = ['content','product','article','media','data','system'];
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

                <!-- ── 栏目与内容 ── -->
                <div @click="toggle('content')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span>栏目与内容</span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.content}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.content" x-collapse>

                <a href="/admin/channel.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'channel' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                    </svg>
                    <?php echo __('admin_channel'); ?>
                </a>

                <a href="/admin/page.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'page' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <?php echo __('admin_page'); ?>
                </a>

                </div>

                <!-- ── 商品与展示 ── -->
                <div @click="toggle('product')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span>商品与展示</span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.product}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.product" x-collapse>

                <a href="/admin/product.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['product', 'product_setting']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                    </svg>
                    <?php echo __('admin_product'); ?>
                </a>

                <a href="/admin/case.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'case' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <?php echo __('admin_case'); ?>
                </a>

                </div>

                <!-- ── 文章与资讯 ── -->
                <div @click="toggle('article')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span>文章与资讯</span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.article}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.article" x-collapse>

                <a href="/admin/article.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'article' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path>
                    </svg>
                    <?php echo __('admin_article'); ?>
                </a>

                <a href="/admin/download.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'download' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <?php echo __('admin_download'); ?>
                </a>

                <a href="/admin/job.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'job' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <?php echo __('admin_job'); ?>
                </a>

                </div>

                <!-- ── 媒体与组件 ── -->
                <div @click="toggle('media')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span>媒体与组件</span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.media}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.media" x-collapse>

                <a href="/admin/media.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'media' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <?php echo __('admin_media'); ?>
                </a>

                <a href="/admin/album.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'album' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                    </svg>
                    <?php echo __('admin_album'); ?>
                </a>

                <a href="/admin/banner.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'banner' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    <?php echo __('admin_banner'); ?>
                </a>

                <a href="/admin/timeline.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'timeline' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <?php echo __('admin_timeline'); ?>
                </a>

                <a href="/admin/link.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'link' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                    </svg>
                    <?php echo __('admin_link'); ?>
                </a>

                </div>

                <!-- ── 互动数据 ── -->
                <div @click="toggle('data')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span>互动数据</span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.data}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.data" x-collapse>

                <a href="/admin/form.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['form', 'form_design']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                    </svg>
                    <?php echo __('admin_form'); ?>
                </a>

                <a href="/admin/member.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['member', 'setting_member']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    会员管理
                </a>

                </div>

                <!-- ── 系统设置 ── -->
                <div @click="toggle('system')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span>系统设置</span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.system}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.system" x-collapse>

                <a href="/admin/setting.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <?php echo __('admin_setting'); ?>
                </a>

                <a href="/admin/setting_home.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_home' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    <?php echo __('admin_setting_home'); ?>
                </a>

                <a href="/admin/setting_contact.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_contact' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                    </svg>
                    联系设置
                </a>

                <a href="/admin/setting_email.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_email' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <?php echo __('admin_setting_email'); ?>
                </a>

                <?php if (isSuperAdmin()): ?>
                <a href="/admin/user.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['user', 'role']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    管理员
                </a>

                <a href="/admin/system.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['system', 'system_log']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                    </svg>
                    系统管理
                </a>

                <a href="/admin/upgrade.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['upgrade', 'online_upgrade']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    升级管理
                </a>

                <a href="/admin/plugin.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'plugin' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"></path>
                    </svg>
                    插件管理
                </a>
                <?php endif; ?>
                </div>
                <div style="height:200px"></div>
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
                    <h1 class="text-lg font-semibold text-gray-800"><?php echo $pageTitle ?? '控制台'; ?></h1>
                </div>

                <!-- 右侧工具栏 -->
                <div class="flex items-center gap-4">
                    <a href="/" target="_blank" class="text-gray-500 hover:text-primary" title="访问前台">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                    </a>

                    <!-- 用户菜单 -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" class="flex items-center gap-2 text-gray-700 hover:text-primary">
                            <div class="w-8 h-8 rounded-full bg-primary text-white flex items-center justify-center text-sm font-bold">
                                <?php echo mb_substr($adminInfo['nickname'] ?? 'A', 0, 1, 'UTF-8'); ?>
                            </div>
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

            <!-- 页面内容 -->
            <main class="p-6">
