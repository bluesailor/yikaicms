<?php
/**
 * 网站常用词典（lang/dict-zh-*.php，2.0.4 起覆盖全部非中文语言）：栏目自动翻译、批量添加常用栏目、
 * AI 翻译前预查都靠它。每种注册语言都要有一份，键与英文词典一一对应，值不能空、不能留中文。
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';

final class SiteDictionaryTest extends TestCase
{
    public function testEveryRegisteredLanguageHasACompleteDictionary(): void
    {
        $base = require ROOT_PATH . '/lang/dict-zh-en.php';
        foreach (LanguageRegistry::codes() as $code) {
            if (LanguageRegistry::isChinese($code)) {
                continue;
            }
            $file = ROOT_PATH . '/lang/dict-zh-' . $code . '.php';
            self::assertFileExists($file, "缺 {$code} 的网站常用词典");
            $dict = require $file;
            self::assertSame(array_keys($base), array_keys($dict), "{$code} 词典的键要与英文词典一致（同样的顺序）");
            foreach ($dict as $zh => $value) {
                self::assertNotSame('', trim((string) $value), "{$code}: {$zh} 没有译名");
                if (!LanguageRegistry::readsHan($code)) {   // 日文本来就用汉字
                    self::assertDoesNotMatchRegularExpression('/\p{Han}/u', (string) $value, "{$code}: {$zh} 的译名里还有中文");
                }
            }
        }
    }

    public function testSeededAndCatalogChannelNamesAreAllInTheDictionary(): void
    {
        $base = require ROOT_PATH . '/lang/dict-zh-en.php';
        $catalog = require ROOT_PATH . '/includes/channel_catalog.php';
        foreach ($catalog['items'] as $key => $item) {
            self::assertArrayHasKey($item['name'], $base, "「批量添加常用栏目」的 {$key} 不在词典里，其它语言会退回英文");
        }
        $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');
        preg_match_all("/INSERT INTO \"yikai_channels\" \\([^)]*\\) VALUES \\(\\d+,'zh-CN',\\d+,\\d+,'([^']+)'/u", $sql, $m);
        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $name) {
            self::assertArrayHasKey($name, $base, "安装自带的栏目「{$name}」不在词典里，启用新语言时只能靠 AI 翻译");
        }
    }

    public function testAdminLanguageListTreatsTheOldInstallDefaultAsUnlimited(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("if (\$raw === '' || \$raw === 'zh-CN,en,ja') {", $functions);
        self::assertStringContainsString("return \$value === 'zh-CN,en,ja' ? \$value . ',' : \$value;", $functions, '站长真选这三种时要能区分');
        foreach (['mysql', 'sqlite'] as $driver) {
            $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/' . $driver . '.sql');
            self::assertStringContainsString("'admin_languages','','text'", $sql, "新装站点（{$driver}）默认不限制后台语言");
        }
        foreach (['admin/includes/header.php', 'admin/setting.php'] as $file) {
            self::assertStringContainsString('adminLanguageChoices()', (string) file_get_contents(ROOT_PATH . '/' . $file), $file);
        }
    }
}
