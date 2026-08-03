<?php
declare(strict_types=1);

/**
 * Per-admin backend menu usage tracking for dashboard recent links.
 */

/** @psalm-suppress ParadoxicalCondition 直接访问该文件时不经过 Psalm 分析到的后台入口。 */
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

function adminMenuUsageEnsureTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $table = DB_PREFIX . 'admin_menu_usage';
    if (db()->isSqlite()) {
        db()->execute("CREATE TABLE IF NOT EXISTS {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            url TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            icon TEXT NOT NULL DEFAULT '',
            used_count INTEGER NOT NULL DEFAULT 0,
            last_used_at TEXT NOT NULL,
            UNIQUE(admin_id, url)
        )");
        db()->execute("CREATE INDEX IF NOT EXISTS idx_admin_menu_usage_admin_last ON {$table} (admin_id, last_used_at)");
    } else {
        db()->execute("CREATE TABLE IF NOT EXISTS {$table} (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id INT UNSIGNED NOT NULL,
            url VARCHAR(255) NOT NULL,
            title VARCHAR(120) NOT NULL DEFAULT '',
            icon VARCHAR(80) NOT NULL DEFAULT '',
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_admin_url (admin_id, url),
            KEY idx_admin_last (admin_id, last_used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    $done = true;
}

function adminMenuUsageNormalizeUrl(string $url): string
{
    $parts = parse_url($url);
    $path = (string) ($parts['path'] ?? $url);
    $query = (string) ($parts['query'] ?? '');

    return $query !== '' ? $path . '?' . $query : $path;
}

function adminMenuUsageFindItem(array $sidebarMenu, string $requestUri): ?array
{
    $current = adminMenuUsageNormalizeUrl($requestUri);
    $currentPath = (string) (parse_url($current, PHP_URL_PATH) ?: $current);

    foreach ($sidebarMenu as $group) {
        if (!empty($group['super_only']) && !isSuperAdmin()) {
            continue;
        }

        foreach ((array) ($group['items'] ?? []) as $item) {
            if (isset($item['visible']) && !$item['visible']) {
                continue;
            }
            if (!empty($item['perm']) && !hasPermission((string) $item['perm'])) {
                continue;
            }

            $url = adminMenuUsageNormalizeUrl((string) ($item['url'] ?? ''));
            if ($url === '' || $url === '/admin/' || $url === '/admin/index.php') {
                continue;
            }

            $itemPath = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
            if ($current === $url || ($itemPath !== '' && $itemPath === $currentPath)) {
                return [
                    'url' => $url,
                    'title' => trim(strip_tags((string) ($item['label'] ?? ''))),
                    'icon' => (string) ($item['tabler_icon'] ?? ''),
                ];
            }
        }
    }

    return null;
}

function adminMenuUsageRecord(array $sidebarMenu, int $adminId, string $requestUri): void
{
    if ($adminId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $item = adminMenuUsageFindItem($sidebarMenu, $requestUri);
    if ($item === null) {
        return;
    }

    adminMenuUsageEnsureTable();
    $table = DB_PREFIX . 'admin_menu_usage';
    $now = date('Y-m-d H:i:s');
    $id = db()->fetchColumn("SELECT id FROM {$table} WHERE admin_id = ? AND url = ?", [$adminId, $item['url']]);

    if ($id) {
        db()->execute(
            "UPDATE {$table} SET title = ?, icon = ?, used_count = used_count + 1, last_used_at = ? WHERE id = ?",
            [$item['title'], $item['icon'], $now, (int) $id]
        );
        return;
    }

    db()->execute(
        "INSERT INTO {$table} (admin_id, url, title, icon, used_count, last_used_at) VALUES (?, ?, ?, ?, 1, ?)",
        [$adminId, $item['url'], $item['title'], $item['icon'], $now]
    );
}

function adminMenuUsageRecent(int $adminId, int $limit = 8): array
{
    if ($adminId <= 0) {
        return [];
    }

    adminMenuUsageEnsureTable();
    $limit = max(1, min(20, $limit));
    $table = DB_PREFIX . 'admin_menu_usage';

    return db()->fetchAll(
        "SELECT url, title, icon, used_count, last_used_at FROM {$table} WHERE admin_id = ? ORDER BY last_used_at DESC LIMIT {$limit}",
        [$adminId]
    );
}
