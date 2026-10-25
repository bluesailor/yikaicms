<?php
/**
 * 进回收站只写 deleted_at、status 仍是 1。凡是按 status = 1 批量列出内容的地方，
 * 都要同时排除回收站，否则回收站里的条目会出现在：站内搜索（2.1.3 前）、静态化清单、
 * 站点配置导出、SEO 插件的 llms.txt / 主动推送网址 / 体检 / 失效链接检查。
 * 逐条守住，直到软删除过滤收进统一的查询层。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SoftDeleteListingQueriesTest extends TestCase
{
    /** 有回收站的表（模型里 softDelete = true 的那几张；栏目表是直接删除，不在此列） */
    private const SOFT_DELETE_TABLES = 'contents|products|downloads|albums|jobs';

    /** @return array<string, array{string, int}> 文件、至少应找到几条按 status = 1 列出的查询 */
    public static function listings(): array
    {
        return [
            '站内搜索' => ['search.php', 10],
            '搜索共用查询' => ['includes/search_query.php', 4],
            '静态化清单' => ['includes/StaticHtml.php', 2],
            '站点配置导出' => ['includes/RecipeService.php', 1],
            'SEO llms.txt 与推送网址' => ['plugins/seo/lib.php', 4],
            'SEO 体检' => ['plugins/seo/audit.php', 1],
            'SEO 失效链接' => ['plugins/seo/linkcheck.php', 3],
            'SEO 主动推送' => ['plugins/seo/autopush.php', 1],
            'SEO 内链建议' => ['plugins/seo/links.php', 2],
        ];
    }

    /** @dataProvider listings */
    public function testListingsSkipTheRecycleBin(string $file, int $minimum): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/' . $file);
        // 把 'FROM ' . DB_PREFIX . 'contents' 这类拼接还原成一句 SQL
        $sql = (string) preg_replace('/[\'"]\s*\.\s*(?:DB_PREFIX|\$doc\[\'table\'\])\s*\.\s*[\'"]/', '', $source);
        $sql = (string) preg_replace('/[\'"]\s*\n\s*\.\s*[\'"]/', ' ', $sql);
        preg_match_all(
            '/FROM\s+(?:' . self::SOFT_DELETE_TABLES . ')?(?:\s+[a-z]\b)?(?:(?!FROM\s).){0,600}?status\s*=\s*1\b[^\'"]*/is',
            $sql,
            $matches
        );

        self::assertGreaterThanOrEqual($minimum, count($matches[0]), $file);
        foreach ($matches[0] as $query) {
            if (preg_match('/FROM\s+channels\b/i', $query) === 1) {
                continue;   // 栏目没有回收站
            }
            self::assertMatchesRegularExpression(
                '/\bdeleted_at\s+IS\s+NULL\b/i',
                $query,
                $file . " lists rows without excluding the recycle bin:\n" . trim($query)
            );
        }
    }
}
