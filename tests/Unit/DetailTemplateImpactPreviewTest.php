<?php
/**
 * 第四轮影响范围预览的纯函数部分：分组与分页合并。真实扫描、权限与链接由 e2e 覆盖。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailTemplateImpactPreviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testGroupsSeparateWinningTiedLosingAndExcludedContent(): void
    {
        $this->assertSame('won', DetailTemplateImpactPreview::group('matched', 'won'));
        $this->assertSame('won', DetailTemplateImpactPreview::group('matched', 'native'), '命中但输出主题默认也是本模板决定的');
        $this->assertSame('conflicted', DetailTemplateImpactPreview::group('matched', 'conflicted'));
        $this->assertSame('lost', DetailTemplateImpactPreview::group('matched', 'lost'), '条件包含但由更具体的模板胜出');
        $this->assertSame('excluded', DetailTemplateImpactPreview::group('excluded', 'no_match'));
        $this->assertSame('untouched', DetailTemplateImpactPreview::group('not_included', 'lost'));
        $this->assertSame('untouched', DetailTemplateImpactPreview::group('not_considered', 'not_considered'));
    }
}
