<?php
/**
 * Command: site:health
 */
declare(strict_types=1);

if (!defined('IK_CLI')) {
    return;
}

CLI::register('site:health', __('health_cli_desc'), function (array $args, array $opts): int {
    $checks = SiteHealth::runDirect();
    if (!empty($opts['remote'])) {
        $checks[] = SiteHealth::checkUpdateService();
    }
    $checks = SiteHealth::normalizeResults($checks);
    $summary = SiteHealth::summary($checks);

    if (!empty($opts['json'])) {
        CLI::out((string) json_encode(
            ['summary' => $summary, 'checks' => $checks],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));
    } else {
        foreach ($checks as $check) {
            $line = sprintf('[%s] %s: %s', strtoupper((string) $check['status']), $check['title'], $check['description']);
            if ($check['status'] === SiteHealth::CRITICAL) {
                CLI::err($line);
            } elseif ($check['status'] === SiteHealth::RECOMMENDED || $check['status'] === SiteHealth::UNKNOWN) {
                CLI::warn($line);
            } else {
                CLI::ok($line);
            }
        }
        CLI::out(sprintf(
            '%s: critical=%d recommended=%d good=%d unknown=%d',
            __('health_cli_summary'),
            $summary['critical'],
            $summary['recommended'],
            $summary['good'],
            $summary['unknown']
        ));
    }

    return $summary['critical'] > 0 ? 1 : 0;
}, ['usage' => 'site:health [--json] [--remote]']);
