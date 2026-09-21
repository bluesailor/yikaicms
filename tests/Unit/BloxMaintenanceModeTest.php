<?php
/**
 * 内容维护模式（E07）的服务端保护。
 *
 * 验收原话是"低权限请求不能绕过 UI 修改结构"——所以这里测的全是**直接构造提交**
 * 的场景，界面上有没有按钮完全不影响结论。
 *
 * 另一条是"维护模式锁定不删除隐藏的历史设置"：被拒绝的是这次提交，文档里原有的
 * 结构与样式必须原样留着。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxMaintenanceMode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/includes/builder/BloxMaintenanceMode.php';

final class BloxMaintenanceModeTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function document(array $overrides = []): array
    {
        $element = [
            'id' => 'e1',
            'type' => 'heading',
            // array_merge 而不是 +：后者左侧优先，会让 overrides 静默失效
            'data' => array_merge(['text' => '原标题', 'level' => 'h2', 'align' => 'left'], $overrides['data'] ?? []),
        ];
        if (isset($overrides['element'])) {
            $element = array_merge($element, $overrides['element']);
        }
        return [[
            'id' => 's1',
            'settings' => array_merge(['padding' => 'lg'], $overrides['section'] ?? []),
            'columns' => [['settings' => ['width' => '12'], 'elements' => [$element]]],
        ]];
    }

    private function assertAccepted(array $next, array $base, string $why): void
    {
        BloxMaintenanceMode::assertContentOnly($next, $base);
        self::assertTrue(true, $why);
    }

    private function assertRejected(array $next, array $base, string $why): void
    {
        try {
            BloxMaintenanceMode::assertContentOnly($next, $base);
            self::fail('应拒绝：' . $why);
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_maintenance_structure_locked', $e->getMessage());
        }
    }

    /** 维护人员的日常操作：改字、换图、改链接——全部放行。 */
    public function testContentEditsAreAccepted(): void
    {
        $base = $this->document();

        $this->assertAccepted($this->document(['data' => ['text' => '新标题']]), $base, '改文字');
        $this->assertAccepted($this->document(['data' => ['src' => '/uploads/new.jpg']]), $base, '换图片');
        $this->assertAccepted($this->document(['data' => ['link_url' => 'https://example.test']]), $base, '改链接');
        $this->assertAccepted($this->document(['data' => ['alt' => '新的替代文字']]), $base, '改 alt');
    }

    /** 结构改动：加删元素、换类型、改版式——一律拒绝，哪怕请求是直接构造的。 */
    public function testStructuralChangesAreRejectedEvenWhenPostedDirectly(): void
    {
        $base = $this->document();

        // 换元素类型
        $this->assertRejected($this->document(['element' => ['type' => 'text']]), $base, '改元素类型');
        // 换元素身份（等于换了一个元素）
        $this->assertRejected($this->document(['element' => ['id' => 'e2']]), $base, '改元素 id');
        // 改版式设置
        $this->assertRejected($this->document(['data' => ['align' => 'center']]), $base, '改对齐');
        $this->assertRejected($this->document(['section' => ['padding' => 'sm']]), $base, '改区块内边距');

        // 删元素
        $stripped = $base;
        $stripped[0]['columns'][0]['elements'] = [];
        $this->assertRejected($stripped, $base, '删元素');

        // 加元素
        $added = $base;
        $added[0]['columns'][0]['elements'][] = ['id' => 'e9', 'type' => 'text', 'data' => ['html' => '<p>x</p>']];
        $this->assertRejected($added, $base, '加元素');

        // 加区块
        $extra = $base;
        $extra[] = ['id' => 's2', 'settings' => [], 'columns' => []];
        $this->assertRejected($extra, $base, '加区块');
    }

    /** 嵌套容器里的结构改动同样要被抓到，不能只看顶层。 */
    public function testNestedStructuralChangesAreDetected(): void
    {
        $base = [[
            'id' => 's1', 'settings' => [],
            'columns' => [['settings' => [], 'elements' => [[
                'id' => 'c1', 'type' => 'container',
                'data' => ['children' => [['id' => 'k1', 'type' => 'heading', 'data' => ['text' => '里层']]]],
            ]]]],
        ]];

        $contentOnly = $base;
        $contentOnly[0]['columns'][0]['elements'][0]['data']['children'][0]['data']['text'] = '改过的里层';
        $this->assertAccepted($contentOnly, $base, '嵌套里改文字');

        $structural = $base;
        $structural[0]['columns'][0]['elements'][0]['data']['children'][0]['type'] = 'text';
        $this->assertRejected($structural, $base, '嵌套里换类型');

        $removed = $base;
        $removed[0]['columns'][0]['elements'][0]['data']['children'] = [];
        $this->assertRejected($removed, $base, '嵌套里删元素');
    }

    /** 未知的新键按结构处理——漏判的方向必须是保守拒绝，而不是放行。 */
    public function testUnknownKeysCountAsStructure(): void
    {
        $base = $this->document();
        $this->assertRejected($this->document(['data' => ['some_future_layout_key' => 'x']]), $base,
            '未登记的新键改动应当保守拒绝');
    }

    /** 投影不改动传入的文档：拒绝一次提交不等于动了已存的数据。 */
    public function testProjectionDoesNotMutateTheDocument(): void
    {
        $base = $this->document();
        $copy = $base;
        BloxMaintenanceMode::structure($base);
        self::assertSame($copy, $base, '结构投影必须是只读的');
    }
}
