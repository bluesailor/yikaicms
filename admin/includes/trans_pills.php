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
 * 后台视图语言三件套：解析 ?lang= → 校验在 enabled_languages 中 → 返回上下文。
 *
 * 用法：
 *   $lang = adminLangView();
 *   $lang['view']     => 'en'        当前视图 lang
 *   $lang['default']  => 'zh-CN'     站点源 lang
 *   $lang['enabled']  => ['zh-CN','en','ja']
 *   $lang['suffixes'] => ['_en','_ja']  非默认 lang 的 _suffix，过滤 setting 表 per-lang 种子行很方便
 *   $lang['isSource'] => true|false  当前视图是否源语言
 *   $lang['qs']       => '?lang=en'  保留视图的 query string（zh-CN 时为空）
 *   $lang['qsAmp']    => '&lang=en'  追加用（zh-CN 时为空）
 *
 * 之前每个 admin 页都重复 5 行解析，这个 helper 一次到位。
 */
function adminLangView(): array
{
    // 缓存按「本次请求的输入」键控：同请求内反复调用仍然只解析一次；
    // 输入变了（正常请求不会，单元测试会）则重新解析，测试之间不再互相污染。
    static $cache = [];
    $cacheKey = (string) ($_POST['view_lang'] ?? '') . '|' . (string) ($_GET['lang'] ?? '');
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $defaultLang = (string) config('site_lang', 'zh-CN');
    // 视图语言的取值顺序：POST view_lang（表单隐藏字段，见 adminLangField()）→ GET lang → 默认语言。
    //
    // 为什么 POST 优先：「我在编辑哪个语言槽」原先只存在于 URL 的 ?lang= 里，而它非常易丢——
    // 任何写死的 tab 链接、fetch('/admin/xxx.php') 都会把它丢掉，于是表单里显示的是英文内容、
    // 保存却写进默认语言（中文）槽位，且毫无提示（2026-08-10 客户实测：英文版内容改不动，
    // 中文前台反而显示英文）。表单渲染时把视图语言烙进隐藏字段随 POST 提交，
    // 保存端以它为准，URL 怎么丢参数都不会错位。
    $viewLang = (string) ($_POST['view_lang'] ?? '');
    if ($viewLang === '') {
        $viewLang = (string) (function_exists('get') ? get('lang', $defaultLang) : ($_GET['lang'] ?? $defaultLang));
    }
    $enabledRaw  = trim((string) config('enabled_languages', ''));
    $enabled     = $enabledRaw !== '' ? (json_decode($enabledRaw, true) ?: [$defaultLang]) : [$defaultLang];
    if (!in_array($viewLang, $enabled, true)) $viewLang = $defaultLang;

    $suffixes = [];
    foreach ($enabled as $lc) {
        if ($lc !== $defaultLang) $suffixes[] = '_' . $lc;
    }

    $isSource = ($viewLang === $defaultLang);
    $qs       = $isSource ? '' : ('?lang=' . urlencode($viewLang));
    $qsAmp    = $isSource ? '' : ('&lang=' . urlencode($viewLang));

    return $cache[$cacheKey] = [
        'view'     => $viewLang,
        'default'  => $defaultLang,
        'enabled'  => $enabled,
        'suffixes' => $suffixes,
        'isSource' => $isSource,
        'qs'       => $qs,
        'qsAmp'    => $qsAmp,
    ];
}

/**
 * 表单隐藏字段：把当前视图语言随 POST 提交（adminLangView() 以它为最高优先）。
 * 所有按 <key>_<lang> 后缀保存设置的表单都必须包含它——否则 URL 丢掉 ?lang=
 * 时保存会写错语言槽位。
 */
function adminLangField(): string
{
    $lang = adminLangView();
    return '<input type="hidden" name="view_lang" value="' . htmlspecialchars($lang['view'], ENT_QUOTES) . '">';
}

/**
 * 给 settings/getByGroup 返回的列表过滤掉 per-lang 种子行（key 以 _en / _ja 结尾的）。
 *
 *   $items = adminFilterLangSuffixes(settingModel()->getByGroup('contact'));
 *
 * 之前每个 setting_*.php 都要 array_filter str_ends_with；这里统一。
 */
