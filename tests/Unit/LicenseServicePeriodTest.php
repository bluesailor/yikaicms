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

    public function testProfessionalLicencesOwnBloxProIncludingThoseIssuedBeforeTheBloxModule(): void
    {
        // 新授权：带 blox 模块
        self::assertTrue(\license_owns_blox(['valid' => true, 'plan' => 'pro', 'modules' => ['blox']]));
        // 老授权：blox 模块推出前签发，只含其它付费模块，同样拥有（专业授权自带 BLOX 高级功能）
        self::assertTrue(\license_owns_blox(['valid' => true, 'plan' => 'pro', 'modules' => ['stats', 'seo', 'ai', 'oss']]));
        // 服务期到期不收回：到期后服务端把 plan 降为 free，但模块照常下发
        self::assertTrue(\license_owns_blox(['valid' => false, 'plan' => 'free', 'expired' => true, 'modules' => ['seo-pro']]));
        // 没有付费模块：免费、停用、域名不符（服务端不下发 modules）都不放行
        foreach ([[], ['valid' => false, 'reason' => 'no_key', 'plan' => 'free', 'modules' => []], ['modules' => ['', null, 1]]] as $state) {
            self::assertFalse(\license_owns_blox($state));
        }
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
