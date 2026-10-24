<?php
/** Clean published-page preview requested by an explicit query parameter. */

declare(strict_types=1);

function isCleanFrontendPreview(): bool
{
    return array_key_exists('preview', $_GET);
}

/** Only the authenticated admin entry point may supply a single draft row. */
function adminDraftPreviewRow(string $table, int $id = 0): ?array
{
    if (!defined('YK_ADMIN_DRAFT_PREVIEW') || empty($_SESSION['admin_id'])) return null;
    $context = YK_ADMIN_DRAFT_PREVIEW;
    if (!is_array($context) || ($context['table'] ?? '') !== $table) return null;
    $row = $context['row'] ?? null;
    return is_array($row) && ($id === 0 || (int) $row['id'] === $id) ? $row : null;
}

/** Resolve list navigation in the content language, including private draft lists. */
function contentListUrl(?array $channel, string $fallback = '/news.html'): string
{
    if (!$channel) return langUrl($fallback, siteLang());
    if (($channel['type'] ?? '') === 'link') return channelUrl($channel);
    if ((string) ($channel['lang'] ?? siteLang()) !== siteLang()) {
        $group = (int) ($channel['translation_group_id'] ?: $channel['id']);
        $translated = channelModel()->findWhere(['translation_group_id' => $group, 'lang' => siteLang()]);
        if ($translated && ((int) $translated['status'] === 1 || defined('YK_ADMIN_DRAFT_PREVIEW'))) $channel = $translated;
    }
    if (defined('YK_ADMIN_DRAFT_PREVIEW') && (int) $channel['status'] !== 1) {
        return '/admin/draft_preview.php?type=channels&id=' . (int) $channel['id'];
    }
    return class_exists('LocalizedUrl') ? LocalizedUrl::urlFor('channel', $channel, displayLang()) : channelUrl($channel);
}

/** Related channel metadata may use its draft translation only inside admin previews. */
function adminPreviewLocalizedChannel(?array $row): ?array
{
    if (!$row || !defined('YK_ADMIN_DRAFT_PREVIEW') || empty($_SESSION['admin_id']) || ($row['lang'] ?? siteLang()) === siteLang()) return $row;
    return channelModel()->findWhere([
        'translation_group_id' => (int) (($row['translation_group_id'] ?? 0) ?: $row['id']),
        'lang' => siteLang(),
    ]) ?: $row;
}

function adminDraftPreviewLayoutChannel(int $id): ?array
{
    if (!defined('YK_ADMIN_DRAFT_PREVIEW') || empty($_SESSION['admin_id'])) return null;
    $row = YK_ADMIN_DRAFT_PREVIEW['channel'] ?? null;
    return is_array($row) && (int) $row['id'] === $id ? $row : null;
}

function draftPreviewEntityUrl(string $table, array $row, string $publicUrl): string
{
    return defined('YK_ADMIN_DRAFT_PREVIEW') && (int) ($row['status'] ?? 1) !== 1
        ? '/admin/draft_preview.php?type=' . rawurlencode($table) . '&id=' . (int) $row['id']
        : $publicUrl;
}

function adminDraftPreviewQuery(): array
{
    if (!defined('YK_ADMIN_DRAFT_PREVIEW') || empty($_SESSION['admin_id'])) return [];
    return ['type' => (string) YK_ADMIN_DRAFT_PREVIEW['table'], 'id' => (string) YK_ADMIN_DRAFT_PREVIEW['row']['id']];
}

function adminDraftPreviewHiddenInputs(): string
{
    $html = '';
    foreach (adminDraftPreviewQuery() as $key => $value) $html .= '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
    return $html;
}

function adminPreviewContentHref(string $url): string
{
    if (!defined('YK_ADMIN_DRAFT_PREVIEW') || empty($_SESSION['admin_id']) || !str_starts_with($url, '/') || str_starts_with($url, '//')) return $url;
    $parts = parse_url($url);
    $segments = explode('/', trim((string) ($parts['path'] ?? ''), '/'));
    if (LanguageRegistry::has($segments[0] ?? '')) array_shift($segments);
    if (count($segments) !== 1 || !str_ends_with($segments[0], '.html')) return $url;
    $row = channelModel()->findBy('slug', rawurldecode(substr($segments[0], 0, -5)));
    if (!$row || ($row['type'] ?? '') === 'link') return $url;
    $target = contentListUrl(adminPreviewLocalizedChannel($row));
    if (!empty($parts['query'])) $target .= (str_contains($target, '?') ? '&' : '?') . $parts['query'];
    return $target . (!empty($parts['fragment']) ? '#' . $parts['fragment'] : '');
}
