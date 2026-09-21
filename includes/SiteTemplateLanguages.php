<?php
/**
 * 整站模板包里的语言分布。
 *
 * 整站导入是**覆盖式**的：它连 site_lang / enabled_languages 一起替换。所以一个只有
 * 中文内容、却声明了三种语言的包，装完站点会照常给出英文和日文入口，点进去却是空的。
 * 这件事在按下"应用"之前完全可以算出来，算不出来才该沉默。
 *
 * 两条口径分开，不要混：
 *   · declared —— 包里 enabled_languages 声明的语言（导入后站点将启用的那些）；
 *   · content  —— 频道/文章/产品里**真的有行**的语言。
 * empty = declared 减去 content，就是"装完会空着的语言"。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class SiteTemplateLanguages
{
    /** 只看真正构成页面的表：媒体、标签这些没有语言，或跟着主体走。 */
    private const CONTENT_TABLES = ['channels', 'contents', 'products'];

    /**
     * @param array<string,mixed> $data manifest 的 data 段（tables + settings）
     * @return array{declared:list<string>,content:list<string>,empty:list<string>}
     */
    public static function inspect(array $data): array
    {
        $content = self::contentLanguages(is_array($data['tables'] ?? null) ? $data['tables'] : []);
        $declared = self::declaredLanguages(is_array($data['settings'] ?? null) ? $data['settings'] : []);

        return [
            'declared' => $declared,
            'content' => $content,
            'empty' => array_values(array_diff($declared, $content)),
        ];
    }

    /**
     * @param array<string,mixed> $tables
     * @return list<string>
     */
    private static function contentLanguages(array $tables): array
    {
        $seen = [];
        foreach (self::CONTENT_TABLES as $table) {
            $rows = is_array($tables[$table] ?? null) ? $tables[$table] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $language = trim((string) ($row['lang'] ?? ''));
                if ($language !== '') {
                    $seen[$language] = true;
                }
            }
        }
        ksort($seen);
        return array_keys($seen);
    }

    /**
     * @param array<string,mixed> $settings
     * @return list<string>
     */
    private static function declaredLanguages(array $settings): array
    {
        $raw = trim((string) ($settings['enabled_languages'] ?? ''));
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $list = [];
        if (is_array($decoded)) {
            foreach ($decoded as $code) {
                if (is_string($code) && trim($code) !== '') {
                    $list[trim($code)] = true;
                }
            }
        }
        // 没声明过 enabled_languages 的包等于「只有默认语言」，不能当成"什么都没启用"
        if ($list === []) {
            $fallback = trim((string) ($settings['site_lang'] ?? ''));
            if ($fallback !== '') {
                $list[$fallback] = true;
            }
        }
        ksort($list);
        return array_keys($list);
    }

    /** 展示用名称；站点没装这个语言包时退回语言代码，不猜也不隐藏。 */
    public static function label(string $code): string
    {
        $available = function_exists('availableLanguages') ? availableLanguages() : [];
        return is_string($available[$code] ?? null) && $available[$code] !== '' ? $available[$code] : $code;
    }

    /** @param list<string> $codes */
    public static function labels(array $codes): string
    {
        return implode('、', array_map([self::class, 'label'], $codes));
    }
}
