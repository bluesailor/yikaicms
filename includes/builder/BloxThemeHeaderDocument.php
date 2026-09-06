<?php
/** 将当前主题生效的 Header 配置转换为可编辑的 Blox 文档。 */

declare(strict_types=1);

final class BloxThemeHeaderDocument
{
    /** @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string} */
    public static function current(string $idPrefix = 'theme-header'): array
    {
        $theme = self::activeTheme();
        if ($theme === 'business') {
            return self::business($idPrefix);
        }
        if ($theme === 'minimal') {
            return self::minimal($idPrefix);
        }
        if ($theme !== 'default') {
            throw new RuntimeException(__('blox_current_header_default_only'));
        }

        $background = (string) config('header_bg_color', '#ffffff');
        $text = (string) config('header_text_color', '#4b5563');
        $sticky = (string) config('header_sticky', '0') === '1';
        $layout = (string) config('header_nav_layout', 'right') === 'below' ? 'below' : 'right';
        $logoHeight = self::logoHeight((int) config('site_logo_max_height', 40));

        $document = [
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => [
                'sticky' => $sticky,
                'sticky_behavior' => 'always',
                'sticky_devices' => BloxHeaderStates::STICKY_DEVICES,
                'header_overlay_enabled' => false,
                'header_states' => [
                    'normal' => [
                        'background' => $background,
                        'text' => $text,
                        'border' => '',
                        'shadow' => 'sm',
                    ],
                    'stuck' => [
                        'background' => $background,
                        'text' => $text,
                        'border' => '#e5e7eb',
                        'shadow' => 'sm',
                    ],
                ],
            ],
            'sections' => $layout === 'below'
                ? self::belowSections($background, $logoHeight)
                : [self::rightSection($background, $logoHeight)],
        ];

        return BloxAreaDocument::process(
            'header',
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $idPrefix
        );
    }

    /** @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string} */
    private static function business(string $idPrefix): array
    {
        $background = '#1e293b';
        $text = '#d1d5db';
        $logoHeight = self::logoHeight((int) config('site_logo_max_height', 48));

        $children = [
            self::logo($logoHeight, 'light'),
            self::navigation([
                'cta_text' => (string) __('detail_consult'),
                'cta_url' => '/contact.html',
                'cta_style' => 'solid',
            ]),
        ];
        if ((string) config('show_lang_switcher', '0') === '1') {
            $children[] = self::languageSwitcher('light');
        }
        $children[] = self::drawer([
            'cta_text' => (string) __('detail_consult'),
            'cta_url' => '/contact.html',
            'cta_style' => 'solid',
        ]);

        $document = [
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => [
                'sticky' => true,
                'sticky_behavior' => 'always',
                'sticky_devices' => BloxHeaderStates::STICKY_DEVICES,
                'header_overlay_enabled' => false,
                'header_states' => [
                    'normal' => [
                        'background' => $background,
                        'text' => $text,
                        'border' => '',
                        'shadow' => 'lg',
                    ],
                    'stuck' => [
                        'background' => $background,
                        'text' => $text,
                        'border' => '',
                        'shadow' => 'lg',
                    ],
                ],
            ],
            'sections' => [self::section($background, [self::container($children)])],
        ];

        return BloxAreaDocument::process(
            'header',
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $idPrefix
        );
    }

    /**
     * Minimal 页头文档:与 marketplace/themes/minimal 原生 Header 对齐——
     * 白底、细边框(gray-200)、随 header_sticky 设置、Logo+普通导航+语言切换+移动抽屉。
     *
     * @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string}
     */
    private static function minimal(string $idPrefix): array
    {
        $background = '#ffffff';
        $text = '#4b5563';
        $border = '#e5e7eb';
        $sticky = (string) config('header_sticky', '1') === '1';
        $logoHeight = self::logoHeight((int) config('site_logo_max_height', 32));

        $children = [
            self::logo($logoHeight),
            self::standardNavigation(),
        ];
        if ((string) config('show_lang_switcher', '0') === '1') {
            $children[] = self::languageSwitcher();
        }
        $children[] = self::drawer();

        $document = [
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => [
                'sticky' => $sticky,
                'sticky_behavior' => 'always',
                'sticky_devices' => BloxHeaderStates::STICKY_DEVICES,
                'header_overlay_enabled' => false,
                'header_states' => [
                    'normal' => [
                        'background' => $background,
                        'text' => $text,
                        'border' => $border,
                        'shadow' => 'none',
                    ],
                    'stuck' => [
                        'background' => $background,
                        'text' => $text,
                        'border' => $border,
                        'shadow' => 'sm',
                    ],
                ],
            ],
            'sections' => [self::section($background, [self::container($children)])],
        ];

        return BloxAreaDocument::process(
            'header',
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $idPrefix
        );
    }

