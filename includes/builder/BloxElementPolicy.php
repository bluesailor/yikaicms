<?php
/**
 * Blox 元素级权限边界（服务端强制）。
 *
 * CodeElement 前台原样输出，等于任意 HTML/JavaScript 执行能力。edit_page
 * 的语义是「编辑页面内容」，不该隐含它——否则默认「内容编辑」角色即可向
 * 全站访客注入脚本。编辑器隐藏 UI 挡不住直接构造 blocks_data 提交的人，
 * 必须在保存管线（BloxDocumentPipeline::process）里按当前会话能力拒绝。
 *
 * 递归检查所有层级：code 元素可以嵌在容器元素的 children 里。
 */

declare(strict_types=1);

final class BloxElementPolicy
{
    /** 需要 blox_code 权限才能保存的元素类型 */
    private const CODE_TYPES = ['code'];

    /**
     * 当前会话能否保存 code 元素。
     *
     * hasPermission 不存在 = 无后台会话上下文（CLI 迁移 / 队列脚本）——不设闸：
     * web 保存入口全部先过登录 + requirePermission，攻击面只存在于有会话的请求。
     */
    public static function canUseCode(): bool
    {
        if (!function_exists('hasPermission')) {
            return true;
        }
        return hasPermission('blox_code');
    }

    /** @param array<int,mixed> $sections */
    public static function assertSectionsAllowed(array $sections): void
    {
        if (self::canUseCode()) {
            return;
        }
        foreach ($sections as $section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        self::assertElementAllowed($element);
                    }
                }
            }
        }
    }

    /** @param array<string,mixed> $element */
    private static function assertElementAllowed(array $element): void
    {
        $type = trim((string) ($element['type'] ?? ''));
        if (in_array($type, self::CODE_TYPES, true)) {
            throw new RuntimeException(__('blox_code_perm_required'));
        }
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::assertElementAllowed($child);
            }
        }
    }
}
