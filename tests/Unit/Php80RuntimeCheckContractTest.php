<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 评审 P2-12：PHP 8.0 门禁只做 token_get_all(TOKEN_PARSE) 时，8.1 才支持的
 * 「初始化器里 new」能通过解析却在 8.0 编译失败；且只扫 themes/default。
 * 门禁本身必须用 PHP 8.0 运行（CI 的 php80 任务），这里锁住实现契约。
 */
final class Php80RuntimeCheckContractTest extends TestCase
{
    public function testGateCompilesEveryFileWithTheMinimumRuntimeAndCoversAllThemes(): void
    {
        $tool = (string) file_get_contents(ROOT_PATH . '/tools/check-php80-runtime.php');
        self::assertStringContainsString("[PHP_BINARY, '-n', '-l', \$path]", $tool);
        self::assertStringContainsString('PHP_VERSION_ID >= 80100', $tool);
        self::assertMatchesRegularExpression("/'plugins', 'themes', 'marketplace\/themes'/", $tool);
        self::assertStringNotContainsString("'themes/default'", $tool);
        self::assertStringContainsString('php tools/check-php80-runtime.php', (string) file_get_contents(ROOT_PATH . '/.github/workflows/ci.yml'));
    }
}
