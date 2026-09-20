<?php
/** v1.26 Site Builder：error404 模板类型的注册形态与激活裁决语义。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxErrorPageTemplateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testErrorPageIsAConditionalTemplateTypeOnTheGenericPipeline(): void
    {
        self::assertTrue(BloxTemplateModel::validType('error404'));
        self::assertTrue(BloxTemplateModel::conditionalType('error404'));
        // 非区域类型：无 header/footer 那样的类型专属 settings 契约
        self::assertFalse(BloxAreaDocument::isArea('error404'));
        // 免费档（与页头页尾同级的站点基础能力）
        self::assertTrue(BloxTemplateEditPolicy::allows('error404', false));
    }

    public function testResolutionUsesAreaConditionsWithLanguageDimension(): void
    {
        // 404 上下文没有栏目/单页：any + 语言是唯一有意义的维度
        $context = ['home' => false, 'channel_id' => 0, 'page_id' => 0, 'lang' => 'en'];
        $default = ['id' => 1, 'conditions' => null];
        $enOnly = ['id' => 2, 'conditions' => json_encode([['main' => 'any', 'langs' => ['en']]])];
        $zhOnly = ['id' => 3, 'conditions' => json_encode([['main' => 'any', 'langs' => ['zh-CN']]])];

        // 语言命中的 any 条件（2+1=3 分）胜过无条件兜底（0 分）；错语言模板出局
        $won = BloxAreaResolver::resolve([$default, $enOnly, $zhOnly], $context);
        self::assertSame(2, (int) $won['id']);
        // 只剩无条件模板：兜底生效
        self::assertSame(1, (int) BloxAreaResolver::resolve([$default, $zhOnly], $context)['id']);
        // 全部出局：不激活（回落主题原生 404），绝不退化为错语言模板
        self::assertNull(BloxAreaResolver::resolve([$zhOnly], $context));
    }
}
