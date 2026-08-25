<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxBuiltinTemplateProviderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function test404TemplateIsListedOnlyForPageEditing(): void
    {
        $provider = new BloxBuiltinTemplateProvider();
        $items = [];
        foreach ($provider->items('page') as $item) {
            $items[$item['key']] = $item;
        }

        // 断言这一条的属性，不断言内置模板的总数——每加一套随包模板都要来改一次
        // 计数，那是没有信息量的维护成本（2026-08-24 加公司介绍/联系我们时踩到）。
        self::assertArrayHasKey('builtin:404-route-lost', $items);
        $item = $items['builtin:404-route-lost'];
        self::assertSame('page', $item['type']);
        self::assertSame('builtin', $item['source']);
        self::assertSame('page', $item['category']);
        self::assertSame('/assets/images/blox-templates/404-route-lost.png', $item['thumbnail']);
        $homeItems = $provider->items('home');
        self::assertNotEmpty($homeItems);
        self::assertNotContains('builtin:404-route-lost', array_column($homeItems, 'key'));
        self::assertSame(['section'], array_values(array_unique(array_column($homeItems, 'type'))));
    }

    public function test404TemplateResolvesThroughTheImporterWithFreshIds(): void
    {
        $provider = new BloxBuiltinTemplateProvider();
        $first = $provider->resolve('404-route-lost', 'page');
        $second = $provider->resolve('404-route-lost', 'page');

        self::assertSame('page', $first['type']);
        self::assertSame('builtin', $first['source']);
        self::assertCount(1, $first['sections']);
        self::assertNotSame($first['sections'][0]['id'], $second['sections'][0]['id']);

        $elements = $first['sections'][0]['columns'][0]['elements'];
        self::assertSame(['heading', 'div', 'text', 'button'], array_column($elements, 'type'));
        self::assertSame('404', $elements[0]['data']['text']);
        self::assertSame('dark', $elements[3]['data']['variant']);
        self::assertSame('pill', $elements[3]['data']['shape']);
    }

    public function test404TemplateCannotBeResolvedInHomeContext(): void
    {
        $this->expectException(RuntimeException::class);
        (new BloxBuiltinTemplateProvider())->resolve('404-route-lost', 'home');
    }
}
