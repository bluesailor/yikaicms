<?php

declare(strict_types=1);

/**
 * Resolves the synthetic values shown for missing localized home settings.
 *
 * config/defaults.php is the zh-CN factory seed, so it must not become the
 * editable baseline of a site whose source language is different.
 */
final class HomeSettingsLanguageDefaults
{
    /** @var array<string, string|null> */
    private const LANGUAGE_KEYS = [
        'home_about_title' => null,
        'home_about_content' => 'home_about_default',
        'home_about_tag_title' => null,
        'home_about_tag_desc' => null,
        'home_stat_1_text' => 'home_stat_1_text',
        'home_stat_2_text' => 'home_stat_2_text',
        'home_stat_3_text' => 'home_stat_3_text',
        'home_stat_4_text' => 'home_stat_4_text',
        'home_testimonials_title' => 'home_testimonials_title',
        'home_testimonials_desc' => 'home_testimonials_desc',
        'home_testimonials' => null,
        'home_advantage_title' => 'home_advantage_title',
        'home_advantage_desc' => 'home_advantage_desc',
        'home_adv_1_title' => 'home_adv_1_title',
        'home_adv_1_desc' => 'home_adv_1_desc',
        'home_adv_2_title' => 'home_adv_2_title',
        'home_adv_2_desc' => 'home_adv_2_desc',
        'home_adv_3_title' => 'home_adv_3_title',
        'home_adv_3_desc' => 'home_adv_3_desc',
        'home_adv_4_title' => 'home_adv_4_title',
        'home_adv_4_desc' => 'home_adv_4_desc',
        'home_cta_title' => 'home_cta_title',
        'home_cta_desc' => 'home_cta_desc',
        'home_links_title' => 'footer_partners',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LANGUAGE_KEYS);
    }

    /** @param array<string, array<string, mixed>> $defaults */
    public static function localizedValue(string $key, string $language, array $defaults): string
    {
        $factoryValue = self::factoryValue($key, $defaults);
        if (self::usesChineseFactoryDefaults($language) || !array_key_exists($key, self::LANGUAGE_KEYS)) {
            return $factoryValue;
        }

        // Reviews are structured content and have no safe translated demo seed.
        if ($key === 'home_testimonials') {
            return '[]';
        }

        $langKey = self::LANGUAGE_KEYS[$key];
        if ($langKey === null) {
            return '';
        }

        $pack = self::languagePack($language);
        return isset($pack[$langKey]) && is_scalar($pack[$langKey])
            ? (string) $pack[$langKey]
            : '';
    }

    /** @param array<string, array<string, mixed>> $defaults */
    public static function isLeakedFactoryValue(
        string $key,
        string $storedValue,
        string $language,
        array $defaults
    ): bool {
        if (self::usesChineseFactoryDefaults($language) || !array_key_exists($key, self::LANGUAGE_KEYS)) {
            return false;
        }

        $factoryValue = self::factoryValue($key, $defaults);
        return $factoryValue !== ''
            && $storedValue === $factoryValue
            && self::localizedValue($key, $language, $defaults) !== $factoryValue;
    }

    /**
     * A synthetic value is display-only. Persisting it would suppress the
     * language-pack fallback and recreate the original leak.
     *
     * @param array<string, array<string, mixed>> $defaults
     */
    public static function shouldSkipSyntheticWrite(
        string $key,
        mixed $submittedValue,
        string $language,
        bool $hasStoredRow,
        ?string $storedValue,
        array $defaults
    ): bool {
        if (self::usesChineseFactoryDefaults($language) || !array_key_exists($key, self::LANGUAGE_KEYS)) {
            return false;
        }

        $isSynthetic = !$hasStoredRow || self::isLeakedFactoryValue(
            $key,
            (string) $storedValue,
            $language,
            $defaults
        );
        if (!$isSynthetic) {
            return false;
        }

        $submitted = (string) $submittedValue;
        return $submitted === self::localizedValue($key, $language, $defaults)
            || $submitted === self::factoryValue($key, $defaults);
    }

    /**
     * @param array<string, array<string, mixed>> $defaults
     * @return array<string, string> key => leaked zh-CN factory value
     */
    public static function pollutedFactoryRows(string $language, array $defaults): array
    {
        if (self::usesChineseFactoryDefaults($language)) {
            return [];
        }

        $rows = [];
        foreach (self::keys() as $key) {
            $factoryValue = self::factoryValue($key, $defaults);
            if ($factoryValue !== '' && self::localizedValue($key, $language, $defaults) !== $factoryValue) {
                $rows[$key] = $factoryValue;
            }
        }
        return $rows;
    }

    /** @param array<string, array<string, mixed>> $defaults */
    private static function factoryValue(string $key, array $defaults): string
    {
        return (string) ($defaults[$key]['value'] ?? '');
    }

    private static function usesChineseFactoryDefaults(string $language): bool
    {
        // zh-TW is a rendered S2T view over zh-CN data, not an independent
        // full language pack. Its stored factory values must remain intact.
        return in_array($language, ['zh-CN', 'zh-TW'], true);
    }

    /** @return array<string, mixed> */
    private static function languagePack(string $language): array
    {
        static $cache = [];
        if (isset($cache[$language])) {
            return $cache[$language];
        }
        if (!preg_match('/^[A-Za-z0-9-]+$/', $language)) {
            return $cache[$language] = [];
        }

        $data = [];
        $langFile = ROOT_PATH . '/lang/' . $language . '.php';
        if (is_file($langFile)) {
            $loaded = require $langFile;
            if (is_array($loaded)) {
                $data = $loaded;
            }
        }

        foreach ([ROOT_PATH . '/lang/overrides/all.php', ROOT_PATH . '/lang/overrides/' . $language . '.php'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $override = require $file;
            if (is_array($override)) {
                $data = array_merge($data, $override);
            }
        }

        return $cache[$language] = $data;
    }
}
