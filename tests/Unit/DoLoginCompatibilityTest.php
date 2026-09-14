<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DoLoginCompatibilityTest extends TestCase
{
    public function testVersionBoundaryAndOldSiteEntryPoints(): void
    {
        foreach (['' => false, '1.19.9' => false, '1.19.10' => false, '1.20.0-rc1' => false,
            'invalid' => false, '1.20.0' => true, '1.20.1' => true, '2.0.0' => true] as $version => $expected) {
            $lines = [];
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/dologin-version-probe.php') . ' ' . escapeshellarg($version), $lines, $exit);
            self::assertSame(0, $exit, $version);
            $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($expected, $result['supported'], $version);
            self::assertSame($expected ? ['admin_login_request'] : [], $result['hooks'], $version);
            if (!$expected) {
                foreach (['admin', 'login'] as $entry) {
                    self::assertSame([403, 'dl_cms_version_required'], $result['blocked'][$entry], $version);
                }
            }
        }
    }
}
