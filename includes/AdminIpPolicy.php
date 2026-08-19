<?php

declare(strict_types=1);

require_once __DIR__ . '/ClientIpResolver.php';

final class AdminIpPolicy
{
    public static function isAllowed(string $clientIp, mixed $whitelist): bool
    {
        $parsed = ClientIpResolver::parseRules($whitelist);
        $isConfigured = is_array($whitelist)
            ? $whitelist !== []
            : trim((string) $whitelist) !== '';
        if (!$isConfigured) {
            return true;
        }
        // 已配置却没有任何合法规则时 fail closed；后台保存会提前拒绝这种配置。
        return $parsed['rules'] !== [] && ClientIpResolver::matchesAny($clientIp, $parsed['rules']);
    }
}
