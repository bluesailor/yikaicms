<?php
declare(strict_types=1);

/** 站点动效策略不进入模板包；模板只携带效果，目标站决定强度。 */
final class BloxMotion
{
    public const LEVELS = ['none', 'light', 'standard'];

    public static function level(): string
    {
        $level = config('motion_intensity', 'standard');
        return in_array($level, self::LEVELS, true) ? $level : 'standard';
    }

    public static function headHtml(): string
    {
        $level = self::level();
        $html = '<meta name="yk-motion" content="' . $level . '">';
        if ($level === 'standard') return $html;
        // 不禁用交互、定位或吸顶头部的功能性 transform；只降低已知的装饰性动效。
        $css = '.yk-icon-motion,.yk-logo-track{animation:none!important;}';
        $css .= '.card-hover:hover,.img-zoom:hover img,.yk-card-hover-zoom:is(:hover,:focus-visible) .yk-card-media img{transform:none!important;}';
        $css .= '.yk-card-hover-lift:is(:hover,:focus-visible),[data-yk-motion-hover]:hover{translate:none!important;}';
        if ($level === 'none') {
            $css .= '[data-animate],[data-aos],[data-stagger]>*{opacity:1!important;animation:none!important;transition:none!important;}';
            $css .= '.card-hover,.img-zoom img,.yk-card-hover-lift,.yk-card-hover-zoom .yk-card-media img,[data-yk-motion-hover]{transition:none!important;}';
        }
        return $html . '<style data-yk-motion-policy>' . $css . '</style>';
    }

    public static function renderHead(): void
    {
        echo self::headHtml();
    }
}
