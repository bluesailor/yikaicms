<?php
/**
 * YikaiCMS - 文章管理
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
requirePermission('edit_article');

// 视图语言（?lang=en/ja 切换列哪个语言；默认为 site_lang）
// 必须先于 news 栏目查询：news 栏目在每种语言下是不同的行（id 不同），
// 用错语言会导致 channel_id 过滤命中空集。
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

// 获取视图语言下的 news 栏目。
// 注意：翻译流程会给 slug 加 -{lang} 后缀（news → news-ja），不能按 slug 直查。
// 必须先用默认语言 slug 拿到源行，再通过 translation_group_id 找姊妹栏目。
$newsChannel = null;
$srcNews = getChannelBySlug('news'); // 默认语言（通常 zh-CN）的源 news
if ($srcNews) {
    if ($srcNews['lang'] === $_viewLang) {
        $newsChannel = $srcNews;
    } else {
        $sisterId = findTranslatedChannelId((int)$srcNews['id'], $_viewLang);
        if ($sisterId > 0) {
            $newsChannel = channelModel()->find($sisterId);
        }
    }
}
$newsChannelId = $newsChannel ? (int)$newsChannel['id'] : 0;
$newsChildIds = $newsChannelId > 0 ? channelModel()->getChildIds($newsChannelId) : [];

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        requirePermission('delete_article');
        $id = postInt('id');
        contentModel()->deleteById($id);
        adminLog('article', 'delete', "删除文章ID: $id");
        success();
    }

    if ($action === 'batch_delete') {
        requirePermission('delete_article');
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

// a.deleted_at IS NULL：ContentModel 为软删除，后台列表此前是裸 SQL 漏了该过滤，
// 导致"删除后前台消失、后台仍显示"。回收站另设入口，主列表只看未删除。
$where = ['a.lang = ?', 'a.deleted_at IS NULL'];
$params = [$_viewLang];

// 限制只查询 news 栏目下的内容
if ($channelId > 0) {
    $where[] = 'a.channel_id = ?';
    $params[] = $channelId;
} elseif (!empty($newsChildIds)) {
    $placeholders = implode(',', array_fill(0, count($newsChildIds), '?'));
    // 同时纳入「未分类」文章(channel_id=0 的 article)：分类未选中时文章会存成 0，
    // 若不显示就会在后台彻底消失、连修改/删除的入口都没有。列出来才能找回并归类。
    $where[] = "(a.channel_id IN ({$placeholders}) OR (a.channel_id = 0 AND a.type = 'article'))";
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

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('contents');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

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
                <i class="ti ti-search text-base"></i>
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
                    <th class="px-4 py-3">翻译</th>
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
                    <td class="px-4 py-3"><?php echo renderTransPills((int)$item['id'], $transStatus, '/admin/article_edit.php'); ?></td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <div class="flex gap-3 items-center">
                            <a href="/admin/article_edit.php?id=<?php echo $item['id']; ?>" class="text-blue-500 hover:text-blue-700 text-sm inline-flex items-center gap-1" title="<?php echo __('admin_edit'); ?>">
                                <i class="ti ti-pencil text-base"></i>
                                <?php echo __('admin_edit'); ?>
                            </a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700 text-sm inline-flex items-center gap-1" title="<?php echo __('admin_delete'); ?>">
                                <i class="ti ti-trash text-base"></i>
                                <?php echo __('admin_delete'); ?>
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
