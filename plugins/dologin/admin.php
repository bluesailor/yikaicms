<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');
checkLogin();
requirePermission('*');
require_once __DIR__ . '/compatibility.php';
if (!DoLoginCompatibility::currentSiteSupported()) {
    error(__('dl_cms_version_required'), 403);
}
if ((defined('DEMO_MODE') && DEMO_MODE) || (defined('DEMO_SANDBOX') && DEMO_SANDBOX)
    || DemoSandbox::mode() !== DemoSandbox::MODE_OFF) {
    error(__('auth_demo_sandbox_protected'), 403);
}
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$message = '';
$failed = false;
$issuedUrl = '';
$model = doLoginLinkModel();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    try {
        $action = post('dl_action');
        if ($action === 'initialize') {
            require_once ROOT_PATH . '/includes/Migrator.php';
            foreach (Migrator::loadAll() as $migration) {
                if ($migration['id'] === '20260914_dologin_links' && !Migrator::isApplied($migration)) {
                    $result = Migrator::runOne($migration);
                    if (!$result['ok']) throw new RuntimeException('Migration failed.');
                }
            }
            if (!$model->ready()) throw new RuntimeException('Migration missing.');
        } elseif ($action === 'issue' && $model->ready()) {
            $issued = $model->issue((int) post('user_id'), getAdminId(), (int) post('minutes'), trim(post('note')));
            $issuedUrl = '/admin/login.php?dologin=1#' . $issued['token'];
        } elseif ($action === 'revoke' && $model->ready()) {
            $model->revoke((int) post('link_id'));
        } elseif ($action === 'protection') {
            $attempts = filter_var(post('attempts'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
            $minutes = filter_var(post('lock_minutes'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
            if ($attempts === false || $minutes === false) throw new InvalidArgumentException('Invalid protection limits.');
            settingModel()->saveBatch(['login_max_attempts' => (string) $attempts, 'login_lock_minutes' => (string) $minutes]);
        } else {
            throw new InvalidArgumentException('Invalid action.');
        }
        adminLog('dologin', $action, __('dl_log_updated'));
        $message = __('dl_saved');
    } catch (Throwable $exception) {
        $failed = true;
        $message = __('dl_operation_failed');
        error_log('Easy Login admin operation failed.');
    }
}
$ready = $model->ready();
$rows = $ready ? $model->recent() : [];
$users = userModel()->where(['status' => 1]);
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<link rel="stylesheet" href="/plugins/dologin/style.css">
<div class="dl-admin">
    <header class="dl-heading"><div><h1><?= e(__('dl_title')) ?></h1><p><?= e(__('dl_intro')) ?></p></div><span class="dl-badge">1.0.0</span></header>
    <?php if ($message !== ''): ?><p role="status" class="dl-notice <?= $failed ? 'dl-alert' : '' ?>"><?= e($message) ?></p><?php endif; ?>
    <?php if ($issuedUrl !== ''): ?>
    <section class="dl-card dl-result" aria-label="<?= e(__('dl_link_created')) ?>">
        <h2><?= e(__('dl_link_created')) ?></h2><p><?= e(__('dl_secret_hint')) ?></p>
        <label for="dl-issued-link"><?= e(__('dl_link')) ?></label>
        <input id="dl-issued-link" type="text" readonly value="<?= e($issuedUrl) ?>">
        <button type="button" id="dl-copy" data-copied="<?= e(__('dl_copied')) ?>" data-fallback="<?= e(__('dl_copy_fallback')) ?>"><?= e(__('dl_copy')) ?></button>
        <span id="dl-copy-status" role="status"></span>
    </section>
    <?php endif; ?>
    <div class="dl-grid">
    <section class="dl-card">
        <h2><?= e(__('dl_create')) ?></h2>
        <?php if (!$ready): ?>
            <p><?= e(__('dl_initialize_hint')) ?></p>
            <form method="post"><?= csrfField() ?><input type="hidden" name="dl_action" value="initialize"><button><?= e(__('dl_initialize')) ?></button></form>
        <?php else: ?>
        <form method="post" class="dl-form">
            <?= csrfField() ?><input type="hidden" name="dl_action" value="issue">
            <label for="dl-user"><?= e(__('dl_account')) ?></label>
            <select name="user_id" id="dl-user" required>
                <?php foreach ($users as $user): ?><option value="<?= e((string) $user['id']) ?>" <?= (int) $user['id'] === getAdminId() ? 'selected' : '' ?>><?= e((string) $user['username']) ?></option><?php endforeach; ?>
            </select>
            <label for="dl-minutes"><?= e(__('dl_expiry')) ?></label>
            <select name="minutes" id="dl-minutes"><option value="15"><?= e(__('dl_15min')) ?></option><option value="60" selected><?= e(__('dl_1hour')) ?></option><option value="1440"><?= e(__('dl_1day')) ?></option><option value="10080"><?= e(__('dl_7days')) ?></option></select>
            <label for="dl-note"><?= e(__('dl_note')) ?></label><input type="text" name="note" id="dl-note" maxlength="200" placeholder="<?= e(__('dl_note_placeholder')) ?>">
            <p><?= e(__('dl_permission_hint')) ?></p>
            <button type="submit"><?= e(__('dl_create')) ?></button>
        </form>
        <?php endif; ?>
    </section>
    <section class="dl-card">
        <h2><?= e(__('dl_protection')) ?></h2><p><?= e(__('dl_protection_hint')) ?></p>
        <form method="post" class="dl-form">
            <?= csrfField() ?><input type="hidden" name="dl_action" value="protection">
            <label for="dl-attempts"><?= e(__('dl_attempts')) ?></label><input type="number" name="attempts" id="dl-attempts" min="1" max="20" required value="<?= e((string) settingModel()->get('login_max_attempts', '5')) ?>">
            <label for="dl-lock"><?= e(__('dl_lock')) ?></label><input type="number" name="lock_minutes" id="dl-lock" min="1" max="1440" required value="<?= e((string) settingModel()->get('login_lock_minutes', '15')) ?>">
            <button type="submit"><?= e(__('dl_save')) ?></button>
        </form>
        <p><a href="/admin/setting_security.php"><?= e(__('dl_ip_settings')) ?></a> · <a href="/admin/profile.php"><?= e(__('dl_2fa_settings')) ?></a></p>
        <p><?= e(__('dl_2fa_hint')) ?></p>
    </section>
    </div>
    <section class="dl-card">
        <h2><?= e(__('dl_history')) ?></h2><p><?= e(__('dl_history_hint')) ?></p>
        <div class="dl-table-wrap"><table><thead><tr>
            <th><?= e(__('dl_account')) ?></th><th><?= e(__('dl_note')) ?></th><th><?= e(__('dl_expiry')) ?></th><th><?= e(__('dl_status')) ?></th><th><?= e(__('dl_used')) ?></th><th><?= e(__('dl_action')) ?></th>
        </tr></thead><tbody>
        <?php foreach ($rows as $row):
            $status = (int) $row['used_at'] > 0 ? 'dl_redeemed' : ((int) $row['revoked_at'] > 0 ? 'dl_revoked' : ((int) $row['expires_at'] <= time() ? 'dl_expired' : 'dl_active'));
        ?>
            <tr><td><?= e((string) ($row['username'] ?? __('dl_deleted'))) ?></td><td><?= e((string) $row['note']) ?></td><td><?= e(date('Y-m-d H:i', (int) $row['expires_at'])) ?></td><td><span class="dl-badge"><?= e(__($status)) ?></span></td><td><?= e((int) $row['used_at'] > 0 ? date('Y-m-d H:i', (int) $row['used_at']) . ' / ' . $row['used_ip'] : '—') ?></td><td>
                <?php if ($status === 'dl_active'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="dl_action" value="revoke"><input type="hidden" name="link_id" value="<?= e((string) $row['id']) ?>"><button class="dl-secondary"><?= e(__('dl_revoke')) ?></button></form><?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="6"><?= e(__('dl_empty')) ?></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<script src="/plugins/dologin/admin.js" defer></script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
