<?php
/** Contact Form 7 → 本站表单模板（2.0.4）：可用标签原样保留，语义不同的改写，验证码类去掉。 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/migrate/WordPressForms.php';
require_once ROOT_PATH . '/includes/migrate/WordPressContent.php';

final class WordPressFormsTest extends TestCase
{
    public function testTagsAreConvertedToTheSiteFormSyntax(): void
    {
        $forms = new WordPressForms();
        $out = $forms->convert(implode("\n", [
            '<label>Company [text company placeholder "Your company"]</label>',
            '[text* your-name]',
            '[email* your-email akismet:author_email]',
            '[select bearing-type "Single row" "Double row"]',
            '[select* size first_as_label "Choose" "S" "M"]',
            '[radio unit default:1 "mm" "inch"]',
            '[number* qty min:1 max:99]',
            '[file drawing limit:3mb filetypes:pdf|dwg|jpg]',
            '[acceptance privacy optional]I agree to the <a href="/privacy/">privacy policy</a>[/acceptance]',
            '[quiz robot "1+1=?|2"]',
            '[recaptcha]',
            '[dynamictext page-url "CF7_URL"]',
            '[submit "Send inquiry"]',
        ]));
        self::assertSame(implode("\n", [
            '<label>Company [text company "Your company"]</label>',
            '[text* your-name]',
            '[email* your-email]',
            '[select bearing-type "" "Single row" "Double row"]',
            '[select* size "Choose" "S" "M"]',
            '[radio unit "mm" "inch"]',
            '[number* qty min:1 max:99]',
            '[file drawing "pdf,jpg" max:3]',
            '[checkbox* privacy "I agree to the privacy policy"]',
            '',
            '',
            '',
            '[submit "Send inquiry"]',
        ]), $out);
        self::assertSame(['dynamictext' => 1], $forms->dropped, '不认识的标签记下来，验证码类静默去掉');

        if (function_exists('formTagPattern')) {
            preg_match_all(formTagPattern(), $out, $m);
            self::assertCount(9, $m[0], '转换结果能被本站表单解析器读出全部字段');
        }
    }

    public function testSuccessMessageIsReadFromCf7Messages(): void
    {
        self::assertSame('Thanks & see you', WordPressForms::successMessage(serialize(['mail_sent_ok' => 'Thanks &amp; <b>see you</b>'])));
        self::assertSame('', WordPressForms::successMessage('not serialized'));
    }

    public function testContentReplacesImportedFormsAndWrapsTables(): void
    {
        $content = new WordPressContent();
        $content->forms = ['42' => 'request-a-quote', 'a1b2c3d' => 'request-a-quote'];
        $html = $content->clean("Intro\n\n[contact-form-7 id=\"a1b2c3d4\" title=\"Quote\"]\n\n[contact-form-7 id=\"99\"]\n\n<table><tr><td>1</td></tr></table>");
        self::assertStringContainsString("[form-request-a-quote]", $html);
        self::assertStringNotContainsString('<p>[form-', $html, '表单不进段落');
        self::assertStringNotContainsString('contact-form-7', $html, '没导入的表单照旧去掉');
        self::assertSame(['contact-form-7' => 1], $content->dropped);
        self::assertStringContainsString('<div class="yk-table-scroll"><table>', $html);
        self::assertSame(WordPressContent::wrapTables($html), $html, '已包过的不重复包');
    }
}
