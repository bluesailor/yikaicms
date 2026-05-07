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
    'content'    => ['channel', 'page'],
    'product'    => ['product', 'product_setting', 'case'],
    'article'    => ['article', 'download', 'job'],
    'media'      => ['media', 'album', 'banner', 'timeline', 'link'],
    'data'       => ['form', 'form_design', 'member', 'setting_member'],
    'site'       => ['setting', 'setting_home', 'setting_contact', 'setting_social', 'setting_email', 'setting_seo'],
    'appearance' => ['theme', 'plugin', 'extfield', 'setting_ai', 'ai_assistant'],
    'system'     => ['user', 'role', 'setting_security', 'system', 'system_log', 'log', 'database', 'upgrade', 'online_upgrade'],
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
<html lang="<?php echo getLang(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? '后台管理'; ?> - <?php echo e(config('admin_title', 'Yikai CMS')); ?></title>
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
                    <span class="text-xl font-bold text-white"><?php echo e(config('admin_title', 'Yikai CMS')); ?></span>
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

                <!-- ── 栏目与内容 ── -->
                <div @click="toggle('content')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span><?php echo __('admin_group_content'); ?></span>
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
                    <span><?php echo __('admin_group_product'); ?></span>
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
                    <span><?php echo __('admin_group_article'); ?></span>
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
                    <span><?php echo __('admin_group_media'); ?></span>
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
                    <span><?php echo __('admin_group_data'); ?></span>
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
                    <?php echo __('admin_member'); ?>
                </a>

                </div>

                <!-- ── 站点设置 ── -->
                <div @click="toggle('site')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span><?php echo __('admin_group_site'); ?></span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.site}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.site" x-collapse>

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
                    <?php echo __('admin_setting_contact'); ?>
                </a>

                <a href="/admin/setting_social.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_social' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path>
                    </svg>
                    <?php echo __('admin_setting_social'); ?>
                </a>

                <a href="/admin/setting_email.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_email' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <?php echo __('admin_setting_email'); ?>
                </a>

                <a href="/admin/setting_seo.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_seo' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    <?php echo __('admin_setting_seo'); ?>
                </a>

                </div>

                <!-- ── 外观与扩展 ── -->
                <div @click="toggle('appearance')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span><?php echo __('admin_group_appearance'); ?></span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.appearance}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.appearance" x-collapse>

                <a href="/admin/theme.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'theme' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                    </svg>
                    <?php echo __('admin_theme'); ?>
                </a>

                <a href="/admin/plugin.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'plugin' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"></path>
                    </svg>
                    <?php echo __('admin_plugin'); ?>
                </a>

                <a href="/admin/extfield.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'extfield' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h10"/>
                    </svg>
                    <?php echo __('admin_extfield'); ?>
                </a>

                <a href="/admin/setting_ai.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_ai' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                    </svg>
                    <?php echo __('admin_setting_ai'); ?>
                </a>

                <a href="/admin/ai_assistant.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'ai_assistant' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                    </svg>
                    AI 助手
                </a>

                </div>

                <!-- ── 系统管理 ── -->
                <?php if (isSuperAdmin()): ?>
                <div @click="toggle('system')" class="sidebar-group px-4 pt-3 pb-1 text-xs text-gray-500 uppercase tracking-wider flex items-center justify-between">
                    <span><?php echo __('admin_group_system'); ?></span>
                    <svg class="w-3 h-3 transition-transform duration-200" :class="{'-rotate-90': !open.system}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                <div x-show="open.system" x-collapse>

                <a href="/admin/user.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['user', 'role']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                    </svg>
                    <?php echo __('admin_user'); ?>
                </a>

                <a href="/admin/setting_security.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'setting_security' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
                    </svg>
                    <?php echo __('admin_setting_security'); ?>
                </a>

                <a href="/admin/system.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['system', 'system_log']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                    </svg>
                    <?php echo __('admin_system_info'); ?>
                </a>

                <a href="/admin/database.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo $currentMenu === 'database' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                    </svg>
                    <?php echo __('admin_database'); ?>
                </a>

                <a href="/admin/upgrade.php" class="sidebar-link flex items-center px-4 py-2 rounded-lg mb-0.5 <?php echo in_array($currentMenu, ['upgrade', 'online_upgrade']) ? 'active' : ''; ?>">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <?php echo __('admin_upgrade'); ?>
                </a>

                </div>
                <?php endif; ?>
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
                    <div class="relative" x-data="adminSearch()" @keydown.window.prevent.ctrl.k="focus()" @keydown.window.prevent.meta.k="focus()">
                        <div class="relative">
                            <input x-ref="input" type="text" x-model="query" @input.debounce.150ms="search()" @focus="open = true" @keydown.escape="close()" @keydown.arrow-down.prevent="moveSel(1)" @keydown.arrow-up.prevent="moveSel(-1)" @keydown.enter.prevent="goSelected()"
                                   placeholder="找页面（Ctrl/⌘+K）"
                                   class="w-44 lg:w-56 pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-full focus:border-primary focus:ring-1 focus:ring-primary outline-none">
                            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <div x-show="open && results.length" x-cloak @click.away="close()"
                             class="absolute right-0 mt-1 w-72 bg-white rounded-lg shadow-xl border border-gray-200 py-1 max-h-96 overflow-y-auto z-50">
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
                            open: false, query: '', results: [], selected: 0,
                            focus() { this.$refs.input.focus(); this.$refs.input.select(); this.open = true; },
                            close() { this.open = false; },
                            moveSel(d) {
                                if (!this.results.length) return;
                                this.selected = (this.selected + d + this.results.length) % this.results.length;
                            },
                            goSelected() {
                                if (this.results[this.selected]) location.href = this.results[this.selected].url;
                            },
                            async search() {
                                const q = this.query.trim();
                                if (!q) { this.results = []; this.selected = 0; return; }
                                try {
                                    const r = await fetch('/admin/api_search.php?q=' + encodeURIComponent(q));
                                    const d = await r.json();
                                    this.results = d.code === 0 ? (d.data || []) : [];
                                    this.selected = 0;
                                } catch (e) { this.results = []; }
                            },
                        };
                    }
                    </script>

                    <!-- 语言切换 (中 / 英) -->
                    <?php $currentAdminLang = config('admin_lang', 'zh-CN'); ?>
                    <div class="flex items-center gap-1 text-sm">
                        <button onclick="switchAdminLang('zh-CN')" class="px-2 py-1 rounded transition <?php echo $currentAdminLang === 'zh-CN' ? 'bg-primary text-white' : 'text-gray-400 hover:text-primary'; ?>">中文</button>
                        <span class="text-gray-300">|</span>
                        <button onclick="switchAdminLang('en')" class="px-2 py-1 rounded transition <?php echo $currentAdminLang === 'en' ? 'bg-primary text-white' : 'text-gray-400 hover:text-primary'; ?>">EN</button>
                    </div>

                    <a href="/" target="_blank" class="text-gray-500 hover:text-primary" title="<?php echo __('admin_visit_frontend'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                        </svg>
                    </a>

                    <!-- AI 助手浮窗（仅在已配置 AI 且不在助手大页时显示） -->
                    <?php if (class_exists('AiService') && aiService()->isConfigured() && $currentMenu !== 'ai_assistant'): ?>
                    <div class="relative" x-data="aiBubble()" x-init="init()">
                        <button @click="toggle()" class="flex items-center gap-1.5 text-sm text-gray-600 hover:text-primary relative px-2 py-1 rounded hover:bg-gray-50" title="AI 助手">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                            </svg>
                            <span class="hidden sm:inline">AI 助手</span>
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
                                        <div class="text-sm font-bold text-gray-800">AI 助手</div>
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
                                <input x-model="prompt" type="text" placeholder="问点什么…"
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
