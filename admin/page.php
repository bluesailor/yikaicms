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

$pageTitle = '单页管理';
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <p class="text-gray-500">管理公司简介、企业文化等独立页面内容。单页内容直接存储在栏目中。</p>
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
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">暂无单页数据</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
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
