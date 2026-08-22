<?php
/**
 * YikaiCMS - SEO 设置
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

// 视图语言（settings 表无 lang 列，per-lang 用 <key>_<lang> 后缀约定）
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];

// 哪些 key 走 per-lang（文案）；剩下的（验证码、sitemap 开关）全局共享
$LANG_KEYS = ['seo_title', 'site_keywords', 'site_description', 'seo_og_image'];

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', 'save');

    if ($action === 'save_robots') {
        verifyCsrf();
        $robotsContent = $_POST['robots_content'] ?? '';
        file_put_contents(ROOT_PATH . '/robots.txt', $robotsContent);
        adminLog('setting', 'update', '更新 robots.txt');
        success([], __('seo_robots_saved'));
    }

    if ($action === 'clear_sitemap_cache') {
        cacheDelete('sitemap_xml');
        adminLog('setting', 'update', '清除 Sitemap 缓存');
        success([], __('seo_sitemap_cache_cleared'));
    }

    // 保存 SEO 设置：lang-able 字段按当前视图 lang 写到 <key>_<lang>
    $settings = $_POST['settings'] ?? [];
    $remapped = [];
    foreach ($settings as $k => $v) {
        $isLangAble = in_array($k, $LANG_KEYS, true);
        $targetKey = ($isLangAble && $_viewLang !== $_defaultLang) ? ($k . '_' . $_viewLang) : (string) $k;
        $remapped[$targetKey] = $v;
    }
    settingModel()->saveBatch($remapped);

    // 清除 sitemap 缓存使配置生效
    cacheDelete('sitemap_xml');

    adminLog('setting', 'update', '更新 SEO 设置 (' . $_viewLang . ')');
    success();
}

$tab = $_GET['tab'] ?? 'basic';

// 读取 lang-able 字段：非默认语言时读 <key>_<lang>，空则回退到 base
$readLang = function (string $base) use ($LANG_KEYS, $_viewLang, $_defaultLang): string {
    if (in_array($base, $LANG_KEYS, true) && $_viewLang !== $_defaultLang) {
        $v = (string) config($base . '_' . $_viewLang, '');
        if ($v !== '') return $v;
    }
    return (string) config($base, '');
};

$seoConfig = [
    'seo_title'           => $readLang('seo_title'),
    'site_keywords'       => $readLang('site_keywords'),
    'site_description'    => $readLang('site_description'),
    'seo_og_image'        => $readLang('seo_og_image'),
    'seo_baidu_verify'    => config('seo_baidu_verify', ''),
    'seo_google_verify'   => config('seo_google_verify', ''),
    'seo_bing_verify'     => config('seo_bing_verify', ''),
    'seo_sitemap_ttl'     => config('seo_sitemap_ttl', '600'),
    'seo_sitemap_enabled' => config('seo_sitemap_enabled', '1'),
];

// 读取 robots.txt
$robotsContent = '';
$robotsPath = ROOT_PATH . '/robots.txt';
if (file_exists($robotsPath)) {
    $robotsContent = file_get_contents($robotsPath);
}

$pageTitle = __('seo_title');
$currentMenu = 'setting_seo';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, str_replace(':lang', $_viewLang, __('seo_lang_hint')));
?>

<?php
// 本页管全站 TDK/OG/验证等基础项；进阶功能在「SEO 助手」插件里。
// 装了就互链，没装就引导去市场装——想做 SEO 的当口正是推荐时机
// （插件自 v1.18.6 起不随核心包发布，见 includes/RecommendedPlugins.php）。
$__seoPluginOn = function_exists('isPluginAvailable') && isPluginAvailable('seo');
$__seoPluginHere = is_dir(ROOT_PATH . '/plugins/seo');
?>
<?php if ($__seoPluginOn): ?>
<div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-6 flex items-center gap-3 text-sm">
    <i class="ti ti-puzzle text-lg text-blue-500"></i>
    <span class="text-blue-800"><?php echo __('seo_plugin_hint'); ?></span>
    <a href="/admin/plugin_page.php?plugin=seo" class="ml-auto text-primary hover:underline whitespace-nowrap"><?php echo __('seo_plugin_hint_go'); ?> →</a>
</div>
<?php elseif (!$__seoPluginHere): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-6 flex items-center gap-3 text-sm">
    <i class="ti ti-puzzle text-lg text-amber-500"></i>
    <span class="text-amber-800"><?php echo e(__('seo_plugin_get_hint')); ?></span>
    <a href="/admin/plugin.php?tab=market&amp;q=seo" class="ml-auto text-primary hover:underline whitespace-nowrap"><?php echo e(__('seo_plugin_get_go')); ?> →</a>
</div>
<?php endif; ?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <a href="/admin/setting_seo.php" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('seo_tab_basic'); ?></a>
        <a href="/admin/setting_seo.php?tab=social<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'social' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('seo_tab_social'); ?></a>
        <a href="/admin/setting_seo.php?tab=verify<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'verify' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('seo_tab_verify'); ?></a>
        <a href="/admin/setting_seo.php?tab=sitemap<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'sitemap' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">Sitemap</a>
        <a href="/admin/setting_seo.php?tab=robots<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'robots' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">Robots.txt</a>
    </div>
</div>

<?php if ($tab === 'basic'): ?>
<!-- ==================== 基础设置 ==================== -->
<form id="settingForm" class="space-y-6">
    <?php echo adminLangField(); ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('seo_basic_title'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_home_title')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_home_title_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_title]"
                           value="<?php echo e($seoConfig['seo_title']); ?>"
                           placeholder="<?php echo e(__('seo_home_title_ph')); ?>"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo e(__('seo_home_title_tip')); ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_keywords')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_keywords_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[site_keywords]"
                           value="<?php echo e($seoConfig['site_keywords']); ?>"
                           placeholder="<?php echo e(__('seo_keywords_ph')); ?>"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo e(__('seo_keywords_tip')); ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_description')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_description_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <textarea name="settings[site_description]" rows="3"
                              placeholder="<?php echo e(__('seo_description_ph')); ?>"
                              class="w-full border rounded px-4 py-2"><?php echo e($seoConfig['site_description']); ?></textarea>
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo str_replace(':n', '<span id="descCount">' . mb_strlen($seoConfig['site_description']) . '</span>', e(__('seo_desc_count'))); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo __('admin_save'); ?></button>
    </div>
</form>

<?php elseif ($tab === 'social'): ?>
<!-- ==================== 社交分享 ==================== -->
<form id="settingForm" class="space-y-6">
    <?php echo adminLangField(); ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('seo_social_title'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_share_image')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_og_image_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <div class="flex items-center gap-4">
                        <input type="text" name="settings[seo_og_image]" id="ogImageInput"
                               value="<?php echo e($seoConfig['seo_og_image']); ?>"
                               placeholder="/uploads/images/og-image.jpg"
                               class="flex-1 border rounded px-4 py-2">
                        <button type="button" onclick="selectMedia('ogImageInput')"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded transition">
                            <?php echo e(__('admin_select_image')); ?>
                        </button>
                    </div>
                    <div class="text-xs text-gray-400 mt-1"><?php echo e(__('seo_share_image_tip')); ?></div>
                    <?php if ($seoConfig['seo_og_image']): ?>
                    <div class="mt-3 p-3 bg-gray-50 rounded-lg">
                        <div class="text-xs text-gray-500 mb-2"><?php echo e(__('admin_preview')); ?></div>
                        <img src="<?php echo e($seoConfig['seo_og_image']); ?>" alt="OG Image" class="h-24 rounded border">
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="border-t pt-4">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-700">
                    <strong><?php echo e(__('admin_note_label')); ?></strong><?php echo e(__('seo_og_note')); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo __('admin_save'); ?></button>
    </div>
</form>

<?php elseif ($tab === 'verify'): ?>
<!-- ==================== 站长验证 ==================== -->
<form id="settingForm" class="space-y-6">
    <?php echo adminLangField(); ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('seo_verify_title'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_baidu_code')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('seo_baidu_platform')); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_baidu_verify]"
                           value="<?php echo e($seoConfig['seo_baidu_verify']); ?>"
                           placeholder="<?php echo e(__('seo_baidu_ph')); ?>"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo str_replace(':site', '<a href="https://ziyuan.baidu.com/" target="_blank" class="text-primary hover:underline">' . e(__('seo_baidu_platform')) . '</a>', e(__('seo_baidu_hint'))); ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_google_code')); ?>
                    <span class="text-gray-400 text-sm block">Google Search Console</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_google_verify]"
                           value="<?php echo e($seoConfig['seo_google_verify']); ?>"
                           placeholder="如：xxxxxxxxxxxxxxxxxxxxxxxxx"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo str_replace(':site', '<a href="https://search.google.com/search-console" target="_blank" class="text-primary hover:underline">Google Search Console</a>', e(__('seo_verify_hint'))); ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_bing_code')); ?>
                    <span class="text-gray-400 text-sm block">Bing Webmaster Tools</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_bing_verify]"
                           value="<?php echo e($seoConfig['seo_bing_verify']); ?>"
                           placeholder="如：XXXXXXXXXXXXXXXXXXXXXXXX"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo str_replace(':site', '<a href="https://www.bing.com/webmasters" target="_blank" class="text-primary hover:underline">Bing Webmaster Tools</a>', e(__('seo_verify_hint'))); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo __('admin_save'); ?></button>
    </div>
</form>

<?php elseif ($tab === 'sitemap'): ?>
<!-- ==================== Sitemap ==================== -->
<form id="settingForm" class="space-y-6">
    <?php echo adminLangField(); ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('seo_sitemap_settings')); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_sitemap_enable')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('seo_sitemap_enable_tip')); ?></span>
                </label>
                <div class="md:col-span-3">
                    <select name="settings[seo_sitemap_enabled]" class="w-full border rounded px-4 py-2">
                        <option value="1" <?php echo $seoConfig['seo_sitemap_enabled'] === '1' ? 'selected' : ''; ?>><?php echo __('admin_enabled'); ?></option>
                        <option value="0" <?php echo $seoConfig['seo_sitemap_enabled'] === '0' ? 'selected' : ''; ?>><?php echo __('admin_disabled'); ?></option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('seo_cache_ttl')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('seo_unit_seconds')); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[seo_sitemap_ttl]"
                           value="<?php echo e($seoConfig['seo_sitemap_ttl']); ?>"
                           min="0" max="86400"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo e(__('seo_cache_ttl_tip')); ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2"><?php echo e(__('seo_sitemap_url')); ?></label>
                <div class="md:col-span-3">
                    <div class="flex items-center gap-4">
                        <code class="bg-gray-100 px-3 py-2 rounded text-sm flex-1"><?php echo e(rtrim(config('site_url', SITE_URL), '/')); ?>/sitemap.xml</code>
                        <a href="/sitemap.xml" target="_blank" class="text-sm text-primary hover:underline"><?php echo e(__('admin_view')); ?></a>
                        <button type="button" onclick="clearSitemapCache()"
                                class="text-sm bg-orange-50 hover:bg-orange-100 text-orange-600 px-3 py-1.5 rounded transition border border-orange-200">
                            <?php echo e(__('seo_refresh_cache')); ?>
                        </button>
                    </div>
                </div>
            </div>

            <div class="border-t pt-4">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-sm text-green-700">
                    <strong><?php echo e(__('seo_sitemap_covers')); ?></strong><?php echo e(__('seo_sitemap_covers_note')); ?>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo __('admin_save'); ?></button>
    </div>
</form>

<?php elseif ($tab === 'robots'): ?>
<!-- ==================== Robots.txt ==================== -->
<div class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo e(__('seo_robots_edit')); ?></h2>
            <a href="/robots.txt" target="_blank" class="text-sm text-primary hover:underline"><?php echo e(__('seo_view_current_file')); ?></a>
        </div>
        <div class="p-6">
            <textarea id="robotsContent" rows="16"
                      class="w-full border rounded px-4 py-2 font-mono text-sm leading-relaxed"
                      placeholder="User-agent: *&#10;Allow: /"><?php echo e($robotsContent); ?></textarea>
            <div class="flex items-center justify-between mt-4">
                <span class="text-xs text-gray-400"><?php echo e(__('seo_robots_tip')); ?></span>
                <button type="button" onclick="saveRobots()"
                        class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                    <?php echo e(__('seo_save_robots')); ?>
                </button>
            </div>

            <div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-700">
                <strong><?php echo e(__('admin_tip_label')); ?></strong><?php echo e(__('seo_robots_note')); ?>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
// 保存 SEO 设置
document.getElementById('settingForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    adminSave(this, { successMsg: '<?php echo __('admin_saved'); ?>' });
});

// 保存 robots.txt
async function saveRobots() {
    return adminSave({
        action: 'save_robots',
        robots_content: document.getElementById('robotsContent').value,
    }, {
        successMsg: false,  // 用服务端返回的 msg
        onSuccess: (d) => showMessage(d.msg || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>),
    });
}

// 清除 Sitemap 缓存
async function clearSitemapCache() {
    return adminSave({ action: 'clear_sitemap_cache' }, {
        successMsg: false,
        onSuccess: (d) => showMessage(d.msg || <?php echo json_encode(__('seo_cache_cleared'), JSON_UNESCAPED_UNICODE); ?>),
    });
}

// 描述字数统计
document.querySelector('textarea[name="settings[site_description]"]')?.addEventListener('input', function() {
    document.getElementById('descCount').textContent = this.value.length;
});

// 媒体选择器
function selectMedia(inputId) {
    window.open('/admin/media.php?mode=select&target=' + inputId, 'mediaSelect', 'width=900,height=600');
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
