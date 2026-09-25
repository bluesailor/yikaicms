<?php
/**
 * 批量上下架必须触发 data_changed（2026-09-17 发版前审计 F02）。
 *
 * article.php 的批量发布/下架原本直接发 UPDATE SQL，绕开模型事件；
 * 页面缓存与静态文件的失效都挂在 data_changed 上，于是已下架的文章
 * 仍以 X-Cache: HIT 返回正文，直到缓存自然过期。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/hooks.php';

final class BatchStatusCacheInvalidationTest extends TestCase
{
    /** @return list<string> */
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT DEFAULT "article",
                title TEXT,
                slug TEXT,
                status INTEGER DEFAULT 1,
                updated_at INTEGER DEFAULT 0,
                deleted_at INTEGER NULL
            )',
        ];
    }

    /** @var array<string,mixed> */
    private array $savedActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 清空前先存下、测完还原：否则其它测试文件加载时注册的动作（如商城的整站模板导入钩子）
        // 被一并抹掉，按文件顺序排在后面的测试才会失败（2026-09-25 CI 首次全量运行暴露）。
        $this->savedActions = $GLOBALS['ik_actions'] ?? [];
        $GLOBALS['ik_actions'] = [];
        db()->getPdo()->exec("INSERT INTO contents (id, type, title, status) VALUES
            (1, 'article', 'A1', 1), (2, 'article', 'A2', 1), (3, 'article', 'A3', 1)");
    }

    protected function tearDown(): void
    {
        $GLOBALS['ik_actions'] = $this->savedActions;
        parent::tearDown();
    }

    public function testBatchUnpublishThroughTheModelFiresDataChanged(): void
    {
        $fired = [];
        add_action('data_changed', function (string $table = '', $id = null) use (&$fired): void {
            $fired[] = $table;
        });

        $ids = [1, 2];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $affected = contentModel()->updateWhere(
            ['status' => 0, 'updated_at' => time()],
            "id IN ({$placeholders})",
            $ids
        );

        self::assertSame(2, $affected);
        self::assertSame(['contents'], $fired, '批量下架必须触发一次内容变更事件');
        self::assertSame(
            ['0', '0', '1'],
            array_map(
                static fn (array $row): string => (string) $row['status'],
                db()->fetchAll('SELECT status FROM contents ORDER BY id')
            )
        );
    }

    /** 反向守住回归：裸 SQL 改状态不会触发事件，所以入口里不许再出现它。 */
    public function testRawSqlUpdateDoesNotFireDataChanged(): void
    {
        $fired = [];
        add_action('data_changed', function (string $table = '', $id = null) use (&$fired): void {
            $fired[] = $table;
        });

        db()->execute('UPDATE contents SET status = 0 WHERE id IN (1,2)');

        self::assertSame([], $fired);
        self::assertStringNotContainsString(
            'UPDATE " . DB_PREFIX . "contents SET status',
            (string) file_get_contents(ROOT_PATH . '/admin/article.php')
        );
    }
}
