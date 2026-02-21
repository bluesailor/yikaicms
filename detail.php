<?php
/**
 * Yikai CMS - 详情页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$id = getInt('id');

if (!$id) {
    header('Location: /');
    exit;
}

// 获取内容
$content = contentModel()->getPublished($id);

if (!$content) {
    header('HTTP/1.1 404 Not Found');
    exit('内容不存在');
}

// 更新浏览量
contentModel()->incrementViews($id);

// 获取栏目
$channel = getChannel((int)$content['channel_id']);

// 页面信息
$pageTitle = $content['title'];
$pageKeywords = $content['tags'] ?: ($channel['seo_keywords'] ?? '');
$pageDescription = $content['summary'] ?: cutStr(strip_tags($content['content'] ?? ''), 150);
$currentChannelId = (int)$content['channel_id'];

// 获取上一篇/下一篇
$prevContent = contentModel()->getPrev((int)$content['channel_id'], $id);
$nextContent = contentModel()->getNext((int)$content['channel_id'], $id);

// 获取相关内容
$relatedContents = contentModel()->getRelated((int)$content['channel_id'], $id);

// 下载类型：获取下载分类用于侧边栏
$downloadSidebarCats = [];
if ($channel && $channel['type'] === 'download') {
    // 当前栏目的兄弟分类（同一父级下的子栏目）
    $parentId = (int)$channel['parent_id'];
    if ($parentId > 0) {
        $downloadSidebarCats = getChannels($parentId, false);
    }
}

// 获取导航
$navChannels = getNavChannels();

// 引入头部
require_once INCLUDES_PATH . 'header.php';
?>

<!-- 面包屑 -->
<div class="bg-gray-100 py-4">
    <div class="container mx-auto px-4">
        <div class="flex items-center gap-2 text-sm text-gray-600">
            <a href="/" class="hover:text-primary"><?php echo __('breadcrumb_home'); ?></a>
            <?php
            // 获取栏目面包屑路径
            if ($channel) {
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
                foreach ($breadcrumbs as $bc):
            ?>
            <span>/</span>
            <a href="<?php echo channelUrl($bc); ?>" class="hover:text-primary">
                <?php echo e($bc['name']); ?>
            </a>
            <?php
                endforeach;
            }
            ?>
            <span>/</span>
            <span class="text-gray-400 truncate max-w-xs"><?php echo e($content['title']); ?></span>
        </div>
    </div>
</div>

<section class="py-12">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
            <!-- 主内容区 -->
            <div class="w-full lg:flex-1">
                <article class="bg-white rounded-lg shadow overflow-hidden">
                    <!-- 标题区 -->
                    <div class="p-6 md:p-8 border-b">
                        <h1 class="text-2xl md:text-3xl font-bold text-dark leading-tight">
                            <?php echo e($content['title']); ?>
                        </h1>
                        <?php if ($content['subtitle']): ?>
                        <p class="mt-2 text-gray-500"><?php echo e($content['subtitle']); ?></p>
                        <?php endif; ?>

                        <div class="mt-4 flex flex-wrap items-center gap-4 text-sm text-gray-500">
                            <?php if ($content['author']): ?>
                            <span><?php echo __('detail_author'); ?>: <?php echo e($content['author']); ?></span>
                            <?php endif; ?>
                            <?php if ($content['source']): ?>
                            <span><?php echo __('detail_source'); ?>: <?php echo e($content['source']); ?></span>
                            <?php endif; ?>
                            <span><?php echo __('detail_publish_time'); ?>: <?php echo date('Y-m-d H:i', (int)$content['publish_time']); ?></span>
                            <span><?php echo __('detail_views'); ?>: <?php echo number_format((int)$content['views'] + 1); ?></span>
                        </div>

                        <?php if ($content['tags']): ?>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <?php foreach (explode(',', $content['tags']) as $tag): ?>
                            <span class="px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded">
                                <?php echo e(trim($tag)); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($content['channel_type'] === 'case' && $content['cover']): ?>
                    <!-- 案例封面图 -->
                    <div class="px-6 md:px-8 pt-6">
                        <img src="<?php echo e($content['cover']); ?>" alt="<?php echo e($content['title']); ?>"
                             class="w-full rounded-lg">
                    </div>
                    <?php endif; ?>

                    <?php if ($content['channel_type'] === 'product'): ?>
                    <!-- 产品信息 -->
                    <div class="p-6 md:p-8 border-b bg-gray-50">
                        <div class="flex flex-wrap gap-8">
                            <?php if ($content['cover']): ?>
                            <div class="w-full md:w-80">
                                <img src="<?php echo e($content['cover']); ?>" alt="<?php echo e($content['title']); ?>"
                                     class="w-full rounded-lg">
                            </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <?php if (config('show_price', '0') === '1' && $content['price']): ?>
                                <div class="mb-4">
                                    <span class="text-gray-500"><?php echo __('detail_price'); ?>:</span>
                                    <span class="text-2xl text-red-500 font-bold">&yen;<?php echo number_format((float)$content['price'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($content['specs']): ?>
                                <div class="space-y-2">
                                    <?php
                                    $specs = json_decode($content['specs'], true) ?: [];
                                    foreach ($specs as $spec):
                                    ?>
                                    <div class="flex">
                                        <span class="text-gray-500 w-20"><?php echo e($spec['name'] ?? ''); ?>：</span>
                                        <span><?php echo e($spec['value'] ?? ''); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($content['channel_type'] === 'download'): ?>
                    <!-- 下载信息 -->
                    <div class="p-6 md:p-8 border-b bg-green-50">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-center gap-6">
                                <span class="text-gray-500"><?php echo __('download_count'); ?>: <?php echo number_format((int)$content['download_count']); ?></span>
                            </div>
                            <?php if ($content['attachment']): ?>
                            <a href="/download.php?id=<?php echo $content['id']; ?>"
                               class="inline-flex items-center gap-2 bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                </svg>
                                <?php echo __('download_btn'); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 正文内容 -->
                    <div class="p-6 md:p-8 prose prose-lg max-w-none">
                        <?php echo parseShortcodes($content['content'] ?? ''); ?>
                    </div>

                    <!-- 图片相册 -->
                    <?php if ($content['images']): ?>
                    <?php $images = json_decode($content['images'], true) ?: []; ?>
                    <?php if (!empty($images)): ?>
                    <div class="p-6 md:p-8 border-t">
                        <h3 class="font-bold text-lg mb-4">图片展示</h3>
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

                    <!-- 上下篇 -->
                    <div class="p-6 md:p-8 border-t bg-gray-50">
                        <div class="flex flex-wrap justify-between gap-4 text-sm">
                            <div>
                                <?php if ($prevContent): ?>
                                <span class="text-gray-500"><?php echo __('detail_prev'); ?>:</span>
                                <a href="<?php echo contentUrl($prevContent); ?>" class="text-primary hover:underline">
                                    <?php echo e(cutStr($prevContent['title'], 30)); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($nextContent): ?>
                                <span class="text-gray-500"><?php echo __('detail_next'); ?>:</span>
                                <a href="<?php echo contentUrl($nextContent); ?>" class="text-primary hover:underline">
                                    <?php echo e(cutStr($nextContent['title'], 30)); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <!-- 侧边栏 -->
            <div class="w-full lg:w-80 space-y-6">
                <?php if (!empty($downloadSidebarCats)): ?>
                <!-- 下载分类 -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-3 border-b font-bold text-dark bg-primary text-white rounded-t-lg">下载分类</div>
                    <div class="divide-y">
                        <?php foreach ($downloadSidebarCats as $cat): ?>
                        <a href="<?php echo channelUrl($cat); ?>"
                           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo (int)$cat['id'] === (int)$content['channel_id'] ? 'text-primary bg-blue-50 font-medium' : 'text-gray-700 hover:text-primary'; ?>">
                            <?php echo e($cat['name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 相关内容 -->
                <?php if (!empty($relatedContents)): ?>
                <div class="bg-white rounded-lg shadow">
                    <div class="px-4 py-3 border-b font-bold text-dark"><?php echo __('detail_related'); ?></div>
                    <div class="p-4 space-y-4">
                        <?php foreach ($relatedContents as $item): ?>
                        <a href="<?php echo contentUrl($item); ?>" class="flex gap-3 group">
                            <?php if ($item['cover']): ?>
                            <div class="w-20 h-16 flex-shrink-0 rounded overflow-hidden">
                                <img src="<?php echo e(thumbnail($item['cover'], 'thumb')); ?>" class="w-full h-full object-cover">
                            </div>
                            <?php endif; ?>
                            <h4 class="flex-1 text-sm text-gray-700 group-hover:text-primary transition line-clamp-2">
                                <?php echo e($item['title']); ?>
                            </h4>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 联系方式 -->
                <div class="bg-white rounded-lg shadow">
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
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once INCLUDES_PATH . 'footer.php'; ?>
