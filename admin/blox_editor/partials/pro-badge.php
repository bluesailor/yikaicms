<?php declare(strict_types=1); ?>
<?php // 易开网页构建器 Pro 能力的统一角标：链到授权页，授权与否都显示。 ?>
<a href="/admin/license.php" target="_blank" rel="noopener" @click.stop
   data-testid="blox-pro-badge"
   title="<?= e(__('blox_pro_badge_title')) ?>" aria-label="<?= e(__('blox_pro_badge_title')) ?>"
   class="inline-flex items-center px-1 rounded text-[9px] font-bold leading-4 tracking-wide bg-amber-500 text-white no-underline hover:bg-amber-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400">PRO</a>
