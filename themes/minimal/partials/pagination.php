<?php
/**
 * Minimal Theme - Pagination
 *
 * Expected variables:
 * @var int $page - Current page number
 * @var int $total - Total number of items
 * @var int $perPage - Items per page
 * @var int $totalPages - Total number of pages (pre-calculated)
 * @var callable $pageUrl - Function that takes page number and returns URL string
 */
?>
<?php if ($total > $perPage): ?>
<div class="mt-12 flex items-center justify-center gap-1">
    <?php if ($page > 1): ?>
    <a href="<?php echo $pageUrl($page - 1); ?>" class="px-4 py-2 text-sm text-gray-400 border border-gray-200 hover:border-gray-400 hover:text-gray-900 transition"><?php echo __('list_prev_page'); ?></a>
    <?php endif; ?>
    <?php
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?>
    <a href="<?php echo $pageUrl($i); ?>"
       class="px-4 py-2 text-sm border transition <?php echo $i === $page ? 'bg-gray-900 text-white border-gray-900' : 'text-gray-400 border-gray-200 hover:border-gray-400 hover:text-gray-900'; ?>">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="<?php echo $pageUrl($page + 1); ?>" class="px-4 py-2 text-sm text-gray-400 border border-gray-200 hover:border-gray-400 hover:text-gray-900 transition"><?php echo __('list_next_page'); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>
