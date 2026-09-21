<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteSetup.php';
checkLogin();
requirePermission('*');

$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (post('action') === 'theme_home' && post('confirm') === '1') {
            $_SESSION['setup_home_undo'] = SiteSetup::changeHomeFlags(
                ['home_layout_active' => '0', 'home_blox_active' => '0'], post('fingerprint')
            );
        } elseif (post('action') === 'undo_home' && isset($_SESSION['setup_home_undo'])) {
            $undo = $_SESSION['setup_home_undo'];
            SiteSetup::changeHomeFlags($undo['flags'], $undo['fingerprint']);
            unset($_SESSION['setup_home_undo']);
        } else {
            throw new RuntimeException(__('setup_plan_stale'));
        }
        adminLog('home', 'source', 'Homepage source changed from site setup');
        $_SESSION['setup_home_notice'] = true;
        redirect('/admin/site_setup.php');
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}
$savedNotice = !empty($_SESSION['setup_home_notice']);
unset($_SESSION['setup_home_notice']);

$pageTitle = __('setup_title');
$currentMenu = 'site_setup';
$homeMode = SiteSetup::currentHomeMode();
$checks = SiteSetup::checklist();
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="space-y-6">
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($savedNotice): ?><p role="status" class="bg-green-50 text-green-700 p-4 rounded"><?= e(__('setup_home_saved')) ?></p><?php endif; ?>
    <header>
        <h1 class="text-2xl font-bold text-gray-800"><?= e($pageTitle) ?></h1>
        <p class="text-gray-600 mt-2"><?= e(__('setup_intro')) ?></p>
    </header>
    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="setup-home">
        <h2 id="setup-home" class="text-lg font-bold text-gray-800"><?= e(__('setup_home')) ?></h2>
        <p class="mt-2 text-gray-700"><?= e(__('setup_mode_' . $homeMode)) ?></p>
        <p class="mt-2 text-gray-600"><?= e(__('setup_home_hint')) ?></p>
        <div class="flex flex-wrap gap-3 mt-4">
            <a class="bg-primary text-white rounded px-4 py-3" href="/admin/setting_home.php"><?= e(__('setup_edit_home')) ?></a>
            <a class="border rounded px-4 py-3 text-gray-700" href="/admin/theme_content.php"><?= e(__('tc_title')) ?></a>
            <a class="border rounded px-4 py-3 text-gray-700" href="/admin/theme.php"><?= e(__('admin_theme')) ?>: <?= e(currentTheme()) ?></a>
            <a class="border rounded px-4 py-3 text-gray-700" href="/" target="_blank" rel="noopener"><?= e(__('setup_preview')) ?></a>
        </div>
        <?php if ($homeMode !== 'theme'): ?>
        <details class="mt-4 border rounded p-4">
            <summary class="cursor-pointer py-2 font-medium"><?= e(__('setup_home_switch')) ?></summary>
            <form method="post" class="space-y-3 mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="theme_home">
                <input type="hidden" name="fingerprint" value="<?= e(SiteSetup::homeFingerprint()) ?>">
                <p class="text-gray-700"><?= e(__('setup_home_switch_hint')) ?></p>
                <label class="flex items-start gap-2 py-2"><input type="checkbox" name="confirm" value="1" required>
                    <span><?= e(__('setup_home_confirm')) ?></span></label>
                <button class="border rounded px-4 py-3" type="submit"><?= e(__('setup_home_switch')) ?></button>
            </form>
        </details>
        <?php endif; ?>
        <?php if (isset($_SESSION['setup_home_undo'])): ?>
        <form method="post" class="mt-4">
            <?= csrfField() ?><input type="hidden" name="action" value="undo_home">
            <p class="text-sm text-gray-600"><?= e(__('setup_home_undo_hint')) ?></p>
            <button type="submit" class="border rounded px-4 py-3 mt-2"><?= e(__('setup_home_undo')) ?></button>
        </form>
        <?php endif; ?>
    </section>
    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="setup-content">
        <h2 id="setup-content" class="text-lg font-bold text-gray-800"><?= e(__('setup_content')) ?></h2>
        <p class="text-gray-600 mt-2"><?= e(__('setup_check_hint')) ?></p>
        <a class="inline-block border rounded px-4 py-3 mt-3" href="/admin/site_content_check.php"><?= e(__('sc_title')) ?></a>
        <ul class="divide-y mt-4">
            <?php foreach ($checks as $check): ?>
            <li class="flex flex-wrap items-center justify-between gap-3 py-4">
                <div><span class="font-medium text-gray-800"><?= e(__($check['label'])) ?></span>
                    <span class="text-sm text-gray-600"> — <?= e(__($check['done'] ? 'setup_present' : 'setup_missing')) ?></span></div>
                <a class="border rounded px-4 py-3 text-gray-700" href="<?= e($check['url']) ?>" aria-label="<?= e(__('setup_review') . ': ' . __($check['label'])) ?>"><?= e(__('setup_review')) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="setup-start">
        <h2 id="setup-start" class="text-lg font-bold text-gray-800"><?= e(__('setup_start')) ?></h2>
        <p class="text-gray-600 mt-2"><?= e(__('st_intro')) ?></p>
        <a class="inline-block bg-primary text-white rounded px-4 py-3 mt-4" href="/admin/site_templates.php"><?= e(__('st_title')) ?></a>
        <p class="text-gray-600 mt-2"><?= e(__('setup_recipe_limit')) ?></p>
        <a class="inline-block border rounded px-4 py-3 text-gray-700 mt-4" href="/admin/recipe.php"><?= e(__('admin_recipe')) ?></a>
    </section>
</div>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
