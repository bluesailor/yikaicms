<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteTemplateService.php';
checkLogin();
requirePermission('*');
$service = new SiteTemplateService(ROOT_PATH);
$errorMessage = '';
$notice = (string) ($_SESSION['site_template_notice'] ?? '');
unset($_SESSION['site_template_notice']);
$brand = ['site_name' => (string) config('site_name'), 'contact_phone' => '', 'contact_email' => '', 'contact_address' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $action = post('action');
        if ($action === 'export') {
            $temporary = tempnam(sys_get_temp_dir(), 'yk-export-');
            if ($temporary === false) throw new RuntimeException('st_storage');
            try {
                $exportSummary = $service->export($temporary);
                adminLog('theme', 'export', 'Site template exported: ' . (string) $exportSummary['theme']);
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="yikai-site-template-' . date('Ymd-His') . '.zip"');
                header('Content-Length: ' . (string) filesize($temporary));
                header('Cache-Control: no-store');
                readfile($temporary);
            } finally { @unlink($temporary); }
            exit;
        }
        if ($action === 'prepare') {
            $upload = $_FILES['package'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) throw new RuntimeException('st_upload');
            $_SESSION['site_template_preview'] = $service->prepare((string) $upload['tmp_name'], getAdminId());
        } elseif ($action === 'apply') {
            foreach ($brand as $key => $_value) $brand[$key] = post($key);
            $service->apply(post('token'), getAdminId(), $brand, post('trusted') === '1' && post('confirm') === '1');
            unset($_SESSION['site_template_preview']);
            // 整站替换会清空并重写 28 张内容表：不留痕就无从追查谁在何时做的
            adminLog('theme', 'import', 'Site template applied');
            $_SESSION['site_template_notice'] = 'st_applied';
        } elseif ($action === 'restore' && post('confirm') === '1') {
            $service->restore();
            unset($_SESSION['site_template_preview']);
            adminLog('theme', 'import', 'Site template restored to pre-import snapshot');
            $_SESSION['site_template_notice'] = 'st_restored';
        } else { throw new RuntimeException('st_invalid'); }
        redirect('/admin/site_templates.php');
    } catch (Throwable $error) {
        $code = $error->getMessage();
        $errorMessage = __(preg_match('/^st_[a-z_]+$/D', $code) ? $code : 'st_invalid');
    }
}
$fresh = false;
$recovery = null;
try { $fresh = $service->canApply(); $recovery = $service->recovery(); }
catch (Throwable $error) { $errorMessage = __('st_storage'); }
$preview = $_SESSION['site_template_preview'] ?? null;
$pageTitle = __('st_title');
$currentMenu = 'site_setup';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="max-w-4xl space-y-6">
    <header><a href="/admin/site_setup.php" class="text-primary underline"><?= e(__('setup_title')) ?></a>
        <h1 class="text-2xl font-bold text-gray-800 mt-2"><?= e($pageTitle) ?></h1>
        <p class="text-gray-600 mt-2"><?= e(__('st_intro')) ?></p></header>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if (in_array($notice, ['st_applied', 'st_restored'], true)): ?><p role="status" class="bg-green-50 text-green-700 p-4 rounded"><?= e(__($notice)) ?></p><?php endif; ?>
    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="st-export">
        <h2 id="st-export" class="text-lg font-bold"><?= e(__('st_export_title')) ?></h2>
        <p class="text-gray-600 mt-2"><?= e(__('st_export_hint')) ?></p>
        <p class="text-sm text-gray-600 mt-2"><?= e(__('st_scope')) ?></p>
        <form method="post" class="mt-4"><?= csrfField() ?><input type="hidden" name="action" value="export">
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_export')) ?></button></form>
    </section>
    <section class="bg-white rounded-lg shadow p-6 space-y-4" aria-labelledby="st-import">
        <h2 id="st-import" class="text-lg font-bold"><?= e(__('st_import_title')) ?></h2>
        <p class="text-gray-600"><?= e(__('st_import_hint')) ?></p>
        <?php if (!$fresh): ?><p class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__('st_not_fresh')) ?></p>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="space-y-3">
            <?= csrfField() ?><input type="hidden" name="action" value="prepare">
            <label class="block font-medium" for="st-package"><?= e(__('st_package')) ?></label>
            <input id="st-package" type="file" name="package" accept=".zip" required class="block w-full max-w-full" aria-describedby="st-size">
            <p id="st-size" class="text-sm text-gray-600"><?= e(__('st_size')) ?></p>
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_preview')) ?></button>
        </form>
        <?php if (is_array($preview)): ?>
        <div class="border rounded p-4 space-y-4">
            <h3 class="font-bold"><?= e(__('st_preview_title')) ?></h3>
            <dl class="grid grid-cols-2 gap-3">
                <?php foreach ($preview['summary'] as $key => $value): ?>
                <div class="min-w-0"><dt class="text-sm text-gray-600"><?= e(__('st_count_' . $key)) ?></dt><dd class="font-medium break-words"><?= e((string) $value) ?></dd></div>
                <?php endforeach; ?>
            </dl>
            <form method="post" class="space-y-4">
                <?= csrfField() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                <p class="text-gray-600"><?= e(__('st_brand_hint')) ?></p>
                <?php foreach ($brand as $key => $value): ?>
                <div><label class="block font-medium mb-1" for="st-<?= e($key) ?>"><?= e(__('st_' . $key)) ?></label>
                    <input id="st-<?= e($key) ?>" name="<?= e($key) ?>" type="<?= $key === 'contact_email' ? 'email' : 'text' ?>" maxlength="500" value="<?= e($value) ?>" <?= $key === 'site_name' ? 'required' : '' ?> class="border rounded px-3 py-2 w-full"></div>
                <?php endforeach; ?>
                <label class="flex gap-2 items-start"><input type="checkbox" name="trusted" value="1" required class="mt-1"><span><?= e(__('st_trust_label')) ?></span></label>
                <label class="flex gap-2 items-start"><input type="checkbox" name="confirm" value="1" required class="mt-1"><span><?= e(__('st_confirm')) ?></span></label>
                <button type="submit" class="bg-primary text-white rounded px-4 py-3"><?= e(__('st_apply')) ?></button>
            </form>
        </div>
        <?php endif; endif; ?>
    </section>
    <?php if ($recovery !== null): ?>
    <section class="bg-white rounded-lg shadow p-6 space-y-3" aria-labelledby="st-recovery">
        <h2 id="st-recovery" class="text-lg font-bold"><?= e(__('st_recovery')) ?></h2>
        <p class="text-gray-600"><?= e(__('st_recovery_hint')) ?></p>
        <p><?= e(__('st_record_time', ['time' => date('Y-m-d H:i', $recovery['created_at'])])) ?></p>
        <?php if ($recovery['can_restore']): ?>
        <form method="post" class="space-y-3"><?= csrfField() ?><input type="hidden" name="action" value="restore">
            <label class="flex gap-2 items-start"><input type="checkbox" name="confirm" value="1" required class="mt-1"><span><?= e(__('st_restore_confirm')) ?></span></label>
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_restore')) ?></button></form>
        <?php else: ?><p class="text-gray-600"><?= e(__($recovery['status'] === 'restored' ? 'st_restored' : 'st_restore_unavailable')) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
    <a class="inline-block border rounded px-4 py-3" href="/" target="_blank" rel="noopener"><?= e(__('setup_preview')) ?></a>
</div>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
