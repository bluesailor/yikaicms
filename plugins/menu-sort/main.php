<?php
/**
 * 后台菜单排序 / 显示隐藏 / 改名
 *
 * v1.2.0 起改走服务端 `admin_sidebar` 过滤器，不再注入 JS 操作 DOM：
 *   - 侧栏、折叠态飞出面板、顶栏命令面板搜索等所有消费菜单数据的地方一次全对
 *   - 无「加载后才变化」的闪烁，也不与他人抢 DOM
 *
 * 配置存 setting `admin_menu_order`（JSON）：
 *   {
 *     "groups": ["site","product",...],          // 分组顺序
 *     "items":  {"site":["channel","page",...]}, // 组内菜单项顺序
 *     "hidden": ["system"],                      // 隐藏的分组
 *     "hiddenItems": ["cron"],                   // 隐藏的菜单项
 *     "labels": {"zh-CN": {"__group:site":"站点", "article":"新闻中心"}}  // 改名（按语言分存）
 *   }
 * 菜单项键与 admin.php 的 ms_item_key() 同规则（插件页为 plugin_page:插件名）。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** 菜单项排序键：普通页取文件名，插件页取 plugin_page:插件名（避免多个插件页同键互相覆盖）。 */
if (!function_exists('ms_item_key')) {
    function ms_item_key(string $url): string
    {
        if (!preg_match('#/admin/([^./?]+)\.php#', $url, $m)) {
            return '';
        }
        if ($m[1] === 'plugin_page' && preg_match('#[?&]plugin=([\w\-]+)#', $url, $pm)) {
            return 'plugin_page:' . $pm[1];
        }
        return $m[1];
    }
}

add_filter('admin_sidebar', function (array $menu): array {
    $cfg = json_decode((string) config('admin_menu_order', ''), true);
    if (!is_array($cfg)) {
        return $menu;
    }

    $hiddenGroups = (array) ($cfg['hidden'] ?? []);
    $hiddenItems  = (array) ($cfg['hiddenItems'] ?? []);
    $itemOrder    = (array) ($cfg['items'] ?? []);
    // 改名按当前后台语言取；无该语言的自定义则保持原文案
    $lang   = function_exists('getLang') ? getLang() : 'zh-CN';
    $labels = (array) (($cfg['labels'] ?? [])[$lang] ?? []);

    // ── 1) 分组：隐藏 / 改名 / 组内排序与隐藏 ──
    foreach ($menu as $gKey => &$group) {
        if (in_array($gKey, $hiddenGroups, true)) {
            unset($menu[$gKey]);
            continue;
        }
        if (isset($labels['__group:' . $gKey]) && $labels['__group:' . $gKey] !== '') {
            $group['label'] = (string) $labels['__group:' . $gKey];
        }

        $items = (array) ($group['items'] ?? []);
        if (!$items) {
            continue;
        }

        // 菜单项：隐藏 + 改名
        $kept = [];
        foreach ($items as $it) {
            $k = ms_item_key((string) ($it['url'] ?? ''));
            if ($k !== '' && in_array($k, $hiddenItems, true)) {
                continue;
            }
            if ($k !== '' && isset($labels[$k]) && $labels[$k] !== '') {
                $it['label'] = (string) $labels[$k];
            }
            $kept[] = $it;
        }

        // 菜单项排序：配置里出现过的按配置序在前，其余（新增项）保持原序追加
        $desired = array_values((array) ($itemOrder[$gKey] ?? []));
        if ($desired) {
            $byKey = [];
            foreach ($kept as $i => $it) {
                $byKey[ms_item_key((string) ($it['url'] ?? '')) ?: ('#' . $i)] = $it;
            }
            $sorted = [];
            foreach ($desired as $k) {
                if (isset($byKey[$k])) {
                    $sorted[] = $byKey[$k];
                    unset($byKey[$k]);
                }
            }
            $kept = array_merge($sorted, array_values($byKey));
        }

        $group['items'] = $kept;
    }
    unset($group);

    // ── 2) 分组排序：配置里出现过的在前，新增分组保持原序追加 ──
    $desiredGroups = array_values((array) ($cfg['groups'] ?? []));
    if ($desiredGroups) {
        $sorted = [];
        foreach ($desiredGroups as $gKey) {
            if (isset($menu[$gKey])) {
                $sorted[$gKey] = $menu[$gKey];
                unset($menu[$gKey]);
            }
        }
        $menu = $sorted + $menu;
    }

    return $menu;
});
