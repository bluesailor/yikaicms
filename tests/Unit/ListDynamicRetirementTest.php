<?php
/**
 * list-dynamic 退役合同（2026-09-20 用户裁决，阶梯见 v3 计划 §7.2.1）。
 *
 * 本文件是退役期间的**回归锚点**：老 JSON（含循环模板子元素）必须一直渲染正常，
 * deprecated 标记必须一直挂着（palette / 容器内插入两条新增路都按它隐藏）。
 * 移除编辑器支持前不得删除本测试；删渲染兼容代码时本测试整体随之退役。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BuilderRegistry;
use BlockRenderer;
use TagEngine;
use Yikai\Tests\TestCase;

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';
require_once ROOT_PATH . '/includes/TagEngine.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class ListDynamicRetirementTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
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
    }

    public function testDeprecatedFlagHidesNewInsertsButKeepsSchema(): void
    {
        BuilderRegistry::boot();
        $element = BuilderRegistry::get('list-dynamic');
        self::assertNotNull($element, '渲染与存量编辑必须保留——退役≠删除');
        self::assertTrue($element->deprecated(), 'palette/容器内新增按此隐藏（blox_editor 8204 + canNestElement）');
        // registry meta 把标记带给两个编辑器（blox_editor elementLib / page_edit_advance BUILDER_ELEMENTS）
        self::assertTrue((bool) (BuilderRegistry::meta()['list-dynamic']['deprecated'] ?? false));
    }

    /** 老 JSON（基础表单模式 + 循环模板子元素两种形态）渲染不炸、数据正常出。 */
    public function testLegacyDocumentsStillRender(): void
    {
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Legacy One', 'summary' => 'S1', 'publish_time' => 200]);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Legacy Two', 'summary' => 'S2', 'publish_time' => 100]);

        // 形态 1：基础表单模式（内置卡片渲染）
        $basic = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'ld-basic', 'type' => 'list-dynamic',
                'data' => ['query_source' => 'type:article', 'limit' => 10],
            ]]]],
        ]]));
        self::assertStringContainsString('Legacy One', $basic);
        self::assertStringContainsString('Legacy Two', $basic);

        // 形态 2（P1 老形态）：template[] 直接渲染子元素、靠手写 {yk:field} 绑定
        $loop = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'ld-loop', 'type' => 'list-dynamic',
                'data' => [
                    'query_source' => 'type:article', 'limit' => 10,
                    'template' => [
                        ['type' => 'heading', 'data' => ['text' => '{yk:field name=title /}', 'level' => 'h3']],
                    ],
                ],
            ]]]],
        ]]));
        self::assertStringContainsString('Legacy One', $loop);
        self::assertStringContainsString('Legacy Two', $loop);

        // 形态 3（编辑器现形态）：children[] 经 bindNode 做 loop_field/loop_fallback 绑定
        // ——专业授权数据的渲染永远免费
        $children = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'ld-children', 'type' => 'list-dynamic',
                'data' => [
                    'query_source' => 'type:article', 'limit' => 10,
                    'children' => [
                        ['type' => 'heading', 'data' => ['text' => '', 'level' => 'h3', 'loop_field' => 'title', 'loop_fallback' => 'LD-Untitled']],
                    ],
                ],
            ]]]],
        ]]));
        self::assertStringContainsString('Legacy One', $children);
        self::assertStringContainsString('Legacy Two', $children);
        self::assertStringContainsString('yk-query-item', $children);

        // 空态文案照旧
        $empty = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'ld-empty', 'type' => 'list-dynamic',
                'data' => ['query_source' => 'type:article', 'keyword' => 'ABSENT-93x', 'empty' => '暂无匹配'],
            ]]]],
        ]]));
        self::assertStringContainsString('暂无匹配', $empty);
    }
}
