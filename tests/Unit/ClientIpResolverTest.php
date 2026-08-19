<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ClientIpResolver.php';
require_once ROOT_PATH . '/includes/AdminIpPolicy.php';

final class ClientIpResolverTest extends TestCase
{
    public function testDirectRequestIgnoresSpoofedForwardingHeaders(): void
    {
        $server = [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.20',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.30',
        ];

        self::assertSame('192.0.2.10', ClientIpResolver::resolve($server, ''));
        self::assertSame('192.0.2.10', ClientIpResolver::resolve($server, '10.0.0.0/8'));
    }

    public function testTrustedProxyUsesDirectClientHeaders(): void
    {
        self::assertSame('203.0.113.20', ClientIpResolver::resolve([
            'REMOTE_ADDR' => '10.0.0.8',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.20',
        ], '10.0.0.0/8'));
    }

    public function testForwardedChainWalksFromTheNearestProxy(): void
    {
        $server = [
            'REMOTE_ADDR' => '10.0.0.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.20, 198.51.100.30, 10.0.0.7',
        ];

        self::assertSame('198.51.100.30', ClientIpResolver::resolve($server, "10.0.0.0/8\n192.168.0.0/16"));
    }

    public function testIpv4AndIpv6CidrRulesAreSupported(): void
    {
        self::assertTrue(ClientIpResolver::matchesAny('192.168.20.9', '192.168.20.0/24'));
        self::assertFalse(ClientIpResolver::matchesAny('192.168.21.9', '192.168.20.0/24'));
        self::assertTrue(ClientIpResolver::matchesAny('2001:db8::42', '2001:db8::/32'));
        self::assertFalse(ClientIpResolver::matchesAny('2001:db9::42', '2001:db8::/32'));
    }

    public function testInvalidRulesAreReportedAndRestrictedPoliciesFailClosed(): void
    {
        $parsed = ClientIpResolver::parseRules("127.0.0.1\n10.0.0.0/33\nnot-an-ip");

        self::assertSame(['127.0.0.1'], $parsed['rules']);
        self::assertSame(['10.0.0.0/33', 'not-an-ip'], $parsed['invalid']);
        self::assertTrue(AdminIpPolicy::isAllowed('127.0.0.1', ''));
        self::assertTrue(AdminIpPolicy::isAllowed('127.0.0.1', []));
        self::assertFalse(AdminIpPolicy::isAllowed('127.0.0.1', 'not-an-ip'));
    }

    public function testAdminWhitelistSupportsExactAndCidrRules(): void
    {
        $rules = "203.0.113.8\n2001:db8:abcd::/48";

        self::assertTrue(AdminIpPolicy::isAllowed('203.0.113.8', $rules));
        self::assertTrue(AdminIpPolicy::isAllowed('2001:db8:abcd::5', $rules));
        self::assertFalse(AdminIpPolicy::isAllowed('203.0.113.9', $rules));
    }
}