function adminFilterLangSuffixes(array $rows, string $keyField = 'key'): array
{
    // 剥离所有「非默认语言」后缀的行——它们只是翻译存储（如 contact_phone_en），不应作为独立字段显示。
    // 注意：按「所有支持语言」而非「已启用语言」生成后缀，否则禁用某语言后其历史种子行会漏网、泄漏到设置页。
    $defaultLang = (string) config('site_lang', 'zh-CN');
    $suffixes = [];
    foreach (array_keys(availableLanguages()) as $lc) {
        if ($lc !== $defaultLang) $suffixes[] = '_' . $lc;
    }
    if (empty($suffixes)) return $rows;
    return array_values(array_filter($rows, function (array $row) use ($suffixes, $keyField): bool {
        $k = (string) ($row[$keyField] ?? '');
        foreach ($suffixes as $suf) {
            if (str_ends_with($k, $suf)) return false;
        }
        return true;
    }));
}

/**
 * POST 保存 settings 时把 lang-able key 重定向到 <key>_<lang>。
 *
 *   $remapped = adminRemapLangKeys($_POST['settings'] ?? [], ['site_title','site_desc']);
 *   settingModel()->saveBatch($remapped);
 *
 * 给的 $langKeys 列表是哪些 key 走 per-lang；不在列表里的保持全局共享。
 */
function adminRemapLangKeys(array $settings, array $langKeys): array
{
    $lang = adminLangView();
    if ($lang['isSource']) return $settings;
    $remapped = [];
    foreach ($settings as $k => $v) {
        $remapped[in_array($k, $langKeys, true) ? ($k . '_' . $lang['view']) : (string) $k] = $v;
    }
    return $remapped;
}

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
    $defaultNote = $extraNote ?: str_replace(':lang', ($labels[$defaultLang] ?? $defaultLang), __('tp_source_note'));

    $html = '<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap text-sm">';
    $html .= '<span class="text-gray-500">' . e(__('admin_view_lang')) . '</span>';
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
        if ($isDefault) $html .= '<span class="ml-1 text-[10px] opacity-70">(' . e(__('lang_source')) . ')</span>';
        $html .= '</a>';
    }
    if ($currentLang !== $defaultLang) {
        $html .= '<span class="ml-auto text-xs text-amber-600">' . e(__('admin_tip_label')) . '' . htmlspecialchars($defaultNote, ENT_QUOTES) . '</span>';
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
    // 用 name: channels / product_categories / links / brands / product_tags / albums
    // 用 title: contents / products / timelines / jobs / downloads / banners
    $titleCol = in_array($table, ['channels', 'product_categories', 'links', 'brands', 'product_tags', 'albums'], true) ? 'name' : 'title';

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

    // 短标签（徽标里只显示 1-2 字符）；中文后台用「中/日/韩」，其他语言回落
    // 大写代码——中文单字在英文/日文界面里读不出是哪门语言。扩展语言无键时同样用代码。
    $shortLabels = [
        'zh-CN' => __('lang_short_zhcn'), 'en' => __('lang_short_en'),
        'ja' => __('lang_short_ja'), 'ko' => __('lang_short_ko'),
        'fr' => 'FR', 'de' => 'DE', 'es' => 'ES',
    ];
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
            $title = htmlspecialchars(__('tp_translated') . ': ' . ($existing['_title'] ?? ''), ENT_QUOTES);
            $html .= '<a href="' . $href . '" title="' . $title . '" class="inline-flex items-center justify-center w-7 h-5 text-[10px] font-medium rounded bg-green-100 text-green-700 hover:bg-green-200 transition">' . $label . ' ✓</a>';
        } else {
            // 缺译 — 灰色，点跳到源行编辑页（使用其 widget 创建翻译）
            $href = htmlspecialchars($editUrl . $sep . $editParam . '=' . $sourceId, ENT_QUOTES);
            $title = htmlspecialchars(str_replace(':lang', $label, __('tp_missing')), ENT_QUOTES);
            $html .= '<a href="' . $href . '" title="' . $title . '" class="inline-flex items-center justify-center w-7 h-5 text-[10px] font-medium rounded bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 transition">' . $label . '</a>';
        }
    }
    $html .= '</div>';
    return $html;
}
