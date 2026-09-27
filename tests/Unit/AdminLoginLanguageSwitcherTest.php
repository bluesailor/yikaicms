<?php
/**
 * 后台登录页的语言切换：放在登录框下方，用下拉选择（2026-09-27 用户要求）。
 *
 * 原先是右上角一排国旗按钮，大屏上离登录框太远。现在是登录卡片之后的一个 GET 表单：
 * <select name="lang"> 选中即提交（仍走 login.php 既有的 ?lang= 处理），没有 JavaScript 时
 * 用 noscript 里的按钮提交。页面同时要加载图标字体，否则 ti-* 图标（含"显示密码"）不显示。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AdminLoginLanguageSwitcherTest extends TestCase
{
    private static string $source = '';

    public static function setUpBeforeClass(): void
    {
        self::$source = (string) file_get_contents(ROOT_PATH . '/admin/login.php');
    }

    public function testSwitcherIsADropdownBelowTheLoginCard(): void
    {
        $src = self::$source;
        $switcher = strpos($src, 'data-testid="login-lang-switcher"');
        self::assertNotFalse($switcher, 'login page must keep a language switcher');
        // 登录卡片里的两个表单（密码 / 两步验证）都在切换器之前
        self::assertLessThan($switcher, strrpos($src, "__('login_button')"));
        self::assertLessThan($switcher, strrpos($src, "__('login_2fa_button')"));

        $block = substr($src, $switcher, 2000);
        self::assertMatchesRegularExpression('/<select[^>]*\bname="lang"/', $block);
        self::assertStringContainsString('onchange="this.form.submit()"', $block);
        self::assertStringContainsString('<noscript>', $block, 'must still work without JavaScript');
        self::assertStringContainsString('for="loginLang"', $block, 'select needs a visible label');
    }

    public function testTopRightFlagRowIsGone(): void
    {
        self::assertStringNotContainsString('absolute top-4 right-4', self::$source);
        self::assertStringNotContainsString('/assets/icons/flags/', self::$source);
    }

    public function testSwitcherOnlyShowsWithTwoOrMoreLanguages(): void
    {
        $before = substr(self::$source, 0, (int) strpos(self::$source, 'data-testid="login-lang-switcher"'));
        self::assertStringContainsString('count($supportedLangs) >= 2', substr($before, -600));
    }

    public function testIconFontIsLoaded(): void
    {
        self::assertStringContainsString('/assets/tabler/tabler-icons.min.css', self::$source);
    }

    public function testLabelIsTranslated(): void
    {
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertArrayHasKey('login_language', $strings, $lang);
            self::assertNotSame('', trim((string) $strings['login_language']), $lang);
        }
    }
}
