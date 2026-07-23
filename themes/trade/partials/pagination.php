<?php
/**
 * Pagination partial
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
<div class="mt-8 flex items-center justify-center gap-2">
    <?php if ($page > 1): ?>
    <a href="<?php echo $pageUrl($page - 1); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
    <?php endif; ?>
    <?php
    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);
    for ($i = $start; $i <= $end; $i++):
    ?>
    <a href="<?php echo $pageUrl($i); ?>"
       class="px-4 py-2 border rounded <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-100'; ?>">
        <?php echo $i; ?>
    </a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
    <a href="<?php echo $pageUrl($page + 1); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>
