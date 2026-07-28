<?php
/**
 * SEO 工坊 - 重定向管理器（专业版）
 *
 * 301/302 重定向规则 + 404 监控。前台在 init 钩子匹配当前路径命中即跳转；
 * render_404 钩子记录未命中路径，供管理员一键建重定向修复死链。
 * 全部 license_has_module('seo-pro') 闸控；表按驱动(MySQL/SQLite)惰性创建。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** Pro 闸。 */
function seo_is_pro(): bool
{
    return function_exists('license_has_module') && license_has_module('seo-pro');
}

// 全名（含前缀）——供 fetchAll/fetchOne/execute 等原始 SQL 使用。
// 注意：insert/update/delete/tableExists 会自动加 DB_PREFIX，须传裸名 seo_redirects / seo_404log。
function seo_redirect_table(): string
{
    return DB_PREFIX . 'seo_redirects';
}
function seo_404_table(): string
{
    return DB_PREFIX . 'seo_404log';
}

/** 惰性建表（幂等，驱动感知）。仅后台 Pro 界面调用，前台只查不建。 */
function seo_redirect_ensure_tables(): void
{
    $rt = seo_redirect_table();
    $lt = seo_404_table();
    if (db()->isSqlite()) {
        db()->execute('CREATE TABLE IF NOT EXISTS "' . $rt . '" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "source" TEXT NOT NULL UNIQUE,
            "target" TEXT NOT NULL,
            "type" INTEGER NOT NULL DEFAULT 301,
            "hits" INTEGER NOT NULL DEFAULT 0,
            "created_at" INTEGER NOT NULL DEFAULT 0,
            "updated_at" INTEGER NOT NULL DEFAULT 0
        )');
        db()->execute('CREATE TABLE IF NOT EXISTS "' . $lt . '" (
            "id" INTEGER PRIMARY KEY AUTOINCREMENT,
            "path" TEXT NOT NULL UNIQUE,
            "referer" TEXT DEFAULT \'\',
            "hits" INTEGER NOT NULL DEFAULT 0,
            "last_seen" INTEGER NOT NULL DEFAULT 0
        )');
    } else {
        db()->execute('CREATE TABLE IF NOT EXISTS `' . $rt . '` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `source` varchar(191) NOT NULL,
            `target` varchar(1000) NOT NULL,
            `type` smallint(6) NOT NULL DEFAULT 301,
            `hits` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_source` (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        db()->execute('CREATE TABLE IF NOT EXISTS `' . $lt . '` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `path` varchar(191) NOT NULL,
            `referer` varchar(500) DEFAULT \'\',
            `hits` int(11) UNSIGNED NOT NULL DEFAULT 0,
            `last_seen` int(11) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_path` (`path`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}

/** 规范化路径：去查询串、去尾部斜杠（根除外）、限长。 */
function seo_norm_path(string $uri): string
{
    $p = (string) parse_url($uri, PHP_URL_PATH);
    if ($p === '') {
        return '';
    }
    if ($p !== '/' && str_ends_with($p, '/')) {
        $p = rtrim($p, '/');
    }
    return mb_substr($p, 0, 191);
}

// ============================================================
// 前台：匹配跳转 + 404 记录（挂 init / render_404）
// ============================================================

/** init 钩子：当前路径命中重定向规则则 301/302 跳转并 exit。 */
function seo_redirect_apply(): void
{
    if (!seo_is_pro()) {
        return;
    }
    $path = seo_norm_path($_SERVER['REQUEST_URI'] ?? '/');
    if ($path === '' || $path === '/') {
        return;
    }
    try {
        $row = db()->fetchOne('SELECT * FROM ' . seo_redirect_table() . ' WHERE source = ?', [$path]);
    } catch (\Throwable $e) {
        return; // 表未建 / 查询异常 → 放行
    }
    if (!$row) {
        return;
    }
    $target = (string) $row['target'];
    // 防自跳与空目标
    if ($target === '' || seo_norm_path($target) === $path) {
        return;
    }
    try {
        db()->execute('UPDATE ' . seo_redirect_table() . ' SET hits = hits + 1 WHERE id = ?', [(int) $row['id']]);
    } catch (\Throwable $e) {
    }
    $code = (int) $row['type'] === 302 ? 302 : 301;
    if (!headers_sent()) {
        header('Location: ' . $target, true, $code);
    }
    exit;
}

/** render_404 钩子：记录未命中路径（upsert 计数）。 */
function seo_redirect_log404(string $path): void
{
    if (!seo_is_pro()) {
        return;
    }
    $path = seo_norm_path($path);
    if ($path === '' || $path === '/') {
        return;
    }
    $referer = mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500);
    $now = time();
    try {
        $n = db()->execute(
            'UPDATE ' . seo_404_table() . ' SET hits = hits + 1, last_seen = ?, referer = ? WHERE path = ?',
            [$now, $referer, $path]
        );
        if ($n === 0) {
            db()->insert('seo_404log', [
                'path' => $path, 'referer' => $referer, 'hits' => 1, 'last_seen' => $now,
            ]);
        }
    } catch (\Throwable $e) {
        // 表未建：静默（管理员进 Pro 界面即建表，之后才开始记录）
    }
}

// ============================================================
// 后台 CRUD（供 admin.php 调用；均假定已在 Pro 界面、表已建）
// ============================================================

/** @return array<int,array> */
function seo_redirect_list(int $limit = 500): array
{
    return db()->fetchAll('SELECT * FROM ' . seo_redirect_table() . ' ORDER BY id DESC LIMIT ' . (int) $limit);
}

/** @return array{0:bool,1:string} */
function seo_redirect_add(string $source, string $target, int $type): array
{
    $source = seo_norm_path($source);
    $target = trim($target);
    if ($source === '' || $source === '/') {
        return [false, '来源路径无效（示例：/old-page.html）'];
    }
    if ($target === '') {
        return [false, '目标不能为空'];
    }
    if (seo_norm_path($target) === $source) {
        return [false, '来源与目标相同，会造成死循环'];
    }
    $type = $type === 302 ? 302 : 301;
    $now = time();
    $exists = db()->fetchOne('SELECT id FROM ' . seo_redirect_table() . ' WHERE source = ?', [$source]);
    if ($exists) {
        db()->update('seo_redirects', ['target' => $target, 'type' => $type, 'updated_at' => $now], 'id = ?', [(int) $exists['id']]);
        return [true, '已更新规则'];
    }
    db()->insert('seo_redirects', [
        'source' => $source, 'target' => $target, 'type' => $type,
        'hits' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    return [true, '已添加规则'];
}

function seo_redirect_delete(int $id): void
{
    db()->delete('seo_redirects', 'id = ?', [$id]);
}

/** @return array<int,array> */
function seo_404_list(int $limit = 200): array
{
    return db()->fetchAll('SELECT * FROM ' . seo_404_table() . ' ORDER BY hits DESC, last_seen DESC LIMIT ' . (int) $limit);
}

function seo_404_delete(int $id): void
{
    db()->delete('seo_404log', 'id = ?', [$id]);
}

function seo_404_clear(): void
{
    db()->execute('DELETE FROM ' . seo_404_table());
}
