<?php
/**
 * 安装期路由探针：首页一点必须由入口文件应答。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RewriteProbe;

require_once ROOT_PATH . '/includes/RewriteProbe.php';

final class RewriteProbeTest extends TestCase
{
    public function testHomeIsOneOfTheProbedPaths(): void
    {
        self::assertContains('/', RewriteProbe::PATHS);
        self::assertContains('/contact.html', RewriteProbe::PATHS);
    }

    /**
     * 2026-09-23 真实 Nginx 实测：面板把 index.html 排在前面时，/ 被改写进 page.php
     * 而 REQUEST_URI 仍是 /。不核对入口文件，探针就会替一个装完必然 404 的首页报「正常」。
     */
    public function testHomeCountsOnlyWhenTheFrontControllerAnswers(): void
    {
        self::assertTrue(RewriteProbe::servedByFrontController(['SCRIPT_FILENAME' => ROOT_PATH . '/index.php']));
        self::assertTrue(RewriteProbe::servedByFrontController(['SCRIPT_FILENAME' => ROOT_PATH . '/./index.php']), '路径写法不同但同一文件');
        self::assertFalse(RewriteProbe::servedByFrontController(['SCRIPT_FILENAME' => ROOT_PATH . '/page.php']));
        self::assertFalse(RewriteProbe::servedByFrontController(['SCRIPT_FILENAME' => ROOT_PATH . '/install/index.php']), '同名但不是根入口');
        self::assertFalse(RewriteProbe::servedByFrontController(['SCRIPT_FILENAME' => ROOT_PATH . '/missing.php']));
        self::assertFalse(RewriteProbe::servedByFrontController([]));
    }
}
