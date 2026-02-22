<?php
/**
 * Yikai CMS - 单页管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'create') {
        $name = post('name');
        if (empty($name)) {
            error('请输入页面名称');
        }
        $slug = resolveSlug('', $name, 'channels', 0);
        $parentId = postInt('parent_id');
        $id = channelModel()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
            'type' => 'page',
            'status' => 1,
            'is_nav' => 1,
            'sort_order' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        adminLog('page', 'create', '创建单页：' . $name);
        success(['id' => $id]);
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $channel = channelModel()->find($id);
        $newStatus = $channel['status'] ? 0 : 1;
        channelModel()->updateById($id, ['status' => $newStatus, 'updated_at' => time()]);
        adminLog('page', 'toggle', "切换单页状态ID: $id");
        success(['status' => $newStatus]);
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
        // 检查是否有子栏目
        $childCount = channelModel()->count(['parent_id' => $id]);
        if ($childCount > 0) {
            error('该栏目下有子栏目，请先删除子栏目');
        }
        // 删除关联内容
        contentModel()->query("DELETE FROM " . contentModel()->tableName() . " WHERE channel_id = ?", [$id]);
        // 删除栏目
        channelModel()->deleteById($id);
        adminLog('page', 'delete', '删除单页：' . $channel['name']);
        success();
    }

    exit;
}

// 获取所有单页类型的栏目
$pages = channelModel()->query(
    'SELECT c.*, p.name as parent_name FROM ' . channelModel()->tableName() . ' c
     LEFT JOIN ' . channelModel()->tableName() . ' p ON c.parent_id = p.id
     WHERE c.type = ? ORDER BY c.parent_id ASC, c.sort_order ASC, c.id ASC',
    ['page']
);

// 获取页脚导航URL列表
$footerNavUrls = [];
$footerNavData = json_decode(config('footer_nav') ?: '[]', true) ?: [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $footerNavUrls[] = $link['url'] ?? '';
    }
}

$pageTitle = '单页管理';
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <p class="text-gray-500">管理公司简介、企业文化等独立页面内容。单页内容直接存储在栏目中。</p>
    <button onclick="showCreateModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded transition inline-flex items-center gap-1 whitespace-nowrap cursor-pointer">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
        添加单页
    </button>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">页面名称</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">所属栏目</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">URL</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">菜单位置</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">排序</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($pages as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($item['image']): ?>
                            <img src="<?php echo e($item['image']); ?>" class="w-12 h-8 object-cover rounded">
                            <?php endif; ?>
                            <div>
                                <div class="font-medium"><?php echo e($item['name']); ?></div>
                                <?php if ($item['description']): ?>
                                <div class="text-xs text-gray-400"><?php echo e(cutStr($item['description'], 30)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($item['parent_name']): ?>
                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                            <?php echo e($item['parent_name']); ?>
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-gray-400">顶级</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded">/<?php echo e($item['slug']); ?>.html</code>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $itemUrl = '/' . $item['slug'] . '.html';
                        $inMain = !empty($item['is_nav']);
                        $inFooter = in_array($itemUrl, $footerNavUrls);
                        ?>
                        <?php if ($inMain): ?>
                        <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600">主导航</span>
                        <?php endif; ?>
                        <?php if ($inFooter): ?>
                        <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-600">页脚</span>
                        <?php endif; ?>
                        <?php if (!$inMain && !$inFooter): ?>
                        <span class="text-xs text-gray-400">无</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500">
                        <?php echo $item['sort_order']; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? '显示' : '隐藏'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="/admin/page_edit.php?id=<?php echo $item['id']; ?>"
                           class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            编辑内容
                        </a>
                        <a href="/<?php echo e($item['slug']); ?>.html" target="_blank"
                           class="text-gray-500 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                            预览
                        </a>
                        <?php if (empty($item['is_system'])): ?>
                        <button onclick="deletePage(<?php echo $item['id']; ?>, '<?php echo e($item['name']); ?>')"
                                class="text-red-500 hover:underline text-sm inline-flex items-center gap-1 ml-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            删除
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($pages)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">暂无单页数据</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 添加单页弹窗 -->
<div id="createModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800">添加单页</h3>
            <button type="button" onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1">页面名称 <span class="text-red-500">*</span></label>
                <input type="text" id="createName" class="w-full border rounded px-4 py-2" placeholder="如：公司简介">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">所属栏目</label>
                <select id="createParent" class="w-full border rounded px-4 py-2">
                    <option value="0">顶级（无上级）</option>
                    <?php foreach ($pages as $p): ?>
                    <?php if (!$p['parent_id']): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo e($p['name']); ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="createPage()" class="w-full bg-primary hover:bg-secondary text-white py-2 rounded transition">
                创建并编辑内容
            </button>
        </div>
    </div>
</div>

<script>
function showCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
    document.getElementById('createModal').classList.add('flex');
    document.getElementById('createName').value = '';
    document.getElementById('createName').focus();
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
    document.getElementById('createModal').classList.remove('flex');
}

async function createPage() {
    var name = document.getElementById('createName').value.trim();
    if (!name) { showMessage('请输入页面名称', 'error'); return; }
    var formData = new FormData();
    formData.append('action', 'create');
    formData.append('name', name);
    formData.append('parent_id', document.getElementById('createParent').value);
    var response = await fetch('', { method: 'POST', body: formData });
    var data = await safeJson(response);
    if (data.code === 0) {
        showMessage('创建成功，正在跳转...');
        setTimeout(function() { location.href = '/admin/page_edit.php?id=' + data.data.id; }, 500);
    } else {
        showMessage(data.msg, 'error');
    }
}

async function deletePage(id, name) {
    if (!confirm('确定要删除单页「' + name + '」吗？\n关联的页面内容也会一并删除，此操作不可恢复。')) return;
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

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '显示';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '隐藏';
        }
        showMessage('状态已更新');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
