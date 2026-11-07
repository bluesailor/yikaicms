<?php
/**
 * Yikai CMS - 交付检查清单（评审 T09）
 *
 * 一页回答「这个站能不能交给客户了」：资料是否已有（实时）、自动检查是否通过（点按钮才跑，记下摘要）、
 * 人工确认过的事（询盘测试、手机端浏览、多语言浏览、交付）三块分开显示，每项带修复入口。
 * 汇总逻辑见 includes/DeliveryChecklist.php。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/DeliveryChecklist.php';
checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) post('action', '');
    $key = (string) post('key', '');
    try {
        if ($action === 'run') {
            // 两套检查都只读；内容自检要扫全站，先放开会话锁，同一账号的其它后台页不排队
            require_once ROOT_PATH . '/includes/SiteHealth.php';
            require_once ROOT_PATH . '/includes/SiteContentChecks.php';
            session_write_close();
            DeliveryChecklist::saveRun(DeliveryChecklist::summarizeRun(SiteHealth::runDirect(ROOT_PATH), SiteContentChecks::run(ROOT_PATH), time()));
            session_start();
            adminLog('delivery', 'run', '运行交付检查');
            $_SESSION['delivery_notice'] = 'dlv_run_done';
        } elseif ($action === 'accept' && in_array($key, DeliveryChecklist::ACCEPT_KEYS, true)) {
            DeliveryChecklist::accept($key, (int) ($_SESSION['admin_id'] ?? 0), (string) ($_SESSION['admin_username'] ?? ''), time());
            adminLog('delivery', 'accept', '交付确认：' . $key);
            $_SESSION['delivery_notice'] = 'dlv_accepted';
        } elseif ($action === 'revoke' && in_array($key, DeliveryChecklist::ACCEPT_KEYS, true)) {
            DeliveryChecklist::revoke($key);
            adminLog('delivery', 'revoke', '撤销交付确认：' . $key);
            $_SESSION['delivery_notice'] = 'dlv_revoked';
        } else {
            throw new RuntimeException('dlv_failed');
        }
    } catch (Throwable) {
        $_SESSION['delivery_notice'] = 'dlv_failed';
    }
    redirect('/admin/delivery_check.php');
}
$notice = (string) ($_SESSION['delivery_notice'] ?? '');
unset($_SESSION['delivery_notice']);

$state = DeliveryChecklist::state();
$list = DeliveryChecklist::evaluate(DeliveryChecklist::facts(), $state);
$run = $state['run'];
$pageTitle = __('dlv_title');
$currentMenu = 'site_setup';
$icon = static fn (string $status): string => match ($status) {
    'done' => 'ti-circle-check text-green-600',
    'warn' => 'ti-alert-circle text-amber-500',
    'fail' => 'ti-circle-x text-red-600',
    'unknown' => 'ti-help-circle text-gray-400',
    default => 'ti-circle-dashed text-amber-500',
};
$countDone = static fn (array $rows): string => count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'done')) . '/' . count($rows);
$detail = static fn (array $row): string => $row['detail'] === '' ? '' : __($row['detail'], $row['params']);
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="max-w-4xl space-y-6">
    <header><?php $breadcrumb = [[__('setup_title'), '/admin/site_setup.php'], [$pageTitle]]; require ROOT_PATH . '/admin/includes/breadcrumb.php'; ?>
        <h1 class="text-2xl font-bold"><?= e($pageTitle) ?></h1>
        <p class="text-gray-600 mt-2"><?= e(__('dlv_intro')) ?></p>
        <p class="mt-3 text-sm <?= $list['open'] === 0 ? 'text-green-700' : 'text-amber-700' ?>" role="status" data-testid="delivery-open"><?= e($list['open'] === 0 ? __('dlv_all_done') : __('dlv_open', ['n' => (string) $list['open']])) ?></p>
    </header>
    <?php if ($notice !== ''): ?>
    <p class="<?= $notice === 'dlv_failed' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?> p-4 rounded" role="alert"><?= e(__($notice)) ?></p>
    <?php endif; ?>

    <?php foreach (['data', 'check', 'accept'] as $group): ?>
    <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6" aria-labelledby="dlv-<?= $group ?>" data-testid="delivery-<?= $group ?>">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="dlv-<?= $group ?>" class="flex items-center gap-2 text-lg font-bold text-gray-800"><?= e(__('dlv_group_' . $group)) ?>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-700"><?= e($countDone($list[$group])) ?></span></h2>
            <?php if ($group === 'check'): ?>
            <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="run">
                <button type="submit" class="bg-primary text-white rounded px-4 py-2 text-sm" data-testid="delivery-run"><?= e(__('dlv_run')) ?></button></form>
            <?php endif; ?>
        </div>
        <p class="text-gray-600 mt-1 text-sm"><?= e(__('dlv_group_' . $group . '_hint')) ?>
            <?php if ($group === 'check' && (int) ($run['at'] ?? 0) > 0): ?> <?= e(__('dlv_last_run', ['time' => date('Y-m-d H:i', (int) $run['at'])])) ?><?php endif; ?></p>
        <ul class="divide-y mt-3">
            <?php foreach ($list[$group] as $row): ?>
            <li class="flex flex-wrap items-center justify-between gap-3 py-3" data-item="<?= e($row['key']) ?>" data-status="<?= e($row['status']) ?>">
                <span class="flex min-w-0 flex-1 items-start gap-2 text-gray-800">
                    <i class="ti <?= $icon($row['status']) ?> text-lg mt-0.5" aria-hidden="true"></i>
                    <span class="min-w-0">
                        <span class="font-medium"><?= e(__($row['label'])) ?></span>
                        <span class="sr-only"><?= e(__('dlv_status_' . $row['status'])) ?></span>
                        <?php if ($detail($row) !== ''): ?><span class="block text-sm text-gray-600 break-words"><?= e($detail($row)) ?></span><?php endif; ?>
                        <?php if ($group === 'accept' && is_array($row['by'] ?? null)): ?>
                        <span class="block text-sm text-green-700"><?= e(__('dlv_accepted_by', ['name' => $row['by']['name'], 'time' => date('Y-m-d H:i', $row['by']['at'])])) ?></span>
                        <?php elseif ($group === 'accept'): ?>
                        <span class="block text-sm text-gray-600"><?= e(__('dlv_accept_' . $row['key'] . '_hint')) ?></span>
                        <?php endif; ?>
                        <?php if ($row['key'] === 'health'): ?>
                            <?php foreach (is_array($run['health']['issues'] ?? null) ? $run['health']['issues'] : [] as $issue): ?>
                            <span class="block text-sm text-red-700 break-words">· <?= e((string) $issue['title']) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-3 text-sm">
                    <?php if ($row['url'] !== ''): ?>
                    <a class="text-primary hover:underline" href="<?= e($row['url']) ?>"<?= $row['url'] === '/' ? ' target="_blank" rel="noopener"' : '' ?> aria-label="<?= e(__('setup_review') . ': ' . __($row['label'])) ?>"><?= e(__($row['url'] === '/' ? 'dlv_open_site' : 'setup_review')) ?></a>
                    <?php endif; ?>
                    <?php if ($group === 'accept'): ?>
                    <form method="post"><?= csrfField() ?><input type="hidden" name="key" value="<?= e($row['key']) ?>">
                        <?php if ($row['status'] === 'done'): ?>
                        <button type="submit" name="action" value="revoke" class="text-gray-600 hover:underline"><?= e(__('dlv_revoke')) ?></button>
                        <?php else: ?>
                        <button type="submit" name="action" value="accept" class="border rounded px-3 py-1.5" data-testid="delivery-accept-<?= e($row['key']) ?>"
                            <?php if ($row['key'] === 'handover' && $list['open'] > 0): ?>title="<?= e(__('dlv_open', ['n' => (string) $list['open']])) ?>"<?php endif; ?>><?= e(__('dlv_accept')) ?></button>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>
    <p class="text-sm text-gray-500"><?= e(__('dlv_footer')) ?></p>
</div>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
