<?php
/**
 * YikaiCMS - 回收站
 *
 * 集中管理软删除的内容（contents）与产品（products）：还原 / 彻底删除 / 清空。
 * 软删除机制见 includes/models/Model.php（$softDelete）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
// 回收站横跨全部内容类型，不该挂在单一权限上——原先写的 'content' 在权限
// 细粒度化后已不是合法键，这页退化成了超管专属。改为：能进页面只要求「有任一
// 内容权限或媒体权限」，真正的判定下沉到每个动作（见 recycleRequirePerm）。
if (!hasAnyContentPerm() && !hasPermission('media')) {
    requirePermission('edit_article');   // 必失败，走统一的「没有操作权限」提示
}

/** 支持的回收站类型 → 对应 model 与展示标签 */
function recycleModels(): array
{
    return [
        'content'  => ['model' => contentModel(), 'label' => __('admin_group_content')],
        'product'  => ['model' => productModel(), 'label' => __('admin_group_product')],
        'album'    => ['model' => albumModel(), 'label' => __('admin_album')],
        'download' => ['model' => downloadModel(), 'label' => __('admin_download')],
        'job'      => ['model' => jobModel(), 'label' => __('admin_job')],
    ];
}

/**
 * 回收站单个动作的权限判定。
 *
 * 还原与彻底删除都是写操作，且彻底删除不可逆，所以要的是 delete_ 档而不是 edit_ 档。
 * content 桶里混着文章/案例/单页/下载四种类型（同一张 contents 表），按行的 type 判；
 * 其余桶各自对应固定权限。清空整个回收站是批量不可逆操作，只给超管。
 */
function recycleRequirePerm(string $type, string $action, int $id): void
{
    if (hasPermission('*')) {
        return;
    }
    if ($action === 'empty') {
        error(__('perm_denied'), 403);   // 批量彻底删除只给超管
    }

    $ok = match ($type) {
        'content'  => $id > 0 && canDeleteContentRow($id),
        'product'  => hasPermission('delete_product'),
        'download' => hasPermission('delete_download'),
        'album'    => hasPermission('media'),
        // 招聘目前没有独立权限键，暂随文章的删除档；待 edit_job/delete_job 落地后改这里
        'job'      => hasPermission('delete_article'),
        default    => false,
    };
    if (!$ok) {
        error(__('perm_denied'), 403);
    }
}

// ============================================================
// AJAX（CSRF 已由 checkLogin() 统一校验）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    $type   = post('type');
    $models = recycleModels();
    if (!isset($models[$type])) {
        error(__('recycle_invalid_type'));
    }
    $model = $models[$type]['model'];
    recycleRequirePerm($type, $action, postInt('id'));

    if ($action === 'restore') {
        $model->restore(postInt('id'));
        adminLog('recycle', 'restore', "还原 {$type} ID：" . postInt('id'));
        success([], __('recycle_restored'));
    }

    if ($action === 'purge') {
        $model->forceDeleteById(postInt('id'));
        adminLog('recycle', 'purge', "彻底删除 {$type} ID：" . postInt('id'));
        success([], __('recycle_purged'));
    }

    if ($action === 'empty') {
        // 清空该类型回收站：逐条彻底删除
        foreach ($model->getTrashed(10000, 0) as $row) {
            $model->forceDeleteById((int) $row['id']);
        }
        adminLog('recycle', 'empty', "清空 {$type} 回收站");
        success([], __('recycle_emptied'));
    }

    error();
}

// ============================================================
// 展示
// ============================================================
$models = recycleModels();
$activeType = isset($models[get('type')]) ? get('type') : 'content';
$model = $models[$activeType]['model'];

