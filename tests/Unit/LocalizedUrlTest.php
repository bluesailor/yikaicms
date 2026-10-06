<?php
/**
 * 2.0.5 LocalizedUrl：条目各语言网址、hreflang、语言切换与严格翻译策略的纯函数部分与接线契约。
 * 端到端行为见 tests/e2e/localized-urls.spec.js 与 frontend-language-boundaries.spec.js。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use LocalizedUrl;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
require_once ROOT_PATH . '/includes/i18n/LocalizedUrl.php';

final class LocalizedUrlTest extends TestCase
{
    public function testLangQueryKeepsTheRouteAndReplacesLanguage(): void
    {
        self::assertSame('/index.php?yk_route=article&slug=a&lang=en', LocalizedUrl::withLangQuery('/index.php?yk_route=article&slug=a', 'en'));
        self::assertSame('/index.php?yk_route=article&slug=a&lang=ja', LocalizedUrl::withLangQuery('/index.php?yk_route=article&lang=en&slug=a', 'ja'));
        self::assertSame('/index.php?yk_route=news', LocalizedUrl::withLangQuery('/index.php?yk_route=news&lang=en', ''), '默认语言不带参数');
        self::assertSame('/', LocalizedUrl::withLangQuery('/', ''));
    }

    public function testStripPrefixOnlyRemovesALeadingLanguageSegment(): void
    {
        self::assertSame('/news/article/a.html', LocalizedUrl::stripPrefix('/en/news/article/a.html'));
        self::assertSame('/news/a.html', LocalizedUrl::stripPrefix('/zh-TW/news/a.html'));
        self::assertSame('/', LocalizedUrl::stripPrefix('/ja'));
        self::assertSame('/english/a.html', LocalizedUrl::stripPrefix('/english/a.html'), '只认整段语言前缀');
        self::assertSame('/news/en/a.html', LocalizedUrl::stripPrefix('/news/en/a.html'));
    }

    public function testNoEntityMeansCallersKeepTheirPathRules(): void
    {
        LocalizedUrl::reset();
        self::assertNull(LocalizedUrl::current());
        self::assertNull(LocalizedUrl::hreflangTags());
        self::assertNull(LocalizedUrl::switchTarget('en'));
    }

    public function testDetailEntriesRegisterTheirRowAndPreviewIsNeverRedirected(): void
    {
        foreach (['article.php' => "LocalizedUrl::enter('content', \$_vars['content'], \$isNativeArticlePreview || isset(\$_GET['preview']));",
            'detail.php' => "LocalizedUrl::enter('content', \$_vars['content'], isset(\$_GET['preview']));",
            'product.php' => "LocalizedUrl::enter('product', \$_vars['product'], \$isNativeProductPreview || isset(\$_GET['preview']));"] as $file => $call) {
            self::assertStringContainsString($call, (string) file_get_contents(ROOT_PATH . '/' . $file), $file);
        }
        $source = (string) file_get_contents(ROOT_PATH . '/includes/i18n/LocalizedUrl.php');
        self::assertStringContainsString("if (\$rowLang === '' || \$rowLang === siteLang()) return;", $source, '繁体视图读简体数据不算缺译文');
        self::assertStringContainsString("header('Location: ' . \$target, true, 302);", $source, '302：以后补了译文同一网址可用');
        // 2.0.5 全新安装回归：默认语言是繁体 / 阿拉伯语的新站，演示数据是简体行而简体没启用，跳过去就是 404
        self::assertStringContainsString("if (function_exists('enabledLanguages') && !array_key_exists(\$rowLang, enabledLanguages())) return;", $source);
    }

    public function testHreflangAndSwitcherUseTheResolverAndDynamicModeKeepsTheRoute(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        $custom = strpos($functions, "customRouteHreflangs(productRouteModel()->translationPaths(");
        $entity = strpos($functions, '$entityTags = LocalizedUrl::hreflangTags();');
        $dynamic = strpos($functions, 'LocalizedUrl::withLangQuery(LocalizedUrl::stripPrefix($requestUri)');
        self::assertIsInt($custom);
        self::assertIsInt($entity);
        self::assertIsInt($dynamic);
        self::assertTrue($entity < $custom && $custom < $dynamic, '条目（含登记网址的条目）→ 其余登记网址 → 动态网址 → 按路径');
        self::assertStringContainsString('function contentLang(): string', $functions);
        $switcher = (string) file_get_contents(ROOT_PATH . '/includes/builder/elements/LanguageSwitcherElement.php');
        self::assertStringContainsString('$target = LocalizedUrl::switchTarget($language);', $switcher);
        self::assertContains('includes/i18n/LocalizedUrl.php', (require ROOT_PATH . '/config/release-runtime.php')['required_files']);
    }
    public function testChannelsOnlyRedirectSinglePagesAndPagedListsKeepPathRules(): void
    {
        // 单页栏目：正文是栏目自己的内容，缺译文按严格策略跳；/{别名}.html 各类栏目都先进 page.php，只认 page
        self::assertStringContainsString("if ((\$channel['type'] ?? '') === 'page') LocalizedUrl::enter('channel', \$channel, isset(\$_GET['preview']));",
            (string) file_get_contents(ROOT_PATH . '/page.php'));
        // 列表栏目：条目本就按当前语言筛选，回落到别的语言行时不登记、不跳
        self::assertStringContainsString("!in_array(\$channel['type'], ['link', 'page'], true) && in_array((string) (\$channel['lang'] ?? ''), ['', siteLang()], true)",
            (string) file_get_contents(ROOT_PATH . '/list.php'));
        // 第 2 页起不按条目给 hreflang / 切换目标（别的语言的第 N 页未必对应）
        LocalizedUrl::enter('channel', ['id' => 1, 'lang' => '', 'type' => 'list'], true, 2);
        self::assertSame(2, LocalizedUrl::current()['page'] ?? null);
        self::assertNull(LocalizedUrl::hreflangTags());
        self::assertNull(LocalizedUrl::switchTarget('en'));
        LocalizedUrl::reset();
        // 链接栏目没有自己的页面
        self::assertSame('', LocalizedUrl::urlFor('channel', ['id' => 1, 'type' => 'link', 'link_url' => 'https://example.com'], 'en'));
    }
}
