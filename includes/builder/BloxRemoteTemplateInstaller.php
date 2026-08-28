<?php
/** Blox 远程模板安装：授权下载、完整性校验与本地落库的单一入口。 */

declare(strict_types=1);

final class BloxRemoteTemplateInstaller
{
    private BloxRemoteTemplateProvider $provider;

    public function __construct(?BloxRemoteTemplateProvider $provider = null)
    {
        $this->provider = $provider ?? new BloxRemoteTemplateProvider();
    }

    /** @return array{id:int,type:string,name:string,sections:int,previous_sections:int,version:string,updated:bool,backup_created:bool} */
    public function install(string $slug, int $adminId = 0): array
    {
        // Provider 是授权、服务期、hash、RSA 签名与包内身份的权威闸口。
        $stateModel = bloxRemoteTemplateStateModel();
        if (!$stateModel->tableReady()) {
            throw new RuntimeException(__('blox_tpl_remote_state_table_missing'));
        }
        $package = $this->provider->fetchVerifiedPackage($slug);
        $json = $package['json'];
        $version = trim((string) ($package['item']['version'] ?? ''));
        $prepared = BloxTemplateImporter::prepare($json);
        $existing = bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug]);

        if (!$existing) {
            $created = BloxTemplateImporter::importJson($json, $adminId, 'remote', $slug);
            try {
                $stateModel->rememberInstall((int) $created['id'], $version);
            } catch (Throwable $e) {
                db()->delete('blox_templates', 'id = ?', [(int) $created['id']]);
                throw $e;
            }
            return $created + [
                'previous_sections' => 0,
                'version' => $version,
                'updated' => false,
                'backup_created' => false,
            ];
        }

        $id = (int) $existing['id'];
        $existingDraft = (string) ($existing['draft_data'] ?? '');
        $previousSections = self::sectionCount($existingDraft);
        db()->beginTransaction();
        try {
            // 保留已发布文档、显示条件和发布状态；更新前草稿与依赖留作一次回退点。
            $stateModel->stageUpdate(
                $id,
                $version,
                $existingDraft,
                (string) ($existing['requirements'] ?? ''),
                (string) ($existing['metadata'] ?? '')
            );
            bloxTemplateModel()->updateDraft(
                $id,
                $prepared['draft_json'],
                $prepared['requirements'],
                $existingDraft
            );
            bloxTemplateModel()->saveMetadata($id, $prepared['metadata']);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return [
            'id' => $id,
            'type' => $prepared['type'],
            'name' => $prepared['name'],
            'sections' => count($prepared['sections']),
            'previous_sections' => $previousSections,
            'version' => $version,
            'updated' => true,
            'backup_created' => true,
        ];
    }

    /** @return array{id:int,version:string,sections:int} */
    public function rollback(int $templateId): array
    {
        $stateModel = bloxRemoteTemplateStateModel();
        if (!$stateModel->tableReady()) {
            throw new RuntimeException(__('blox_tpl_remote_state_table_missing'));
        }
        $template = bloxTemplateModel()->findForExport($templateId);
        $state = $stateModel->forTemplate($templateId);
        if (!$template || (string) ($template['source'] ?? '') !== 'remote') {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
        $backupDraft = is_array($state) ? (string) ($state['backup_draft'] ?? '') : '';
        if ($backupDraft === '') {
            throw new RuntimeException(__('blox_tpl_remote_no_backup'));
        }

        $requirements = self::decodeArray((string) ($state['backup_requirements'] ?? ''));
        $metadata = self::decodeArray((string) ($state['backup_metadata'] ?? ''));
        $currentDraft = (string) ($template['draft_data'] ?? '');
        $restoredVersion = trim((string) ($state['backup_version'] ?? ''));
        db()->beginTransaction();
        try {
            bloxTemplateModel()->updateDraft($templateId, $backupDraft, $requirements, $currentDraft);
            bloxTemplateModel()->saveMetadata($templateId, $metadata);
            $stateModel->finishRollback($templateId, $restoredVersion);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return [
            'id' => $templateId,
            'version' => $restoredVersion,
            'sections' => self::sectionCount($backupDraft),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeArray(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function sectionCount(string $json): int
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 0;
        }
        return is_array($decoded) ? count(BloxDocumentPipeline::extractSections($decoded)) : 0;
    }
}
