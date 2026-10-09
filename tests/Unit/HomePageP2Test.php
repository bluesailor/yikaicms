<?php
/**
 * 首页源码审查 P2（2026-10-10）：分类筛选含子分类、读屏文字、分享图与组织 logo、未翻译语言的站点检查。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxBlockSchema;
use ReflectionMethod;
use SiteContentChecks;
use Yikai\Tests\TestCase;

class HomePageP2Test extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, lang TEXT, status INTEGER DEFAULT 1)',
        ];
    }

    public function testParentCategoryButtonsIncludeTheirSubcategories(): void
    {
        foreach (['themes/default/blocks/channel.php', 'includes/blocks/channel.php'] as $file) {
            $source = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringContainsString("data-category-ids=\"<?php echo e(implode(' ', productCategoryModel()->getChildIds((int) \$cat['id']))); ?>\"", $source, $file);
            self::assertStringContainsString('aria-pressed="true"', $source, $file . '：「全部」初始为按下');
        }
        $index = (string) file_get_contents(ROOT_PATH . '/index.php');
        self::assertStringContainsString('const ids = (this.dataset.categoryIds || cat).split(" ");', $index);
        self::assertStringContainsString('ids.includes(itemCat)', $index, '子分类的产品在父分类按钮下也显示');
        self::assertStringContainsString('this.setAttribute("aria-pressed", "true");', $index);
    }

    public function testBannerScreenReaderLabelsFollowThePageLanguage(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $attributes = HomeBloxBlockSchema::bannerRuntimeAttributes([]);
        self::assertStringContainsString('data-blox-label-prev="detail_prev_photo"', $attributes, '测试环境 __() 原样返回键名');
        self::assertStringContainsString('data-blox-label-next="detail_next_photo"', $attributes);
        self::assertStringContainsString('data-blox-label-dot="blox_carousel_dot_label"', $attributes);

        $js = (string) file_get_contents(ROOT_PATH . '/assets/js/yikay-banner.js');
        self::assertStringContainsString("options.a11y.prevSlideMessage = labelPrev;", $js);
        self::assertStringContainsString("labelDot.replace('%d', '{{index}}')", $js);
    }

    public function testDecorativeAdvantageIconsAreHiddenFromScreenReaders(): void
    {
        foreach (['themes/default/blocks/advantage.php', 'includes/blocks/advantage.php'] as $file) {
            self::assertStringContainsString('viewBox="0 0 20 20" aria-hidden="true" focusable="false">', (string) file_get_contents(ROOT_PATH . '/' . $file), $file);
        }
    }

    public function testHomeShareImageIsARasterBannerAndTheOrganizationLogoStaysTheLogo(): void
    {
        $index = (string) file_get_contents(ROOT_PATH . '/index.php');
        self::assertStringContainsString("preg_match('/\\.(?:jpe?g|png|webp)(?:\\?.*)?$/i', \$ykBannerImage)", $index, '社交平台不收 SVG');
        self::assertLessThan(strpos($index, "require_once theme_path('layouts/header.php');"), strpos($index, '$ykHomeShareImage = \'\';'));
        foreach (['themes/default/layouts/header.php', 'includes/header.php'] as $file) {
            $header = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringContainsString("?: ((\$ykHomeShareImage ?? '') ?: \$siteLogo)", $header, $file);
            self::assertStringNotContainsString("'logo' => \$ogImage", $header, $file . '：Organization logo 不能是分享图');
            self::assertStringContainsString("'logo' => \$siteLogo ?", $header, $file);
        }
    }

    public function testEnabledLanguageWithoutChannelsIsReported(): void
    {
        require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
        require_once ROOT_PATH . '/includes/SiteContentChecks.php';
        foreach ([['首页', 'zh-CN'], ['Home', 'en']] as [$name, $lang]) {
            db()->execute('INSERT INTO channels (name, lang, status) VALUES (?, ?, 1)', [$name, $lang]);
        }
        db()->execute('INSERT INTO channels (name, lang, status) VALUES (?, ?, 0)', ['Entwurf', 'de']);   // 未启用的栏目不算
        $GLOBALS['_test_config']['enabled_languages'] = json_encode(['zh-CN', 'en', 'de', 'zh-TW']);
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';

        $issues = [];
        $method = new ReflectionMethod(SiteContentChecks::class, 'inspectLanguages');
        $method->setAccessible(true);
        $method->invokeArgs(null, [&$issues]);

        $flagged = array_values(array_map(static fn(array $i): string => $i['label'], array_filter($issues, static fn(array $i): bool => $i['kind'] === 'sc_lang_untranslated')));
        self::assertSame(['Deutsch'], $flagged, '只报德语：英语有栏目，默认语言与繁体（由简体转换）不报');
        self::assertSame('/admin/setting_lang.php', array_values($issues)[0]['url']);
        unset($GLOBALS['_test_config']['enabled_languages'], $GLOBALS['_test_config']['site_lang']);
    }
}
