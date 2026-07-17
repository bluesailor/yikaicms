<?php
/**
 * YikaiCMS 页面构建器 —— 可复用块库（P2）。
 *
 * blocks_library 表存命名区块（单个 section 的 JSON）。页面 blocks_data 里的区块
 * 以 {library_id: N} 引用，BlockRenderer 渲染时经此展开——库里改一处全站生效。
 */

declare(strict_types=1);

final class BlocksLibrary
{
    /**
     * 测试注入点：fn(int $id): ?array，返回 section 数组。
     * 非 null 时短路 DB 查询，让渲染器测试无需数据库。
     * @var callable|null
     */
    public static $resolver = null;

    /** 按 id 取库块的 section 数组；不存在/表缺失/JSON 损坏返回 null（渲染静默跳过） */
    public static function get(int $id): ?array
    {
        if (self::$resolver !== null) {
            return (self::$resolver)($id);
        }
        if ($id <= 0 || !function_exists('db')) {
            return null;
        }
        try {
            $row = db()->fetchOne(
                'SELECT data FROM ' . DB_PREFIX . 'blocks_library WHERE id = ?',
                [$id]
            );
        } catch (\Throwable $e) {
            // 表未升级等：容错为「引用不存在」
            return null;
        }
        if (!$row || empty($row['data'])) {
            return null;
        }
        $section = json_decode($row['data'], true);
        return is_array($section) ? $section : null;
    }
}
