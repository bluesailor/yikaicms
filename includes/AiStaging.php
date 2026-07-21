<?php
/**
 * Yikai CMS - AI 变更暂存（提案→确认→应用 的服务端存储）
 *
 * Agent 循环里，写类能力不直接执行，而是把「提案」暂存到会话；
 * 用户在 UI 确认后，api_ai_apply.php 按 set_id 取回**服务端**暂存的提案再执行
 * （绝不信任前端回传的改动内容，防篡改）。暂存 30 分钟自动过期。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class AiStaging
{
    private const TTL = 1800; // 30 分钟

    private static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['ai_staging'] ??= [];
    }

    /** 新建一个提案集，返回 set_id */
    public static function newSet(): string
    {
        self::boot();
        self::gc();
        return 'aps_' . bin2hex(random_bytes(8));
    }

    /** 追加一条提案，返回 proposal_id */
    public static function add(string $setId, string $ability, array $input, array $preview): string
    {
        self::boot();
        $_SESSION['ai_staging'][$setId] ??= ['created' => time(), 'admin_id' => (int) ($_SESSION['admin_id'] ?? 0), 'items' => []];
        $pid = 'p' . count($_SESSION['ai_staging'][$setId]['items']);
        $_SESSION['ai_staging'][$setId]['items'][] = [
            'id'      => $pid,
            'ability' => $ability,
            'input'   => $input,
            'preview' => $preview,
        ];
        return $pid;
    }

    /** 取回某提案集的全部提案（仅限本管理员） */
    public static function items(string $setId): array
    {
        self::boot();
        $set = $_SESSION['ai_staging'][$setId] ?? null;
        if (!$set) return [];
        if ((int) ($set['admin_id'] ?? 0) !== (int) ($_SESSION['admin_id'] ?? 0)) return [];
        if ((int) ($set['created'] ?? 0) < time() - self::TTL) { self::clear($setId); return []; }
        return $set['items'] ?? [];
    }

    public static function clear(string $setId): void
    {
        self::boot();
        unset($_SESSION['ai_staging'][$setId]);
    }

    /** 清理过期提案集 */
    private static function gc(): void
    {
        foreach (($_SESSION['ai_staging'] ?? []) as $k => $v) {
            if ((int) ($v['created'] ?? 0) < time() - self::TTL) {
                unset($_SESSION['ai_staging'][$k]);
            }
        }
    }
}
