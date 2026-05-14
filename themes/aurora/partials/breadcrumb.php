<?php
/**
 * Aurora Theme - Breadcrumb
 *
 * @var array $breadcrumbs - [{name, url}]
 */
if (empty($breadcrumbs)) return;
?>
<nav aria-label="Breadcrumb" class="py-4 border-b border-slate-800/40">
    <div class="container mx-auto px-6 lg:px-8">
        <ol class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <li>
                <a href="/" class="hover:text-indigo-300 transition">
                    <?php echo __('breadcrumb_home'); ?>
                </a>
            </li>
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <li class="flex items-center gap-2">
                <svg class="w-3 h-3 text-slate-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                <?php if ($i === count($breadcrumbs) - 1 || empty($crumb['url'])): ?>
                <span class="text-slate-300"><?php echo e($crumb['name']); ?></span>
                <?php else: ?>
                <a href="<?php echo e($crumb['url']); ?>" class="hover:text-indigo-300 transition"><?php echo e($crumb['name']); ?></a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ol>
    </div>
</nav>
