<?php
/**
 * ikaiCMS - SEO 设置
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

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', 'save');

    if ($action === 'save_robots') {
        verifyCsrf();
        $robotsContent = $_POST['robots_content'] ?? '';
        file_put_contents(ROOT_PATH . '/robots.txt', $robotsContent);
        adminLog('setting', 'update', '更新 robots.txt');
        success([], 'robots.txt 已更新');
    }

    if ($action === 'clear_sitemap_cache') {
        cacheDelete('sitemap_xml');
        adminLog('setting', 'update', '清除 Sitemap 缓存');
        success([], 'Sitemap 缓存已清除');
    }

    // 保存 SEO 设置
    $settings = $_POST['settings'] ?? [];
    settingModel()->saveBatch($settings);

    // 清除 sitemap 缓存使配置生效
    cacheDelete('sitemap_xml');

    adminLog('setting', 'update', '更新 SEO 设置');
    success();
}

$tab = $_GET['tab'] ?? 'basic';

// 获取当前设置
$seoConfig = [
    'seo_title'           => config('seo_title', ''),
    'site_keywords'       => config('site_keywords', ''),
    'site_description'    => config('site_description', ''),
    'seo_og_image'        => config('seo_og_image', ''),
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

$pageTitle = 'SEO 设置';
$currentMenu = 'setting_seo';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <a href="/admin/setting_seo.php" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('seo_tab_basic'); ?></a>
        <a href="/admin/setting_seo.php?tab=social" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'social' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('seo_tab_social'); ?></a>
        <a href="/admin/setting_seo.php?tab=verify" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'verify' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('seo_tab_verify'); ?></a>
        <a href="/admin/setting_seo.php?tab=sitemap" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'sitemap' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">Sitemap</a>
        <a href="/admin/setting_seo.php?tab=robots" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'robots' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">Robots.txt</a>
    </div>
</div>

<?php if ($tab === 'basic'): ?>
<!-- ==================== 基础设置 ==================== -->
<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('seo_basic_title'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    首页 SEO 标题
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_home_title_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_title]"
                           value="<?php echo e($seoConfig['seo_title']); ?>"
                           placeholder="例：公司名称 - 核心业务关键词"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">建议 30 字以内，搜索结果标题显示上限约 30 个中文字符</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    全站关键词
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_keywords_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[site_keywords]"
                           value="<?php echo e($seoConfig['site_keywords']); ?>"
                           placeholder="关键词1,关键词2,关键词3"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">建议 3-8 个核心关键词</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    全站描述
                    <span class="text-gray-400 text-sm block"><?php echo __('seo_description_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <textarea name="settings[site_description]" rows="3"
                              placeholder="简要描述网站的核心内容和价值"
                              class="w-full border rounded px-4 py-2"><?php echo e($seoConfig['site_description']); ?></textarea>
                    <div class="text-xs text-gray-400 mt-1">
                        当前 <span id="descCount"><?php echo mb_strlen($seoConfig['site_description']); ?></span> 字，建议 80-160 字
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
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('seo_social_title'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    默认分享图片
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
                            选择图片
                        </button>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">建议尺寸 1200×630 像素，用于微信/微博/Facebook/Twitter 分享预览</div>
                    <?php if ($seoConfig['seo_og_image']): ?>
                    <div class="mt-3 p-3 bg-gray-50 rounded-lg">
                        <div class="text-xs text-gray-500 mb-2">预览：</div>
                        <img src="<?php echo e($seoConfig['seo_og_image']); ?>" alt="OG Image" class="h-24 rounded border">
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="border-t pt-4">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-700">
                    <strong>说明：</strong>系统已自动为所有页面生成 OpenGraph 和 Twitter Card 标签。
                    文章、产品等页面会自动使用各自的封面图，此处设置的图片仅作为没有封面图时的默认回退。
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
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('seo_verify_title'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    百度站长验证码
                    <span class="text-gray-400 text-sm block">百度搜索资源平台</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_baidu_verify]"
                           value="<?php echo e($seoConfig['seo_baidu_verify']); ?>"
                           placeholder="如：codeva-xxxxxxxxxxxx"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        在 <a href="https://ziyuan.baidu.com/" target="_blank" class="text-primary hover:underline">百度搜索资源平台</a> 添加站点后，选择 HTML 标签验证方式获取
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    Google 验证码
                    <span class="text-gray-400 text-sm block">Google Search Console</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_google_verify]"
                           value="<?php echo e($seoConfig['seo_google_verify']); ?>"
                           placeholder="如：xxxxxxxxxxxxxxxxxxxxxxxxx"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        在 <a href="https://search.google.com/search-console" target="_blank" class="text-primary hover:underline">Google Search Console</a> 添加站点后获取
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    Bing 验证码
                    <span class="text-gray-400 text-sm block">Bing Webmaster Tools</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[seo_bing_verify]"
                           value="<?php echo e($seoConfig['seo_bing_verify']); ?>"
                           placeholder="如：XXXXXXXXXXXXXXXXXXXXXXXX"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        在 <a href="https://www.bing.com/webmasters" target="_blank" class="text-primary hover:underline">Bing Webmaster Tools</a> 添加站点后获取
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
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">Sitemap 设置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    启用 Sitemap
                    <span class="text-gray-400 text-sm block">自动生成 XML 网站地图</span>
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
                    缓存时间
                    <span class="text-gray-400 text-sm block">单位：秒</span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[seo_sitemap_ttl]"
                           value="<?php echo e($seoConfig['seo_sitemap_ttl']); ?>"
                           min="0" max="86400"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">默认 600 秒（10 分钟），设为 0 则不缓存</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">Sitemap 地址</label>
                <div class="md:col-span-3">
                    <div class="flex items-center gap-4">
                        <code class="bg-gray-100 px-3 py-2 rounded text-sm flex-1"><?php echo e(rtrim(config('site_url', SITE_URL), '/')); ?>/sitemap.xml</code>
                        <a href="/sitemap.xml" target="_blank" class="text-sm text-primary hover:underline">查看</a>
                        <button type="button" onclick="clearSitemapCache()"
                                class="text-sm bg-orange-50 hover:bg-orange-100 text-orange-600 px-3 py-1.5 rounded transition border border-orange-200">
                            刷新缓存
                        </button>
                    </div>
                </div>
            </div>

            <div class="border-t pt-4">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-sm text-green-700">
                    <strong>自动收录内容：</strong>首页、所有栏目页、文章详情、产品详情、案例、下载、招聘等已发布内容，
                    各类型最多收录 5000 条。Sitemap 地址已在 robots.txt 中声明。
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
            <h2 class="font-bold text-gray-800">robots.txt 编辑</h2>
            <a href="/robots.txt" target="_blank" class="text-sm text-primary hover:underline">查看当前文件</a>
        </div>
        <div class="p-6">
            <textarea id="robotsContent" rows="16"
                      class="w-full border rounded px-4 py-2 font-mono text-sm leading-relaxed"
                      placeholder="User-agent: *&#10;Allow: /"><?php echo e($robotsContent); ?></textarea>
            <div class="flex items-center justify-between mt-4">
                <span class="text-xs text-gray-400">直接编辑网站根目录的 robots.txt 文件，修改后立即生效</span>
                <button type="button" onclick="saveRobots()"
                        class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                    保存 robots.txt
                </button>
            </div>

            <div class="mt-4 bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-700">
                <strong>提示：</strong>robots.txt 用于告诉搜索引擎哪些页面可以抓取、哪些不可以。
                修改前请确认规则正确，错误的配置可能导致网站从搜索结果中消失。
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
// 保存 SEO 设置
document.getElementById('settingForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});

// 保存 robots.txt
async function saveRobots() {
    const formData = new FormData();
    formData.append('action', 'save_robots');
    formData.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    formData.append('robots_content', document.getElementById('robotsContent').value);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage(data.msg);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
}

// 清除 Sitemap 缓存
async function clearSitemapCache() {
    const formData = new FormData();
    formData.append('action', 'clear_sitemap_cache');
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage(data.msg);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
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
