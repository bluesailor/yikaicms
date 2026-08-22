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

/** @return array{ts: int, id: int} */
function seo_autopush_cursor_decode(mixed $raw): array
{
    if (is_int($raw) || (is_string($raw) && preg_match('/^[0-9]+$/D', $raw) === 1)) {
        return ['ts' => max(0, (int) $raw), 'id' => 0];
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($decoded)) {
        return ['ts' => 0, 'id' => 0];
    }
    return [
        'ts' => max(0, (int) ($decoded['ts'] ?? 0)),
        'id' => max(0, (int) ($decoded['id'] ?? 0)),
    ];
}

/** @param array{ts: int, id: int} $cursor */
function seo_autopush_cursor_encode(array $cursor): string
{
    return json_encode($cursor, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/** @param array{ts: int, id: int} $a @param array{ts: int, id: int} $b */
function seo_autopush_cursor_after(array $a, array $b): bool
{
    return $a['ts'] > $b['ts'] || ($a['ts'] === $b['ts'] && $a['id'] > $b['id']);
}

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
 * **按最旧优先排序**：原先按最新排、再把游标推到批次最大时间，积压超过单批上限时
 * 较旧的那些会被永久跳过（codex 审计 P1-3）。从旧往新推，游标才能单调走完积压。
 *
 * @param int|array{ts: int, id: int} $cursor
 * @return array{0: list<array<string, mixed>>, 1: array{ts: int, id: int}} [内容行, 新游标]
 */
function seo_autopush_rows(int|array $cursor, int $limit = SEO_AUTOPUSH_BATCH): array
{
    $cursor = seo_autopush_cursor_decode($cursor);
    $since = date('Y-m-d H:i:s', $cursor['ts']);
    $nextCursor = $cursor;

    // CASE 只用 MySQL 5.7 / SQLite 共有语法。三个字段先归一空值，再取字典序最大值；
    // Y-m-d H:i:s 可安全按字符串排序，避免 COALESCE 误把较旧 updated_at 当有效时间。
    $epoch = "'1970-01-01 00:00:00'";
    $updated = "COALESCE(NULLIF(c.updated_at, ''), {$epoch})";
    $created = "COALESCE(NULLIF(c.created_at, ''), {$epoch})";
    $published = "COALESCE(NULLIF(c.publish_time, ''), {$epoch})";
    $effective = "CASE WHEN {$updated} >= {$created} AND {$updated} >= {$published} THEN {$updated} "
        . "WHEN {$created} >= {$published} THEN {$created} ELSE {$published} END";

    try {
        $rows = db()->fetchAll(
            'SELECT c.id, c.slug, c.type, c.channel_id, c.updated_at, c.created_at, c.publish_time,
                    ch.slug AS channel_slug, ch.type AS channel_type, ' . $effective . ' AS effective_at
             FROM ' . DB_PREFIX . 'contents c
             LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 AND c.deleted_at IS NULL
               AND ((' . $effective . ') > ? OR ((' . $effective . ') = ? AND c.id > ?))
             ORDER BY effective_at ASC, c.id ASC
             LIMIT ' . (int) $limit,
            [$since, $since, $cursor['id']]
        );
    } catch (\Throwable $e) {
        return [[], $cursor];
    }

    foreach ($rows as $row) {
        $nextCursor = [
            'ts' => strtotime((string) ($row['effective_at'] ?? '')) ?: $cursor['ts'],
            'id' => (int) ($row['id'] ?? 0),
        ];
    }

    return [$rows, $nextCursor];
}

/**
 * @param int|array{ts: int, id: int} $cursor
 * @return array{0: list<string>, 1: array{ts: int, id: int}} [URL 列表, 新游标]
 */
function seo_autopush_changed(int|array $cursor, int $limit = SEO_AUTOPUSH_BATCH): array
{
    [$rows, $nextCursor] = seo_autopush_rows($cursor, $limit);
    $siteUrl = rtrim(siteBaseUrl(), '/');
    $urls = [];
    foreach ($rows as $row) {
        try {
            $urls[] = $siteUrl . contentUrl($row);
        } catch (\Throwable $e) {
            // 单条取 URL 失败不该拖垮整批
        }
    }
    return [array_values(array_unique($urls)), $nextCursor];
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

    // 两个服务各自记游标：原先共用一个，只要有一个成功就推进，失败那个的这批 URL
    // 再也不会重试（codex 审计 P1-3）。分开之后各推各的，互不影响。
    $site = (string) config('seo_baidu_site', '');
    $token = (string) config('seo_baidu_token', '');
    $key = (string) config('seo_indexnow_key', '');
    $host = rtrim(preg_replace('#^https?://#', '', rtrim(siteBaseUrl(), '/')) ?? '', '/');

    $services = [];
    if ($site !== '' && $token !== '') {
        $services['baidu'] = static fn(array $urls): array => seo_submit_baidu($site, $token, $urls);
    }
    if ($key !== '') {
        $services['indexnow'] = static fn(array $urls): array => seo_submit_indexnow($host, $key, $urls);
    }
    if (!$services) {
        return 'skipped: 未配置百度 token 或 IndexNow 密钥';
    }

    $parts = [];
    $anyPushed = false;
    $anyOk = false;
    foreach ($services as $name => $submit) {
        $ck = 'seo_autopush_cursor_' . $name;
        $cursor = seo_autopush_cursor_decode(config($ck, '0'));
        if ($cursor['ts'] <= 0) {
            // 首次不把全站历史内容一次推出去（会瞬间打光百度配额）：以此刻为起点。
            // 要全量推送用免费层的手动推送。
            settingModel()->set($ck, seo_autopush_cursor_encode(['ts' => time(), 'id' => 0]), 'system');
            $parts[] = $name . '：已建立增量游标';
            continue;
        }

        [$urls, $newest] = seo_autopush_changed($cursor);
        if (!$urls) {
            // 行存在但 URL 生成失败时也要越过坏数据，否则每次 cron 都卡在同一行。
            if (seo_autopush_cursor_after($newest, $cursor)) {
                settingModel()->set($ck, seo_autopush_cursor_encode($newest), 'system');
            }
            continue;
        }
        $anyPushed = true;
        [$ok, $msg] = $submit($urls);
        $parts[] = $msg;
        // 只有真的推出去了才推进该服务自己的游标——网络抖动不能让这批永久漏掉
        if ($ok) {
            $anyOk = true;
            settingModel()->set($ck, seo_autopush_cursor_encode($newest), 'system');
        }
    }

    if (!$anyPushed) {
        return $parts ? implode('；', $parts) : 'no changes';
    }

    seo_autopush_log_add([
        'time' => date('Y-m-d H:i:s'),
        'count' => count($urls ?? []),
        'ok' => $anyOk,
        'manual' => $manual,
        'msg' => mb_substr(implode('；', $parts), 0, 300),
    ]);

    return ($anyOk ? 'pushed: ' : 'failed: ') . implode('；', $parts);
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
