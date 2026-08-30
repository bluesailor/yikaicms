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
            error(__('sset_no_default'));
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
        error(__('admin_bad_params'));
    }

    if ($action === 'save_admin_languages') {
        verifyCsrf();
        $val = trim((string)($_POST['admin_languages'] ?? ''));
        // 仅允许 lang/*.php 实际存在的语言，去重，按 availableLanguages() 顺序
        $allowed = array_keys(availableLanguages());
        $list = array_values(array_intersect($allowed, array_filter(array_map('trim', explode(',', $val)))));
        if ($list === []) error(__('sset_keep_one_lang'));
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
        if ($enabled === []) error(__('sset_keep_one_front'));
        // 默认语言必须在启用列表中
        if (!in_array($default, $enabled, true)) $enabled[] = $default;
        // 重新按 allowed 顺序排序
        $enabled = array_values(array_intersect($allowed, $enabled));
        settingModel()->set('enabled_languages', json_encode($enabled));
        settingModel()->set('site_lang', $default);
        settingModel()->set('show_lang_switcher', !empty($_POST['show_switcher']) ? '1' : '0');

        // per-lang "首页" 菜单显示开关
        // 表单字段：nav_home_show[zh-CN] / nav_home_show[en] / ...
        // 存储约定：默认语言用 nav_home_show（不带后缀）；其他语言用 nav_home_show_{lang}
        // 只迭代已启用的语言 — 这是访客可见的前台开关，未启用语言不应写入键。
        $homeShowMap = (array) ($_POST['nav_home_show'] ?? []);
        foreach ($enabled as $lc) {
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
    // 授权相关：由「授权管理」页(admin/license.php)维护，属 system 组系统内部项。
    // 显式列入黑名单兜底——防止历史数据里 group 漂移成 basic 时泄漏到设置页。
    'license_key', 'license_state',
    // 由"语言"tab 的 per-lang 复选框管理，不该在基本设置里以裸 key 输入框出现
    'nav_home_show', 'nav_home_text',
];
// 收集启用的非默认语言后缀 (_en / _ja / ...)，过滤 per-lang 种子行
$_langSuffixesForFilter = [];
foreach ($_enabledList as $_lc) {
    if ($_lc !== $_defaultLang) $_langSuffixesForFilter[] = '_' . $_lc;
}

// 约束：构建 key→规范分组 映射（来自 defaults.php），剔除"分组漂移"的内部项。
// 历史数据可能把某些键的 group 写错（如老站把 system 组的 license_key 写成 basic），
// 升级后 DB 行的 group 不会自动迁移，于是泄漏到设置页。规则：若某行 key 在 defaults
// 里归属的规范分组 ≠ 当前 tab 分组，一律不渲染。这样所有 system/内部组的键都自动免疫，
// 无需逐个往 $hiddenKeys 里补——加新内部键到 defaults 的非 tab 分组即可，永不泄漏。
$_canonicalGroup = [];
foreach (getDefaults() as $_g => $_groupItems) {
    if (!is_array($_groupItems)) continue;
    foreach ($_groupItems as $_k => $_def) {
        $_canonicalGroup[$_k] = (string) $_g;
    }
}

$items = array_filter($items, function (array $item) use ($hiddenKeys, $_langSuffixesForFilter, $_canonicalGroup, $group): bool {
    if (str_starts_with($item['key'], 'admin_menu_')) return false;
    if (str_starts_with($item['key'], 'ai_'))         return false;
    if (in_array($item['key'], $hiddenKeys, true))    return false;
    // 白名单 / 默认拒绝：只渲染在 defaults.php 中「显式声明」且归属当前分组的键。
    // 未声明的键（system/内部键、运行时 set() 写入的 static_html_* / timeline_layout
    // 等）一律不渲染——彻底免疫泄漏。要新增显示项，必须在 defaults.php 声明它。
    if (!isset($_canonicalGroup[$item['key']]) || $_canonicalGroup[$item['key']] !== $group) return false;
    // ICP/公安备案为中国大陆特有：非中文后台不展示（英文/日语版没有「备案信息」）
    if (in_array($item['key'], ['site_icp', 'site_police'], true) && getLang() !== 'zh-CN') return false;
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

// ============================================================
// 分区（quick-nav）：按 defaults.php 里各字段的 'section' 把可见项分组，
// 分区顺序 = 在 defaults.php 中首次出现的声明顺序。未声明 section 的字段
// 归入末尾「其他」分区，绝不丢失。仅当存在 ≥2 个具名分区时才显示导航条。
$sectionOrder = [];
foreach (array_keys($groupDefaults) as $__k) {
    $__s = trim((string) ($groupDefaults[$__k]['section'] ?? ''));
    if ($__s !== '' && !in_array($__s, $sectionOrder, true)) $sectionOrder[] = $__s;
}
$OTHER_SECTION = __('sset_sec_other');
$itemsBySection = [];
foreach ($items as $__it) {
    $__s = trim((string) ($groupDefaults[$__it['key']]['section'] ?? ''));
    if ($__s === '') $__s = $OTHER_SECTION;
    $itemsBySection[$__s][] = $__it;
}
$renderSections = [];
foreach ($sectionOrder as $__s) {
    if (!empty($itemsBySection[$__s])) $renderSections[] = $__s;
}
if (!empty($itemsBySection[$OTHER_SECTION])) $renderSections[] = $OTHER_SECTION;
$hasSections = count($renderSections) >= 2;
$sectionAnchor = [];
foreach ($renderSections as $__i => $__s) $sectionAnchor[$__s] = 'setsec-' . $__i;

// 分区名多语言：defaults.php 里存中文名，这里映射到 i18n key，按后台语言显示。
$__sectionKeyMap = [
    __('sset_sec_site_info') => 'site_info',
    __('sset_sec_identity') => 'site_identity',
    __('sset_sec_appearance') => 'appearance',
    __('sset_sec_icp') => 'icp',
    __('sset_sec_upload') => 'upload',
    __('sset_sec_admin_brand') => 'admin_brand',
    __('sset_sec_other')     => 'other',
];
$sectionLabel = function (string $sec) use ($__sectionKeyMap): string {
    $k = 'setting_section_' . ($__sectionKeyMap[$sec] ?? 'other');
    $t = __($k);
    return $t !== $k ? $t : $sec; // 缺翻译时回退中文名
};

$pageTitle = __('setting_page_title');
$currentMenu = 'setting';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
require_once ROOT_PATH . '/admin/includes/header.php';

if ($_langAware) {
    $_hint = match ($_tabForLang) {
        'basic'  => str_replace(':key', 'key_' . $_viewLang, __('sset_tip_basic')),
        'header' => str_replace(':key', 'key_' . $_viewLang, __('sset_tip_header')),
        default  => str_replace(':key', 'key_' . $_viewLang, __('sset_tip_footer')),
    };
    echo renderAdminLangSwitcher($_viewLang, $_hint);
}
?>

<style>
/* 设置项输入框聚焦态：用主题色细边框替换浏览器默认的黑色 outline */
#settingForm input:not([type="color"]):focus,
#settingForm textarea:focus,
#settingForm select:focus {
    outline: none;
    border-color: var(--color-primary, #3B82F6);
    box-shadow: 0 0 0 1px var(--color-primary, #3B82F6);
}
</style>

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
        <a href="/admin/setting.php?tab=code<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'code' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_code'); ?></a>
        <a href="/admin/setting.php?tab=lang<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'lang' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_lang'); ?></a>
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
// 只为已启用的语言计算 — 未启用的语言不在前台渲染，自然不需要 per-lang 开关。
$navHomeShowMap = [];
foreach ($siteEnabled as $_lc) {
    $_key = $_lc === $siteDefault ? 'nav_home_show' : 'nav_home_show_' . $_lc;
    $_val = config($_key, null);
    // null（从未设置过）默认按显示处理
    $navHomeShowMap[$_lc] = $_val === null ? '1' : (string) $_val;
}
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b flex items-start justify-between">
        <div>
            <h2 class="font-bold text-gray-800"><?php echo e(__('sset_front_langs')); ?></h2>
            <p class="text-xs text-gray-500 mt-1"><?php echo e(__('sset_front_langs_tip')); ?></p>
        </div>
        <a href="/admin/setting_lang.php" class="text-xs text-primary hover:underline whitespace-nowrap mt-1"><?php echo e(__('sset_advanced')); ?> →</a>
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
                    <label class="flex items-center gap-1 ml-1 cursor-pointer" title="<?php echo e(__('sset_set_default')); ?>">
                        <input type="radio" name="site_default" value="<?php echo e($code); ?>"
                               <?php echo $code === $siteDefault ? 'checked' : ''; ?>>
                        <span class="text-[11px] text-gray-500"><?php echo e(__('slang_default_badge')); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- per-language 首页菜单显示开关：只渲染已启用语言 -->
        <div class="border-t pt-3">
            <p class="text-xs text-gray-500 mb-2"><?php echo e(__('sset_home_per_lang')); ?></p>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <?php foreach ($siteEnabled as $code): $label = $_allLangsForUI[$code] ?? $code; ?>
                <label class="flex items-center gap-1.5 cursor-pointer">
                    <input type="checkbox" name="nav_home_show[<?php echo e($code); ?>]" value="1"
                           <?php echo ($navHomeShowMap[$code] ?? '1') === '1' ? 'checked' : ''; ?>>
                    <span class="text-sm text-gray-700"><?php echo str_replace(':lang', e($label), e(__('sset_show_home_for'))); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="flex items-center justify-between border-t pt-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="siteShowSwitcher" <?php echo $showSwitcher === '1' ? 'checked' : ''; ?>>
                <span class="text-sm text-gray-700"><?php echo e(__('slang_switcher')); ?></span>
                <span class="text-xs text-gray-400"><?php echo e(__('sset_switcher_min')); ?></span>
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
    try { return JSON.parse(t); } catch (e) { return { code: -1, msg: <?php echo json_encode(__('admin_server_error'), JSON_UNESCAPED_UNICODE); ?> + ': ' + t.slice(0, 200) }; }
}
async function saveSiteLanguages() {
    const enabled = Array.from(document.querySelectorAll('input[name="site_langs[]"]:checked')).map(el => el.value);
    if (enabled.length === 0) { _langToast(<?php echo json_encode(__('sset_keep_one_front'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }
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
        _langToast(d.msg || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>, d.code === 0 ? 'success' : 'error');
        if (d.code === 0) setTimeout(() => location.reload(), 600);
    } catch (e) {
        _langToast(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + e.message, 'error');
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
        <h2 class="font-bold text-gray-800"><?php echo e(__('slang_admin_lang')); ?></h2>
        <p class="text-xs text-gray-500 mt-1"><?php echo e(__('sset_admin_langs_tip')); ?></p>
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
    if (checked.length === 0) { _langToast(<?php echo json_encode(__('sset_keep_one_lang'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }
    const fd = new FormData();
    fd.append('_token', _LANG_CSRF);
    fd.append('action', 'save_admin_languages');
    fd.append('admin_languages', checked.join(','));
    try {
        const r = await fetch(location.href, { method: 'POST', body: fd });
        const d = await _langSafeJson(r);
        _langToast(d.msg || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>, d.code === 0 ? 'success' : 'error');
        if (d.code === 0) setTimeout(() => location.reload(), 600);
    } catch (e) {
        _langToast(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + e.message, 'error');
    }
}
</script>
<?php endif; ?>

<?php if ($tab !== 'lang'): ?>
<form id="settingForm" class="space-y-6">
    <?php echo adminLangField(); ?>
    <input type="hidden" name="tab_hint" value="<?php echo e($tab); ?>">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo ['basic'=>__('setting_tab_basic'),'header'=>__('setting_tab_header'),'footer'=>__('setting_tab_footer'),'code'=>__('setting_tab_code')][$tab] ?? __('setting_tab_basic'); ?></h2>
            <button type="button" onclick="restoreAllDefaults()" class="text-xs text-gray-400 hover:text-red-500 transition inline-flex items-center gap-1" title="<?php echo __('setting_restore_all_tip'); ?>">
                <i class="ti ti-refresh text-sm"></i>
                <?php echo __('setting_restore_defaults'); ?>
            </button>
        </div>
        <?php if ($hasSections): ?>
        <!-- 快速导航：吸顶胶囊条（吸在顶栏下方）-->
        <div class="sticky top-16 z-30 bg-white/95 backdrop-blur border-b px-4 py-2 flex flex-wrap gap-1.5">
            <?php foreach ($renderSections as $__s): ?>
            <a href="#<?php echo $sectionAnchor[$__s]; ?>" data-target="<?php echo $sectionAnchor[$__s]; ?>"
               class="js-setpill px-3 py-1 text-xs rounded-full bg-gray-100 text-gray-600 hover:bg-primary hover:text-white transition cursor-pointer"><?php echo e($sectionLabel($__s)); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="p-6 space-y-8">
            <?php foreach ($renderSections as $__sec): ?>
            <?php if ($hasSections): ?>
            <section id="<?php echo $sectionAnchor[$__sec]; ?>" class="scroll-mt-32">
                <h3 class="text-sm font-semibold text-gray-700 mb-4 pb-2 border-b flex items-center gap-2">
                    <span class="w-1 h-4 bg-primary rounded"></span><?php echo e($sectionLabel($__sec)); ?>
                </h3>
                <div class="space-y-4">
            <?php endif; ?>
            <?php foreach ($itemsBySection[$__sec] as $item): ?>

            <?php if ($item['type'] === 'footer_columns'): ?>
            <!-- 页脚栏目编辑器 -->
            <?php $columnsData = json_decode($item['value'], true) ?: []; ?>
            <?php $__navGroups = navMenuModel()->asMap(); // 栏可引用网站菜单组（选了组则该栏渲染组链接） ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php echo e(settingLabel($item['key'], (string) $item['name'])); ?>
                    <?php $__tip = settingTip($item['key'], (string) $item['tip']); ?>
                    <?php if ($__tip !== ''): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php echo e($__tip); ?></span>
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
                                <i class="ti ti-x text-base"></i>
                            </button>
                        </div>
                        <div class="mt-2 ml-8 flex items-center gap-2 flex-wrap">
                            <label class="text-xs text-gray-400 flex-shrink-0"><?php echo __('setting_col_menu'); ?></label>
                            <select class="fcol-menu border rounded px-2 py-1.5 text-sm">
                                <option value="0"><?php echo __('setting_col_menu_none'); ?></option>
                                <?php foreach ($__navGroups as $__gid => $__gname): ?>
                                <option value="<?php echo (int) $__gid; ?>" <?php echo (int) ($col['menu_id'] ?? 0) === (int) $__gid ? 'selected' : ''; ?>><?php echo e($__gname); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($__navGroups === []): ?>
                            <a href="/admin/nav_menu.php" class="text-xs text-primary hover:underline"><?php echo e(__('setting_col_menu_create')); ?></a>
                            <?php else: ?>
                            <span class="text-xs text-gray-400"><?php echo e(__('setting_col_menu_hint')); ?></span>
                            <?php endif; ?>
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
                    <?php echo e(settingLabel($item['key'], (string) $item['name'])); ?>
                    <?php $__tip = settingTip($item['key'], (string) $item['tip']); ?>
                    <?php if ($__tip !== ''): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php echo e($__tip); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[footer_nav]" id="footerNavJson">
                <div id="footerNavEditor" class="space-y-3"></div>
                <button type="button" onclick="addFooterNavGroup()" class="mt-3 text-sm text-primary hover:text-secondary cursor-pointer inline-flex items-center gap-1">
                    <i class="ti ti-plus text-base"></i>
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
            // 整块文本域（代码注入等）改为「标签在上、输入框整行铺满」的堆叠布局，
            // 避免窄标签列与右侧宽文本域之间留出大片空白。
            $__stackedField = in_array($item['type'], ['code'], true);
            ?>
            <div class="<?php echo $__stackedField ? '' : 'grid grid-cols-1 md:grid-cols-4 gap-4 items-start'; ?>">
                <label class="text-gray-700 <?php echo $__stackedField ? 'block mb-2 font-medium' : 'pt-2'; ?>">
                    <?php echo e(settingLabel($item['key'], (string) $item['name'])); ?>
                    <?php // tip：语言包 setting_<key>_tip 优先；数据库存的 tip 兜底（老库该列可能为空，故不能以它做显示开关） ?>
                    <?php $__tip = settingTip($item['key'], (string) $item['tip']); ?>
                    <?php if ($__tip !== ''): ?>
                    <span class="text-gray-400 text-sm block"><?php echo e($__tip); ?></span>
                    <?php endif; ?>
                    <?php if ($isModified && $defaultValue !== ''): ?>
                    <span class="text-gray-300 text-xs block mt-1 truncate" title="<?php echo e($defaultValue); ?>"><?php echo __('setting_default'); ?>: <?php echo e(mb_strimwidth($defaultValue, 0, 30, '...')); ?></span>
                    <?php endif; ?>
                </label>
                <div class="<?php echo $__stackedField ? '' : 'md:col-span-3'; ?>">
                    <?php if ($item['type'] === 'textarea'): ?>
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                              class="w-full border rounded px-4 py-2"><?php echo e($item['value']); ?></textarea>

                    <?php elseif ($item['type'] === 'image'): ?>
                    <?php
                    $__imageAsset = SiteAsset::inspect((string) $item['value']);
                    $__imageCanPreview = in_array(
                        $__imageAsset['state'],
                        [SiteAsset::LOCAL_AVAILABLE, SiteAsset::REMOTE],
                        true
                    );
                    ?>
                    <div class="flex gap-2 items-center">
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               id="input_<?php echo e($item['key']); ?>"
                               class="flex-1 border rounded px-4 py-2">
                        <button type="button" onclick="uploadImage('<?php echo e($item['key']); ?>')"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <i class="ti ti-upload text-base"></i>
                            <?php echo __('btn_upload'); ?>
                        </button>
                        <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <i class="ti ti-photo text-base"></i>
                            <?php echo __("admin_media_library"); ?>
                        </button>
                    </div>
                    <?php if ($item['value'] && !$__imageCanPreview): ?>
                    <p data-testid="setting-image-resource-warning" data-setting-key="<?php echo e($item['key']); ?>"
                       class="mt-2 flex items-start gap-1.5 text-xs text-amber-700">
                        <i class="ti ti-alert-triangle mt-0.5 shrink-0" aria-hidden="true"></i>
                        <span><?php echo e(__($__imageAsset['state'] === SiteAsset::INVALID
                            ? 'setting_image_resource_invalid'
                            : 'setting_image_resource_missing')); ?></span>
                    </p>
                    <?php endif; ?>
                    <?php if ($item['value'] && $__imageCanPreview): ?>
                    <img src="<?php echo e($item['value']); ?>" class="h-16 mt-2 rounded" id="preview_<?php echo e($item['key']); ?>">
                    <?php endif; ?>
                    <?php
                    // 站点图标 / LOGO：想做图的当口就在这里，所以入口也放这里——
                    // 插件已启用 → 直达制作页；未安装 → 引导去插件市场（logo-maker 自
                    // v1.18.6 起不随核心包发布，见 includes/RecommendedPlugins.php）。
                    $__isBrandField = in_array($item['key'], ['site_favicon', 'site_logo'], true);
                    $__logoMakerHere = is_dir(ROOT_PATH . '/plugins/logo-maker');
                    $__logoMakerOn = function_exists('isPluginAvailable') && isPluginAvailable('logo-maker');
                    ?>
                    <?php if ($__isBrandField && $__logoMakerOn): ?>
                    <p class="text-xs text-gray-400 mt-2">
                        <i class="ti ti-wand"></i>
                        <a href="/admin/plugin_page.php?plugin=logo-maker#<?php echo $item['key'] === 'site_logo' ? 'logo' : 'text'; ?>" class="text-primary hover:underline"><?php echo __($item['key'] === 'site_logo' ? 'setting_logo_make' : 'setting_favicon_make'); ?></a>
                    </p>
                    <?php elseif ($__isBrandField && !$__logoMakerHere && hasPermission('*')): ?>
                    <p class="text-xs text-gray-400 mt-2">
                        <i class="ti ti-wand"></i>
                        <a href="/admin/plugin.php?tab=market&amp;q=logo-maker" class="text-primary hover:underline"><?php echo e(__('setting_logo_make_get_plugin')); ?></a>
                    </p>
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
                            // 选项文案本地化：优先 lang 键 setting_opt_<key>_<value>（DB 里的中文标签是兜底）
                            $__optLangKey = 'setting_opt_' . $item['key'] . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $optKey);
                            $__optText = __($__optLangKey);
                            if ($__optText === $__optLangKey) {
                                $__optText = (string) $optLabel;
                            }
                        ?>
                        <option value="<?php echo e((string)$optKey); ?>" <?php echo $item['value'] === (string)$optKey ? 'selected' : ''; ?>>
                            <?php echo e($__optText); ?>
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
                                ['name' => __('setting_color_deep_blue'),    'primary' => '#2563EB', 'secondary' => '#1D4ED8'],
                                ['name' => __('setting_color_classic_blue'), 'primary' => '#3B82F6', 'secondary' => '#1D4ED8'],
                                ['name' => __('setting_color_emerald'),      'primary' => '#10B981', 'secondary' => '#059669'],
                                ['name' => __('setting_color_red'),          'primary' => '#EF4444', 'secondary' => '#DC2626'],
                                ['name' => __('setting_color_orange'),       'primary' => '#F97316', 'secondary' => '#EA580C'],
                                ['name' => __('setting_color_purple'),       'primary' => '#8B5CF6', 'secondary' => '#7C3AED'],
                                ['name' => __('setting_color_cyan'),         'primary' => '#06B6D4', 'secondary' => '#0891B2'],
                                ['name' => __('setting_color_rose'),         'primary' => '#F43F5E', 'secondary' => '#E11D48'],
                                ['name' => __('setting_color_amber'),        'primary' => '#F59E0B', 'secondary' => '#D97706'],
                            ];
                            $currentPrimary = config('primary_color', '#2563EB');
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
                        <i class="ti ti-refresh text-sm"></i>
                        <?php echo __('setting_restore_default'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; /* 分区内字段 */ ?>
            <?php if ($hasSections): ?>
                </div>
            </section>
            <?php endif; ?>
            <?php endforeach; /* 分区 */ ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 text-center">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <i class="ti ti-check text-base"></i>
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

function clearImageResourceWarning(key) {
    document.querySelectorAll('[data-testid="setting-image-resource-warning"]').forEach(function(warning) {
        if (warning.dataset.settingKey === key) warning.remove();
    });
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
            console.error('upload response:', text);
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
            clearImageResourceWarning(currentImageKey);
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg || '<?php echo __('setting_upload_failed'); ?>', 'error');
        }
    } catch (err) {
        console.error('upload error:', err);
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
        clearImageResourceWarning(key);
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
        var menuSel = row.querySelector('.fcol-menu');
        var menuId = menuSel ? (parseInt(menuSel.value) || 0) : 0;
        if (title || content || menuId) {
            cols.push({ title: title, content: content, col_span: colSpan, menu_id: menuId });
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
        var menuSel = row.querySelector('.fcol-menu');
        if (menuSel) { menuSel.value = '0'; menuSel.dispatchEvent(new Event('change')); }
    });
});

// 选了菜单组 → 内容框置灰（渲染时被忽略，但保留内容便于切回）
document.querySelectorAll('.fcol-menu').forEach(function(sel) {
    var sync = function() {
        var row = sel.closest('.fcol-row');
        var ta = row.querySelector('.fcol-content');
        var on = (parseInt(sel.value) || 0) > 0;
        ta.classList.toggle('opacity-40', on);
        ta.classList.toggle('pointer-events-none', on);
    };
    sel.addEventListener('change', sync);
    sync();
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
                '<i class="ti ti-x text-base"></i>' +
            '</button>' +
        '</div>' +
        '<div class="fnav-links space-y-2 ml-6">' + linksHtml + '</div>' +
        '<button type="button" onclick="addFooterNavLink(' + gi + ')" class="ml-6 mt-2 text-xs text-primary hover:text-secondary cursor-pointer inline-flex items-center gap-1">' +
            '<i class="ti ti-plus text-sm"></i> <?php echo __('setting_add_link'); ?>' +
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
            '<i class="ti ti-x text-sm"></i>' +
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

// ========== 设置分区快速导航：平滑滚动 + 滚动高亮 ==========
(function () {
    var pills = Array.prototype.slice.call(document.querySelectorAll('.js-setpill'));
    if (!pills.length) return;

    function setActive(id) {
        pills.forEach(function (p) {
            var on = p.dataset.target === id;
            p.classList.toggle('bg-primary', on);
            p.classList.toggle('text-white', on);
            p.classList.toggle('bg-gray-100', !on);
            p.classList.toggle('text-gray-600', !on);
        });
    }

    pills.forEach(function (p) {
        p.addEventListener('click', function (e) {
            e.preventDefault();
            var el = document.getElementById(p.dataset.target);
            if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); setActive(p.dataset.target); }
        });
    });

    var sections = pills.map(function (p) { return document.getElementById(p.dataset.target); }).filter(Boolean);
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) { if (en.isIntersecting) setActive(en.target.id); });
        }, { rootMargin: '-140px 0px -70% 0px', threshold: 0 });
        sections.forEach(function (s) { io.observe(s); });
    }
    if (sections[0]) setActive(sections[0].id);
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
