<?php
/**
 * 站点地图不得收录回收站里的内容与产品。
 *
 * 病：sitemap.php 只筛 status = 1，软删的行照样进 sitemap.xml，
 * 而它们的详情页已经 404 —— 等于持续向搜索引擎提交死链
 * （2026-09-26 ht-sshc 站删掉 4 个产品后实测复现）。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SitemapSoftDeleteContractTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(ROOT_PATH . '/sitemap.php');
    }

    /**
     * @dataProvider softDeletableQueries
     */
    public function testSoftDeletedRowsAreExcluded(string $table, string $alias): void
    {
        $src = self::source();

        self::assertStringContainsString(
            'DB_PREFIX . "' . $table . ' ' . $alias,
            $src,
            "sitemap.php 不再查询 {$table}，请同步更新本测试"
        );

        self::assertMatchesRegularExpression(
            '/WHERE\s+' . $alias . '\.status\s*=\s*1\s+AND\s+' . $alias . '\.deleted_at\s+IS\s+NULL/',
            $src,
            "{$table} 的站点地图查询缺少 {$alias}.deleted_at IS NULL"
        );
    }

    /** @return array<string, array{string, string}> */
    public static function softDeletableQueries(): array
    {
        return [
            'contents' => ['contents', 'c'],
            'products' => ['products', 'p'],
        ];
    }
}
