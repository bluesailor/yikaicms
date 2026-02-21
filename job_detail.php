<?php
/**
 * Yikai CMS - 招聘详情页
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

// 获取职位
$job = jobModel()->find($id);

if (!$job || (int)$job['status'] !== 1) {
    header('HTTP/1.1 404 Not Found');
    exit('职位不存在');
}

// 更新浏览量
jobModel()->incrementViews($id);

// 获取招聘栏目
$channel = null;
$channels = channelModel()->where(['type' => 'job', 'status' => 1]);
if (!empty($channels)) {
    $channel = $channels[0];
}

// 页面信息
$pageTitle = $job['title'];
$pageKeywords = $channel['seo_keywords'] ?? config('site_keywords');
$pageDescription = $job['summary'] ?: cutStr(strip_tags($job['content'] ?? ''), 150);
$currentChannelId = $channel ? (int)$channel['id'] : 0;

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
            <?php if ($channel): ?>
            <span>/</span>
            <a href="<?php echo channelUrl($channel); ?>" class="hover:text-primary"><?php echo e($channel['name']); ?></a>
            <?php endif; ?>
            <span>/</span>
            <span class="text-gray-400 truncate max-w-xs"><?php echo e($job['title']); ?></span>
        </div>
    </div>
</div>

<section class="py-12">
    <div class="container mx-auto px-4">
        <div class="max-w-4xl mx-auto">
            <article class="bg-white rounded-lg shadow overflow-hidden">
                <!-- 标题区 -->
                <div class="p-6 md:p-8 border-b">
                    <h1 class="text-2xl md:text-3xl font-bold text-dark leading-tight">
                        <?php echo e($job['title']); ?>
                    </h1>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <?php if ($job['salary']): ?>
                        <span class="text-primary font-bold text-xl"><?php echo e($job['salary']); ?></span>
                        <?php endif; ?>
                        <?php if ($job['location']): ?>
                        <span class="flex items-center gap-1 text-gray-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                            </svg>
                            <?php echo e($job['location']); ?>
                        </span>
                        <?php endif; ?>
                        <span class="text-sm text-gray-400"><?php echo friendlyTime((int)$job['publish_time']); ?></span>
                    </div>
                </div>

                <!-- 招聘信息 -->
                <div class="p-6 md:p-8 border-b bg-blue-50">
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <?php if ($job['salary']): ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">薪资待遇</div>
                            <div class="text-primary font-bold"><?php echo e($job['salary']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($job['location']): ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">工作地点</div>
                            <div class="font-medium"><?php echo e($job['location']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($job['job_type']): ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">工作性质</div>
                            <div class="font-medium"><?php echo e($job['job_type']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($job['headcount']): ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">招聘人数</div>
                            <div class="font-medium"><?php echo e($job['headcount']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($job['education']): ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">学历要求</div>
                            <div class="font-medium"><?php echo e($job['education']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($job['experience']): ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">经验要求</div>
                            <div class="font-medium"><?php echo e($job['experience']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="text-xs text-gray-500 mb-1">发布时间</div>
                            <div class="font-medium"><?php echo date('Y-m-d', (int)$job['publish_time']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- 任职要求 -->
                <?php if ($job['requirements']): ?>
                <div class="p-6 md:p-8 border-b">
                    <h2 class="text-lg font-bold text-dark mb-4">任职要求</h2>
                    <div class="prose max-w-none text-gray-700 whitespace-pre-line"><?php echo e($job['requirements']); ?></div>
                </div>
                <?php endif; ?>

                <!-- 职位详情 -->
                <?php if ($job['content']): ?>
                <div class="p-6 md:p-8 prose prose-lg max-w-none">
                    <?php echo $job['content']; ?>
                </div>
                <?php endif; ?>

                <!-- 返回列表 -->
                <div class="p-6 md:p-8 border-t bg-gray-50 text-center">
                    <?php if ($channel): ?>
                    <a href="<?php echo channelUrl($channel); ?>" class="inline-flex items-center gap-2 text-primary hover:underline">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        返回招聘列表
                    </a>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </div>
</section>

<?php require_once INCLUDES_PATH . 'footer.php'; ?>
