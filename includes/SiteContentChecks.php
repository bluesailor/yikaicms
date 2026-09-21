<?php
declare(strict_types=1);
require_once __DIR__ . '/SiteTemplateData.php';

/** Read-only editorial checks; no URL fetching and no execution of theme PHP. */
final class SiteContentChecks
{
    public static function run(string $root): array
    {
        $issues = [];
        $scanned = 0;
        $limited = false;
        foreach (['channels', 'contents', 'products'] as $table) {
            $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE status = ? ORDER BY id LIMIT 1001', [1]);
            if (count($rows) > 1000) { $limited = true; array_pop($rows); }
            foreach ($rows as $row) {
                if (!empty($row['deleted_at'])) continue;
                $scanned++;
                $label = (string) ($row['title'] ?? $row['name'] ?? $row['id']);
                $url = match ($table) {
                    'channels' => '/admin/channel.php?edit=' . (int) $row['id'],
                    'contents' => '/admin/content_edit.php?id=' . (int) $row['id'],
                    default => '/admin/product_edit.php?id=' . (int) $row['id'],
                };
                self::inspectValue($row, $root, $label, $url, $issues);
                if ($table === 'channels' && self::looksEmpty($row)) self::add($issues, 'sc_empty', $label, '', $url);
            }
        }
        foreach (settingModel()->getAll() as $key => $value) {
            if (!SiteTemplateData::settingAllowed($key) || preg_match('/(?:_data|_draft|_history)(?:_|$)/', $key)) continue;
            $url = str_starts_with($key, 'theme_content_') ? '/admin/theme_content.php'
                : (str_starts_with($key, 'contact_') ? '/admin/setting_contact.php' : '/admin/setting_home.php');
            if (str_starts_with($key, 'site_')) $url = '/admin/setting.php?tab=basic';
            self::inspectValue((string) $value, $root, $key, $url, $issues);
        }
        return ['issues' => array_values($issues), 'scanned' => $scanned, 'limited' => $limited || count($issues) >= 200];
    }

    private static function looksEmpty(array $channel): bool
    {
        $id = (int) $channel['id'];
        if ((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'channels WHERE parent_id = ? AND status = ?', [$id, 1]) > 0) return false;
        if ((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? AND status = ?', [$id, 1]) > 0) return false;
        if ($channel['type'] !== 'page' && !in_array($channel['type'], ['list', 'case'], true)) return false;
        if (($channel['redirect_type'] ?? '') === 'url' && trim((string) ($channel['redirect_url'] ?? '')) !== '') return false;
        // A builder template or a special contact/timeline renderer may still provide content: report for review, not as a definite failure.
        return trim((string) ($channel['content'] ?? '')) === '';
    }

    private static function inspectValue(mixed $value, string $root, string $label, string $editUrl, array &$issues): void
    {
        if (count($issues) >= 200) return;
        if (is_array($value)) { foreach ($value as $item) self::inspectValue($item, $root, $label, $editUrl, $issues); return; }
        if (!is_string($value)) return;
        $json = json_decode($value, true);
        if (is_array($json)) { self::inspectValue($json, $root, $label, $editUrl, $issues); return; }
        if (preg_match('/示例公司|演示公司|请替换|Lorem ipsum|Your Company|example\.com/i', $value)) self::add($issues, 'sc_demo', $label, '', $editUrl);
        $origin = rtrim((string) config('site_url', ''), '/');
        if ($origin !== '') $value = str_replace($origin . '/', '/', $value);
        preg_match_all('~(?<![a-zA-Z0-9/:.])/(?:uploads|themes|assets)/[^\s"\'<>?#),]+~u', html_entity_decode($value, ENT_QUOTES, 'UTF-8'), $matches);
        $rootReal = realpath($root);
        if ($rootReal === false) return;
        $rootPrefix = strtolower(str_replace('\\', '/', $rootReal)) . '/';
        foreach (array_unique($matches[0]) as $url) {
            $path = rawurldecode($url);
            if (str_contains($path, '\\') || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f:]~', $path)) {
                self::add($issues, 'sc_missing', $label, $url, $editUrl); continue;
            }
            $file = $rootReal . $path;
            $real = realpath($file);
            if ($real === false || !str_starts_with(strtolower(str_replace('\\', '/', $real)), $rootPrefix) || !is_file($file)) self::add($issues, 'sc_missing', $label, $url, $editUrl);
        }
    }

    private static function add(array &$issues, string $kind, string $label, string $detail, string $url): void
    {
        if (count($issues) >= 200) return;
        $key = hash('sha256', $kind . $url . $label . $detail);
        $issues[$key] = ['kind' => $kind, 'label' => mb_substr($label, 0, 120), 'detail' => mb_substr($detail, 0, 500), 'url' => $url];
    }
}
