<?php
/**
 * 邮件可靠性层（roadmap #3）回归：
 *   - record 记成功/失败与原因，界面只取脱敏预览（正文全文不入展示）
 *   - 失败重试队列：退避窗口内不重试、达到上限不重试、重试复用原行递增 attempts
 *   - failureStreak 只统计开头连续段，成功一封即中断
 */

declare(strict_types=1);

// tests 不加载 includes/functions.php：按 bootstrap 的镜像模式提供发送桩。
// 必须是**全局命名空间**（测试类在 Yikai\Tests\Unit 下，直接写函数会落进测试命名空间）。
// 本文件是全仓唯一引用 sendMailRaw 的测试，无跨用例污染。
namespace {
    if (!function_exists('sendMailRaw')) {
        /**
         * 依次消费 $GLOBALS['yk_test_mail_outcomes'] 里的结果；队列空则视为成功。
         * @param array<int,string|bool> $attachments
         */
        function sendMailRaw(string $to, string $subject, string $body, array $attachments = []): bool|string
        {
            $GLOBALS['yk_test_mail_calls'][] = ['to' => $to, 'subject' => $subject];
            $next = array_shift($GLOBALS['yk_test_mail_outcomes']);
            return $next === null ? true : $next;
        }
    }
}

namespace Yikai\Tests\Unit {

use MailDelivery;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/MailDelivery.php';

final class MailDeliveryTest extends TestCase
{
    protected function schemaSql(): array
    {
        return ['CREATE TABLE IF NOT EXISTS mail_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            to_email TEXT NOT NULL,
            subject TEXT NOT NULL DEFAULT \'\',
            body TEXT NOT NULL DEFAULT \'\',
            preview TEXT NOT NULL DEFAULT \'\',
            status TEXT NOT NULL DEFAULT \'failed\',
            error TEXT NOT NULL DEFAULT \'\',
            attempts INTEGER NOT NULL DEFAULT 1,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        )'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['yk_test_mail_outcomes'] = [];
        $GLOBALS['yk_test_mail_calls'] = [];
    }

    private function row(int $id): array
    {
        return db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'mail_log WHERE id = ?', [$id]);
    }

    public function testRecordCapturesOutcomeAndSanitizedPreview(): void
    {
        MailDelivery::record('a@example.com', 'Hello', '<p>Hi <b>there</b> &amp; welcome</p>', true);
        MailDelivery::record('b@example.com', 'Broken', '<div>Body</div>', '连接SMTP服务器失败: timeout (110)');

        $rows = MailDelivery::recent(10);
        self::assertCount(2, $rows);

        $sent = $rows[1];
        self::assertSame('sent', $sent['status']);
        self::assertSame('', $sent['error']);
        self::assertSame('Hi there & welcome', $sent['preview'], '预览必须去标签、解实体并压空白');

        $failed = $rows[0];
        self::assertSame('failed', $failed['status']);
        self::assertStringContainsString('timeout', $failed['error']);
        // 列表查询不带 body：正文全文不进入界面渲染路径
        self::assertArrayNotHasKey('body', $failed);
    }

    public function testRetryRespectsBackoffAndAttemptLimit(): void
    {
        MailDelivery::record('a@example.com', 'One', 'body', 'fail');
        $id = (int) db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'mail_log LIMIT 1');

        // 刚失败：退避窗口内不重试
        $summary = MailDelivery::retryFailed(5);
        self::assertSame(0, $summary['retried'], '10 分钟退避窗口内不得重试');
        self::assertSame([], $GLOBALS['yk_test_mail_calls']);

        // 窗口过后重试成功：复用原行，attempts 递增，状态转 sent
        db()->update('mail_log', ['updated_at' => time() - MailDelivery::BACKOFF_SECONDS - 1], 'id = ?', [$id]);
        $GLOBALS['yk_test_mail_outcomes'] = [true];
        $summary = MailDelivery::retryFailed(5);
        self::assertSame(1, $summary['retried']);
        self::assertSame(1, $summary['sent']);
        $row = $this->row($id);
        self::assertSame('sent', $row['status']);
        self::assertSame(2, (int) $row['attempts']);
        self::assertSame('a@example.com', $GLOBALS['yk_test_mail_calls'][0]['to'], '重试原文原样重发');

        // 再次失败到上限：不再进入重试队列
        db()->update('mail_log', [
            'status' => 'failed', 'error' => 'still bad', 'attempts' => MailDelivery::MAX_ATTEMPTS,
            'updated_at' => time() - MailDelivery::BACKOFF_SECONDS - 1,
        ], 'id = ?', [$id]);
        $GLOBALS['yk_test_mail_outcomes'] = [true];
        $summary = MailDelivery::retryFailed(5);
        self::assertSame(0, $summary['retried'], '达到尝试上限后必须停止重试');
    }

    public function testRetryKeepsFailureReasonWhenStillBroken(): void
    {
        MailDelivery::record('c@example.com', 'Two', 'body', 'first failure');
        $id = (int) db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'mail_log LIMIT 1');
        db()->update('mail_log', ['updated_at' => time() - MailDelivery::BACKOFF_SECONDS - 1], 'id = ?', [$id]);

        $GLOBALS['yk_test_mail_outcomes'] = ['密码验证失败: 535 auth error'];
        $summary = MailDelivery::retryFailed(5);

        self::assertSame(1, $summary['still_failed']);
        $row = $this->row($id);
        self::assertSame('failed', $row['status']);
        self::assertStringContainsString('535', (string) $row['error'], '重试失败原因要覆盖旧原因');
        self::assertSame(2, (int) $row['attempts']);
    }

    public function testFailureStreakCountsOnlyLeadingFailures(): void
    {
        // 最早：成功；随后连续 2 次失败 → streak 应为 2（成功封顶不计入）
        MailDelivery::record('old@example.com', 'old', 'body', true);
        MailDelivery::record('a@example.com', 'f1', 'body', 'smtp down');
        MailDelivery::record('a@example.com', 'f2', 'body', 'smtp down');

        $info = MailDelivery::failureStreak();
        self::assertSame(2, $info['streak']);
        self::assertSame('smtp down', $info['last_error']);
        self::assertSame(1, $info['total_sent']);
        self::assertSame(2, $info['total_failed']);

        // 再成功一封：连续段清零，但累计失败仍可见
        MailDelivery::record('ok@example.com', 'ok', 'body', true);
        $info = MailDelivery::failureStreak();
        self::assertSame(0, $info['streak'], '最新一封成功后不得再报连败');
        self::assertSame('', $info['last_error']);
    }

    public function testPruneKeepsRecentRows(): void
    {
        for ($i = 0; $i < MailDelivery::KEEP_ROWS + 5; $i++) {
            MailDelivery::record('bulk@example.com', 'bulk ' . $i, 'body', true);
        }
        $count = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'mail_log');
        self::assertSame(MailDelivery::KEEP_ROWS + 5, $count, '构造量必须超过保留阈值');

        // 一小时内写入的行受保护（仍在退避窗口内的失败有重试价值）：prune 不动它们
        MailDelivery::prune();
        self::assertSame($count, (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'mail_log'));

        // 落到保留阈值之外且过了保护窗的旧行才会被清理，最终收敛到 KEEP_ROWS
        db()->execute('UPDATE ' . DB_PREFIX . 'mail_log SET updated_at = ?', [time() - 7200]);
        MailDelivery::prune();
        self::assertSame(
            MailDelivery::KEEP_ROWS,
            (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'mail_log')
        );
    }
}

}   // namespace Yikai\Tests\Unit

