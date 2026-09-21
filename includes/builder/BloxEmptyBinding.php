<?php
/**
 * 动态绑定为空时怎么办（E10）。
 *
 * 标签层已有 fallback 管道（`{{loop.subtitle | 备用文案}}`），解决的是"给个替代值"。
 * 但更常见的诉求是**别占位**：产品没有副标题时，卡片上不该留一行空白。此前元素照常
 * 渲染出空标签，版面就多出一道缝。
 *
 * 四种规则对应计划原文——替代值由 fallback 管道承担，这里实现其余三种：
 *   keep   保留占位（默认，旧文档行为不变）
 *   hide   隐藏这个元素
 *   row    连所在的直接容器一起隐藏（整行消失，不留空容器的内边距与间距）
 *
 * 只有**确实带动态标签且解析为空**才触发：作者手写的空文本不算动态绑定为空，
 * 那是他自己要的空元素。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class BloxEmptyBinding
{
    public const KEEP = 'keep';
    public const HIDE = 'hide';
    public const ROW = 'row';

    /**
     * 各元素的"主内容"字段：判断绑定是否为空只看这些键。
     *
     * 只列元素的主体内容，不含链接、alt 这类附属字段——副标题为空要隐藏，
     * 链接为空却不该让整段文字消失。未登记的类型不参与，等于保持旧行为。
     */
    private const CONTENT_FIELDS = [
        'heading' => ['text'],
        'text' => ['html'],
        'image' => ['src'],
        'button' => ['label'],
        'card' => ['title', 'text'],
    ];

    /** 控件声明：供元素 controls() 展开，避免每个元素各写一份。 */
    public static function control(): array
    {
        return [
            'key' => '_empty_binding',
            'type' => 'select',
            'label' => __('blox_empty_binding'),
            'default' => self::KEEP,
            'tab' => 'advanced',
            'options' => [
                self::KEEP => __('blox_empty_binding_keep'),
                self::HIDE => __('blox_empty_binding_hide'),
                self::ROW => __('blox_empty_binding_row'),
            ],
        ];
    }

    /**
     * 本元素的动态绑定是否解析为空。
     *
     * @param array<string,mixed> $data
     */
    public static function isEmpty(string $type, array $data): bool
    {
        $fields = self::CONTENT_FIELDS[$type] ?? null;
        if ($fields === null) {
            return false;
        }
        $sawBinding = false;
        foreach ($fields as $field) {
            $raw = $data[$field] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            if (!BloxDynamicTags::hasTags($raw)) {
                // 有静态内容就不是"绑定为空"，无论其它字段如何
                return false;
            }
            $sawBinding = true;
            if (trim(strip_tags(BloxDynamicTags::resolveText($raw))) !== '') {
                return false;
            }
        }
        return $sawBinding;
    }

    /**
     * 本次渲染该怎么处理：返回 keep / hide / row。
     *
     * @param array<string,mixed> $data
     */
    public static function decide(string $type, array $data): string
    {
        $rule = (string) ($data['_empty_binding'] ?? self::KEEP);
        if ($rule !== self::HIDE && $rule !== self::ROW) {
            return self::KEEP;
        }
        return self::isEmpty($type, $data) ? $rule : self::KEEP;
    }
}
