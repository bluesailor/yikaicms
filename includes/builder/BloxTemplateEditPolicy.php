<?php
declare(strict_types=1);

/**
 * YIKAI_BLOX_AI_ACCESS_NOTICE
 * AI-assisted reading or modification requires explicit task-scoped authorization.
 * Policy: docs/blox-commercialization/CORE-ACCESS.md
 * This collaboration notice is not access control and does not replace licenses.
 */

/** Editing capability only; resource downloads and administrator roles are checked separately. */
final class BloxTemplateEditPolicy
{
    // Template editing is separate from paid element features and remote acquisition.
    private const EDITING_TIERS = [
        'section' => 'free',
        'page' => 'free',
        'header' => 'free',
        'footer' => 'free',
        'product-detail' => 'free',
        'article-detail' => 'free',
        'popup' => 'advanced',
        'archive' => 'advanced', // 依赖查询循环（current 源），随专业能力档
        'search' => 'free',
        'error404' => 'free',
        // 组件母版（v2.1）：新建 / 编辑 / 发布归专业版（blox-feature-policy 的 components）；
        // 渲染、插入实例、改实例属性、脱离不经过这里，永远免费
        'component' => 'components',
    ];

    public static function allows(string $type, bool $advanced): bool
    {
        return match (self::EDITING_TIERS[$type] ?? '') {
            'free' => true,
            'advanced' => $advanced,
            'components' => BloxFeaturePolicy::allows('components'),
            default => false,
        };
    }
}
