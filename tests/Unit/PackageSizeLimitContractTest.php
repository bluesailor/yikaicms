<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 所有者规定（2026-10-09）：完整安装包不超过 10 MiB，超了先分析确认才能放行。 */
final class PackageSizeLimitContractTest extends TestCase
{
    public function testBuildStopsAboveTenMebibytesUnlessExplicitlyConfirmed(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');

        self::assertStringContainsString('PACKAGE_SIZE_LIMIT=10485760', $build);
        self::assertMatchesRegularExpression('/if \[ "\$ZIP_BYTES" -gt "\$PACKAGE_SIZE_LIMIT" \]; then\s+if \[ -z "\$\{YK_ALLOW_OVERSIZE:-\}" \]; then[^\n]*\n(?:[^\n]*\n){0,3}?\s+rm -f "\$ZIP_FILE"\s+exit 1/', $build, '超限且未确认时必须删包并失败');
        // 体积检查必须在生成校验和之前，超限的包不能留下可发布的 sha256
        self::assertLessThan(strpos($build, 'sha256sum "$ZIP_FILE"'), strpos($build, 'PACKAGE_SIZE_LIMIT=10485760'));
    }
}
