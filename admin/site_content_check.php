<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteContentChecks.php';
checkLogin();
requirePermission('*');
$report = null;
$errorMessage = '';
if (get('scan') === '1') {
    try { $report = SiteContentChecks::run(ROOT_PATH); }
    catch (Throwable $error) { $errorMessage = __('sc_failed'); }
}
$pageTitle = __('sc_title');
$currentMenu = 'site_setup';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="max-w-4xl space-y-6">
    <header><a href="/admin/site_setup.php" class="underline text-primary"><?= e(__('setup_title')) ?></a>
        <h1 class="text-2xl font-bold mt-2"><?= e($pageTitle) ?></h1><p class="text-gray-600 mt-2"><?= e(__('sc_intro')) ?></p></header>
    <form method="get"><input type="hidden" name="scan" value="1"><button class="bg-primary text-white rounded px-4 py-3" type="submit"><?= e(__('sc_run')) ?></button></form>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($report !== null): ?>
        <p role="status"><?= e(__('sc_summary', ['count' => (string) $report['scanned'], 'issues' => (string) count($report['issues'])])) ?></p>
        <?php if ($report['limited']): ?><p class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__('sc_limited')) ?></p><?php endif; ?>
        <?php if ($report['issues'] === []): ?><p class="bg-green-50 text-green-700 p-4 rounded"><?= e(__('sc_clear')) ?></p><?php endif; ?>
        <ul class="space-y-3">
            <?php foreach ($report['issues'] as $issue): ?>
            <li class="bg-white shadow rounded p-4 flex flex-wrap items-center justify-between gap-4">
                <div class="min-w-0 flex-1"><p class="font-medium break-words"><?= e($issue['label']) ?></p>
                    <p class="text-gray-700 mt-1"><?= e(__($issue['kind'])) ?></p>
                    <?php if ($issue['detail'] !== ''): ?><p class="break-all text-sm text-gray-600 mt-1"><?= e($issue['detail']) ?></p><?php endif; ?></div>
                <a href="<?= e($issue['url']) ?>" class="border rounded px-4 py-3"><?= e(__('setup_review')) ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <section class="bg-white rounded shadow p-6 space-y-3"><h2 class="text-lg font-bold"><?= e(__('sc_links')) ?></h2>
        <p class="text-gray-600"><?= e(__('sc_links_hint')) ?></p>
        <a class="inline-block border rounded px-4 py-3" href="/admin/plugin_page.php?plugin=seo#seo-linkcheck"><?= e(__('sc_links_open')) ?></a>
        <a class="inline-block border rounded px-4 py-3" href="/admin/site_health.php"><?= e(__('sc_health')) ?></a>
    </section>
</div>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
