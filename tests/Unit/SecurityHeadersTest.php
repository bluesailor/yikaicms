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

    /** 静态页由 Apache 直出、不经过 PHP：html/.htaccess 补同一组头，没有 mod_headers 时不能 500 */
    public function testApacheRulesForStaticPagesMirrorTheHeaders(): void
    {
        $rules = SecurityHeaders::apacheRules();

        self::assertStringContainsString('<IfModule mod_headers.c>', $rules);
        foreach (SecurityHeaders::lines() as $line) {
            [$name, $value] = explode(': ', $line, 2);
            self::assertStringContainsString('Header set ' . $name . ' "' . $value . '"', $rules);
        }
        $static = (string) file_get_contents(ROOT_PATH . '/includes/StaticHtml.php');
        self::assertStringContainsString('self::writeServerHeaders();', $static, 'Every static write must keep html/.htaccess in place');
    }

    /** nginx 读不了 .htaccess：模板里每个直出 html/ 的 location 都要带齐同一组 add_header */
    public function testNginxStaticLocationsSendTheSameHeaders(): void
    {
        $conf = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/deploy/nginx-server.conf'));
        preg_match_all('/^location [^\n]*\{\n(.*?)^\}/ms', $conf, $blocks);
        $static = array_values(array_filter($blocks[1], static fn (string $body): bool => str_contains($body, 'try_files /html')));

        self::assertCount(4, $static, 'Language .html, .html, home and default locations serve html/');
        foreach ($static as $body) {
            foreach (SecurityHeaders::lines() as $line) {
                [$name, $value] = explode(': ', $line, 2);
                self::assertStringContainsString('add_header ' . $name . ' "' . $value . '";', $body);
            }
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
