<?php

/**
 * SEO 助手 · 搜索引擎自动推送（专业版）
 *
 * 免费层已有「手动推送全站 URL」；本模块把它变成无人值守：内容有增改就自动
 * ping 百度与 IndexNow（Bing / Yandex），并留下历史与配额记录。
 *
 * 取增量的方式是「游标 + updated_at 比对」，不是在保存处挂钩子：
 * 后台、开放 API、AI 对话改站、批量导入都会写 contents，逐个挂钩子既漏又散；
 * 比对时间戳则天然覆盖所有写入路径，且断点续传、可重跑。
 *
 * 依赖 cron：站点必须配了定时任务（后台「系统 → 定时任务」有说明）。没配
 * crontab 的主机上任务不会自己跑，页面里会明示，此时仍可用「立即推送」手动触发。
 *
 * PHP 8.0+
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib.php';

/** 每次推送的 URL 上限：百度普通收录每日配额有限，别一次打光。 */
const SEO_AUTOPUSH_BATCH = 50;

/** 历史保留条数。 */
const SEO_AUTOPUSH_LOG_MAX = 20;

/** 自动推送是否可用＝专业版 + 已开开关。 */
function seo_autopush_enabled(): bool
{
    if (!function_exists('license_has_module') || !license_has_module('seo-pro')) {
        return false;
    }
    return (string) config('seo_autopush_enabled', '0') === '1';
}

/**
 * 取自游标以来有增改的内容 URL。
 *
 * 只收 status=1 且未软删的行：草稿和回收站里的东西推给搜索引擎只会拿到 404。
 * 用 updated_at / publish_time / created_at 三者取大，兼容老数据里 updated_at
 * 为空的行（早期版本不写这一列）。
 *
 * @return array{0: list<string>, 1: int} [URL 列表, 新游标]
 */
function seo_autopush_changed(int $sinceTs, int $limit = SEO_AUTOPUSH_BATCH): array
{
    $siteUrl = rtrim(siteBaseUrl(), '/');
    $since = date('Y-m-d H:i:s', max(0, $sinceTs));
    $urls = [];
    $newest = $sinceTs;

    try {
        $rows = db()->fetchAll(
            'SELECT c.id, c.slug, c.type, c.channel_id, c.updated_at, c.created_at, c.publish_time,
                    ch.slug AS channel_slug, ch.type AS channel_type
             FROM ' . DB_PREFIX . 'contents c
             LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 AND c.deleted_at IS NULL
               AND (c.updated_at > ? OR c.created_at > ? OR c.publish_time > ?)
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT ' . (int) $limit,
            [$since, $since, $since]
        );
    } catch (\Throwable $e) {
        return [[], $sinceTs];
    }

    foreach ($rows as $row) {
        $stamps = array_filter([
            strtotime((string) ($row['updated_at'] ?? '')) ?: 0,
            strtotime((string) ($row['created_at'] ?? '')) ?: 0,
            strtotime((string) ($row['publish_time'] ?? '')) ?: 0,
        ]);
        $newest = max($newest, $stamps ? max($stamps) : 0);
        try {
            $urls[] = $siteUrl . contentUrl($row);
        } catch (\Throwable $e) {
            // 单条取 URL 失败不该拖垮整批
        }
    }

    return [array_values(array_unique($urls)), $newest];
}

/** @return array<int, array<string, mixed>> 最近的推送历史（新的在前） */
function seo_autopush_log(): array
{
    $raw = (string) config('seo_autopush_log', '');
    if ($raw === '') {
        return [];
    }
    $list = json_decode($raw, true);
    return is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
}

/** 追加一条历史（超出上限丢弃最旧的）。 */
function seo_autopush_log_add(array $entry): void
{
    $log = seo_autopush_log();
    array_unshift($log, $entry);
    $log = array_slice($log, 0, SEO_AUTOPUSH_LOG_MAX);
    settingModel()->set('seo_autopush_log', json_encode($log, JSON_UNESCAPED_UNICODE), 'system');
}

/**
 * 跑一次推送。cron 与「立即推送」共用。
 *
 * @param bool $manual 手动触发时忽略「自动推送开关」，只认专业版
 * @return string 给 cron 日志/页面看的一句话
 */
function seo_autopush_run(bool $manual = false): string
{
    if (!function_exists('license_has_module') || !license_has_module('seo-pro')) {
        return 'skipped: 需要专业版';
    }
    if (!$manual && !seo_autopush_enabled()) {
        return 'skipped: 自动推送未开启';
    }

    $cursor = (int) config('seo_autopush_cursor', '0');
    if ($cursor <= 0) {
        // 首次运行不把全站历史内容一次性推出去（会瞬间打光百度配额）：
        // 以「此刻」为起点，只推之后的增改。要全量推送用免费层的手动推送。
        settingModel()->set('seo_autopush_cursor', (string) time(), 'system');
        return 'initialized: 已建立增量游标，之后的内容变更将自动推送';
    }

    [$urls, $newest] = seo_autopush_changed($cursor);
    if (!$urls) {
        return 'no changes';
    }

    $site = (string) config('seo_baidu_site', '');
    $token = (string) config('seo_baidu_token', '');
    $key = (string) config('seo_indexnow_key', '');
    $host = rtrim(preg_replace('#^https?://#', '', rtrim(siteBaseUrl(), '/')) ?? '', '/');

    $parts = [];
    $anyOk = false;

    if ($site !== '' && $token !== '') {
        [$ok, $msg] = seo_submit_baidu($site, $token, $urls);
        $anyOk = $anyOk || $ok;
        $parts[] = $msg;
    }
    if ($key !== '') {
        [$ok, $msg] = seo_submit_indexnow($host, $key, $urls);
        $anyOk = $anyOk || $ok;
        $parts[] = $msg;
    }
    if (!$parts) {
        return 'skipped: 未配置百度 token 或 IndexNow 密钥';
    }

    // 只有真的推出去了才推进游标——否则这批内容下次还要重试，
    // 不能因为一次网络抖动就让它们永远漏掉。
    if ($anyOk) {
        settingModel()->set('seo_autopush_cursor', (string) max($newest, $cursor), 'system');
    }

    seo_autopush_log_add([
        'time' => date('Y-m-d H:i:s'),
        'count' => count($urls),
        'ok' => $anyOk,
        'manual' => $manual,
        'msg' => mb_substr(implode('；', $parts), 0, 300),
    ]);

    return ($anyOk ? 'pushed ' : 'failed ') . count($urls) . ' urls: ' . implode('；', $parts);
}

/**
 * 注册 cron 任务。挂 core 的 `cron_register` action。
 *
 * 间隔 15 分钟：搜索引擎收录本身有延迟，更密只会烧配额。任务体内部再判专业版
 * 与开关，非 Pro 站点是一次早退，几乎无开销。
 */
function seo_autopush_register(): void
{
    if (!class_exists('Cron')) {
        return;
    }
    Cron::register('seo_autopush', 'SEO 自动推送', 900, static function (): string {
        return seo_autopush_run(false);
    });
}
