<?php
/**
 * Job listing card partial
 *
 * Expected variables:
 * @var array $item - Job data with title, job_type, education, experience, headcount, location, salary, publish_time
 */
?>
<a href="<?php echo jobUrl($item); ?>" class="block bg-white rounded-lg shadow p-6 hover:shadow-lg transition group">
    <div class="flex flex-wrap gap-4 items-start justify-between">
        <div class="flex-1 min-w-0">
            <h3 class="text-lg font-bold text-dark group-hover:text-primary transition"><?php echo e($item['title']); ?></h3>
            <div class="flex flex-wrap gap-2 mt-2">
                <?php if ($item['job_type']): ?>
                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded"><?php echo e($item['job_type']); ?></span>
                <?php endif; ?>
                <?php if ($item['education']): ?>
                <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded"><?php echo e($item['education']); ?></span>
                <?php endif; ?>
                <?php if ($item['experience']): ?>
                <span class="px-2 py-0.5 bg-orange-100 text-orange-700 text-xs rounded"><?php echo e($item['experience']); ?></span>
                <?php endif; ?>
                <?php if ($item['headcount']): ?>
                <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs rounded">招<?php echo e($item['headcount']); ?></span>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-4 mt-2 text-sm text-gray-500">
                <?php if ($item['location']): ?>
                <span class="flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                    </svg>
                    <?php echo e($item['location']); ?>
                </span>
                <?php endif; ?>
                <span><?php echo friendlyTime((int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></span>
            </div>
        </div>
        <?php if ($item['salary']): ?>
        <div class="text-primary font-bold text-lg flex-shrink-0"><?php echo e($item['salary']); ?></div>
        <?php endif; ?>
    </div>
</a>
