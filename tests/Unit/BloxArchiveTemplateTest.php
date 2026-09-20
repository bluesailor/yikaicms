<?php
/** v1.26 Site Builder：archive（栏目列表）模板类型的注册形态与激活裁决语义。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxArchiveTemplateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testArchiveIsAConditionalAdvancedTemplateType(): void
    {
        self::assertTrue(BloxTemplateModel::validType('archive'));
        self::assertTrue(BloxTemplateModel::conditionalType('archive'));
        self::assertFalse(BloxAreaDocument::isArea('archive'));
        // 依赖查询循环（current 源）：随专业能力档，免费站不提供作者端
        self::assertFalse(BloxTemplateEditPolicy::allows('archive', false));
        self::assertTrue(BloxTemplateEditPolicy::allows('archive', true));
    }

    public function testResolutionPrefersTargetedChannelOverGlobalAndFailsClosed(): void
    {
        $context = ['home' => false, 'channel_id' => 7, 'page_id' => 0, 'lang' => 'zh-CN'];
        $allChannels = ['id' => 1, 'conditions' => json_encode([['main' => 'channel', 'ids' => []]])];
        $newsOnly = ['id' => 2, 'conditions' => json_encode([['main' => 'channel', 'ids' => [7]]])];
        $otherOnly = ['id' => 3, 'conditions' => json_encode([['main' => 'channel', 'ids' => [8]]])];

        // 同为 channel 档：指定栏目与"全部栏目"同分，id 大者（后创建）胜——这里断言指定者可命中
        $won = BloxAreaResolver::resolve([$allChannels, $newsOnly], $context);
        self::assertSame(2, (int) $won['id']);
        // 指定其他栏目的模板不命中；只剩全栏目模板时它兜住
        self::assertSame(1, (int) BloxAreaResolver::resolve([$allChannels, $otherOnly], $context)['id']);
        // 条件损坏（fail-closed）：绝不退化为全站接管
        $broken = ['id' => 4, 'conditions' => '{"oops"'];
        self::assertNull(BloxAreaResolver::resolve([$broken], $context));
    }
}
