<?php
/** Mail delivery log: per-message outcome, failure reason and retry attempts (roadmap #3). */

declare(strict_types=1);

return [
    'id'    => '20260921_mail_log',
    'title' => '邮件投递日志',
    'desc'  => '新增 mail_log 表：记录每封邮件的收件人、主题、脱敏预览、成功/失败与原因、重试次数。失败邮件由内置 cron 任务自动重试（上限 3 次，10 分钟退避），后台邮件设置页可查看日志并手动重试。',
    'title_en' => 'Mail delivery log',
    'title_ja' => 'メール送信ログ',
    'desc_en' => 'Adds the mail_log table: recipient, subject, sanitized preview, outcome with failure reason, and retry attempts per message. Failed mail is retried automatically by a built-in cron task (3 attempts, 10-minute backoff); the email settings page shows the log and offers manual retry.',
    'desc_ja' => 'mail_log テーブルを追加します：宛先・件名・マスク済みプレビュー・成否と失敗理由・再試行回数を記録します。失敗したメールは内蔵 cron タスクで自動再試行（上限 3 回、10 分バックオフ）され、メール設定画面からログ確認と手動再試行が可能です。',
    'check' => static fn (): bool => db()->tableExists('mail_log'),
    'sqls' => [
        'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'mail_log` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `to_email` varchar(191) NOT NULL,
            `subject` varchar(500) NOT NULL DEFAULT \'\',
            `body` mediumtext NOT NULL,
            `preview` varchar(600) NOT NULL DEFAULT \'\',
            `status` varchar(16) NOT NULL DEFAULT \'failed\',
            `error` varchar(500) NOT NULL DEFAULT \'\',
            `attempts` int(11) UNSIGNED NOT NULL DEFAULT 1,
            `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `idx_mail_log_status` (`status`, `updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ],
];
