<?php
declare(strict_types=1);

/** 站点动效策略不进入模板包；模板只携带效果，目标站决定强度。 */
final class BloxMotion
{
    public const LEVELS = ['none', 'light', 'standard'];
    /** 页面切换动画（2.0.4）：浏览器原生跨页 View Transitions，不支持的浏览器照常跳转 */
    public const TRANSITIONS = ['none', 'fade', 'slide', 'zoom'];

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
        $css = '.yk-icon-motion,.yk-logo-track,.yk-aurora::before{animation:none!important;}';
        $css .= '.yk-testimonial-track{animation:none!important;flex-wrap:wrap;}.yk-testimonial-track[aria-hidden="true"]{display:none;}';
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

    public static function transition(): string
    {
        $value = config('page_transition', 'none');
        return in_array($value, self::TRANSITIONS, true) ? $value : 'none';
    }

    /**
     * 跨页切换动画。两页都声明 @view-transition 才会播放，所以整站一个开关即可；
     * 时长随全站动效强度（标准 320ms、轻动效 200ms 且只淡入淡出），无动效时不输出；
     * 访客开了「减少动态」时浏览器仍会切换但不播动画。
     */
    public static function transitionHtml(): string
    {
        $style = self::transition();
        $level = self::level();
        if ($style === 'none' || $level === 'none') {
            return '';
        }
        if ($level === 'light') {
            $style = 'fade';
        }
        $ms = $level === 'light' ? 200 : 320;
        $old = '::view-transition-old(root)';
        $new = '::view-transition-new(root)';
        $timing = $ms . 'ms cubic-bezier(.4,0,.2,1) both';
        $css = '@view-transition{navigation:auto}';
        $css .= match ($style) {
            'slide' => $old . '{animation:' . $timing . ' yk-vt-fade-out,' . $timing . ' yk-vt-slide-out}'
                . $new . '{animation:' . $timing . ' yk-vt-fade-in,' . $timing . ' yk-vt-slide-in}'
                . '@keyframes yk-vt-slide-out{to{translate:-32px 0}}@keyframes yk-vt-slide-in{from{translate:32px 0}}'
                // 从右到左的语言反向滑动
                . 'html[dir="rtl"]' . $old . '{animation-name:yk-vt-fade-out,yk-vt-slide-out-rtl}'
                . 'html[dir="rtl"]' . $new . '{animation-name:yk-vt-fade-in,yk-vt-slide-in-rtl}'
                . '@keyframes yk-vt-slide-out-rtl{to{translate:32px 0}}@keyframes yk-vt-slide-in-rtl{from{translate:-32px 0}}',
            'zoom' => $old . '{animation:' . $timing . ' yk-vt-fade-out,' . $timing . ' yk-vt-zoom-out}'
                . $new . '{animation:' . $timing . ' yk-vt-fade-in,' . $timing . ' yk-vt-zoom-in}'
                . '@keyframes yk-vt-zoom-out{to{scale:.98}}@keyframes yk-vt-zoom-in{from{scale:1.02}}',
            default => $old . '{animation:' . $timing . ' yk-vt-fade-out}' . $new . '{animation:' . $timing . ' yk-vt-fade-in}',
        };
        $css .= '@keyframes yk-vt-fade-out{to{opacity:0}}@keyframes yk-vt-fade-in{from{opacity:0}}';
        $css .= '@media (prefers-reduced-motion:reduce){::view-transition-group(*),::view-transition-old(*),::view-transition-new(*){animation:none!important}}';
        return '<style data-yk-page-transition="' . $style . '">' . $css . '</style>';
    }

    public static function renderTransitionHead(): void
    {
        echo self::transitionHtml();
    }
}
