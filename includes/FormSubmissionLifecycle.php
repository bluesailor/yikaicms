<?php
declare(strict_types=1);

final class FormSubmissionLifecycle
{
    /**
     * Keep the guard reservation and database row consistent. Commit happens inside the
     * guard callback, so a failed insert/commit restores the reservation before unlocking.
     * @return array{reason:string,retry:int,id:int}
     */
    public static function persist(
        FormSpamGuard $guard,
        string $ip,
        array $fingerprint,
        int $limit,
        int $window,
        callable $persist,
        callable $begin,
        callable $commit,
        callable $rollback
    ): array {
        if (!$begin()) throw new RuntimeException('Form transaction failed');
        try {
            $accepted = $guard->submit($ip, $fingerprint, $limit, $window,
                static function () use ($persist, $commit): int {
                    $id = (int) $persist();
                    if ($id <= 0 || !$commit()) throw new RuntimeException('Form persistence failed');
                    return $id;
                });
            if ($accepted['reason'] !== '') $rollback();
            return $accepted;
        } catch (Throwable $error) {
            $rollback();
            throw $error;
        }
    }

    /** Post-commit integrations are best-effort and can never reverse an accepted submission. */
    public static function afterCommit(int $formId, array $data, callable $hook, callable $notify, ?callable $logger = null): void
    {
        $logger ??= static fn(string $message): bool => error_log($message);
        try {
            $hook($formId, $data);
        } catch (Throwable $error) {
            $logger('Form submitted hook failed: ' . get_class($error));
        }
        try {
            $notify($data);
        } catch (Throwable $error) {
            $logger('Form notification failed: ' . get_class($error));
        }
    }
}
