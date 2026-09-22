<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteTemplatePluginDataTest extends TestCase
{
    public function testPublicPluginDataRoundtripPrivacyFailureRollbackAndRestore(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-plugin-probe.php'));
        self::assertSame("Site template plugin roundtrip passed\n", $output);
    }
}
