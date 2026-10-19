<?php
/** 2.1.1：站点健康按级别统计近几天的错误日志条数（后台脚本错误写成级别 JS）。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ErrorHandler.php';

final class ErrorHandlerRecentCountTest extends TestCase
{
    public function testCountsOnlyTheLevelSinceTheCutoff(): void
    {
        $log = implode("\n", [
            '[2026-10-10 08:00:00] [JS] [blox-editor /admin/blox_editor.php?id=2] old one at /assets/a.js:1 {POST /admin/client_error_api.php} {admin#1}',
            '[2026-10-18 09:00:00] [JS] [blox-editor] Cannot read properties of null at /assets/alpinejs/alpine.min.js:5 {POST /x} {admin#1}',
            '    at foo (bar.js:1:2)',
            '[2026-10-18 09:05:00] [E_WARNING] Undefined index at /var/www/x.php:3 {GET /} {-}',
            '[2026-10-19 10:00:00] [JS] [admin] [JS] looks like a level inside the text at :0 {POST /x} {admin#1}',
        ]) . "\n";
        $since = (int) strtotime('2026-10-12 00:00:00');
        self::assertSame(2, ErrorHandler::countEntries($log, 'JS', $since), '只数开头的级别，正文里的 [JS] 不算');
        self::assertSame(1, ErrorHandler::countEntries($log, 'E_WARNING', $since));
        self::assertSame(3, ErrorHandler::countEntries($log, 'JS', 0));
        self::assertSame(0, ErrorHandler::countEntries('', 'JS', 0));
    }
}
