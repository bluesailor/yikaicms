<?php
/**
 * 整站包的语言分布（E08）。
 *
 * 要守住的是两条口径不混：声明启用的语言，和真的有内容的语言。
 * 差集才是"装完会空着的语言"——这个判断必须在覆盖式导入**之前**给出。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteTemplateLanguages;

require_once ROOT_PATH . '/includes/SiteTemplateLanguages.php';

final class SiteTemplateLanguagesTest extends TestCase
{
    /** @param list<string> $languages */
    private function data(array $declared, array $languages, string $siteLang = 'zh-CN'): array
    {
        return [
            'settings' => [
                'site_lang' => $siteLang,
                'enabled_languages' => $declared === [] ? '' : (string) json_encode($declared),
            ],
            'tables' => [
                'channels' => array_map(static fn (string $l): array => ['id' => 1, 'lang' => $l], $languages),
                'contents' => [],
                'products' => [],
            ],
        ];
    }

    public function testEmptyLanguagesAreTheDeclaredOnesWithoutContent(): void
    {
        $result = SiteTemplateLanguages::inspect($this->data(['zh-CN', 'en', 'ja'], ['zh-CN']));

        $this->assertSame(['en', 'ja', 'zh-CN'], $result['declared']);
        $this->assertSame(['zh-CN'], $result['content']);
        $this->assertSame(['en', 'ja'], $result['empty'], '声明了却没内容的语言就是导入后会空的那些');
    }

    public function testFullyCoveredPackageReportsNoGap(): void
    {
        $result = SiteTemplateLanguages::inspect($this->data(['zh-CN', 'en'], ['zh-CN', 'en']));
        $this->assertSame([], $result['empty']);
    }

    /** 没声明 enabled_languages 的包等于只有默认语言，不是"什么都没启用"。 */
    public function testPackageWithoutDeclaredLanguagesFallsBackToTheSiteLanguage(): void
    {
        $result = SiteTemplateLanguages::inspect($this->data([], ['zh-CN'], 'zh-CN'));
        $this->assertSame(['zh-CN'], $result['declared']);
        $this->assertSame([], $result['empty']);
    }

    /** 有内容但没声明的语言不算缺口——那是多出来的内容，不是空页面。 */
    public function testContentInAnUndeclaredLanguageIsNotAGap(): void
    {
        $result = SiteTemplateLanguages::inspect($this->data(['zh-CN'], ['zh-CN', 'ja']));
        $this->assertSame(['ja', 'zh-CN'], $result['content']);
        $this->assertSame([], $result['empty']);
    }

    /** 三张内容表都要看：只查频道会把只有产品的语言漏判成空。 */
    public function testAllContentTablesCount(): void
    {
        $data = $this->data(['zh-CN', 'en'], ['zh-CN']);
        $data['tables']['products'] = [['id' => 5, 'lang' => 'en']];
        $this->assertSame([], SiteTemplateLanguages::inspect($data)['empty']);
    }

    /** 残缺的包不该让预览炸掉：算不出来就当没有声明、没有内容。 */
    public function testMalformedPackageDataIsTolerated(): void
    {
        $this->assertSame(
            ['declared' => [], 'content' => [], 'empty' => []],
            SiteTemplateLanguages::inspect([])
        );
        $this->assertSame(
            ['declared' => [], 'content' => [], 'empty' => []],
            SiteTemplateLanguages::inspect(['tables' => 'nope', 'settings' => 7])
        );
        $this->assertSame(
            [],
            SiteTemplateLanguages::inspect([
                'settings' => ['enabled_languages' => '{not json'],
                'tables' => ['channels' => ['nope', ['lang' => '']]],
            ])['content']
        );
    }
}
