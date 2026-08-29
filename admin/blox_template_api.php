<?php
/** Blox 编辑器模板目录与安全解析 API。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

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

/** @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string} */
$processTemplateDocument = static function (string $type, int $id, string $json): array {
    return BloxAreaDocument::isArea($type)
        ? BloxAreaDocument::process($type, $json, 'tpl' . $id)
        : ($type === 'popup'
            ? BloxPopupDocument::process($json, 'tpl' . $id)
            : BloxDocumentPipeline::process($json, 'tpl' . $id));
};

$templateRevisionMatches = static function (string $type, string $json, string $revision): bool {
    return $type === 'popup'
        ? BloxPopupDocument::revisionMatches($json, $revision)
        : BloxDocumentPipeline::revisionMatches($json, $revision);
};

$templateFingerprint = static function (string $type, string $json): string {
    return $type === 'popup'
        ? BloxPopupDocument::fingerprint($json)
        : BloxDocumentPipeline::fingerprint($json);
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
        $revisionMatches = $templateRevisionMatches($type, $currentDraft, $baseRevision);
        if ($baseRevision !== '' && !$revisionMatches) {
            error(__('blox_save_conflict'), 409);
        }
        $processed = $processTemplateDocument($type, $id, (string) post('blocks_data', '[]'));
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
            'base_revision' => $templateFingerprint($type, $processed['json']),
            'return_receipt' => BloxAreaEditorTarget::issueReturnReceipt('draft'),
        ]);
    }
    if ($action === 'save_section' && $method === 'POST') {
        // r14 画布「另存为区块模板」：客户端只发 section JSON + 名称，服务端组
        // 标准模板包走 Importer 安全链（危险字段/元素白名单/插件依赖/Pipeline 校验
        // 与文件导入完全一致——画布选区不是绕过安检的后门）。发布后目录立即可插回。
        requireBloxTemplateTypePermission('section');
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
            'metadata' => [
                'page_types' => [trim((string) post('page_intent', 'general'))],
                'priority' => 60,
            ],
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
                'metadata' => BloxSectionMetadata::normalize([
                    'page_types' => [trim((string) post('page_intent', 'general'))],
                    'priority' => 60,
                ]),
                'updated_at' => time(),
            ],
        ]);
    }
    if ($action === 'save_area_copy' && $method === 'POST') {
        verifyCsrf();
        $type = strtolower(trim((string) post('type', '')));
        if (!in_array($type, ['header', 'footer'], true)) {
            error(__('blox_tpl_bad_type_short'));
        }
        $requireTemplateLicense($type);
        requireBloxTemplateTypePermission($type);
        $name = mb_substr(trim((string) post('name', '')), 0, 150);
        if ($name === '') {
            error(__('blox_tpl_name_required'));
        }
        $processed = $processTemplateDocument($type, 0, (string) post('blocks_data', '[]'));
        $id = bloxTemplateModel()->createDraft(
            $type,
            $name,
            $processed['json'],
            'user',
            BloxDocumentPipeline::SCHEMA_VERSION,
            BloxTemplateImporter::deriveRequirements($processed['sections']),
            '',
            (int) ($_SESSION['admin_id'] ?? 0),
            'editor-copy'
        );
        adminLog('blox_template', 'save_area_copy', '另存 Blox ' . $type . ' 模板 #' . $id . ' ' . $name);
        success(['id' => $id, 'edit_url' => '/admin/blox_editor.php?template=' . $id]);
    }
    if ($action === 'publish' && $method === 'POST') {
        verifyCsrf();
        $id = (int) post('id', '0');
        $row = bloxTemplateModel()->findForExport($id);
        if (!$row) {
            error(__('blox_tpl_not_found'));
        }
        $type = (string) ($row['type'] ?? '');
        $requireTemplateLicense($type);
        requireBloxTemplateTypePermission($type);

        // 发布以当前画布为准，不再要求用户先手动保存。旧客户端不传 blocks_data
        // 时仍兼容原来的“发布现有草稿”；新客户端则在同一事务中保存并发布。
        $currentDraftRaw = (string) ($row['draft_data'] ?? '');
        $currentDraft = trim($currentDraftRaw) !== '' ? $currentDraftRaw : '[]';
        $processed = null;
        if (array_key_exists('blocks_data', $_POST)) {
            $baseRevision = trim((string) post('base_revision', ''));
            if ($baseRevision !== '' && !$templateRevisionMatches($type, $currentDraft, $baseRevision)) {
                error(__('blox_save_conflict'), 409);
            }
            $processed = $processTemplateDocument($type, $id, (string) post('blocks_data', '[]'));
        }
        $replaceThemeArea = strtolower(trim((string) post('replace_theme_area', '')));
        $replaceTheme = $replaceThemeArea !== '';
        if ($replaceTheme && ($replaceThemeArea !== $type
            || !in_array($type, ['header', 'footer'], true)
            || !BloxAreaEditorTarget::isThemeFallbackTemplate($row, $type))) {
            error(__('blox_invalid_action'));
        }

        $isGlobalFallback = static function (mixed $rawConditions): bool {
            $conditions = BloxAreaResolver::parse($rawConditions);
            if ($conditions === []) {
                return !BloxAreaResolver::hasConditionInput($rawConditions);
            }
            return array_reduce(
                $conditions,
                static fn (bool $carry, array $condition): bool => $carry
                    && $condition['main'] === 'any' && !$condition['exclude'],
                true
            );
        };
        if ($replaceTheme && !$isGlobalFallback($row['conditions'] ?? null)) {
            error(__('blox_invalid_action'));
        }

        $replacementIds = [];
        $replacementNames = [];
        if ($replaceTheme) {
            foreach (bloxTemplateModel()->publishedAreaTemplates($type) as $other) {
                $otherId = (int) ($other['id'] ?? 0);
                if ($otherId < 1 || $otherId === $id) {
                    continue;
                }
                $rawConditions = $other['conditions'] ?? null;
                if (!$isGlobalFallback($rawConditions)) {
                    continue;
                }
                $replacementIds[] = $otherId;
                $replacementNames[] = (string) ($other['name'] ?? ('#' . $otherId)) . ' (#' . $otherId . ')';
            }
        }

        $conflictMessage = $replaceTheme && $replacementIds !== []
            ? __('blox_tpl_replace_theme_conflict', ['templates' => implode('、', $replacementNames)])
            : ($replaceTheme ? '' : BloxAreaConditions::publishConflictMessage($row));
        if ($conflictMessage !== '' && (string) post('confirm_conflict', '') !== '1') {
            error($conflictMessage, 409);
        }

        db()->beginTransaction();
        try {
            if ($processed !== null) {
                bloxTemplateModel()->updateDraft(
                    $id,
                    $processed['json'],
                    BloxTemplateImporter::deriveRequirements($processed['sections']),
                    $currentDraftRaw
                );
            }
            if ($replaceTheme) {
                foreach ($replacementIds as $replacementId) {
                    bloxTemplateModel()->unpublish($replacementId);
                }
            }
            bloxTemplateModel()->publishDraft($id);
            if ($replaceTheme) {
                settingModel()->saveBatch([
                    $type === 'header' ? 'blox_custom_header_enabled' : 'blox_custom_footer_enabled' => '1',
                ]);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            if ($e instanceof RuntimeException && $e->getMessage() === __('blox_save_conflict')) {
                error($e->getMessage(), 409);
            }
            throw $e;
        }

        $publishedDocument = $processed !== null ? $processed['json'] : $currentDraft;
        $response = [
            'id' => $id,
            'base_revision' => $templateFingerprint($type, $publishedDocument),
            'return_receipt' => BloxAreaEditorTarget::issueReturnReceipt('published'),
        ];
        if ($replaceTheme) {
            $response['activated_area'] = $type;
            $response['replaced_ids'] = $replacementIds;
            adminLog(
                'blox_template',
                'publish_replace_theme',
                '发布并启用 Blox ' . $type . ' #' . $id
                    . ($replacementIds === [] ? '' : '，停用冲突模板 #' . implode(',#', $replacementIds))
            );
        } else {
            adminLog(
                'blox_template',
                $processed !== null ? 'save_and_publish' : 'publish',
                ($processed !== null ? '保存并发布' : '发布') . ' Blox 模板 #' . $id
            );
        }
        success($response);
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
