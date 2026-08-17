<?php
/**
 * Business 主题 — 分页（方角，单色，密集）
 *
 *   @var int      $page         当前页
 *   @var int      $total        总条数
 *   @var int      $perPage
 *   @var int      $totalPages
 *   @var callable $pageUrl      function(int $p): string
 */
?>
<?php if ($total > $perPage): ?>
<nav aria-label="pagination" class="mt-10 flex items-center justify-between gap-4 flex-wrap">
    <!-- 总条数 / 当前页提示 -->
    <div class="text-xs text-slate-500 font-medium tracking-wide">
        <?php
        // 「123 条 · 第 5/10 页」这种拼法只在中文成立：英文是 "123 items · Page 5 of 10"，
        // 语序和量词都不同。整句走 :占位 的 key，数字回填时套上样式（都是整数，无需转义）。
        echo strtr(__('pager_summary'), [
            ':total' => '<span class="text-slate-700 font-bold">' . number_format($total) . '</span>',
            ':page'  => '<span class="text-primary font-bold">' . (int) $page . '</span>',
            ':pages' => (int) $totalPages,
        ]);
        ?>
    </div>

    <!-- 翻页按钮组 -->
    <ul class="flex items-stretch divide-x divide-slate-200 border border-slate-200">
        <?php
        $btnBase   = 'px-3 min-w-[2.5rem] flex items-center justify-center text-sm transition select-none';
        $btnNormal = $btnBase . ' bg-white text-slate-600 hover:bg-primary hover:text-white';
        $btnActive = $btnBase . ' bg-primary text-white font-bold pointer-events-none';
        $btnMuted  = $btnBase . ' bg-slate-50 text-slate-300 cursor-not-allowed';
        ?>

        <li>
            <?php if ($page > 1): ?>
            <a href="<?php echo $pageUrl($page - 1); ?>" class="<?php echo $btnNormal; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <?php else: ?>
            <span class="<?php echo $btnMuted; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </span>
            <?php endif; ?>
        </li>

        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        if ($start > 1):
        ?>
        <li><a href="<?php echo $pageUrl(1); ?>" class="<?php echo $btnNormal; ?>">1</a></li>
        <?php if ($start > 2): ?>
        <li><span class="<?php echo $btnMuted; ?>">…</span></li>
        <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $start; $i <= $end; $i++): ?>
        <li>
            <?php if ($i === $page): ?>
            <span class="<?php echo $btnActive; ?>"><?php echo $i; ?></span>
            <?php else: ?>
            <a href="<?php echo $pageUrl($i); ?>" class="<?php echo $btnNormal; ?>"><?php echo $i; ?></a>
            <?php endif; ?>
        </li>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
        <?php if ($end < $totalPages - 1): ?>
        <li><span class="<?php echo $btnMuted; ?>">…</span></li>
        <?php endif; ?>
        <li><a href="<?php echo $pageUrl($totalPages); ?>" class="<?php echo $btnNormal; ?>"><?php echo $totalPages; ?></a></li>
        <?php endif; ?>

        <li>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo $pageUrl($page + 1); ?>" class="<?php echo $btnNormal; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
            <?php else: ?>
            <span class="<?php echo $btnMuted; ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
            <?php endif; ?>
        </li>
    </ul>
</nav>
<?php endif; ?>
