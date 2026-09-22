<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/FormSubmissionNonce.php';

final class FormSubmissionNonceTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testNonceIsSingleUseAndBoundToItsForm(): void
    {
        $nonce = FormSubmissionNonce::issue('contact', 'secret', 1000);
        self::assertNotSame('', $nonce);
        self::assertTrue(FormSubmissionNonce::consume('contact', $nonce, 'secret', 1001));
        self::assertFalse(FormSubmissionNonce::consume('contact', $nonce, 'secret', 1001));

        $crossForm = FormSubmissionNonce::issue('contact', 'secret', 1000);
        self::assertFalse(FormSubmissionNonce::consume('product-inquiry', $crossForm, 'secret', 1001));
        self::assertFalse(FormSubmissionNonce::consume('contact', $crossForm, 'secret', 1001), 'Wrong-form attempt must burn the nonce');
    }

    public function testExpiredAndMalformedNonceFailClosed(): void
    {
        $nonce = FormSubmissionNonce::issue('contact', 'secret', 1000);
        self::assertFalse(FormSubmissionNonce::consume('contact', $nonce, 'secret', 1901));
        self::assertFalse(FormSubmissionNonce::consume('contact', '', 'secret', 1000));
        self::assertSame('', FormSubmissionNonce::issue('../contact', 'secret', 1000));
    }

    public function testConcurrentProcessesCanConsumeTheSessionNonceOnlyOnce(): void
    {
        $directory = sys_get_temp_dir() . '/yikai-form-nonce-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $previousPath = session_save_path();
        $previousId = session_id();
        $sessionId = 'nonce' . bin2hex(random_bytes(12));
        try {
            session_save_path($directory);
            session_id($sessionId);
            session_start();
            $nonce = FormSubmissionNonce::issue('contact', 'test-secret', 1000);
            session_write_close();

            $workers = [];
            for ($i = 0; $i < 8; $i++) {
                $process = proc_open([PHP_BINARY, ROOT_PATH . '/tests/fixtures/form-submission-nonce-worker.php', $directory, $sessionId, $nonce],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }

            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
                self::assertSame('', $errors);
            }
            self::assertSame(1, count(array_filter($results, static fn(string $value): bool => $value === 'accepted')));
            self::assertSame(7, count(array_filter($results, static fn(string $value): bool => $value === 'rejected')));
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            session_save_path($previousPath);
            session_id($previousId);
            foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
            if (is_dir($directory)) rmdir($directory);
        }
    }
}
