<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/shop/lib/routes.php';
require_once ROOT_PATH . '/includes/BasePath.php';

final class ShopFrontRoutesTest extends TestCase
{
    public function testPrettyUrlsPreserveExistingRoutes(): void
    {
        self::assertSame('/shop/api', shopFrontUrl('api', [], 'pretty'));
        self::assertSame('/shop/cart?err=shop_err_token', shopFrontUrl('cart', ['err' => 'shop_err_token'], 'pretty'));
        self::assertSame('/member/shop-orders?page=2', shopFrontUrl('member-orders', ['page' => 2], 'pretty'));
    }

    public function testQueryUrlsUsePhysicalEntryAndKeepParameters(): void
    {
        foreach (['api', 'cart', 'checkout', 'order', 'pay', 'member-orders'] as $route) {
            $url = shopFrontUrl($route, ['no' => 'A&B', '_lang' => 'ja', 'shop_route' => '../admin'], 'query');
            self::assertSame('/plugins/shop/front/index.php', parse_url($url, PHP_URL_PATH));
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame(['shop_route' => $route, 'no' => 'A&B', '_lang' => 'ja'], $query);
        }
    }

    public function testSubdirectoryIsAppliedOnceToFormAndRedirect(): void
    {
        $url = shopFrontUrl('api', ['_lang' => 'en'], 'query');
        $form = BasePath::rewriteHtml('<form action="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></form>', '/pet');
        self::assertStringContainsString('action="/pet/plugins/shop/front/index.php?shop_route=api&amp;_lang=en"', $form);
        $cart = shopFrontUrl('cart', ['err' => 'shop_err_token', '_lang' => 'en'], 'query');
        self::assertSame('/pet' . $cart, BasePath::prefixLocation($cart, '/pet'));
    }

    public function testUnknownRouteCannotBecomeAnIncludePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        shopFrontUrl('../admin', [], 'query');
    }
}
