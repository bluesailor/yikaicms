<?php
/**
 * 老站 .htaccess 的语言前缀规则一键更新：只认随包发过的历史写法、只改那一行、保留换行风格与其余定制，
 * 改前备份、可还原。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use LanguageRouting;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguageRouting.php';

final class LanguageRoutingTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/yk-langroute-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array<string, array{string, string}> 历史上随包发过的写法 → 期望的 RewriteCond 前缀 */
    public static function legacyForms(): array
    {
        return [
            '2.0 YK_BASE' => ['    RewriteRule ^(ja|en|zh-CN|zh-TW)/(.*)$ %{ENV:YK_BASE}$2?_lang=$1 [QSA,L,DPI]', '%{DOCUMENT_ROOT}%{ENV:YK_BASE}lang/$1.php -f'],
            '1.x root' => ['    RewriteRule ^(ja|en|zh-CN|zh-TW)/(.*)$ /$2?_lang=$1 [QSA,L,DPI]', '%{DOCUMENT_ROOT}/lang/$1.php -f'],
            '1.x three' => ['    RewriteRule ^(ja|en|zh-CN)/(.*)$ /$2?_lang=$1 [QSA,L,DPI]', '%{DOCUMENT_ROOT}/lang/$1.php -f'],
            'subdir' => ["\tRewriteRule ^(en|ja)/(.*)$ /site/$2?_lang=$1 [QSA,L]", '%{DOCUMENT_ROOT}/site/lang/$1.php -f'],
        ];
    }

    /** @dataProvider legacyForms */
    public function testLegacyRuleBecomesTheGenericRule(string $line, string $cond): void
    {
        $text = "# my redirect\nRewriteEngine On\n# 多语言\n" . $line . "\n    RewriteRule ^news\\.html$ news.php [L]\n";
        $new = LanguageRouting::upgradedHtaccess($text);
        self::assertNotNull($new);
        self::assertStringContainsString('RewriteCond ' . $cond, $new);
        self::assertStringContainsString('RewriteRule ^([a-z]{2}(?:-[A-Z]{2})?)/(.*)$ ', $new);
        self::assertStringNotContainsString($line, $new);
        // 其余内容原样、顺序不变：自定义注释仍在最前，后续规则仍在后
        self::assertStringStartsWith("# my redirect\nRewriteEngine On\n# 多语言\n", $new);
        self::assertStringEndsWith("    RewriteRule ^news\\.html$ news.php [L]\n", $new);
        // 缩进与原行一致；标志保留
        preg_match('/^([ \t]*)RewriteRule/', $line, $indent);
        self::assertStringContainsString("\n" . $indent[1] . 'RewriteCond ', $new);
        preg_match('/(\[[A-Z,]+\])$/', $line, $flags);
        self::assertStringContainsString('$2?_lang=$1 ' . $flags[1], $new);
    }

    public function testCrlfFilesStayCrlfAndStatusFollowsTheRule(): void
    {
        $text = "RewriteEngine On\r\n    RewriteRule ^(ja|en|zh-CN|zh-TW)/(.*)$ %{ENV:YK_BASE}$2?_lang=$1 [QSA,L,DPI]\r\n";
        file_put_contents($this->dir . '/.htaccess', $text);
        self::assertSame(['state' => 'legacy', 'codes' => ['ja', 'en', 'zh-CN', 'zh-TW']], LanguageRouting::htaccessStatus($this->dir));

        $result = LanguageRouting::fixHtaccess($this->dir, $this->dir . '/storage/backups/htaccess');
        self::assertTrue($result['ok'], $result['error']);
        $new = (string) file_get_contents($this->dir . '/.htaccess');
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $new), 'no bare LF in a CRLF file');
        self::assertSame('current', LanguageRouting::htaccessStatus($this->dir)['state']);
        self::assertSame($text, file_get_contents($this->dir . '/storage/backups/htaccess/' . $result['backup']));

        self::assertTrue(LanguageRouting::restoreHtaccess($this->dir, $this->dir . '/storage/backups/htaccess', $result['backup']));
        self::assertSame($text, file_get_contents($this->dir . '/.htaccess'));
        self::assertFalse(LanguageRouting::restoreHtaccess($this->dir, $this->dir . '/storage/backups/htaccess', '../../.htaccess'));
    }

    public function testUnrecognizedOrAmbiguousFilesAreNotTouched(): void
    {
        self::assertSame('none', LanguageRouting::htaccessStatus($this->dir)['state']);
        $custom = "RewriteEngine On\nRewriteRule ^(ja|en)/(.*)$ index.php?lang=$1&p=$2 [L]\n";
        file_put_contents($this->dir . '/.htaccess', $custom);
        self::assertSame('custom', LanguageRouting::htaccessStatus($this->dir)['state']);
        self::assertSame('unrecognized', LanguageRouting::fixHtaccess($this->dir, $this->dir . '/storage/b')['error']);
        self::assertSame($custom, file_get_contents($this->dir . '/.htaccess'));
        // 两条同类规则：认不准改哪条，不动
        $twice = "RewriteRule ^(ja|en)/(.*)$ /$2?_lang=$1 [L]\nRewriteRule ^(ja|en)/(.*)$ /$2?_lang=$1 [L]\n";
        self::assertNull(LanguageRouting::upgradedHtaccess($twice));
    }

    public function testShippedHtaccessIsCurrentAndUncoveredLanguagesAreListed(): void
    {
        self::assertSame('current', LanguageRouting::htaccessStatus(ROOT_PATH)['state']);
        self::assertSame(['ko', 'de'], LanguageRouting::uncovered(['ja', 'en', 'zh-CN', 'zh-TW'], ['en', 'ko', 'de']));
        // ko 已注册但仓库里没有语言包：不按前缀路由，也就不在检查之列
        self::assertSame(['en'], LanguageRouting::prefixLanguages(['zh-CN', 'en', 'ko', 'xx'], 'zh-CN'));
    }

    public function testProbeIsAnsweredBeforeLanguageDetectionAndTheFixIsSandboxGuarded(): void
    {
        $init = (string) file_get_contents(ROOT_PATH . '/includes/init.php');
        self::assertLessThan(strpos($init, "if (!defined('SITE_LANG')) {"), strpos($init, 'LanguageRouting::respondProbe();'));
        self::assertLessThan(strpos($init, 'LanguageDomains::redirectTarget('), strpos($init, 'LanguageRouting::respondProbe();'));
        $page = (string) file_get_contents(ROOT_PATH . '/admin/setting_lang.php');
        self::assertStringContainsString("in_array(\$action, ['save_domains', 'fix_htaccess', 'restore_htaccess'], true) && defined('DEMO_SANDBOX') && DEMO_SANDBOX", $page);
    }
}
