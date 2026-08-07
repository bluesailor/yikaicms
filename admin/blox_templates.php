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
    error('功能未启用');
}
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$tableReady = db()->tableExists('blox_templates');
$errorMessage = '';
$notice = '';

if (isset($_GET['imported'])) {
    $notice = '模板已导入为草稿，编号 #' . max(0, (int) $_GET['imported']);
}
if (isset($_GET['deleted'])) {
    $notice = '模板草稿已删除';
}
if (isset($_GET['status'])) {
    $notice = '模板发布状态已更新';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post('action', '');
    try {
        if (!$tableReady) {
            throw new RuntimeException('模板表尚未创建，请先运行数据库升级');
        }

        if ($action === 'import') {
            $json = trim((string) post('template_json', ''));
            $file = $_FILES['template_file'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('模板文件上传失败');
                }
                if ((int) ($file['size'] ?? 0) > BloxTemplateImporter::MAX_BYTES) {
                    throw new RuntimeException('模板文件不能超过 2MB');
                }
                $originalName = (string) ($file['name'] ?? '');
                if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'json') {
                    throw new RuntimeException('只允许上传 JSON 模板文件');
                }
                $tmpName = (string) ($file['tmp_name'] ?? '');
                $uploaded = $tmpName !== '' ? file_get_contents($tmpName) : false;
                if (!is_string($uploaded)) {
                    throw new RuntimeException('无法读取上传的模板文件');
                }
                $json = $uploaded;
            }
            if ($json === '') {
                throw new RuntimeException('请选择模板文件或粘贴模板 JSON');
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
                throw new RuntimeException('模板不存在');
            }
            if (!in_array((string) ($row['source'] ?? ''), ['user', 'import'], true)) {
                throw new RuntimeException('内置或插件模板不能在这里删除');
            }
            bloxTemplateModel()->deleteById($id);
            adminLog('blox_template', 'delete', '删除 Blox 模板草稿 #' . $id);
            redirect('/admin/blox_templates.php?deleted=1');
        }

        throw new RuntimeException('无效操作');
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

