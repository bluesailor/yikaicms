<?php
declare(strict_types=1);
require_once __DIR__ . '/SiteTemplateData.php';
require_once __DIR__ . '/SiteAuthoringChecks.php';

/** Read-only editorial checks; no URL fetching and no execution of theme PHP. */
final class SiteContentChecks
{
    public static function run(string $root): array
    {
        $issues = [];
        $scanned = 0;
        $limited = false;
        $documents = 0;
        $drafts = 0;
        $channels = [];
        foreach (['channels', 'contents', 'products'] as $table) {
            $model = match ($table) { 'channels' => channelModel(), 'contents' => contentModel(), default => productModel() };
            $rows = $model->where(['status' => 1], 'id ASC', 1001);
            if (count($rows) > 1000) { $limited = true; array_pop($rows); }
            foreach ($rows as $row) {
                if (!empty($row['deleted_at'])) continue;
                if ($table === 'channels') $channels[(int) $row['id']] = $row;
                $scanned++;
                $label = (string) ($row['title'] ?? $row['name'] ?? $row['id']);
                $url = match ($table) {
                    'channels' => '/admin/channel.php?edit=' . (int) $row['id'],
                    'contents' => '/admin/content_edit.php?id=' . (int) $row['id'],
                    default => '/admin/product_edit.php?id=' . (int) $row['id'],
                };
                // Builder documents are checked below with their own editor link and publication state.
                $values = $row;
                unset($values['blocks_data']);
                if (trim((string) ($row['blocks_data'] ?? '')) !== '') unset($values['content']);
                self::inspectValue($values, $root, $label, $url, $issues);
                if (trim((string) ($row['blocks_data'] ?? '')) !== '') {
                    $builderUrl = $url;
                    if ($table === 'contents' && ($channels[(int) ($row['channel_id'] ?? 0)]['type'] ?? '') === 'page') {
                        $builderUrl = '/admin/blox_editor.php?id=' . (int) $row['channel_id'];
                    }
                    self::inspectDocument((string) $row['blocks_data'], $root, $label, $builderUrl, $issues, $limited);
                    $documents++;
                }
                if ($table === 'channels' && self::looksEmpty($row)) self::add($issues, 'sc_empty', $label, '', $url);
            }
        }
        $settings = settingModel()->getAll();
        foreach ($settings as $key => $value) {
            if (preg_match('/^home_(?:blox|layout)_(?:data|published|history)$/D', $key)) continue;
            if (!SiteTemplateData::settingAllowed($key) || preg_match('/(?:_data|_draft|_history)(?:_|$)/', $key)) continue;
            $url = str_starts_with($key, 'theme_content_') ? '/admin/theme_content.php'
                : (str_starts_with($key, 'contact_') ? '/admin/setting_contact.php' : '/admin/setting_home.php');
            if (str_starts_with($key, 'site_')) $url = '/admin/setting.php?tab=basic';
            self::inspectValue((string) $value, $root, $key, $url, $issues);
        }
        self::inspectLanguages($issues);
        foreach (['home_blox', 'home_layout'] as $prefix) {
            $published = (string) ($settings[$prefix . '_published'] ?? '');
            $draft = (string) ($settings[$prefix . '_data'] ?? '');
            $url = $prefix === 'home_blox' ? '/admin/blox_editor.php?home=1' : '/admin/setting_home.php';
            $label = __($prefix === 'home_blox' ? 'sc_home_builder' : 'sc_home_layout');
            if ($published !== '') {
                self::inspectDocument($published, $root, $label, $url, $issues, $limited);
                $documents++;
            }
            if (SiteAuthoringChecks::hasChanges($draft, $published)) {
                self::add($issues, 'sc_unpublished', $label, '', $url);
                self::inspectDocument($draft, $root, $label . ' — ' . __('sc_draft'), $url, $issues, $limited);
                $drafts++;
            }
        }
        if (db()->tableExists('blox_page_drafts')) {
            $rows = bloxPageDraftModel()->where([], 'id ASC', 1001);
            if (count($rows) > 1000) { $limited = true; array_pop($rows); }
            foreach ($rows as $row) {
                $channel = $channels[(int) $row['page_id']] ?? null;
                if ($channel === null) continue;
                $url = '/admin/blox_editor.php?id=' . (int) $channel['id'];
                $label = (string) $channel['name'];
                $published = (string) ($row['published_data'] ?? '');
                if ($channel['type'] === 'page') {
                    $content = contentModel()->getFirstByChannel((int) $channel['id'], (string) $channel['lang']);
                    $published = (string) ($content['blocks_data'] ?? '');
                } elseif ($published !== '') {
                    self::inspectDocument($published, $root, $label, $url, $issues, $limited);
                    $documents++;
                }
                $draft = (string) ($row['draft_data'] ?? '');
                if (SiteAuthoringChecks::hasChanges($draft, $published)) {
                    self::add($issues, 'sc_unpublished', $label, '', $url);
                    self::inspectDocument($draft, $root, $label . ' — ' . __('sc_draft'), $url, $issues, $limited);
                    $drafts++;
                }
            }
        }
        if (db()->tableExists('blox_templates')) {
            $rows = bloxTemplateModel()->where(['status' => 1], 'id ASC', 1001);
            if (count($rows) > 1000) { $limited = true; array_pop($rows); }
            foreach ($rows as $row) {
                $url = '/admin/blox_editor.php?template=' . (int) $row['id'];
                $label = (string) $row['name'];
                $published = (string) ($row['published_data'] ?? '');
                if ($published !== '') {
                    self::inspectDocument($published, $root, $label, $url, $issues, $limited);
                    $documents++;
                }
                $draft = (string) ($row['draft_data'] ?? '');
                if (SiteAuthoringChecks::hasChanges($draft, $published)) {
                    self::add($issues, 'sc_unpublished', $label, '', $url);
                    self::inspectDocument($draft, $root, $label . ' — ' . __('sc_draft'), $url, $issues, $limited);
                    $drafts++;
                }
            }
        }
        return ['issues' => array_values($issues), 'scanned' => $scanned, 'documents' => $documents, 'drafts' => $drafts,
            'limited' => $limited || count($issues) >= 200];
    }

