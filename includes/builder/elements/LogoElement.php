<?php
/**
 * 站点标识元素 —— 绑定站点设置（site_logo / site_name），换 logo 不改模板。
 * 头模板生态件（r8）：数据源是设置项而非裸 image，多语言站名经 configRawLang 感知。
 */

declare(strict_types=1);

final class LogoElement extends AbstractElement
{
    private const HEIGHT_MAP = ['sm' => 'h-8', 'md' => 'h-10', 'lg' => 'h-14'];

    public function type(): string { return 'logo'; }
    public function label(): string { return __('blox_el_logo'); }
    public function icon(): string { return 'badge-cc'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }

    public function controls(): array
    {
        return [
            ['key' => 'display', 'type' => 'select', 'label' => __('blox_logo_display'), 'default' => 'both',
                'options' => [
                    'both' => __('blox_logo_both'),
                    'image' => __('blox_logo_image_only'),
                    'text' => __('blox_logo_text_only'),
                ]],
            ['key' => 'height', 'type' => 'select', 'label' => __('blox_ctl_height'), 'default' => 'md',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')]],
            ['key' => 'custom_height', 'type' => 'number', 'label' => __('blox_logo_custom_height'), 'default' => 0,
                'min' => 0, 'max' => 200, 'help' => __('blox_logo_custom_height_help')],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
            ['key' => 'link_home', 'type' => 'checkbox', 'label' => __('blox_logo_link_home'), 'default' => true],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $display = in_array($data['display'] ?? '', ['both', 'image', 'text'], true) ? $data['display'] : 'both';
        $height = self::HEIGHT_MAP[$data['height'] ?? ''] ?? self::HEIGHT_MAP['md'];
        $textClass = ($data['tone'] ?? 'dark') === 'light' ? 'text-white' : 'text-gray-900';
        $logo = function_exists('configRawLang')
            ? self::availableLogoUrl((string) configRawLang('site_logo', ''))
            : '';
        $name = function_exists('configRawLang') ? (string) configRawLang('site_name', '') : '';
        // 精确像素高度（16-200）覆盖三档位；未设置时输出与历史逐字节一致
        $customHeight = (int) ($data['custom_height'] ?? 0);
        $imgSize = $customHeight >= 16 && $customHeight <= 200
            ? 'w-auto" style="max-height:' . $customHeight . 'px'
            : $height . ' w-auto';

        $inner = '';
        if ($display !== 'text' && $logo !== '') {
            $inner .= '<img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="' . htmlspecialchars($name, ENT_QUOTES) . '" class="' . $imgSize . '">';
        }
        if ($display !== 'image' || ($logo === '' && $name !== '')) {
            // 无 logo 图时任何模式都降级到站名文字，保证头部不空洞
            if ($display !== 'image' || $logo === '') {
                $inner .= '<span class="text-xl font-bold ' . $textClass . '">' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
            }
        }
        if ($inner === '') {
            return '';
        }

        $wrap = 'inline-flex items-center gap-2';
        $homeUrl = function_exists('langPrefix') ? langPrefix() . '/' : '/';
        $body = !isset($data['link_home']) || (string) $data['link_home'] !== '0'
            ? '<a href="' . htmlspecialchars($homeUrl, ENT_QUOTES) . '" class="' . $wrap . ' no-underline">' . $inner . '</a>'
            : '<span class="' . $wrap . '">' . $inner . '</span>';
        return '<div' . $this->animationAttrs($data) . '>' . $body . '</div>';
    }

    /** 本地 Logo 文件被迁移或删除时回退站名，避免浏览器用 alt 文本撑出破图。 */
    private static function availableLogoUrl(string $logo): string
    {
        $logo = trim($logo);
        if ($logo === '') {
            return '';
        }
        if (str_starts_with($logo, '//')) {
            return $logo;
        }

        $parts = parse_url($logo);
        if ($parts === false) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '') {
            return in_array($scheme, ['http', 'https'], true) ? $logo : '';
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $normalized = str_replace('\\', '/', $path);
        if ($normalized === '' || str_contains($normalized, "\0")
            || in_array('..', explode('/', $normalized), true)) {
            return '';
        }

        $root = defined('ROOT_PATH') ? rtrim((string) ROOT_PATH, '/\\') : '';
        return $root !== '' && is_file($root . '/' . ltrim($normalized, '/')) ? $logo : '';
    }

}
