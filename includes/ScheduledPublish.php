<?php
/**
 * 定时发布（文章等 contents 内容）与定时上架（产品）共用的规则（2.0.3）。
 *
 * 状态：0 草稿 / 下架，1 已发布 / 上架，3 定时（到 publish_time 自动变 1）。
 * 前台只认 status = 1，所以定时内容在上线前对访客不可见。
 *
 * 保存时以发布时间为准：
 *   - 选「定时」但时间已到或已过 → 直接发布；
 *   - 选「发布」但时间在未来 → 自动改为定时（不会提前上线、带着未来日期挂在列表里）；
 *   - 选「定时」却没填时间 → 拒绝保存。
 *
 * 到点上线由 sweep() 完成：前台每次访问限流 60 秒扫一次（includes/init.php），
 * 配了系统定时任务的站点另由 Cron「publish_sweep」每分钟扫一次。上线后清页面缓存。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class ScheduledPublish
{
    public const STATUS = 3;

    /**
     * 按发布时间规范化状态。
     *
     * @param int    $status   表单提交的状态（0 / 1 / 3）
     * @param string $posted   表单提交的时间（datetime-local，可空）
     * @param int    $existing 已保存的发布时间（新建为 0）
     * @return array{status:int, publish_time:int}
     * @throws InvalidArgumentException 选了定时却没给时间
     */
    public static function normalize(int $status, string $posted, int $existing = 0, ?int $now = null): array
    {
        $now ??= time();
        $time = trim($posted) !== '' ? (int) strtotime($posted) : 0;
        if ($status === self::STATUS) {
            if ($time <= 0) {
                throw new InvalidArgumentException('scheduled_time_required');
            }
            return ['status' => $time <= $now ? 1 : self::STATUS, 'publish_time' => $time];
        }
        if ($status === 1) {
            if ($time > $now) {
                return ['status' => self::STATUS, 'publish_time' => $time];
            }
            return ['status' => 1, 'publish_time' => $time > 0 ? $time : ($existing > 0 ? $existing : $now)];
        }
        return ['status' => 0, 'publish_time' => $time > 0 ? $time : $existing];
    }

    /**
     * 把到点的定时文章与产品上线。返回上线条数；有上线时清页面缓存。
     */
    public static function sweep(?int $now = null): int
    {
        $now ??= time();
        $total = 0;
        foreach (['contents', 'products'] as $table) {
            try {
                $total += db()->execute(
                    'UPDATE ' . DB_PREFIX . $table . ' SET status = 1, updated_at = ?'
                    . ' WHERE status = ' . self::STATUS . ' AND publish_time > 0 AND publish_time <= ?',
                    [$now, $now]
                );
            } catch (Throwable) {
                // 老站还没跑产品表迁移（缺 publish_time）时跳过这一张表
            }
        }
        if ($total > 0) {
            // 批量 UPDATE 不经过 Model，不会触发 data_changed：手动通知，列表页与首页缓存才会刷新
            if (function_exists('do_action')) {
                do_action('data_changed', 'contents', null, []);
            } elseif (class_exists('HtmlCache')) {
                HtmlCache::invalidate();
            }
        }
        return $total;
    }

    /**
     * 产品表是否已有 publish_time（2.0.3 迁移 20261001_product_publish_time）。
     * 文件已升级、数据库升级还没跑的那段时间里，产品编辑不提供定时、也不写这一列，保存照常。
     */
    public static function productsReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $table = DB_PREFIX . 'products';
                $ready = (bool) db()->fetchOne(db()->isSqlite()
                    ? "SELECT 1 FROM pragma_table_info('" . $table . "') WHERE name = 'publish_time'"
                    : "SHOW COLUMNS FROM `" . $table . "` LIKE 'publish_time'");
            } catch (Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /** 后台列表里的状态单元格文字（不含可切换按钮）：定时 + 时间。 */
    public static function badge(int $publishTime, string $label): string
    {
        return '<span class="text-orange-500" title="' . e($label . '：' . date('Y-m-d H:i', $publishTime)) . '">'
            . e($label) . '</span>';
    }
}
