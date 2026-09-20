<?php
/** Manage article detail layouts without editing article records. */
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requireBloxTemplateTypePermission('article-detail');
require_once ROOT_PATH . '/includes/builder/BloxTemplateEditPolicy.php';
if (!bloxPageEditorEnabled() || !BloxTemplateEditPolicy::allows('article-detail', bloxAdvancedFeaturesEnabled())) error(__('blox_feature_disabled'));
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$message = '';
$success = (string) ($_SESSION['article_design_success'] ?? '');
unset($_SESSION['article_design_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    try {
        if (post('action') === 'create') {
            $language = (string) post('language', '');
            if (!isset(availableLanguages()[$language])) throw new RuntimeException(__('blox_bad_request'));
            // v1.26 Single 扩自定义模型：创建时定类型（article / 已注册模型 key）。
            // 注册核对走统一 IO 点；未注册/缺失回落 article，不报错（表单只列合法项）。
            $contentType = DetailTemplateProvider::contentTypeForTemplate(
                'article-detail',
                (string) post('content_type', 'article')
            );
            $document = ArticleTemplateDocument::seed($language, $contentType);
            $id = bloxTemplateModel()->createDraft('article-detail', (string) post('name', ''), $document,
                'user', 1, BloxTemplateImporter::deriveRequirements(BloxDocumentPipeline::decode($document)['sections']),
                '', (int) ($_SESSION['admin_id'] ?? 0));
            adminLog('blox_template', 'create', 'Article detail draft #' . $id);
            header('Location: /admin/blox_editor.php?template=' . $id . '&article_lang=' . rawurlencode($language));
            exit;
        }
        $action = (string) post('action');
        if (!in_array($action, ['unpublish', 'source'], true)) throw new RuntimeException(__('blox_invalid_action'));
        $id = (int) post('id', '0');
        $row = bloxTemplateModel()->findForExport($id);
        if (($row['type'] ?? '') !== 'article-detail') throw new RuntimeException(__('blox_tpl_not_found'));
        if ($action === 'source') {
            $source = (string) post('source', '');
            bloxTemplateModel()->switchDetailSource($id, 'article-detail', $source, (string) post('revision', ''));
            $_SESSION['article_design_success'] = __($source === 'native' ? 'blox_article_restored_native' : 'blox_article_restored_custom');
        } else {
            bloxTemplateModel()->unpublish($id);
            $_SESSION['article_design_success'] = __('blox_article_rule_removed');
        }
        require_once ROOT_PATH . '/includes/HtmlCache.php';
        HtmlCache::invalidate();
        adminLog('blox_template', $action, 'Article detail template #' . $id);
        header('Location: /admin/article_design.php');
        exit;
    } catch (RuntimeException|InvalidArgumentException $e) {
        $message = $e->getMessage();
    }
}
$templates = bloxTemplateModel()->catalog('article-detail');
$published = array_column(bloxTemplateModel()->publishedDetailTemplates('article-detail'), null, 'id');
$languages = availableLanguages();
$previewArticles = [];
foreach (array_keys($languages) as $code) {
    $previewArticles[(string) $code] = array_values(array_filter(
        contentModel()->getList(0, 100, 0, ['lang' => (string) $code, 'type' => 'article']),
        static fn(array $row): bool => ($row['lang'] ?? '') === $code && ($row['type'] ?? '') === 'article'
    ));
}
$pageTitle = __('blox_tpl_type_article-detail');
require ROOT_PATH . '/admin/includes/header.php';
?>
<div class="space-y-6">
    <a href="/admin/site_design.php" class="text-sm text-gray-700"><i class="ti ti-arrow-left" aria-hidden="true"></i> <?= e(__('site_design_title')) ?></a>
    <h1 class="text-xl font-semibold text-gray-900"><?= e($pageTitle) ?></h1>
    <?php if ($message !== ''): ?><p role="alert" class="text-red-700"><?= e($message) ?></p><?php endif; ?>
    <?php if ($success !== ''): ?><p role="status" class="text-green-800"><?= e($success) ?></p><?php endif; ?>
    <section class="border-y border-gray-200 py-4 space-y-3" aria-labelledby="article-native-title">
        <h2 id="article-native-title" class="font-semibold text-gray-900"><?= e(__('blox_article_source_native')) ?></h2>
        <form action="/admin/article_native_preview.php" method="get" target="_blank" rel="noopener" class="flex flex-wrap items-end gap-3">
            <label class="min-w-0 w-full sm:w-auto sm:flex-1"><span class="block mb-1 text-sm"><?= e(__('blox_article_preview')) ?></span>
                <select name="id" required class="w-full rounded border border-gray-300 px-3 py-2" data-testid="article-native-record">
                    <option value=""><?= e(__('blox_article_choose')) ?></option>
                    <?php foreach ($previewArticles as $code => $articles): ?>
                    <?php if ($articles !== []): ?><optgroup label="<?= e((string) $languages[$code]) ?>">
                        <?php foreach ($articles as $article): ?><option value="<?= (int) $article['id'] ?>"><?= e((string) $article['title']) ?></option><?php endforeach; ?>
                    </optgroup><?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="rounded border border-gray-300 px-4 py-2 text-gray-900" data-testid="article-native-open"><i class="ti ti-external-link" aria-hidden="true"></i> <?= e(__('blox_article_preview_native')) ?></button>
        </form>
        <?php if (!array_filter($previewArticles)): ?><p class="text-sm text-gray-600"><?= e(__('blox_article_preview_empty')) ?></p><?php endif; ?>
    </section>
    <form method="post" class="flex flex-wrap items-end gap-3 border-y border-gray-200 py-4">
        <?= csrfField() ?><input type="hidden" name="action" value="create">
        <label class="min-w-0 w-full sm:w-auto sm:flex-1"><span class="block mb-1 text-sm"><?= e(__('blox_article_name')) ?></span><input name="name" required maxlength="150" class="w-full rounded border border-gray-300 px-3 py-2" data-testid="article-design-name"></label>
        <label><span class="block mb-1 text-sm"><?= e(__('blox_article_language')) ?></span><select name="language" class="rounded border border-gray-300 px-3 py-2">
            <?php foreach (availableLanguages() as $code => $label): ?><option value="<?= e((string) $code) ?>" <?= $code === config('site_lang', 'zh-CN') ? 'selected' : '' ?>><?= e((string) $label) ?></option><?php endforeach; ?>
        </select></label>
        <?php
        // v1.26：有注册模型时提供类型选择（模板条件的 content_type 维度，见 DetailTemplateResolver）
        $articleTemplateModels = [];
        try {
            foreach (contentModelModel()->allActive() as $modelRow) {
                $modelKey = (string) ($modelRow['model_key'] ?? '');
                if ($modelKey !== '') {
                    $articleTemplateModels[$modelKey] = (string) ($modelRow['name'] ?? $modelKey);
                }
            }
        } catch (Throwable) {
            // 表未建：只有文章一种
        }
        ?>
        <?php if ($articleTemplateModels !== []): ?>
        <label><span class="block mb-1 text-sm"><?= e(__('blox_article_content_type')) ?></span><select name="content_type" class="rounded border border-gray-300 px-3 py-2" data-testid="article-design-content-type">
            <option value="article"><?= e(__('blox_article_type_article')) ?></option>
            <?php foreach ($articleTemplateModels as $modelKey => $modelName): ?><option value="<?= e($modelKey) ?>"><?= e($modelName) ?></option><?php endforeach; ?>
        </select></label>
        <?php endif; ?>
        <button class="rounded bg-gray-900 text-white px-4 py-2" data-testid="article-design-create"><i class="ti ti-plus" aria-hidden="true"></i> <?= e(__('blox_article_create')) ?></button>
    </form>
    <div class="divide-y divide-gray-200">
        <?php
        // TASK-005-R01：栏目名称映射整页只查一次（懒加载），不放进模板循环
        $channelNameMap = null;
        $loadChannelNames = static function () use (&$channelNameMap): array {
            if ($channelNameMap === null) {
                $channelNameMap = [];
                foreach (channelModel()->all() as $channelRow) {
                    $channelNameMap[(int) ($channelRow['id'] ?? 0)] = (string) ($channelRow['name'] ?? '');
                }
            }
            return $channelNameMap;
        };
        ?>
        <?php foreach ($templates as $template): ?>
        <?php
        $live = $published[(int) $template['id']] ?? null;
        $scope = null;
        if ($live !== null) {
            try {
                $scope = DetailTemplateResolver::normalizeScope(
                    BloxDocumentPipeline::decode((string) $live['published_data'])['settings']['detail_template'] ?? null
                );
            } catch (Throwable) {
                // 文档损坏时仍保留编辑器与撤下入口
            }
        }
        $scopeText = '';
        $scopeCategoryText = '';
        if ($scope !== null) {
            // TASK-005 B：不再只看 include[0]（混合 all/item/category 会被误标成"暂不应用"）
            $includeRules = is_array($scope['include'] ?? null) ? $scope['include'] : [];
            $hasAll = false;
            $itemCount = 0;
            $categoryRuleCount = 0;
            foreach ($includeRules as $includeRule) {
                $ruleKind = (string) ($includeRule['kind'] ?? '');
                if ($ruleKind === 'all') {
                    $hasAll = true;
                } elseif ($ruleKind === 'item') {
                    $itemCount += count(is_array($includeRule['ids'] ?? null) ? $includeRule['ids'] : []);
                } elseif ($ruleKind === 'category') {
                    $categoryRuleCount++;
                }
            }
            if ($includeRules === []) {
                $scopeText = __('blox_article_scope_none');
            } elseif ($hasAll) {
                $scopeText = __('blox_article_scope_all');
            } elseif ($itemCount > 0) {
                $scopeText = __('blox_article_scope_count', ['count' => $itemCount]);
            } elseif ($categoryRuleCount > 0) {
                $scopeText = __('blox_article_scope_category', ['count' => $categoryRuleCount]);
            } else {
                $scopeText = __('blox_article_scope_none');
            }

            // 栏目条件逐条呈现（含子级），纳入与排除分开；只描述存储规则
            if (DetailScopeSummary::hasCategory($scope)) {
                $channelRules = DetailScopeSummary::categoryRules($scope);
                $channelNames = $loadChannelNames();
                $channelParts = [];
                foreach ([['include', 'blox_scope_channel_include'], ['exclude', 'blox_scope_channel_exclude']] as $sidePair) {
                    foreach (DetailScopeSummary::labelled($channelRules[$sidePair[0]], $channelNames) as $channelRule) {
                        $channelParts[] = __($sidePair[1], ['names' => implode(', ', $channelRule['labels'])])
                            . ($channelRule['include_children'] ? __('blox_scope_children') : '');
                    }
                }
                $scopeCategoryText = implode('; ', $channelParts);
            }
        }
        ?>
        <div class="flex flex-wrap items-center justify-between gap-3 py-4" data-testid="article-design-row-<?= (int) $template['id'] ?>">
            <div class="min-w-0"><strong class="break-words"><?= e((string) $template['name']) ?></strong><span class="ml-3 text-sm text-gray-600"><?= e(__((int) $template['status'] === 1 ? 'blox_article_status_published' : 'blox_article_status_draft')) ?></span>
                <?php if ($scope !== null): ?>
                <p class="mt-1 text-sm text-gray-600"><?= e(__('blox_article_scope')) ?>: <?= e((string) ($languages[$scope['lang']] ?? $scope['lang'])) ?> · <?= e($scopeText) ?></p>
                <?php if (($scopeCategoryText ?? '') !== ''): ?>
                <p class="mt-1 text-sm text-gray-600" data-testid="article-design-category-rules"><?= e($scopeCategoryText) ?></p>
                <?php endif; ?>
                <p class="mt-1 text-sm text-gray-900" data-testid="article-design-source"><?= e(__('blox_article_rule_output')) ?>: <?= e(__(($scope['source'] ?? '') === 'native' ? 'blox_article_source_native' : 'blox_article_source_custom')) ?></p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap items-center gap-4">
                <?php if ($scope !== null): ?>
                <form method="post" class="flex flex-wrap items-center gap-2">
                    <?= csrfField() ?><input type="hidden" name="action" value="source"><input type="hidden" name="id" value="<?= (int) $template['id'] ?>"><input type="hidden" name="revision" value="<?= e(hash('sha256', (string) $live['published_data'])) ?>">
                    <select name="source" aria-label="<?= e(__('blox_article_rule_output')) ?>" class="max-w-full rounded border border-gray-300 px-3 py-2" data-testid="article-design-source-select">
                        <option value="custom" <?= ($scope['source'] ?? '') !== 'native' ? 'selected' : '' ?>><?= e(__('blox_article_source_custom')) ?></option>
                        <option value="native" <?= ($scope['source'] ?? '') === 'native' ? 'selected' : '' ?>><?= e(__('blox_article_source_native')) ?></option>
                    </select>
                    <button class="rounded border border-gray-300 px-3 py-2 text-gray-900" data-testid="article-design-source-apply"><i class="ti ti-check" aria-hidden="true"></i> <?= e(__('blox_article_apply_source')) ?></button>
                </form>
                <?php endif; ?>
                <a class="text-primary" href="/admin/blox_editor.php?template=<?= (int) $template['id'] ?>"><i class="ti ti-edit" aria-hidden="true"></i> <?= e(__('site_design_open')) ?></a>
                <?php if ((int) $template['status'] === 1): ?>
                <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="unpublish"><input type="hidden" name="id" value="<?= (int) $template['id'] ?>"><button class="text-gray-700" data-testid="article-design-unpublish"><i class="ti ti-player-pause" aria-hidden="true"></i> <?= e(__('blox_article_remove_rule')) ?></button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($templates === []): ?><p class="py-4 text-gray-600"><?= e(__('blox_article_none')) ?></p><?php endif; ?>
    </div>
    <?php if ($published !== []): ?><p class="text-sm text-gray-600"><?= e(__('blox_article_rule_priority')) ?></p><?php endif; ?>
</div>
<?php require ROOT_PATH . '/admin/includes/footer.php'; ?>
