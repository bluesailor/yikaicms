<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 带占位的整句译文：占位必须在，且拼出来的结果不能粘连。
 *
 * 起因：首页「关于」标题原先是 __('home_about_title') . site_name。
 * 中文「关于KKSKY」读得通，英文站就渲染成「AboutKKSKY Solar Light」——
 * 词与词之间要不要空格、站名放前还是放后，是**语言**的事，不能在 PHP 里拼。
 * 改成 :site 占位后，这里锁住三件事：占位没被翻译掉、拉丁语言留了分隔、
 * 回填后不残留占位符。
 */
final class LangPlaceholderTest extends TestCase
{
    private const LANGS = ['zh-CN', 'en', 'ja'];

    /** key => 该 key 必须包含的占位符 */
    private const PLACEHOLDERS = [
        'email_test_sent_at'     => ':time',
        'email_test_fail_prefix' => ':error',
        'home_about_title_site'  => ':site',
        'upgrade_welcome_title'  => ':version',
    ];

    /** @return array<string, array<string, string>> */
    private function langData(): array
    {
        $out = [];
        foreach (self::LANGS as $code) {
            $file = dirname(__DIR__, 2) . '/lang/' . $code . '.php';
            $this->assertFileExists($file);
            /** @var array<string, string> $data */
            $data = require $file;
            $out[$code] = $data;
        }
        return $out;
    }

    public function testPlaceholdersSurviveTranslation(): void
    {
        foreach ($this->langData() as $code => $data) {
            foreach (self::PLACEHOLDERS as $key => $token) {
                $this->assertArrayHasKey($key, $data, "lang/$code.php 缺 key $key");
                $this->assertStringContainsString(
                    $token,
                    $data[$key],
                    "lang/$code.php 的 $key 丢了占位符 $token —— 回填后数据会消失"
                );
            }
        }
    }

    /**
     * 拉丁字母语言里，占位符两侧必须有分隔（空格或标点），
     * 否则「About」+「KKSKY」会粘成「AboutKKSKY」。
     * 中日文不做此要求——「关于KKSKY」正是期望的读法。
     */
    public function testLatinLanguagesSeparatePlaceholder(): void
    {
        $data = $this->langData()['en'];

        foreach (self::PLACEHOLDERS as $key => $token) {
            $parts = explode($token, $data[$key], 2);
            $before = $parts[0];
            $after = $parts[1] ?? '';

            if ($before !== '') {
                $this->assertMatchesRegularExpression(
                    '/[\s:：,，.。\-—(（]$/u',
                    $before,
                    "lang/en.php 的 $key 在占位符前缺分隔，回填后会粘连：" . $data[$key]
                );
            }
            if ($after !== '') {
                $this->assertMatchesRegularExpression(
                    '/^[\s:：,，.。\-—)）]/u',
                    $after,
                    "lang/en.php 的 $key 在占位符后缺分隔，回填后会粘连：" . $data[$key]
                );
            }
        }
    }

    public function testAboutTitleComposesCleanlyInEveryLanguage(): void
    {
        $site = 'KKSKY Solar Light';
        $expected = [
            'zh-CN' => '关于KKSKY Solar Light',
            'en'    => 'About KKSKY Solar Light',
            'ja'    => 'KKSKY Solar Light について',
        ];

        foreach ($this->langData() as $code => $data) {
            $out = trim(str_replace(':site', $site, $data['home_about_title_site']));
            $this->assertSame($expected[$code], $out, "lang/$code.php 的关于标题拼法变了");
            $this->assertStringNotContainsString(':site', $out, '占位符没被回填干净');
        }
    }
}
