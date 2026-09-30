<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 元素设置依赖（2.0.3，来自英文模板制作反馈）：元素直接读取的站点设置必须声明，
 * 且声明与整站包导出白名单一致——社媒入口不随包导出这类问题在测试里就会暴露，
 * 而不是在客户站上才发现。另外禁止数据驱动的图标名绕过 BloxIcon（子集外字形会空白）。
 */
final class BloxElementSettingDependencyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        require_once ROOT_PATH . '/includes/SiteTemplateData.php';
        require_once ROOT_PATH . '/includes/SiteExportChecks.php';
    }

    /** @return array<string,AbstractElement> 文件名 → 元素实例 */
    private static function elementsByFile(): array
    {
        $byClass = [];
        foreach (BuilderRegistry::all() as $element) {
            $byClass[get_class($element)] = $element;
        }
        $out = [];
        foreach (glob(ROOT_PATH . '/includes/builder/elements/*.php') ?: [] as $file) {
            $class = basename($file, '.php');
            if (isset($byClass[$class])) {
                $out[$file] = $byClass[$class];
            }
        }
        return $out;
    }

    public function testEverySettingAnElementReadsIsDeclared(): void
    {
        $checked = 0;
        foreach (self::elementsByFile() as $file => $element) {
            $source = (string) file_get_contents($file);
            preg_match_all("/\\b(?:config|configRawLang|configJsonLang)\\(\\s*'([a-z0-9_]+)'/", $source, $m);
            // 形如 ['setting' => 'contact_phone'] 的数据驱动读取同样算
            preg_match_all("/'setting'\\s*=>\\s*'([a-z0-9_]+)'/", $source, $dynamic);
            $read = array_unique(array_merge($m[1], $dynamic[1]));
            $declared = array_keys($element->settingDependencies());
            foreach ($read as $key) {
                self::assertContains($key, $declared, basename($file) . " reads setting '$key' but does not declare it in settingDependencies()");
                $checked++;
            }
        }
        self::assertGreaterThan(10, $checked, 'the scan actually found setting reads');
    }

    public function testDeclarationsAgreeWithTheExportWhitelist(): void
    {
        foreach (BuilderRegistry::all() as $element) {
            foreach ($element->settingDependencies() as $key => $scope) {
                self::assertContains($scope, ['portable', 'optional', 'site'], $element->type() . ".$key");
                if ($scope === 'site') {
                    self::assertFalse(SiteTemplateData::settingAllowed($key), "$key belongs to the target site and must not be exported");
                } else {
                    self::assertTrue(SiteTemplateData::settingAllowed($key), $element->type() . " declares '$key' portable but site packages drop it");
                    self::assertFalse(SensitiveSettings::isSensitive($key), "$key must not be a sensitive setting");
                }
            }
        }
    }

    public function testDataDrivenIconNamesGoThroughBloxIcon(): void
    {
        foreach (glob(ROOT_PATH . '/includes/builder/elements/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            // 字面量图标（'ti ti-search'）由子集审计扫描收录；拼接变量的图标名扫描看不到，必须经 BloxIcon::classes()
            self::assertDoesNotMatchRegularExpression("/['\"](?:ti ti-|bi bi-)['\"]\\s*\\.\\s*\\$/", $source, basename($file));
        }
    }

    public function testExportCheckReportsEmptyAndTargetSiteSettings(): void
    {
        $footer = json_encode([['columns' => [['elements' => [
            ['id' => 'a', 'type' => 'social-links', 'data' => []],
            ['id' => 'b', 'type' => 'site-filing', 'data' => []],
            ['id' => 'c', 'type' => 'cta', 'data' => []],
            ['id' => 'd', 'type' => 'site-contact', 'data' => []],
        ]]]]]);
        $data = ['settings' => [
            'blox_footer_published' => $footer, 'social_links' => '[]',
            'contact_phone' => '+1 555', 'contact_email' => '', 'contact_address_en' => '1 Main St',
        ], 'tables' => []];
        $issues = [];
        foreach (SiteExportChecks::settingDependencyIssues($data) as $issue) {
            $issues[$issue['detail']] = $issue['code'];
        }
        self::assertSame('usability_export_setting_empty', $issues['social_links'] ?? null, 'empty social links are reported');
        self::assertSame('usability_export_setting_site', $issues['site_icp'] ?? null);
        self::assertSame('usability_export_setting_site', $issues['site_police'] ?? null);
        self::assertSame('usability_export_setting_empty', $issues['contact_email'] ?? null);
        self::assertArrayNotHasKey('contact_phone', $issues);
        self::assertArrayNotHasKey('contact_address', $issues, 'a language variant counts as a value');
        self::assertArrayNotHasKey('home_cta_link', $issues, 'optional settings with a built-in fallback are not reported');

        $data['settings']['social_links'] = '[{"platform":"x","url":"https://x.com/a"}]';
        $again = array_column(SiteExportChecks::settingDependencyIssues($data), 'code', 'detail');
        self::assertArrayNotHasKey('social_links', $again);
        self::assertSame([], SiteExportChecks::settingDependencyIssues(['settings' => [], 'tables' => []]), 'no elements, no issues');
    }

    public function testProductLayoutTravelsWithTheTemplate(): void
    {
        self::assertTrue(SiteTemplateData::settingAllowed('product_layout'));
    }
}
