<?php
/**
 * ikaiCMS - 社交媒体设置
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = $_POST['settings'] ?? [];
    settingModel()->saveBatch($settings);
    adminLog('setting', 'update', '更新社交媒体设置');
    success();
}

$socialLinks = json_decode(config('social_links', '[]'), true) ?: [];

$platformGroups = [
    '中国' => [
        'wechat'     => ['name' => '微信',     'placeholder' => '微信公众号链接 / 二维码图片URL',       'color' => '#07C160'],
        'weibo'      => ['name' => '微博',     'placeholder' => 'https://weibo.com/username',          'color' => '#E6162D'],
        'douyin'     => ['name' => '抖音',     'placeholder' => 'https://douyin.com/user/xxx',         'color' => '#000000'],
        'kuaishou'   => ['name' => '快手',     'placeholder' => 'https://kuaishou.com/profile/xxx',    'color' => '#FF4906'],
        'xiaohongshu'=> ['name' => '小红书',   'placeholder' => 'https://xiaohongshu.com/user/xxx',    'color' => '#FE2C55'],
        'bilibili'   => ['name' => 'Bilibili', 'placeholder' => 'https://space.bilibili.com/xxx',     'color' => '#00A1D6'],
        'zhihu'      => ['name' => '知乎',     'placeholder' => 'https://zhihu.com/people/xxx',        'color' => '#0066FF'],
    ],
    '国际' => [
        'wechat_global' => ['name' => 'WeChat',  'placeholder' => 'https://wechat.com/... 或二维码图片URL', 'color' => '#07C160'],
        'whatsapp'  => ['name' => 'WhatsApp',    'placeholder' => 'https://wa.me/8613800138000',        'color' => '#25D366'],
        'discord'   => ['name' => 'Discord',     'placeholder' => 'https://discord.gg/invite-code',    'color' => '#5865F2'],
        'x'         => ['name' => 'X (Twitter)', 'placeholder' => 'https://x.com/username',            'color' => '#000000'],
        'instagram' => ['name' => 'Instagram',   'placeholder' => 'https://instagram.com/username',    'color' => '#E4405F'],
        'facebook'  => ['name' => 'Facebook',    'placeholder' => 'https://facebook.com/username',     'color' => '#1877F2'],
        'youtube'   => ['name' => 'YouTube',     'placeholder' => 'https://youtube.com/@channel',      'color' => '#FF0000'],
        'tiktok'    => ['name' => 'TikTok',      'placeholder' => 'https://tiktok.com/@username',      'color' => '#000000'],
        'linkedin'  => ['name' => 'LinkedIn',    'placeholder' => 'https://linkedin.com/company/xxx',  'color' => '#0A66C2'],
        'threads'   => ['name' => 'Threads',     'placeholder' => 'https://threads.net/@username',     'color' => '#000000'],
        'pinterest' => ['name' => 'Pinterest',   'placeholder' => 'https://pinterest.com/username',    'color' => '#BD081C'],
    ],
];
// 扁平化（保存/显示用）
$platforms = [];
foreach ($platformGroups as $pfs) { $platforms = array_merge($platforms, $pfs); }

// 将已有数据映射
$linkMap = [];
foreach ($socialLinks as $sl) { $linkMap[$sl['platform'] ?? ''] = $sl['url'] ?? ''; }

$pageTitle = '社交媒体设置';
$currentMenu = 'setting_social';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800">社交媒体链接设置</h2>
        <p class="text-sm text-gray-500 mt-1">输入URL的社交媒体图标将显示在页脚。留空则不显示。</p>
    </div>

    <form id="settingForm">
        <input type="hidden" name="settings[social_links]" id="socialLinksJson">

        <?php $groupKeys = array_keys($platformGroups); ?>
        <div x-data="{ tab: '<?php echo $groupKeys[0]; ?>' }">
            <!-- Tab 导航 -->
            <div class="flex border-b">
                <?php foreach ($platformGroups as $groupName => $groupPlatforms): ?>
                <button type="button" @click="tab='<?php echo e($groupName); ?>'"
                    :class="tab==='<?php echo e($groupName); ?>' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="px-6 py-3 text-sm font-medium border-b-2">
                    <?php echo e($groupName); ?>
                    <?php
                    $groupCount = 0;
                    foreach ($groupPlatforms as $k => $pf) { if (!empty($linkMap[$k])) $groupCount++; }
                    if ($groupCount > 0): ?>
                    <span class="ml-1 bg-primary text-white text-xs px-1.5 py-0.5 rounded-full"><?php echo $groupCount; ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($platformGroups as $groupName => $groupPlatforms): ?>
            <div x-show="tab==='<?php echo e($groupName); ?>'" x-cloak>
            <div class="divide-y">
                <?php foreach ($groupPlatforms as $key => $pf): ?>
                <div class="px-6 py-3 flex items-center gap-4 social-row" data-platform="<?php echo $key; ?>">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0" style="background:<?php echo $pf['color']; ?>15">
                        <span class="text-xs font-bold" style="color:<?php echo $pf['color']; ?>"><?php echo mb_strtoupper(mb_substr($pf['name'], 0, 2)); ?></span>
                    </div>
                    <div class="w-36 flex-shrink-0">
                        <span class="font-medium text-gray-800 text-sm"><?php echo e($pf['name']); ?></span>
                    </div>
                    <div class="flex-1">
                        <input type="text" class="social-url w-full border rounded-lg px-4 py-2 text-sm"
                               placeholder="<?php echo e($pf['placeholder']); ?>"
                               value="<?php echo e($linkMap[$key] ?? ''); ?>">
                    </div>
                    <?php if (!empty($linkMap[$key])): ?>
                    <a href="<?php echo e($linkMap[$key]); ?>" target="_blank" class="text-gray-400 hover:text-primary flex-shrink-0" title="打开">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="px-6 py-4 border-t bg-gray-50 rounded-b-lg">
            <button type="submit" class="bg-primary hover:opacity-90 text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                保存
            </button>
        </div>
    </form>
</div>

<script>
function collectSocialLinks() {
    var rows = document.querySelectorAll('.social-row');
    var links = [];
    rows.forEach(function(row) {
        var url = row.querySelector('.social-url').value.trim();
        if (url) {
            links.push({ platform: row.dataset.platform, url: url });
        }
    });
    document.getElementById('socialLinksJson').value = JSON.stringify(links);
}

document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    collectSocialLinks();

    try {
        var response = await fetch(location.href, { method: 'POST', body: new FormData(this) });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
