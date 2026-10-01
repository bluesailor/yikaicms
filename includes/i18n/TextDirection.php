<?php
/**
 * Blox 文档里存的「左 / 右」在输出时一律按「起始 / 结束」理解（逻辑方向）。
 *
 * 文档与模板都是在从左到右的编辑器里做的；同一份版式用到阿拉伯语页面时，整体镜像才对
 * （标题靠右、侧边距换边），这也是 RTL 本地化的通行做法。LTR 页面输出的视觉效果与改之前完全相同：
 * text-start 就是左对齐，margin-inline-start 就是 margin-left。
 *
 * 不镜像的：背景图与图片焦点位置（图片本身不翻转）、横幅里的入场动画方向。
 */

declare(strict_types=1);

final class TextDirection
{
    /** 存储值 → text-align 取值：left→start、right→end，其余（center、justify）原样。 */
    public static function alignValue(string $align): string
    {
        return match ($align) {
            'left' => 'start',
            'right' => 'end',
            default => $align,
        };
    }

    /** 存储值 → Tailwind 对齐类：left→text-start、right→text-end、center→text-center、justify→text-justify；其他为空串。 */
    public static function alignClass(string $align): string
    {
        return match ($align) {
            'left' => 'text-start',
            'right' => 'text-end',
            'center' => 'text-center',
            'justify' => 'text-justify',
            default => '',
        };
    }

    /** margin-left / padding-right / border-left-width … → 对应的 inline-start / inline-end 逻辑属性；其他原样。 */
    public static function property(string $property): string
    {
        return (string) preg_replace_callback('/^(margin|padding|border)-(left|right)(-.+)?$/D',
            static fn(array $m): string => $m[1] . '-inline-' . ($m[2] === 'left' ? 'start' : 'end') . ($m[3] ?? ''),
            $property);
    }

    /** 「靠左 / 靠右」摆放块元素：左 = margin-inline-start:0 + margin-inline-end:auto。 */
    public static function blockAlignCss(string $align): string
    {
        return match ($align) {
            'left' => 'margin-inline-start:0;margin-inline-end:auto',
            'right' => 'margin-inline-start:auto;margin-inline-end:0',
            default => 'margin-inline-start:auto;margin-inline-end:auto',
        };
    }
}
