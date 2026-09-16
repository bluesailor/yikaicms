<?php
/** Read-only page templates shipped with YikaiCMS and shown in the local library. */

declare(strict_types=1);

final class BloxBuiltinTemplateProvider
{
    private const PRESETS = [
        'basic-heading' => [
            'number' => 1,
            'type' => 'section',
            'file' => 'basic-heading.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_basic_heading_name',
            'description_key' => 'blox_builtin_section_basic_heading_desc',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-basic-heading.png',
            'metadata' => [
                'purpose' => 'content',
                'page_types' => ['general', 'home', 'about', 'service', 'landing'],
                'content_slots' => ['heading', 'text', 'button'],
                'cta_type' => 'learn-more',
                'priority' => 90,
            ],
        ],
        'image-text' => [
            'number' => 2,
            'type' => 'section',
            'file' => 'image-text.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_image_text_name',
            'description_key' => 'blox_builtin_section_image_text_desc',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-image-text.jpg',
            'metadata' => [
                'purpose' => 'company-intro',
                'page_types' => ['general', 'home', 'about', 'service'],
                'content_slots' => ['heading', 'text', 'image', 'button'],
                'cta_type' => 'learn-more',
                'image_ratio' => '4:3',
                'priority' => 85,
            ],
        ],
        'feature-grid' => [
            'number' => 3,
            'type' => 'section',
            'file' => 'feature-grid.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_feature_name',
            'description_key' => 'blox_builtin_section_feature_desc',
            'category' => 'business',
            'thumbnail' => '/assets/images/blox-templates/section-feature-grid.png',
            'metadata' => [
                'purpose' => 'features',
                'page_types' => ['general', 'home', 'about', 'service', 'product-list'],
                'content_slots' => ['heading', 'icon', 'text'],
                'priority' => 88,
            ],
        ],
        'cta-banner' => [
            'number' => 4,
            'type' => 'section',
            'file' => 'cta-banner.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_cta_name',
            'description_key' => 'blox_builtin_section_cta_desc',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-cta-banner.png',
            'metadata' => [
                'purpose' => 'cta',
                'page_types' => ['general', 'home', 'about', 'service', 'contact', 'landing'],
                'content_slots' => ['heading', 'text', 'button'],
                'cta_type' => 'contact',
                'priority' => 76,
            ],
        ],
        'cta-split' => [
            'number' => 5,
            'type' => 'section',
            'file' => 'cta-split.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_cta_split_name',
            'description_key' => 'blox_builtin_section_cta_split_desc',
            'keywords_key' => 'blox_builtin_section_cta_split_keywords',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-cta-banner.png',
            'metadata' => [
                'purpose' => 'cta',
                'variant' => 'split',
                'page_types' => ['general', 'home', 'about', 'service', 'contact', 'landing'],
                'content_slots' => ['heading', 'text', 'image', 'button'],
                'cta_type' => 'contact',
                'priority' => 88,
            ],
        ],
        'testimonial-quote' => [
            'number' => 6,
            'type' => 'section',
            'file' => 'testimonial-quote.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_quote_name',
            'description_key' => 'blox_builtin_section_quote_desc',
            'category' => 'content',
            'thumbnail' => '/assets/images/blox-templates/section-testimonial-quote.png',
            'metadata' => [
                'purpose' => 'testimonials',
                'page_types' => ['home', 'about'],
                'content_slots' => ['quote', 'author'],
                'priority' => 68,
            ],
        ],
        'pricing-plans' => [
            'number' => 7,
            'type' => 'section',
            'file' => 'pricing-plans.json',
            'contexts' => ['page', 'home'],
            'name_key' => 'blox_builtin_section_pricing_name',
            'description_key' => 'blox_builtin_section_pricing_desc',
            'keywords_key' => 'blox_builtin_section_pricing_keywords',
            'category' => 'marketing',
            'thumbnail' => '/assets/images/blox-templates/section-pricing-plans.png',
            'metadata' => [
                'purpose' => 'products',
                'page_types' => ['general', 'home', 'service', 'product-list', 'landing'],
                'content_slots' => ['heading', 'text', 'pricing'],
                'cta_type' => 'contact',
                'priority' => 80,
            ],
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
                // 基础区块按固定编号展示（01–06）；整页模板不编号，number 为 0
                'number' => (int) ($preset['number'] ?? 0),
                'type' => (string) $preset['type'],
                'name' => __((string) $preset['name_key']),
                'description' => __((string) $preset['description_key']),
                'keywords' => isset($preset['keywords_key']) ? __((string) $preset['keywords_key']) : '',
                'source' => 'builtin',
                'provider' => 'yikaicms',
                'category' => (string) $preset['category'],
                'thumbnail' => (string) $preset['thumbnail'],
                'metadata' => BloxSectionMetadata::normalize($preset['metadata'] ?? []),
                'updated_at' => (int) (filemtime($path) ?: 0),
            ];
        }
        return $items;
    }

    /**
     * @return array{key:string,type:string,name:string,source:string,provider:string,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,requirements:array<string,mixed>,design_diagnostics:array<string,mixed>,package_json:string,package_version:string}
     */
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
            'settings' => $prepared['settings'],
            'sections' => $prepared['sections'],
            // 画布插入检查用：requirements/诊断展示给编辑器；package_json 只留服务端发评审记录。
            'requirements' => $prepared['requirements'],
            'design_diagnostics' => $prepared['design_diagnostics'],
            'package_json' => $json,
            'package_version' => '',
        ];
    }

    private static function packagePath(string $type, string $file): string
    {
        $directory = $type === 'section' ? 'sections' : 'pages';
        return dirname(__DIR__, 2) . '/templates/blox/' . $directory . '/' . $file;
    }
}
