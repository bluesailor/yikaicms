<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/SiteTemplateService.php';
require_once ROOT_PATH . '/includes/SiteImportReport.php';
checkLogin();
requirePermission('*');
$service = new SiteTemplateService(ROOT_PATH);
$errorMessage = '';
$exportCheck = null;
$notice = (string) ($_SESSION['site_template_notice'] ?? '');
unset($_SESSION['site_template_notice']);
$brand = ['site_name' => (string) config('site_name'), 'contact_phone' => '', 'contact_email' => '', 'contact_address' => ''];
// 导入完成后实时生成报告（不落库）：随时可重跑，也不会出现过期结论
$report = null;
if (($notice === 'st_applied' || get('report') === '1') && $service->recovery() !== null) {
    $report = SiteImportReport::build(ROOT_PATH);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        $action = post('action');
        if ($action === 'export') {
            $exportCheck = $service->exportCheck();
            if ($exportCheck['blocked'] !== '') throw new RuntimeException($exportCheck['blocked']);
            if (($exportCheck['issues'] !== [] || $exportCheck['limited']) && post('confirm_export') !== '1') throw new RuntimeException('st_export_review');
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
        if ($action === 'stage') {
            // 分阶段提取（E03）：每次只处理一批，前端按返回的进度继续调用。
            // 服务端决定推进节奏，客户端不能指定处理哪些条目或跳到哪个阶段。
            success($service->stage(post('token'), getAdminId()));
        }
        if ($action === 'check_export') {
            $exportCheck = $service->exportCheck();
        } elseif ($action === 'prepare') {
            $upload = $_FILES['package'] ?? [];
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) throw new RuntimeException('st_upload');
            // 与模板市场一致：先预览，已有内容的站在导入那一步确认替换
            $_SESSION['site_template_preview'] = $service->prepare((string) $upload['tmp_name'], getAdminId(), !$service->canApply());
        } elseif ($action === 'install_plugins') {
            // 导入页勾选的插件：本站已有的启用，官方插件市场有的走与插件页同一条校验链安装后启用
            $selected = array_values(array_filter(array_map('strval', (array) ($_POST['plugins'] ?? [])),
                static fn(string $slug): bool => preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) === 1));
            $installed = $service->installRequiredPlugins(post('token'), getAdminId(), $selected);
            foreach ($installed as $result) {
                adminLog('plugin', $result['ok'] ? 'site_template_enable' : 'site_template_enable_failed', 'Site template plugin: ' . $result['slug']);
            }
            success(['results' => $installed]);
        } elseif ($action === 'refresh_preview') {
            // 必须是新的请求：新启用的插件这时已加载，整站数据适配器才注册上，导入会带上它们的数据
            $_SESSION['site_template_preview'] = $service->refreshPreview(post('token'), getAdminId());
            success(['token' => $_SESSION['site_template_preview']['token'], 'missing' => count($_SESSION['site_template_preview']['missing_plugins'])]);
        } elseif ($action === 'apply') {
            foreach ($brand as $key => $_value) $brand[$key] = post($key);
            $replacedExisting = !empty($_SESSION['site_template_preview']['replace_existing']);
            // 官方模板市场验签过的包可免「信任来源」勾选，但「确认替换」始终要管理员自己勾
            $service->apply(post('token'), getAdminId(), $brand, post('trusted') === '1', post('confirm') === '1');
            unset($_SESSION['site_template_preview']);
            // 整站替换会清空并重写 28 张内容表：不留痕就无从追查谁在何时做的
            adminLog('theme', 'import', $replacedExisting ? 'Site template applied over existing site content' : 'Site template applied');
            $_SESSION['site_template_notice'] = 'st_applied';
        } elseif ($action === 'cancel_import') {
            unset($_SESSION['site_template_preview']);
            redirect('/admin/site_template_market.php');
        } elseif ($action === 'restore' && post('confirm') === '1') {
            $service->restore();
            unset($_SESSION['site_template_preview']);
            adminLog('theme', 'import', 'Site template restored to pre-import snapshot');
            $_SESSION['site_template_notice'] = 'st_restored';
        } else { throw new RuntimeException('st_invalid'); }
        if ($action !== 'check_export') redirect('/admin/site_templates.php');
    } catch (Throwable $error) {
        $code = $error->getMessage();
        $errorMessage = __(preg_match('/^st_[a-z_]+$/D', $code) ? $code : 'st_invalid');
        if (post('ajax') === '1') error($errorMessage);
    }
}
$fresh = false;
$recovery = null;
try { $fresh = $service->canApply(); $recovery = $service->recovery(); }
catch (Throwable $error) { $errorMessage = __('st_storage'); }
$preview = $_SESSION['site_template_preview'] ?? null;
// 导入资料默认沿用模板自带的名称与联系方式（提交失败时保留管理员刚填的）
if (is_array($preview) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($brand as $key => $_value) {
        $templateValue = (string) ($preview['brand'][$key] ?? '');
        if ($templateValue !== '') $brand[$key] = $templateValue;
    }
}
$importing = is_array($preview) && $notice !== 'st_applied';
$pageTitle = __('st_title');
$currentMenu = 'site_setup';
require_once ROOT_PATH . '/admin/includes/header.php';
require_once ROOT_PATH . '/includes/SiteSetup.php';

