<?php
/** 版权文字与备案号的站点级读写规则，供 Blox 版权元素的面板内编辑使用。 */

declare(strict_types=1);

final class SiteCopyrightSettings
{
    public const COPYRIGHT_KEY = 'footer_copyright_text';
    /** ICP / 公安备案只属于中国大陆站点；繁中、英、日等语言版本一律不显示 */
    public const FILING_LANGUAGE = 'zh-CN';

    private const COPYRIGHT_MAX = 255;
    private const FILING_MAX = 100;

    public static function filingApplies(string $language): bool
    {
        return $language === self::FILING_LANGUAGE;
    }

    /**
     * 写入与前台 configRawLang() 读取一致的键：`<key>_<lang>` 有值时优先；
     * 默认语言否则写基础键，其它语言写 `<key>_<lang>`（与 setting.php 的约定一致）。
     *
     * @param callable(string):string $read
     */
    public static function copyrightKey(string $language, string $defaultLanguage, callable $read): string
    {
        $languageKey = self::COPYRIGHT_KEY . '_' . $language;
        if ($language !== $defaultLanguage || trim($read($languageKey)) !== '') {
            return $languageKey;
        }
        return self::COPYRIGHT_KEY;
    }

    /**
     * 面板初始值：显示该语言在前台实际生效的文字（语言键为空时回落基础键）。
     *
     * @param callable(string):string $read
     * @return array{language:string,copyright:string,icp:string,police:string,filing:bool}
     */
    public static function editorState(string $language, callable $read): array
    {
        $copyright = $read(self::COPYRIGHT_KEY . '_' . $language);
        if (trim($copyright) === '') {
            $copyright = $read(self::COPYRIGHT_KEY);
        }
        return [
            'language' => $language,
            'copyright' => $copyright,
            'icp' => $read('site_icp'),
            'police' => $read('site_police'),
            'filing' => self::filingApplies($language),
        ];
    }

    /**
     * 规整面板提交值为待保存的设置。备案号是全站单值（不分语言），仅在提交了对应字段时写入；
     * 面板只在备案可见时提交，未提交的字段保持原值，避免误清空。
     *
     * @param array<string,mixed> $input
     * @param callable(string):string $read
     * @return array<string,string>
     */
    public static function normalizeInput(array $input, string $language, string $defaultLanguage, callable $read): array
    {
        $settings = [
            self::copyrightKey($language, $defaultLanguage, $read) => self::clean($input['copyright'] ?? '', self::COPYRIGHT_MAX),
        ];
        foreach (['icp' => 'site_icp', 'police' => 'site_police'] as $field => $key) {
            if (array_key_exists($field, $input)) {
                $settings[$key] = self::clean($input[$field], self::FILING_MAX);
            }
        }
        return $settings;
    }

    private static function clean(mixed $value, int $limit): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit);
    }
}
