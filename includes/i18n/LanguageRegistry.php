<?php
/**
 * 语言注册表：系统支持哪些语言、每种语言的固定属性，全站唯一来源。
 *
 * 三层关系：
 *   - 注册（本表）：产品认识的语言。URL 前缀、hreflang、书写方向、国旗、字体组都只从这里取。
 *   - 可用（availableLanguages）：注册 且 lang/<code>.php 存在。后台能选、能写翻译。
 *   - 启用（enabledLanguages）：可用 × 后台 enabled_languages 设置。访客能看到。
 *
 * 新增语言 = 在这里加一行 + 放 lang/<code>.php 语言包；不要再在别处写死语言列表。
 * .htaccess 与 nginx 配置里的前缀正则须与 urlPrefixPattern() 一致（有单测钉住）。
 * 注册过的代码同时是保留别名：频道/单页不能用它做 slug（否则 /de/xxx.html 会被当成德语前缀）。
 */

declare(strict_types=1);

final class LanguageRegistry
{
    /**
     * name     本族语名（切换器、后台选择器显示）
     * english  英文名（AI 翻译提示词、日志）
     * hreflang 搜索引擎语言标记
     * dir      书写方向 ltr / rtl
     * flag     assets/icons/flags/<flag>.svg；空串 = 不显示旗
     * font     字体预设组（font_presets.php 的一级键）
     * picker   flatpickr 本地化包（assets/flatpickr/l10n-<picker>.js）；空串 = 英文界面
     *
     * @var array<string, array{name:string, english:string, hreflang:string, dir:string, flag:string, font:string, picker:string}>
     */
    private const LANGUAGES = [
        'zh-CN' => ['name' => '简体中文', 'english' => 'Simplified Chinese', 'hreflang' => 'zh-CN', 'dir' => 'ltr', 'flag' => 'cn', 'font' => 'zh-CN', 'picker' => 'zh'],
        'zh-TW' => ['name' => '繁體中文', 'english' => 'Traditional Chinese', 'hreflang' => 'zh-Hant', 'dir' => 'ltr', 'flag' => '', 'font' => 'zh-CN', 'picker' => 'zh'],
        'en'    => ['name' => 'English', 'english' => 'English', 'hreflang' => 'en', 'dir' => 'ltr', 'flag' => 'us', 'font' => 'en', 'picker' => ''],
        'ja'    => ['name' => '日本語', 'english' => 'Japanese', 'hreflang' => 'ja', 'dir' => 'ltr', 'flag' => 'jp', 'font' => 'ja', 'picker' => 'ja'],
        'ko'    => ['name' => '한국어', 'english' => 'Korean', 'hreflang' => 'ko', 'dir' => 'ltr', 'flag' => 'kr', 'font' => 'en', 'picker' => 'ko'],
        'es'    => ['name' => 'Español', 'english' => 'Spanish', 'hreflang' => 'es', 'dir' => 'ltr', 'flag' => 'es', 'font' => 'en', 'picker' => 'es'],
        'pt'    => ['name' => 'Português (Brasil)', 'english' => 'Brazilian Portuguese', 'hreflang' => 'pt-BR', 'dir' => 'ltr', 'flag' => 'br', 'font' => 'en', 'picker' => 'pt'],
        'fr'    => ['name' => 'Français', 'english' => 'French', 'hreflang' => 'fr', 'dir' => 'ltr', 'flag' => 'fr', 'font' => 'en', 'picker' => 'fr'],
        'de'    => ['name' => 'Deutsch', 'english' => 'German', 'hreflang' => 'de', 'dir' => 'ltr', 'flag' => 'de', 'font' => 'en', 'picker' => 'de'],
        'ru'    => ['name' => 'Русский', 'english' => 'Russian', 'hreflang' => 'ru', 'dir' => 'ltr', 'flag' => 'ru', 'font' => 'en', 'picker' => 'ru'],
        'it'    => ['name' => 'Italiano', 'english' => 'Italian', 'hreflang' => 'it', 'dir' => 'ltr', 'flag' => 'it', 'font' => 'en', 'picker' => 'it'],
        'tr'    => ['name' => 'Türkçe', 'english' => 'Turkish', 'hreflang' => 'tr', 'dir' => 'ltr', 'flag' => 'tr', 'font' => 'en', 'picker' => 'tr'],
        'vi'    => ['name' => 'Tiếng Việt', 'english' => 'Vietnamese', 'hreflang' => 'vi', 'dir' => 'ltr', 'flag' => 'vn', 'font' => 'en', 'picker' => 'vn'],
        'id'    => ['name' => 'Bahasa Indonesia', 'english' => 'Indonesian', 'hreflang' => 'id', 'dir' => 'ltr', 'flag' => 'id', 'font' => 'en', 'picker' => 'id'],
        'th'    => ['name' => 'ไทย', 'english' => 'Thai', 'hreflang' => 'th', 'dir' => 'ltr', 'flag' => 'th', 'font' => 'en', 'picker' => 'th'],
        'ar'    => ['name' => 'العربية', 'english' => 'Arabic', 'hreflang' => 'ar', 'dir' => 'rtl', 'flag' => '', 'font' => 'ar', 'picker' => 'ar'],
        'fa'    => ['name' => 'فارسی', 'english' => 'Persian', 'hreflang' => 'fa', 'dir' => 'rtl', 'flag' => '', 'font' => 'ar', 'picker' => 'fa'],
        'ms'    => ['name' => 'Bahasa Melayu', 'english' => 'Malay', 'hreflang' => 'ms', 'dir' => 'ltr', 'flag' => '', 'font' => 'en', 'picker' => 'ms'],
    ];

