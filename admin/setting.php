<?php
/**
 * YikaiCMS - 站点设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// ============== 多语言视图（仅 footer tab 启用；per-lang 用 <key>_<lang> 后缀约定） ==============
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_tabForLang  = (string) ($_GET['tab'] ?? $_POST['tab_hint'] ?? 'basic');
// 各 tab 算 lang-able 的 key（其它字段全局共享）
$TAB_LANG_KEYS = [
    'basic'  => ['site_name', 'site_keywords', 'site_description', 'site_logo'],
    'footer' => ['footer_columns', 'footer_nav', 'footer_copyright_text'],
    'header' => ['topbar_left'],
];
$_langAware       = isset($TAB_LANG_KEYS[$_tabForLang]);
$FOOTER_LANG_KEYS = $TAB_LANG_KEYS['footer'];  // 向后兼容（页面里别处可能引用）
$_currentLangKeys = $TAB_LANG_KEYS[$_tabForLang] ?? [];

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    // 恢复默认值
    if ($action === 'restore_defaults') {
        verifyCsrf();
        $restoreGroup = $_POST['group'] ?? '';
        $restoreKey = $_POST['key'] ?? '';
        if ($restoreKey) {
            // 恢复单个设置
            $defaultValue = getDefault($restoreKey, null);
            if ($defaultValue !== null) {
                settingModel()->set($restoreKey, $defaultValue);
                adminLog('setting', 'restore', '恢复默认值: ' . $restoreKey);
                success(['value' => $defaultValue]);
            }
            error('未找到该设置的默认值');
        } elseif ($restoreGroup) {
            // 恢复整个分组
            $groupDefaults = getDefaults($restoreGroup);
            $batch = [];
            foreach ($groupDefaults as $k => $item) {
                $batch[$k] = $item['value'];
            }
            settingModel()->saveBatch($batch);
            adminLog('setting', 'restore', '恢复分组默认值: ' . $restoreGroup);
            success();
        }
        error('参数错误');
    }

    if ($action === 'save_admin_languages') {
        verifyCsrf();
        $val = trim((string)($_POST['admin_languages'] ?? ''));
        // 仅允许 lang/*.php 实际存在的语言，去重，按 availableLanguages() 顺序
        $allowed = array_keys(availableLanguages());
        $list = array_values(array_intersect($allowed, array_filter(array_map('trim', explode(',', $val)))));
        if ($list === []) error('至少保留一种语言');
        settingModel()->set('admin_languages', implode(',', $list));
        adminLog('setting', 'admin_languages', '更新后台语言: ' . implode(',', $list));
        success(['admin_languages' => implode(',', $list)]);
    }

    if ($action === 'save_site_languages') {
        verifyCsrf();
        $allowed = array_keys(availableLanguages());
        $enabled = array_values(array_intersect($allowed, (array)($_POST['enabled'] ?? [])));
        $default = (string)($_POST['site_lang'] ?? 'zh-CN');
        if (!in_array($default, $allowed, true)) $default = 'zh-CN';
        if ($enabled === []) error('至少保留一种前台语言');
        // 默认语言必须在启用列表中
        if (!in_array($default, $enabled, true)) $enabled[] = $default;
        // 重新按 allowed 顺序排序
        $enabled = array_values(array_intersect($allowed, $enabled));
        settingModel()->set('enabled_languages', json_encode($enabled));
        settingModel()->set('site_lang', $default);
        settingModel()->set('show_lang_switcher', !empty($_POST['show_switcher']) ? '1' : '0');

        // per-lang "首页" 菜单显示开关
        // 表单字段：nav_home_show_zh-CN / nav_home_show_en / nav_home_show_ja
        // 存储约定：默认语言用 nav_home_show（不带后缀）；其他语言用 nav_home_show_{lang}
        $homeShowMap = (array) ($_POST['nav_home_show'] ?? []);
        foreach ($allowed as $lc) {
            $val = !empty($homeShowMap[$lc]) ? '1' : '0';
            $settingKey = $lc === $default ? 'nav_home_show' : 'nav_home_show_' . $lc;
            settingModel()->set($settingKey, $val);
        }

        adminLog('setting', 'site_languages', '更新前台语言: ' . implode(',', $enabled) . ' / default=' . $default);
        success();
    }

    $settings = $_POST['settings'] ?? [];

    // header/footer tab + 非默认语言：lang-able key 重定向到 <key>_<lang>
    // tab 关联的 lang keys 在 $TAB_LANG_KEYS 里定义
    if ($_langAware && $_viewLang !== $_defaultLang) {
        $remapped = [];
        foreach ($settings as $k => $v) {
            $isLangAble = in_array($k, $_currentLangKeys, true);
            $targetKey = $isLangAble ? ($k . '_' . $_viewLang) : (string) $k;
            $remapped[$targetKey] = $v;
        }
        $settings = $remapped;
    }
    settingModel()->saveBatch($settings);

    adminLog('setting', 'update', '更新站点设置 (' . ($_langAware ? $_viewLang : 'global') . ')');
    success();
}

$tab = $_GET['tab'] ?? 'basic';
$groupMap = [
    'basic'  => 'basic',
    'header' => 'header',
    'footer' => 'footer',
    'code'   => 'code',
    // 'lang' tab 不对应任何 settings 组——靠两个独立卡片渲染
];
$group = $groupMap[$tab] ?? 'basic';

// lang tab 不读 settings 行
$items = $tab === 'lang' ? [] : settingModel()->getByGroup($group);

// 过滤掉不应在主设置页渲染的条目：
// 1) admin_menu_* / ai_* / current_theme — 由专门页面管理
// 2) 在专门子页面管理的语言相关 key（多语言设置页）
// 3) 系统自动写入的"无元数据"行（SettingModel::set() 直接 INSERT，
//    没有填 name/type，否则会以裸 key=value 形式漏出，如 timeline_layout）
$hiddenKeys = [
    'current_theme',
    'site_lang', 'admin_lang', 'admin_languages',
    'enabled_languages', 'show_lang_switcher',
    'translate_api', 'translate_api_key',
    'sidebar_state',
    'timeline_layout',
    'admin_menu_order',
    // 由"语言"tab 的 per-lang 复选框管理，不该在基本设置里以裸 key 输入框出现
    'nav_home_show', 'nav_home_text',
];
// 收集启用的非默认语言后缀 (_en / _ja / ...)，过滤 per-lang 种子行
$_langSuffixesForFilter = [];
foreach ($_enabledList as $_lc) {
    if ($_lc !== $_defaultLang) $_langSuffixesForFilter[] = '_' . $_lc;
}

$items = array_filter($items, function (array $item) use ($hiddenKeys, $_langSuffixesForFilter): bool {
    if (str_starts_with($item['key'], 'admin_menu_')) return false;
    if (str_starts_with($item['key'], 'ai_'))         return false;
    if (in_array($item['key'], $hiddenKeys, true))    return false;
    // 过滤 per-lang 后缀（footer_columns_en / footer_nav_ja 这种"per-lang 存储位"，
    // 不是独立设置项；它们的值通过 lang 切换器显示在 base 行里）
    foreach ($_langSuffixesForFilter as $suf) {
        if (str_ends_with($item['key'], $suf)) return false;
    }
    // SettingModel::set() 写入时没有 name/type，这些 row 本意是系统内部状态，
    // 不该在主设置页以表单字段呈现。
    if (trim((string)($item['name'] ?? '')) === '')   return false;
    return true;
});

// header/footer tab + 非默认语言：把 lang-able key 的 value 替换成 _<lang> 值
if ($_langAware && $_viewLang !== $_defaultLang) {
    foreach ($items as &$it) {
        if (!in_array($it['key'], $_currentLangKeys, true)) continue;
        $it['value'] = (string) config($it['key'] . '_' . $_viewLang, '');
    }
    unset($it);
}

$groupDefaults = getDefaults($group);

$pageTitle = __('setting_page_title');
$currentMenu = 'setting';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
require_once ROOT_PATH . '/admin/includes/header.php';

if ($_langAware) {
    $_hint = match ($_tabForLang) {
        'basic'  => '提示：站点名称 / SEO关键词 / SEO描述 / Logo 按语言独立保存（key_' . $_viewLang . '）；备案号、颜色、Banner 高度等全局共享',
        'header' => '提示：通栏左侧内容（topbar_left）按语言独立保存（key_' . $_viewLang . '）；颜色/开关等全局共享',
        default  => '提示：页脚栏目/导航/版权 按语言独立保存（key_' . $_viewLang . '）；背景色/图片等其余设置全局共享',
    };
    echo renderAdminLangSwitcher($_viewLang, $_hint);
}
?>

<!-- Tab 导航 -->
<?php
// 进入/切换 lang-aware tab 时保留 ?lang=；其它 tab 不带 lang 参数（非翻译）
$_aLangQS = ($_viewLang !== $_defaultLang) ? ('&lang=' . urlencode($_viewLang)) : '';
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/setting.php?tab=basic<?php echo $_aLangQS; ?>" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_basic'); ?></a>
        <a href="/admin/setting.php?tab=header<?php echo $_aLangQS; ?>" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'header' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_header'); ?></a>
        <a href="/admin/setting.php?tab=footer<?php echo $_aLangQS; ?>" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'footer' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_footer'); ?></a>
        <a href="/admin/setting.php?tab=code" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'code' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_code'); ?></a>
        <a href="/admin/setting.php?tab=lang" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'lang' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_lang'); ?></a>
    </div>
</div>

<?php if ($tab === 'lang'): ?>
<!-- 前台语言配置（独立卡片）-->
<?php
$siteDefault = config('site_lang', 'zh-CN');
$siteEnabledRaw = trim((string)config('enabled_languages', ''));
$siteEnabled = $siteEnabledRaw !== '' ? json_decode($siteEnabledRaw, true) : null;
if (!is_array($siteEnabled) || $siteEnabled === []) {
    $siteEnabled = array_keys(availableLanguages());
}
$showSwitcher = config('show_lang_switcher', '0');

// 所有 lang/*.php 实际存在的语言（code => label）
$_allLangsForUI = availableLanguages();

// per-lang "首页" 菜单显示开关：默认语言用 nav_home_show，其他语言用 nav_home_show_{lang}
$navHomeShowMap = [];
foreach (array_keys($_allLangsForUI) as $_lc) {
    $_key = $_lc === $siteDefault ? 'nav_home_show' : 'nav_home_show_' . $_lc;
    $_val = config($_key, null);
    // null（从未设置过）默认按显示处理
    $navHomeShowMap[$_lc] = $_val === null ? '1' : (string) $_val;
}
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b flex items-start justify-between">
        <div>
            <h2 class="font-bold text-gray-800">前台语言</h2>
            <p class="text-xs text-gray-500 mt-1">勾选要启用的前台语言，并选定一个为默认。访问者首次访问时使用默认语言。</p>
        </div>
        <a href="/admin/setting_lang.php" class="text-xs text-primary hover:underline whitespace-nowrap mt-1">高级设置 →</a>
    </div>
    <div class="p-6 space-y-4">
        <div class="flex flex-wrap items-center gap-x-8 gap-y-3">
            <?php foreach ($_allLangsForUI as $code => $label): ?>
                <div class="flex items-center gap-2">
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="checkbox" name="site_langs[]" value="<?php echo e($code); ?>"
                               <?php echo in_array($code, $siteEnabled, true) ? 'checked' : ''; ?>>
                        <span class="text-sm"><?php echo e($label); ?> <span class="text-gray-400">(<?php echo e($code); ?>)</span></span>
                    </label>
                    <label class="flex items-center gap-1 ml-1 cursor-pointer" title="设为默认">
                        <input type="radio" name="site_default" value="<?php echo e($code); ?>"
                               <?php echo $code === $siteDefault ? 'checked' : ''; ?>>
                        <span class="text-[11px] text-gray-500">默认</span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- per-language 首页菜单显示开关 -->
        <div class="border-t pt-3">
            <p class="text-xs text-gray-500 mb-2">每种语言独立控制是否在导航栏显示「首页」菜单：</p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <?php foreach ($_allLangsForUI as $code => $label): ?>
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" name="nav_home_show[<?php echo e($code); ?>]" value="1"
                           <?php echo ($navHomeShowMap[$code] ?? '1') === '1' ? 'checked' : ''; ?>>
                    <span class="text-sm text-gray-700"><?php echo e($label); ?> 显示「首页」</span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="flex items-center justify-between border-t pt-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="siteShowSwitcher" <?php echo $showSwitcher === '1' ? 'checked' : ''; ?>>
                <span class="text-sm text-gray-700">显示前台语言切换器</span>
                <span class="text-xs text-gray-400">（启用 2 种以上语言时生效）</span>
            </label>
            <button type="button" onclick="saveSiteLanguages()" class="cursor-pointer bg-primary hover:opacity-90 text-white px-4 py-1.5 rounded text-sm">保存设置</button>
        </div>
    </div>
</div>
<script>
// 自给自足的 toast + json 解析 + 显式 CSRF，避免依赖 footer.php 的全局 helper
// （footer.php 的 fetch 拦截器在大型外部脚本加载期间可能还没接管 window.fetch）。
const _LANG_CSRF = <?php echo json_encode(csrfToken()); ?>;
function _langToast(msg, type) {
    var div = document.createElement('div');
    div.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;padding:.75rem 1.5rem;border-radius:.5rem;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:.875rem';
    div.style.background = (type === 'error') ? '#ef4444' : '#10b981';
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 3000);
}
async function _langSafeJson(r) {
    var t = await r.text();
    try { return JSON.parse(t); } catch (e) { return { code: -1, msg: '服务器返回异常: ' + t.slice(0, 200) }; }
}
async function saveSiteLanguages() {
    const enabled = Array.from(document.querySelectorAll('input[name="site_langs[]"]:checked')).map(el => el.value);
    if (enabled.length === 0) { _langToast('至少保留一种前台语言', 'error'); return; }
    const def = document.querySelector('input[name="site_default"]:checked')?.value || 'zh-CN';
    const fd = new FormData();
    fd.append('_token', _LANG_CSRF);
    fd.append('action', 'save_site_languages');
    enabled.forEach(c => fd.append('enabled[]', c));
    fd.append('site_lang', def);
    if (document.getElementById('siteShowSwitcher').checked) fd.append('show_switcher', '1');
    // 收集 per-lang nav_home_show 勾选状态（未勾的也要送，不然后端无法区分"未勾"和"未发送"）
    document.querySelectorAll('input[name^="nav_home_show["]').forEach(cb => {
        if (cb.checked) fd.append(cb.name, '1');
    });
    try {
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await _langSafeJson(r);
        _langToast(d.msg || '已保存', d.code === 0 ? 'success' : 'error');
        if (d.code === 0) setTimeout(() => location.reload(), 600);
    } catch (e) {
        _langToast('请求失败: ' + e.message, 'error');
    }
}
</script>

<!-- 后台语言配置（独立卡片）-->
<?php
$adminLangsRaw = trim((string)config('admin_languages', ''));
$adminLangsCurrent = $adminLangsRaw !== ''
    ? array_filter(array_map('trim', explode(',', $adminLangsRaw)))
    : array_keys(availableLanguages());
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800">后台语言</h2>
        <p class="text-xs text-gray-500 mt-1">勾选后台顶栏语言切换器要显示的语言。少于 2 种时不显示切换器。</p>
    </div>
    <div class="p-6 flex items-center gap-6">
        <?php foreach ($_allLangsForUI as $code => $label): ?>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="admin_langs[]" value="<?php echo e($code); ?>"
                       <?php echo in_array($code, $adminLangsCurrent, true) ? 'checked' : ''; ?>>
                <span class="text-sm"><?php echo e($label); ?> <span class="text-gray-400">(<?php echo e($code); ?>)</span></span>
            </label>
        <?php endforeach; ?>
        <button type="button" onclick="saveAdminLanguages()" class="ml-auto cursor-pointer bg-primary hover:opacity-90 text-white px-4 py-1.5 rounded text-sm">保存设置</button>
    </div>
</div>
<script>
async function saveAdminLanguages() {
    const checked = Array.from(document.querySelectorAll('input[name="admin_langs[]"]:checked')).map(el => el.value);
    if (checked.length === 0) { _langToast('至少要保留一种语言', 'error'); return; }
    const fd = new FormData();
    fd.append('_token', _LANG_CSRF);
    fd.append('action', 'save_admin_languages');
    fd.append('admin_languages', checked.join(','));
    try {
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await _langSafeJson(r);
        _langToast(d.msg || '已保存', d.code === 0 ? 'success' : 'error');
        if (d.code === 0) setTimeout(() => location.reload(), 600);
    } catch (e) {
        _langToast('请求失败: ' + e.message, 'error');
    }
}
</script>
<?php endif; ?>

<?php if ($tab !== 'lang'): ?>
<form id="settingForm" class="space-y-6">
    <input type="hidden" name="tab_hint" value="<?php echo e($tab); ?>">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo ['basic'=>__('setting_tab_basic'),'header'=>__('setting_tab_header'),'footer'=>__('setting_tab_footer'),'code'=>__('setting_tab_code')][$tab] ?? __('setting_tab_basic'); ?></h2>
            <button type="button" onclick="restoreAllDefaults()" class="text-xs text-gray-400 hover:text-red-500 transition inline-flex items-center gap-1" title="<?php echo __('setting_restore_all_tip'); ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                <?php echo __('setting_restore_defaults'); ?>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <?php foreach ($items as $item): ?>

            <?php if ($item['type'] === 'footer_columns'): ?>
            <!-- 页脚栏目编辑器 -->
            <?php $columnsData = json_decode($item['value'], true) ?: []; ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php $__lbl = __('setting_' . $item['key']); echo e($__lbl !== 'setting_' . $item['key'] ? $__lbl : $item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[footer_columns]" id="footerColumnsJson">
                <div class="text-xs text-gray-400 mb-2 mt-1">
                    <?php echo __('setting_footer_placeholder_hint'); ?>
                </div>
                <div id="footerColumnsEditor" class="space-y-3">
                    <?php for ($ci = 0; $ci < 4; $ci++): ?>
                    <?php $col = $columnsData[$ci] ?? null; ?>
                    <div class="fcol-row p-3 border rounded-lg <?php echo $col ? 'bg-white' : 'bg-gray-50'; ?>" data-index="<?php echo $ci; ?>">
                        <div class="flex gap-3 items-start">
                            <span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0"><?php echo $ci + 1; ?></span>
                            <div class="flex-1">
                                <label class="text-xs text-gray-400 block mb-1"><?php echo __('setting_col_title'); ?></label>
                                <input type="text" class="fcol-title w-full border rounded px-3 py-1.5 text-sm" placeholder="<?php echo __('setting_col_title'); ?>" value="<?php echo e($col['title'] ?? ''); ?>">
                            </div>
                            <div class="w-24 flex-shrink-0">
                                <label class="text-xs text-gray-400 block mb-1"><?php echo __('setting_col_span'); ?></label>
                                <select class="fcol-span w-full border rounded px-2 py-1.5 text-sm">
                                    <option value="1" <?php echo ($col['col_span'] ?? 1) == 1 ? 'selected' : ''; ?>>1<?php echo __('setting_col_unit'); ?></option>
                                    <option value="2" <?php echo ($col['col_span'] ?? 1) == 2 ? 'selected' : ''; ?>>2<?php echo __('setting_col_unit'); ?></option>
                                </select>
                            </div>
                            <button type="button" class="fcol-clear text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="<?php echo __('setting_clear_row'); ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        <div class="mt-2 ml-8">
                            <label class="text-xs text-gray-400 block mb-1"><?php echo __('setting_col_content'); ?></label>
                            <textarea class="fcol-content w-full border rounded px-3 py-1.5 text-sm" rows="3" placeholder="<?php echo __('setting_footer_content_placeholder'); ?>"><?php echo e($col['content'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php elseif ($item['type'] === 'footer_nav'): ?>
            <!-- 页脚导航编辑器 -->
            <?php $navData = json_decode($item['value'], true) ?: []; ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php $__lbl = __('setting_' . $item['key']); echo e($__lbl !== 'setting_' . $item['key'] ? $__lbl : $item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[footer_nav]" id="footerNavJson">
                <div id="footerNavEditor" class="space-y-3"></div>
                <button type="button" onclick="addFooterNavGroup()" class="mt-3 text-sm text-primary hover:text-secondary cursor-pointer inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <?php echo __('setting_add_group'); ?>
                </button>
            </div>
            <script>
            var _footerNavData = <?php echo json_encode($navData, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            </script>

            <?php else: ?>
            <!-- 普通设置项 -->
            <?php
            $defaultItem = $groupDefaults[$item['key']] ?? null;
            $defaultValue = $defaultItem['value'] ?? '';
            $isModified = $defaultItem !== null && (string)$item['value'] !== (string)$defaultValue;
            ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php $__lbl = __('setting_' . $item['key']); echo e($__lbl !== 'setting_' . $item['key'] ? $__lbl : $item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm block"><?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?></span>
                    <?php endif; ?>
                    <?php if ($isModified && $defaultValue !== ''): ?>
                    <span class="text-gray-300 text-xs block mt-1 truncate" title="<?php echo e($defaultValue); ?>"><?php echo __('setting_default'); ?>: <?php echo e(mb_strimwidth($defaultValue, 0, 30, '...')); ?></span>
                    <?php endif; ?>
                </label>
                <div class="md:col-span-3">
                    <?php if ($item['type'] === 'textarea'): ?>
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                              class="w-full border rounded px-4 py-2"><?php echo e($item['value']); ?></textarea>

                    <?php elseif ($item['type'] === 'image'): ?>
                    <div class="flex gap-2 items-center">
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               id="input_<?php echo e($item['key']); ?>"
                               class="flex-1 border rounded px-4 py-2">
                        <button type="button" onclick="uploadImage('<?php echo e($item['key']); ?>')"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            <?php echo __('btn_upload'); ?>
                        </button>
                        <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <?php echo __("admin_media_library"); ?>
                        </button>
                    </div>
                    <?php if ($item['value']): ?>
                    <img src="<?php echo e($item['value']); ?>" class="h-16 mt-2 rounded" id="preview_<?php echo e($item['key']); ?>">
                    <?php endif; ?>

                    <?php elseif ($item['type'] === 'select'): ?>
                    <select name="settings[<?php echo e($item['key']); ?>]" class="w-full border rounded px-4 py-2">
                        <?php
                        $options = json_decode($item['options'] ?? '{}', true) ?: [];
                        if (empty($options)) {
                            $defaultOptions = [
                                'show_price' => ['0' => __('setting_hide'), '1' => __('setting_show')],
                            ];
                            $options = $defaultOptions[$item['key']] ?? ['0' => __('setting_no'), '1' => __('setting_yes')];
                        }
                        foreach ($options as $optKey => $optLabel):
                        ?>
                        <option value="<?php echo e((string)$optKey); ?>" <?php echo $item['value'] === (string)$optKey ? 'selected' : ''; ?>>
                            <?php echo e($optLabel); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <?php elseif ($item['type'] === 'color'): ?>
                    <?php if ($item['key'] === 'primary_color'): ?>
                    <!-- 预设配色方案 -->
                    <div class="mb-3" id="colorPresets">
                        <div class="text-xs text-gray-400 mb-2"><?php echo __('setting_color_presets'); ?></div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $presets = [
                                ['name' => 'クラシックブルー', 'primary' => '#3B82F6', 'secondary' => '#1D4ED8'],
                                ['name' => 'エメラルド',     'primary' => '#10B981', 'secondary' => '#059669'],
                                ['name' => 'レッド',         'primary' => '#EF4444', 'secondary' => '#DC2626'],
                                ['name' => 'オレンジ',       'primary' => '#F97316', 'secondary' => '#EA580C'],
                                ['name' => 'パープル',       'primary' => '#8B5CF6', 'secondary' => '#7C3AED'],
                                ['name' => 'シアン',         'primary' => '#06B6D4', 'secondary' => '#0891B2'],
                                ['name' => 'ローズ',         'primary' => '#F43F5E', 'secondary' => '#E11D48'],
                                ['name' => 'アンバー',       'primary' => '#F59E0B', 'secondary' => '#D97706'],
                            ];
                            $currentPrimary = config('primary_color', '#3B82F6');
                            $currentSecondary = config('secondary_color', '#1D4ED8');
                            foreach ($presets as $preset):
                                $isActive = strtolower($currentPrimary) === strtolower($preset['primary']);
                            ?>
                            <button type="button"
                                    class="color-preset flex items-center gap-1.5 px-3 py-1.5 border rounded-full text-xs transition <?php echo $isActive ? 'border-gray-800 bg-gray-50 font-medium' : 'border-gray-200 hover:border-gray-400'; ?>"
                                    data-primary="<?php echo $preset['primary']; ?>"
                                    data-secondary="<?php echo $preset['secondary']; ?>">
                                <span class="w-3 h-3 rounded-full flex-shrink-0" style="background: <?php echo $preset['primary']; ?>"></span>
                                <span class="w-3 h-3 rounded-full flex-shrink-0 -ml-2 border border-white" style="background: <?php echo $preset['secondary']; ?>"></span>
                                <?php echo $preset['name']; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="flex gap-2 items-center">
                        <input type="color"
                               value="<?php echo e($item['value'] ?: '#000000'); ?>"
                               class="w-10 h-10 p-1 border rounded cursor-pointer"
                               onchange="this.nextElementSibling.value = this.value">
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               class="flex-1 border rounded px-4 py-2 font-mono"
                               id="input_<?php echo e($item['key']); ?>"
                               pattern="#[0-9a-fA-F]{6}"
                               placeholder="#000000"
                               onchange="this.previousElementSibling.value = this.value">
                    </div>

                    <?php elseif ($item['type'] === 'code'): ?>
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="8"
                              class="w-full border rounded px-4 py-2 font-mono text-sm bg-gray-50"
                              spellcheck="false"
                              placeholder="<?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?>"><?php echo e($item['value']); ?></textarea>

                    <?php elseif ($item['type'] === 'number'): ?>
                    <input type="number" name="settings[<?php echo e($item['key']); ?>]"
                           value="<?php echo e($item['value']); ?>"
                           class="w-full border rounded px-4 py-2">

                    <?php else: ?>
                    <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                           value="<?php echo e($item['value']); ?>"
                           class="w-full border rounded px-4 py-2">
                    <?php endif; ?>
                    <?php if ($isModified): ?>
                    <button type="button" class="restore-btn text-xs text-gray-400 hover:text-primary mt-1 inline-flex items-center gap-1 transition"
                            data-key="<?php echo e($item['key']); ?>"
                            data-default="<?php echo e($defaultValue); ?>"
                            title="<?php echo __('setting_restore_to_default'); ?>: <?php echo e(mb_strimwidth($defaultValue, 0, 50, '...')); ?>">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <?php echo __('setting_restore_default'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 text-center">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <?php echo __('btn_save_settings'); ?>
        </button>
    </div>
</form>
<?php endif; ?>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script>
let currentImageKey = '';

function uploadImage(key) {
    currentImageKey = key;
    document.getElementById('imageFileInput').click();
}

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('上传响应:', text);
            showMessage('<?php echo __('setting_upload_error'); ?>', 'error');
            return;
        }

        if (data.code === 0) {
            document.getElementById('input_' + currentImageKey).value = data.data.url;
            let preview = document.getElementById('preview_' + currentImageKey);
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'preview_' + currentImageKey;
                preview.className = 'h-16 mt-2 rounded';
                document.getElementById('input_' + currentImageKey).parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg || '<?php echo __('setting_upload_failed'); ?>', 'error');
        }
    } catch (err) {
        console.error('上传错误:', err);
        showMessage('<?php echo __('setting_upload_failed'); ?>: ' + err.message, 'error');
    }

    this.value = '';
});

function pickFromMedia(key) {
    openMediaPicker(function(url) {
        document.getElementById('input_' + key).value = url;
        var preview = document.getElementById('preview_' + key);
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'preview_' + key;
            preview.className = 'h-16 mt-2 rounded';
            document.getElementById('input_' + key).parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

// 预设配色方案点击
document.querySelectorAll('.color-preset').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var primary = this.dataset.primary;
        var secondary = this.dataset.secondary;
        // 更新主题色
        var pInput = document.getElementById('input_primary_color');
        if (pInput) { pInput.value = primary; pInput.previousElementSibling.value = primary; }
        // 更新次要色
        var sInput = document.getElementById('input_secondary_color');
        if (sInput) { sInput.value = secondary; sInput.previousElementSibling.value = secondary; }
        // 更新按钮高亮
        document.querySelectorAll('.color-preset').forEach(function(b) {
            b.classList.remove('border-gray-800', 'bg-gray-50', 'font-medium');
            b.classList.add('border-gray-200');
        });
        this.classList.remove('border-gray-200');
        this.classList.add('border-gray-800', 'bg-gray-50', 'font-medium');
    });
});

// 页脚栏目编辑器 - 收集JSON
function collectFooterColumns() {
    var editor = document.getElementById('footerColumnsEditor');
    if (!editor) return;
    var rows = editor.querySelectorAll('.fcol-row');
    var cols = [];
    rows.forEach(function(row) {
        var title = row.querySelector('.fcol-title').value.trim();
        var content = row.querySelector('.fcol-content').value.trim();
        var colSpan = parseInt(row.querySelector('.fcol-span').value) || 1;
        if (title || content) {
            cols.push({ title: title, content: content, col_span: colSpan });
        }
    });
    document.getElementById('footerColumnsJson').value = JSON.stringify(cols);
}

// 清空按钮
document.querySelectorAll('.fcol-clear').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var row = this.closest('.fcol-row');
        row.querySelector('.fcol-title').value = '';
        row.querySelector('.fcol-content').value = '';
        row.querySelector('.fcol-span').value = '1';
    });
});

// 单项恢复默认值
document.querySelectorAll('.restore-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = this.dataset.key;
        var defaultVal = this.dataset.default;
        var input = document.querySelector('[name="settings[' + key + ']"]');
        if (input) {
            if (input.tagName === 'SELECT') {
                input.value = defaultVal;
            } else if (input.tagName === 'TEXTAREA') {
                input.value = defaultVal;
            } else {
                input.value = defaultVal;
            }
            // 同步颜色选择器
            var colorPicker = input.previousElementSibling;
            if (colorPicker && colorPicker.type === 'color') {
                colorPicker.value = defaultVal || '#000000';
            }
        }
        this.remove();
        showMessage('<?php echo __('setting_restored_save'); ?>');
    });
});

// 恢复全部默认值
async function restoreAllDefaults() {
    if (!confirm('<?php echo __('setting_restore_all_confirm'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'restore_defaults');
    formData.append('group', '<?php echo e($group); ?>');
    const response = await fetch(location.href, { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('setting_restored'); ?>');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg || '<?php echo __('setting_restore_failed'); ?>', 'error');
    }
}

document.getElementById('settingForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    collectFooterColumns();
    collectFooterNav();
    adminSave(this, {
        url: location.href,
        successMsg: '<?php echo __('admin_saved'); ?>',
        errorMsg:   '<?php echo __('admin_request_failed'); ?>',
    });
});

// ========== 页脚导航编辑器 ==========
function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function renderFooterNav(data) {
    var editor = document.getElementById('footerNavEditor');
    if (!editor) return;
    editor.innerHTML = '';
    (data || []).forEach(function(group, gi) {
        editor.insertAdjacentHTML('beforeend', renderNavGroup(group, gi));
    });
}

function renderNavGroup(group, gi) {
    var linksHtml = '';
    (group.links || []).forEach(function(link, li) {
        linksHtml += renderNavLink(link, gi, li);
    });
    return '<div class="fnav-group border rounded-lg p-3 bg-white" data-gi="' + gi + '">' +
        '<div class="flex items-center gap-3 mb-2">' +
            '<input type="text" class="fnav-title flex-1 border rounded px-3 py-1.5 text-sm" placeholder="<?php echo __('setting_nav_group_placeholder'); ?>" value="' + escHtml(group.title) + '">' +
            '<button type="button" onclick="removeFooterNavGroup(' + gi + ')" class="text-gray-300 hover:text-red-400 cursor-pointer" title="<?php echo __('setting_delete_group'); ?>">' +
                '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
            '</button>' +
        '</div>' +
        '<div class="fnav-links space-y-2 ml-6">' + linksHtml + '</div>' +
        '<button type="button" onclick="addFooterNavLink(' + gi + ')" class="ml-6 mt-2 text-xs text-primary hover:text-secondary cursor-pointer inline-flex items-center gap-1">' +
            '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg> <?php echo __('setting_add_link'); ?>' +
        '</button>' +
    '</div>';
}

function renderNavLink(link, gi, li) {
    var selSelf = (link.target || '_self') !== '_blank' ? ' selected' : '';
    var selBlank = (link.target || '_self') === '_blank' ? ' selected' : '';
    return '<div class="fnav-link flex items-center gap-2">' +
        '<input type="text" class="fnav-name border rounded px-2 py-1 text-sm w-28" placeholder="<?php echo __('setting_link_name'); ?>" value="' + escHtml(link.name) + '">' +
        '<input type="text" class="fnav-url flex-1 border rounded px-2 py-1 text-sm" placeholder="<?php echo __('setting_link_url_placeholder'); ?>" value="' + escHtml(link.url) + '">' +
        '<select class="fnav-target border rounded px-2 py-1 text-sm w-24">' +
            '<option value="_self"' + selSelf + '><?php echo __('setting_target_self'); ?></option>' +
            '<option value="_blank"' + selBlank + '><?php echo __('setting_target_blank'); ?></option>' +
        '</select>' +
        '<button type="button" onclick="this.closest(\'.fnav-link\').remove()" class="text-gray-300 hover:text-red-400 cursor-pointer">' +
            '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
        '</button>' +
    '</div>';
}

function addFooterNavGroup() {
    var editor = document.getElementById('footerNavEditor');
    if (!editor) return;
    var gi = editor.querySelectorAll('.fnav-group').length;
    editor.insertAdjacentHTML('beforeend', renderNavGroup({title: '', links: [{name: '', url: '', target: '_self'}]}, gi));
}

function removeFooterNavGroup(gi) {
    var groups = document.querySelectorAll('#footerNavEditor .fnav-group');
    if (groups[gi]) groups[gi].remove();
}

function addFooterNavLink(gi) {
    var groups = document.querySelectorAll('#footerNavEditor .fnav-group');
    if (!groups[gi]) return;
    var container = groups[gi].querySelector('.fnav-links');
    container.insertAdjacentHTML('beforeend', renderNavLink({name: '', url: '', target: '_self'}, gi, container.children.length));
}

function collectFooterNav() {
    var editor = document.getElementById('footerNavEditor');
    if (!editor) return;
    var groups = [];
    editor.querySelectorAll('.fnav-group').forEach(function(el) {
        var title = el.querySelector('.fnav-title').value.trim();
        var links = [];
        el.querySelectorAll('.fnav-link').forEach(function(lEl) {
            var name = lEl.querySelector('.fnav-name').value.trim();
            var url = lEl.querySelector('.fnav-url').value.trim();
            var target = lEl.querySelector('.fnav-target').value;
            if (name && url) links.push({name: name, url: url, target: target});
        });
        if (title || links.length > 0) groups.push({title: title, links: links});
    });
    document.getElementById('footerNavJson').value = JSON.stringify(groups);
}

// 初始化页脚导航编辑器
if (typeof _footerNavData !== 'undefined' && document.getElementById('footerNavEditor')) {
    renderFooterNav(_footerNavData);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