    /** @return array<string,mixed> */
    private static function rightSection(string $background, string $logoHeight): array
    {
        $children = [
            self::logo($logoHeight),
            self::navigation(),
        ];
        if ((string) config('show_lang_switcher', '0') === '1') {
            $children[] = self::languageSwitcher();
        }
        $children[] = self::drawer();

        return self::section($background, [self::container($children)]);
    }

    /** @return list<array<string,mixed>> */
    private static function belowSections(string $background, string $logoHeight): array
    {
        $brandRow = self::section($background, [self::container([
            self::logo($logoHeight),
            self::drawer(),
        ])]);
        $navigationRow = self::section($background, [self::container([
            self::navigation(),
        ], 'start')]);
        $navigationRow['settings']['padding'] = 'none';

        return [$brandRow, $navigationRow];
    }

    /** @param list<array<string,mixed>> $elements @return array<string,mixed> */
    private static function section(string $background, array $elements): array
    {
        return [
            'type' => 'section',
            'settings' => [
                'padding' => 'sm',
                'max_width' => 'wide',
                'gap' => 'none',
                'bg_color' => $background,
            ],
            'columns' => [['elements' => $elements]],
        ];
    }

    /** @param list<array<string,mixed>> $children @return array<string,mixed> */
    private static function container(array $children, string $justify = 'between'): array
    {
        return [
            'type' => 'container',
            'data' => [
                'display' => 'flex',
                'direction' => 'row',
                'wrap' => 'nowrap',
                'gap' => 'md',
                'align' => 'center',
                'justify' => $justify,
                'padding' => 'none',
                'radius' => 'none',
                'children' => $children,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function logo(string $height, string $tone = 'dark'): array
    {
        return [
            'type' => 'logo',
            'data' => ['display' => 'image', 'height' => $height, 'tone' => $tone, 'link_home' => true],
        ];
    }

    /** @return array<string,mixed> */
    private static function navigation(array $extra = []): array
    {
        return [
            'type' => 'nav-mega',
            'data' => ['menu_group' => 0, 'show_desc' => false, 'full_width' => false] + $extra,
        ];
    }

    /** @return array<string,mixed> */
    private static function standardNavigation(array $extra = []): array
    {
        return [
            'type' => 'nav',
            'data' => [
                'menu_group' => 0,
                'parent' => '',
                'nav_only' => true,
                'dropdown' => true,
                'desktop_only' => true,
                'wrap_class' => 'flex flex-nowrap items-center gap-8 whitespace-nowrap',
            ] + $extra,
        ];
    }

    /** @return array<string,mixed> */
    private static function drawer(array $extra = []): array
    {
        return [
            'type' => 'nav-drawer',
            'data' => ['menu_group' => 0, 'side' => 'right', 'show_logo' => true] + $extra,
        ];
    }

    /** @return array<string,mixed> */
    private static function languageSwitcher(string $tone = 'dark'): array
    {
        return [
            'type' => 'language-switcher',
            'data' => ['layout' => 'dropdown', 'display' => 'name', 'tone' => $tone],
        ];
    }

    private static function activeTheme(): string
    {
        if (defined('ROOT_PATH') && class_exists('ThemeRuntime')) {
            return ThemeRuntime::resolve((string) config('current_theme', 'default'), ROOT_PATH . '/themes');
        }
        return (string) config('current_theme', 'default') === 'business' ? 'business' : 'default';
    }

    private static function logoHeight(int $pixels): string
    {
        return match (true) {
            $pixels <= 32 => 'sm',
            $pixels > 48 => 'lg',
            default => 'md',
        };
    }
}
