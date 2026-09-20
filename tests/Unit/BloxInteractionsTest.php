<?php
/** v1.28 元素交互：归一化白名单 / 渲染期属性注入与按需资产 / 授权与冻结。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxInteractionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testNormalizationEnforcesWhitelistsAndClamps(): void
    {
        $items = BloxInteractions::normalize([
            ['trigger' => 'click', 'action' => 'toggle', 'target' => 'self', 'run_once' => '1', 'junk' => 'x'],
            ['trigger' => 'scroll', 'action' => 'add_class', 'target' => 'selector', 'selector' => '.hero', 'value' => 'is-active', 'scroll_depth' => 900],
            ['trigger' => 'hover', 'action' => 'animate', 'target' => 'parent', 'value' => 'fade-up'],
            ['trigger' => 'page_load', 'action' => 'open_popup', 'target' => 'selector'], // popup 动作无目标概念
            ['trigger' => 'click', 'action' => 'add_class', 'target' => 'self', 'value' => 'bad name'],   // 类名非法：丢
            ['trigger' => 'click', 'action' => 'toggle', 'target' => 'selector', 'selector' => 'div > a'], // 选择器越权：丢
            ['trigger' => 'click', 'action' => 'animate', 'target' => 'self', 'value' => 'spin'],          // 动画不在白名单：丢
            ['trigger' => 'shake', 'action' => 'toggle', 'target' => 'self'],                              // 触发非法：丢
            'junk',
        ]);

        self::assertCount(4, $items);
        self::assertSame(['trigger' => 'click', 'action' => 'toggle', 'target' => 'self', 'run_once' => true], $items[0]);
        self::assertSame(100, $items[1]['scroll_depth']);
        self::assertSame('.hero', $items[1]['selector']);
        self::assertSame('fade-up', $items[2]['value']);
        self::assertArrayNotHasKey('target', $items[3]);
        self::assertArrayNotHasKey('junk', $items[0]);

        // 全部非法：键整体删除
        $data = BloxInteractions::normalizeElementData(['_interactions' => [['trigger' => 'nope']]]);
        self::assertArrayNotHasKey('_interactions', $data);
    }

    public function testRendererInjectsAttributeAndCollectsRuntimeOnDemand(): void
    {
        BloxAssetCollector::resetForTests();
        $sections = static fn (array $extra): string => (string) json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'ix-el', 'type' => 'heading',
                'data' => ['text' => 'Hi', 'level' => 'h2'] + $extra,
            ]]]],
        ]]);

        // 无交互：零属性、零脚本（「未用交互时 Builder 前端 JS = 0」承诺）
        $plain = BlockRenderer::render($sections([]));
        self::assertStringNotContainsString('data-yk-interactions', $plain);
        self::assertStringNotContainsString('blox-interactions.js', implode(' ', BloxAssetCollector::scripts()));

        // 有交互：首标签得到序列化属性 + runtime 入列
        $withInteractions = BlockRenderer::render($sections([
            '_interactions' => [['trigger' => 'click', 'action' => 'toggle', 'target' => 'self']],
        ]));
        self::assertStringContainsString('data-yk-interactions=', $withInteractions);
        self::assertStringContainsString('&quot;trigger&quot;:&quot;click&quot;', $withInteractions);
        self::assertStringContainsString('/assets/js/blox-interactions.js', implode(' ', BloxAssetCollector::scripts()));

        // 编辑态不注入（画布不执行交互）——编辑渲染入口是 renderElementNode(editMode=true)
        $editHtml = BlockRenderer::renderElementNode([
            'id' => 'ix-el', 'type' => 'heading',
            'data' => ['text' => 'Hi', 'level' => 'h2',
                '_interactions' => [['trigger' => 'click', 'action' => 'toggle', 'target' => 'self']]],
        ], 0, true, [0, 0, 0]);
        self::assertStringNotContainsString('data-yk-interactions=', $editHtml);
        BloxAssetCollector::resetForTests();
    }

    public function testAuthoringGateAndFreeze(): void
    {
        $sections = [['id' => 's', 'columns' => [['id' => 'c', 'elements' => [[
            'id' => 'e', 'type' => 'heading',
            'data' => ['text' => 'x', '_interactions' => [['trigger' => 'click', 'action' => 'toggle', 'target' => 'self']]],
        ]]]]]];

        BloxInteractions::assertSectionsAllowed($sections, true);
        try {
            BloxInteractions::assertSectionsAllowed($sections, false);
            self::fail('未授权站点不得新增交互');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_interactions_license_required', $e->getMessage());
        }

        // 冻结：能力失效时既有 _interactions 不可修改（trigger 篡改被拒），原样保留通过
        BloxProtectedFields::forValidation($sections, $sections, ['interactions']);
        $tampered = $sections;
        $tampered[0]['columns'][0]['elements'][0]['data']['_interactions'][0]['trigger'] = 'hover';
        $this->expectExceptionMessage('blox_protected_fields_changed');
        BloxProtectedFields::forValidation($tampered, $sections, ['interactions']);
    }
}
