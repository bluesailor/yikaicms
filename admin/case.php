<?php
/**
 * YikaiCMS - 案例管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/admin/includes/list_ui.php';   // 列表共享组件：行内操作 / 批量下拉 / 封面占位

checkLogin();
requirePermission('edit_case');

$contentType = 'case';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        requirePermission('delete_case');
        $id = postInt('id');
        contentModel()->deleteById($id);
        adminLog('case', 'delete', '删除案例ID：' . $id);
        success();
    }

    if ($action === 'batch_delete') {
        requirePermission('delete_case');
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            contentModel()->deleteByIds($ids);
            adminLog('case', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'batch_publish' || $action === 'batch_unpublish') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $val = $action === 'batch_publish' ? 1 : 0;
            foreach ($ids as $bid) {
                contentModel()->updateById($bid, ['status' => $val, 'updated_at' => time()]);
            }
            adminLog('case', $action, '批量' . ($val ? '发布' : '下架') . '：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'duplicate') {
        $id  = postInt('id');
        $src = contentModel()->find($id);
        if (!$src) {
            error(__('admin_no_data'));
        }
        // 复制为草稿；translation_group_id 必须清零，否则副本会被当成原文的翻译行
        unset($src['id'], $src['deleted_at']);
        $src['title']                = $src['title'] . ' ' . __('admin_copy_suffix');
        $src['slug']                 = resolveSlug('', (string) $src['title'], 'contents', 0);
        $src['status']               = 0;
        $src['views']                = 0;
        $src['translation_group_id'] = 0;
        $src['publish_time']         = 0;
        $src['created_at']           = time();
        $src['updated_at']           = time();
        $src['admin_id']             = $_SESSION['admin_id'] ?? 0;
        $newId = contentModel()->create($src);
        adminLog('case', 'duplicate', "复制案例 #$id → #$newId");
        success(['id' => $newId]);
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');
        if (in_array($field, ['status', 'is_top', 'is_recommend', 'is_hot'])) {
            contentModel()->updateById($id, [$field => $value]);
        }
        success();
    }

    exit;
}

// 获取案例类型的栏目
$channels = channelModel()->where(['type' => 'case', 'status' => 1], 'sort_order ASC');

// 查询参数
$channelId = getInt('channel_id');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = adminListPageSize('case', $page);

// 视图语言（?lang=en/ja 切换）
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

// 构建查询
$where = ['c.type = ?', 'c.lang = ?', 'c.deleted_at IS NULL']; //将删除的案例从显示 - duke
$params = [$contentType, $_viewLang];

if ($channelId > 0) {
    $where[] = 'c.channel_id = ?';
    $params[] = $channelId;
}

if ($status !== '') {
    $where[] = 'c.status = ?';
    $params[] = (int)$status;
}

if ($keyword) {
    $where[] = '(c.title LIKE ? OR c.summary LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$total = (int) contentModel()->queryColumn(
    "SELECT COUNT(*) FROM " . contentModel()->tableName() . " c $whereClause",
    $params
);

$offset = (int)(($page - 1) * $perPage);
$perPage = (int)$perPage;
$params[] = $perPage;
$params[] = $offset;
$items = contentModel()->query(
    "SELECT c.*, ch.name as channel_name FROM " . contentModel()->tableName() . " c
     LEFT JOIN " . channelModel()->tableName() . " ch ON c.channel_id = ch.id
     $whereClause ORDER BY c.is_top DESC, c.id DESC LIMIT ? OFFSET ?",
    $params
);

$pageTitle = __('admin_case_manage');
$currentMenu = 'case';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('contents');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/case.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('case_tab_list'); ?></a>
        <a href="/admin/case_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('case_tab_category'); ?></a>
    </div>
</div>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="channel_id" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all_channels'); ?></option>
                <?php foreach ($channels as $ch): ?>
                <option value="<?php echo $ch['id']; ?>" <?php echo $channelId === (int)$ch['id'] ? 'selected' : ''; ?>>
                    <?php echo e($ch['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all'); ?></option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>><?php echo __('admin_published'); ?></option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>><?php echo __('admin_draft'); ?></option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>

            <?php echo renderAdminPageSize($perPage); ?>
        </form>

        <a href="/admin/content_edit.php?type=case" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo __('admin_add_case'); ?>
        </a>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <form id="listForm">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left"><input type="checkbox" id="checkAll"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_title_label'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_channel'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('detail_views'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_recommend'); ?></th>

                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('label_publish_time'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_translation')); ?></th>

                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($items as $item): ?>
                    <tr class="group hover:bg-gray-50">
                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($item['cover']): ?>
                                <img src="<?php echo e($item['cover']); ?>" class="w-16 h-12 object-cover rounded">
                                <?php else: ?>
                                <?php echo renderCoverCell('', 'w-16 h-12'); ?>
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <div class="font-medium"><?php echo e(cutStr($item['title'], 40)); ?></div>
                                    <?php echo renderRowActions([
                                        'id'        => (int) $item['id'],
                                        'edit'      => '/admin/content_edit.php?id=' . (int) $item['id'],
                                        'view'      => contentUrl($item),
                                        'duplicate' => true,
                                    ]); ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['channel_name'] ?: '-'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo number_format((int)$item['views']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'is_recommend', <?php echo $item['is_recommend'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_recommend'] ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_recommend'] ? __('admin_recommend') : '-'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'status', <?php echo $item['status'] ? 0 : 1; ?>, this)" class="text-sm block mx-auto">
                                <?php echo $item['status']
                                    ? '<span class="text-green-600">' . __('admin_published') . '</span>'
                                    : '<span class="text-gray-400">' . __('admin_draft') . '</span>'; ?>
                            </button>
                            <span class="text-gray-400 text-xs">
                                <?php $_ts = (int) ($item['publish_time'] ?: $item['updated_at'] ?? 0); ?>
                                <?php echo $_ts ? date('Y-m-d H:i', $_ts) : '-'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $_pillSrcId = (int) ($item['translation_group_id'] ?: $item['id']);
                            echo renderTransPills($_pillSrcId, $transStatus, '/admin/content_edit.php');
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <?php echo renderBulkBar([
                'batch_publish'   => __('admin_published'),
                'batch_unpublish' => __('admin_unpublished'),
                'batch_delete'    => __('admin_move_to_trash'),
            ]); ?>
            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500"><?php echo sprintf(__('admin_total_items'), $total); ?></span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['channel_id' => $channelId, 'status' => $status, 'keyword' => $keyword, 'per_page' => $perPage]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(el => el.checked = this.checked);
});

async function toggle(id, field, value, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        location.reload();
    }
}

async function deleteItem(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    }
}

async function batchAction(action) {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) { showMessage('<?php echo __('admin_please_select'); ?>', 'error'); return; }
    const labels = {
        batch_publish: '<?php echo __('admin_published'); ?>',
        batch_unpublish: '<?php echo __('admin_unpublished'); ?>',
        batch_delete: '<?php echo __('admin_move_to_trash'); ?>'
    };
    if (!confirm('<?php echo __('admin_bulk_confirm_prefix'); ?>' + (labels[action] || '') + ' ' + checked.length + ' <?php echo __('admin_bulk_confirm_suffix'); ?>')) return;
    const formData = new FormData();
    formData.append('action', action);
    checked.forEach(el => formData.append('ids[]', el.value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) { showMessage('<?php echo __('admin_success'); ?>'); setTimeout(() => location.reload(), 1000); }
    else { showMessage(data.msg, 'error'); }
}

async function duplicateItem(id) {
    const formData = new FormData();
    formData.append('action', 'duplicate');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_duplicated'); ?>');
        setTimeout(() => location.href = '/admin/content_edit.php?id=' + data.data.id, 700);
    } else { showMessage(data.msg, 'error'); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
