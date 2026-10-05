<?php
/**
 * 语言注册表是全站唯一的语言来源：URL 前缀、hreflang、服务器改写规则都要与它一致。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use LanguageRegistry;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
require_once ROOT_PATH . '/includes/Dispatcher.php';

final class LanguageRegistryTest extends TestCase
{
    private const SERVER_PATTERN = '[a-z]{2}(?:-[A-Z]{2})?';

    public function testEveryShippedLanguagePackIsRegistered(): void
    {
        foreach (glob(ROOT_PATH . '/lang/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if (str_starts_with($code, 'dict-')) continue;
            self::assertTrue(LanguageRegistry::has($code), "lang/$code.php 没有登记到 LanguageRegistry");
        }
    }

    public function testEntriesAreWellFormed(): void
    {
        foreach (LanguageRegistry::all() as $code => $lang) {
            // 服务器改写规则按这个形状通配（新增语言不用改 .htaccess / nginx）
            self::assertMatchesRegularExpression('/^' . self::SERVER_PATTERN . '$/D', $code);
            self::assertContains($lang['dir'], ['ltr', 'rtl'], $code);
            self::assertNotSame('', $lang['name'], $code);
            self::assertNotSame('', $lang['english'], $code);
            self::assertNotSame('', $lang['hreflang'], $code);
            if ($lang['flag'] !== '') {
                self::assertFileExists(ROOT_PATH . '/assets/icons/flags/' . $lang['flag'] . '.svg', $code);
            }
        }
        self::assertSame('zh-Hant', LanguageRegistry::hreflang('zh-TW'));
        self::assertTrue(LanguageRegistry::isRtl('ar'));
        // 2.0.4：波斯语从右到左、与阿拉伯语同用阿拉伯文字体；马来语从左到右（slewing-bearing.com 迁移需要）
        self::assertTrue(LanguageRegistry::isRtl('fa'));
        self::assertSame('ar', LanguageRegistry::fontGroup('fa'));
        self::assertFalse(LanguageRegistry::isRtl('ms'));
        self::assertSame('Bahasa Melayu', LanguageRegistry::name('ms'));
        self::assertTrue(LanguageRegistry::isReservedSlug('fa') && LanguageRegistry::isReservedSlug('ms'), '语言前缀不能被别名占用');
        self::assertFalse(LanguageRegistry::isRtl('en'));
    }

    public function testPrefixPatternMatchesLongestCodeFirst(): void
    {
        $pattern = LanguageRegistry::urlPrefixPattern();
        self::assertStringStartsWith('zh-CN|zh-TW|', $pattern);
        self::assertSame(1, preg_match('#^/(' . $pattern . ')(?=/|$)#', '/zh-TW/news.html', $m));
        self::assertSame('zh-TW', $m[1]);
        self::assertSame('de', LanguageRegistry::prefixOf('/de/about.html'));
        self::assertNull(LanguageRegistry::prefixOf('/design/about.html'));
    }

    public function testOnlyInstalledLanguagesBecomeUrlPrefixes(): void
    {
        self::assertSame('en', \Dispatcher::languagePrefixFromPath('/en/contact.html'));
        self::assertSame('zh-TW', \Dispatcher::languagePrefixFromPath('/zh-TW/'));
        $missing = null;
        foreach (LanguageRegistry::codes() as $code) {
            if (!is_file(ROOT_PATH . '/lang/' . $code . '.php')) { $missing = $code; break; }
        }
        if ($missing !== null) {
            // 注册了但没装语言包：当普通栏目别名（老站叫 it、de 的栏目照常能访问）
            self::assertNull(\Dispatcher::languagePrefixFromPath('/' . $missing . '/page.html'));
        }
    }

    public function testLanguageCodesAreReservedSlugs(): void
    {
        self::assertTrue(LanguageRegistry::isReservedSlug('de'));
        self::assertTrue(LanguageRegistry::isReservedSlug('zh-cn'));
        self::assertFalse(LanguageRegistry::isReservedSlug('design'));
        self::assertStringContainsString('LanguageRegistry::isReservedSlug', (string) file_get_contents(ROOT_PATH . '/admin/channel.php'));
        self::assertStringContainsString('LanguageRegistry::isReservedSlug', (string) file_get_contents(ROOT_PATH . '/includes/models/ChannelModel.php'));
    }

    public function testLocalizedFieldFallsBackToEnglishOnlyForNonHanLanguages(): void
    {
        $row = ['name' => '企业官网', 'name_en' => 'Corporate', 'name_ja' => '', 'name_de' => 'Firmenseite'];
        self::assertSame('Firmenseite', LanguageRegistry::localizedField($row, 'name', 'de'));
        self::assertSame('Corporate', LanguageRegistry::localizedField($row, 'name', 'ko'), '韩语缺译回落英文');
        self::assertSame('企业官网', LanguageRegistry::localizedField($row, 'name', 'ja'), '日语缺译仍回落中文基准');
        self::assertSame('企业官网', LanguageRegistry::localizedField($row, 'name', 'zh-TW'));
        self::assertSame('Corporate', LanguageRegistry::localizedField($row, 'name', 'en'));
        // 站点数据行：无后缀基准就是站点默认语言
        $jaSite = ['name' => 'ダウンロード', 'name_en' => 'Downloads'];
        self::assertSame('ダウンロード', LanguageRegistry::localizedField($jaSite, 'name', 'ja', 'ja'));
        self::assertSame('Downloads', LanguageRegistry::localizedField($jaSite, 'name', 'fr', 'ja'));
        self::assertSame('', LanguageRegistry::localizedField(['name' => ['x']], 'name', 'en'));
    }

    public function testUntranslatedKeysOfNewLanguagesFallBackToEnglish(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("if (\$lang !== 'en' && !LanguageRegistry::readsHan(\$lang) && file_exists(\$englishFile)) {", $functions);
        self::assertTrue(LanguageRegistry::readsHan('ja'));
        self::assertFalse(LanguageRegistry::readsHan('ko'));
    }

    /** 老站升级不会替换 .htaccess / nginx 配置，所以随包规则一律用通配前缀，新增语言零改动。 */
    public function testServerRulesUseTheGenericLanguagePrefix(): void
    {
        $apache = "RewriteCond %{DOCUMENT_ROOT}%{ENV:YK_BASE}lang/\$1.php -f\n    RewriteRule ^(" . self::SERVER_PATTERN . ')/(.*)$ %{ENV:YK_BASE}$2?_lang=$1 [QSA,L,DPI]';
        foreach (['.htaccess', 'deploy/aliyun-vhost.htaccess'] as $file) {
            $text = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/' . $file));
            self::assertStringContainsString($apache, $text, $file);
        }
        foreach (['deploy/nginx-baota.conf', 'deploy/nginx-server.conf', 'deploy/aliyun-nginx.htaccess'] as $file) {
            $text = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringContainsString('rewrite "^/' . self::SERVER_PATTERN . '/" /index.php last;', $text, $file);
        }
        foreach (['.htaccess', 'deploy/aliyun-vhost.htaccess', 'deploy/nginx-baota.conf', 'deploy/nginx-server.conf', 'deploy/aliyun-nginx.htaccess'] as $file) {
            self::assertStringNotContainsString('ja|en|zh-CN|zh-TW', (string) file_get_contents(ROOT_PATH . '/' . $file), $file);
        }
    }

    public function testLanguageNamesAreShownInTheInterfaceLanguage(): void
    {
        self::assertSame('马来语', LanguageRegistry::localName('ms', 'zh-CN'));
        self::assertSame('Persian', LanguageRegistry::localName('fa', 'en'));
        self::assertSame('マレー語', LanguageRegistry::localName('ms', 'ja'));
        self::assertSame('Malay', LanguageRegistry::localName('ms', 'xx'), '未知界面语言退回英文');
        $table = require ROOT_PATH . '/includes/i18n/language-names.php';
        foreach (LanguageRegistry::codes() as $ui) {
            foreach (LanguageRegistry::codes() as $code) {
                self::assertNotSame('', $table[$ui][$code] ?? '', "{$ui} 缺 {$code} 的名称：注册表改了要重跑 tools/build-language-names.js");
            }
        }
    }

    /** 后台语言列表（设置 → 语言、语言设置）：界面语言名 · 本族语名；相同或互含时只留本族语名。 */
    public function testLanguageTitlesPairInterfaceAndNativeNames(): void
    {
        self::assertSame('德语 · Deutsch', LanguageRegistry::title('de', 'Deutsch', 'zh-CN'));
        self::assertSame('马来语 · Bahasa Melayu', LanguageRegistry::title('ms', 'Bahasa Melayu', 'zh-CN'));
        self::assertSame('German · Deutsch', LanguageRegistry::title('de', 'Deutsch', 'en'));
        self::assertSame('中文', LanguageRegistry::title('zh-CN', '中文', 'zh-CN'));
        self::assertSame('English', LanguageRegistry::title('en', 'English', 'en'));

        $setting = (string) file_get_contents(ROOT_PATH . '/admin/setting.php');
        self::assertStringContainsString('LanguageRegistry::title($_lc, $_native, getLang())', $setting, '设置 → 语言也显示界面语言名');
    }
}
