<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxProtectedFields.php';

/**
 * 循环字段只在循环模板内部受保护。基础元素插入时自带 loop_field 等默认值，
 * 放在循环外必须能自由新增/修改，否则未授权站点连普通标题都插不进去（v1.20.1 发版前实测）。
 */
final class BloxProtectedFieldsLoopScopeTest extends TestCase
{
    private const DENIED = ['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing'];

    private static function doc(array $elements): array
    {
        return [['id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => $elements]]]];
    }

    private static function loop(array $children): array
    {
        return ['id' => 'loop', 'type' => 'list-dynamic', 'data' => ['source' => 'content', 'children' => $children]];
    }

    public function testNewBasicElementsOutsideLoopsKeepTheirInertLoopDefaults(): void
    {
        $trusted = self::doc([['id' => 'h0', 'type' => 'heading', 'data' => ['text' => 'Old']]]);
        $sections = self::doc([
            ['id' => 'h0', 'type' => 'heading', 'data' => ['text' => 'Old']],
            ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'New', 'loop_field' => 'title']],
            ['id' => 't1', 'type' => 'text', 'data' => ['html' => '<p>x</p>', 'loop_field' => 'summary']],
            ['id' => 'i1', 'type' => 'image', 'data' => ['src' => '/a.png', 'loop_field' => 'cover', 'loop_alt_field' => 'title']],
        ]);
        self::assertSame($sections, BloxProtectedFields::forValidation($sections, $trusted, self::DENIED));
    }

    public function testLoopBindingsInsideLoopTemplatesStayProtected(): void
    {
        $trusted = self::doc([self::loop([['id' => 'c1h', 'type' => 'heading', 'data' => ['loop_field' => 'title']]])]);
        $changed = self::doc([self::loop([['id' => 'c1h', 'type' => 'heading', 'data' => ['loop_field' => 'summary']]])]);
        $this->expectException(RuntimeException::class);
        BloxProtectedFields::forValidation($changed, $trusted, self::DENIED);
    }

    /** v1.20.2 起站点数据绑定为免费能力：全部专业能力未授权时，仍可新增、修改和删除站点绑定。 */
    public function testSiteBindingsAreFreeEvenWhenAllProFeaturesAreDenied(): void
    {
        $trusted = self::doc([['id' => 'h0', 'type' => 'heading', 'data' => ['text' => 'Old', 'site_field' => 'site_name']]]);
        foreach ([
            ['text' => 'Old', 'site_field' => 'contact_email'],
            ['text' => 'Old'],
            ['text' => 'Old', 'site_field' => 'site_name', 'site_url_field' => 'site_url'],
        ] as $data) {
            $next = self::doc([['id' => 'h0', 'type' => 'heading', 'data' => $data]]);
            self::assertSame($next, BloxProtectedFields::forValidation($next, $trusted, self::DENIED));
        }
    }
}
