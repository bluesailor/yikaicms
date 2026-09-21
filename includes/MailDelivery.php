<?php

/**
 * 邮件投递日志与失败重试（邮件可靠性层）。
 *
 * 设计边界：
 *   - sendMail() 的薄包装自动记日志（见 functions.php），所有调用方——含插件——零改动入账；
 *   - 一行 = 一封信：重试复用原行递增 attempts（上限 MAX_ATTEMPTS），连续失败按
 *     「最近的信件结果」计算；
 *   - 隐私边界：正文全文只落库供重发使用，界面与摘要只出示脱敏预览
 *     （strip_tags + 实体解码 + 压空白 + 截断），日志表不外泄；
 *   - 日志失败绝不影响发信本身（record 内部吞异常，表未建/无 DB 时静默跳过）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class MailDelivery
{
    /** 单封邮件的最大尝试次数（含首发）。 */
    public const MAX_ATTEMPTS = 3;

    /** 重试退避基数：第 n 次尝试后至少等 n × BACKOFF_SECONDS 再重试。 */
    public const BACKOFF_SECONDS = 600;

    /** 日志保留行数（超出随机概率修剪）。 */
    public const KEEP_ROWS = 1000;

    /** 建表（幂等，双驱动）。表同时由迁移 20260921_mail_log 在升级路径创建。 */
    public static function ensureTable(): void
    {
        $table = DB_PREFIX . 'mail_log';
        if (db()->isSqlite()) {
            db()->execute('CREATE TABLE IF NOT EXISTS "' . $table . '" (
                "id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "to_email" TEXT NOT NULL,
                "subject" TEXT NOT NULL DEFAULT \'\',
                "body" TEXT NOT NULL DEFAULT \'\',
                "preview" TEXT NOT NULL DEFAULT \'\',
                "status" TEXT NOT NULL DEFAULT \'failed\',
                "error" TEXT NOT NULL DEFAULT \'\',
                "attempts" INTEGER NOT NULL DEFAULT 1,
                "created_at" INTEGER NOT NULL DEFAULT 0,
                "updated_at" INTEGER NOT NULL DEFAULT 0
            )');
            return;
        }
        db()->execute('CREATE TABLE IF NOT EXISTS `' . $table . '` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /**
     * 记录一次发送尝试。日志层的任何异常都不许影响发信。
     *
     * @param string|bool $result sendMail 的返回值：true=成功，string=失败原因
     */
    public static function record(string $to, string $subject, string $body, bool|string $result): void
    {
        try {
            if (!function_exists('db') || !db()->tableExists('mail_log')) {
                return;
            }
            $now = time();
            db()->insert('mail_log', [
                'to_email' => mb_substr(trim($to), 0, 190),
                'subject' => mb_substr(trim($subject), 0, 190),
                'body' => $body,
                'preview' => self::preview($body),
                'status' => $result === true ? 'sent' : 'failed',
                'error' => mb_substr($result === true ? '' : (string) $result, 0, 190),
                'attempts' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (mt_rand(1, 20) === 1) {
                self::prune();
            }
        } catch (Throwable $e) {
            // 发信是主路径，日志是旁路：吞掉
        }
    }

    /**
     * 重试到期的失败邮件（cron 任务体，也可后台手动触发）。
     * 一行 = 一封信：重试复用原行（attempts+1），正文取自落库全文；
     * 直接调 sendMailRaw() 而非 sendMail()，避免同一封信重复记行。
     * @return array{retried:int,sent:int,still_failed:int}
     */
    public static function retryFailed(int $limit = 5): array
    {
        $summary = ['retried' => 0, 'sent' => 0, 'still_failed' => 0];
        if (!function_exists('db') || !db()->tableExists('mail_log')) {
            return $summary;
        }
        $rows = db()->fetchAll(
            'SELECT * FROM ' . DB_PREFIX . 'mail_log'
            . ' WHERE status = \'failed\' AND attempts < ' . self::MAX_ATTEMPTS
            . ' AND updated_at < ' . (time() - self::BACKOFF_SECONDS)
            . ' ORDER BY updated_at ASC LIMIT ' . max(1, min(50, $limit))
        );
        foreach ($rows as $row) {
            $summary['retried']++;
            $result = sendMailRaw(
                (string) $row['to_email'],
                (string) $row['subject'],
                (string) $row['body']
            );
            db()->update('mail_log', [
                'status' => $result === true ? 'sent' : 'failed',
                'error' => mb_substr($result === true ? '' : (string) $result, 0, 190),
                'attempts' => (int) $row['attempts'] + 1,
                'updated_at' => time(),
            ], 'id = ?', [(int) $row['id']]);
            if ($result === true) {
                $summary['sent']++;
            } else {
                $summary['still_failed']++;
            }
        }
        return $summary;
    }

    /**
     * 连续失败情况（站点健康告警用）：streak 只数**最新一段**连续失败——
     * 最新的信一旦成功就归零；totals 仍按取到的窗口全量统计。
     * @return array{streak:int,last_error:string,last_at:int,total_sent:int,total_failed:int}
     */
    public static function failureStreak(): array
    {
        $info = ['streak' => 0, 'last_error' => '', 'last_at' => 0, 'total_sent' => 0, 'total_failed' => 0];
        if (!function_exists('db') || !db()->tableExists('mail_log')) {
            return $info;
        }
        $rows = db()->fetchAll(
            'SELECT status, error, updated_at FROM ' . DB_PREFIX . 'mail_log'
            . ' WHERE status IN (\'sent\', \'failed\') ORDER BY id DESC LIMIT 50'
        );
        $leading = true;
        foreach ($rows as $row) {
            if ((string) $row['status'] === 'sent') {
                $info['total_sent']++;
                $leading = false;   // 连续段到此为止；更早的失败不再计入 streak
                continue;
            }
            $info['total_failed']++;
            if (!$leading) {
                continue;
            }
            if ($info['streak'] === 0) {
                $info['last_error'] = (string) ($row['error'] ?? '');
                $info['last_at'] = (int) ($row['updated_at'] ?? 0);
            }
            $info['streak']++;
            if ($info['streak'] >= 20) {
                break;
            }
        }
        return $info;
    }

    /** @return list<array<string,mixed>> 最近的投递记录（界面展示，只带脱敏预览）。 */
    public static function recent(int $limit = 50): array
    {
        if (!function_exists('db') || !db()->tableExists('mail_log')) {
            return [];
        }
        return db()->fetchAll(
            'SELECT id, to_email, subject, preview, status, error, attempts, created_at, updated_at'
            . ' FROM ' . DB_PREFIX . 'mail_log ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    /** 超量修剪：只保留最近 KEEP_ROWS 行（body 留着占地，旧失败也无重试价值）。 */
    public static function prune(): void
    {
        if (!function_exists('db') || !db()->tableExists('mail_log')) {
            return;
        }
        $threshold = (int) db()->fetchColumn(
            'SELECT id FROM ' . DB_PREFIX . 'mail_log ORDER BY id DESC LIMIT 1 OFFSET ' . (self::KEEP_ROWS - 1)
        );
        if ($threshold > 0) {
            db()->execute('DELETE FROM ' . DB_PREFIX . 'mail_log WHERE id < ' . $threshold . ' AND updated_at < ' . (time() - 3600));
        }
    }

    /** 脱敏预览：去标签、解实体、压空白、截断。 */
    public static function preview(string $body): string
    {
        $text = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';
        $text = trim($text);
        return mb_substr($text, 0, 200);
    }
}
