<?php
/**
 * Yikai CMS - 单页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$channelId = getInt('id');
$slug = get('slug');
$parentSlug = get('parent');

// 通过slug或id获取栏目
if ($slug) {
    $channel = getChannelBySlug($slug);
    // 如果有父级slug，验证父子关系
    if ($channel && $parentSlug) {
        $parent = getChannelBySlug($parentSlug);
        if (!$parent || $channel['parent_id'] != $parent['id']) {
            $channel = null; // 父子关系不匹配
        }
    }

    // 如果找不到栏目，但有parent参数，可能是内容详情页
    if (!$channel && $parentSlug) {
        // 检查是否为内容slug
        $parentChannel = getChannelBySlug($parentSlug);
        if ($parentChannel) {
            $contentItem = contentModel()->findWhere(['slug' => $slug, 'channel_id' => $parentChannel['id'], 'status' => 1]);
            if ($contentItem) {
                // 直接加载详情页（避免重定向）
                $_GET['id'] = $contentItem['id'];
                include __DIR__ . '/detail.php';
                exit;
            }
        }
    }

    $channelId = $channel ? (int)$channel['id'] : 0;
} elseif ($channelId > 0) {
    $channel = getChannel($channelId);
} else {
    $channel = null;
}

if (!$channel || $channel['status'] != 1) {
    header('HTTP/1.1 404 Not Found');
    exit('页面不存在');
}

// 如果不是单页类型且不是相册类型，直接加载列表页（避免重定向循环）
if ($channel['type'] !== 'page' && $channel['type'] !== 'album' && !defined('LOADING_FROM_PAGE')) {
    define('LOADING_FROM_LIST', true);
    $_GET['id'] = $channelId;
    $_GET['slug'] = $channel['slug'] ?? '';
    include __DIR__ . '/list.php';
    exit;
}

// 如果是相册类型，获取相册数据
$albumData = null;
$albumPhotos = [];
if ($channel['type'] === 'album' && $channel['album_id'] > 0) {
    $albumData = albumModel()->findWhere(['id' => (int)$channel['album_id'], 'status' => 1]);
    if ($albumData) {
        $albumPhotos = albumPhotoModel()->where(['album_id' => (int)$albumData['id'], 'status' => 1]);
    }
}

// 页面跳转逻辑
$redirectType = $channel['redirect_type'] ?? 'auto';

if ($redirectType === 'url' && !empty($channel['redirect_url'])) {
    // 指定地址跳转（仅允许站内路径或本站域名）
    $redirectUrl = $channel['redirect_url'];
    if (!str_starts_with($redirectUrl, '/') && !str_starts_with($redirectUrl, SITE_URL)) {
        $redirectUrl = '/';
    }
    header('Location: ' . $redirectUrl);
    exit;
} elseif ($redirectType === 'auto') {
    // 自动跳转到第一个子栏目（默认行为）
    $children = channelModel()->getByParent($channelId, true);
    $firstChild = $children[0] ?? null;
    if ($firstChild) {
        header('Location: ' . channelUrl($firstChild));
        exit;
    }
}
// redirectType === 'none' 时不跳转，显示自身内容

// 获取单页内容（从 contents 表获取该栏目的第一条内容）
$content = contentModel()->getFirstByChannel($channelId);

// 页面信息
$pageTitle = $channel['seo_title'] ?: $channel['name'];
$pageKeywords = $channel['seo_keywords'] ?: config('site_keywords');
$pageDescription = $channel['seo_description'] ?: config('site_description');
$currentChannelId = $channelId;

// 获取侧边栏栏目（同级栏目或子栏目，不限制is_nav）
$sidebarChannels = [];
$sidebarTitle = $channel['name'];

if ($channel['parent_id'] > 0) {
    // 如果是子栏目，获取同级栏目（兄弟栏目）
    $parentChannel = getChannel((int)$channel['parent_id']);
    if ($parentChannel) {
        $sidebarTitle = $parentChannel['name'];
        $sidebarChannels = getChannels((int)$channel['parent_id'], false);
    }
} else {
    // 如果是顶级栏目，获取子栏目
    $sidebarChannels = getChannels($channelId, false);
}

// 获取导航
$navChannels = getNavChannels();

// 引入头部
require_once INCLUDES_PATH . 'header.php';
?>

<?php
// 准备面包屑数据
$breadcrumbs = [];
$tempChannel = $channel;
while ($tempChannel) {
    array_unshift($breadcrumbs, $tempChannel);
    if ($tempChannel['parent_id'] > 0) {
        $tempChannel = getChannel((int)$tempChannel['parent_id']);
    } else {
        $tempChannel = null;
    }
}
?>

<!-- 页面头部 -->
<?php if ($channel['image']): ?>
<section class="relative py-16 bg-cover bg-center" style="background-image: url('<?php echo e($channel['image']); ?>')">
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <div class="flex items-center gap-2 text-sm text-gray-300 mb-6">
            <a href="/" class="hover:text-white"><?php echo __('breadcrumb_home'); ?></a>
            <?php foreach ($breadcrumbs as $i => $bc): ?>
            <span>/</span>
            <?php if ($i === count($breadcrumbs) - 1): ?>
            <span class="text-white"><?php echo e($bc['name']); ?></span>
            <?php else: ?>
            <a href="<?php echo channelUrl($bc); ?>" class="hover:text-white"><?php echo e($bc['name']); ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-200 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<section class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 py-16 relative overflow-hidden">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-secondary rounded-full blur-3xl"></div>
    </div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <div class="flex items-center gap-2 text-sm text-gray-400 mb-6">
            <a href="/" class="hover:text-white"><?php echo __('breadcrumb_home'); ?></a>
            <?php foreach ($breadcrumbs as $i => $bc): ?>
            <span>/</span>
            <?php if ($i === count($breadcrumbs) - 1): ?>
            <span class="text-white"><?php echo e($bc['name']); ?></span>
            <?php else: ?>
            <a href="<?php echo channelUrl($bc); ?>" class="hover:text-white"><?php echo e($bc['name']); ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-300 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="py-12">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
            <!-- 主内容区 -->
            <div class="w-full <?php echo !empty($sidebarChannels) ? 'lg:flex-1' : ''; ?>">

                <?php if ($channel['type'] === 'album'): ?>
                <!-- 相册类型展示 -->
                <?php if (($content && $content['content']) || ($albumData && $albumData['description'])): ?>
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <?php if ($content && $content['content']): ?>
                    <div class="prose prose-lg max-w-none">
                        <?php echo parseShortcodes($content['content']); ?>
                    </div>
                    <?php elseif ($albumData && $albumData['description']): ?>
                    <p class="text-gray-600"><?php echo e($albumData['description']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($albumPhotos)): ?>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($albumPhotos as $photo): ?>
                        <div class="group">
                            <a href="<?php echo e($photo['image']); ?>"
                               data-lightbox="album"
                               data-title="<?php echo e($photo['title']); ?>"
                               class="block aspect-square rounded-lg overflow-hidden bg-gray-100">
                                <img src="<?php echo e(thumbnail($photo['image'], 'medium')); ?>"
                                     alt="<?php echo e($photo['title']); ?>"
                                     class="w-full h-full object-cover group-hover:scale-110 transition duration-300">
                            </a>
                            <?php if ($photo['title']): ?>
                            <p class="text-center text-sm text-gray-600 mt-2"><?php echo e($photo['title']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                    暂无图片
                </div>
                <?php endif; ?>

                <?php elseif ($content): ?>
                <!-- 单页类型展示 -->
                <article class="bg-white rounded-lg shadow p-6 md:p-8">
                    <?php if ($content['cover']): ?>
                    <div class="mb-6">
                        <img src="<?php echo e($content['cover']); ?>" alt="<?php echo e($content['title']); ?>"
                             class="w-full rounded-lg">
                    </div>
                    <?php endif; ?>

                    <div class="prose prose-lg max-w-none">
                        <?php echo parseShortcodes($content['content']); ?>
                    </div>

                    <!-- 图片相册 -->
                    <?php if ($content['images']): ?>
                    <?php $images = json_decode($content['images'], true) ?: []; ?>
                    <?php if (!empty($images)): ?>
                    <div class="mt-8 pt-8 border-t">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <?php foreach ($images as $image): ?>
                            <a href="<?php echo e($image); ?>" target="_blank" class="block aspect-square rounded overflow-hidden">
                                <img src="<?php echo e($image); ?>" class="w-full h-full object-cover hover:scale-110 transition duration-300">
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </article>
                <?php else: ?>
                <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
                    <?php echo __('no_content'); ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 侧边栏导航 -->
            <?php if (!empty($sidebarChannels)): ?>
            <div class="w-full lg:w-64">
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-3 border-b font-bold text-dark bg-primary text-white rounded-t-lg">
                        <?php echo e($sidebarTitle); ?>
                    </div>
                    <div class="divide-y">
                        <?php foreach ($sidebarChannels as $sub): ?>
                        <a href="<?php echo channelUrl($sub); ?>"
                           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo (int)$sub['id'] === $channelId ? 'text-primary bg-blue-50 font-medium' : 'text-gray-700 hover:text-primary'; ?>">
                            <?php echo e($sub['name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 联系方式 -->
                <div class="bg-white rounded-lg shadow mt-6">
                    <div class="px-4 py-3 border-b font-bold text-dark"><?php echo __('footer_contact'); ?></div>
                    <div class="p-4 space-y-3 text-sm">
                        <?php if ($phone = config('contact_phone')): ?>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                            </svg>
                            <span><?php echo e($phone); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($email = config('contact_email')): ?>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                            <span><?php echo e($email); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($address = config('contact_address')): ?>
                        <div class="flex items-start gap-2">
                            <svg class="w-4 h-4 text-primary mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                            </svg>
                            <span><?php echo e($address); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; /* sidebarChannels */ ?>
        </div>
    </div>
