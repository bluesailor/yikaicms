<?php
/** Blox 编辑器模板目录与安全解析 API。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_page');

$advancedBloxEnabled = bloxAdvancedFeaturesEnabled();
if (!bloxPageEditorEnabled()) {
    error(__('blox_feature_disabled'));
}

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

header('Cache-Control: no-store, max-age=0');

$requireTemplateLicense = static function (string $type) use ($advancedBloxEnabled): void {
    if (!in_array($type, ['section', 'page'], true) && !$advancedBloxEnabled) {
        error(__('blox_feature_disabled'));
    }
};

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = $method === 'POST' ? (string) post('action', '') : (string) get('action', 'list');
    if ($action === 'list' && $method === 'GET') {
        $context = (string) get('context', 'page');
        requireBloxTemplateTypePermission($context);
        $items = BloxTemplateCatalog::items($context, true, (string) get('refresh', '') === '1');
        if (!$advancedBloxEnabled) {
            $items = array_map(static function (array $item): array {
                if (($item['source'] ?? '') === 'remote') {
                    $item['locked'] = true;
                    $item['locked_reason'] = 'license_missing';
                }
                return $item;
            }, $items);
        }
        success([
            'items' => $items,
            'remote_error' => BloxTemplateCatalog::remoteError(),
        ]);
    }
    if ($action === 'save_draft' && $method === 'POST') {
        verifyCsrf();
        $id = (int) post('id', '0');
        $row = bloxTemplateModel()->findForExport($id);
        if (!$row) {
            error(__('blox_tpl_not_found'));
        }
        $type = (string) ($row['type'] ?? '');
        $requireTemplateLicense($type);
        requireBloxTemplateTypePermission($type);
        $currentDraft = trim((string) ($row['draft_data'] ?? '')) !== ''
            ? (string) $row['draft_data']
            : '[]';
        $baseRevision = trim((string) post('base_revision', ''));
        $revisionMatches = $type === 'popup'
            ? BloxPopupDocument::revisionMatches($currentDraft, $baseRevision)
            : BloxDocumentPipeline::revisionMatches($currentDraft, $baseRevision);
        if ($baseRevision !== '' && !$revisionMatches) {
            error(__('blox_save_conflict'), 409);
        }
        $processed = BloxAreaDocument::isArea($type)
            ? BloxAreaDocument::process($type, (string) post('blocks_data', '[]'), 'tpl' . $id)
            : ($type === 'popup'
                ? BloxPopupDocument::process((string) post('blocks_data', '[]'), 'tpl' . $id)
                : BloxDocumentPipeline::process((string) post('blocks_data', '[]'), 'tpl' . $id));
        $requirements = BloxTemplateImporter::deriveRequirements($processed['sections']);
        try {
            bloxTemplateModel()->updateDraft($id, $processed['json'], $requirements, (string) ($row['draft_data'] ?? ''));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === __('blox_save_conflict')) {
                error($e->getMessage(), 409);
            }
            throw $e;
        }
        adminLog('blox_template', 'save_draft', '保存 Blox 模板草稿 #' . $id);
        success([
            'id' => $id,
            'base_revision' => $type === 'popup'
                ? BloxPopupDocument::fingerprint($processed['json'])
                : BloxDocumentPipeline::fingerprint($processed['json']),
        ]);
    }
    if ($action === 'save_section' && $method === 'POST') {
        // r14 画布「另存为区块模板」：客户端只发 section JSON + 名称，服务端组
        // 标准模板包走 Importer 安全链（危险字段/元素白名单/插件依赖/Pipeline 校验
        // 与文件导入完全一致——画布选区不是绕过安检的后门）。发布后目录立即可插回。
        verifyCsrf();
        $name = mb_substr(trim((string) post('name', '')), 0, 150);
        if ($name === '') {
            error(__('blox_tpl_name_required'));
        }
        $decoded = json_decode((string) post('section', ''), true);
        if (!is_array($decoded)) {
            error(__('blox_doc_invalid_json'));
        }
        $package = json_encode([
            'format' => BloxTemplateImporter::FORMAT,
            'version' => BloxTemplateImporter::VERSION,
            'type' => 'section',
            'name' => $name,
            'document' => [$decoded],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $result = BloxTemplateImporter::importJson($package, (int) ($_SESSION['admin_id'] ?? 0), 'user', 'canvas');
        $templateId = (int) $result['id'];
        bloxTemplateModel()->publishDraft($templateId);
        adminLog('blox_template', 'save_section', '画布另存区块模板 #' . $templateId . ' ' . $name);
        // 直接返回目录项，客户端无需等待包含远程 provider 的整表刷新即可看到新模板。
        success([
            'id' => $templateId,
            'template' => [
                'key' => 'local:' . $templateId,
                'type' => 'section',
                'name' => $name,
                'description' => '',
                'source' => 'local',
                'provider' => 'user',
                'category' => 'section',
                'thumbnail' => '',
                'updated_at' => time(),
            ],
        ]);
    }
    if ($action === 'publish' && $method === 'POST') {
        verifyCsrf();
        $id = (int) post('id', '0');
        $row = bloxTemplateModel()->find($id);
        if (!$row) {
            error(__('blox_tpl_not_found'));
        }
        $type = (string) ($row['type'] ?? '');
        $requireTemplateLicense($type);
        requireBloxTemplateTypePermission($type);
        $conflictMessage = BloxAreaConditions::publishConflictMessage($row);
        if ($conflictMessage !== '' && (string) post('confirm_conflict', '') !== '1') {
            error($conflictMessage, 409);
        }
        bloxTemplateModel()->publishDraft($id);
        adminLog('blox_template', 'publish', '发布 Blox 模板 #' . $id);
        success(['id' => $id]);
    }
    if ($action === 'get' && $method === 'POST') {
        verifyCsrf();
        $context = (string) post('context', 'page');
        requireBloxTemplateTypePermission($context);
        $key = trim((string) post('key', ''));
        if (!$advancedBloxEnabled && str_starts_with($key, 'remote:')) {
            error(__('blox_template_locked_license'));
        }
        $template = BloxTemplateCatalog::resolve($key, $context);
        requireBloxTemplateTypePermission((string) ($template['type'] ?? $context));
        if (($template['source'] ?? '') === 'remote') {
            try {
                adminLog(
                    'blox_template',
                    'remote_resolve',
                    '解析在线模板 ' . $key . ' ' . mb_substr((string) ($template['name'] ?? ''), 0, 150)
                );
            } catch (Throwable $logError) {
                error_log('[BloxTemplateApi] Remote audit log: ' . $logError->getMessage());
            }
        }
        success(['template' => $template]);
    }
    error(__('blox_invalid_action'));
} catch (Throwable $e) {
    error($e->getMessage());
}
