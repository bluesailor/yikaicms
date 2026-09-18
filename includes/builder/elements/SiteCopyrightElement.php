<?php
/** 站点版权文字，实时绑定站点设置。备案号已拆为独立的 site-filing 元素，这里仅兼容旧数据。 */

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
            // 旧版「版权 + 备案」合一的开关：缺省仍为开启以保持已发布页脚不变；
            // legacy_filing 让编辑器在两项都关闭后隐藏它们，引导改用独立的备案元素。
            // site_langs：仅在这些站点语言下有意义；编辑器在固定为其它语言的模板里隐藏
            ['key' => 'show_icp', 'type' => 'checkbox', 'label' => __('blox_copyright_show_icp'), 'default' => true,
                'site_langs' => [SiteCopyrightSettings::FILING_LANGUAGE], 'legacy_filing' => true],
            ['key' => 'show_police', 'type' => 'checkbox', 'label' => __('blox_copyright_show_police'), 'default' => true,
                'site_langs' => [SiteCopyrightSettings::FILING_LANGUAGE], 'legacy_filing' => true],
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
        if (trim($template) === '') {
            $template = '© {year} {site_name} ' . __('footer_copyright');
        }
        $copyright = self::formatText($template, $siteName, (int) date('Y'));
        $items = '<span data-yk-copyright-text>' . htmlspecialchars($copyright, ENT_QUOTES) . '</span>';

        $language = function_exists('siteLang') ? siteLang() : SiteCopyrightSettings::FILING_LANGUAGE;
        if (SiteCopyrightSettings::filingApplies($language)) {
            $items .= implode('', SiteFilingElement::links(
                SiteFilingElement::enabled($data, 'show_icp'),
                SiteFilingElement::enabled($data, 'show_police')
            ));
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
}
