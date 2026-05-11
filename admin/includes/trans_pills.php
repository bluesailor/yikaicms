<?php
/**
 * 列表页翻译状态徽标 helper
 *
 * 用法：
 *   require_once ROOT_PATH . '/admin/includes/trans_pills.php';
 *   $transStatus = loadTransStatus('contents');           // 一次批量查所有翻译
 *   echo renderTransPills($row['id'], $transStatus, '/admin/content_edit.php');
 *
 * 设计：
 *   - 一次查全表 (lang IN ('en','ja')) 索引到 [translation_group_id][lang] => [id, name]
 *   - 每行 O(1) 查找，N 行 O(1) 查询而不是 O(N) 查询
 *   - 默认语言不显示徽标（它本身就是源行）
 *
 * 徽标语义：
 *   绿 ✓ — 已有该语言翻译，点击跳转到翻译版本编辑页
 *   灰 + — 缺该语言，点击跳到源行编辑页（在那里用 lang_switcher_edit 工具条创建翻译）
 */

declare(strict_types=1);

/**
 * 渲染列表页"查看语言"切换器（仅当启用 ≥2 种语言时输出）。
 *
 *   $currentLang: 当前视图语言（来自 ?lang= 或默认）
 *   $extraNote:   非源语言下右侧黄色提示文字（可空）
 */
function renderAdminLangSwitcher(string $currentLang, string $extraNote = ''): string
{
    $defaultLang = (string) config('site_lang', 'zh-CN');
    $enabledRaw = trim((string) config('enabled_languages', ''));
    $enabled = $enabledRaw !== '' ? json_decode($enabledRaw, true) : null;
    if (!is_array($enabled) || count($enabled) < 2) return '';

    $labels = availableLanguages();
    $defaultNote = $extraNote ?: '源语言（' . ($labels[$defaultLang] ?? $defaultLang) . '）才能新增/删除；当前是翻译版本，编辑用于本地化文字';

    $html = '<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap text-sm">';
    $html .= '<span class="text-gray-500">查看语言：</span>';
    foreach ($enabled as $lc) {
        if (!isset($labels[$lc])) continue;
        $isCurrent = ($lc === $currentLang);
        $isDefault = ($lc === $defaultLang);
        $cls = $isCurrent ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200';
        // 保留 URL 上的其它 query 参数（如 tab / channel_id / status / keyword）
        $qs = $_GET;
        $qs['lang'] = $lc;
        $href = '?' . http_build_query($qs);
        $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="px-3 py-1 rounded-full transition ' . $cls . '">'
              . htmlspecialchars($labels[$lc], ENT_QUOTES);
        if ($isDefault) $html .= '<span class="ml-1 text-[10px] opacity-70">(源)</span>';
        $html .= '</a>';
    }
    if ($currentLang !== $defaultLang) {
        $html .= '<span class="ml-auto text-xs text-amber-600">提示：' . htmlspecialchars($defaultNote, ENT_QUOTES) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 批量加载某表的翻译版本索引。
 *
 * @param string $table 不带前缀的表名（'channels' / 'contents' / 'products'）
 * @return array<int, array<string, array>>  [groupId => [lang => row]]
 */
function loadTransStatus(string $table): array
{
    $tableName = DB_PREFIX . $table;
    $defaultLang = (string) config('site_lang', 'zh-CN');
    $enabledRaw = trim((string) config('enabled_languages', ''));
    $enabled = $enabledRaw !== '' ? json_decode($enabledRaw, true) : null;
    if (!is_array($enabled) || count($enabled) < 2) return [];

    // 取目标语言（非默认）
    $targets = array_values(array_filter($enabled, fn($c) => $c !== $defaultLang));
    if (!$targets) return [];

    $placeholders = implode(',', array_fill(0, count($targets), '?'));

    // 选出 name/title 字段（不同表命名习惯不同）
    // 用 name: channels / product_categories / links / brands / product_tags
    // 用 title: contents / products / timelines / jobs / downloads / banners
    $titleCol = in_array($table, ['channels', 'product_categories', 'links', 'brands', 'product_tags'], true) ? 'name' : 'title';

    $rows = db()->fetchAll(
        "SELECT id, lang, translation_group_id, {$titleCol} AS _title
         FROM {$tableName}
         WHERE lang IN ({$placeholders}) AND translation_group_id > 0",
        $targets
    );

    $index = [];
    foreach ($rows as $r) {
        $g = (int) $r['translation_group_id'];
        $index[$g][$r['lang']] = $r;
    }
    return $index;
}

/**
 * 渲染某源行的翻译徽标。
 *
 * @param int    $sourceId   源行 id（默认语言行的 id 用作 translation_group_id 的目标值）
 * @param array  $statusIdx  loadTransStatus() 返回的索引
 * @param string $editUrl    编辑页路径，例如 '/admin/content_edit.php'
 * @param string $editParam  URL 上的 id 参数名，默认 'id'，channel.php 用 'edit'
 */
function renderTransPills(int $sourceId, array $statusIdx, string $editUrl, string $editParam = 'id'): string
{
    $defaultLang = (string) config('site_lang', 'zh-CN');
    $enabledRaw = trim((string) config('enabled_languages', ''));
    $enabled = $enabledRaw !== '' ? json_decode($enabledRaw, true) : null;
    if (!is_array($enabled) || count($enabled) < 2) return '';

    // 短标签（徽标里只显示 1-2 字符）；扩展时优先短标签，否则用 code 大写
    $shortLabels = ['zh-CN' => '中', 'en' => 'EN', 'ja' => '日', 'ko' => '韩', 'fr' => 'FR', 'de' => 'DE', 'es' => 'ES'];
    $available = availableLanguages();
    $sep = strpos($editUrl, '?') !== false ? '&' : '?';

    $html = '<div class="inline-flex items-center gap-1">';
    foreach ($enabled as $lang) {
        if ($lang === $defaultLang) continue;
        if (!isset($available[$lang])) continue;   // 不在 lang/ 文件中的不渲染
        $label = $shortLabels[$lang] ?? strtoupper(substr($lang, 0, 2));
        $existing = $statusIdx[$sourceId][$lang] ?? null;

        if ($existing) {
            // 已翻译 — 绿色，点跳到翻译版本编辑页
            $href = htmlspecialchars($editUrl . $sep . $editParam . '=' . (int) $existing['id'], ENT_QUOTES);
            $title = htmlspecialchars('已译: ' . ($existing['_title'] ?? ''), ENT_QUOTES);
            $html .= '<a href="' . $href . '" title="' . $title . '" class="inline-flex items-center justify-center w-7 h-5 text-[10px] font-medium rounded bg-green-100 text-green-700 hover:bg-green-200 transition">' . $label . ' ✓</a>';
        } else {
            // 缺译 — 灰色，点跳到源行编辑页（使用其 widget 创建翻译）
            $href = htmlspecialchars($editUrl . $sep . $editParam . '=' . $sourceId, ENT_QUOTES);
            $title = htmlspecialchars('缺' . $label . '翻译，点击进入源行编辑页创建', ENT_QUOTES);
            $html .= '<a href="' . $href . '" title="' . $title . '" class="inline-flex items-center justify-center w-7 h-5 text-[10px] font-medium rounded bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 transition">' . $label . '</a>';
        }
    }
    $html .= '</div>';
    return $html;
}
