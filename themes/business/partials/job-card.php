<?php
/**
 * Business 主题 — 招聘职位卡（左侧标志条 + 右侧 CTA）
 *
 *   @var array $item  ['title','job_type','education','experience','headcount','location','salary','publish_time']
 */
?>
<a href="<?php echo jobUrl($item); ?>"
   class="group flex bg-white border border-slate-200 hover:border-primary
          transition relative">
    <!-- 左侧蓝色 accent 条 -->
    <span aria-hidden class="w-1 bg-primary flex-shrink-0"></span>

    <div class="flex-1 px-6 py-5 flex flex-wrap gap-4 items-start justify-between">
        <div class="flex-1 min-w-0">
            <h3 class="text-lg font-bold text-slate-900 group-hover:text-primary transition">
                <?php echo e($item['title']); ?>
            </h3>

            <?php
            $tags = array_filter([
                $item['job_type']   ?? null,
                $item['education']  ?? null,
                $item['experience'] ?? null,
                !empty($item['headcount']) ? __('job_hire_count', ['count' => (int)$item['headcount']]) : null,
            ]);
            ?>
            <?php if ($tags): ?>
            <div class="mt-2.5 flex flex-wrap gap-2">
                <?php foreach ($tags as $tag): ?>
                <span class="px-2.5 py-0.5 bg-slate-100 border border-slate-200 text-slate-600 text-xs">
                    <?php echo e($tag); ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-slate-500">
                <?php if (!empty($item['location'])): ?>
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <?php echo e($item['location']); ?>
                </span>
                <?php endif; ?>
                <span class="flex items-center gap-1.5 text-slate-400 text-xs">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php echo friendlyTime((int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?>
                </span>
            </div>
        </div>

        <div class="flex flex-col items-end gap-2 flex-shrink-0">
            <?php if (!empty($item['salary'])): ?>
            <div class="text-primary font-bold text-xl tracking-tight">
                <?php echo e($item['salary']); ?>
            </div>
            <?php endif; ?>
            <span class="inline-flex items-center gap-1 text-xs font-medium text-slate-400 group-hover:text-primary transition">
                <?php echo '查看职位'; ?>
                <svg class="w-3.5 h-3.5 group-hover:translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>
    </div>
</a>
