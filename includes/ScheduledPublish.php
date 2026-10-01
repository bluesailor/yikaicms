<?php
/**
 * 定时发布（文章等 contents 内容）与定时上架（产品）共用的规则（2.0.3）。
 *
 * 状态：0 草稿 / 下架，1 已发布 / 上架，3 定时（到发布时间自动变 1）。
 * 前台只认 status = 1，所以定时内容在上线前对访客不可见。
 *
 * 发布时间存哪：文章用 contents.publish_time；产品表没有这一列，存在通用元数据表
 * metas（owner_type=product、meta_key=publish_time）。不给 products 加列是刻意的：
 * 整站模板包按表结构精确比对，加一列会让已发布的全部整站模板在新版本上无法导入；
 * metas 本身随整站模板导出导入，定时时间也会跟着走。
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
    public const PRODUCT_META = 'publish_time';

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

    /** 产品的上架时间（未设置为 0）。 */
    public static function productTime(int $productId): int
    {
        return $productId > 0 ? (int) metaModel()->get('product', $productId, self::PRODUCT_META, 0) : 0;
    }

    /** 设置产品上架时间；0 = 删除（不再定时）。 */
    public static function setProductTime(int $productId, int $time): void
    {
        if ($productId <= 0) {
            return;
        }
        if ($time > 0) {
            metaModel()->set('product', $productId, self::PRODUCT_META, (string) $time);
        } else {
            metaModel()->del('product', $productId, self::PRODUCT_META);
        }
    }

    /**
     * 一批产品的上架时间（列表页用，一次查询）。
     *
     * @param list<int> $ids
     * @return array<int,int>
     */
    public static function productTimes(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $rows = db()->fetchAll(
            'SELECT owner_id, meta_value FROM ' . DB_PREFIX . 'metas WHERE owner_type = ? AND meta_key = ? AND owner_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge(['product', self::PRODUCT_META], $ids)
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['owner_id']] = (int) $row['meta_value'];
        }
        return $out;
    }

    /**
     * 把到点的定时文章与产品上线。返回上线条数；有上线时清页面缓存。
     */
    public static function sweep(?int $now = null): int
    {
        $now ??= time();
        $total = 0;
        try {
            $total += db()->execute(
                'UPDATE ' . DB_PREFIX . 'contents SET status = 1, updated_at = ?'
                . ' WHERE status = ' . self::STATUS . ' AND publish_time > 0 AND publish_time <= ?',
                [$now, $now]
            );
        } catch (Throwable) {
            // 表缺失（安装未完成）时跳过
        }
        try {
            $due = array_map('intval', array_column(db()->fetchAll(
                'SELECT p.id FROM ' . DB_PREFIX . 'products p JOIN ' . DB_PREFIX . 'metas m'
                . ' ON m.owner_type = ? AND m.owner_id = p.id AND m.meta_key = ?'
                // 时间直接写进 SQL（int）：SQLite 里「表达式 <= 绑定的字符串参数」按类型排序比较，数字永远小于文本
                . ' WHERE p.status = ' . self::STATUS . ' AND (m.meta_value + 0) > 0 AND (m.meta_value + 0) <= ' . (int) $now,
                ['product', self::PRODUCT_META]
            ), 'id'));
            if ($due !== []) {
                $total += db()->execute(
                    'UPDATE ' . DB_PREFIX . 'products SET status = 1, updated_at = ? WHERE status = ' . self::STATUS
                    . ' AND id IN (' . implode(',', array_fill(0, count($due), '?')) . ')',
                    array_merge([$now], $due)
                );
            }
        } catch (Throwable) {
            // 同上
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
}
