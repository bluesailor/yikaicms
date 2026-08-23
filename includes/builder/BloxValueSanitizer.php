<?php
/**
 * Blox Typed Control Schema 的执行层（v1.18.6 核心）。
 *
 * controls() 从「编辑器表单描述」升级为「数据契约」：每种 control 类型在
 * 保存管线里有唯一的 normalize/sanitize 规则，新增 Element 只声明字段类型，
 * 不再自行编写安全过滤。由 BloxDocumentPipeline::normalizeElement() 调用——
 * 直接构造 blocks_data 提交也绕不过。
 *
 * 设计约束（与 AbstractElement 一致）：引擎自包含。richtext 依赖全局
 * sanitizeHtml()（functions.php/security.php），无 web 上下文时跳过，
 * 渲染层的第二道净化兜底。
 */

declare(strict_types=1);

final class BloxValueSanitizer
{
    /** text 控件默认长度上限（标题/短文案语义；控件可用 maxlength 覆盖） */
    public const TEXT_MAX = 2000;
    /** textarea 默认长度上限 */
    public const TEXTAREA_MAX = 20000;
    /** icon 名称上限（tabler / bi:xxx 形态） */
    public const ICON_MAX = 100;

    /**
     * 声明为标量的控件类型：收到数组即视为无效输入。
     * 不在此列的（faq_repeater 等复合类型、以及尚未纳入契约的新类型）原样放行，
     * 由对应 Element 的 items()/render() 自行解析与转义。
     */
    private const SCALAR_TYPES = [
        'text', 'textarea', 'richtext', 'url', 'video_url', 'image',
        'number', 'range', 'select', 'checkbox', 'icon',
    ];

    /**
     * 按 control 声明清洗单个标量值。
     *
     * 数组值只有在 control **自己声明了 responsive** 时才算合法（那是断点结构，
     * 由管线的 BloxResponsiveValue 分支先行处理）。其余标量类型收到数组必须归一，
     * 不能原样放行：直接构造 blocks_data 把 text/url/image 提交成数组时，值会一路
     * 传到 htmlspecialchars() 并抛 TypeError，整页 500。（codex 审计 P2-1，已复现）
     *
     * @param array<string,mixed> $control
     */
    public static function sanitize(array $control, mixed $value): mixed
    {
        $type = (string) ($control['type'] ?? '');
        if (is_array($value)) {
            if (!empty($control['responsive'])) {
                return $value;   // 声明过 responsive：断点结构，交给上游分支
            }
            // 只有**标量类型**收到数组才算无效输入。复合类型（faq_repeater 等）本来就
            // 以数组承载内容，一律归一会把它们的数据清空。
            //
            // ⚠ 2026-08-24 修：原先这里不分类型一律清空，导致 accordion 的 items 在
            // 保存管线里被抹成空串——**编辑器保存一次 FAQ 就没了**，随 v1.18.6/1.18.7
            // 发了出去。下面 default 分支那句「repeater 不误伤」的注释当时已经失真，
            // 因为这个 guard 跑在 switch 之前。做整页模板时被导入失败暴露出来。
            if (!in_array($type, self::SCALAR_TYPES, true)) {
                return $value;   // 复合/未知类型：交给各自的 Element / Schema 处理
            }
            // 数值控件继续走下面的 null 分支（那里已有区间兜底）。
            $value = ($type === 'number' || $type === 'range') ? null : '';
        }

        switch ($type) {
            case 'text':
                return mb_substr(self::str($value), 0, self::intOpt($control, 'maxlength', self::TEXT_MAX));

            case 'textarea':
                return mb_substr(self::str($value), 0, self::intOpt($control, 'maxlength', self::TEXTAREA_MAX));

            case 'richtext':
                $html = self::str($value);
                return function_exists('sanitizeHtml') ? sanitizeHtml($html) : $html;

            case 'url':
                // safeHref：站内相对/锚点/查询串/http(s)/mailto/tel/循环占位符；
                // javascript: 等伪协议清为空串（渲染层各元素再兜一道）
                return AbstractElement::safeHref($value);

            case 'video_url':
                // 平台页面地址（watch/BV 链接）与站内直链都是 safeHref 合法集；
                // 嵌入白名单与扩展名约束由 VideoElement 渲染层执行
                return AbstractElement::safeHref($value);

            case 'image':
                return AbstractElement::cssImageUrl(self::str($value)) ?? '';

            case 'number':
            case 'range':
                if (!is_numeric($value)) {
                    $fallback = $control['default'] ?? 0;
                    return is_numeric($fallback) ? $fallback + 0 : 0;
                }
                $n = $value + 0;
                if (isset($control['min']) && is_numeric($control['min'])) {
                    $n = max($control['min'] + 0, $n);
                }
                if (isset($control['max']) && is_numeric($control['max'])) {
                    $n = min($control['max'] + 0, $n);
                }
                return $n;

            case 'select':
                $options = is_array($control['options'] ?? null) ? $control['options'] : [];
                if ($options === []) {
                    return self::str($value);
                }
                // 键转串比较：'0' 与 0 视为同一选项（options 键既有 int 也有 string）
                if (in_array((string) $value, array_map('strval', array_keys($options)), true)) {
                    return $value;
                }
                return $control['default'] ?? array_key_first($options);

            case 'checkbox':
                // 归一为 '1'/'0' 字符串：存量渲染同时存在 !empty() 与
                // (string)$v !== '0' 两种判定，bool false 会被后者误判为真
                return self::truthy($value) ? '1' : '0';

            case 'icon':
                $icon = (string) preg_replace('/[^a-zA-Z0-9:_-]/', '', self::str($value));
                return mb_substr($icon, 0, self::ICON_MAX);

            default:
                // color/responsive 由管线专门分支处理；repeater 等复合类型
                // 各自的 Element/Schema 负责，这里不误伤
                return $value;
        }
    }

    public static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $s = strtolower(trim(self::str($value)));
        return !in_array($s, ['', '0', 'false', 'off', 'no'], true);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string,mixed> $control */
    private static function intOpt(array $control, string $key, int $default): int
    {
        $v = $control[$key] ?? null;
        return is_numeric($v) && (int) $v > 0 ? (int) $v : $default;
    }
}