// Collect template providers after Builder plugin registration.
if ((string) get('action', '') === 'export') {
    if (!$tableReady) {
        error('模板表尚未创建，请先运行数据库升级');
    }
    $id = max(0, (int) get('id', 0));
    $template = bloxTemplateModel()->findForExport($id);
    if (!$template) {
        error('模板不存在');
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
    'section' => '区块',
    'page' => '整页',
    'header' => '网页头',
    'footer' => '网页尾',
];
$sourceLabels = [
    'user' => '我的模板',
    'import' => '导入',
    'builtin' => '内置',
    'plugin' => '插件',
];

$GLOBALS['pageTitle'] = __('admin_blox_templates');
$GLOBALS['currentMenu'] = 'blox_templates';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('admin_blox_templates')); ?></h1>
            <p class="mt-1 text-sm text-gray-500">当前支持区块和整页模板；网页头、网页尾将在渲染契约完成后开放</p>
        </div>
        <a href="/admin/blox_editor.php?home=1"
           class="inline-flex items-center gap-2 rounded bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">
            <i class="ti ti-layout-dashboard"></i>
            首页 Blox
        </a>
    </div>

    <?php if (!$tableReady): ?>
        <div class="border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            模板表尚未创建。请先到“系统 → 升级管理”执行数据库升级。
        </div>
    <?php endif; ?>
    <?php if ($notice !== ''): ?>
        <div class="border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800"><?php echo e($notice); ?></div>
    <?php endif; ?>
    <?php if ($errorMessage !== ''): ?>
        <div class="border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800"><?php echo e($errorMessage); ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-px overflow-hidden border border-gray-200 bg-gray-200 md:grid-cols-4">
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500">我的模板</div><div class="mt-1 text-xl font-semibold"><?php echo count($storedTemplates); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500">内置 / 插件</div><div class="mt-1 text-xl font-semibold"><?php echo count($providerTemplates); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500">可复用区块</div><div class="mt-1 text-xl font-semibold"><?php echo count($libraryBlocks); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500">模板格式</div><div class="mt-1 text-xl font-semibold">JSON v1</div></div>
    </div>

    <section class="border-y border-gray-200 bg-white">
        <div class="px-5 py-4 border-b border-gray-200">
            <h2 class="font-semibold text-gray-900">导入模板</h2>
        </div>
        <form method="post" enctype="multipart/form-data" class="grid gap-4 p-5 lg:grid-cols-[minmax(0,320px)_1fr_auto] lg:items-end">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="import">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">JSON 文件</label>
                <input type="file" name="template_file" accept=".json,application/json"
                       class="block w-full border border-gray-300 bg-white px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">或粘贴 JSON</label>
                <textarea name="template_json" rows="3"
                          class="block w-full border border-gray-300 px-3 py-2 font-mono text-xs"
                          placeholder='{"format":"yikaicms-blox-template","version":1,...}'></textarea>
            </div>
            <button type="submit" <?php echo $tableReady ? '' : 'disabled'; ?>
                    class="inline-flex h-10 items-center justify-center gap-2 bg-blue-600 px-5 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300">
                <i class="ti ti-file-import"></i>
                导入区块/整页草稿
            </button>
        </form>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="px-5 py-4 border-b border-gray-200"><h2 class="font-semibold text-gray-900">我的模板</h2></div>
        <?php if ($storedTemplates === []): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400">暂无模板草稿</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-xs text-gray-500">
                        <tr><th class="px-5 py-3">名称</th><th class="px-4 py-3">类型</th><th class="px-4 py-3">来源</th><th class="px-4 py-3">状态</th><th class="px-4 py-3">更新时间</th><th class="px-5 py-3 text-right">操作</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($storedTemplates as $template): ?>
                        <tr>
                            <td class="px-5 py-3 font-medium text-gray-900"><?php echo e((string) $template['name']); ?></td>
                            <td class="px-4 py-3"><?php echo e($typeLabels[(string) $template['type']] ?? (string) $template['type']); ?></td>
                            <td class="px-4 py-3 text-gray-500"><?php echo e($sourceLabels[(string) $template['source']] ?? (string) $template['source']); ?></td>
                            <td class="px-4 py-3"><?php echo (int) $template['status'] === 1 ? '已发布' : '草稿'; ?></td>
                            <td class="px-4 py-3 text-gray-500"><?php echo date('Y-m-d H:i', (int) $template['updated_at']); ?></td>
                            <td class="px-5 py-3 text-right">
                                <a href="/admin/blox_templates.php?action=export&amp;id=<?php echo (int) $template['id']; ?>"
                                   class="mr-3 text-gray-600 hover:text-gray-900" title="导出 JSON">
                                    <i class="ti ti-download"></i>
                                </a>
                                <form method="post" class="mr-3 inline">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="<?php echo (int) $template['status'] === 1 ? 'unpublish' : 'publish'; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <button type="submit" class="text-blue-600 hover:text-blue-800" title="<?php echo (int) $template['status'] === 1 ? '取消发布' : '发布草稿'; ?>">
                                        <i class="ti <?php echo (int) $template['status'] === 1 ? 'ti-player-pause' : 'ti-send'; ?>"></i>
                                    </button>
                                </form>
                                <form method="post" class="inline" onsubmit="return confirm('确定删除这个模板草稿？');">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800" title="删除"><i class="ti ti-trash"></i></button>
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
        <div class="px-5 py-4 border-b border-gray-200"><h2 class="font-semibold text-gray-900">内置与插件模板</h2></div>
        <?php if ($providerTemplates === []): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400">当前没有启用的模板提供者</div>
        <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($providerTemplates as $template): ?>
                    <div class="flex items-center justify-between gap-4 px-5 py-3">
                        <div><div class="font-medium text-gray-900"><?php echo e((string) ($template['name'] ?? '未命名模板')); ?></div><div class="mt-1 text-xs text-gray-500"><?php echo e((string) ($template['plugin'] ?? 'builtin')); ?></div></div>
                        <span class="bg-gray-100 px-2 py-1 text-xs text-gray-600"><?php echo e($typeLabels[(string) ($template['type'] ?? '')] ?? (string) ($template['type'] ?? '')); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="border-y border-gray-200 bg-white">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="font-semibold text-gray-900">可复用区块</h2>
            <a href="/admin/page_edit_advance.php?home=1" class="text-sm text-blue-600 hover:text-blue-800">排版编辑器</a>
        </div>
        <?php if ($libraryBlocks === []): ?>
            <div class="px-5 py-10 text-center text-sm text-gray-400">暂无可复用区块</div>
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
