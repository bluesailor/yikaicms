<?php
/**
 * Footer navigation helpers shared by legacy and themed footers.
 *
 * The footer_nav setting is an editor-owned JSON list. We keep disabled channel
 * links in storage so re-enabling a channel can restore them, but frontend
 * output must not show links to disabled internal channels.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * @param string|null $raw Optional footer_nav JSON. Null reads current language config.
 * @return list<array{title:string,links:list<array<string,mixed>>}>
 */
function footerNavigationGroups(?string $raw = null): array
{
    if ($raw === null) {
        $raw = function_exists('configJsonLang')
            ? configJsonLang('footer_nav')
            : (string) config('footer_nav', '');
    }
    $groups = json_decode((string) $raw, true);
    if (!is_array($groups)) {
        return [];
    }

    $filtered = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }
        $links = [];
        foreach ((array) ($group['links'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $url = trim((string) ($link['url'] ?? ''));
            $name = trim((string) ($link['name'] ?? ''));
            if ($url === '' || $name === '' || footerNavigationLinkHiddenByChannelStatus($url)) {
                continue;
            }
            $link['url'] = $url;
            $link['name'] = $name;
            $links[] = $link;
        }
        if ($links === []) {
            continue;
        }
        $filtered[] = [
            'title' => trim((string) ($group['title'] ?? '')),
            'links' => $links,
        ];
    }

    return $filtered;
}

function footerNavigationLinkHiddenByChannelStatus(string $url): bool
{
    $channel = footerNavigationChannelForUrl($url);
    return $channel !== null && empty($channel['status']);
}

/**
 * @return array<string,mixed>|null
 */
function footerNavigationChannelForUrl(string $url): ?array
{
    $path = footerNavigationPath($url);
    if ($path === '' || $path === '/') {
        return null;
    }

    $channel = footerNavigationChannelByPath($path);
    if ($channel === null) {
        return null;
    }

    if (function_exists('isMultiLangEnabled') && isMultiLangEnabled('channels')) {
        $groupId = (int) ($channel['translation_group_id'] ?: $channel['id']);
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
        $sibling = db()->fetchOne(
            'SELECT * FROM ' . DB_PREFIX . 'channels WHERE translation_group_id = ? AND lang = ?',
            [$groupId, $lang]
        );
        if ($sibling) {
            return $sibling;
        }
    }

    return $channel;
}

function footerNavigationPath(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    if (isset($parts['host'])) {
        $siteHost = parse_url(function_exists('siteBaseUrl') ? siteBaseUrl() : (string) config('site_url', ''), PHP_URL_HOST);
        if ($siteHost === null || strtolower((string) $parts['host']) !== strtolower((string) $siteHost)) {
            return '';
        }
    }
    $path = rawurldecode((string) ($parts['path'] ?? ''));
    if ($path === '') {
        return '/';
    }
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    return rtrim($path, '/') ?: '/';
}

/**
 * @return array<string,mixed>|null
 */
function footerNavigationChannelByPath(string $path): ?array
{
    if (preg_match('#^/(?:page|list)/(\d+)\.html$#', $path, $m) === 1) {
        $row = channelModel()->find((int) $m[1]);
        return is_array($row) ? $row : null;
    }

    $slugPath = preg_replace('#\.html$#', '', ltrim($path, '/'));
    if (!is_string($slugPath) || $slugPath === '') {
        return null;
    }
    $parts = array_values(array_filter(explode('/', $slugPath), static fn (string $part): bool => $part !== ''));
    $slug = end($parts);
    if (!is_string($slug) || $slug === '') {
        return null;
    }

    $rows = db()->fetchAll(
        'SELECT * FROM ' . DB_PREFIX . 'channels WHERE slug = ? ORDER BY parent_id ASC, id ASC',
        [$slug]
    );
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (count($parts) < 2) {
            return $row;
        }
        $parent = channelModel()->find((int) ($row['parent_id'] ?? 0));
        if (is_array($parent) && (string) ($parent['slug'] ?? '') === $parts[count($parts) - 2]) {
            return $row;
        }
    }

    return null;
}
