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
        $items = $provider->items('page');

        self::assertCount(1, $items);
        self::assertSame('builtin:404-route-lost', $items[0]['key']);
        self::assertSame('page', $items[0]['type']);
        self::assertSame('builtin', $items[0]['source']);
        self::assertSame('page', $items[0]['category']);
        self::assertSame('/assets/images/blox-templates/404-route-lost.png', $items[0]['thumbnail']);
        self::assertSame([], $provider->items('home'));
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
