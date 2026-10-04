<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 多语言 C1：后台各处的语言列表来自注册表 + 已装语言包，不再写死中英日。
 * GLM 交付的新语言包（lang/<code>.php）合入后，后台切换器、登录页、设置页、安装向导都能直接选到。
 */
final class AdminLanguageSwitchTest extends TestCase
{
    /** 去掉注释后的源码 */
    private function code(string $path): string
    {
        $src = '';
        foreach (token_get_all((string) file_get_contents(ROOT_PATH . '/' . $path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $src .= is_array($token) ? $token[1] : $token;
        }
        return $src;
    }

    /** 从文件里取出一个顶层函数的源码（单测不加载完整的 functions.php） */
    private function functionSource(string $path, string $name): string
    {
        $src = (string) file_get_contents(ROOT_PATH . '/' . $path);
        self::assertSame(1, preg_match('/^function ' . $name . '\(.*?^\}$/ms', $src, $m), "{$path} 找不到 {$name}()");
        return $m[0];
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function testAdminLanguagesKeepsInstalledPacksExceptRtl(): void
    {
        require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
        eval('function availableLanguages(): array {
            return ["zh-CN" => "中文", "zh-TW" => "繁體中文", "en" => "English", "ja" => "日本語", "ko" => "한국어", "ar" => "العربية"];
        }');
        eval($this->functionSource('includes/functions.php', 'adminLanguages'));

        self::assertSame(
            ['zh-CN' => '中文', 'zh-TW' => '繁體中文', 'en' => 'English', 'ja' => '日本語', 'ko' => '한국어'],
            adminLanguages()
        );
    }

    /** @runInSeparateProcess @preserveGlobalState disabled */
    public function testTranslatedPackSurvivesBackslashesAndQuotes(): void
    {
        require_once ROOT_PATH . '/includes/i18n/LanguageRegistry.php';
        eval($this->functionSource('admin/setting_translate.php', 'saveLangFile'));

        $file = tempnam(sys_get_temp_dir(), 'yk-lang');
        $data = [
            'a_trailing' => 'C:\\path\\',
            'a_quote' => "it's \\' tricky",
            'b_lines' => "line1\nline2",
        ];
        try {
            saveLangFile($file, 'ko', $data);
            self::assertStringContainsString('Korean language pack (ko)', (string) file_get_contents($file));
            self::assertSame($data, require $file);
        } finally {
            @unlink($file);
        }
    }

    public function testNoHardcodedLanguageTablesLeft(): void
    {
        $files = [
            'admin/blox_editor/partials/header.php',
            'admin/blox_editor.php',
            'includes/builder/BloxAreaEditorLanguageLinks.php',
            'includes/builder/BloxAreaConditions.php',
            'admin/channel.php',
            'admin/nav_menu.php',
            'admin/includes/header.php',
            'admin/theme_content.php',
            'admin/setting_translate.php',
            'admin/login.php',
            'install/index.php',
        ];
        foreach ($files as $file) {
            $src = $this->code($file);
            // 'ja' => 'JA' / '日本語' / 'ja' 这类逐语言写死的表
            self::assertDoesNotMatchRegularExpression("/'ja'\\s*=>\\s*'/", $src, "{$file} 仍写死了语言表");
            self::assertStringNotContainsString("'日本語'", $src, "{$file} 仍写死了语言名");
        }
    }

    public function testAdminLanguageEntryPointsShareOneSource(): void
    {
        self::assertStringContainsString('$langLabels = adminLanguages();', $this->code('admin/includes/header.php'));
        self::assertStringContainsString('$_allLangs = adminLanguages();', $this->code('admin/login.php'));

        $setting = $this->code('admin/setting.php');
        self::assertStringContainsString('$allowed = array_keys(adminLanguages());', $setting);
        self::assertStringContainsString("isset(adminLanguages()[\$settings['admin_lang']])", $setting, '顶栏切换器提交的 admin_lang 要校验');

        $settingLang = $this->code('admin/setting_lang.php');
        self::assertStringContainsString('!isset(adminLanguages()[$newAdmin])', $settingLang);
        self::assertStringContainsString('foreach (adminLanguages() as $code => $label)', $settingLang);

        $install = $this->code('install/index.php');
        self::assertStringContainsString('isset($_adminChoices[$_POST[\'admin_lang\']])', $install);
        self::assertStringContainsString('$adminLangOptions = installerLangOptions(true);', $install);
        self::assertStringContainsString('foreach ($adminLangOptions as $code => $name)', $install);
    }

    public function testShortLabelsComeFromRegistry(): void
    {
        foreach ([
            'admin/blox_editor/partials/header.php' => 'LanguageRegistry::shortLabel((string) $homeLangCode)',
            'admin/blox_editor.php' => "'short' => LanguageRegistry::shortLabel(\$languageCode)",
            'includes/builder/BloxAreaEditorLanguageLinks.php' => "'short' => LanguageRegistry::shortLabel(\$code)",
        ] as $file => $needle) {
            self::assertStringContainsString($needle, $this->code($file), $file);
        }
    }
}
