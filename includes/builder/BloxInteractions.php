<?php
/**
 * Blox 元素交互（v1.28 §10.1）：`data._interactions` repeater。
 *
 * PHP 零逻辑承诺：服务端只做归一化/授权/序列化为 `data-yk-interactions` 属性，
 * 全部行为语义在 assets/js/blox-interactions.js（页面含交互元素才按需输出——
 * BloxAssetCollector 现成能力，兑现「未用交互时 Builder 前端 JS = 0」）。
 *
 * 对计划 §10.1 的两处实施收敛（记档）：
 * - target 收敛为 self|parent|selector：element_id 目标在前台没有统一可寻址标记
 *   （data-yk-el-id 只在编辑态输出），受限 selector + 元素锚点 html_id 已覆盖同一需求；
 *   child 语义模糊同期不做。P2 若做组件再议。
 * - conditions[]（交互级条件）不做：服务端条件键无法在客户端求值，需要另一套
 *   客户端词汇表，放 P2；结构上 repeater 条目已是独立对象，将来加键不破坏存量。
 *
 * 授权：interactions 归付费层（PROTECTED_FEATURES 冻结 `_interactions`）；
 * 已发布内容的渲染永远免费（渲染端不查能力位）。现有入场动画保持免费不受影响。
 */

declare(strict_types=1);

final class BloxInteractions
{
    private const MAX_ITEMS = 10;
    public const TRIGGERS = ['click', 'hover', 'page_load', 'enter_viewport', 'scroll'];
    public const ACTIONS = ['show', 'hide', 'toggle', 'add_class', 'remove_class', 'toggle_class', 'animate', 'open_popup', 'close_popup'];
    public const TARGETS = ['self', 'parent', 'selector'];
    /** animate 动作复用入场动画的类白名单（AbstractElement::animationAttrs 同源词表） */
    public const ANIMATIONS = ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in'];
    /** 受限选择器：与弹窗 click 触发同一规则（单一 #id / .class / [data-*]） */
    private const SELECTOR_PATTERN = '/^(?:#[A-Za-z][A-Za-z0-9_-]*|\.[A-Za-z][A-Za-z0-9_-]*|\[data-[a-z0-9_-]+\])$/';
    private const CLASS_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D';

    /** 归一元素 data 上的 `_interactions`：非法条目丢弃，空列表删键（安全边界——值进 DOM 属性驱动 JS）。 */
    public static function normalizeElementData(array $data): array
    {
        if (!array_key_exists('_interactions', $data)) {
            return $data;
        }
        $items = self::normalize($data['_interactions']);
        if ($items === []) {
            unset($data['_interactions']);
        } else {
            $data['_interactions'] = $items;
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $trigger = trim((string) ($item['trigger'] ?? ''));
            $action = trim((string) ($item['action'] ?? ''));
            if (!in_array($trigger, self::TRIGGERS, true) || !in_array($action, self::ACTIONS, true)) {
                continue;
            }
            $normalized = ['trigger' => $trigger, 'action' => $action];

            // scroll 触发的深度（10–100%，语义同弹窗 scroll 触发）
            if ($trigger === 'scroll') {
                $normalized['scroll_depth'] = max(10, min(100, (int) ($item['scroll_depth'] ?? 50)));
            }

            // 目标：popup 动作作用于弹窗 runtime，无目标概念
            if (!in_array($action, ['open_popup', 'close_popup'], true)) {
                $target = trim((string) ($item['target'] ?? 'self'));
                if (!in_array($target, self::TARGETS, true)) {
                    continue;
                }
                $normalized['target'] = $target;
                if ($target === 'selector') {
                    $selector = trim((string) ($item['selector'] ?? ''));
                    if (preg_match(self::SELECTOR_PATTERN, $selector) !== 1) {
                        continue;
                    }
                    $normalized['selector'] = $selector;
                }
            }

            // 动作值：类名 / 动画名 需白名单，其余动作无值
            if (in_array($action, ['add_class', 'remove_class', 'toggle_class'], true)) {
                $value = trim((string) ($item['value'] ?? ''));
                if (preg_match(self::CLASS_PATTERN, $value) !== 1) {
                    continue;
                }
                $normalized['value'] = $value;
            } elseif ($action === 'animate') {
                $value = trim((string) ($item['value'] ?? ''));
                if (!in_array($value, self::ANIMATIONS, true)) {
                    continue;
                }
                $normalized['value'] = $value;
            }

            if (!empty($item['run_once'])) {
                $normalized['run_once'] = true;
            }
            $out[] = $normalized;
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    public static function hasInput(mixed $raw): bool
    {
        return is_array($raw) && $raw !== [];
    }

    /** 渲染期属性值（已归一数据直出 JSON；单引号属性包裹由调用方负责转义）。 */
    public static function attributeValue(array $items): string
    {
        return json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]';
    }

    /**
     * 保存期授权断言（照 BloxDisplayConditions 同款遍历）：文档含交互且能力不可用即拒。
     *
     * @param array<int,mixed> $sections
     */
    public static function assertSectionsAllowed(array $sections, ?bool $advanced = null): void
    {
        $hasInteractions = false;
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        self::assertElement($element, $hasInteractions);
                    }
                }
            }
        }
        if ($hasInteractions && !($advanced ?? BloxFeaturePolicy::allows('interactions'))) {
            throw new RuntimeException(__('blox_interactions_license_required'));
        }
    }

    /** @param array<string,mixed> $element */
    private static function assertElement(array $element, bool &$hasInteractions): void
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $raw = $data['_interactions'] ?? null;
        if (self::hasInput($raw)) {
            $hasInteractions = true;
            if (self::normalize($raw) === []) {
                throw new RuntimeException(__('blox_interactions_invalid'));
            }
        }
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::assertElement($child, $hasInteractions);
            }
        }
    }
}
