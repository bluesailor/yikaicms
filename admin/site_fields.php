<?php
/**
 * Yikai CMS - 全站选项（2.0.4 高级字段：对标 ACF 选项页）
 *
 * 填写「扩展字段 → 全站选项」里定义的字段（证书、工厂数据、全站通用的联系人等），
 * 值存 metas（owner_type=site、owner_id=0），前台用 {{option.键}} 或主题里的 option('键') 取。
 * 字段定义归专业版；已定义的字段任何时候都能在这里填写。
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = (array) ($_POST['ext_fields'] ?? []);
    $missingField = ExtFields::missingRequired('site', $posted);
    if ($missingField !== null) {
        error(sprintf(__('ef_required_missing'), $missingField));
    }
    ExtFields::save('site', 0, $posted);
    settingModel()->rotateHtmlCacheGeneration();   // 全站都可能用到：整页缓存一并失效
    adminLog('site_fields', 'update', '更新全站选项');
    success();
}

$pageTitle = __('ef_site_title');
$currentMenu = 'site_fields';
$hasFields = ExtFields::fields('site') !== [];

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<form id="siteFieldsForm" class="max-w-4xl space-y-6">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <p class="flex-1 min-w-0 text-sm text-gray-500"><?php echo e(__('ef_site_tip')); ?></p>
            <a href="/admin/extfield.php?owner_type=site" class="text-sm text-primary hover:underline"><?php echo e(__('ef_manage_link')); ?></a>
        </div>
        <?php if ($hasFields): ?>
            <?php
            $extFieldOwnerType = 'site';
            $extFieldOwnerId = 0;
            $extFieldBare = true;
            require ROOT_PATH . '/admin/includes/extfield_render.php';
            ?>
        <?php else: ?>
            <p class="py-8 text-center text-gray-500"><?php echo e(__('ef_site_empty')); ?></p>
        <?php endif; ?>
    </div>
    <?php if ($hasFields): ?>
    <div class="flex justify-end">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-check text-base"></i> <?php echo e(__('btn_save')); ?>
        </button>
    </div>
    <?php endif; ?>
</form>

<script>
document.getElementById('siteFieldsForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
    if (data.code === 0) showMessage(<?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
    else showMessage(data.msg, 'error');
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
