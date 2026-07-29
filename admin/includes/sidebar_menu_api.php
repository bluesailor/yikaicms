<?php
/**
 * Yikai CMS — admin sidebar registration API.
 *
 * Public surface for plugins:
 *   register_admin_menu($groupKey, array $item, ?array $groupDefaults = null)
 *
 * Internal helper used by header.php:
 *   resolveAdminSidebar(): array  // returns the final, sorted, filtered menu
 *
 * Plugins normally just:
 *
 *   register_admin_menu('appearance', [
 *       'key'      => 'cool_widget',
 *       'label'    => 'Cool Widget',
 *       'url'      => '/admin/cool_widget.php',
 *       'icon'     => '<path d="..." />',
 *       'priority' => 50,
 *   ]);
 *
 * If $groupKey doesn't exist yet, supply $groupDefaults to create it:
 *
 *   register_admin_menu('reports', [...], [
 *       'label'    => 'Reports',
 *       'priority' => 65,
 *   ]);
 *
 * The data flows through the `admin_sidebar` filter so power users can
 * still rewrite the entire menu wholesale via add_filter().
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** @internal — staging area populated by register_admin_menu(); merged in resolveAdminSidebar(). */
$GLOBALS['_yikai_admin_menu_registry'] = $GLOBALS['_yikai_admin_menu_registry'] ?? [];

/**
 * Register a sidebar item from a plugin or any code path that runs before
 * the admin header is rendered (typical: plugins/<slug>/register.php).
 *
 * @param string                    $groupKey       e.g. 'content', 'appearance'
 * @param array<string,mixed>       $item           item config — at minimum 'key', 'label', 'url'
 * @param array<string,mixed>|null  $groupDefaults  if the group doesn't exist yet, use these to create it
 *                                                  ([label, priority, super_only])
 */
function register_admin_menu(string $groupKey, array $item, ?array $groupDefaults = null): void
{
    if (!isset($item['key']) || !isset($item['label']) || !isset($item['url'])) {
        throw new \InvalidArgumentException(
            "register_admin_menu: 'key', 'label', and 'url' are required (group={$groupKey})"
        );
    }
    $GLOBALS['_yikai_admin_menu_registry'][] = [
        'group'          => $groupKey,
        'item'           => $item,
        'group_defaults' => $groupDefaults,
    ];
}

/**
 * Build the final menu structure: defaults from sidebar_menu.php, merged
 * with anything register_admin_menu() collected, then run through the
 * `admin_sidebar` filter.
 *
 * @return array<string,array{label:string,icon?:string,priority:int,super_only?:bool,items:array}>
 */
function resolveAdminSidebar(): array
{
    $menu = require __DIR__ . '/sidebar_menu.php';

    // Apply registrations from plugins.
    foreach ($GLOBALS['_yikai_admin_menu_registry'] ?? [] as $reg) {
        $g  = $reg['group'];
        $it = $reg['item'];

        if (!isset($menu[$g])) {
            $defaults = $reg['group_defaults'] ?? null;
            if ($defaults === null) {
                // Skip silently — caller registered into an unknown group
                // without defaults. Logging would need a logger we don't
                // have here; the alternative is throwing, which would
                // break the whole admin if a plugin typoed.
                continue;
            }
            $menu[$g] = [
                'label'      => (string) ($defaults['label'] ?? $g),
                'priority'   => (int) ($defaults['priority'] ?? 100),
                'super_only' => (bool) ($defaults['super_only'] ?? false),
                'items'      => [],
            ];
        }
        $menu[$g]['items'][] = $it;
    }

    // Sort groups + items by priority (ascending; ties keep insertion order).
    uasort($menu, fn($a, $b) => ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100)));
    foreach ($menu as &$group) {
        if (!empty($group['items'])) {
            usort($group['items'], fn($a, $b) => ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100)));
        }
    }
    unset($group);

    // The grand-finale filter — lets a plugin rewrite the whole structure.
    if (function_exists('apply_filters')) {
        $menu = apply_filters('admin_sidebar', $menu);
    }

    return $menu;
}

/**
 * Render a single sidebar item as the same anchor markup the legacy
 * inline HTML produced. Centralizing this keeps the rendering loop in
 * header.php trivial.
 */
function renderAdminMenuItem(array $item, string $currentMenu): string
{
    $key        = (string) $item['key'];
    $label      = (string) $item['label'];
    $url        = (string) $item['url'];
    $icon       = (string) ($item['icon'] ?? '');
    $activeKeys = (array)  ($item['active_keys'] ?? [$key]);

    $isActive = in_array($currentMenu, $activeKeys, true);
    // 子项 14px / 图标 18px，与分组标题（16px 半粗 / 20px 图标）形成层级
    $cls = 'sidebar-link flex items-center px-4 py-1.5 rounded-lg mb-0.5 text-sm'
         . ($isActive ? ' active' : '');

    $svg = $icon !== ''
        ? '<svg class="w-[18px] h-[18px] mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">' . $icon . '</svg>'
        : '';

    // 红点徽标（如待更新数）：item['badge'] > 0 时在右侧显示
    $badge = (int) ($item['badge'] ?? 0);
    $badgeHtml = $badge > 0
        ? '<span class="ml-auto inline-flex items-center justify-center bg-red-500 text-white text-xs font-medium rounded-full px-1.5" style="min-width:18px;height:18px;line-height:1">' . $badge . '</span>'
        : '';

    return sprintf(
        '<a href="%s" class="%s">%s%s%s</a>',
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'),
        $svg,
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
        $badgeHtml
    );
}
