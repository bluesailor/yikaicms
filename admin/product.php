<?php
/**
 * YikaiCMS - 产品管理
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
requirePermission('edit_product');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        requirePermission('delete_product');
        $id = postInt('id');
        productModel()->deleteById($id);
        adminLog('product', 'delete', "删除产品ID: $id");
        success();
    }

    if ($action === 'batch_delete') {
        requirePermission('delete_product');
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            productModel()->deleteByIds($ids);
            adminLog('product', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'batch_publish' || $action === 'batch_unpublish') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $val = $action === 'batch_publish' ? 1 : 0;
            foreach ($ids as $bid) {
                productModel()->updateById($bid, ['status' => $val, 'updated_at' => time()]);
            }
            adminLog('product', $action, '批量' . ($val ? '上架' : '下架') . '：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'duplicate') {
        $id  = postInt('id');
        $src = productModel()->find($id);
        if (!$src) {
            error(__('admin_no_data'));
        }
        // 复制为下架草稿：清主键/统计/时间，重算 slug；translation_group_id 必须清零，
        // 否则副本会被当成原品的翻译行而在语言切换时相互覆盖。
        unset($src['id'], $src['deleted_at']);
        $src['title']                = $src['title'] . ' ' . __('admin_copy_suffix');
        $src['slug']                 = resolveSlug('', (string) $src['title'], 'products', 0);
        $src['status']               = 0;
        $src['views']                = 0;
        $src['translation_group_id'] = 0;
        $src['created_at']           = time();
        $src['updated_at']           = time();
        $newId = productModel()->create($src);
        adminLog('product', 'duplicate', "复制产品 #$id → #$newId");
        success(['id' => $newId]);
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = productModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'toggle_top') {
        $id = postInt('id');
        $newValue = productModel()->toggle($id, 'is_top');
        success(['is_top' => $newValue]);
    }

    if ($action === 'toggle_recommend') {
        $id = postInt('id');
        $newValue = productModel()->toggle($id, 'is_recommend');
        success(['is_recommend' => $newValue]);
    }

    exit;
}

// 获取分类
$categories = productCategoryModel()->where(['status' => 1]);

// 查询参数
$categoryId = getInt('category_id');
$status = get('status', '');
$productType = get('product_type', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

$offset = ($page - 1) * $perPage;
// 视图语言（?lang=en/ja 切换）
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

$filters = array_filter([
    'lang' => $_viewLang,
    'category_id' => $categoryId,
    'status' => $status,
    'product_type' => $productType,
    'keyword' => $keyword,
], fn($v) => $v !== '' && $v !== 0);
$result = productModel()->getAdminList($filters, $perPage, $offset);
$total = $result['total'];
$products = $result['items'];

$pageTitle = __('admin_product');
$currentMenu = 'product';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('products');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('product_tab_list'); ?></a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('product_tab_category'); ?></a>
        <a href="/admin/product_brand.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_brand'); ?></a>
        <a href="/admin/product_tag.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_tag'); ?></a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('product_tab_setting'); ?></a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="category_id" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all'); ?></option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all'); ?></option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>><?php echo __('status_on_shelf'); ?></option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>><?php echo __('status_off_shelf'); ?></option>
            </select>

            <?php if (getLang() === 'ja'): ?>
            <select name="product_type" class="border rounded px-3 py-2">
                <option value=""><?php echo __('product_type_all'); ?></option>
                <option value="standard" <?php echo $productType === 'standard' ? 'selected' : ''; ?>><?php echo __('product_type_standard'); ?></option>
                <option value="custom" <?php echo $productType === 'custom' ? 'selected' : ''; ?>><?php echo __('product_type_custom'); ?></option>
            </select>
            <?php endif; ?>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>

        <a href="/admin/product_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo __('admin_add'); ?>
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
                        <th class="px-4 py-3 text-left">
                            <input type="checkbox" id="checkAll">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_product'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('product_tab_category'); ?></th>
                        <?php if (config('show_price', '0') === '1'): ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('pr_price')); ?></th>
                        <?php endif; ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_top'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_recommend'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('detail_views'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_date'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_translate')); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($products as $item): ?>
                    <tr class="group hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>">
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php echo renderCoverCell((string) $item['cover'], 'w-12 h-12'); ?>
                                <div>
                                    <div class="font-medium flex items-center gap-2">
                                        <?php echo e(cutStr($item['title'], 30)); ?>
                                        <?php if (getLang() === 'ja'): ?>
                                        <?php $pt = $item['product_type'] ?? 'custom'; ?>
                                        <?php if ($pt === 'standard'): ?>
                                        <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-0.5 rounded-full"><?php echo __('product_badge_standard'); ?></span>
                                        <?php else: ?>
                                        <span class="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full"><?php echo __('product_badge_custom'); ?></span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo e($item['model'] ?: '-'); ?>
                                        <?php if (!empty($item['slug'])): ?>
                                        <code class="bg-gray-100 px-1.5 py-0.5 rounded ml-1"><?php echo e($item['slug']); ?></code>
                                        <?php endif; ?>
                                    </div>
                                    <?php echo renderRowActions([
                                        'id'        => (int) $item['id'],
                                        'edit'      => '/admin/product_edit.php?id=' . (int) $item['id'],
                                        'view'      => productUrl($item),
                                        'duplicate' => true,
                                        'delete_fn' => 'deleteProduct',
                                    ]); ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['category_name'] ?: __('admin_uncategorized')); ?>
                            </span>
                        </td>
                        <?php if (config('show_price', '0') === '1'): ?>
                        <td class="px-4 py-3 text-center">
                            <?php if ($item['price'] > 0): ?>
                            <span class="text-red-600 font-medium">&yen;<?php echo number_format((float)$item['price'], 2); ?></span>
                            <?php else: ?>
                            <span class="text-gray-400"><?php echo e(__('pr_negotiable')); ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleTop(<?php echo $item['id']; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_top'] ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_top'] ? __('admin_top') : '-'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleRecommend(<?php echo $item['id']; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_recommend'] ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_recommend'] ? __('admin_recommend') : '-'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo number_format((int)$item['views']); ?>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <?php echo renderStatusDateCell(
                                (int) $item['id'],
                                (int) $item['status'],
                                (int) ($item['updated_at'] ?: $item['created_at'] ?? 0)
                            ); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php echo renderTransPills((int)$item['id'], $transStatus, '/admin/product_edit.php'); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="<?php echo config('show_price', '0') === '1' ? 10 : 9; ?>" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td>
                    </tr>
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
                <span class="text-sm text-gray-500"><?php echo str_replace(':n', (string) $total, e(__('admin_total_n'))); ?></span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['category_id' => $categoryId, 'status' => $status, 'keyword' => $keyword]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    <i class="ti ti-chevron-left text-base"></i>
                    <?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <span class="text-sm"><?php echo str_replace([':p', ':t'], [(string) $page, (string) $totalPages], e(__('admin_page_of'))); ?></span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    <?php echo __('list_next_page'); ?>
                    <i class="ti ti-chevron-right text-base"></i>
                </a>
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

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '<?php echo __('admin_published'); ?>';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '<?php echo __('admin_unpublished'); ?>';
        }
    }
}

async function toggleTop(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_top');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.is_top) {
            btn.className = 'text-xs px-2 py-1 rounded bg-orange-100 text-orange-600';
            btn.textContent = '<?php echo __('admin_top'); ?>';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-400';
            btn.textContent = '-';
        }
    }
}

async function toggleRecommend(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_recommend');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.is_recommend) {
            btn.className = 'text-xs px-2 py-1 rounded bg-purple-100 text-purple-600';
            btn.textContent = '<?php echo __('admin_recommend'); ?>';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-400';
            btn.textContent = '-';
        }
    }
}

async function deleteProduct(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

// 批量操作入口（由共享组件的 applyBulk() 调用）
async function batchAction(action) {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) {
        showMessage('<?php echo __('admin_please_select'); ?>', 'error');
        return;
    }
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
    if (data.code === 0) {
        showMessage('<?php echo __('admin_success'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

async function duplicateItem(id) {
    const formData = new FormData();
    formData.append('action', 'duplicate');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_duplicated'); ?>');
        setTimeout(() => location.href = '/admin/product_edit.php?id=' + data.data.id, 700);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
