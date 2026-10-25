<?php
/**
 * 进回收站只写 deleted_at、status 仍是 1。凡是按 status = 1 批量列出内容的地方，
 * 都要同时排除回收站，否则回收站里的条目会出现在：站内搜索（2.1.3 前）、静态化清单、
 * 站点配置导出。逐条守住，直到软删除过滤收进统一的查询层。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SoftDeleteListingQueriesTest extends TestCase
{
    /** @return array<string, array{string, string, int}> 文件、表别名（无别名留空）、至少几条 */
    public static function listings(): array
    {
        return [
            '静态化清单' => ['includes/StaticHtml.php', '[cp]', 2],
            '站点配置导出' => ['includes/RecipeService.php', '', 1],
        ];
    }

    /** @dataProvider listings */
    public function testListingsSkipTheRecycleBin(string $file, string $alias, int $minimum): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/' . $file);
        // 有别名时只认带别名的列：栏目表没有回收站（直接删除），它不带别名的 status 条件不在此列
        $prefix = $alias === '' ? '()' : '(' . $alias . '\.)';
        preg_match_all('/(?:WHERE|AND)\s+' . $prefix . 'status\s*=\s*1\b[^"]*/', $source, $matches, PREG_SET_ORDER);

        self::assertGreaterThanOrEqual($minimum, count($matches), $file);
        foreach ($matches as $match) {
            $column = ($match[1] ?? '') . 'deleted_at IS NULL';
            self::assertStringContainsString($column, $match[0], $file . ' lists rows without excluding the recycle bin');
        }
    }
}
