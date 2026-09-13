<?php
/** Manage product detail layouts without editing product records. */
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requireBloxTemplateTypePermission('product-detail');
if (!bloxPageEditorEnabled() || !bloxAdvancedFeaturesEnabled()) error(__('blox_feature_disabled'));
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$message = '';
$success = (string) ($_SESSION['product_design_success'] ?? '');
unset($_SESSION['product_design_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (post('action') === 'create') {
            $language = (string) post('language', '');
            if (!isset(availableLanguages()[$language])) throw new RuntimeException(__('blox_bad_request'));
            $document = ProductTemplateDocument::seed($language);
            $id = bloxTemplateModel()->createDraft('product-detail', (string) post('name', ''), $document,
                'user', 1, BloxTemplateImporter::deriveRequirements(BloxDocumentPipeline::decode($document)['sections']),
                '', (int) ($_SESSION['admin_id'] ?? 0));
            adminLog('blox_template', 'create', 'Product detail draft #' . $id);
            header('Location: /admin/blox_editor.php?template=' . $id . '&product_lang=' . rawurlencode($language));
            exit;
        }
        $action = (string) post('action');
        if (!in_array($action, ['unpublish', 'source'], true)) throw new RuntimeException(__('blox_invalid_action'));
        $id = (int) post('id', '0');
        $row = bloxTemplateModel()->findForExport($id);
        if (($row['type'] ?? '') !== 'product-detail') throw new RuntimeException(__('blox_tpl_not_found'));
        if ($action === 'source') {
            $source = (string) post('source', '');
            bloxTemplateModel()->switchProductSource($id, $source, (string) post('revision', ''));
            $_SESSION['product_design_success'] = __($source === 'native' ? 'blox_product_restored_native' : 'blox_product_restored_custom');
        } else {
            bloxTemplateModel()->unpublish($id);
            $_SESSION['product_design_success'] = __('blox_product_rule_removed');
        }
        require_once ROOT_PATH . '/includes/HtmlCache.php';
        HtmlCache::invalidate();
        adminLog('blox_template', $action, 'Product detail template #' . $id);
        header('Location: /admin/product_design.php');
        exit;
    } catch (RuntimeException|InvalidArgumentException $e) {
        $message = $e->getMessage();
    }
}
$templates = bloxTemplateModel()->catalog('product-detail');
$published = array_column(bloxTemplateModel()->publishedProductTemplates(), null, 'id');
$languages = availableLanguages();
$previewProducts = [];
foreach (array_keys($languages) as $code) {
    $previewProducts[(string) $code] = array_values(array_filter(
        productModel()->getList(0, 100, 0, ['lang' => (string) $code]),
        static fn(array $product): bool => ($product['lang'] ?? '') === $code
    ));
}
$pageTitle = __('blox_tpl_type_product-detail');
require ROOT_PATH . '/admin/includes/header.php';
?>
<div class="space-y-6">
    <a href="/admin/site_design.php" class="text-sm text-gray-700"><i class="ti ti-arrow-left" aria-hidden="true"></i> <?= e(__('site_design_title')) ?></a>
    <h1 class="text-xl font-semibold text-gray-900"><?= e($pageTitle) ?></h1>
    <?php if ($message !== ''): ?><p role="alert" class="text-red-700"><?= e($message) ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p role="status" class="text-green-800"><?= e($success) ?></p><?php endif; ?>
    <section class="border-y border-gray-200 py-4 space-y-3" aria-labelledby="product-native-title">
        <h2 id="product-native-title" class="font-semibold text-gray-900"><?= e(__('blox_product_source_native')) ?></h2>
        <form action="/admin/product_native_preview.php" method="get" target="_blank" rel="noopener" class="flex flex-wrap items-end gap-3">
            <label class="min-w-0 w-full sm:w-auto sm:flex-1"><span class="block mb-1 text-sm"><?= e(__('blox_product_preview')) ?></span>
                <select name="id" required class="w-full rounded border border-gray-300 px-3 py-2" data-testid="product-native-record">
                    <option value=""><?= e(__('blox_product_choose')) ?></option>
                    <?php foreach ($previewProducts as $code => $products): ?>
                    <?php if ($products !== []): ?><optgroup label="<?= e((string) $languages[$code]) ?>">
                        <?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= e((string) $product['title']) ?></option><?php endforeach; ?>
                    </optgroup><?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="rounded border border-gray-300 px-4 py-2 text-gray-900" data-testid="product-native-open"><i class="ti ti-external-link" aria-hidden="true"></i> <?= e(__('blox_product_preview_native')) ?></button>
        </form>
        <?php if (!array_filter($previewProducts)): ?><p class="text-sm text-gray-600"><?= e(__('blox_product_preview_empty')) ?></p><?php endif; ?>
    </section>
    <form method="post" class="flex flex-wrap items-end gap-3 border-y border-gray-200 py-4">
        <?= csrfField() ?><input type="hidden" name="action" value="create">
        <label class="min-w-0 w-full sm:w-auto sm:flex-1"><span class="block mb-1 text-sm"><?= e(__('blox_product_name')) ?></span><input name="name" required maxlength="150" class="w-full rounded border border-gray-300 px-3 py-2" data-testid="product-design-name"></label>
        <label><span class="block mb-1 text-sm"><?= e(__('blox_product_language')) ?></span><select name="language" class="rounded border border-gray-300 px-3 py-2">
            <?php foreach (availableLanguages() as $code => $label): ?><option value="<?= e((string) $code) ?>" <?= $code === config('site_lang', 'zh-CN') ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?>
        </select></label>
        <button class="rounded bg-gray-900 text-white px-4 py-2" data-testid="product-design-create"><i class="ti ti-plus" aria-hidden="true"></i> <?= e(__('blox_product_create')) ?></button>
    </form>
    <div class="divide-y divide-gray-200">
        <?php foreach ($templates as $template): ?>
        <?php
        $live = $published[(int) $template['id']] ?? null;
        $scope = null;
        if ($live !== null) {
            try {
                // TASK-002-R02：展示必须与渲染同源——v2 存在时以 v2 为准
                $scope = ProductTemplateDocument::authoritativeScope(BloxDocumentPipeline::decode((string) $live['published_data']));
            } catch (Throwable) {
                // Keep the editor and deactivate action reachable for a damaged document.
            }
        }
        ?>
        <div class="flex flex-wrap items-center justify-between gap-3 py-4" data-testid="product-design-row-<?= (int) $template['id'] ?>">
            <div class="min-w-0"><strong class="break-words"><?= e((string) $template['name']) ?></strong><span class="ml-3 text-sm text-gray-600"><?= e(__((int) $template['status'] === 1 ? 'blox_product_published' : 'blox_product_draft')) ?></span>
                <?php if ($scope !== null): ?>
                <p class="mt-1 text-sm text-gray-600"><?= e(__('blox_product_scope')) ?>: <?= e((string) ($languages[$scope['lang']] ?? $scope['lang'])) ?> · <?= e($scope['mode'] === 'all' ? __('blox_product_all') : __('blox_product_scope_count', ['count' => count($scope['ids'])])) ?></p>
                <p class="mt-1 text-sm text-gray-900" data-testid="product-design-source"><?= e(__('blox_product_rule_output')) ?>: <?= e(__(($scope['source'] ?? '') === 'native' ? 'blox_product_source_native' : 'blox_product_source_custom')) ?></p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <?php if ($scope !== null): ?>
                <form method="post" class="flex flex-wrap items-center gap-2">
                    <?= csrfField() ?><input type="hidden" name="action" value="source"><input type="hidden" name="id" value="<?= (int) $template['id'] ?>"><input type="hidden" name="revision" value="<?= e(hash('sha256', (string) $live['published_data'])) ?>">
                    <select name="source" aria-label="<?= e(__('blox_product_rule_output')) ?>" class="max-w-full rounded border border-gray-300 px-3 py-2" data-testid="product-design-source-select">
                        <option value="custom" <?= ($scope['source'] ?? '') !== 'native' ? 'selected' : '' ?>><?= e(__('blox_product_source_custom')) ?></option>
                        <option value="native" <?= ($scope['source'] ?? '') === 'native' ? 'selected' : '' ?>><?= e(__('blox_product_source_native')) ?></option>
                    </select>
                    <button class="rounded border border-gray-300 px-3 py-2 text-gray-900" data-testid="product-design-source-apply"><i class="ti ti-check" aria-hidden="true"></i> <?= e(__('blox_product_apply_source')) ?></button>
                </form>
                <?php endif; ?>
                <a class="text-primary" href="/admin/blox_editor.php?template=<?= (int) $template['id'] ?>"><i class="ti ti-edit" aria-hidden="true"></i> <?= e(__('site_design_open')) ?></a>
                <?php if ((int) $template['status'] === 1): ?>
                <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="id" value="<?= (int) $template['id'] ?>"><button class="text-gray-700" data-testid="product-design-unpublish"><i class="ti ti-player-pause" aria-hidden="true"></i> <?= e(__('blox_product_remove_rule')) ?></button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($templates === []): ?><p class="py-4 text-gray-600"><?= e(__('blox_product_native')) ?></p><?php endif; ?>
    </div>
    <?php if ($published !== []): ?><p class="text-sm text-gray-600"><?= e(__('blox_product_rule_priority')) ?></p><?php endif; ?>
</div>
<?php require ROOT_PATH . '/admin/includes/footer.php'; ?>
