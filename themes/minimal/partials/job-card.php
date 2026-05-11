<?php
/**
 * Minimal 主题 — 招聘职位卡（行式列表 + 细线分隔）
 *
 *   @var array $item  ['title','job_type','education','experience','headcount','location','salary','publish_time']
 */
?>
<a href="<?php echo jobUrl($item); ?>"
   class="group block py-6 border-b border-gray-200 hover:border-gray-900 transition">
    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2 justify-between">
        <h3 class="text-lg font-light text-gray-900 group-hover:tracking-wide transition-all duration-300">
            <?php echo e($item['title']); ?>
        </h3>
        <?php if (!empty($item['salary'])): ?>
        <div class="text-sm font-light text-gray-900 tracking-wide">
            <?php echo e($item['salary']); ?>
        </div>
        <?php endif; ?>
    </div>

    <?php
    $meta = array_filter([
        $item['location']   ?? null,
        $item['job_type']   ?? null,
        $item['experience'] ?? null,
        $item['education']  ?? null,
        !empty($item['headcount']) ? __('job_hire_count', ['count' => (int)$item['headcount']]) : null,
    ]);
    ?>
    <?php if ($meta): ?>
    <div class="mt-3 flex flex-wrap items-center text-xs text-gray-400">
        <?php foreach ($meta as $i => $m): ?>
        <?php if ($i > 0): ?><span class="mx-3 opacity-40">·</span><?php endif; ?>
        <span><?php echo e($m); ?></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-3 flex items-center gap-2 text-xs text-gray-300 tracking-widest uppercase
                opacity-0 group-hover:opacity-100 -translate-x-1 group-hover:translate-x-0 transition duration-300">
        <span><?php echo 'View Position'; ?></span>
        <span>&rarr;</span>
    </div>
</a>
