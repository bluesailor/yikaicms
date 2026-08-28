<?php
/** Blox 区块目录元数据：用于场景推荐，不参与文档渲染。 */

declare(strict_types=1);

final class BloxSectionMetadata
{
    private const PAGE_TYPES = [
        'general', 'home', 'about', 'product-list', 'product-detail', 'content-list',
        'case', 'contact', 'jobs', 'service', 'landing',
    ];
    private const PURPOSES = [
        'general', 'hero', 'company-intro', 'features', 'stats', 'products', 'cases',
        'process', 'faq', 'cta', 'contact', 'testimonials', 'content',
    ];
    private const CTA_TYPES = ['none', 'learn-more', 'contact', 'quote', 'download', 'purchase', 'subscribe'];

    /** @return array<string,mixed> */
    public static function normalize(mixed $raw, string $fallbackPurpose = 'general'): array
    {
        $metadata = is_array($raw) ? $raw : [];
        $purpose = self::enumValue($metadata['purpose'] ?? '', self::PURPOSES, $fallbackPurpose);
        $pageTypes = self::slugList($metadata['page_types'] ?? [], self::PAGE_TYPES, 12);

        return [
            'schema' => 1,
            'purpose' => $purpose,
            'page_types' => $pageTypes !== [] ? $pageTypes : ['general'],
            'industries' => self::slugList($metadata['industries'] ?? [], null, 12),
            'content_slots' => self::slugList($metadata['content_slots'] ?? [], null, 20),
            'cta_type' => self::enumValue($metadata['cta_type'] ?? '', self::CTA_TYPES, 'none'),
            'required_plugins' => self::slugList($metadata['required_plugins'] ?? [], null, 20),
            'language_coverage' => self::languageList($metadata['language_coverage'] ?? []),
            'image_ratio' => self::imageRatio($metadata['image_ratio'] ?? ''),
            'min_cms_version' => self::version($metadata['min_cms_version'] ?? ''),
            'priority' => max(0, min(100, (int) ($metadata['priority'] ?? 0))),
        ];
    }

    /**
     * @param array<string,mixed> $page
     * @psalm-suppress PossiblyUnusedMethod Called from the Psalm-excluded full-screen editor entry.
     */
    public static function inferPageType(
        bool $isHome,
        bool $templateMode,
        bool $isContact,
        bool $isProductList,
        bool $isContentList,
        array $page
    ): string {
        if ($isHome) {
            return 'home';
        }
        if ($templateMode) {
            return 'general';
        }
        if ($isContact) {
            return 'contact';
        }
        if ($isProductList) {
            return 'product-list';
        }
        if ($isContentList) {
            return 'content-list';
        }

        $identity = mb_strtolower(trim((string) ($page['slug'] ?? '') . ' ' . (string) ($page['name'] ?? '')));
        $patterns = [
            'about' => [['about', 'company', 'profile', 'introduction'], 'blox_page_intent_about_tokens'],
            'case' => [['case', 'cases', 'project', 'projects', 'portfolio'], 'blox_page_intent_case_tokens'],
            'jobs' => [['job', 'jobs', 'career', 'careers', 'recruit', 'talent'], 'blox_page_intent_jobs_tokens'],
            'service' => [['service', 'services', 'process', 'solution', 'solutions'], 'blox_page_intent_service_tokens'],
        ];
        foreach ($patterns as $pageType => [$asciiTokens, $localizedKey]) {
            if (self::matchesIdentity($identity, $asciiTokens, $localizedKey)) {
                return $pageType;
            }
        }
        return 'general';
    }

    /** @param list<string> $asciiTokens */
    private static function matchesIdentity(string $identity, array $asciiTokens, string $localizedKey): bool
    {
        $asciiPattern = '/(?:^|[\s_-])(?:' . implode('|', array_map(
            static fn (string $token): string => preg_quote($token, '/'),
            $asciiTokens
        )) . ')(?:$|[\s_-])/u';
        if (preg_match($asciiPattern, $identity) === 1) {
            return true;
        }
        foreach (explode(',', __($localizedKey)) as $token) {
            $token = mb_strtolower(trim($token));
            if ($token !== '' && str_contains($identity, $token)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $allowed */
    private static function enumValue(mixed $raw, array $allowed, string $fallback): string
    {
        $value = strtolower(trim(is_string($raw) ? $raw : ''));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** @param list<string>|null $allowed @return list<string> */
    private static function slugList(mixed $raw, ?array $allowed, int $limit): array
    {
        $values = is_array($raw) ? $raw : [];
        $result = [];
        foreach ($values as $value) {
            $slug = strtolower(trim(is_string($value) ? $value : ''));
            if (preg_match('/^[a-z][a-z0-9-]{0,49}$/', $slug) !== 1
                || ($allowed !== null && !in_array($slug, $allowed, true))) {
                continue;
            }
            $result[$slug] = true;
            if (count($result) >= $limit) {
                break;
            }
        }
        return array_keys($result);
    }

    /** @return list<string> */
    private static function languageList(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : [];
        $result = [];
        foreach ($values as $value) {
            $language = trim(is_string($value) ? $value : '');
            if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $language) !== 1) {
                continue;
            }
            $result[$language] = true;
            if (count($result) >= 12) {
                break;
            }
        }
        return array_keys($result);
    }

    private static function imageRatio(mixed $raw): string
    {
        $value = trim(is_string($raw) ? $raw : '');
        return preg_match('/^[1-9]\d?:[1-9]\d?$/', $value) === 1 ? $value : '';
    }

    private static function version(mixed $raw): string
    {
        $value = trim(is_string($raw) ? $raw : '');
        return preg_match('/^\d{1,3}\.\d{1,3}(?:\.\d{1,3})?$/', $value) === 1 ? $value : '';
    }
}