/** 导入检查按问题归组：同一类问题（如多个栏目没有直接正文）合成一行，列表折叠起来 */
$groupReport = static function (array $items): array {
    $groups = [];
    foreach ($items as $item) {
        $key = $item['level'] . '|' . $item['code'];
        $groups[$key] ??= ['level' => $item['level'], 'code' => $item['code'], 'items' => []];
        $groups[$key]['items'][] = $item;
    }
    usort($groups, static fn(array $a, array $b): int => ($a['level'] === SiteImportReport::FAILED ? 0 : 1) <=> ($b['level'] === SiteImportReport::FAILED ? 0 : 1));
    return $groups;
};
?>
<div class="max-w-4xl space-y-6">
    <?php require ROOT_PATH . '/admin/includes/setting_sources_notice.php'; ?>
    <header>
        <?php $breadcrumb = $importing
            ? [[__('setup_title'), '/admin/site_setup.php'], [__('st_market_title'), '/admin/site_template_market.php'], [__('st_wizard_title')]]
            : [[__('setup_title'), '/admin/site_setup.php'], [__('st_title')]];
        require ROOT_PATH . '/admin/includes/breadcrumb.php'; ?>
        <h1 class="text-2xl font-bold text-gray-800"><?= e($importing ? __('st_wizard_title') : $pageTitle) ?></h1>
        <?php if (!$importing): ?>
        <p class="text-gray-600 mt-2"><?= e(__('st_intro')) ?></p>
        <?php // 整站包是覆盖整个站点的；只想复用一个页面或区块的人该去 Blox 模板库 ?>
        <p class="text-gray-600 mt-1 text-sm"><?= e(__('st_scope_note')) ?>
            <a href="/admin/blox_templates.php" class="text-primary hover:underline"><?= e(__('admin_blox_templates')) ?></a>
        </p>
        <?php endif; ?>
    </header>
    <?php if ($errorMessage !== ''): ?><p role="alert" class="bg-red-50 text-red-700 p-4 rounded"><?= e($errorMessage) ?></p><?php endif; ?>
    <?php if ($notice === 'st_restored'): ?><p role="status" class="bg-green-50 text-green-700 p-4 rounded"><?= e(__($notice)) ?></p><?php endif; ?>

    <?php if ($notice === 'st_applied'): ?>
    <?php // 导入完成：先告诉管理员接下来做什么，检查结果按问题归组放在下面 ?>
    <section class="rounded-lg border border-green-200 bg-white shadow-sm overflow-hidden" data-testid="st-import-done" aria-labelledby="st-done-title">
        <div class="bg-green-50 px-5 py-4">
            <h2 id="st-done-title" class="text-lg font-bold text-green-800 flex items-center gap-2"><i class="ti ti-circle-check" aria-hidden="true"></i><?= e(__('st_done_title')) ?></h2>
            <p class="mt-1 text-sm text-green-900"><?= e(__('st_done_intro')) ?></p>
        </div>
        <ul class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 border-t border-green-100">
            <?php foreach ([
                ['open', 'ti-world', '/', true],
                ['home', 'ti-layout-dashboard', SiteSetup::homeEditUrl(), false],
                ['brand', 'ti-id-badge', '/admin/setting.php?tab=basic', false],
                ['contact', 'ti-phone', '/admin/setting_contact.php', false],
            ] as [$doneKey, $doneIcon, $doneUrl, $doneBlank]): ?>
            <li class="border-gray-100 sm:border-b sm:odd:border-r">
                <a href="<?= e($doneUrl) ?>" data-testid="st-done-<?= e($doneKey) ?>" <?= $doneBlank ? 'target="_blank" rel="noopener"' : '' ?>
                   class="flex items-start gap-3 px-5 py-4 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
                    <i class="ti <?= e($doneIcon) ?> mt-0.5 text-lg text-primary" aria-hidden="true"></i>
                    <span><span class="block font-semibold text-gray-900"><?= e(__('st_done_' . $doneKey)) ?></span>
                        <span class="block text-xs text-gray-500 mt-0.5"><?= e(__('st_done_' . $doneKey . '_desc')) ?></span></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500"><?= e(__('st_applied')) ?></p>
    </section>
    <?php endif; ?>

    <?php if ($report !== null): ?>
    <?php $reportGroups = $groupReport($report['items']); ?>
    <section class="rounded-lg border border-gray-200 bg-white p-5 space-y-3" aria-labelledby="ir-title" data-testid="st-import-report">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="ir-title" class="font-bold text-gray-800"><?= e(__('ir_title')) ?></h2>
            <a href="/admin/site_templates.php?report=1" class="text-xs text-primary hover:underline"><?= e(__('ir_recheck')) ?></a>
        </div>
        <p class="text-sm text-gray-600"><?= e(__('ir_status_' . $report['status'])) ?></p>
        <?php if ($reportGroups === []): ?>
        <p class="text-sm text-green-700"><?= e(__('st_done_checks_none')) ?></p>
        <?php else: ?>
        <ul class="divide-y rounded border text-sm">
            <?php foreach ($reportGroups as $group): $groupFailed = $group['level'] === SiteImportReport::FAILED; $first = $group['items'][0]; ?>
            <li class="p-3" data-testid="st-report-group">
                <details <?= $groupFailed ? 'open' : '' ?>>
                    <summary class="flex cursor-pointer flex-wrap items-baseline gap-2">
                        <span class="text-xs px-1.5 py-0.5 rounded <?= $groupFailed ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' ?>"><?= e(__('ir_level_' . $group['level'])) ?></span>
                        <span class="text-gray-800"><?= e(__($group['code'])) ?></span>
                        <?php if (count($group['items']) > 1): ?><span class="text-xs text-gray-500"><?= e(__('st_done_group_count', ['count' => (string) count($group['items'])])) ?></span><?php endif; ?>
                        <?php if (count($group['items']) === 1 && $first['url'] !== ''): ?><a class="ml-auto text-xs text-primary hover:underline" href="<?= e($first['url']) ?>"><?= e(__('ir_fix')) ?></a><?php endif; ?>
                    </summary>
                    <?php if (count($group['items']) > 1 || $first['label'] !== '' || $first['detail'] !== ''): ?>
                    <ul class="mt-2 space-y-1 pl-2 text-gray-600">
                        <?php foreach ($group['items'] as $item): ?>
                        <li class="flex flex-wrap items-baseline gap-2">
                            <?php if ($item['label'] !== ''): ?><span><?= e($item['label']) ?></span><?php endif; ?>
                            <?php if ($item['detail'] !== ''): ?><code class="text-xs text-gray-500 break-all"><?= e($item['detail']) ?></code><?php endif; ?>
                            <?php if ($item['url'] !== ''): ?><a class="text-xs text-primary hover:underline" href="<?= e($item['url']) ?>"><?= e(__('ir_fix')) ?></a><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </details>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($report['limited']): ?><p class="text-xs text-gray-500"><?= e(__('ir_limited')) ?></p><?php endif; ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($importing): ?>
    <?php
    // 导入向导：只摆本次导入需要的东西；导出与上传入口先收起来，免得新用户在两件事之间迷路
    $origin = is_array($preview['origin'] ?? null) ? $preview['origin'] : ['official' => false, 'name' => '', 'screenshot' => '', 'version' => ''];
    $official = !empty($origin['official']);
    $summary = $preview['summary'];
    $emptyLanguages = is_array($summary['languages_empty'] ?? null) ? $summary['languages_empty'] : [];
    $missingPlugins = is_array($preview['missing_plugins'] ?? null) ? $preview['missing_plugins'] : [];
    $templateName = (string) ($origin['name'] !== '' ? $origin['name'] : $summary['theme']);
    ?>
    <section id="st-preview" class="bg-white rounded-lg shadow overflow-hidden" data-testid="st-import-wizard" aria-labelledby="st-wizard-name">
        <div class="flex flex-col sm:flex-row gap-4 p-5 border-b">
            <?php if ($origin['screenshot'] !== ''): ?><img src="<?= e($origin['screenshot']) ?>" alt="" referrerpolicy="no-referrer" class="w-full sm:w-48 aspect-video object-cover object-top rounded border bg-gray-100"><?php endif; ?>
            <div class="min-w-0 flex-1">
                <h2 id="st-wizard-name" class="text-xl font-bold text-gray-900"><?= e($templateName) ?></h2>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    <span class="rounded px-2 py-0.5 text-xs <?= $official ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600' ?>" data-testid="st-wizard-origin"><?= e(__($official ? 'st_wizard_official' : 'st_wizard_uploaded')) ?></span>
                    <?php if ($origin['version'] !== ''): ?><span class="text-xs text-gray-500"><?= e(__('st_market_version', ['version' => $origin['version'], 'cms' => $summary['cms'], 'series' => SiteTemplateArchive::cmsSeries((string) $summary['cms'])])) ?></span><?php endif; ?>
                </p>
                <p class="mt-2 text-sm text-gray-600"><?= e(__('st_wizard_languages', ['list' => (string) $summary['languages']])) ?></p>
                <form method="post" class="mt-3"><?= csrfField() ?><input type="hidden" name="action" value="cancel_import">
                    <button type="submit" class="text-sm text-gray-500 underline hover:text-gray-800" data-testid="st-wizard-cancel"><?= e(__('st_wizard_cancel')) ?></button></form>
            </div>
        </div>

        <ol class="divide-y">
            <li class="p-5 space-y-3">
                <h3 class="font-bold text-gray-800"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs text-blue-700">1</span><?= e(__('st_wizard_step_content')) ?></h3>
                <ul class="flex flex-wrap gap-2 text-sm" data-testid="st-wizard-counts">
                    <?php foreach (['channels', 'contents', 'products', 'forms', 'media'] as $countKey): ?>
                    <li class="rounded bg-gray-50 border px-3 py-1.5"><span class="font-semibold text-gray-900"><?= e((string) $summary[$countKey]) ?></span> <span class="text-gray-600"><?= e(__('st_count_' . $countKey)) ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($emptyLanguages !== []): ?>
                <p role="status" class="bg-amber-50 text-amber-900 p-3 rounded text-sm"><?= e(__('st_lang_empty', ['list' => SiteTemplateLanguages::labels($emptyLanguages)])) ?></p>
                <?php endif; ?>
                <details class="text-sm text-gray-600">
                    <summary class="cursor-pointer"><?= e(__('st_wizard_tech')) ?></summary>
                    <dl class="mt-2 grid grid-cols-2 gap-2">
                        <?php foreach (['theme', 'cms', 'plugins'] as $techKey): ?>
                        <div class="min-w-0"><dt class="text-xs text-gray-500"><?= e(__('st_count_' . $techKey)) ?></dt><dd class="break-words"><?= e((string) $summary[$techKey]) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </details>
            </li>

            <?php if ($missingPlugins !== []):
                $pluginActions = is_array($preview['plugin_actions'] ?? null) ? array_column($preview['plugin_actions'], null, 'slug') : [];
                $themePlugins = is_array($preview['theme_plugins'] ?? null) ? $preview['theme_plugins'] : [];
                $optionalPlugins = false; ?>
            <li class="p-5 space-y-3" data-testid="st-missing-plugins">
                <h3 class="font-bold text-gray-800"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs text-blue-700">2</span><?= e(__('st_wizard_step_plugins')) ?></h3>
                <p class="text-sm text-gray-600"><?= e(__('st_plugins_inline_intro')) ?></p>
                <ul class="space-y-2 text-sm">
                    <?php foreach ($missingPlugins as $plugin):
                        $action = $pluginActions[$plugin['slug']] ?? ['action' => 'manual', 'available' => ''];
                        $required = in_array($plugin['slug'], $themePlugins, true);
                        $label = __('st_plugin_inline_' . ($action['action'] === 'manual' ? 'manual' : $action['action']), ['plugin' => $plugin['slug'], 'version' => (string) ($action['available'] ?: $plugin['version'])]); ?>
                    <li data-testid="st-missing-plugin" data-action="<?= e($action['action']) ?>" data-required="<?= $required ? '1' : '0' ?>" class="rounded border px-3 py-2">
                        <?php if ($action['action'] === 'manual'): ?>
                        <span class="<?= $required ? 'text-red-700' : 'text-amber-800' ?>"><code><?= e($plugin['slug']) ?></code> · <?= e($label) ?><?= $required ? ' ' . e(__('st_plugin_inline_blocking')) : '' ?></span>
                        <?php elseif ($required): ?>
                        <input type="hidden" name="plugins[]" value="<?= e($plugin['slug']) ?>" form="st-apply-form">
                        <span class="flex items-center gap-2"><i class="ti ti-circle-check text-green-600" aria-hidden="true"></i><span><?= e($label) ?></span>
                            <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600"><?= e(__('st_plugin_required')) ?></span></span>
                        <?php else: $optionalPlugins = true; ?>
                        <label class="flex items-center gap-2"><input type="checkbox" name="plugins[]" value="<?= e($plugin['slug']) ?>" checked form="st-apply-form"><span><?= e($label) ?></span></label>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($optionalPlugins): ?><p class="text-xs text-gray-500"><?= e(__('st_plugin_inline_skip')) ?></p><?php endif; ?>
            </li>
            <?php endif; ?>

            <li class="p-5">
                <form method="post" class="space-y-5" id="st-apply-form">
                    <?= csrfField() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                    <div class="space-y-3">
                        <h3 class="font-bold text-gray-800"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs text-blue-700"><?= $missingPlugins !== [] ? '3' : '2' ?></span><?= e(__('st_wizard_step_brand')) ?></h3>
                        <p class="text-sm text-gray-600"><?= e(__('st_wizard_brand_hint')) ?></p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php foreach ($brand as $key => $value): ?>
                            <div class="<?= $key === 'contact_address' ? 'sm:col-span-2' : '' ?>"><label class="block text-sm font-medium mb-1" for="st-<?= e($key) ?>"><?= e(__('st_' . $key)) ?></label>
                                <input id="st-<?= e($key) ?>" name="<?= e($key) ?>" type="<?= $key === 'contact_email' ? 'email' : 'text' ?>" maxlength="500" value="<?= e($value) ?>" <?= $key === 'site_name' ? 'required' : '' ?> class="border rounded px-3 py-2 w-full"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="space-y-3 border-t pt-5">
                        <h3 class="font-bold text-gray-800"><span class="mr-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-xs text-blue-700"><?= $missingPlugins !== [] ? '4' : '3' ?></span><?= e(__('st_wizard_step_confirm')) ?></h3>
                        <p id="st-stage-status" role="status" aria-live="polite" class="text-sm text-gray-600 hidden"></p>
                        <?php if ($official): ?>
                        <p class="flex gap-2 items-start text-sm text-green-800" data-testid="st-official-trust"><i class="ti ti-shield-check mt-0.5" aria-hidden="true"></i><span><?= e(__('st_wizard_official_trust')) ?></span></p>
                        <?php else: ?>
                        <label class="flex gap-2 items-start"><input type="checkbox" name="trusted" value="1" required class="mt-1"><span><?= e(__('st_trust_label')) ?></span></label>
                        <?php endif; ?>
                        <?php if (!empty($preview['replace_existing'])): ?>
                        <?php // 已有内容的站：只在真正导入前提醒一次，勾选的就是「已备份、确认替换」 ?>
                        <p class="bg-red-50 text-red-800 p-3 rounded text-sm" data-testid="st-replace-reminder"><?= e(__('st_replace_short')) ?>
                            <a href="/admin/database.php" class="font-medium underline"><?= e(__('st_replace_backup')) ?></a></p>
                        <label class="flex gap-2 items-start text-red-800"><input type="checkbox" name="confirm" value="1" required class="mt-1" data-testid="st-replace-confirm"><span><?= e(__('st_replace_confirm')) ?></span></label>
                        <?php else: ?>
                        <label class="flex gap-2 items-start"><input type="checkbox" name="confirm" value="1" required class="mt-1"><span><?= e(__('st_confirm')) ?></span></label>
                        <?php endif; ?>
                        <button type="submit" class="bg-primary text-white rounded px-5 py-3 font-medium" data-testid="st-apply"><?= e(__('st_apply')) ?></button>
                    </div>
                </form>
                <script>
                // 一次点击完成：勾选的插件先装好并启用 → 新请求里重建预览（新插件的数据适配器此时才注册）
                // → 分批把媒体落盘（大包的文件 IO 按服务端预算推进）→ 提交导入，只剩数据库替换与生效。
                (function () {
                    var form = document.getElementById('st-apply-form');
                    var status = document.getElementById('st-stage-status');
                    var tokenInput = form.querySelector('input[name="token"]');
                    var csrfName = <?= json_encode(CSRF_TOKEN_NAME) ?>;
                    var csrf = form.querySelector('input[name="' + csrfName + '"]').value;
                    var texts = <?= json_encode([
                        'progress' => __('st_stage_progress'),
                        'failed' => __('st_stage_failed'),
                        'plugins' => __('st_plugins_installing'),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    var ready = false;
                    var post = function (fields) {
                        var body = new URLSearchParams();
                        body.set(csrfName, csrf);
                        body.set('ajax', '1');
                        body.set('token', tokenInput.value);
                        fields.forEach(function (field) { body.append(field[0], field[1]); });
                        return fetch(window.location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function (response) { return response.json(); })
                            .then(function (result) {
                                if (!result || Number(result.code) !== 0) throw new Error((result && result.msg) || texts.failed);
                                return result.data || {};
                            });
                    };
                    var installPlugins = function (plugins) {
                        status.textContent = texts.plugins;
                        return post([['action', 'install_plugins']].concat(plugins.map(function (slug) { return ['plugins[]', slug]; })))
                            .then(function (data) {
                                var failed = (data.results || []).filter(function (item) { return !item.ok; });
                                if (failed.length) throw new Error(failed.map(function (item) { return item.slug + ': ' + item.msg; }).join('; '));
                                return post([['action', 'refresh_preview']]);
                            })
                            .then(function (data) { tokenInput.value = data.token; });
                    };
                    var stage = function () {
                        return post([['action', 'stage']]).then(function (data) {
                            status.textContent = texts.progress
                                .replace(':done', String(data.done || 0))
                                .replace(':total', String(data.total || 0));
                            if (!data.complete) return stage();
                        });
                    };
                    form.addEventListener('submit', function (event) {
                        if (ready) return;
                        event.preventDefault();
                        var button = form.querySelector('button[type="submit"]');
                        button.disabled = true;
                        status.classList.remove('hidden');
                        var plugins = new FormData(form).getAll('plugins[]');
                        (plugins.length ? installPlugins(plugins) : Promise.resolve())
                            .then(stage)
                            .then(function () { ready = true; form.submit(); })
                            .catch(function (error) {
                                status.textContent = String((error && error.message) || texts.failed);
                                button.disabled = false;
                            });
                    });
                })();
                </script>
            </li>
        </ol>
    </section>
    <?php elseif ($notice !== 'st_applied'): ?>

    <?php // 最常见的路子放最前面：从模板市场挑一套 ?>
    <section class="rounded-lg border border-blue-200 bg-blue-50 p-5 flex flex-col sm:flex-row sm:items-center gap-4" data-testid="st-market-cta">
        <div class="flex-1">
            <h2 class="text-lg font-bold text-gray-900"><?= e(__('st_market_cta_title')) ?></h2>
            <p class="mt-1 text-sm text-gray-700"><?= e(__('st_market_cta_desc')) ?></p>
        </div>
        <a href="/admin/site_template_market.php" class="shrink-0 bg-primary text-white rounded px-5 py-3 font-medium text-center"><?= e(__('st_market_cta_button')) ?></a>
    </section>

    <section class="bg-white rounded-lg shadow p-6 space-y-4" aria-labelledby="st-import">
        <h2 id="st-import" class="text-lg font-bold"><?= e(__('st_import_title')) ?></h2>
        <p class="text-gray-600"><?= e(__('st_import_hint')) ?></p>
        <form method="post" enctype="multipart/form-data" class="space-y-3">
            <?= csrfField() ?><input type="hidden" name="action" value="prepare">
            <label class="block font-medium" for="st-package"><?= e(__('st_package')) ?></label>
            <input id="st-package" type="file" name="package" accept=".zip" required class="block w-full max-w-full" aria-describedby="st-size">
            <p id="st-size" class="text-sm text-gray-600"><?= e(__('st_size')) ?></p>
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_preview')) ?></button>
        </form>
    </section>

    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="st-export">
        <h2 id="st-export" class="text-lg font-bold"><?= e(__('st_export_title')) ?></h2>
        <p class="text-gray-600 mt-2"><?= e(__('st_export_hint')) ?></p>
        <p class="text-sm text-gray-600 mt-2"><?= e(__('st_scope')) ?></p>
        <p class="text-sm text-gray-600 mt-2"><?= e(__('usability_export_scope')) ?></p>
        <form method="post" class="mt-4"><?= csrfField() ?><input type="hidden" name="action" value="check_export">
            <button type="submit" class="border rounded px-4 py-3"><?= e(__('usability_export_check')) ?></button></form>
        <?php if ($exportCheck !== null): ?>
        <div role="status" class="mt-4 space-y-3" data-testid="export-check-report">
            <?php if ($exportCheck['blocked'] !== ''): ?>
            <p class="bg-red-50 text-red-700 p-4 rounded"><?= e(__('usability_export_blocked')) ?> <?= e(__($exportCheck['blocked'])) ?></p>
            <?php else: ?>
            <p><?= e(__('usability_export_checked', ['count' => (string) $exportCheck['scanned']])) ?></p>
            <?php foreach ($exportCheck['issues'] as $exportIssue): ?>
            <p class="bg-amber-50 text-amber-900 p-4 rounded"><?= e(__($exportIssue['code'])) ?> — <?= e($exportIssue['label']) ?> <code class="break-all"><?= e($exportIssue['detail']) ?></code>
                <a href="<?= e($exportIssue['url']) ?>" class="text-primary underline"><?= e(__('ir_fix')) ?></a></p>
            <?php endforeach; ?>
            <?php if ($exportCheck['limited']): ?><p><?= e(__('ir_limited')) ?></p><?php endif; ?>
            <form method="post" class="space-y-3"><?= csrfField() ?><input type="hidden" name="action" value="export">
                <?php if ($exportCheck['issues'] !== [] || $exportCheck['limited']): ?><label class="block"><input type="checkbox" name="confirm_export" value="1" required> <?= e(__('usability_export_confirm')) ?></label><?php endif; ?>
                <button type="submit" class="border rounded px-4 py-3"><?= e(__('st_export')) ?></button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($recovery !== null && !$importing): ?>
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
</div>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
