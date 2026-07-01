<?php
/**
 * 更新检测：统计「待应用的数据库迁移」数量（= 有可执行的升级）。
 * 复用 Migrator（含 _columnExists / loadAll / isApplied）。结果缓存 300 秒，
 * 避免每个后台页都跑一遍 check 查询。供菜单红点 + 首页提示使用。
 */

if (!defined('ROOT_PATH')) exit;

require_once ROOT_PATH . '/includes/Migrator.php';

/** 实时统计待应用迁移数（会跑各迁移的 check()） */
function ik_count_pending_updates(): int
{
    try {
        $n = 0;
        foreach (Migrator::loadAll() as $m) {
            if (!Migrator::isApplied($m)) $n++;
        }
        return $n;
    } catch (\Throwable $e) {
        return 0;
    }
}

/** 带缓存的待更新数（默认读 300s 内缓存；$fresh=true 强制刷新） */
function ik_pending_updates_count(bool $fresh = false): int
{
    if (!$fresh) {
        try {
            $c = (string) settingModel()->get('ik_updates_cache', '');
            if ($c !== '' && str_contains($c, ':')) {
                [$ts, $cnt] = explode(':', $c, 2);
                if (time() - (int) $ts < 300) return (int) $cnt;
            }
        } catch (\Throwable $e) {}
    }
    $cnt = ik_count_pending_updates();
    try {
        settingModel()->set('ik_updates_cache', time() . ':' . $cnt, 'system');
    } catch (\Throwable $e) {}
    return $cnt;
}
