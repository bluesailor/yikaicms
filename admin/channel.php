<?php
/**
 * ikaiCMS - 栏目管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 栏目类型
$channelTypes = [
    'list' => __('admin_article_list') ?: '文章列表',
    'page' => __('admin_page_static') ?: '单页',
    'product' => __('admin_product') ?: '产品',
    'case' => __('admin_case') ?: '案例',
    'download' => __('admin_download') ?: '下载',
    'job' => __('admin_job') ?: '招聘',
    'album' => __('admin_album') ?: '相册',
    'link' => __('admin_link') ?: '链接',
];

// 获取相册列表（用于相册类型栏目）
$albums = albumModel()->query("SELECT id, name FROM " . albumModel()->tableName() . " ORDER BY sort_order DESC, id ASC");

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'parent_id' => postInt('parent_id'),
            'name' => post('name'),
            'slug' => post('slug'),
            'type' => post('type', 'list'),
            'album_id' => postInt('album_id'),
            'icon' => post('icon'),
            'image' => post('image'),
            'description' => post('description'),
            'content' => $_POST['content'] ?? '',
            'link_url' => post('link_url'),
            'link_target' => post('link_target', '_self'),
            'redirect_type' => post('redirect_type', 'auto'),
            'redirect_url' => post('redirect_url'),
            'seo_title' => post('seo_title'),
            'seo_keywords' => post('seo_keywords'),
            'seo_description' => post('seo_description'),
            'is_nav' => postInt('is_nav'),
            'is_home' => postInt('is_home'),
            'status' => postInt('status', 1),
            'sort_order' => postInt('sort_order'),
            'updated_at' => time(),
        ];

        if (empty($data['name'])) {
            error(__('admin_category_name_required'));
        }

        if (empty($data['slug'])) {
            $data['slug'] = $data['name'];
        }

        // 检查 slug 唯一性
        if (!channelModel()->isSlugUnique($data['slug'], $id)) {
            error(__('admin_url_alias_exists'));
        }

        // 获取旧slug（用于更新页脚导航中的URL）
        $oldSlug = '';
        if ($id > 0) {
            $oldCh = channelModel()->find($id);
            $oldSlug = $oldCh ? $oldCh['slug'] : '';
        }

        if ($id > 0) {
            channelModel()->updateById($id, $data);
            adminLog('channel', 'update', __('admin_edit') . '：' . $data['name']);
        } else {
            $data['created_at'] = time();
            $id = channelModel()->create($data);
            adminLog('channel', 'create', __('admin_add') . '：' . $data['name']);
        }

        // 更新页脚导航
        $isFooterNav = postInt('is_footer_nav');
        $newUrl = '/' . $data['slug'] . '.html';
        $oldUrl = $oldSlug ? '/' . $oldSlug . '.html' : '';
        $footerNav = json_decode(config('footer_nav') ?: '[]', true) ?: [];

        // 移除旧URL（slug可能已变更）
        if ($oldUrl) {
            foreach ($footerNav as &$group) {
                $group['links'] = array_values(array_filter($group['links'] ?? [], function($link) use ($oldUrl) {
                    return ($link['url'] ?? '') !== $oldUrl;
                }));
            }
            unset($group);
        }

        if ($isFooterNav) {
            // 检查新URL是否已存在
            $exists = false;
            foreach ($footerNav as $group) {
                foreach (($group['links'] ?? []) as $link) {
                    if (($link['url'] ?? '') === $newUrl) {
                        $exists = true;
                        break 2;
                    }
                }
            }
            if (!$exists) {
                if (empty($footerNav)) {
                    $footerNav[] = ['title' => '', 'links' => []];
                }
                $footerNav[0]['links'][] = ['name' => $data['name'], 'url' => $newUrl, 'target' => '_self'];
            }
        }

        // 清理空分组
        $footerNav = array_values(array_filter($footerNav, function($g) {
            return !empty($g['links']);
        }));

        settingModel()->set('footer_nav', json_encode($footerNav, JSON_UNESCAPED_UNICODE));

        success(['id' => $id]);
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');

        if (!in_array($field, ['status', 'is_nav', 'is_home'])) {
            error(__('admin_invalid_operation'));
        }

        channelModel()->updateById($id, [$field => $value]);
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');
        $channel = channelModel()->find($id);
        if (!$channel) {
            error(__('admin_category_not_found'));
        }
        if (!empty($channel['is_system'])) {
            error(__('admin_category_system'));
        }
        if (channelModel()->hasChildren($id)) {
            error(__('admin_category_has_children'));
        }
        // 删除关联内容
        db()->execute('DELETE FROM ' . DB_PREFIX . 'contents WHERE channel_id = ?', [$id]);
        // 删除栏目
        channelModel()->deleteById($id);
        adminLog('channel', 'delete', __('admin_delete') . '：' . $channel['name']);
        success();
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        $parentId = postInt('parent_id');
        channelModel()->updateSort($ids);
        success();
    }

    if ($action === 'sort_footer_nav') {
        $urls = $_POST['urls'] ?? [];
        $footerNav = json_decode(config('footer_nav') ?: '[]', true) ?: [];

        // 扁平化所有链接，建立 url => link 映射
        $allLinks = [];
        foreach ($footerNav as $group) {
            foreach (($group['links'] ?? []) as $link) {
                $allLinks[$link['url'] ?? ''] = $link;
            }
        }

        // 按新顺序重建
        $newLinks = [];
        foreach ($urls as $url) {
            if (isset($allLinks[$url])) {
                $newLinks[] = $allLinks[$url];
            }
        }

        $newFooterNav = empty($newLinks) ? [] : [['title' => '', 'links' => $newLinks]];
        settingModel()->set('footer_nav', json_encode($newFooterNav, JSON_UNESCAPED_UNICODE));
        success();
    }

    // 切换产品分类的导航显示
    if ($action === 'toggle_cat_nav') {
        $catId = postInt('cat_id');
        $value = postInt('value');
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'product_categories SET is_nav = ? WHERE id = ?',
            [$value, $catId]
        );
        success();
    }

    exit;
}

// 获取栏目列表（平铺，用于下拉选项）
$channels = channelModel()->getFlatList();

// 后台用：不过滤 status，所有栏目都显示
$channelTree = channelModel()->getTreeAll();

// 获取产品分类（用于产品类型栏目显示）
$productCats = db()->fetchAll(
    'SELECT * FROM ' . DB_PREFIX . 'product_categories WHERE parent_id = 0 ORDER BY sort_order ASC, id ASC'
);
// 按产品栏目ID索引（找出所有 type=product 的顶级栏目）
$productChannelIds = [];
foreach ($channelTree as $ch) {
    if ($ch['type'] === 'product') {
        $productChannelIds[] = (int)$ch['id'];
    }
}
// 获取页脚导航中的URL列表（用于显示菜单位置）
$footerNavUrls = [];
$footerNavData = json_decode(config('footer_nav') ?: '[]', true) ?: [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $footerNavUrls[] = $link['url'] ?? '';
    }
}
$homeInFooterNav = in_array('/', $footerNavUrls);

// 按 footer_nav JSON 顺序构建页脚栏目列表
$footerNavItems = [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $url = $link['url'] ?? '';
        if ($url === '/') {
            $footerNavItems[] = ['type' => 'home', 'link' => $link];
        } else {
            $matched = false;
            foreach ($channelTree as $ch) {
                if ('/' . $ch['slug'] . '.html' === $url) {
                    $footerNavItems[] = ['type' => 'channel', 'channel' => $ch, 'link' => $link];
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $footerNavItems[] = ['type' => 'external', 'link' => $link];
            }
        }
    }
}

// 三分法：主导航 / 页脚导航(由footerNavItems控制) / 未定义
$mainNavChannels = [];
$undefinedChannels = [];
foreach ($channelTree as $ch) {
    $chUrl = '/' . $ch['slug'] . '.html';
    if (!empty($ch['is_nav'])) {
        $mainNavChannels[] = $ch;
    } elseif (!in_array($chUrl, $footerNavUrls)) {
        $undefinedChannels[] = $ch;
    }
}

$activeTab = $_GET['tab'] ?? 'main';

$editId = getInt('edit');
$editChannel = $editId > 0 ? channelModel()->find($editId) : null;

$pageTitle = __('admin_channel');
$currentMenu = 'channel';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- 栏目列表 -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow" x-data="{ tab: '<?php echo e($activeTab); ?>' }">
            <!-- Tab 导航 -->
            <div class="px-6 py-3 border-b flex items-center gap-1 flex-wrap">
                <button @click="tab='main'" :class="tab==='main' ? 'text-primary border-primary' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer">
                    <?= __('admin_main_nav') ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($mainNavChannels) + 1; ?>)</span>
                </button>
                <button @click="tab='footer'" :class="tab==='footer' ? 'text-primary border-primary' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer">
                    <?= __('admin_footer_nav') ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($footerNavItems); ?>)</span>
                </button>
                <button @click="tab='none'" :class="tab==='none' ? 'text-primary border-primary' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer">
                    <?php echo __('admin_channel_unassigned'); ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($undefinedChannels); ?>)</span>
                </button>
                <div class="flex-1"></div>
                <a href="?edit=0&tab=<?php echo e($activeTab); ?>" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm transition inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <?php echo __('admin_channel_add'); ?>
                </a>
            </div>

            <!-- Tab 1: 主导航栏目 -->
            <div x-show="tab==='main'" x-cloak>
                <!-- Home (固定) -->
                <div class="px-4 pt-4">
                    <div class="flex items-center gap-3 px-4 py-3 bg-blue-50 rounded-lg border border-blue-200">
                        <span class="text-blue-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                        </span>
                        <span class="font-medium text-gray-800 flex-1">Home</span>
                        <span class="text-xs text-gray-400"><?php echo __('admin_label_fixed'); ?></span>
                        <a href="/admin/setting_home.php" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                    </div>
                </div>

                <?php if (!empty($mainNavChannels)): ?>
                <div class="p-4 pt-2">
                    <div id="sortable-root" class="space-y-2" data-parent="0">
                        <?php foreach ($mainNavChannels as $ch): ?>
                        <div class="channel-item" data-id="<?php echo $ch['id']; ?>">
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm group">
                                <span class="drag-handle-root cursor-grab text-gray-300 hover:text-gray-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                </span>
                                <span class="font-medium text-gray-800 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=main" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <?php if (in_array('/' . $ch['slug'] . '.html', $footerNavUrls)): ?>
                                <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-600"><?php echo __('admin_footer_nav_badge'); ?></span>
                                <?php endif; ?>
                                <button onclick="toggleField(<?php echo $ch['id']; ?>, 'status', <?php echo $ch['status'] ? 0 : 1; ?>)"
                                        class="text-xs px-2 py-0.5 rounded <?php echo $ch['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                    <?php echo $ch['status'] ? __('admin_show') : __('admin_hide'); ?>
                                </button>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=main" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                <?php if (($ch['type'] ?? '') === 'page'): ?>
                                <?php if (($ch['slug'] ?? '') === 'contact'): ?>
                                <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                <?php else: ?>
                                <a href="/admin/page_edit.php?id=<?php echo $ch['id']; ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (empty($ch['is_system'])): ?>
                                <button onclick="deleteChannel(<?php echo $ch['id']; ?>, '<?php echo e($ch['name']); ?>')"
                                        class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ch['children'])): ?>
                            <div class="sortable-children ml-8 mt-2 space-y-2" data-parent="<?php echo $ch['id']; ?>">
                                <?php foreach ($ch['children'] as $child): ?>
                                <div class="channel-item" data-id="<?php echo $child['id']; ?>">
                                    <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border hover:shadow-sm group">
                                        <span class="drag-handle cursor-grab text-gray-300 hover:text-gray-500">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                        </span>
                                        <span class="text-gray-300 text-xs">└</span>
                                        <span class="text-gray-700 flex-1">
                                            <a href="?edit=<?php echo $child['id']; ?>&tab=main" class="hover:text-primary"><?php echo e($child['name']); ?></a>
                                        </span>
                                        <span class="text-xs text-gray-400"><?php echo $channelTypes[$child['type']] ?? $child['type']; ?></span>
                                        <button onclick="toggleField(<?php echo $child['id']; ?>, 'status', <?php echo $child['status'] ? 0 : 1; ?>)"
                                                class="text-xs px-2 py-0.5 rounded <?php echo $child['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                            <?php echo $child['status'] ? __('admin_show') : __('admin_hide'); ?>
                                        </button>
                                        <a href="?edit=<?php echo $child['id']; ?>&tab=main" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                        <?php if (($child['type'] ?? '') === 'page'): ?>
                                        <?php if (($child['slug'] ?? '') === 'contact'): ?>
                                        <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                        <?php else: ?>
                                        <a href="/admin/page_edit.php?id=<?php echo $child['id']; ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (empty($child['is_system'])): ?>
                                        <button onclick="deleteChannel(<?php echo $child['id']; ?>, '<?php echo e($child['name']); ?>')"
                                                class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($ch['type'] === 'product' && !empty($productCats)): ?>
                            <div class="ml-8 mt-2 space-y-2">
                                <div class="flex items-center gap-2 px-4 py-1.5">
                                    <span class="text-xs text-gray-400"><?= __('admin_product_category_auto') ?></span>
                                    <a href="/admin/product_category.php" class="text-xs text-primary hover:underline"><?= __('admin_category_manage') ?></a>
                                </div>
                                <?php foreach ($productCats as $cat): ?>
                                <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border hover:shadow-sm">
                                    <span class="text-amber-300 text-xs">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"></path></svg>
                                    </span>
                                    <span class="text-gray-300 text-xs">└</span>
                                    <span class="text-gray-700 flex-1"><?php echo e($cat['name']); ?></span>
                                    <span class="text-xs text-amber-500"><?= __('admin_product_category') ?></span>
                                    <button onclick="toggleCatNav(<?php echo $cat['id']; ?>, <?php echo !empty($cat['is_nav']) ? 0 : 1; ?>)"
                                            class="text-xs px-2 py-0.5 rounded <?php echo !empty($cat['is_nav']) ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'; ?>">
                                        <?= __('admin_nav_on') ?> <?php echo !empty($cat['is_nav']) ? 'ON' : 'OFF'; ?>
                                    </button>
                                    <a href="/admin/product_category.php" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm"><?= __('admin_main_nav_empty') ?></div>
                <?php endif; ?>
                <div class="px-4 pb-2">
                    <p class="text-xs text-gray-400"><?= __('admin_nav_sort_tip') ?></p>
                </div>
            </div>

            <!-- Tab 2: 页脚导航栏目 -->
            <div x-show="tab==='footer'" x-cloak>
                <?php if (!empty($footerNavItems)): ?>
                <div class="p-4">
                    <div id="sortable-footer" class="space-y-2">
                        <?php foreach ($footerNavItems as $fi): ?>
                        <?php if ($fi['type'] === 'home'): ?>
                        <div class="footer-nav-item" data-url="/">
                            <div class="flex items-center gap-3 px-4 py-3 bg-blue-50 rounded-lg border border-blue-200">
                                <span class="drag-handle-footer cursor-grab text-blue-300 hover:text-blue-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                </span>
                                <span class="text-blue-300">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                                </span>
                                <span class="font-medium text-gray-800 flex-1"><?php echo e($fi['link']['name'] ?? __('admin_home')); ?></span>
                                <span class="text-xs text-gray-400">/</span>
                                <a href="/admin/setting_home.php" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                            </div>
                        </div>
                        <?php elseif ($fi['type'] === 'channel'): ?>
                        <?php $ch = $fi['channel']; ?>
                        <div class="footer-nav-item" data-url="<?php echo e($fi['link']['url']); ?>">
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm">
                                <span class="drag-handle-footer cursor-grab text-gray-300 hover:text-gray-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                </span>
                                <span class="font-medium text-gray-800 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=footer" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <?php if (!empty($ch['is_nav'])): ?>
                                <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?= __('admin_main_nav') ?></span>
                                <?php endif; ?>
                                <button onclick="toggleField(<?php echo $ch['id']; ?>, 'status', <?php echo $ch['status'] ? 0 : 1; ?>)"
                                        class="text-xs px-2 py-0.5 rounded <?php echo $ch['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                    <?php echo $ch['status'] ? __('admin_show') : __('admin_hide'); ?>
                                </button>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=footer" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                <?php if (($ch['type'] ?? '') === 'page'): ?>
                                <?php if (($ch['slug'] ?? '') === 'contact'): ?>
                                <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                <?php else: ?>
                                <a href="/admin/page_edit.php?id=<?php echo $ch['id']; ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (empty($ch['is_system'])): ?>
                                <button onclick="deleteChannel(<?php echo $ch['id']; ?>, '<?php echo e($ch['name']); ?>')"
                                        class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="footer-nav-item" data-url="<?php echo e($fi['link']['url']); ?>">
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm">
                                <span class="drag-handle-footer cursor-grab text-gray-300 hover:text-gray-500">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                </span>
                                <span class="font-medium text-gray-800 flex-1"><?php echo e($fi['link']['name'] ?? ''); ?></span>
                                <span class="text-xs text-gray-400"><?php echo e($fi['link']['url'] ?? ''); ?></span>
                                <span class="text-xs px-2 py-0.5 rounded bg-yellow-100 text-yellow-600"><?php echo __('admin_external_link'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm"><?= __('admin_footer_nav_empty') ?></div>
                <?php endif; ?>
                <div class="px-4 pb-2">
                    <p class="text-xs text-gray-400"><?= __('admin_footer_nav_tip') ?> <a href="/admin/setting.php?tab=footer" class="text-primary hover:underline"><?php echo __('admin_system_setting_footer'); ?></a></p>
                </div>
            </div>

            <!-- Tab 3: 未定义位置栏目 -->
            <div x-show="tab==='none'" x-cloak>
                <?php if (!empty($undefinedChannels)): ?>
                <div class="p-4">
                    <div class="space-y-2">
                        <?php foreach ($undefinedChannels as $ch): ?>
                        <div>
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm">
                                <span class="font-medium text-gray-800 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=none" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <button onclick="toggleField(<?php echo $ch['id']; ?>, 'status', <?php echo $ch['status'] ? 0 : 1; ?>)"
                                        class="text-xs px-2 py-0.5 rounded <?php echo $ch['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                    <?php echo $ch['status'] ? __('admin_show') : __('admin_hide'); ?>
                                </button>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=none" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                <?php if (($ch['type'] ?? '') === 'page'): ?>
                                <?php if (($ch['slug'] ?? '') === 'contact'): ?>
                                <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                <?php else: ?>
                                <a href="/admin/page_edit.php?id=<?php echo $ch['id']; ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (empty($ch['is_system'])): ?>
                                <button onclick="deleteChannel(<?php echo $ch['id']; ?>, '<?php echo e($ch['name']); ?>')"
                                        class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ch['children'])): ?>
                            <div class="ml-8 mt-2 space-y-2">
                                <?php foreach ($ch['children'] as $child): ?>
                                <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border hover:shadow-sm">
                                    <span class="text-gray-300 text-xs">└</span>
                                    <span class="text-gray-700 flex-1">
                                        <a href="?edit=<?php echo $child['id']; ?>&tab=none" class="hover:text-primary"><?php echo e($child['name']); ?></a>
                                    </span>
                                    <span class="text-xs text-gray-400"><?php echo $channelTypes[$child['type']] ?? $child['type']; ?></span>
                                    <button onclick="toggleField(<?php echo $child['id']; ?>, 'status', <?php echo $child['status'] ? 0 : 1; ?>)"
                                            class="text-xs px-2 py-0.5 rounded <?php echo $child['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                        <?php echo $child['status'] ? __('admin_show') : __('admin_hide'); ?>
                                    </button>
                                    <a href="?edit=<?php echo $child['id']; ?>&tab=none" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                    <?php if (($child['type'] ?? '') === 'page'): ?>
                                    <?php if (($child['slug'] ?? '') === 'contact'): ?>
                                    <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                    <?php else: ?>
                                    <a href="/admin/page_edit.php?id=<?php echo $child['id']; ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (empty($child['is_system'])): ?>
                                    <button onclick="deleteChannel(<?php echo $child['id']; ?>, '<?php echo e($child['name']); ?>')"
                                            class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm"><?= __('admin_all_placed') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 编辑表单 -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow sticky top-20">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo $editChannel ? __('admin_channel_edit') : __('admin_channel_add'); ?></h2>
            </div>
            <form id="channelForm" class="p-6 space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editChannel['id'] ?? 0; ?>">

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_parent_category') ?></label>
                    <select name="parent_id" class="w-full border rounded px-3 py-2">
                        <option value="0"><?php echo __('admin_top_level'); ?></option>
                        <?php foreach ($channels as $ch): ?>
                        <option value="<?php echo $ch['id']; ?>"
                                <?php echo ($editChannel['parent_id'] ?? 0) == $ch['id'] ? 'selected' : ''; ?>
                                <?php echo ($editChannel['id'] ?? 0) == $ch['id'] ? 'disabled' : ''; ?>>
                            <?php echo str_repeat('　', $ch['_level']); ?><?php echo e($ch['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_category_name') ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?php echo e($editChannel['name'] ?? ''); ?>" required
                           class="w-full border rounded px-3 py-2">
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_url_alias') ?></label>
                    <input type="text" name="slug" value="<?php echo e($editChannel['slug'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2" placeholder="<?php echo __('admin_slug_auto_placeholder'); ?>">
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_type') ?></label>
                    <select name="type" id="channelType" class="w-full border rounded px-3 py-2">
                        <?php foreach ($channelTypes as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($editChannel['type'] ?? 'list') === $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="linkFields" class="hidden space-y-4">
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_link_url'); ?></label>
                        <input type="text" name="link_url" value="<?php echo e($editChannel['link_url'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="https://">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_link_target'); ?></label>
                        <select name="link_target" class="w-full border rounded px-3 py-2">
                            <option value="_self" <?php echo ($editChannel['link_target'] ?? '') === '_self' ? 'selected' : ''; ?>><?php echo __('admin_link_target_self'); ?></option>
                            <option value="_blank" <?php echo ($editChannel['link_target'] ?? '') === '_blank' ? 'selected' : ''; ?>><?php echo __('admin_link_target_blank'); ?></option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_page_redirect'); ?></label>
                    <select name="redirect_type" id="redirectType" class="w-full border rounded px-3 py-2">
                        <option value="auto" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>><?= __('admin_redirect_auto') ?></option>
                        <option value="none" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'none' ? 'selected' : ''; ?>><?php echo __('admin_redirect_none_detail'); ?></option>
                        <option value="url" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'url' ? 'selected' : ''; ?>><?php echo __('admin_redirect_url_option'); ?></option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1"><?= __('admin_redirect_type') ?></p>
                </div>

                <div id="redirectUrlField" class="hidden">
                    <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_redirect_url_label'); ?></label>
                    <input type="text" name="redirect_url" value="<?php echo e($editChannel['redirect_url'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2" placeholder="/about/company.html">
                    <p class="text-xs text-gray-400 mt-1"><?php echo __('admin_redirect_url_hint'); ?></p>
                </div>

                <div id="albumFields" class="hidden">
                    <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_related_album'); ?></label>
                    <select name="album_id" class="w-full border rounded px-3 py-2">
                        <option value="0"><?php echo __('admin_select_album'); ?></option>
                        <?php foreach ($albums as $alb): ?>
                        <option value="<?php echo $alb['id']; ?>" <?php echo ($editChannel['album_id'] ?? 0) == $alb['id'] ? 'selected' : ''; ?>>
                            <?php echo e($alb['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-1"><?= __('admin_album') ?></p>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_description') ?></label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"><?php echo e($editChannel['description'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_sort_order'); ?></label>
                        <input type="number" name="sort_order" value="<?php echo $editChannel['sort_order'] ?? 0; ?>"
                               class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_status'); ?></label>
                        <select name="status" class="w-full border rounded px-3 py-2">
                            <option value="1" <?php echo ($editChannel['status'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo __('admin_show'); ?></option>
                            <option value="0" <?php echo ($editChannel['status'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo __('admin_hide'); ?></option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_menu_position') ?></label>
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_nav" value="1" <?php echo ($editChannel['is_nav'] ?? 1) ? 'checked' : ''; ?> class="mr-2">
                            <?= __('admin_main_menu') ?>
                        </label>
                        <label class="flex items-center">
                            <?php
                            $editInFooterNav = false;
                            if ($editChannel) {
                                $editChannelUrl = '/' . ($editChannel['slug'] ?? '') . '.html';
                                $editInFooterNav = in_array($editChannelUrl, $footerNavUrls);
                            }
                            ?>
                            <input type="checkbox" name="is_footer_nav" value="1" <?php echo $editInFooterNav ? 'checked' : ''; ?> class="mr-2">
                            <?= __('admin_footer_nav') ?>
                        </label>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <p class="text-gray-500 text-sm mb-2"><?php echo __('admin_seo_settings'); ?></p>
                    <div class="space-y-3">
                        <input type="text" name="seo_title" value="<?php echo e($editChannel['seo_title'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="<?php echo __('admin_seo_title'); ?>">
                        <input type="text" name="seo_keywords" value="<?php echo e($editChannel['seo_keywords'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="<?php echo __('admin_seo_keywords'); ?>">
                        <textarea name="seo_description" rows="2" class="w-full border rounded px-3 py-2"
                                  placeholder="<?php echo __('admin_seo_description'); ?>"><?php echo e($editChannel['seo_description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <?php if ($editChannel && ($editChannel['type'] ?? '') === 'page'): ?>
                <?php if (($editChannel['slug'] ?? '') === 'contact'): ?>
                <a href="/admin/setting_contact.php"
                   class="block w-full text-center bg-gray-700 hover:bg-gray-800 text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    <?php echo __('admin_setting_contact'); ?>
                </a>
                <?php else: ?>
                <a href="/admin/page_edit.php?id=<?php echo $editChannel['id']; ?>"
                   class="block w-full text-center bg-gray-700 hover:bg-gray-800 text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    <?php echo __('admin_content_edit'); ?>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <?php echo __('admin_save'); ?>
                    </button>
                    <?php if ($editChannel): ?>
                    <a href="?" class="px-4 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        <?php echo __('admin_cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 显示/隐藏类型相关字段
document.getElementById('channelType').addEventListener('change', function() {
    document.getElementById('linkFields').classList.toggle('hidden', this.value !== 'link');
    document.getElementById('albumFields').classList.toggle('hidden', this.value !== 'album');
});
document.getElementById('channelType').dispatchEvent(new Event('change'));

// 跳转类型联动
document.getElementById('redirectType').addEventListener('change', function() {
    document.getElementById('redirectUrlField').classList.toggle('hidden', this.value !== 'url');
});
document.getElementById('redirectType').dispatchEvent(new Event('change'));

// 当前 tab
var currentTab = new URLSearchParams(location.search).get('tab') || 'main';

// 表单提交
document.getElementById('channelForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(function() { location.href = '?tab=' + currentTab; }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed')); ?>, 'error');
    }
});

// 删除栏目
async function deleteChannel(id, name) {
    if (!confirm('<?= __('admin_confirm_delete') ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg, 'error');
    }
}

// 切换产品分类导航显示
async function toggleCatNav(catId, value) {
    const formData = new FormData();
    formData.append('action', 'toggle_cat_nav');
    formData.append('cat_id', catId);
    formData.append('value', value);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        location.reload();
    } else {
        showMessage(data.msg, 'error');
    }
}

// 切换字段
async function toggleField(id, field, value) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        location.reload();
    } else {
        showMessage(data.msg, 'error');
    }
}

// 保存排序
async function saveSort(container) {
    var parentId = container.dataset.parent;
    var items = container.querySelectorAll(':scope > .channel-item');
    var formData = new FormData();
    formData.append('action', 'sort');
    formData.append('parent_id', parentId);
    for (var i = 0; i < items.length; i++) {
        formData.append('ids[]', items[i].dataset.id);
    }

    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage(<?php echo json_encode(__('admin_sort_saved')); ?>);
        }
    } catch (err) {}
}

// 初始化拖放排序
// 顶级栏目排序（主导航）
var root = document.getElementById('sortable-root');
if (root) {
    new Sortable(root, {
        handle: '.drag-handle-root',
        animation: 200,
        ghostClass: 'opacity-30',
        chosenClass: 'shadow-lg',
        onEnd: function() { saveSort(root); }
    });
}

// 子栏目排序
document.querySelectorAll('.sortable-children').forEach(function(el) {
    new Sortable(el, {
        handle: '.drag-handle',
        animation: 200,
        ghostClass: 'opacity-30',
        chosenClass: 'shadow-lg',
        onEnd: function() { saveSort(el); }
    });
});

// 页脚导航排序
var footerSortable = document.getElementById('sortable-footer');
if (footerSortable) {
    new Sortable(footerSortable, {
        handle: '.drag-handle-footer',
        animation: 200,
        ghostClass: 'opacity-30',
        chosenClass: 'shadow-lg',
        onEnd: function() { saveFooterSort(footerSortable); }
    });
}

async function saveFooterSort(container) {
    var items = container.querySelectorAll(':scope > .footer-nav-item');
    var formData = new FormData();
    formData.append('action', 'sort_footer_nav');
    items.forEach(function(item) {
        formData.append('urls[]', item.dataset.url);
    });
    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage(<?php echo json_encode(__('admin_sort_saved')); ?>);
        }
    } catch (err) {}
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
