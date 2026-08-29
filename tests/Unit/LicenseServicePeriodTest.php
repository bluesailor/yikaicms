<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/License.php';

final class LicenseServicePeriodTest extends TestCase
{
    public function testExpiredServiceKeepsPurchasedModulesButStopsServiceEligibility(): void
    {
        $state = \license_apply_local_expiry([
            'valid' => true,
            'reason' => 'ok',
            'plan' => 'pro',
            'modules' => ['seo-pro', 'forms-pro'],
            'expires_at' => '2026-01-31',
            'expired' => false,
        ], strtotime('2026-02-01 00:00:00'));

        self::assertFalse($state['valid']);
        self::assertTrue($state['expired']);
        self::assertSame('expired', $state['reason']);
        self::assertSame('pro', $state['plan']);
        self::assertSame(['seo-pro', 'forms-pro'], $state['modules']);
        self::assertFalse(\license_service_active($state));
    }

    public function testActiveServiceStateIsUnchanged(): void
    {
        $state = [
            'valid' => true,
            'reason' => 'ok',
            'plan' => 'pro',
            'modules' => ['seo-pro'],
            'expires_at' => '2026-12-31',
            'expired' => false,
        ];

        self::assertSame($state, \license_apply_local_expiry($state, strtotime('2026-02-01 00:00:00')));
    }
}
