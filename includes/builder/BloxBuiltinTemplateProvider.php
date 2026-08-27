<?php
/** Read-only page templates shipped with YikaiCMS and shown in the local library. */

declare(strict_types=1);

final class BloxBuiltinTemplateProvider
{
    private const PRESETS = [
        'hero-intro' => [
            'type' => 'section',
            'file' => 'hero-intro.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_hero_name',
            'description_key' => 'blox_builtin_section_hero_desc',
            'category' => 'landing',
            'thumbnail' => '/assets/images/blox-templates/section-hero-intro.png',
        ],
        'image-text' => [
            'type' => 'section',
            'file' => 'image-text.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_image_text_name',
            'description_key' => 'blox_builtin_section_image_text_desc',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-image-text.jpg',
        ],
        'image-text-reverse' => [
            'type' => 'section',
            'file' => 'image-text-reverse.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_image_text_reverse_name',
            'description_key' => 'blox_builtin_section_image_text_reverse_desc',
            'keywords_key' => 'blox_builtin_section_image_text_reverse_keywords',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-image-text-reverse.png',
        ],
        'text-columns' => [
            'type' => 'section',
            'file' => 'text-columns.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_text_columns_name',
            'description_key' => 'blox_builtin_section_text_columns_desc',
            'keywords_key' => 'blox_builtin_section_text_columns_keywords',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-text-columns.png',
        ],
        'stats-band' => [
            'type' => 'section',
            'file' => 'stats-band.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_stats_name',
            'description_key' => 'blox_builtin_section_stats_desc',
            'category' => 'business',
            'thumbnail' => '/assets/images/blox-templates/section-stats-band.png',
        ],
        'feature-grid' => [
            'type' => 'section',
            'file' => 'feature-grid.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_feature_name',
            'description_key' => 'blox_builtin_section_feature_desc',
            'category' => 'business',
            'thumbnail' => '/assets/images/blox-templates/section-feature-grid.png',
        ],
        'process-steps' => [
            'type' => 'section',
            'file' => 'process-steps.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_process_steps_name',
            'description_key' => 'blox_builtin_section_process_steps_desc',
            'keywords_key' => 'blox_builtin_section_process_steps_keywords',
            'category' => 'business',
            'thumbnail' => '/assets/images/blox-templates/section-process-steps.png',
        ],
        'trust-grid' => [
            'type' => 'section',
            'file' => 'trust-grid.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_trust_grid_name',
            'description_key' => 'blox_builtin_section_trust_grid_desc',
            'keywords_key' => 'blox_builtin_section_trust_grid_keywords',
            'category' => 'business',
            'thumbnail' => '/assets/images/blox-templates/section-trust-grid.png',
        ],
        'card-grid' => [
            'type' => 'section',
            'file' => 'card-grid.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_cards_name',
            'description_key' => 'blox_builtin_section_cards_desc',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-card-grid.jpg',
        ],
        'case-grid' => [
            'type' => 'section',
            'file' => 'case-grid.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_case_grid_name',
            'description_key' => 'blox_builtin_section_case_grid_desc',
            'keywords_key' => 'blox_builtin_section_case_grid_keywords',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-case-grid.png',
        ],
        'testimonial-quote' => [
            'type' => 'section',
            'file' => 'testimonial-quote.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_quote_name',
            'description_key' => 'blox_builtin_section_quote_desc',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-testimonial-quote.png',
        ],
        'faq-accordion' => [
            'type' => 'section',
            'file' => 'faq-accordion.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_faq_name',
            'description_key' => 'blox_builtin_section_faq_desc',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-faq-accordion.png',
        ],
        'cta-banner' => [
            'type' => 'section',
            'file' => 'cta-banner.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_cta_name',
            'description_key' => 'blox_builtin_section_cta_desc',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-cta-banner.png',
        ],
        'contact-strip' => [
            'type' => 'section',
            'file' => 'contact-strip.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_contact_strip_name',
            'description_key' => 'blox_builtin_section_contact_strip_desc',
            'keywords_key' => 'blox_builtin_section_contact_strip_keywords',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-contact-strip.png',
        ],
        'company-intro' => [
            'type' => 'page',
            'file' => 'company-intro.json',
            'contexts' => ['page'],
            'name_key' => 'blox_builtin_company_name',
            'description_key' => 'blox_builtin_company_desc',
            'category' => 'page',
            'thumbnail' => '/assets/images/blox-templates/company-intro.svg',
        ],
        'contact-page' => [
            'type' => 'page',
            'file' => 'contact-page.json',
            'contexts' => ['page'],
            'name_key' => 'blox_builtin_contact_name',
            'description_key' => 'blox_builtin_contact_desc',
            'category' => 'page',
            'thumbnail' => '/assets/images/blox-templates/contact-page.svg',
        ],
        'service-process' => [
            'type' => 'page',
            'file' => 'service-process.json',
            'contexts' => ['page'],
            'name_key' => 'blox_builtin_process_name',
            'description_key' => 'blox_builtin_process_desc',
            'category' => 'page',
            'thumbnail' => '/assets/images/blox-templates/service-process.png',
        ],
        '404-route-lost' => [
            'type' => 'page',
            'file' => '404-route-lost.json',
            'contexts' => ['page'],
            'name_key' => 'blox_builtin_404_name',
            'description_key' => 'blox_builtin_404_desc',
            'category' => 'page',
            'thumbnail' => '/assets/images/blox-templates/404-route-lost.png',
        ],
    ];

    /** @return list<array<string,mixed>> */
    public function items(string $context = 'page'): array
    {
        $items = [];
        foreach (self::PRESETS as $slug => $preset) {
            $path = self::packagePath((string) $preset['type'], (string) $preset['file']);
            if (!in_array($context, $preset['contexts'], true) || !is_file($path)) {
                continue;
            }
            $items[] = [
                'key' => 'builtin:' . $slug,
                'type' => (string) $preset['type'],
                'name' => __((string) $preset['name_key']),
                'description' => __((string) $preset['description_key']),
                'keywords' => isset($preset['keywords_key']) ? __((string) $preset['keywords_key']) : '',
                'source' => 'builtin',
                'provider' => 'yikaicms',
                'category' => (string) $preset['category'],
                'thumbnail' => (string) $preset['thumbnail'],
                'updated_at' => (int) (filemtime($path) ?: 0),
            ];
        }
        return $items;
    }

    /** @return array{key:string,type:string,name:string,source:string,provider:string,sections:array<int,array<string,mixed>>} */
    public function resolve(string $slug, string $context = 'page'): array
    {
        $preset = self::PRESETS[$slug] ?? null;
        if ($preset === null || !in_array($context, $preset['contexts'], true)) {
            throw new RuntimeException(__('blox_builtin_template_not_found'));
        }
        $json = file_get_contents(self::packagePath((string) $preset['type'], (string) $preset['file']));
        if (!is_string($json)) {
            throw new RuntimeException(__('blox_builtin_template_unreadable'));
        }
        $prepared = BloxTemplateImporter::prepare($json);
        if ($prepared['type'] !== $preset['type']) {
            throw new RuntimeException(__('blox_builtin_template_invalid'));
        }

        return [
            'key' => 'builtin:' . $slug,
            'type' => (string) $preset['type'],
            'name' => __((string) $preset['name_key']),
            'source' => 'builtin',
            'provider' => 'yikaicms',
            'sections' => $prepared['sections'],
        ];
    }

    private static function packagePath(string $type, string $file): string
    {
        $directory = $type === 'section' ? 'sections' : 'pages';
        return dirname(__DIR__, 2) . '/templates/blox/' . $directory . '/' . $file;
    }
}
