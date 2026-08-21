<?php
/** Blox 模板库：受控 JSON 导入、来源查看和本地草稿管理。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

if (!bloxAdvancedFeaturesEnabled()) {
    error(__('blox_feature_disabled'));
}
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$tableReady = db()->tableExists('blox_templates');
$errorMessage = '';
$notice = '';
$filterType = strtolower(trim((string) get('type', 'all')));
if ($filterType !== 'all' && !BloxTemplateModel::validType($filterType)) {
    $filterType = 'all';
}

if (isset($_GET['imported'])) {
    $notice = __('blox_tpl_imported_msg') . ' #' . max(0, (int) $_GET['imported']);
}
if (isset($_GET['deleted'])) {
    $notice = __('blox_tpl_deleted_msg');
}
if (isset($_GET['status'])) {
    $notice = __('blox_tpl_status_updated_msg');
}
if (isset($_GET['area_enabled'])) {
    $noticeArea = (string) get('area', 'header') === 'footer' ? 'footer' : 'header';
    $noticeEnabled = (string) get('area_enabled', '0') === '1';
    $notice = __(sprintf('blox_custom_%s_%s_notice', $noticeArea, $noticeEnabled ? 'enabled' : 'disabled'));
} elseif (isset($_GET['header_enabled'])) {
    // 兼容上一轮生成的 Header 重定向链接。
    $notice = (string) get('header_enabled', '0') === '1'
        ? __('blox_custom_header_enabled_notice')
        : __('blox_custom_header_disabled_notice');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) post('action', '');
    try {
        if ($action === 'set_custom_area_enabled' || $action === 'set_custom_header_enabled') {
            $area = $action === 'set_custom_header_enabled' ? 'header' : strtolower(trim((string) post('area', '')));
            $settingKeys = [
                'header' => 'blox_custom_header_enabled',
                'footer' => 'blox_custom_footer_enabled',
            ];
            if (!isset($settingKeys[$area])) {
                throw new RuntimeException(__('blox_invalid_action'));
            }
            $enabled = (string) post('enabled', '0') === '1' ? '1' : '0';
            settingModel()->saveBatch([$settingKeys[$area] => $enabled]);
            adminLog(
                'blox_template',
                'set_custom_area_enabled',
                ($enabled === '1' ? '启用' : '停用') . ' Blox 自定义' . ($area === 'header' ? '网页头' : '网页尾')
            );
            $redirectUrl = '/admin/blox_templates.php?type=' . $area
                . '&area=' . $area . '&area_enabled=' . $enabled;
            $context = trim((string) post('context', 'home'));
            if ($context !== '' && $context !== 'home') {
                $redirectUrl .= '&context=' . rawurlencode($context);
            }
            redirect($redirectUrl);
        }

        if (!$tableReady) {
            throw new RuntimeException(__('blox_tpl_table_missing'));
        }

        if ($action === 'create_popup') {
            $name = mb_substr(trim((string) post('name', '')), 0, 150);
            if ($name === '') {
                throw new RuntimeException(__('blox_tpl_name_required'));
            }
            $seed = [
                'schema' => 1,
                'settings' => BloxPopupDocument::normalizeSettings([]),
                'sections' => [[
                    'type' => 'section',
                    'settings' => ['padding' => 'xl', 'max_width' => 'wide', 'bg_color' => '#ffffff'],
                    'columns' => [[
                        'elements' => [
                            ['type' => 'heading', 'data' => ['text' => __('blox_popup_seed_title'), 'level' => 'h2', 'align' => 'center']],
                            ['type' => 'text', 'data' => ['html' => '<p style="text-align:center">' . e(__('blox_popup_seed_text')) . '</p>']],
                        ],
                    ]],
                ]],
            ];
            $processed = BloxPopupDocument::process(json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $id = bloxTemplateModel()->createDraft(
                'popup',
                $name,
                $processed['json'],
                'user',
                1,
                BloxTemplateImporter::deriveRequirements($processed['sections']),
                '',
                (int) ($_SESSION['admin_id'] ?? 0)
            );
            adminLog('blox_template', 'create_popup', '创建 Popup 模板 #' . $id);
            redirect('/admin/blox_editor.php?template=' . $id);
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

            // r6：四类模板均可导入管理（importJson 内部完整校验）。header/footer 经激活条件前台生效，画布插入目录仍只 section/page。
            $result = BloxTemplateImporter::importJson($json, (int) ($_SESSION['admin_id'] ?? 0));
            adminLog(
                'blox_template',
                'import',
                '导入 Blox 模板 #' . $result['id'] . ' ' . $result['name']
            );
            redirect('/admin/blox_templates.php?imported=' . $result['id']);
        }

        if ($action === 'install_remote') {
            // r16 官方模板库一键安装：下载+hash+RSA 验签（provider 安检段）→ importJson
            // 完整校验落库——与文件导入同一安全链，官方包不是绕过安检的后门。
            // 审计 r17-3：按 (source=remote, source_ref=slug) 幂等——重放/重试/并发
            // 二次提交更新既有记录草稿而非新建行（无唯一约束迁移的轻量方案：入口
            // 唯一（本分支）+ 先查后写；决策记录见 r17 轮次文档）。
            $slug = trim((string) post('slug', ''));
            $provider = new BloxRemoteTemplateProvider();
            $json = $provider->fetchPackageJson($slug);
            $existing = bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug]);
            if ($existing) {
                $prepared = BloxTemplateImporter::prepare($json);
                bloxTemplateModel()->updateDraft((int) $existing['id'], $prepared['draft_json'], $prepared['requirements']);
                adminLog('blox_template', 'install_remote', '重装官方模板 ' . $slug . ' → #' . $existing['id']);
                redirect('/admin/blox_templates.php?imported=' . (int) $existing['id']);
            }
            $result = BloxTemplateImporter::importJson($json, (int) ($_SESSION['admin_id'] ?? 0), 'remote', $slug);
            adminLog('blox_template', 'install_remote', '安装官方模板 ' . $slug . ' → #' . $result['id']);
            redirect('/admin/blox_templates.php?imported=' . $result['id']);
        }

        if ($action === 'install_builtin_area') {
            // 固定清单解析 + Importer 安全链；重复安装只更新草稿，保留发布版本与显示条件。
            $slug = trim((string) post('slug', ''));
            $result = BloxAreaTemplatePresets::install($slug, (int) ($_SESSION['admin_id'] ?? 0));
            adminLog(
                'blox_template',
                'install_builtin_area',
                ($result['updated'] ? '更新' : '安装') . '内置区域模板 ' . $slug . ' → #' . $result['id']
            );
            redirect('/admin/blox_templates.php?imported=' . $result['id']);
        }

        if ($action === 'publish' || $action === 'unpublish') {
            $id = max(0, (int) post('id', 0));
            if ($action === 'publish') {
                $row = bloxTemplateModel()->find($id);
                if (!$row) {
                    throw new RuntimeException(__('blox_tpl_not_found'));
                }
                if (BloxTemplateModel::conditionalType((string) ($row['type'] ?? ''))) {
                    $conflictMessage = BloxAreaConditions::publishConflictMessage($row);
                    if ($conflictMessage !== '' && (string) post('confirm_conflict', '') !== '1') {
                        throw new RuntimeException(__('blox_cond_publish_confirm_required') . '：' . $conflictMessage);
                    }
                }
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
            if (!in_array((string) ($row['source'] ?? ''), ['user', 'import', 'remote'], true)) {
                throw new RuntimeException(__('blox_tpl_provider_undeletable'));
            }
            bloxTemplateModel()->deleteById($id);
            adminLog('blox_template', 'delete', '删除 Blox 模板草稿 #' . $id);
            redirect('/admin/blox_templates.php?deleted=1');
        }

        if ($action === 'save_conditions') {
            $id = max(0, (int) post('id', 0));
            $row = bloxTemplateModel()->find($id);
            if (!$row || !BloxTemplateModel::conditionalType((string) ($row['type'] ?? ''))) {
                throw new RuntimeException(__('blox_tpl_not_found'));
            }
            $raw = (string) post('conditions_json', '[]');
            // 管理端保存必须 fail-closed；损坏 JSON、未知条件和未选实体的单页条件均拒绝。
            $conditions = BloxAreaConditions::parseForSave($raw, BloxAreaConditions::entityOptions());
            bloxTemplateModel()->saveConditions($id, $conditions);
            adminLog('blox_template', 'conditions', '更新 Blox 模板激活条件 #' . $id);
            redirect('/admin/blox_templates.php?status=1');
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
$allProviderTemplates = BloxPluginRegistry::templates('all');
$providerTemplates = $filterType === 'all' ? $allProviderTemplates : array_values(array_filter(
    $allProviderTemplates,
    static fn (array $template): bool => (string) ($template['type'] ?? '') === $filterType
));
$allStoredTemplates = $tableReady ? array_values(array_filter(
    bloxTemplateModel()->catalog(),
    static fn (array $template): bool => in_array((string) ($template['source'] ?? ''), ['user', 'import', 'remote', 'builtin'], true)
)) : [];
$storedTemplates = $filterType === 'all' ? $allStoredTemplates : array_values(array_filter(
    $allStoredTemplates,
    static fn (array $template): bool => (string) ($template['type'] ?? '') === $filterType
));
$allLibraryBlocks = [];
if (db()->tableExists('blocks_library')) {
    $allLibraryBlocks = db()->fetchAll(
        'SELECT id,name,updated_at FROM ' . DB_PREFIX . 'blocks_library ORDER BY updated_at DESC, id DESC LIMIT 100'
    );
}
$libraryBlocks = in_array($filterType, ['all', 'section'], true) ? $allLibraryBlocks : [];

// r16 官方模板库（含 header/footer；远程不可达时静默降级为空列表+提示）
$officialTemplates = [];
$officialError = '';
if ($tableReady) {
    try {
        $officialTemplates = (new BloxRemoteTemplateProvider())->installable(isset($_GET['refresh_official']));
        if ($filterType !== 'all') {
            $officialTemplates = array_values(array_filter(
                $officialTemplates,
                static fn (array $template): bool => (string) ($template['type'] ?? '') === $filterType
            ));
        }
    } catch (Throwable $e) {
        $officialError = $e->getMessage();
    }
}
$installedRefs = [];
$builtinInstalledRefs = [];
foreach ($allStoredTemplates as $t) {
    if ((string) ($t['source'] ?? '') === 'remote' && (string) ($t['source_ref'] ?? '') !== '') {
        $installedRefs[(string) $t['source_ref']] = (int) $t['id'];
    }
    if ((string) ($t['source'] ?? '') === 'builtin' && (string) ($t['source_ref'] ?? '') !== '') {
        $builtinInstalledRefs[(string) $t['source_ref']] = (int) $t['id'];
    }
}
$areaPresets = BloxAreaTemplatePresets::catalog();
if (in_array($filterType, ['header', 'footer'], true)) {
    $areaPresets = array_values(array_filter(
        $areaPresets,
        static fn (array $preset): bool => (string) ($preset['type'] ?? '') === $filterType
    ));
}
$conditionEntities = BloxAreaConditions::entityOptions();
$areaConditionSummaries = [];
$areaConflicts = [];
$designDiagnostics = [];
foreach ($storedTemplates as $template) {
    $templateId = (int) ($template['id'] ?? 0);
    $templateType = (string) ($template['type'] ?? '');
    $requirements = json_decode((string) ($template['requirements'] ?? ''), true);
    $designDiagnostics[$templateId] = BloxDesignDependencies::diagnose(is_array($requirements) ? $requirements : []);
    if (!BloxTemplateModel::conditionalType($templateType)) {
        continue;
    }
    $areaConditionSummaries[$templateId] = BloxAreaConditions::summary(
        $template['conditions'] ?? null,
        $conditionEntities
    );
    $areaConflicts[$templateId] = BloxAreaConditions::conflicts(
        $template,
        bloxTemplateModel()->publishedAreaTemplates($templateType)
    );
}
$currentTheme = (string) config('current_theme', 'default');
$customAreaEnabled = [
    'header' => (string) config('blox_custom_header_enabled', '1') === '1',
    'footer' => (string) config('blox_custom_footer_enabled', '1') === '1',
];

// 当前区域状态必须与前台 bloxAreaHtml() 使用同一 Resolver，不能仅凭“有已发布模板”推断。
$areaContexts = [[
    'key' => 'home',
    'label' => __('blox_current_context_home'),
    'context' => ['home' => true, 'channel_id' => 0, 'page_id' => 0],
]];
foreach ($conditionEntities['channel'] as $entity) {
    $areaContexts[] = [
        'key' => 'channel:' . (int) $entity['id'],
        'label' => __('blox_cond_channel') . ' · ' . (string) $entity['label'],
        'context' => ['home' => false, 'channel_id' => (int) $entity['id'], 'page_id' => 0],
    ];
}
foreach ($conditionEntities['page'] as $entity) {
    $areaContexts[] = [
        'key' => 'page:' . (int) $entity['id'],
        'label' => __('blox_cond_page') . ' · ' . (string) $entity['label'],
        'context' => ['home' => false, 'channel_id' => 0, 'page_id' => (int) $entity['id']],
    ];
}
$areaContextKey = trim((string) get('context', 'home'));
$selectedAreaContext = $areaContexts[0];
foreach ($areaContexts as $candidateContext) {
    if ($candidateContext['key'] === $areaContextKey) {
        $selectedAreaContext = $candidateContext;
        break;
    }
}
$areaContextKey = (string) $selectedAreaContext['key'];
$areaPreviewUrl = '/?preview=1';
if ($areaContextKey !== 'home') {
    $contextParts = explode(':', $areaContextKey, 2);
    $contextChannel = channelModel()->find((int) ($contextParts[1] ?? 0));
    if ($contextChannel) {
        $areaPreviewUrl = channelUrl($contextChannel);
        $areaPreviewUrl .= str_contains($areaPreviewUrl, '?') ? '&preview=1' : '?preview=1';
    }
}

$currentAreas = [];
foreach (['header', 'footer'] as $areaType) {
    $publishedCandidates = $tableReady ? bloxTemplateModel()->publishedAreaTemplates($areaType) : [];
    $resolvedCandidate = $publishedCandidates === []
        ? null
        : BloxAreaResolver::resolve($publishedCandidates, $selectedAreaContext['context']);
    $areaEnabled = $customAreaEnabled[$areaType];
    $resolvedTemplate = $areaEnabled ? $resolvedCandidate : null;
    $drafts = array_values(array_filter(
        $allStoredTemplates,
        static fn (array $template): bool => (string) ($template['type'] ?? '') === $areaType
            && (int) ($template['status'] ?? 0) !== 1
    ));
    $currentAreas[$areaType] = [
        'resolved' => $resolvedTemplate,
        'resolved_candidate' => $resolvedCandidate,
        'enabled' => $areaEnabled,
        'published_count' => count($publishedCandidates),
        'drafts' => $drafts,
        'latest_draft' => $drafts[0] ?? null,
    ];
}

$typeLabels = [
    'section' => __('blox_template_type_section'),
    'page' => __('blox_template_type_page'),
    'header' => __('blox_tpl_type_header'),
    'footer' => __('blox_tpl_type_footer'),
    'popup' => __('blox_tpl_type_popup'),
];
$sourceLabels = [
    'user' => __('blox_tpl_source_user'),
    'import' => __('blox_tpl_source_import'),
    'remote' => __('blox_template_source_remote'),
    'builtin' => __('blox_tpl_source_builtin'),
    'plugin' => __('blox_template_source_plugin'),
];

$GLOBALS['pageTitle'] = __('admin_blox_templates');
$GLOBALS['currentMenu'] = 'blox_templates';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<script>
function condForm(initial, entities) {
    var normalized = Array.isArray(initial) ? initial : [];
    return {
        rows: normalized.map(function (row) {
            return {
                main: row && typeof row.main === "string" ? row.main : "any",
                ids: row && Array.isArray(row.ids) ? row.ids.map(Number).filter(function (id) { return id > 0; }) : [],
                exclude: !!(row && row.exclude),
                _query: "",
                _open: false
            };
        }),
        entities: entities || { channel: [], page: [] },
        payload: function () {
            return JSON.stringify(this.rows.map(function (row) {
                return { main: row.main, ids: row.ids, exclude: row.exclude };
            }));
        },
        choices: function (row) {
            var list = Array.isArray(this.entities[row.main]) ? this.entities[row.main] : [];
            var query = String(row._query || "").trim().toLocaleLowerCase();
            if (!query) return list;
            return list.filter(function (item) {
                return (String(item.label || "") + " " + String(item.search || "")).toLocaleLowerCase().includes(query);
            });
        },
        toggle: function (row, id) {
            id = Number(id);
            var index = row.ids.indexOf(id);
            if (index >= 0) row.ids.splice(index, 1);
            else row.ids.push(id);
        },
        selected: function (row, id) {
            return row.ids.indexOf(Number(id)) >= 0;
        },
        selectedText: function (row) {
            if (row.main === "channel" && row.ids.length === 0) return <?php echo json_encode(__('blox_cond_all_channels'), JSON_UNESCAPED_UNICODE); ?>;
            if (row.main === "page" && row.ids.length === 0) return <?php echo json_encode(__('blox_cond_choose_pages'), JSON_UNESCAPED_UNICODE); ?>;
            var list = Array.isArray(this.entities[row.main]) ? this.entities[row.main] : [];
            var labels = row.ids.map(function (id) {
                var found = list.find(function (item) { return Number(item.id) === Number(id); });
                return found ? found.label : "#" + id;
            });
            if (labels.length <= 2) return labels.join("、");
            return labels.slice(0, 2).join("、") + <?php echo json_encode(__('blox_cond_selected_more'), JSON_UNESCAPED_UNICODE); ?>.replace(":count", String(labels.length - 2));
        }
    };
}
function confirmAreaPublish(form) {
    var message = form.getAttribute("data-conflict-message") || "";
    if (message && !window.confirm(message)) return false;
    var field = form.querySelector('[name="confirm_conflict"]');
    if (field) field.value = "1";
    return true;
}
</script>

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
    <?php if ($currentTheme === 'default'): ?>
        <div class="border-l-4 border-blue-500 bg-blue-50 px-4 py-3 text-sm text-blue-800" data-testid="blox-default-theme-status">
            <i class="ti ti-circle-check mr-1"></i><?php echo __('blox_default_theme_area_ready'); ?>
        </div>
    <?php else: ?>
        <div class="border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <i class="ti ti-alert-triangle mr-1"></i><?php echo e(__('blox_nondefault_theme_area_notice', ['theme' => $currentTheme])); ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-2 gap-px overflow-hidden border border-gray-200 bg-gray-200 md:grid-cols-4">
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_source_user'); ?></div><div class="mt-1 text-xl font-semibold"><?php echo count($allStoredTemplates); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_builtin_plugin'); ?></div><div class="mt-1 text-xl font-semibold"><?php echo count($allProviderTemplates); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_reusable_blocks'); ?></div><div class="mt-1 text-xl font-semibold"><?php echo count($allLibraryBlocks); ?></div></div>
        <div class="bg-white px-4 py-3"><div class="text-xs text-gray-500"><?php echo __('blox_tpl_format'); ?></div><div class="mt-1 text-xl font-semibold">JSON v1</div></div>
    </div>

    <nav class="flex flex-wrap gap-1 border-y border-gray-200 bg-white p-2" aria-label="<?php echo e(__('blox_tpl_filter_label')); ?>" data-testid="blox-template-type-filter">
        <?php foreach (array_merge(['all'], BloxTemplateModel::TYPES) as $type):
            $label = $type === 'all' ? __('blox_tpl_filter_all') : ($typeLabels[$type] ?? $type);
            $active = $filterType === $type;
        ?>
        <a href="/admin/blox_templates.php<?php echo $type === 'all' ? '' : '?type=' . e($type); ?>"
           data-testid="blox-template-filter-<?php echo e($type); ?>"
           <?php echo $active ? 'aria-current="page"' : ''; ?>
           class="inline-flex h-9 items-center px-3 text-sm <?php echo $active ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'; ?>">
            <?php echo e($label); ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <?php if (in_array($filterType, ['all', 'header', 'footer'], true)):
        $overviewTypes = in_array($filterType, ['header', 'footer'], true) ? [$filterType] : ['header', 'footer'];
    ?>
    <section data-testid="blox-current-areas">
        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900"><?php echo e(__('blox_current_areas_title')); ?></h2>
                <p class="mt-1 text-xs text-gray-500"><?php echo e(__('blox_current_areas_hint')); ?></p>
            </div>
            <form method="get" class="flex min-w-0 items-center gap-2">
                <?php if ($filterType !== 'all'): ?><input type="hidden" name="type" value="<?php echo e($filterType); ?>"><?php endif; ?>
                <label for="blox-current-context" class="shrink-0 text-xs font-medium text-gray-600"><?php echo e(__('blox_current_context')); ?></label>
                <select id="blox-current-context" name="context" onchange="this.form.submit()"
                        data-testid="blox-current-context"
                        class="h-9 min-w-0 max-w-80 border border-gray-300 bg-white px-2 text-sm">
                    <?php foreach ($areaContexts as $contextOption): ?>
                    <option value="<?php echo e($contextOption['key']); ?>" <?php echo $areaContextKey === $contextOption['key'] ? 'selected' : ''; ?>><?php echo e($contextOption['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
            <?php foreach ($overviewTypes as $areaType):
                $area = $currentAreas[$areaType];
                $resolved = $area['resolved'];
                $resolvedCandidate = $area['resolved_candidate'];
                $areaEnabled = (bool) $area['enabled'];
                $latestDraft = $area['latest_draft'];
                $isHeader = $areaType === 'header';
                $areaDisabledHintKey = $isHeader ? 'blox_custom_header_disabled_hint' : 'blox_custom_footer_disabled_hint';
                $areaPreservedKey = $isHeader ? 'blox_custom_header_preserved' : 'blox_custom_footer_preserved';
                $areaDisableKey = $isHeader ? 'blox_custom_header_disable' : 'blox_custom_footer_disable';
                $areaEnableKey = $isHeader ? 'blox_custom_header_enable' : 'blox_custom_footer_enable';
                $areaConfirmKey = $isHeader ? 'blox_custom_header_disable_confirm' : 'blox_custom_footer_disable_confirm';
            ?>
            <article class="flex min-h-52 flex-col border border-gray-200 bg-white p-5" data-testid="blox-current-area-<?php echo e($areaType); ?>">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center bg-gray-100 text-gray-600"><i class="ti <?php echo $isHeader ? 'ti-layout-navbar' : 'ti-layout-bottombar'; ?> text-xl"></i></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-medium text-gray-900"><?php echo e($typeLabels[$areaType]); ?></h3>
                            <?php if (!$areaEnabled): ?>
                            <span class="bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700" data-testid="blox-current-area-source-disabled"><?php echo e(__('blox_custom_header_disabled_badge')); ?></span>
                            <?php elseif ($resolved): ?>
                            <span class="bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700" data-testid="blox-current-area-source-blox"><?php echo e(__('blox_current_source_blox')); ?></span>
                            <?php else: ?>
                            <span class="bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600" data-testid="blox-current-area-source-theme"><?php echo e(__('blox_current_source_theme')); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!$areaEnabled): ?>
                        <div class="mt-2 font-semibold text-gray-900"><?php echo e(__('blox_current_theme_fallback', ['theme' => $currentTheme])); ?></div>
                        <p class="mt-1 text-xs text-gray-500"><?php echo e(__($areaDisabledHintKey)); ?></p>
                        <?php elseif ($resolved): ?>
                        <div class="mt-2 font-semibold text-gray-900"><?php echo e((string) $resolved['name']); ?> <span class="text-xs font-normal text-gray-400">#<?php echo (int) $resolved['id']; ?></span></div>
                        <p class="mt-1 text-xs text-gray-500"><?php echo e(BloxAreaConditions::summary($resolved['conditions'] ?? null, $conditionEntities)); ?></p>
                        <?php else: ?>
                        <div class="mt-2 font-semibold text-gray-900"><?php echo e(__('blox_current_theme_fallback', ['theme' => $currentTheme])); ?></div>
                        <p class="mt-1 text-xs text-gray-500"><?php echo e(__('blox_current_no_match')); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mt-4 flex-1 border-t border-gray-100 pt-3 text-xs text-gray-500">
                    <?php if (!$areaEnabled): ?>
                    <div class="flex items-center gap-2 text-amber-700" data-testid="blox-custom-<?php echo e($areaType); ?>-disabled-state">
                        <i class="ti ti-player-pause"></i>
                        <span><?php echo e(__($areaPreservedKey, ['count' => $area['published_count']])); ?></span>
                    </div>
                    <?php elseif (!$resolved && $latestDraft): ?>
                    <div class="flex items-center gap-2 text-amber-700" data-testid="blox-current-area-draft">
                        <i class="ti ti-pencil"></i>
                        <span><?php echo e(__('blox_current_draft_ready', ['name' => (string) $latestDraft['name'], 'count' => count($area['drafts'])])); ?></span>
                    </div>
                    <?php elseif (!$resolved): ?>
                    <div class="flex items-center gap-2 text-gray-500"><i class="ti ti-info-circle"></i><span><?php echo e(__('blox_current_no_draft')); ?></span></div>
                    <?php else: ?>
                    <div class="flex items-center gap-2 text-emerald-700"><i class="ti ti-circle-check"></i><span><?php echo e(__('blox_current_published_candidates', ['count' => $area['published_count']])); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3">
                    <form method="post" class="contents" <?php echo $areaEnabled ? 'onsubmit="return confirm(' . e(json_encode(__($areaConfirmKey), JSON_UNESCAPED_UNICODE)) . ')"' : ''; ?>>
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="set_custom_area_enabled">
                        <input type="hidden" name="area" value="<?php echo e($areaType); ?>">
                        <input type="hidden" name="enabled" value="<?php echo $areaEnabled ? '0' : '1'; ?>">
                        <input type="hidden" name="context" value="<?php echo e($areaContextKey); ?>">
                        <button type="submit"
                                role="switch"
                                aria-checked="<?php echo $areaEnabled ? 'true' : 'false'; ?>"
                                data-testid="blox-custom-<?php echo e($areaType); ?>-toggle"
                                class="inline-flex h-8 items-center gap-2 border px-2.5 text-xs font-medium <?php echo $areaEnabled ? 'border-gray-300 text-gray-600 hover:border-red-300 hover:text-red-600' : 'border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100'; ?>">
                            <span class="relative inline-flex h-4 w-7 items-center rounded-full <?php echo $areaEnabled ? 'bg-emerald-500' : 'bg-gray-300'; ?>" aria-hidden="true">
                                <span class="h-3 w-3 rounded-full bg-white transition-transform <?php echo $areaEnabled ? 'translate-x-3.5' : 'translate-x-0.5'; ?>"></span>
                            </span>
                            <?php echo e($areaEnabled ? __($areaDisableKey) : __($areaEnableKey)); ?>
                        </button>
                    </form>
                    <?php if ($resolved): ?>
                    <a href="/admin/blox_editor.php?template=<?php echo (int) $resolved['id']; ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"><i class="ti ti-edit"></i><?php echo e(__('blox_current_edit')); ?></a>
                    <?php elseif (!$areaEnabled && $resolvedCandidate): ?>
                    <a href="/admin/blox_editor.php?template=<?php echo (int) $resolvedCandidate['id']; ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"><i class="ti ti-edit"></i><?php echo e(__('blox_current_edit')); ?></a>
                    <?php elseif ($latestDraft): ?>
                    <a href="/admin/blox_editor.php?template=<?php echo (int) $latestDraft['id']; ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"><i class="ti ti-edit"></i><?php echo e(__('blox_current_edit_draft')); ?></a>
                    <?php else: ?>
                    <a href="/admin/blox_templates.php?type=<?php echo e($areaType); ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"><i class="ti ti-layout-grid-add"></i><?php echo e(__('blox_current_choose_design')); ?></a>
                    <?php endif; ?>
                    <a href="/admin/blox_templates.php?type=<?php echo e($areaType); ?>" class="text-sm text-gray-500 hover:text-gray-900"><?php echo e(__('site_design_manage')); ?></a>
                    <a href="<?php echo e($areaPreviewUrl); ?>" target="_blank" rel="noopener" class="ml-auto inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900"><i class="ti ti-external-link"></i><?php echo e(__('blox_current_preview')); ?></a>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (in_array($filterType, ['all', 'popup'], true)): ?>
    <section class="border-y border-gray-200 bg-white" data-testid="blox-popup-create">
        <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-4">
            <div>
                <h2 class="font-semibold text-gray-900"><i class="ti ti-window mr-1 text-fuchsia-600"></i><?php echo e(__('blox_popup_templates')); ?></h2>
                <p class="mt-1 text-xs text-gray-500"><?php echo e(__('blox_popup_templates_hint')); ?></p>
            </div>
            <form method="post" class="flex items-center gap-2">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create_popup">
                <input type="text" name="name" required maxlength="150" placeholder="<?php echo e(__('blox_popup_name_placeholder')); ?>"
                       class="h-9 w-56 border border-gray-300 px-3 text-sm">
                <button type="submit" class="inline-flex h-9 items-center gap-1 bg-fuchsia-600 px-3 text-sm text-white hover:bg-fuchsia-500">
                    <i class="ti ti-plus"></i><?php echo e(__('blox_popup_create')); ?>
                </button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <?php if (in_array($filterType, ['all', 'header', 'footer'], true)): ?>
    <section class="border-y border-gray-200 bg-white" data-testid="blox-area-presets">
        <div class="border-b border-gray-200 px-5 py-4">
            <h2 class="font-semibold text-gray-900"><?php echo __('blox_area_presets_title'); ?></h2>
            <p class="mt-1 text-xs text-gray-500"><?php echo __('blox_area_presets_hint'); ?></p>
        </div>
        <?php if ($areaPresets === []): ?>
        <div class="px-5 py-3 text-sm text-gray-400"><?php echo __('blox_area_presets_empty'); ?></div>
        <?php else: ?>
        <div class="grid gap-3 p-5 sm:grid-cols-2">
            <?php foreach ($areaPresets as $preset):
                $presetId = $builtinInstalledRefs[$preset['slug']] ?? 0;
            ?>
            <div class="flex min-h-32 flex-col gap-2 rounded border border-gray-200 p-4">
                <div class="flex items-center gap-2">
                    <i class="ti <?php echo $preset['type'] === 'header' ? 'ti-layout-navbar' : 'ti-layout-bottombar'; ?> text-gray-500"></i>
                    <span class="font-medium text-gray-900"><?php echo e($preset['name']); ?></span>
                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500"><?php echo e($typeLabels[$preset['type']]); ?></span>
                </div>
                <p class="flex-1 text-xs text-gray-500"><?php echo e($preset['description']); ?></p>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[11px] text-gray-400">
                        <?php echo $presetId > 0 ? __('blox_area_preset_draft_safe') : __('blox_area_preset_not_active'); ?>
                    </span>
                    <div class="flex items-center gap-3">
                        <?php if ($presetId > 0): ?>
                        <a href="/admin/blox_editor.php?template=<?php echo $presetId; ?>" class="text-xs text-gray-600 hover:text-primary">
                            <i class="ti ti-edit"></i> <?php echo __('blox_tpl_open_editor'); ?>
                        </a>
                        <?php endif; ?>
                        <form method="post">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="install_builtin_area">
                            <input type="hidden" name="slug" value="<?php echo e($preset['slug']); ?>">
                            <button type="submit" class="text-xs text-primary hover:opacity-80" data-testid="blox-area-preset-install">
                                <i class="ti <?php echo $presetId > 0 ? 'ti-refresh' : 'ti-download'; ?>"></i>
                                <?php echo $presetId > 0 ? __('blox_area_preset_update') : __('blox_tpl_install'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- r16 官方模板库：远程签名资产一键安装（含 header/footer） -->
    <section class="border-y border-gray-200 bg-white">
        <div class="px-5 py-4 border-b border-gray-200 flex items-center gap-2">
            <h2 class="font-semibold text-gray-900"><?php echo __('blox_tpl_official_title'); ?></h2>
            <span class="text-xs text-gray-400"><?php echo __('blox_tpl_official_hint'); ?></span>
            <a href="/admin/blox_templates.php?refresh_official=1<?php echo $filterType === 'all' ? '' : '&amp;type=' . e($filterType); ?>" class="ml-auto text-xs text-gray-400 hover:text-primary" data-testid="blox-official-refresh">
                <i class="ti ti-refresh"></i> <?php echo __('blox_tpl_official_refresh'); ?>
            </a>
        </div>
        <?php if ($officialError !== ''): ?>
        <div class="px-5 py-3 text-sm text-amber-600"><i class="ti ti-cloud-off"></i> <?php echo e($officialError); ?></div>
        <?php elseif ($officialTemplates === []): ?>
        <div class="px-5 py-3 text-sm text-gray-400"><?php echo __('blox_tpl_official_empty'); ?></div>
        <?php else: ?>
        <div class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3" data-testid="blox-official-list">
            <?php foreach ($officialTemplates as $ot):
                $slug = str_replace('remote:', '', (string) $ot['key']);
                $installedId = $installedRefs[$slug] ?? 0;
            ?>
            <div class="rounded-xl border border-gray-200 p-4 flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900"><?php echo e((string) $ot['name']); ?></span>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500"><?php echo e($typeLabels[(string) $ot['type']] ?? (string) $ot['type']); ?></span>
                    <?php if (!empty($ot['paid'])): ?><span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-600">Pro</span><?php endif; ?>
                </div>
                <p class="text-xs text-gray-500 flex-1"><?php echo e((string) ($ot['description'] ?? '')); ?></p>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] text-gray-400">v<?php echo e((string) ($ot['version'] ?? '')); ?></span>
                    <?php if ($installedId > 0): ?>
                    <span class="text-xs text-emerald-600"><i class="ti ti-check"></i> <?php echo __('blox_tpl_installed'); ?></span>
                    <?php elseif (!empty($ot['locked'])): ?>
                    <span class="text-xs text-gray-400"><i class="ti ti-lock"></i> <?php echo __('blox_tpl_locked'); ?></span>
                    <?php else: ?>
                    <form method="post">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="install_remote">
                        <input type="hidden" name="slug" value="<?php echo e($slug); ?>">
                        <button type="submit" class="text-xs text-primary hover:opacity-80" data-testid="blox-official-install">
                            <i class="ti ti-download"></i> <?php echo __('blox_tpl_install'); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

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
                    <tbody class="divide-y divide-gray-100" x-data="{ condOpen: 0 }">
                    <?php foreach ($storedTemplates as $template):
                        $templateId = (int) $template['id'];
                        $templateRequirements = json_decode((string) ($template['requirements'] ?? ''), true);
                        $templateRequirements = is_array($templateRequirements) ? $templateRequirements : [];
                        $isAreaTemplate = BloxTemplateModel::conditionalType((string) $template['type']);
                        $templateConflicts = $areaConflicts[$templateId] ?? [];
                        $designDiagnostic = $designDiagnostics[$templateId] ?? ['complete' => true];
                        $conflictSummary = BloxAreaConditions::conflictSummary($templateConflicts);
                        $publishConflictMessage = __('blox_cond_publish_confirm') . ($conflictSummary !== '' ? "\n\n" . $conflictSummary : '');
                    ?>
                        <tr>
                            <td class="px-5 py-3">
                                <div class="font-medium text-gray-900"><?php echo e((string) $template['name']); ?></div>
                                <?php if ($isAreaTemplate): ?>
                                <div class="mt-1 max-w-md text-xs text-gray-500" data-testid="blox-condition-summary">
                                    <i class="ti ti-target-arrow mr-1"></i><?php echo e($areaConditionSummaries[$templateId] ?? ''); ?>
                                </div>
                                <?php if ($templateConflicts !== []): ?>
                                <div class="mt-1 max-w-md text-xs text-amber-700" data-testid="blox-condition-conflict">
                                    <i class="ti ti-alert-triangle mr-1"></i><?php echo e($conflictSummary); ?>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php if (empty($designDiagnostic['complete'])): ?>
                                <div class="mt-1 max-w-md text-xs text-amber-700" data-testid="blox-template-design-missing">
                                    <i class="ti ti-unlink mr-1"></i><?php echo e(__('blox_design_dependencies_missing', [
                                        'tokens' => implode(', ', $designDiagnostic['missing_tokens'] ?? []),
                                        'styles' => implode(', ', $designDiagnostic['missing_styles'] ?? []),
                                    ])); ?>
                                </div>
                                <?php elseif (($designDiagnostic['archived_tokens'] ?? []) !== [] || ($designDiagnostic['archived_styles'] ?? []) !== []): ?>
                                <div class="mt-1 max-w-md text-xs text-gray-500" data-testid="blox-template-design-archived">
                                    <i class="ti ti-archive mr-1"></i><?php echo e(__('blox_design_dependencies_archived')); ?>
                                </div>
                                <?php elseif (($templateRequirements['design_tokens'] ?? []) !== [] || ($templateRequirements['design_styles'] ?? []) !== []): ?>
                                <div class="mt-1 max-w-md text-xs text-emerald-700" data-testid="blox-template-design-complete">
                                    <i class="ti ti-link mr-1"></i><?php echo e(__('blox_design_dependencies_complete')); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3"><?php echo e($typeLabels[(string) $template['type']] ?? (string) $template['type']); ?></td>
                            <td class="px-4 py-3 text-gray-500"><?php echo e($sourceLabels[(string) $template['source']] ?? (string) $template['source']); ?></td>
                            <td class="px-4 py-3"><?php echo (int) $template['status'] === 1 ? __('blox_tpl_published') : __('blox_tpl_draft'); ?></td>
                            <td class="px-4 py-3 text-gray-500"><?php echo date('Y-m-d H:i', (int) $template['updated_at']); ?></td>
                            <td class="px-5 py-3 text-right">
                                <a href="/admin/blox_editor.php?template=<?php echo (int) $template['id']; ?>"
                                   class="mr-3 text-blue-600 hover:text-blue-800" title="<?php echo e(__('blox_tpl_open_editor')); ?>">
                                    <i class="ti ti-edit"></i>
                                </a>
                                <?php if ($isAreaTemplate): ?>
                                <button type="button" class="mr-3 text-indigo-600 hover:text-indigo-800"
                                        @click="condOpen = condOpen === <?php echo (int) $template['id']; ?> ? 0 : <?php echo (int) $template['id']; ?>"
                                        data-testid="blox-condition-toggle"
                                        title="<?php echo e(__('blox_tpl_conditions')); ?>">
                                    <i class="ti ti-adjustments-alt"></i>
                                </button>
                                <?php endif; ?>
                                <a href="/admin/blox_templates.php?action=export&amp;id=<?php echo (int) $template['id']; ?>"
                                   class="mr-3 text-gray-600 hover:text-gray-900" title="<?php echo e(__('blox_tpl_export_json')); ?>">
                                    <i class="ti ti-download"></i>
                                </a>
                                <form method="post" class="mr-3 inline"
                                      <?php if ((int) $template['status'] !== 1 && $templateConflicts !== []): ?>
                                      data-conflict-message="<?php echo e($publishConflictMessage); ?>"
                                      onsubmit="return confirmAreaPublish(this)"
                                      <?php endif; ?>>
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="<?php echo (int) $template['status'] === 1 ? 'unpublish' : 'publish'; ?>">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <input type="hidden" name="confirm_conflict" value="">
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
                        <?php if ($isAreaTemplate): ?>
                        <tr x-show="condOpen === <?php echo (int) $template['id']; ?>" x-cloak>
                            <td colspan="6" class="px-5 py-4 bg-indigo-50/50">
                                <form method="post" data-testid="blox-condition-form"
                                      x-data='condForm(<?php echo json_encode(BloxAreaResolver::parse($template['conditions'] ?? null), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode($conditionEntities, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="save_conditions">
                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                    <input type="hidden" name="conditions_json" :value="payload()">
                                    <div class="mb-2 text-xs text-gray-500"><?php echo __('blox_tpl_conditions_hint'); ?></div>
                                    <template x-for="(row, i) in rows" :key="'c' + i">
                                        <div class="mb-3 flex flex-wrap items-start gap-2">
                                            <select x-model="row.main" @change="row.ids = []; row._query = ''; row._open = false"
                                                    class="h-9 border border-gray-300 bg-white px-2 text-sm">
                                                <option value="any"><?php echo e(__('blox_cond_any')); ?></option>
                                                <option value="home"><?php echo e(__('blox_cond_home')); ?></option>
                                                <option value="channel"><?php echo e(__('blox_cond_channel')); ?></option>
                                                <option value="page"><?php echo e(__('blox_cond_page')); ?></option>
                                            </select>
                                            <div x-show="row.main === 'channel' || row.main === 'page'" class="relative w-full max-w-md">
                                                <button type="button" @click="row._open = !row._open" :aria-expanded="row._open ? 'true' : 'false'"
                                                        class="flex h-9 w-full items-center justify-between gap-2 border border-gray-300 bg-white px-3 text-left text-sm text-gray-700"
                                                        data-testid="blox-condition-picker">
                                                    <span class="min-w-0 truncate" x-text="selectedText(row)"></span>
                                                    <i class="ti ti-chevron-down shrink-0 text-gray-400"></i>
                                                </button>
                                                <div x-show="row._open" x-cloak @click.outside="row._open = false"
                                                     class="absolute left-0 top-full z-30 mt-1 w-full border border-gray-200 bg-white shadow-lg">
                                                    <div class="border-b border-gray-100 p-2">
                                                        <div class="flex items-center gap-2 border border-gray-200 px-2">
                                                            <i class="ti ti-search text-gray-400"></i>
                                                            <input type="search" x-model="row._query"
                                                                   placeholder="<?php echo e(__('blox_cond_search_placeholder')); ?>"
                                                                   class="h-8 min-w-0 flex-1 border-0 bg-transparent text-sm outline-none">
                                                        </div>
                                                    </div>
                                                    <div class="max-h-56 overflow-y-auto py-1">
                                                        <template x-for="choice in choices(row)" :key="choice.id">
                                                            <button type="button" @click="toggle(row, choice.id)"
                                                                    data-testid="blox-condition-choice"
                                                                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-gray-50">
                                                                <i class="ti shrink-0 text-base" :class="selected(row, choice.id) ? 'ti-square-check-filled text-primary' : 'ti-square text-gray-300'"></i>
                                                                <span class="min-w-0 flex-1 truncate" x-text="choice.label"></span>
                                                                <span class="shrink-0 text-[10px] text-gray-400" x-text="'#' + choice.id"></span>
                                                            </button>
                                                        </template>
                                                        <div x-show="choices(row).length === 0" class="px-3 py-4 text-center text-xs text-gray-400">
                                                            <?php echo e(__('blox_cond_no_results')); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <label class="inline-flex h-9 items-center gap-1 text-xs text-gray-600">
                                                <input type="checkbox" x-model="row.exclude"><?php echo e(__('blox_cond_exclude')); ?>
                                            </label>
                                            <button type="button" @click="rows.splice(i, 1)" class="inline-flex h-9 w-9 items-center justify-center text-red-500 hover:bg-red-50 hover:text-red-700"
                                                    title="<?php echo e(__('delete')); ?>"><i class="ti ti-x"></i></button>
                                        </div>
                                    </template>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <button type="button" @click="rows.push({main:'any', ids:[], exclude:false, _query:'', _open:false})"
                                                data-testid="blox-condition-add"
                                                class="border border-indigo-200 px-2 py-1 text-xs text-indigo-600 hover:text-indigo-800">
                                            + <?php echo e(__('blox_cond_add')); ?>
                                        </button>
                                        <button type="submit" class="bg-indigo-600 px-3 py-1.5 text-xs text-white hover:bg-indigo-500">
                                            <?php echo e(__('save')); ?>
                                        </button>
                                        <span class="text-[11px] text-gray-400"><?php echo __('blox_cond_empty_hint'); ?></span>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endif; ?>
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

    <?php if (in_array($filterType, ['all', 'section'], true)): ?>
    <section class="border-y border-gray-200 bg-white">
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-200">
            <h2 class="font-semibold text-gray-900"><?php echo __('blox_tpl_reusable_blocks'); ?></h2>
            <?php if (bloxAdvancedFeaturesEnabled()): ?>
            <a href="/admin/blox_editor.php?home=1" class="text-sm text-blue-600 hover:text-blue-800"><?php echo __('blox_tpl_layout_editor'); ?></a>
            <?php endif; ?>
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
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
