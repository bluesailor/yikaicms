<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/SiteTemplateData.php';

final class SiteTemplateSchemaContractTest extends TestCase
{
    public function testCanonicalContractMatchesFreshInstallSchema(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-schema-contract.php');
        self::assertSame("Site template schema contract passed\n", shell_exec($command));
    }
}
