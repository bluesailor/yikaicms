<?php
/**
 * 首页多语言种子数据（2026-10-10：日语首页客户评价显示中文、Banner/CTA 按钮指向中文页、次按钮 href="#"）。
 * 迁移 20261010_home_seed_i18n 给旧站补译文绑定与按钮链接；模板按当前语言输出站内链接、没有链接的按钮不出。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use LocalizedUrl;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
require_once ROOT_PATH . '/includes/i18n/LocalizedUrl.php';

class HomeSeedI18nTest extends TestCase
{
    private const SEED_CONTENT = '从选型到交付只用了三周，技术团队全程跟进，现场调试一次通过。后续两条产线也继续选择了他们。';

    /** @var array<string,mixed> */
    private array $mig;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', '
            . 'options TEXT, sort_order INTEGER DEFAULT 0, UNIQUE("key"))',   // 与线上表一致：迁移后清缓存会 upsert 设置
            'CREATE TABLE banners (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT DEFAULT \'zh-CN\', '
            . 'btn1_text TEXT DEFAULT \'\', btn1_url TEXT DEFAULT \'\', btn2_text TEXT DEFAULT \'\', btn2_url TEXT DEFAULT \'\')',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mig = require ROOT_PATH . '/migrations/20261010_home_seed_i18n.php';
    }

    /** @param list<string> $contents @return array<string,mixed> */
    private static function doc(array $contents): array
    {
        $items = array_map(static fn (string $c): array => ['name' => 'n', 'role' => 'r', 'content' => $c, 'rating' => '5'], $contents);
        return ['schema' => 1, 'sections' => [[
            'id' => 'home_s_7', 'type' => 'section', 'settings' => ['title' => '客户评价'],
            'columns' => [['id' => 'c', 'elements' => [['id' => 'e', 'type' => 'testimonial-carousel', 'data' => ['items' => $items]]]]],
        ]]];
    }

    private function seed(string $key, array $doc): void
    {
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', [$key, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /** @return array<string,mixed> */
    private function element(string $key): array
    {
        $row = db()->fetchOne('SELECT value FROM settings WHERE "key" = ?', [$key]);
        return json_decode((string) $row['value'], true)['sections'][0]['columns'][0]['elements'][0]['data'];
    }

    public function testSeedTestimonialsGetTheTranslationBindingAndEditedOnesAreLeftAlone(): void
    {
        $this->seed('home_blox_published', self::doc([self::SEED_CONTENT]));
        $this->seed('home_blox_data', self::doc([self::SEED_CONTENT, '站长自己写的评价']));
        self::assertFalse(($this->mig['check'])());

        self::assertSame('testimonials 1, banners 0', ($this->mig['php'])());
        $binding = $this->element('home_blox_published')['_home_testimonials_i18n'];
        self::assertSame('zh-CN', $binding['lang']);
        self::assertSame(self::SEED_CONTENT, $binding['source']['items'][0]['content']);
        self::assertSame('Siyuan Chen', $binding['translations']['en']['items'][0]['name']);
        self::assertArrayHasKey('ja', $binding['translations']);
        self::assertArrayNotHasKey('_home_testimonials_i18n', $this->element('home_blox_data'), '换过内容的不补');

        self::assertTrue(($this->mig['check'])());
        self::assertSame('testimonials 0, banners 0', ($this->mig['php'])(), '可重复跑');
    }

    public function testBindingMatchesTheInstallSeed(): void
    {
        $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');
        preg_match("/'home','home_blox_published','(.*?)'\\s*,\\s*'/s", $sql, $m);
        $doc = json_decode(str_replace("''", "'", $m[1]), true);
        $seedBinding = null;
        $walk = static function (mixed $node) use (&$walk, &$seedBinding): void {
            if (!is_array($node)) return;
            if (($node['type'] ?? '') === 'testimonial-carousel') $seedBinding = $node['data']['_home_testimonials_i18n'] ?? null;
            foreach ($node as $child) $walk($child);
        };
        $walk($doc);
        $this->seed('home_blox_published', self::doc([self::SEED_CONTENT]));
        ($this->mig['php'])();
        self::assertSame($seedBinding, $this->element('home_blox_published')['_home_testimonials_i18n'], '迁移补的绑定与全新安装的一致');
    }

    public function testDemoBannerSecondaryButtonsGetLinks(): void
    {
        $insert = 'INSERT INTO banners (lang, btn1_text, btn1_url, btn2_text, btn2_url) VALUES (?, ?, ?, ?, ?)';
        db()->execute($insert, ['en', 'Learn More', '/about.html', 'Contact Sales', '']);
        db()->execute($insert, ['ja', 'サービス内容', '/service.html', 'サービスを見る', '']);
        db()->execute($insert, ['en', 'Learn More', '/about.html', 'View Cases', '/my-cases.html']);
        db()->execute($insert, ['zh-CN', '了解更多', '/about.html', '', '']);

        self::assertSame('testimonials 0, banners 2', ($this->mig['php'])());
        $rows = db()->fetchAll('SELECT btn2_text, btn2_url FROM banners ORDER BY id');
        self::assertSame(['btn2_text' => 'Contact Sales', 'btn2_url' => '/contact.html'], $rows[0]);
        self::assertSame(['btn2_text' => 'お問い合わせ', 'btn2_url' => '/contact.html'], $rows[1], '与主按钮重复的「サービスを見る」换成问询');
        self::assertSame('/my-cases.html', $rows[2]['btn2_url'], '站长填过的链接不动');
        self::assertSame('', $rows[3]['btn2_url']);
        self::assertTrue(($this->mig['check'])());
    }

    public function testInstallSeedBannersHaveNoButtonWithoutALink(): void
    {
        foreach (['sqlite.sql', 'mysql.sql'] as $file) {
            $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/' . $file);
            preg_match_all("/INSERT INTO [`\"]yikai_banners[`\"].*?VALUES \\(\\d+,'home','[^']*',\\d+,'[^']*','[^']*','([^']*)','([^']*)','([^']*)','([^']*)'/", $sql, $m, PREG_SET_ORDER);
            self::assertCount(9, $m, $file);
            foreach ($m as [, $text1, $url1, $text2, $url2]) {
                self::assertFalse($text1 !== '' && $url1 === '', $file . ': ' . $text1);
                self::assertFalse($text2 !== '' && $url2 === '', $file . ': ' . $text2);
            }
        }
    }

    public function testSiteLinksStayUnchangedInTheDefaultLanguageAndForNonPageUrls(): void
    {
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
        try {
            self::assertSame('/about.html', LocalizedUrl::siteLink('/about.html'), '默认语言原样');
            self::assertSame('https://example.com/a.html', LocalizedUrl::siteLink('https://example.com/a.html'));
            self::assertSame('#top', LocalizedUrl::siteLink('#top'));
            self::assertSame('', LocalizedUrl::siteLink(''));
        } finally {
            unset($GLOBALS['_test_config']['site_lang']);
        }
    }

    public function testTemplatesLocalizeButtonLinksAndSkipButtonsWithoutOne(): void
    {
        foreach (['includes/blocks/banner.php', 'themes/default/blocks/banner.php', 'includes/blocks/cta.php', 'themes/default/blocks/cta.php'] as $file) {
            $source = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringNotContainsString("?: '#'", $source, $file . '：没有链接的按钮不该渲染成 href="#"');
            self::assertStringNotContainsString('href="/contact.html"', $source, $file . '：写死的中文页地址');
            self::assertStringContainsString('LocalizedUrl::siteLink(', $source, $file);
        }
        $source = (string) file_get_contents(ROOT_PATH . '/includes/i18n/LocalizedUrl.php');
        self::assertStringContainsString("self::alternates('channel', \$channel)[\$lang]", $source, '/{别名}.html 换成该栏目在当前语言的译本');
    }
}
