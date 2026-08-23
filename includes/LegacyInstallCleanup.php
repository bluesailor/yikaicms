<?php
/**
 * Removes legacy unauthenticated installer upgrade entry points.
 */
declare(strict_types=1);

final class LegacyInstallCleanup
{
    private const PATHS = [
        'install/upgrade.php',
        'install/run_upgrade.php',
    ];

    /**
     * @param null|callable(string):bool $unlinker
     * @param null|callable(string):void $logger
     * @return array{removed:list<string>,failed:list<string>,skipped:bool}
     * @psalm-suppress PossiblyUnusedReturnValue Admin templates intentionally discard the report.
     */
    public static function run(
        string $root,
        ?callable $unlinker = null,
        ?callable $logger = null,
        bool $logFailures = true
    ): array {
        $remove = $unlinker ?? static fn(string $path): bool => @unlink($path);
        $writeLog = $logger ?? static function (string $message): void {
            error_log($message);
        };
        $removed = [];
        $failed = [];

        foreach (self::PATHS as $relativePath) {
            $path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($path)) {
                continue;
            }
            if ($remove($path)) {
                $removed[] = $relativePath;
                continue;
            }
            $failed[] = $relativePath;
            if ($logFailures) {
                $writeLog('[security] unable to remove legacy install upgrade entry: ' . $relativePath);
            }
        }

        return ['removed' => $removed, 'failed' => $failed, 'skipped' => false];
    }

    /**
     * The admin fallback runs at most once per interval. The marker is written before
     * deletion so a read-only install directory cannot turn every admin request into a log write.
     *
     * @param null|callable(string):bool $unlinker
     * @param null|callable(string):void $logger
     * @return array{removed:list<string>,failed:list<string>,skipped:bool}
     * @psalm-suppress PossiblyUnusedReturnValue Admin templates intentionally discard the report.
     */
    public static function runThrottled(
        string $root,
        string $markerFile,
        int $interval = 86400,
        ?callable $unlinker = null,
        ?callable $logger = null,
        ?int $now = null
    ): array {
        $time = $now ?? time();
        $sessionAt = isset($_SESSION['legacy_install_cleanup_at'])
            ? (int) $_SESSION['legacy_install_cleanup_at'] : 0;
        $fileAt = is_file($markerFile) ? (int) @filemtime($markerFile) : 0;
        if (max($sessionAt, $fileAt) > $time - max(60, $interval)) {
            return ['removed' => [], 'failed' => [], 'skipped' => true];
        }

        $_SESSION['legacy_install_cleanup_at'] = $time;
        $markerDir = dirname($markerFile);
        $markerReady = (is_dir($markerDir) || @mkdir($markerDir, 0755, true) || is_dir($markerDir))
            && @file_put_contents($markerFile, (string) $time, LOCK_EX) !== false;

        return self::run($root, $unlinker, $logger, $markerReady);
    }
}
