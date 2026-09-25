<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/License.php';

/** 2026-09-25 裁决：整站模板「导出」归专业版，「导入」免费。 */
final class SiteTemplateExportLicenseTest extends TestCase
{
    public function testExportNeedsALicenseAndKeepsWorkingAfterTheServicePeriod(): void
    {
        self::assertTrue(\license_allows_site_template_export(['valid' => true, 'plan' => 'basic', 'modules' => []]));
        // 永久回退：服务期到期、仍持有付费模块，导出照常可用
        self::assertTrue(\license_allows_site_template_export(['valid' => false, 'expired' => true, 'modules' => ['blox']]));
        self::assertFalse(\license_allows_site_template_export(\license_free('no_key')));
        self::assertFalse(\license_allows_site_template_export(['valid' => false, 'reason' => 'domain_mismatch', 'modules' => []]));
    }

    public function testBothExportActionsAreGatedAndImportIsNot(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/site_templates.php');
        $gate = strpos($page, "if (in_array(\$action, ['export', 'check_export'], true) && !\$exportAllowed) throw new RuntimeException('st_export_pro');");
        self::assertIsInt($gate);
        self::assertLessThan((int) strpos($page, '$service->exportCheck()'), $gate, '授权检查在任何导出动作之前');
        self::assertLessThan((int) strpos($page, '$service->export($temporary)'), $gate);
        self::assertStringContainsString('$exportAllowed = license_allows_site_template_export();', $page);
        // 导入相关动作不经过授权检查
        foreach (["'prepare'", "'install_plugins'", "'refresh_preview'", "'stage'", "'apply'", "'restore'"] as $importAction) {
            self::assertStringNotContainsString('in_array($action, [' . $importAction, $page);
        }
        self::assertStringNotContainsString('license_allows_site_template_export', (string) file_get_contents(ROOT_PATH . '/admin/site_template_market.php'), '模板市场导入免费');
        // 未授权时不给导出按钮，提示去填注册码
        self::assertStringContainsString('data-testid="st-export-locked"', $page);
    }
}
