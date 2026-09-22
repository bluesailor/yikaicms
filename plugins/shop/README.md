# 轻量商城支付网关适配

商城核心默认只支持后台人工确认收款。在线支付平台通过 `shop_payment_verify`
过滤器接入，通知地址为：

```text
POST /shop/payment-notify/{gateway}
```

`gateway` 只能包含小写字母、数字、下划线和短横线，最长 20 字符。适配器必须先按
支付平台官方规则验证原始请求正文和请求头；验签成功后返回统一结构：

```php
add_filter('shop_payment_verify', static function (
    mixed $result,
    string $gateway,
    string $rawBody,
    array $context
): mixed {
    if ($gateway !== 'your_gateway') {
        return $result;
    }

    // 这里按支付平台官方规则验签；禁止只相信正文里的 success/status 字段。
    $verified = yourGatewayVerify($rawBody, $context['headers'] ?? []);
    if (!$verified['ok']) {
        return $result;
    }

    return [
        'verified' => true,
        'status' => 'succeeded',
        'order_no' => $verified['out_trade_no'],
        'trade_no' => $verified['trade_no'],
        'amount' => $verified['amount_decimal'], // 必须是十进制字符串，不能传 float
        'currency' => 'CNY',
    ];
});
```

核心在验签后仍会独立核对订单号、订单金额和币种，并以通知摘要唯一键和
`(gateway, trade_no)` 唯一键做两层幂等。正文最大 256 KiB；失败响应只返回
`fail`，不会回显订单或数据库细节。
