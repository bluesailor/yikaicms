<?php
/** 站点备案信息（ICP / 公安备案），与版权文字分开布局；仅简体中文页面输出。 */

declare(strict_types=1);

final class SiteFilingElement extends AbstractElement
{
    private const ALIGN_MAP = [
        'left' => 'justify-start text-left',
        'center' => 'justify-center text-center',
        'right' => 'justify-end text-right',
    ];

    public function type(): string { return 'site-filing'; }
    public function label(): string { return __('blox_el_site_filing'); }
    public function icon(): string { return 'shield-check'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'show_icp', 'type' => 'checkbox', 'label' => __('blox_copyright_show_icp'), 'default' => true],
            ['key' => 'show_police', 'type' => 'checkbox', 'label' => __('blox_copyright_show_police'), 'default' => true],
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_filing_layout'), 'default' => 'inline',
                'options' => ['inline' => __('blox_filing_layout_inline'), 'stacked' => __('blox_filing_layout_stacked')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $language = function_exists('siteLang') ? siteLang() : SiteCopyrightSettings::FILING_LANGUAGE;
        $links = SiteCopyrightSettings::filingApplies($language)
            ? self::links(self::enabled($data, 'show_icp'), self::enabled($data, 'show_police'))
            : [];
        $tone = ($data['tone'] ?? 'dark') === 'light' ? 'text-white/70' : 'text-gray-500';
        if ($links === []) {
            // 画布里保留可点选的占位，前台不输出任何空壳
            return BlockRenderer::$showHidden
                ? '<div class="text-xs italic opacity-60 ' . $tone . '">' . htmlspecialchars(__('blox_filing_canvas_empty'), ENT_QUOTES) . '</div>'
                : '';
        }

        $align = self::ALIGN_MAP[$data['align'] ?? ''] ?? self::ALIGN_MAP['left'];
        $layout = ($data['layout'] ?? 'inline') === 'stacked'
            ? 'flex flex-col gap-1 ' . str_replace('justify-', 'items-', $align)
            : 'flex flex-wrap items-center gap-x-5 gap-y-2 ' . $align;
        return '<div class="' . $layout . ' text-sm ' . $tone . '">' . implode('', $links) . '</div>';
    }

    /**
     * 备案链接的唯一渲染入口（旧版版权元素兼容输出也走这里）。调用方负责语言判断。
     *
     * @return list<string>
     */
    public static function links(bool $showIcp, bool $showPolice): array
    {
        if (!function_exists('config')) {
            return [];
        }
        $links = [];
        $icp = trim((string) config('site_icp', ''));
        if ($showIcp && $icp !== '') {
            $links[] = '<a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener" class="hover:underline">'
                . htmlspecialchars($icp, ENT_QUOTES) . '</a>';
        }
        $police = trim((string) config('site_police', ''));
        if ($showPolice && $police !== '') {
            $links[] = '<a href="http://www.beian.gov.cn/" target="_blank" rel="nofollow noopener" class="inline-flex items-center gap-1 hover:underline">'
                . '<img src="/images/gaba.png" alt="" class="h-4 w-4">'
                . htmlspecialchars($police, ENT_QUOTES) . '</a>';
        }
        return $links;
    }

    /** 缺省视为开启：与版权元素旧数据的默认语义一致 */
    public static function enabled(array $data, string $key): bool
    {
        if (!array_key_exists($key, $data)) {
            return true;
        }
        return !in_array($data[$key], [false, 0, '0', '', null], true);
    }
}
