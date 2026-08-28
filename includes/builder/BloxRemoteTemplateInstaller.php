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

    /** @return array{id:int,type:string,name:string,sections:int,updated:bool} */
    public function install(string $slug, int $adminId = 0): array
    {
        // Provider 是授权、服务期、hash、RSA 签名与包内身份的权威闸口。
        $json = $this->provider->fetchPackageJson($slug);
        $prepared = BloxTemplateImporter::prepare($json);
        $existing = bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug]);

        if (!$existing) {
            $created = BloxTemplateImporter::importJson($json, $adminId, 'remote', $slug);
            return $created + ['updated' => false];
        }

        $id = (int) $existing['id'];
        db()->beginTransaction();
        try {
            // 保留已发布文档、显示条件和发布状态，只替换可继续审阅的草稿。
            bloxTemplateModel()->updateDraft($id, $prepared['draft_json'], $prepared['requirements']);
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
            'updated' => true,
        ];
    }
}
