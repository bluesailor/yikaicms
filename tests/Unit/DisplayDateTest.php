<?php
/**
 * 给访客看的日期按语言显示（2.0.4 国际化第二步）：格式来自语言包，月份名来自语言包，
 * 站长可统一指定预设格式；访客可见的模板不再写死 date('Y-m-d')。
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
final class DisplayDateTest extends TestCase
{
    private const AT = 1_790_942_400; // 2026-10-02 12:00 UTC

    /** @param array<string,string> $lang @param array<string,string> $settings */
    private function load(array $lang, array $settings = []): void
    {
        date_default_timezone_set('UTC');
        // 测试引导的 __() 永远返回键名：把取出的生产代码里的 __( 换成带测试文案的 __t(；
        // config() 用引导自带的 $GLOBALS['_test_config']
        $GLOBALS['__test_lang'] = $lang;
        $GLOBALS['_test_config'] = $settings;
        if (!function_exists('__t')) {
            eval('function __t(string $key): string { return $GLOBALS["__test_lang"][$key] ?? $key; }');
        }
        if (function_exists('displayDate')) {
            return;
        }
        $src = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        foreach (['/^const DATE_FORMAT_PRESETS = \[.*?^\];$/ms', '/^function displayDate\(.*?^\}$/ms', '/^function formatDatePattern\(.*?^\}$/ms'] as $pattern) {
            self::assertSame(1, preg_match($pattern, $src, $m), $pattern);
            eval(str_replace('__(', '__t(', $m[0]));
        }
    }

    /** @return array<string,string> */
    private function english(): array
    {
        $lang = ['date_format_date' => 'M j, Y', 'date_format_datetime' => 'M j, Y H:i', 'date_format_short' => 'M j'];
        foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $i => $name) {
            $lang['month_short_' . ($i + 1)] = $name;
        }
        return $lang;
    }

    public function testEachLanguageUsesItsOwnPattern(): void
    {
        $this->load($this->english());
        self::assertSame('Oct 2, 2026', displayDate(self::AT));
        self::assertSame('Oct 2, 2026 12:00', displayDate(self::AT, 'datetime'));
        self::assertSame('Oct 2', displayDate(self::AT, 'short'));
        self::assertSame('Oct 2, 2026', displayDate((string) self::AT), '数字字符串当时间戳');
        self::assertSame('', displayDate(0));
        self::assertSame('', displayDate(null));
    }

    public function testChineseJapaneseAndSpanishStyles(): void
    {
        $this->load(['date_format_date' => 'Y年n月j日', 'month_long_10' => 'octubre']);
        self::assertSame('2026年10月2日', displayDate(self::AT));
        // 西语带字面词要用反斜杠转义（翻译说明里写了）
        self::assertSame('2 de octubre de 2026', formatDatePattern('j \d\e F \d\e Y', self::AT));
        // 指定语言表：设置页给每种启用的语言出示例
        self::assertSame('2. Okt. 2026', formatDatePattern('j. M Y', self::AT, ['month_short_10' => 'Okt.']));
    }

    public function testMissingLanguageKeysFallBackToIsoStyle(): void
    {
        $this->load([]);
        self::assertSame('2026-10-02', displayDate(self::AT));
        self::assertSame('2026-10-02 12:00', displayDate(self::AT, 'datetime'));
        self::assertSame('10-02', displayDate(self::AT, 'short'));
        self::assertSame('Oct 2', formatDatePattern('M j', self::AT), '没有月份名时退回 PHP 英文缩写');
    }

    public function testSiteWidePresetOverridesDateButNotShort(): void
    {
        $this->load($this->english(), ['date_format' => 'd/m/Y']);
        self::assertSame('02/10/2026', displayDate(self::AT));
        self::assertSame('02/10/2026 12:00', displayDate(self::AT, 'datetime'));
        self::assertSame('Oct 2', displayDate(self::AT, 'short'));

        $this->load($this->english(), ['date_format' => '<script>']);
        self::assertSame('Oct 2, 2026', displayDate(self::AT), '不是预设的值不认');
    }

    public function testVisitorTemplatesNoLongerHardCodeTheDateFormat(): void
    {
        foreach (['themes/default/partials/article-card.php', 'themes/default/partials/article-grid-card.php',
            'includes/partials/article-card.php', 'includes/partials/article-grid-card.php', 'includes/partials/search-results.php',
            'views/list/download.php', 'article.php', 'detail.php', 'job_detail.php', 'member/profile.php'] as $file) {
            $src = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringNotContainsString("date('Y-m-d", $src, $file);
            self::assertStringContainsString('displayDate(', $src, $file);
        }
        $settings = (string) file_get_contents(ROOT_PATH . '/admin/setting.php');
        self::assertStringContainsString("if (\$format !== '' && !isset(DATE_FORMAT_PRESETS[\$format])) {", $settings, '只收预设');
    }
}
