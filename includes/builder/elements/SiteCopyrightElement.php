<?php
/** 站点版权与备案信息，实时绑定页脚和基本设置。 */

declare(strict_types=1);

final class SiteCopyrightElement extends AbstractElement
{
    private const ALIGN_MAP = [
        'left' => 'justify-start text-left',
        'center' => 'justify-center text-center',
        'right' => 'justify-end text-right',
    ];

    public function type(): string { return 'site-copyright'; }
    public function label(): string { return __('blox_el_site_copyright'); }
    public function icon(): string { return 'copyright'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'show_icp', 'type' => 'checkbox', 'label' => __('blox_copyright_show_icp'), 'default' => true],
            ['key' => 'show_police', 'type' => 'checkbox', 'label' => __('blox_copyright_show_police'), 'default' => true],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $siteName = function_exists('configRawLang') ? configRawLang('site_name', 'Yikai CMS') : 'Yikai CMS';
        $template = function_exists('configRawLang') ? configRawLang('footer_copyright_text', '') : '';
        $copyright = self::formatText($template, $siteName, (int) date('Y'));
        $items = '<span data-yk-copyright-text>' . htmlspecialchars($copyright, ENT_QUOTES) . '</span>';

        if (self::enabled($data, 'show_icp', true)
            && self::isChineseMainland()
            && function_exists('config')
        ) {
            $icp = trim((string) config('site_icp', ''));
            if ($icp !== '') {
                $items .= '<a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener" class="hover:underline">'
                    . htmlspecialchars($icp, ENT_QUOTES) . '</a>';
            }
        }
        if (self::enabled($data, 'show_police', true)
            && self::isChineseMainland()
            && function_exists('config')
        ) {
            $police = trim((string) config('site_police', ''));
            if ($police !== '') {
                $items .= '<a href="http://www.beian.gov.cn/" target="_blank" rel="nofollow noopener" class="inline-flex items-center gap-1 hover:underline">'
                    . '<img src="/images/gaba.png" alt="" class="h-4 w-4">'
                    . htmlspecialchars($police, ENT_QUOTES) . '</a>';
            }
        }

        $align = self::ALIGN_MAP[$data['align'] ?? ''] ?? self::ALIGN_MAP['left'];
        $tone = ($data['tone'] ?? 'dark') === 'light' ? 'text-white/70' : 'text-gray-500';
        return '<div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm ' . $align . ' ' . $tone . '">' . $items . '</div>';
    }

    public static function formatText(string $template, string $siteName, int $year): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = '© {year} {site_name}';
        }
        return str_replace(['{year}', '{site_name}'], [(string) $year, $siteName], $template);
    }

    private static function isChineseMainland(): bool
    {
        return !function_exists('siteLang') || siteLang() === 'zh-CN';
    }

    private static function enabled(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        return !in_array($data[$key], [false, 0, '0', '', null], true);
    }
}
