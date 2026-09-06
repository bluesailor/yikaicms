<?php

declare(strict_types=1);

/**
 * 全站“全部”搜索使用的统一查询。
 */
function globalSearchQuery(string $prefix): string
{
    return "SELECT c.id, c.title, c.summary, c.cover, c.publish_time as sort_time, c.type as _type, c.type,
                    ch.name as channel_name, ch.slug as channel_slug
             FROM {$prefix}contents c
             LEFT JOIN {$prefix}channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 AND c.lang = ? AND (c.title LIKE ? OR c.summary LIKE ?)
            UNION ALL
            SELECT p.id, p.title, p.summary, p.cover, p.updated_at as sort_time, 'product' as _type, 'product' as type,
                    pc.name as channel_name, pc.slug as channel_slug
             FROM {$prefix}products p
             LEFT JOIN {$prefix}product_categories pc ON p.category_id = pc.id
             WHERE p.status = 1 AND p.lang = ? AND (p.title LIKE ? OR p.summary LIKE ?)
            UNION ALL
            SELECT d.id, d.title, d.description as summary, '' as cover, d.created_at as sort_time, 'download' as _type, 'download' as type,
                    dc.name as channel_name, '' as channel_slug
             FROM {$prefix}downloads d
             LEFT JOIN {$prefix}download_categories dc ON d.category_id = dc.id
             WHERE d.status = 1 AND d.lang = ? AND (d.title LIKE ? OR d.description LIKE ?)
            ORDER BY sort_time DESC LIMIT ? OFFSET ?";
}

/** Download-only search normalizes description to the common result-card schema. */
function downloadSearchQuery(string $prefix): string
{
    return "SELECT d.*, d.description AS summary, dc.name AS category_name, 'download' AS _type
            FROM {$prefix}downloads d
            LEFT JOIN {$prefix}download_categories dc ON d.category_id = dc.id
            WHERE d.status = 1 AND d.lang = ? AND (d.title LIKE ? OR d.description LIKE ?)
            ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
}
