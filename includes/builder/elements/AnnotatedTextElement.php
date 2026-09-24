<?php
/** 短语标注文字：保留语义文字，用受控 SVG 装饰首个匹配短语。 */

declare(strict_types=1);

final class AnnotatedTextElement extends AbstractElement
{
    private const SIZES = [
        'h1' => 'text-3xl md:text-3xl',
        'h2' => 'text-2xl md:text-2xl',
        'h3' => 'text-xl md:text-xl',
        'h4' => 'text-lg',
        'h5' => 'text-base',
        'h6' => 'text-sm',
        'p' => 'text-base',
    ];

    private const PATHS = [
        'underline' => 'M2 32 C21 28 33 34 51 30 S78 31 98 29',
        'wavy' => 'M2 31 C10 21 18 39 26 30 S42 21 50 30 S66 39 74 30 S90 21 98 30',
        'highlight' => 'M2 25 C24 21 47 27 68 23 S87 24 98 21',
        'circle' => 'M51 5 C75 3 97 8 98 19 C101 32 79 37 49 36 C20 36 2 30 2 20 C1 10 23 5 51 5 Z',
        'box' => 'M4 7 L94 4 L98 31 L6 36 L4 7',
    ];

    public function type(): string { return 'annotated-text'; }
    public function label(): string { return __('blox_annotated_label'); }
    public function icon(): string { return 'highlight'; }
    public function treeLabelField(): ?string { return 'text'; }
    public function styles(): array { return ['/assets/css/blox-annotated-text.css']; }
    public function scriptsFor(array $data): array
    {
        return in_array($data['animate'] ?? true, [true, 1, '1'], true)
            ? ['/assets/js/blox-annotated-text.js'] : [];
    }

    public function controls(): array
    {
        return [
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_annotated_text'),
                'default' => __('blox_annotated_default_text'), 'maxlength' => 2000],
            ['key' => 'mark_text', 'type' => 'text', 'label' => __('blox_annotated_phrase'),
                'default' => __('blox_annotated_default_phrase'), 'maxlength' => 40,
                'help' => __('blox_annotated_phrase_help')],
            ['key' => 'level', 'type' => 'select', 'label' => __('blox_ctl_level'), 'default' => 'h2',
                'options' => ['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => __('blox_annotated_paragraph')]],
            ['key' => 'variant', 'type' => 'select', 'label' => __('blox_annotated_style'), 'default' => 'underline',
                'tab' => 'style', 'options' => [
                    'underline' => __('blox_annotated_underline'),
                    'wavy' => __('blox_annotated_wavy'),
                    'highlight' => __('blox_annotated_highlight'),
                    'circle' => __('blox_annotated_circle'),
                    'box' => __('blox_annotated_box'),
                ]],
            ['key' => 'mark_color', 'type' => 'color', 'label' => __('blox_annotated_color'),
                'default' => '#6366f1', 'tab' => 'style'],
            ['key' => 'animate', 'type' => 'checkbox', 'label' => __('blox_annotated_animate'),
                'default' => true, 'tab' => 'style', 'help' => __('blox_annotated_animate_help')],
            ['key' => 'draw_trigger', 'type' => 'select', 'label' => __('blox_anim_trigger'),
                'default' => 'viewport', 'tab' => 'style', 'required' => ['animate', '=', true],
                'options' => ['viewport' => __('blox_anim_trigger_viewport'), 'load' => __('blox_anim_trigger_load')]],
            ['key' => 'draw_speed', 'type' => 'select', 'label' => __('blox_anim_speed'),
                'default' => 'normal', 'tab' => 'style', 'required' => ['animate', '=', true],
                'options' => ['fast' => __('blox_anim_fast'), 'normal' => __('blox_anim_normal'), 'slow' => __('blox_anim_slow')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $text = is_scalar($data['text'] ?? null) ? mb_substr((string) $data['text'], 0, 2000) : '';
        $phrase = is_scalar($data['mark_text'] ?? null) ? mb_substr((string) $data['mark_text'], 0, 40) : '';
        $level = is_string($data['level'] ?? null) && isset(self::SIZES[$data['level']]) ? $data['level'] : 'h2';
        $variant = is_string($data['variant'] ?? null) && isset(self::PATHS[$data['variant']]) ? $data['variant'] : 'underline';
        $content = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $position = $phrase !== '' ? mb_strpos($text, $phrase) : false;
        if ($position !== false) {
            $prefix = htmlspecialchars(mb_substr($text, 0, $position), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $marked = htmlspecialchars($phrase, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $suffix = htmlspecialchars(mb_substr($text, $position + mb_strlen($phrase)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $color = self::cssColor($data['mark_color'] ?? '#6366f1') ?? '#6366f1';
            $speed = in_array($data['draw_speed'] ?? null, ['fast', 'normal', 'slow'], true) ? $data['draw_speed'] : 'normal';
            $trigger = ($data['draw_trigger'] ?? null) === 'load' ? 'load' : 'viewport';
            $animate = in_array($data['animate'] ?? true, [true, 1, '1'], true) ? '1' : '0';
            $mark = '<span class="yk-annotated-mark yk-annotated-mark--' . $variant . '"'
                . ' style="--yk-annotated-color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-yk-annotated data-annotation-animate="' . $animate . '"'
                . ' data-annotation-trigger="' . $trigger . '" data-annotation-speed="' . $speed . '">'
                . '<span class="yk-annotated-mark__text">' . $marked . '</span>'
                . '<svg class="yk-annotated-mark__svg" viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
                . '<path class="yk-annotated-mark__stroke" d="' . self::PATHS[$variant] . '" pathLength="100"></path></svg></span>';
            $content = $prefix . $mark . $suffix;
        }
        $content = str_replace(["\r\n", "\r", "\n"], '<br>', $content);
        $class = 'yk-annotated-text ' . self::SIZES[$level] . ($level === 'p' ? '' : ' font-bold') . ' mb-4';
        $themeClass = class_exists(BloxDesignTheme::class) && BloxDesignTheme::hasTypography($level)
            ? ' yk-type-' . $level : '';
        return '<' . $level . ' class="' . $class . $themeClass . '">' . $content . '</' . $level . '>';
    }
}
