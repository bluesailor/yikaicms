<?php
/**
 * SEO 助手 - URL 别名管理
 *
 * 为什么在 SEO 插件里：改别名必须配 301，而重定向管理就在本插件（专业版）；
 * 失效链接检查也在这里——伪静态模式下非法别名就是死链的一种，同域归拢。
 *
 * 分层（2026-09-21 定价裁决）：
 *   免费   —— 列表 + 单条改名（站长随时能把 URL 改成自己想要的）。
 *   专业版 —— 批量规范化 + 改名自动写 301（省事与不断链属增值）。
 *
 * 净化规则**不在本文件实现**：一律走核心的 normalizeSlugInput()（includes/Slug.php），
 * 插件再写一套必然与核心漂移——客户站的中文 URL 事故正是"规则多处各自为政"造成的。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once ROOT_PATH . '/includes/Slug.php';

/**
 * 可管理的别名表白名单。键是**裸表名**（db() 的 insert/update 会自动加 DB_PREFIX），
 * 值给出标题列与该类记录的前台地址构造方式。
 * 白名单同时是安全边界：表名来自请求，绝不能直接拼进 SQL。
 *
 * @return array<string,array{label_column:string,kind:string}>
 */
function seo_slug_tables(): array
{
    return [
        'channels' => ['label_column' => 'name', 'kind' => 'channel'],
        'contents' => ['label_column' => 'title', 'kind' => 'content'],
        'products' => ['label_column' => 'title', 'kind' => 'product'],
        'product_categories' => ['label_column' => 'name', 'kind' => 'product_category'],
    ];
}

/** 合法别名：伪静态路由（Dispatcher）只认这个字符集，其余一律 404。 */
function seo_slug_is_valid(string $slug): bool
{
    return $slug !== '' && preg_match('/^[a-z0-9\-]+$/', $slug) === 1;
}

/**
 * 扫描别名清单。
 *
 * @param string $filter all|invalid
 * @return array{rows:list<array<string,mixed>>,total:int,invalid:int,pretty:bool}
 */
function seo_slug_scan(string $filter = 'all', int $limit = 500): array
{
    $rows = [];
    $invalid = 0;
    $total = 0;
    foreach (seo_slug_tables() as $table => $meta) {
        if (!db()->tableExists($table)) {
            continue;
        }
        $records = db()->fetchAll('SELECT id, slug, ' . $meta['label_column'] . ' AS label FROM '
            . DB_PREFIX . $table . ' WHERE slug <> \'\' ORDER BY id ASC LIMIT ' . (int) $limit);
        foreach ($records as $record) {
            $slug = (string) ($record['slug'] ?? '');
            $ok = seo_slug_is_valid($slug);
            $total++;
            if (!$ok) {
                $invalid++;
            }
            if ($filter === 'invalid' && $ok) {
                continue;
            }
            $rows[] = [
                'table' => $table,
                'kind' => $meta['kind'],
                'id' => (int) ($record['id'] ?? 0),
                'label' => (string) ($record['label'] ?? ''),
                'slug' => $slug,
                'valid' => $ok,
                // 非法别名的建议值：先按别名本身转写（保留站长命名），再退回标题
                'suggested' => $ok ? '' : seo_slug_suggest($table, (int) $record['id'], $slug, (string) ($record['label'] ?? '')),
            ];
        }
    }
    return [
        'rows' => $rows,
        'total' => $total,
        'invalid' => $invalid,
        // 伪静态下非法别名 = 404 死链；动态 URL 下只是不好看
        'pretty' => !function_exists('isDynamicUrlMode') || !isDynamicUrlMode(),
    ];
}

/** 建议别名：别名转写优先，退回标题，再退回“表名-id”；同表重名加 id 后缀（复跑稳定）。 */
function seo_slug_suggest(string $table, int $id, string $slug, string $label): string
{
    $candidate = normalizeSlugInput($slug);
    if ($candidate === '') {
        $candidate = generateSlug($label);
    }
    if ($candidate === '') {
        $candidate = $table . '-' . $id;
    }
    if (seo_slug_taken($table, $candidate, $id)) {
        $candidate .= '-' . $id;
    }
    return $candidate;
}

/** 同表内别名是否已被别的记录占用（沿用核心 resolveSlug 的全表唯一口径）。 */
function seo_slug_taken(string $table, string $slug, int $excludeId): bool
{
    if (!isset(seo_slug_tables()[$table])) {
        return true;
    }
    return db()->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE slug = ? AND id <> ?',
        [$slug, $excludeId]) !== null;
}

/**
 * 改写单条别名。
 *
 * @param bool $withRedirect 是否为旧地址写 301（专业版能力，调用方先判授权）
 * @return array{0:bool,1:string,2:string} [成功, 提示, 实际落库的别名]
 */
function seo_slug_rename(string $table, int $id, string $newSlug, bool $withRedirect): array
{
    $tables = seo_slug_tables();
    if (!isset($tables[$table]) || $id <= 0) {
        return [false, __('seo_slug_bad_target'), ''];
    }
    // 站长手输同样过核心净化：这里放行中文就等于把事故搬进插件
    $clean = normalizeSlugInput($newSlug);
    if (!seo_slug_is_valid($clean)) {
        return [false, __('seo_slug_bad_value'), ''];
    }
    $row = db()->fetchOne('SELECT id, slug FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]);
    if (!$row) {
        return [false, __('seo_slug_bad_target'), ''];
    }
    $old = (string) ($row['slug'] ?? '');
    if ($old === $clean) {
        return [true, __('seo_slug_unchanged'), $clean];
    }
    if (seo_slug_taken($table, $clean, $id)) {
        return [false, __('seo_slug_taken'), ''];
    }

    db()->update($table, ['slug' => $clean], 'id = ?', [$id]);
    if ($withRedirect && $table === 'channels' && function_exists('seo_redirect_add') && $old !== '') {
        // 栏目是顶级 /xxx.html，旧地址最可能被外部引用；其余类型路径依赖栏目层级，
        // 交由失效链接检查兜底，不在这里猜地址（猜错的 301 比没有更糟）
        seo_redirect_add('/' . rawurlencode($old) . '.html', '/' . $clean . '.html', 301);
    }
    if (function_exists('do_action')) {
        do_action('data_changed', $table, $id);
    }
    return [true, __('seo_slug_renamed'), $clean];
}

/**
 * 批量规范化所有非法别名（专业版）。
 *
 * @return array{fixed:int,failed:int,items:list<array{label:string,from:string,to:string}>}
 */
function seo_slug_normalize_all(bool $withRedirect, int $limit = 500): array
{
    $fixed = 0;
    $failed = 0;
    $items = [];
    foreach (seo_slug_scan('invalid', $limit)['rows'] as $row) {
        [$ok, , $applied] = seo_slug_rename((string) $row['table'], (int) $row['id'],
            (string) $row['suggested'], $withRedirect);
        if ($ok && $applied !== '') {
            $fixed++;
            $items[] = ['label' => (string) $row['label'], 'from' => (string) $row['slug'], 'to' => $applied];
        } else {
            $failed++;
        }
    }
    return ['fixed' => $fixed, 'failed' => $failed, 'items' => $items];
}
