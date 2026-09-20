<?php
/** v1.25 全局查询：CRUD 授权门 / 引用形态解析 / 删除保护 / 悬挂引用渲染契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BloxGlobalQueries;
use BloxLoopQuery;
use RuntimeException;
use TagEngine;
use Yikai\Tests\TestCase;

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';
require_once ROOT_PATH . '/includes/TagEngine.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxGlobalQueriesTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_global_queries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query_id TEXT NOT NULL,
                name TEXT NOT NULL,
                query TEXT NOT NULL,
                modified INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_query_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                query_id TEXT NOT NULL,
                doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT, content TEXT,
                type TEXT DEFAULT 'article',
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_test_config'] = [];
        TagEngine::setItem(null);
        BloxLoopQuery::resetForTests();
        BloxGlobalQueries::resetForTests();
    }

    public function testMutationsRequireLicenseAndValidateBodyAndNames(): void
    {
        try {
            BloxGlobalQueries::mutate('query_add', ['name' => '最新文章', 'query' => ['source' => 'type:article']], false);
            self::fail('未授权不得创建全局查询');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_query_loop_license_required', $e->getMessage());
        }
        try {
            BloxGlobalQueries::mutate('query_add', ['name' => 'x', 'query' => ['source' => 'evil;drop']], true);
            self::fail('非法查询体应被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_design_invalid', $e->getMessage());
        }

        $row = BloxGlobalQueries::mutate('query_add', [
            'name' => '最新文章',
            'query' => ['source' => 'type:article', 'limit' => 999, 'junk' => 'x'],
        ], true);
        self::assertMatchesRegularExpression(BloxGlobalQueries::ID_PATTERN, (string) $row['query_id']);
        BloxGlobalQueries::resetForTests();
        $body = BloxGlobalQueries::queryBody((string) $row['query_id']);
        self::assertSame(50, $body['limit'], '查询体经同一份归一钳位');
        self::assertArrayNotHasKey('junk', $body);

        $this->expectExceptionMessage('blox_gquery_duplicate_name');
        BloxGlobalQueries::mutate('query_add', ['name' => '最新文章', 'query' => ['source' => 'type:article']], true);
    }

    public function testElementRefNormalizationAndRenderThroughGlobalQuery(): void
    {
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'type' => 'article']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Global A', 'publish_time' => 200]);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Global B', 'publish_time' => 100]);
        $row = BloxGlobalQueries::mutate('query_add', [
            'name' => '新闻流', 'query' => ['source' => 'type:article', 'limit' => 10],
        ], true);
        BloxGlobalQueries::resetForTests();
        $queryId = (string) $row['query_id'];

        // 归一：引用形态只保留 ref 键；非法 ref 整体删除
        $data = BloxLoopQuery::normalizeElementData(['_query' => ['ref' => $queryId, 'junk' => 1]]);
        self::assertSame(['ref' => $queryId], $data['_query']);
        self::assertArrayNotHasKey('_query', BloxLoopQuery::normalizeElementData(['_query' => ['ref' => 'gq_bad']]));

        $document = static fn (string $ref): string => (string) json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'gq-host', 'type' => 'div',
                'data' => ['_query' => ['ref' => $ref], 'children' => [
                    ['id' => 'gq-card', 'type' => 'heading', 'data' => ['text' => '{{loop.title}}']],
                ]],
            ]]]],
        ]]);

        $out = BlockRenderer::render($document($queryId));
        self::assertStringContainsString('Global A', $out);
        self::assertStringContainsString('Global B', $out);

        // 悬挂引用：按空结果提示处理，绝不静态渲染模板
        BloxLoopQuery::resetForTests();
        $dangling = BlockRenderer::render($document('gq_ffffffffffff'));
        self::assertStringContainsString('yk-query-empty', $dangling);
        self::assertStringNotContainsString('{{loop.title}}', $dangling);
    }

    public function testReferenceIndexBlocksDeletionWhileInUse(): void
    {
        $row = BloxGlobalQueries::mutate('query_add', [
            'name' => '产品流', 'query' => ['source' => 'type:product'],
        ], true);
        $queryId = (string) $row['query_id'];

        $sections = [['columns' => [['elements' => [[
            'type' => 'container',
            'data' => ['_query' => ['ref' => $queryId], 'children' => []],
        ]]]]]];
        self::assertSame([$queryId => 1], BloxGlobalQueries::collectReferences($sections));
        BloxGlobalQueries::replaceDocumentRefs('page:5', [$queryId => 1]);
        self::assertSame(['docs' => 1, 'refs' => 1], BloxGlobalQueries::usage()[$queryId]);

        try {
            BloxGlobalQueries::mutate('query_delete', ['id' => $queryId], true);
            self::fail('被引用的查询不得删除');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_gquery_in_use', $e->getMessage());
        }

        BloxGlobalQueries::replaceDocumentRefs('page:5', []);
        BloxGlobalQueries::mutate('query_delete', ['id' => $queryId], true);
        BloxGlobalQueries::resetForTests();
        self::assertNull(BloxGlobalQueries::queryBody($queryId));
    }
}
