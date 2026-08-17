<?php
/**
 * Aurora Theme - Pagination
 *
 * @var int $totalPages
 * @var int $currentPage
 * @var string $baseUrl  (e.g. "/list/8.html?page=" — 末尾留好 ?page= 拼接位)
 */
if (($totalPages ?? 1) <= 1) return;
$currentPage = (int)($currentPage ?? 1);
$totalPages = (int)$totalPages;
$baseUrl = $baseUrl ?? ('?page=');

$start = max(1, $currentPage - 2);
$end = min($totalPages, $currentPage + 2);
if ($end - $start < 4) {
    if ($start === 1) $end = min($totalPages, $start + 4);
    if ($end === $totalPages) $start = max(1, $end - 4);
}
?>
<nav class="flex justify-center mt-12">
    <ul class="inline-flex items-center gap-1">
        <?php if ($currentPage > 1): ?>
        <li>
            <a href="<?php echo e($baseUrl . ($currentPage - 1)); ?>"
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm text-slate-400 border border-slate-800 hover:text-white hover:border-slate-600 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </a>
        </li>
        <?php endif; ?>

        <?php if ($start > 1): ?>
        <li>
            <a href="<?php echo e($baseUrl . '1'); ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm text-slate-400 border border-slate-800 hover:text-white hover:border-slate-600 transition">1</a>
        </li>
        <?php if ($start > 2): ?>
        <li class="px-2 text-slate-600">…</li>
        <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
        <li>
            <?php if ($i === $currentPage): ?>
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm font-medium text-white bg-gradient-to-r from-indigo-500 to-purple-600 shadow-lg shadow-indigo-500/30">
                <?php echo $i; ?>
            </span>
            <?php else: ?>
            <a href="<?php echo e($baseUrl . $i); ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm text-slate-400 border border-slate-800 hover:text-white hover:border-slate-600 transition">
                <?php echo $i; ?>
            </a>
            <?php endif; ?>
        </li>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?>
        <li class="px-2 text-slate-600">…</li>
        <?php endif; ?>
        <li>
            <a href="<?php echo e($baseUrl . $totalPages); ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm text-slate-400 border border-slate-800 hover:text-white hover:border-slate-600 transition"><?php echo $totalPages; ?></a>
        </li>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
        <li>
            <a href="<?php echo e($baseUrl . ($currentPage + 1)); ?>"
               class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-sm text-slate-400 border border-slate-800 hover:text-white hover:border-slate-600 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
        </li>
        <?php endif; ?>
    </ul>
</nav>
