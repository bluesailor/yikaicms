<?php
/**
 * Minimal 主题 — 右侧导航 + 联系信息（极简，无背景色，全靠细线）
 *
 *   @var string $rightSidebarTitle
 *   @var array  $rightSidebarChannels
 *   @var int    $channelId
 *   @var ?int   $rightSidebarActiveId
 */
$activeId = $rightSidebarActiveId ?? $channelId;
?>
<aside class="w-full lg:w-64 space-y-12">

    <div>
        <h3 class="text-xs text-gray-400 tracking-widest uppercase mb-6">
            <?php echo e($rightSidebarTitle); ?>
        </h3>
        <ul class="space-y-px">
            <?php foreach ($rightSidebarChannels as $sub):
                $active = (int)$sub['id'] === $activeId;
            ?>
            <li>
                <a href="<?php echo channelUrl($sub); ?>"
                   class="flex items-center justify-between gap-2 py-3 text-sm transition border-b border-gray-100
                          <?php echo $active
                              ? 'text-gray-900'
                              : 'text-gray-500 hover:text-gray-900'; ?>">
                    <span class="truncate"><?php echo e($sub['name']); ?></span>
                    <?php if ($active): ?>
                    <span aria-hidden class="text-base">&rarr;</span>
                    <?php else: ?>
                    <span aria-hidden class="text-base opacity-0 group-hover:opacity-100 transition">&rarr;</span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php
    $phone   = config('contact_phone');
    $email   = config('contact_email');
    $address = config('contact_address');
    ?>
    <?php if ($phone || $email || $address): ?>
    <div>
        <h3 class="text-xs text-gray-400 tracking-widest uppercase mb-6">
            <?php echo __('footer_contact'); ?>
        </h3>
        <div class="space-y-4 text-sm text-gray-600 font-light leading-relaxed">
            <?php if ($phone): ?>
            <a href="tel:<?php echo e($phone); ?>" class="block hover:text-gray-900 transition">
                <div class="text-[10px] text-gray-300 tracking-widest uppercase mb-1">Tel</div>
                <?php echo e($phone); ?>
            </a>
            <?php endif; ?>
            <?php if ($email): ?>
            <a href="mailto:<?php echo e($email); ?>" class="block hover:text-gray-900 transition break-all">
                <div class="text-[10px] text-gray-300 tracking-widest uppercase mb-1">Mail</div>
                <?php echo e($email); ?>
            </a>
            <?php endif; ?>
            <?php if ($address): ?>
            <div>
                <div class="text-[10px] text-gray-300 tracking-widest uppercase mb-1">Address</div>
                <?php echo e($address); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</aside>