</section>

<?php if ($channel['type'] === 'album' && !empty($albumPhotos)): ?>
<!-- Lightbox -->
<div id="lightbox" class="lightbox-overlay" onclick="closeLightbox(event)">
    <div class="lightbox-content">
        <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
        <span class="lightbox-nav lightbox-prev" onclick="prevImage(event)">&lsaquo;</span>
        <img id="lightbox-img" src="" alt="">
        <span class="lightbox-nav lightbox-next" onclick="nextImage(event)">&rsaquo;</span>
        <div class="lightbox-title" id="lightbox-title"></div>
    </div>
</div>
<script>
const images = <?php echo json_encode(array_map(function($p) { return ['src' => $p['image'], 'title' => $p['title']]; }, $albumPhotos)); ?>;
let currentIndex = 0;

document.querySelectorAll('[data-lightbox="album"]').forEach((el, idx) => {
    el.addEventListener('click', (e) => {
        e.preventDefault();
        currentIndex = idx;
        showImage();
        document.getElementById('lightbox').classList.add('active');
        document.body.style.overflow = 'hidden';
    });
});

function showImage() {
    document.getElementById('lightbox-img').src = images[currentIndex].src;
    document.getElementById('lightbox-title').textContent = images[currentIndex].title || '';
}

function closeLightbox(e) {
    if (!e || e.target.id === 'lightbox') {
        document.getElementById('lightbox').classList.remove('active');
        document.body.style.overflow = '';
    }
}

function prevImage(e) {
    e.stopPropagation();
    currentIndex = (currentIndex - 1 + images.length) % images.length;
    showImage();
}

function nextImage(e) {
    e.stopPropagation();
    currentIndex = (currentIndex + 1) % images.length;
    showImage();
}

document.addEventListener('keydown', (e) => {
    if (!document.getElementById('lightbox').classList.contains('active')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') prevImage(e);
    if (e.key === 'ArrowRight') nextImage(e);
});
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . 'footer.php'; ?>