    /**
     * @return array<string, array{name:string, english:string, hreflang:string, dir:string, flag:string, font:string, picker:string}>
     * @psalm-suppress PossiblyUnusedMethod 注册表完整视图，供插件与测试使用
     */
    public static function all(): array
    {
        return self::LANGUAGES;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LANGUAGES);
    }

    public static function has(string $code): bool
    {
        return isset(self::LANGUAGES[$code]);
    }

    public static function name(string $code): string
    {
        return self::LANGUAGES[$code]['name'] ?? $code;
    }

    /**
     * 按钮、胶囊上的短标记：ZH / ZH-TW / EN / JA / KO …（未注册的代码原样大写）。
     * @psalm-suppress PossiblyUnusedMethod 后台语言胶囊（Codex 任务单 C1）接入前暂无调用方
     */
    public static function shortLabel(string $code): string
    {
        return $code === 'zh-CN' ? 'ZH' : strtoupper($code);
    }

    public static function englishName(string $code): string
    {
        return self::LANGUAGES[$code]['english'] ?? $code;
    }

    public static function hreflang(string $code): string
    {
        return self::LANGUAGES[$code]['hreflang'] ?? $code;
    }

    /** 书写方向：ltr / rtl。未注册的代码按 ltr。 */
    public static function dir(string $code): string
    {
        return (self::LANGUAGES[$code]['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }

    public static function isRtl(string $code): bool
    {
        return self::dir($code) === 'rtl';
    }

    public static function flag(string $code): string
    {
        return self::LANGUAGES[$code]['flag'] ?? '';
    }

    public static function fontGroup(string $code): string
    {
        return self::LANGUAGES[$code]['font'] ?? 'en';
    }

    public static function pickerLocale(string $code): string
    {
        return self::LANGUAGES[$code]['picker'] ?? '';
    }

    /**
     * 按语言取「<字段>_<语言>」式的多语字段（主题/插件/模板清单、目录条目、带 _en/_ja 列的数据行）。
     *
     * 取值顺序：本语言后缀 → 无后缀基准（仅当基准就是这种语言，或同为中文）→ 英文后缀 → 基准。
     * 读汉字的语言（中文、日语）不插英文这一档，缺译时直接回落中文基准（与 2.0 之前一致）。
     * $baseLang 是无后缀字段所用的语言：清单与官方目录一律是中文；站点数据行是站点默认语言。
     * 这样韩语后台看到英文描述而不是中文，日语默认站仍优先显示基准里的日文。全部为空时返回空串。
     */
    public static function localizedField(array $row, string $field, string $lang, string $baseLang = 'zh-CN'): string
    {
        $chain = [];
        if ($lang !== '' && $lang !== 'zh-CN') $chain[] = $field . '_' . $lang;
        if ($lang === $baseLang || (self::isChinese($lang) && self::isChinese($baseLang))) $chain[] = $field;
        if ($lang !== 'en' && !self::readsHan($lang)) $chain[] = $field . '_en';
        $chain[] = $field;
        foreach ($chain as $key) {
            $value = $row[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
        }
        return '';
    }

    /**
     * 嵌套写法的多语值：{"zh-CN": "…", "en": "…", "ko": "…"}（content-fields.json、精品区块名称等）。
     * 与 localizedField() 同一回落顺序：本语言 → 英文（读汉字的语言除外）→ 简体中文；空串视为缺译。
     *
     * @param array<array-key,mixed> $values
     */
    public static function localizedValue(array $values, string $lang): string
    {
        $chain = [$lang];
        if ($lang !== 'en' && !self::readsHan($lang)) $chain[] = 'en';
        $chain[] = 'zh-CN';
        foreach ($chain as $code) {
            $value = $values[$code] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') return (string) $value;
        }
        return '';
    }

    /** 与语言代码同名的别名会和语言前缀撞车（/de/xxx.html），栏目与单页不能新用。 */
    public static function isReservedSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), array_map('strtolower', self::codes()), true);
    }

    /** 读汉字的语言：多语字段缺译时回落中文基准比回落英文更好懂。 */
    public static function readsHan(string $code): bool
    {
        return self::isChinese($code) || $code === 'ja';
    }

    /** 中文（简/繁）：与「按中文习惯」有关的判断（全角标点、不加空格等）走这里。 */
    public static function isChinese(string $code): bool
    {
        return $code === 'zh-CN' || $code === 'zh-TW';
    }

    /**
     * URL 语言前缀的正则分支（不含分组括号），长代码在前，如 `zh-CN|zh-TW|en|ja|…`。
     * .htaccess / nginx 配置里的前缀规则必须与它一致。
     */
    public static function urlPrefixPattern(): string
    {
        $codes = self::codes();
        $order = array_flip($codes);
        usort($codes, static fn(string $a, string $b): int => (strlen($b) <=> strlen($a)) ?: ($order[$a] <=> $order[$b]));
        return implode('|', $codes);   // 代码只含字母与连字符，放进正则无需转义
    }

    /** 路径（不含开头 /）的第一段是注册语言时返回该代码。 */
    public static function prefixOf(string $path): ?string
    {
        $path = ltrim($path, '/');
        foreach (self::codes() as $code) {
            if ($path === $code || str_starts_with($path, $code . '/')) {
                return $code;
            }
        }
        return null;
    }
}
