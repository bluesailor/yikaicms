<?php
/**
 * 迁移 20260810_normalize_default_lang_shadow 的行为锁定。
 *
 * 病：非中文默认站上 <key>_<默认语言> 后缀行遮蔽 base，后台改了前台不生效
 * （kksky.ph 实测；安装器装英文站从不归位种子行，出厂即带病）。
 * 三规则已在 fhzn2 44 行实测验证，这里锁住每条规则与幂等性。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class NormalizeLangShadowMigrationTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $mig;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', '
            . 'options TEXT, sort_order INTEGER DEFAULT 0)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mig = require ROOT_PATH . '/migrations/20260810_normalize_default_lang_shadow.php';
    }

    private function seed(array $rows): void
    {
        foreach ($rows as $k => $v) {
            db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', [$k, $v]);
        }
    }

    private function val(string $key): ?string
    {
        $r = db()->fetchOne('SELECT value FROM settings WHERE "key" = ?', [$key]);
        return $r === null ? null : (string) $r['value'];
    }

    public function testChineseDefaultSiteIsNoop(): void
    {
        $this->seed(['site_lang' => 'zh-CN', 'foo_en' => 'x', 'foo' => '中文']);

        $this->assertTrue(($this->mig['check'])(), '中文默认站应视为已应用');
        ($this->mig['php'])();
        $this->assertSame('x', $this->val('foo_en'), '中文默认站一行都不能动');
    }

    public function testEnglishSiteWithShadowRowsIsPending(): void
    {
        $this->seed(['site_lang' => 'en', 'site_description_en' => 'seed text', 'site_description' => '中文种子']);

        $this->assertFalse(($this->mig['check'])(), '有默认语言后缀行 = 待归位');
    }

    public function testThreeRules(): void
    {
        $this->seed([
            'site_lang' => 'en',
            // R1a：base 行不存在 → 后缀行改名成 base
            'only_suffix_en' => 'promoted-by-rename',
            // R1b：base 行存在但为空 → 后缀值提升
            'empty_base' => '', 'empty_base_en' => 'promoted-by-copy',
            // R2：base 是中文种子 → 后缀覆盖（英文站不露中文）
            'seeded' => '中文出厂文案', 'seeded_en' => 'English seed',
            // R3：base 是站长编辑（非中文非空）→ 保留 base，站长的修改终于生效
            'edited' => 'CUSTOMER NEW TEXT', 'edited_en' => 'stale seed shadowing',
        ]);

        $msg = ($this->mig['php'])();

        $this->assertSame('promoted-by-rename', $this->val('only_suffix'), 'R1a 改名提升');
        $this->assertNull($this->val('only_suffix_en'));
        $this->assertSame('promoted-by-copy', $this->val('empty_base'), 'R1b 复制提升');
        $this->assertNull($this->val('empty_base_en'));
        $this->assertSame('English seed', $this->val('seeded'), 'R2 中文种子被英文覆盖');
        $this->assertNull($this->val('seeded_en'));
        $this->assertSame('CUSTOMER NEW TEXT', $this->val('edited'), 'R3 站长编辑必须保住——这是整个病的主诉');
        $this->assertNull($this->val('edited_en'));
        $this->assertStringContainsString('4', $msg);
    }

    public function testOtherLanguageSuffixesUntouched(): void
    {
        // 只归位默认语言的后缀；_ja / _zh-CN 是真翻译存储，不能动
        $this->seed([
            'site_lang' => 'en',
            'title' => 'Hello', 'title_en' => 'Hello', 'title_ja' => 'こんにちは', 'title_zh-CN' => '你好',
        ]);

        ($this->mig['php'])();

        $this->assertNull($this->val('title_en'), '_en 已归位删除');
        $this->assertSame('こんにちは', $this->val('title_ja'), '_ja 不能动');
        $this->assertSame('你好', $this->val('title_zh-CN'), '_zh-CN 不能动');
    }

    public function testIdempotent(): void
    {
        $this->seed(['site_lang' => 'en', 'k' => '中文', 'k_en' => 'EN']);

        ($this->mig['php'])();
        $this->assertTrue(($this->mig['check'])(), '归位后 check 应为已应用');
        $second = ($this->mig['php'])();
        $this->assertSame('EN', $this->val('k'), '重复执行不改变结果');
        $this->assertStringContainsString('无需归位', $second);
    }

    /** LIKE 的 `_` 必须转义：站点默认语言 en 时，形如 xxx_men 的键不能被误当 _en 后缀 */
    public function testUnderscoreEscaping(): void
    {
        $this->seed(['site_lang' => 'en', 'nav_men' => 'menu-config', 'real_en' => 'x', 'real' => '中文']);

        ($this->mig['php'])();

        $this->assertSame('menu-config', $this->val('nav_men'), 'nav_men 以 en 结尾但不是 _en 后缀，不能被卷入');
    }
}
