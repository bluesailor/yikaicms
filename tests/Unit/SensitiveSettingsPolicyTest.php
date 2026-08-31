<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 配方导出/导入的敏感设置闸。
 *
 * 起因：v1.19.3 之前 RecipeService 自带一份四项黑名单，其中三项键名与库里真实的键
 * 对不上（黑名单写 smtp_password，真实键叫 smtp_pass），于是 cron_token、license_key、
 * seo_indexnow_key 全都被原样导出过。cron_token 就是 DemoSandbox 的站长口令——
 * 导一次配方等于把守门的钥匙一起交出去。
 *
 * 本测试把「键名漂移」钉死：新增 secret 只要沿用 *_key / *_token / *pass* 命名，
 * 就必须自动被拦下，不能依赖有人记得回来补名单。
 */
final class SensitiveSettingsPolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/SensitiveSettings.php';
    }

    /** @return list<array{0:string}> */
    public static function secretKeyProvider(): array
    {
        return [
            ['cron_token'],          // = DemoSandbox 站长口令
            ['encrypt_key'],
            ['license_key'],
            ['license_state'],
            ['ai_api_key'],
            ['smtp_pass'],           // 旧黑名单写的是 smtp_password，对不上
            ['smtp_user'],
            ['translate_api_key'],
            ['map_amap_key'],
            ['seo_indexnow_key'],
            // 尚不存在、但按现有命名习惯迟早会出现的键：也必须被兜住
            ['stripe_secret_key'],
            ['wechat_app_secret'],
            ['oss_access_key_id'],
            ['webhook_signature'],
            ['session_salt'],
        ];
    }

    /** @dataProvider secretKeyProvider */
    public function testSecretsAreNeverExportable(string $key): void
    {
        self::assertTrue(
            SensitiveSettings::isSensitive($key),
            "{$key} 必须判为敏感；否则它会被写进配方 JSON 随模板分发出去。"
        );
        self::assertFalse(SensitiveSettings::isImportable($key), "{$key} 不该允许被外来配方写入。");
    }

    /** 误伤自检：名字里带 key，但只是 SEO 关键词，必须照常导出 */
    public function testKeywordsAreNotMistakenForSecrets(): void
    {
        foreach (['site_keywords', 'site_keywords_en', 'site_keywords_ja', 'seo_keywords'] as $key) {
            self::assertFalse(SensitiveSettings::isSensitive($key), "{$key} 是可公开的 SEO 关键词，不该被当成 secret 剔除。");
        }
    }

    public function testExportFilterDropsSecretsAndReportsThem(): void
    {
        $result = SensitiveSettings::filterExportable([
            'site_name' => 'Demo',
            'site_keywords' => 'cms,企业官网',
            'cron_token' => 'd6ccc5f4c3518baa724dd898aab4cdb7',
            'license_key' => 'YIKAI-XXXXX',
            'footer_copyright' => '© 2026',
        ]);

        self::assertSame(['footer_copyright', 'site_keywords', 'site_name'], self::sortedKeys($result['settings']));
        self::assertSame(['cron_token', 'license_key'], $result['excluded']);
        self::assertStringNotContainsString(
            'd6ccc5f4c3518baa724dd898aab4cdb7',
            json_encode($result, JSON_UNESCAPED_UNICODE) ?: '',
            'excluded 清单只该记键名，绝不能把值带出来。'
        );
    }

    /**
     * 导入侧比导出侧更严：这些键描述「这台站点是谁 / 这台机器怎么跑」，
     * 任何外来配方都不该改写。全新站上它们多半是空的，
     * RecipeService 里「已有非空值则跳过」那层保护根本挡不住。
     */
    public function testMachineIdentityKeysCannotBeImported(): void
    {
        foreach (['site_url', 'demo_mode', 'installed', 'enabled_languages', 'official_media_api_base'] as $key) {
            self::assertFalse(SensitiveSettings::isImportable($key), "{$key} 属于本机身份，不该由配方写入。");
        }
    }

    public function testAllSecurityDefaultsAndMachineSettingsAreExcludedInBothDirections(): void
    {
        $defaults = require ROOT_PATH . '/config/defaults.php';
        $keys = array_merge(array_keys($defaults['security']), [
            'site_url', 'demo_mode', 'demo_reset_interval', 'installed', 'install_time',
            'cms_version', 'db_version', 'enabled_languages', 'site_lang', 'admin_lang',
            'official_media_api_base', 'static_html_base_url', 'static_html_enabled', 'static_html_last_gen',
            'demo_owner_token',
        ]);
        foreach ($keys as $key) {
            foreach ([$key, strtoupper($key), ' ' . $key . ' '] as $variant) {
                self::assertSame([], SensitiveSettings::filterImportable([$variant => 'attack'])['settings'], $variant);
                self::assertSame([], SensitiveSettings::filterExportable([$variant => 'private'])['settings'], $variant);
            }
        }
        self::assertSame(['site_name' => 'Company'], SensitiveSettings::filterImportable(['site_name' => 'Company'])['settings']);
    }

    public function testImportFilterRejectsAndReports(): void
    {
        $result = SensitiveSettings::filterImportable([
            'site_name' => '被配方带来的名字',
            'theme_color' => '#2563EB',
            'cron_token' => 'attacker-known-value',
            'demo_mode' => '0',
        ]);

        self::assertSame(['site_name', 'theme_color'], self::sortedKeys($result['settings']));
        self::assertSame(['cron_token', 'demo_mode'], $result['rejected']);
    }

    /** @param array<string,string> $settings @return list<string> */
    private static function sortedKeys(array $settings): array
    {
        $keys = array_keys($settings);
        sort($keys);
        return $keys;
    }
}