    private static function inspectDocument(string $json, string $root, string $label, string $url, array &$issues, bool &$limited): void
    {
        if (count($issues) >= 200) { $limited = true; return; }
        $report = SiteAuthoringChecks::inspect($json);
        $limited = $limited || $report['limited'];
        foreach ($report['issues'] as $issue) self::add($issues, $issue['kind'], $label, $issue['detail'], $url);
        if (strlen($json) <= 2000000) self::inspectValue($json, $root, $label, $url, $issues);
    }

    /**
     * 启用了、却没有任何该语言栏目的语言：前台只剩空导航，语言切换器和 hreflang 仍把它当独立版本列出，
     * 搜索引擎会把它当成重复内容。繁体由简体内容转换生成，不算。
     */
    private static function inspectLanguages(array &$issues): void
    {
        $enabled = json_decode((string) config('enabled_languages', ''), true);
        if (!is_array($enabled)) {
            return;
        }
        $default = (string) config('site_lang', 'zh-CN');
        foreach ($enabled as $code) {
            if (!is_string($code) || $code === $default || $code === 'zh-TW' || !LanguageRegistry::has($code)) {
                continue;
            }
            $live = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'channels WHERE lang = ? AND status = 1', [$code]);
            if ($live === 0) {
                self::add($issues, 'sc_lang_untranslated', LanguageRegistry::name($code), '', '/admin/setting_lang.php');
            }
        }
    }

    private static function looksEmpty(array $channel): bool
    {
        $id = (int) $channel['id'];
        if ((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'channels WHERE parent_id = ? AND status = ?', [$id, 1]) > 0) return false;
        if ((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'contents WHERE channel_id = ? AND status = ?', [$id, 1]) > 0) return false;
        if ($channel['type'] !== 'page' && !in_array($channel['type'], ['list', 'case'], true)) return false;
        if (($channel['redirect_type'] ?? '') === 'url' && trim((string) ($channel['redirect_url'] ?? '')) !== '') return false;
        if (db()->tableExists('blox_page_drafts')) {
            $builder = bloxPageDraftModel()->findByPageId($id);
            if (trim((string) ($builder['published_data'] ?? '')) !== '') return false;
        }
        // A builder template or a special contact/timeline renderer may still provide content: report for review, not as a definite failure.
        return trim((string) ($channel['content'] ?? '')) === '';
    }

    private static function inspectValue(mixed $value, string $root, string $label, string $editUrl, array &$issues, int $depth = 0): void
    {
        if (count($issues) >= 200 || $depth > 24) return;
        if (is_array($value)) { foreach ($value as $item) self::inspectValue($item, $root, $label, $editUrl, $issues, $depth + 1); return; }
        if (!is_string($value)) return;
        $json = json_decode($value, true);
        if (is_array($json)) { self::inspectValue($json, $root, $label, $editUrl, $issues, $depth + 1); return; }
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
        $issues[$key] = ['kind' => $kind, 'label' => mb_substr($label, 0, 120), 'detail' => mb_substr($detail, 0, 500), 'url' => $url,
            'level' => in_array($kind, ['sc_missing', 'sc_builder_invalid'], true) ? 'error' : 'review'];
    }
}
