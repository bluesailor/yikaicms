<?php
/**
 * ikaiCMS - 文章管理
 *
 * 合并后使用 ContentModel + ChannelModel
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

// 获取 news 栏目及其子栏目 ID
$newsChannel = getChannelBySlug('news');
$newsChannelId = $newsChannel ? (int)$newsChannel['id'] : 0;
$newsChildIds = $newsChannelId > 0 ? channelModel()->getChildIds($newsChannelId) : [];

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        contentModel()->deleteById($id);
        adminLog('article', 'delete', "删除文章ID: $id");
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            contentModel()->deleteByIds($ids);
            adminLog('article', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'batch_publish') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->execute("UPDATE " . DB_PREFIX . "contents SET status = 1 WHERE id IN ({$placeholders})", $ids);
            adminLog('article', 'batch_publish', '批量发布：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'batch_unpublish') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->execute("UPDATE " . DB_PREFIX . "contents SET status = 0 WHERE id IN ({$placeholders})", $ids);
            adminLog('article', 'batch_unpublish', '批量下架：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = contentModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'toggle_top') {
        $id = postInt('id');
        $newValue = contentModel()->toggle($id, 'is_top');
        success(['is_top' => $newValue]);
    }

    if ($action === 'toggle_recommend') {
        $id = postInt('id');
        $newValue = contentModel()->toggle($id, 'is_recommend');
        success(['is_recommend' => $newValue]);
    }

    exit;
}

// 获取子栏目（替代原来的 article_categories）
$categories = [];
if ($newsChannelId > 0) {
    $categories = channelModel()->getFlatList($newsChannelId);
}

// 查询参数
$channelId = getInt('channel_id');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

$offset = ($page - 1) * $perPage;

// 构建查询条件
$where = [];
$params = [];

// 限制只查询 news 栏目下的内容
if ($channelId > 0) {
    $where[] = 'a.channel_id = ?';
    $params[] = $channelId;
} elseif (!empty($newsChildIds)) {
    $placeholders = implode(',', array_fill(0, count($newsChildIds), '?'));
    $where[] = "a.channel_id IN ({$placeholders})";
    $params = array_merge($params, $newsChildIds);
}

if (isset($status) && $status !== '') {
    $where[] = 'a.status = ?';
    $params[] = (int)$status;
}
if (!empty($keyword)) {
    $where[] = '(a.title LIKE ? OR a.summary LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)db()->fetchColumn(
    "SELECT COUNT(*) FROM " . DB_PREFIX . "contents a {$whereSQL}",
    $params
);

$articles = db()->fetchAll(
    "SELECT a.*, c.name as channel_name
     FROM " . DB_PREFIX . "contents a
     LEFT JOIN " . DB_PREFIX . "channels c ON a.channel_id = c.id
     {$whereSQL} ORDER BY a.is_top DESC, a.id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$pageTitle = __('admin_article');
$currentMenu = 'article';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/article.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('admin_article'); ?></a>
    </div>
</div>

<!-- 筛选栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <form method="get" class="p-4 flex flex-wrap items-center gap-4">
        <select name="channel_id" class="border rounded px-3 py-2 text-sm">
            <option value=""><?php echo __('admin_all'); ?></option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?php echo $cat['id']; ?>" <?php echo $channelId === (int)$cat['id'] ? 'selected' : ''; ?>>
                <?php echo e($cat['_prefix'] . $cat['name']); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="border rounded px-3 py-2 text-sm">
            <option value=""><?php echo __('admin_all'); ?></option>
            <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>><?php echo __('admin_published'); ?></option>
            <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>><?php echo __('admin_draft'); ?></option>
        </select>

        <div class="relative">
            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   placeholder="<?php echo __('admin_search'); ?>..."
                   class="border rounded pl-3 pr-8 py-2 text-sm w-48">
            <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                </svg>
            </button>
        </div>

        <a href="/admin/article_edit.php" class="ml-auto bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm transition">
            + <?php echo __('admin_add'); ?>
        </a>
    </form>
</div>

<!-- 文章列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-gray-50 text-left">
                    <th class="px-4 py-3 w-8"><input type="checkbox" id="checkAll"></th>
                    <th class="px-4 py-3"><?php echo __('admin_title_label'); ?></th>
                    <th class="px-4 py-3"><?php echo __('admin_channel'); ?></th>
                    <th class="px-4 py-3"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3"><?php echo __('admin_top'); ?></th>
                    <th class="px-4 py-3"><?php echo __('admin_recommend'); ?></th>
                    <th class="px-4 py-3"><?php echo __('detail_views'); ?></th>
                    <th class="px-4 py-3"><?php echo __('admin_created_at'); ?></th>
                    <th class="px-4 py-3 w-32"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($articles)): ?>
                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400"><?php echo __('admin_no_data'); ?></td></tr>
                <?php else: ?>
                <?php foreach ($articles as $item): ?>
                <tr class="hover:bg-gray-50" id="row-<?php echo $item['id']; ?>">
                    <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>" class="row-check"></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($item['cover']): ?>
                            <img src="<?php echo e(thumbnail($item['cover'], 'thumb')); ?>" alt="" class="w-10 h-10 rounded object-cover flex-shrink-0">
                            <?php endif; ?>
                            <a href="/admin/article_edit.php?id=<?php echo $item['id']; ?>" class="text-gray-800 hover:text-primary font-medium line-clamp-1">
                                <?php echo e($item['title']); ?>
                            </a>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?php echo e($item['channel_name'] ?? '-'); ?></td>
                    <td class="px-4 py-3">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>)" class="status-btn-<?php echo $item['id']; ?>">
                            <?php if ($item['status']): ?>
                            <span class="text-green-500"><?php echo __('admin_published'); ?></span>
                            <?php else: ?>
                            <span class="text-gray-400"><?php echo __('admin_draft'); ?></span>
                            <?php endif; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3">
                        <button onclick="toggleTop(<?php echo $item['id']; ?>)" class="top-btn-<?php echo $item['id']; ?>">
                            <?php echo $item['is_top'] ? '<span class="text-red-500">' . __('admin_yes') . '</span>' : '<span class="text-gray-300">' . __('admin_no') . '</span>'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3">
                        <button onclick="toggleRecommend(<?php echo $item['id']; ?>)" class="rec-btn-<?php echo $item['id']; ?>">
                            <?php echo $item['is_recommend'] ? '<span class="text-orange-500">' . __('admin_yes') . '</span>' : '<span class="text-gray-300">' . __('admin_no') . '</span>'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-gray-500"><?php echo number_format((int)$item['views']); ?></td>
                    <td class="px-4 py-3 text-gray-400 text-xs"><?php echo $item['publish_time'] ? date('Y-m-d', (int)$item['publish_time']) : '-'; ?></td>
                    <td class="px-4 py-3">
                        <div class="flex gap-3 items-center">
                            <a href="/admin/article_edit.php?id=<?php echo $item['id']; ?>" class="text-blue-500 hover:text-blue-700 text-sm inline-flex items-center gap-1" title="<?php echo __('admin_edit'); ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                <?php echo __('admin_edit'); ?>
                            </a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 底部操作栏 & 分页 -->
    <div class="px-4 py-3 border-t flex items-center justify-between">
        <div class="flex items-center gap-2">
            <button onclick="batchAction('batch_publish')" class="border px-3 py-1 rounded text-sm hover:bg-green-50 text-green-600"><?php echo __('admin_published'); ?></button>
            <button onclick="batchAction('batch_unpublish')" class="border px-3 py-1 rounded text-sm hover:bg-yellow-50 text-yellow-600"><?php echo __('admin_unpublished'); ?></button>
            <button onclick="batchAction('batch_delete')" class="border px-3 py-1 rounded text-sm hover:bg-red-50 text-red-600"><?php echo __('admin_batch_delete'); ?></button>
        </div>
        <?php if ($total > $perPage): ?>
        <div class="flex items-center gap-2 text-sm">
            <span class="text-gray-400">共 <?php echo $total; ?> 条</span>
            <?php
            $totalPages = (int)ceil($total / $perPage);
            $qstr = http_build_query(array_filter(['channel_id' => $channelId, 'status' => $status, 'keyword' => $keyword]));
            ?>
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&<?php echo $qstr; ?>" class="px-3 py-1 border rounded hover:bg-gray-50"><?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>&<?php echo $qstr; ?>" class="px-3 py-1 border rounded hover:bg-gray-50"><?php echo __('list_next_page'); ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
});

async function postAction(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v)) v.forEach(i => formData.append(k + '[]', i));
        else formData.append(k, v);
    }
    const res = await fetch('', { method: 'POST', body: formData });
    return await safeJson(res);
}

async function toggleStatus(id) {
    const data = await postAction('toggle_status', { id });
    if (data.code === 0) location.reload();
}
async function toggleTop(id) {
    const data = await postAction('toggle_top', { id });
    if (data.code === 0) location.reload();
}
async function toggleRecommend(id) {
    const data = await postAction('toggle_recommend', { id });
    if (data.code === 0) location.reload();
}
async function deleteItem(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const data = await postAction('delete', { id });
    if (data.code === 0) { document.getElementById('row-' + id)?.remove(); showMessage('<?php echo __('admin_deleted'); ?>'); }
}
async function batchAction(action) {
    const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!ids.length) { showMessage('<?php echo __('admin_please_select'); ?>', 'error'); return; }
    const labels = { batch_publish: '发布', batch_unpublish: '下架', batch_delete: '删除' };
    if (!confirm('确定' + (labels[action] || '操作') + '选中的 ' + ids.length + ' 篇文章？')) return;
    const data = await postAction(action, { ids });
    if (data.code === 0) { showMessage('<?php echo __('admin_success'); ?>'); setTimeout(() => location.reload(), 1000); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
