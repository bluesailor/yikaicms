<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/DefaultLangShadow.php';
require_once ROOT_PATH . '/includes/Migrator.php';
require_once ROOT_PATH . '/includes/SiteTemplateData.php';

/**
 * 回归（2026-09-30，英文 Flow 整站模板）：全新安装零待执行，导入整站模板后后台却提示
 * 「数据库有 6 项升级待执行」。原因不在安装：导入整表替换了种子类迁移 check() 读的内容
 * （询盘表单、下载分类、法务页正文、内置页脚、site_logo_max_height），又把默认语言切到 en
 * 却留着 <key>_en 后缀行。修法：后缀行在导入时真正归位；被合法替换的种子迁移由导入登记为已了结。
 */
final class SiteTemplateImportMigrationsTest extends TestCase
{
    public function testImportLeavesNoPendingMigrationsAndUndoRestoresLanguageRows(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-migrations-probe.php') . ' 2>&1');
        self::assertSame("Site template import keeps migrations settled\n", $output);
    }

    public function testPackageShadowRowsFoldWithTheMigrationRules(): void
    {
        $allowed = static fn(string $key): bool => $key !== 'blocked';
        $folded = DefaultLangShadow::fold([
            'site_lang' => 'en',
            'site_name' => 'Flow', 'site_name_en' => 'Flow',                      // 相同：去掉后缀行
            'site_description' => '中文种子', 'site_description_en' => 'English', // 中文种子被英文顶替
            'site_logo_en' => '/uploads/logo.svg',                                // base 缺失：提升
            'contact_form_title' => 'Edited', 'contact_form_title_en' => 'Seed',  // base 是编辑：保留 base
            'blocked_en' => 'kept',                                               // base 不可写：原样保留
            'home_blox_published_ja' => '{}',                                     // 非默认语言：不动
        ], 'en', $allowed);
        self::assertSame([
            'site_lang' => 'en',
            'site_name' => 'Flow',
            'site_description' => 'English',
            'contact_form_title' => 'Edited',
            'blocked_en' => 'kept',
            'home_blox_published_ja' => '{}',
            'site_logo' => '/uploads/logo.svg',
        ], $folded);
        // 中日文默认语言与迁移 20260810 一样不归位（CJK 启发不成立）
        self::assertSame(['site_name' => 'A', 'site_name_ja' => 'B'], DefaultLangShadow::fold(['site_name' => 'A', 'site_name_ja' => 'B'], 'ja', $allowed));
    }

    public function testMigrationAndImportShareOneRuleSet(): void
    {
        $migration = (string) file_get_contents(ROOT_PATH . '/migrations/20260810_normalize_default_lang_shadow.php');
        self::assertStringContainsString('DefaultLangShadow::applies($default)', $migration);
        self::assertStringContainsString('DefaultLangShadow::rule($bVal, $sVal)', $migration);
        $service = (string) file_get_contents(ROOT_PATH . '/includes/SiteTemplateService.php');
        self::assertStringContainsString('DefaultLangShadow::fold(', $service);
        self::assertStringContainsString('$this->restoreLanguageRows($journal);', $service, 'undo reverses the language switch of site-owned rows');
    }

    public function testSettledLedgerIsNeitherExportedNorReplacedByAnImport(): void
    {
        self::assertFalse(SiteTemplateData::settingAllowed(Migrator::SETTLED_KEY));
    }

    public function testSettledMigrationCountsAsAppliedOnlyWhenItsCheckIsCallable(): void
    {
        $ref = new ReflectionProperty(Migrator::class, 'settled');
        $ref->setValue(null, ['m_settled']);
        try {
            self::assertTrue(Migrator::isApplied(['id' => 'm_settled', 'check' => static fn(): bool => false]));
            self::assertFalse(Migrator::isApplied(['id' => 'm_other', 'check' => static fn(): bool => false]));
            self::assertFalse(Migrator::isApplied(['id' => 'm_settled']), 'a malformed migration is never treated as applied');
        } finally {
            Migrator::forgetSettled();
        }
    }
}