$page = max(1, getInt('page', 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$total = $model->trashedCount();
$items = $model->getTrashed($perPage, $offset);
$totalPages = (int) ceil($total / $perPage);

// 各类型待清数量（tab 徽标）
$counts = [];
foreach ($models as $k => $m) {
    $counts[$k] = $m['model']->trashedCount();
}

$pageTitle = __('admin_recycle_bin');
$currentMenu = 'recycle';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-4 flex items-center justify-between">
    <div class="flex gap-2">
        <?php foreach ($models as $k => $m): ?>
        <a href="?type=<?php echo e($k); ?>"
           class="px-4 py-2 rounded-lg text-sm font-medium transition <?php echo $activeType === $k ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50 border'; ?>">
            <?php echo e($m['label']); ?>
            <?php if ($counts[$k] > 0): ?><span class="ml-1 px-1.5 py-0.5 rounded-full text-xs <?php echo $activeType === $k ? 'bg-white/25' : 'bg-gray-100'; ?>"><?php echo (int) $counts[$k]; ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if ($total > 0): ?>
    <button type="button" onclick="emptyTrash()" class="text-red-500 hover:text-red-600 text-sm inline-flex items-center gap-1 cursor-pointer">
        <i class="ti ti-trash text-base"></i><?php echo __('recycle_empty_all'); ?>
    </button>
    <?php endif; ?>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <?php if (empty($items)): ?>
    <div class="p-12 text-center text-gray-400">
        <i class="ti ti-trash-off text-4xl mb-2 block"></i>
        <?php echo __('recycle_empty_state'); ?>
    </div>
    <?php else: ?>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
            <tr>
                <th class="px-6 py-3 font-medium"><?php echo __('recycle_col_title'); ?></th>
                <th class="px-6 py-3 font-medium w-40"><?php echo __('recycle_col_deleted_at'); ?></th>
                <th class="px-6 py-3 font-medium w-48 text-right"><?php echo __('recycle_col_actions'); ?></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($items as $row): ?>
            <tr class="hover:bg-gray-50" data-id="<?php echo (int) $row['id']; ?>">
                <td class="px-6 py-3">
                    <div class="font-medium text-gray-800"><?php echo e($row['title'] ?? ('#' . $row['id'])); ?></div>
                    <?php if (!empty($row['slug'])): ?><div class="text-xs text-gray-400"><?php echo e($row['slug']); ?></div><?php endif; ?>
                </td>
                <td class="px-6 py-3 text-gray-500"><?php echo !empty($row['deleted_at']) ? date('Y-m-d H:i', (int) $row['deleted_at']) : '-'; ?></td>
                <td class="px-6 py-3 text-right space-x-2 whitespace-nowrap">
                    <button type="button" onclick="restoreItem(<?php echo (int) $row['id']; ?>)"
                            class="text-primary hover:text-secondary inline-flex items-center gap-1 cursor-pointer">
                        <i class="ti ti-restore"></i><?php echo __('recycle_restore'); ?>
                    </button>
                    <button type="button" onclick="purgeItem(<?php echo (int) $row['id']; ?>)"
                            class="text-red-500 hover:text-red-600 inline-flex items-center gap-1 cursor-pointer">
                        <i class="ti ti-trash"></i><?php echo __('recycle_purge'); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="mt-4 flex justify-center gap-1">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <a href="?type=<?php echo e($activeType); ?>&page=<?php echo $p; ?>"
       class="px-3 py-1.5 rounded text-sm <?php echo $p === $page ? 'bg-primary text-white' : 'bg-white border text-gray-600 hover:bg-gray-50'; ?>"><?php echo $p; ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<script>
const RECYCLE_TYPE = <?php echo json_encode($activeType); ?>;
const CSRF = <?php echo json_encode(csrfToken()); ?>;
const T = {
    restoreConfirm: <?php echo json_encode(__('recycle_restore_confirm')); ?>,
    purgeConfirm: <?php echo json_encode(__('recycle_purge_confirm')); ?>,
    emptyConfirm: <?php echo json_encode(__('recycle_empty_confirm')); ?>,
};

async function recyclePost(action, id) {
    const body = new URLSearchParams({ action, type: RECYCLE_TYPE, [<?php echo json_encode(CSRF_TOKEN_NAME); ?>]: CSRF });
    if (id) body.set('id', id);
    const res = await fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body });
    const data = await res.json().catch(() => ({ code: 1, msg: 'error' }));
    if (data.code === 0) { location.reload(); }
    else { alert(data.msg || 'error'); }
}
function restoreItem(id) { if (confirm(T.restoreConfirm)) recyclePost('restore', id); }
function purgeItem(id) { if (confirm(T.purgeConfirm)) recyclePost('purge', id); }
function emptyTrash() { if (confirm(T.emptyConfirm)) recyclePost('empty', 0); }
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
