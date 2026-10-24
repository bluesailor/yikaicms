<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecurityHeaders;

require_once ROOT_PATH . '/includes/SecurityHeaders.php';

final class SecurityHeadersTest extends TestCase
{
    public function testPagesRefuseCrossOriginFraming(): void
    {
        $lines = SecurityHeaders::lines();

        self::assertContains('X-Frame-Options: SAMEORIGIN', $lines);
        self::assertContains("Content-Security-Policy: frame-ancestors 'self'", $lines);
        self::assertContains('X-Content-Type-Options: nosniff', $lines);
        self::assertContains('Referrer-Policy: strict-origin-when-cross-origin', $lines);
    }

    public function testHeadersDoNotRestrictScriptsOrEmbeds(): void
    {
        foreach (SecurityHeaders::lines() as $line) {
            self::assertStringNotContainsString('script-src', $line, 'Admin and builder pages rely on inline scripts');
            self::assertStringNotContainsStringIgnoringCase('Permissions-Policy', $line, 'Contact maps and video embeds need these features');
        }
    }

    /** 安装器模板 config.sample.php 不带安全头，所以必须由每个入口都会加载的 functions.php 发出。 */
    public function testEveryEntryPointSendsTheHeaders(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        $call = strpos($functions, 'SecurityHeaders::send();');

        self::assertNotFalse($call, 'functions.php must send the security headers');
        self::assertLessThan(strpos($functions, "\nfunction "), $call, 'Headers must go out before any helper can print');
    }
}
