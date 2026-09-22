<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/FormSubmissionLifecycle.php';
require_once ROOT_PATH . '/includes/FormSpamGuard.php';

final class FormSubmissionLifecycleTest extends TestCase
{
    public function testCommitFailureRollsBackDatabaseAndGuardReservation(): void
    {
        $directory = sys_get_temp_dir() . '/yk-form-life-' . bin2hex(random_bytes(5));
        $guard = new FormSpamGuard($directory, 'secret');
        $inTransaction = false;
        $rows = [];
        $begin = static function () use (&$inTransaction): bool { $inTransaction = true; return true; };
        $rollback = static function () use (&$inTransaction, &$rows): void { if ($inTransaction) $rows = []; $inTransaction = false; };
        try {
            FormSubmissionLifecycle::persist($guard, 'ip', ['content' => 'same'], 2, 60,
                static function () use (&$rows): int { $rows[] = 1; return 1; }, $begin,
                static function (): bool { throw new RuntimeException('commit failed'); }, $rollback);
            self::fail('Commit failure accepted');
        } catch (RuntimeException $error) {
            self::assertSame('commit failed', $error->getMessage());
        }
        self::assertSame([], $rows);

        $result = FormSubmissionLifecycle::persist($guard, 'ip', ['content' => 'same'], 2, 60,
            static function () use (&$rows): int { $rows[] = 2; return 2; }, $begin,
            static function () use (&$inTransaction): bool { $inTransaction = false; return true; }, $rollback);
        self::assertSame(2, $result['id']);
        foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
        rmdir($directory);
    }

    public function testHookAndNotificationFailuresAreIsolatedAfterCommit(): void
    {
        $events = [];
        FormSubmissionLifecycle::afterCommit(7, ['name' => 'Saved'],
            static function () use (&$events): void { $events[] = 'hook'; throw new RuntimeException('crm down'); },
            static function () use (&$events): void { $events[] = 'mail'; throw new RuntimeException('smtp down'); },
            static function (string $message) use (&$events): void { $events[] = $message; }
        );
        self::assertSame('hook', $events[0]);
        self::assertStringContainsString('hook failed', $events[1]);
        self::assertSame('mail', $events[2]);
        self::assertStringContainsString('notification failed', $events[3]);
    }
}
