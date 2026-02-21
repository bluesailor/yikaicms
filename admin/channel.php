<?php
/**
 * Yikai CMS - 栏目管理
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
    'list' => '文章列表',
    'page' => '单页',
    'product' => '产品',
    'case' => '案例',
    'download' => '下载',
    'job' => '招聘',
    'album' => '图库相册',
    'link' => '外部链接',
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
            'link_url' => post('link_url'),
            'link_target' => post('link_target', '_self'),
            'redirect_type' => post('redirect_type', 'auto'),
            'redirect_url' => post('redirect_url'),
            'seo_title' => post('seo_title'),
            'seo_keywords' => post('seo_keywords'),
            'seo_description' => post('seo_description'),
            'is_nav' => postInt('is_nav', 1),
            'is_home' => postInt('is_home'),
            'status' => postInt('status', 1),
            'sort_order' => postInt('sort_order'),
            'updated_at' => time(),
        ];

        if (empty($data['name'])) {
            error('请输入栏目名称');
        }

        if (empty($data['slug'])) {
            $data['slug'] = $data['name'];
        }

        // 检查 slug 唯一性
        if (!channelModel()->isSlugUnique($data['slug'], $id)) {
            error('URL别名已存在');
        }

        if ($id > 0) {
            channelModel()->updateById($id, $data);
            adminLog('channel', 'update', '更新栏目：' . $data['name']);
        } else {
            $data['created_at'] = time();
            $id = channelModel()->create($data);
            adminLog('channel', 'create', '创建栏目：' . $data['name']);
        }

        success(['id' => $id]);
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');

        if (!in_array($field, ['status', 'is_nav', 'is_home'])) {
            error('非法操作');
        }

        channelModel()->updateById($id, [$field => $value]);
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');
        $channel = channelModel()->find($id);
        if (!$channel) {
            error('栏目不存在');
        }
        if (!empty($channel['is_system'])) {
            error('系统预设栏目不可删除，只能隐藏');
        }
        if (channelModel()->hasChildren($id)) {
            error('该栏目下有子栏目，请先删除子栏目');
        }
        // 删除关联内容
        db()->execute('DELETE FROM ' . DB_PREFIX . 'contents WHERE channel_id = ?', [$id]);
        // 删除栏目
        channelModel()->deleteById($id);
        adminLog('channel', 'delete', '删除栏目：' . $channel['name']);
        success();
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        $parentId = postInt('parent_id');
        channelModel()->updateSort($ids);
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
$editId = getInt('edit');
$editChannel = $editId > 0 ? channelModel()->find($editId) : null;

$pageTitle = '栏目管理';
$currentMenu = 'channel';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- 栏目列表 -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h2 class="font-bold text-gray-800">栏目列表 <span class="text-xs text-gray-400 font-normal ml-2">拖动排序</span></h2>
                <a href="?edit=0" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm transition inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    添加栏目
                </a>
            </div>

            <!-- 首页（固定在顶部，不可排序） -->
            <div class="px-4 pt-4">
                <div class="flex items-center gap-3 px-4 py-3 bg-blue-50 rounded-lg border border-blue-200">
                    <span class="text-blue-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    </span>
                    <span class="font-medium text-gray-800 flex-1"><?php echo e(config('nav_home_text', '') ?: '首页'); ?></span>
                    <span class="text-xs text-gray-400">网站首页导航文字</span>
                    <a href="/admin/setting_home.php" class="text-primary hover:underline text-sm">编辑</a>
                </div>
            </div>

            <?php if (empty($channelTree)): ?>
            <div class="px-6 py-8 text-center text-gray-500">暂无栏目</div>
            <?php else: ?>
            <div class="p-4 pt-2">
                <div id="sortable-root" class="space-y-2" data-parent="0">
                    <?php foreach ($channelTree as $ch): ?>
                    <div class="channel-item" data-id="<?php echo $ch['id']; ?>">
                        <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm group">
                            <span class="drag-handle-root cursor-grab text-gray-300 hover:text-gray-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                            </span>
                            <span class="font-medium text-gray-800 flex-1">
                                <a href="?edit=<?php echo $ch['id']; ?>" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                            </span>
                            <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                            <button onclick="toggleField(<?php echo $ch['id']; ?>, 'is_nav', <?php echo $ch['is_nav'] ? 0 : 1; ?>)"
                                    class="text-xs px-2 py-0.5 rounded <?php echo $ch['is_nav'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'; ?>">
                                导航<?php echo $ch['is_nav'] ? '开' : '关'; ?>
                            </button>
                            <button onclick="toggleField(<?php echo $ch['id']; ?>, 'status', <?php echo $ch['status'] ? 0 : 1; ?>)"
                                    class="text-xs px-2 py-0.5 rounded <?php echo $ch['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $ch['status'] ? '显示' : '隐藏'; ?>
                            </button>
                            <a href="?edit=<?php echo $ch['id']; ?>" class="text-primary hover:underline text-sm">编辑</a>
                            <?php if (empty($ch['is_system'])): ?>
                            <button onclick="deleteChannel(<?php echo $ch['id']; ?>, '<?php echo e($ch['name']); ?>')"
                                    class="text-red-400 hover:text-red-600 text-sm">删除</button>
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
                                        <a href="?edit=<?php echo $child['id']; ?>" class="hover:text-primary"><?php echo e($child['name']); ?></a>
                                    </span>
                                    <span class="text-xs text-gray-400"><?php echo $channelTypes[$child['type']] ?? $child['type']; ?></span>
                                    <button onclick="toggleField(<?php echo $child['id']; ?>, 'is_nav', <?php echo $child['is_nav'] ? 0 : 1; ?>)"
                                            class="text-xs px-2 py-0.5 rounded <?php echo $child['is_nav'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'; ?>">
                                        导航<?php echo $child['is_nav'] ? '开' : '关'; ?>
                                    </button>
                                    <button onclick="toggleField(<?php echo $child['id']; ?>, 'status', <?php echo $child['status'] ? 0 : 1; ?>)"
                                            class="text-xs px-2 py-0.5 rounded <?php echo $child['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                                        <?php echo $child['status'] ? '显示' : '隐藏'; ?>
                                    </button>
                                    <a href="?edit=<?php echo $child['id']; ?>" class="text-primary hover:underline text-sm">编辑</a>
                                    <?php if (empty($child['is_system'])): ?>
                                    <button onclick="deleteChannel(<?php echo $child['id']; ?>, '<?php echo e($child['name']); ?>')"
                                            class="text-red-400 hover:text-red-600 text-sm">删除</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($ch['type'] === 'product' && !empty($productCats)): ?>
                        <!-- 同步的产品分类 -->
                        <div class="ml-8 mt-2 space-y-2">
                            <div class="flex items-center gap-2 px-4 py-1.5">
                                <span class="text-xs text-gray-400">以下为产品分类（自动同步）</span>
                                <a href="/admin/product_category.php" class="text-xs text-primary hover:underline">管理分类</a>
                            </div>
                            <?php foreach ($productCats as $cat): ?>
                            <div class="flex items-center gap-3 px-4 py-2.5 bg-amber-50 rounded-lg border border-amber-200 hover:shadow-sm">
                                <span class="text-amber-300 text-xs">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"></path></svg>
                                </span>
                                <span class="text-gray-300 text-xs">└</span>
                                <span class="text-gray-700 flex-1"><?php echo e($cat['name']); ?></span>
                                <span class="text-xs text-amber-500">产品分类</span>
                                <button onclick="toggleCatNav(<?php echo $cat['id']; ?>, <?php echo !empty($cat['is_nav']) ? 0 : 1; ?>)"
                                        class="text-xs px-2 py-0.5 rounded <?php echo !empty($cat['is_nav']) ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'; ?>">
                                    导航<?php echo !empty($cat['is_nav']) ? '开' : '关'; ?>
                                </button>
                                <a href="/admin/product_category.php" class="text-primary hover:underline text-sm">编辑</a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 编辑表单 -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow sticky top-20">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo $editChannel ? '编辑栏目' : '添加栏目'; ?></h2>
            </div>
            <form id="channelForm" class="p-6 space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editChannel['id'] ?? 0; ?>">

                <div>
                    <label class="block text-gray-700 text-sm mb-1">上级栏目</label>
                    <select name="parent_id" class="w-full border rounded px-3 py-2">
                        <option value="0">顶级栏目</option>
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
                    <label class="block text-gray-700 text-sm mb-1">栏目名称 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?php echo e($editChannel['name'] ?? ''); ?>" required
                           class="w-full border rounded px-3 py-2">
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1">URL别名</label>
                    <input type="text" name="slug" value="<?php echo e($editChannel['slug'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2" placeholder="留空自动生成">
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1">栏目类型</label>
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
                        <label class="block text-gray-700 text-sm mb-1">链接地址</label>
                        <input type="text" name="link_url" value="<?php echo e($editChannel['link_url'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="https://">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm mb-1">打开方式</label>
                        <select name="link_target" class="w-full border rounded px-3 py-2">
                            <option value="_self" <?php echo ($editChannel['link_target'] ?? '') === '_self' ? 'selected' : ''; ?>>当前窗口</option>
                            <option value="_blank" <?php echo ($editChannel['link_target'] ?? '') === '_blank' ? 'selected' : ''; ?>>新窗口</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1">页面跳转</label>
                    <select name="redirect_type" id="redirectType" class="w-full border rounded px-3 py-2">
                        <option value="auto" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>>自动跳转到第一个子栏目</option>
                        <option value="none" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'none' ? 'selected' : ''; ?>>不跳转，显示自身内容</option>
                        <option value="url" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'url' ? 'selected' : ''; ?>>跳转到指定地址</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">有子栏目时的访问行为</p>
                </div>

                <div id="redirectUrlField" class="hidden">
                    <label class="block text-gray-700 text-sm mb-1">跳转地址</label>
                    <input type="text" name="redirect_url" value="<?php echo e($editChannel['redirect_url'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2" placeholder="/about/company.html">
                    <p class="text-xs text-gray-400 mt-1">支持站内路径或完整URL</p>
                </div>

                <div id="albumFields" class="hidden">
                    <label class="block text-gray-700 text-sm mb-1">关联相册</label>
                    <select name="album_id" class="w-full border rounded px-3 py-2">
                        <option value="0">-- 请选择相册 --</option>
                        <?php foreach ($albums as $alb): ?>
                        <option value="<?php echo $alb['id']; ?>" <?php echo ($editChannel['album_id'] ?? 0) == $alb['id'] ? 'selected' : ''; ?>>
                            <?php echo e($alb['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">选择要在此栏目显示的图库相册</p>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1">栏目描述</label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"><?php echo e($editChannel['description'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm mb-1">排序</label>
                        <input type="number" name="sort_order" value="<?php echo $editChannel['sort_order'] ?? 0; ?>"
                               class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm mb-1">状态</label>
                        <select name="status" class="w-full border rounded px-3 py-2">
                            <option value="1" <?php echo ($editChannel['status'] ?? 1) == 1 ? 'selected' : ''; ?>>显示</option>
                            <option value="0" <?php echo ($editChannel['status'] ?? 1) == 0 ? 'selected' : ''; ?>>隐藏</option>
                        </select>
                    </div>
                </div>

                <div class="flex gap-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_nav" value="1" <?php echo ($editChannel['is_nav'] ?? 1) ? 'checked' : ''; ?> class="mr-2">
                        显示在导航
                    </label>
                    <label class="flex items-center">
                        <input type="checkbox" name="is_home" value="1" <?php echo ($editChannel['is_home'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                        显示在首页
                    </label>
                </div>

                <div class="border-t pt-4">
                    <p class="text-gray-500 text-sm mb-2">SEO设置</p>
                    <div class="space-y-3">
                        <input type="text" name="seo_title" value="<?php echo e($editChannel['seo_title'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="SEO标题">
                        <input type="text" name="seo_keywords" value="<?php echo e($editChannel['seo_keywords'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="SEO关键词">
                        <textarea name="seo_description" rows="2" class="w-full border rounded px-3 py-2"
                                  placeholder="SEO描述"><?php echo e($editChannel['seo_description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        保存
                    </button>
                    <?php if ($editChannel): ?>
                    <a href="?" class="px-4 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        取消</a>
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

// 表单提交
document.getElementById('channelForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('保存成功');
            setTimeout(function() { location.href = '?'; }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});

// 删除栏目
async function deleteChannel(id, name) {
    if (!confirm('确定要删除栏目「' + name + '」吗？\n关联的页面内容也会一并删除，此操作不可恢复。')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
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
            showMessage('排序已保存');
        }
    } catch (err) {}
}

// 初始化拖放排序
// 顶级栏目排序
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
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
