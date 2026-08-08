<?php
/** Blox 编辑器模板目录与安全解析 API。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_page');

if (!bloxEditorEnabled()) {
    error(__('blox_feature_disabled'));
}

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

header('Cache-Control: no-store, max-age=0');

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = $method === 'POST' ? (string) post('action', '') : (string) get('action', 'list');
    if ($action === 'list' && $method === 'GET') {
        $context = (string) get('context', 'page');
        success([
            'items' => BloxTemplateCatalog::items($context, true, (string) get('refresh', '') === '1'),
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
        $processed = BloxDocumentPipeline::process((string) post('blocks_data', '[]'), 'tpl' . $id);
        $requirements = BloxTemplateImporter::deriveRequirements($processed['sections']);
        bloxTemplateModel()->updateDraft($id, $processed['json'], $requirements);
        adminLog('blox_template', 'save_draft', '保存 Blox 模板草稿 #' . $id);
        success(['id' => $id]);
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
        bloxTemplateModel()->publishDraft((int) $result['id']);
        adminLog('blox_template', 'save_section', '画布另存区块模板 #' . (int) $result['id'] . ' ' . $name);
        success(['id' => (int) $result['id']]);
    }
    if ($action === 'publish' && $method === 'POST') {
        verifyCsrf();
        $id = (int) post('id', '0');
        bloxTemplateModel()->publishDraft($id);
        adminLog('blox_template', 'publish', '发布 Blox 模板 #' . $id);
        success(['id' => $id]);
    }
    if ($action === 'get' && $method === 'POST') {
        verifyCsrf();
        $context = (string) post('context', 'page');
        $key = trim((string) post('key', ''));
        $template = BloxTemplateCatalog::resolve($key, $context);
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