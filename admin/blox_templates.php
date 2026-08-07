<?php
/** Blox 模板库：受控 JSON 导入、来源查看和本地草稿管理。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

if (!bloxEditorEnabled()) {
    error(__('blox_feature_disabled'));
}
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$tableReady = db()->tableExists('blox_templates');
$errorMessage = '';
$notice = '';

if (isset($_GET['imported'])) {
    $notice = __('blox_tpl_imported_msg') . ' #' . max(0, (int) $_GET['imported']);
}
if (isset($_GET['deleted'])) {
    $notice = __('blox_tpl_deleted_msg');
}
if (isset($_GET['status'])) {
    $notice = __('blox_tpl_status_updated_msg');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action', '');
    try {
        if (!$tableReady) {
            throw new RuntimeException(__('blox_tpl_table_missing'));
        }

        if ($action === 'import') {
            $json = trim((string) post('template_json', ''));
            $file = $_FILES['template_file'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(__('blox_tpl_upload_failed'));
                }
                if ((int) ($file['size'] ?? 0) > BloxTemplateImporter::MAX_BYTES) {
                    throw new RuntimeException(__('blox_tpl_too_large'));
                }
                $originalName = (string) ($file['name'] ?? '');
                if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'json') {
                    throw new RuntimeException(__('blox_tpl_json_only'));
                }
                $tmpName = (string) ($file['tmp_name'] ?? '');
                $uploaded = $tmpName !== '' ? file_get_contents($tmpName) : false;
                if (!is_string($uploaded)) {
                    throw new RuntimeException(__('blox_tpl_unreadable'));
                }
                $json = $uploaded;
            }
            if ($json === '') {
                throw new RuntimeException(__('blox_tpl_pick_or_paste'));
            }

            $prepared = BloxTemplateImporter::prepare($json);
            if (!BloxTemplateCatalog::supportsEditorType($prepared['type'])) {
                throw new RuntimeException(__('blox_template_type_not_ready'));
            }
            $result = BloxTemplateImporter::importJson($json, (int) ($_SESSION['admin_id'] ?? 0));
            adminLog(
                'blox_template',
                'import',
                '导入 Blox 模板 #' . $result['id'] . ' ' . $result['name']
            );
            redirect('/admin/blox_templates.php?imported=' . $result['id']);
        }

        if ($action === 'publish' || $action === 'unpublish') {
            $id = max(0, (int) post('id', 0));
            if ($action === 'publish') {
                bloxTemplateModel()->publishDraft($id);
                adminLog('blox_template', 'publish', '发布 Blox 模板 #' . $id);
            } else {
                bloxTemplateModel()->unpublish($id);
                adminLog('blox_template', 'unpublish', '取消发布 Blox 模板 #' . $id);
            }
            redirect('/admin/blox_templates.php?status=1');
        }

        if ($action === 'delete') {
            $id = max(0, (int) post('id', 0));
            $row = bloxTemplateModel()->find($id);
            if (!$row) {
                throw new RuntimeException(__('blox_tpl_not_found'));
            }
            if (!in_array((string) ($row['source'] ?? ''), ['user', 'import'], true)) {
                throw new RuntimeException(__('blox_tpl_provider_undeletable'));
            }
            bloxTemplateModel()->deleteById($id);
            adminLog('blox_template', 'delete', '删除 Blox 模板草稿 #' . $id);
            redirect('/admin/blox_templates.php?deleted=1');
        }

        throw new RuntimeException(__('blox_invalid_action'));
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

// Collect template providers after Builder plugin registration.
if ((string) get('action', '') === 'export') {
    if (!$tableReady) {
        error(__('blox_tpl_table_missing'));
    }
    $id = max(0, (int) get('id', 0));
    $template = bloxTemplateModel()->findForExport($id);
    if (!$template) {
        error(__('blox_tpl_not_found'));
    }
    try {
        $json = BloxTemplateImporter::exportJson($template);
        $filename = BloxTemplateImporter::exportFilename($template);
    } catch (Throwable $e) {
        error($e->getMessage());
    }
    adminLog('blox_template', 'export', '导出 Blox 模板 #' . $id);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}
BuilderRegistry::boot();
$providerTemplates = BloxPluginRegistry::templates('all');
$storedTemplates = $tableReady ? array_values(array_filter(
    bloxTemplateModel()->catalog(),
    static fn (array $template): bool => in_array((string) ($template['source'] ?? ''), ['user', 'import'], true)
)) : [];
$libraryBlocks = [];
if (db()->tableExists('blocks_library')) {
    $libraryBlocks = db()->fetchAll(
        'SELECT id,name,updated_at FROM ' . DB_PREFIX . 'blocks_library ORDER BY updated_at DESC, id DESC LIMIT 100'
    );
}

$typeLabels = [
    'section' => __('blox_template_type_section'),
    'page' => __('blox_template_type_page'),
    'header' => __('blox_tpl_type_header'),
    'footer' => __('blox_tpl_type_footer'),
];
$sourceLabels = [
    'user' => __('blox_tpl_source_user'),
    'import' => __('blox_tpl_source_import'),
    'builtin' => __('blox_tpl_source_builtin'),
    'plugin' => __('blox_template_source_plugin'),
];

$GLOBALS['pageTitle'] = __('admin_blox_templates');
$GLOBALS['currentMenu'] = 'blox_templates';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('admin_blox_templates')); ?></h1>
            <p class="mt-1 text-sm text-gray-500"><?php echo __('blox_tpl_page_intro'); ?></p>
        </div>
        <a href="/admin/blox_editor.php?home=1"
           class="inline-flex items-center gap-2 rounded bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
            <i class="ti ti-layout-dashboard"></i>
            <?php echo __('blox_tpl_home_badge'); ?>
        </a>
    </div>

    <?php if (!$tableReady): ?>
        <div class="border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <?php echo __('blox_tpl_table_missing_hint'); ?>
        </div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo e($notice); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo e($errorMessage); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-px overflow-hidden border border-gray-200 bg-gray-200 md:grid-cols-4">
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_source_user'); ?></div><div class="mt-1 text-xl font-semibold"><?php echo count($storedTemplates); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_builtin_plugin'); ?></div><div class="mt-1 text-xl font-semibold"><?php echo count($providerTemplates); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_reusable_blocks'); ?></div><div class="mt-1 text-xl font-semibold"><?php echo count($libraryBlocks); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_format'); ?></div><div class="mt-1 text-xl font-semibold">JSON v1</div></div>
    </div>

    <section class="border-y border-gray-200 bg-white">
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="font-semibold text-gray-900"><?php echo __('blox_tpl_import_title'); ?></h2>
        </div>
        <form method="post" enctype="multipart/form-data" class="grid gap-4 p-5 lg:grid-cols-[minmax(0,320px)_1fr_auto] lg:items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="import">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700"><?php echo __('blox_tpl_json_file'); ?></label>
                <input type="file" name="template_file" accept=".json,application/json"
                       class="block w-full border border-gray-300 bg-white px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700"><?php echo __('blox_tpl_paste_json'); ?></label>
                <textarea name="template_json" rows="3"
                          class="block w-full border border-gray-300 px-3 py-2 font-mono text-xs"
                          placeholder='{"format":"yikaicms-blox-template","version":1,...}'></textarea>
            </div>
            <button type="submit" <?php echo $tableReady ? '' : 'disabled'; ?>
                    class="inline-flex h-10 items-center justify-center gap-2 bg-blue-600 px-5 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                <i class="ti ti-file-import"></i>
                <?php echo __('blox_tpl_import_hint'); ?>
            </button>
        </form>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="px-5 py-4 border-b border-gray-200"><h2 class="font-semibold text-gray-900"><?php echo __('blox_tpl_source_user'); ?></h2></div>
        <?php if ($storedTemplates === []): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400"><?php echo __('blox_tpl_none'); ?></div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500">
                        <tr><th class="px-5 py-3"><?php echo __('blox_tpl_col_name'); ?></th><th class="px-4 py-3"><?php echo __('blox_tpl_col_type'); ?></th><th class="px-4 py-3"><?php echo __('blox_tpl_col_source'); ?></th><th class="px-4 py-3"><?php echo __('blox_tpl_col_status'); ?></th><th class="px-4 py-3"><?php echo __('blox_tpl_col_updated'); ?></th><th class="px-5 py-3 text-right"><?php echo __('blox_tpl_col_actions'); ?></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($storedTemplates as $template): ?>
                        <tr>
                            <td class="px-5 py-3 font-medium text-gray-900"><?php echo e((string) $template['name']); ?></td>
                            <td class="px-4 py-3"><?php echo e($typeLabels[(string) $template['type']] ?? (string) $template['type']); ?></td>
                            <td class="px-4 py-3 text-gray-500"><?php echo e($sourceLabels[(string) $template['source']] ?? (string) $template['source']); ?></td>
                            <td class="px-4 py-3"><?php echo (int) $template['status'] === 1 ? __('blox_tpl_published') : __('blox_tpl_draft'); ?></td>
                            <td class="px-4 py-3 text-gray-500"><?php echo date('Y-m-d H:i', (int) $template['updated_at']); ?></td>
                            <td class="px-5 py-3 text-right">
                                <a href="/admin/blox_templates.php?action=export&amp;id=<?php echo (int) $template['id']; ?>"
                                   class="mr-3 text-gray-600 hover:text-gray-900" title="<?php echo e(__('blox_tpl_export_json')); ?>">
                                    <i class="ti ti-download"></i>
                                </a>
                                <form method="post" class="mr-3 inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="<?php echo (int) $template['status'] === 1 ? 'unpublish' : 'publish'; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <button type="submit" class="text-blue-600 hover:text-blue-800" title="<?php echo (int) $template['status'] === 1 ? __('blox_tpl_unpublish') : __('blox_tpl_publish_draft'); ?>">
                                        <i class="ti <?php echo (int) $template['status'] === 1 ? 'ti-player-pause' : 'ti-send'; ?>"></i>
                                    </button>
                                </form>
                                <form method="post" class="inline" onsubmit="return confirm('<?php echo e(__('blox_tpl_delete_confirm')); ?>');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800" title="<?php echo e(__('delete')); ?>"><i class="ti ti-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="px-5 py-4 border-b border-gray-200"><h2 class="font-semibold text-gray-900"><?php echo __('blox_tpl_provider_title'); ?></h2></div>
        <?php if ($providerTemplates === []): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400"><?php echo __('blox_tpl_no_providers'); ?></div>
        <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($providerTemplates as $template): ?>
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div><div class="font-medium text-gray-900"><?php echo e((string) ($template['name'] ?? __('blox_tpl_unnamed'))); ?></div><div class="mt-1 text-xs text-gray-500"><?php echo e((string) ($template['plugin'] ?? 'builtin')); ?></div></div>
                        <span class="bg-gray-100 px-2 py-1 text-xs text-gray-600"><?php echo e($typeLabels[(string) ($template['type'] ?? '')] ?? (string) ($template['type'] ?? '')); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="font-semibold text-gray-900"><?php echo __('blox_tpl_reusable_blocks'); ?></h2>
            <a href="/admin/page_edit_advance.php?home=1" class="text-sm text-blue-600 hover:text-blue-800"><?php echo __('blox_tpl_layout_editor'); ?></a>
        </div>
        <?php if ($libraryBlocks === []): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400"><?php echo __('blox_tpl_no_reusable'); ?></div>
        <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($libraryBlocks as $block): ?>
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="font-medium text-gray-900"><?php echo e((string) $block['name']); ?></span>
                        <span class="text-xs text-gray-400">#<?php echo (int) $block['id']; ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
