<?php

declare(strict_types=1);

return [
    'id' => '20260823_redact_admin_log_request_data',
    'title' => '清理历史后台日志中的敏感请求数据',
    'desc' => '清空历史 POST 快照并脱敏 URL 查询参数；新日志会在写入前自动脱敏。',
    'title_en' => 'Redact sensitive data in historical admin logs',
    'title_ja' => '過去の管理ログに含まれる機密データを消去',
    'desc_en' => 'Clears historical POST snapshots and redacts URL query parameters. New logs are sanitized before storage.',
    'desc_ja' => '過去の POST スナップショットを消去し、URL クエリをマスキングします。新しいログは保存前に処理されます。',
    'check' => static function (): bool {
        if (!db()->tableExists('admin_logs') || !db()->tableExists('settings')) {
            return true;
        }
        return db()->fetchOne(
            'SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ? AND `value` = ? LIMIT 1',
            ['migration_20260823_admin_log_redacted', '1']
        ) !== null;
    },
    'sqls' => [],
    'php' => static function (): string {
        $lastId = 0;
        $updatedUrls = 0;
        do {
            $rows = db()->fetchAll(
                'SELECT id, url FROM ' . DB_PREFIX . 'admin_logs WHERE id > ? ORDER BY id ASC LIMIT 500',
                [$lastId]
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $url = AdminLogSanitizer::url((string) ($row['url'] ?? ''));
                db()->update('admin_logs', ['request_data' => '{}', 'url' => $url], 'id = ?', [$lastId]);
                $updatedUrls++;
            }
        } while (count($rows) === 500);

        settingModel()->set('migration_20260823_admin_log_redacted', '1', 'system');
        return "已清理 {$updatedUrls} 条后台日志";
    },
];
