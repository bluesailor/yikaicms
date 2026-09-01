<?php
/**
 * Minimal 主题 — 右侧导航 + 联系信息（极简，无背景色，全靠细线）
 *
 *   @var string $rightSidebarTitle
 *   @var array  $rightSidebarChannels
 *   @var int    $channelId
 *   @var ?int   $rightSidebarActiveId
 *   @var ?array $rightSidebarItems 预构建链接 [{label,url,active}]，用于下载分类等
 */
$activeId = $rightSidebarActiveId ?? $channelId;
$sidebarItems = $rightSidebarItems ?? null;
?>
<aside class="w-full lg:w-64 space-y-12">

    <div>
        <h3 class="text-xs text-gray-600 tracking-widest uppercase mb-6">
            <?php echo e($rightSidebarTitle); ?>
        </h3>
        <ul class="space-y-px">
            <?php if (is_array($sidebarItems)): ?>
            <?php foreach ($sidebarItems as $item): ?>
            <li>
                <a href="<?php echo e((string) ($item['url'] ?? '')); ?>"
                   class="group flex items-center justify-between gap-2 py-3 text-sm transition-colors border-b border-gray-100
                          <?php echo !empty($item['active'])
                              ? 'text-gray-900 font-medium'
                              : 'text-gray-600 hover:text-gray-900'; ?>">
                    <span class="truncate"><?php echo e((string) ($item['label'] ?? '')); ?></span>
                    <span aria-hidden class="text-base <?php echo !empty($item['active']) ? '' : 'opacity-0 group-hover:opacity-100'; ?> transition-opacity">&rarr;</span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($rightSidebarChannels as $sub):
                $active = (int)$sub['id'] === $activeId;
            ?>
            <li>
                <a href="<?php echo channelUrl($sub); ?>"
                   class="group flex items-center justify-between gap-2 py-3 text-sm transition-colors border-b border-gray-100
                          <?php echo $active
                              ? 'text-gray-900 font-medium'
                              : 'text-gray-600 hover:text-gray-900'; ?>">
                    <span class="truncate"><?php echo e($sub['name']); ?></span>
                    <span aria-hidden class="text-base <?php echo $active ? '' : 'opacity-0 group-hover:opacity-100'; ?> transition-opacity">&rarr;</span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <?php
    $phone   = configRawLang('contact_phone');
    $email   = configRawLang('contact_email');
    $address = configRawLang('contact_address');
    ?>
    <?php if ($phone || $email || $address): ?>
    <div>
        <h3 class="text-xs text-gray-600 tracking-widest uppercase mb-6">
            <?php echo __('footer_contact'); ?>
        </h3>
        <div class="space-y-4 text-sm text-gray-600 font-light leading-relaxed">
            <?php if ($phone): ?>
            <a href="tel:<?php echo e($phone); ?>" class="block hover:text-gray-900 transition">
                <div class="text-[10px] text-gray-500 tracking-widest uppercase mb-1"><?php echo e(__('contact_phone_label')); ?></div>
                <?php echo e($phone); ?>
            </a>
            <?php endif; ?>
            <?php if ($email): ?>
            <a href="mailto:<?php echo e($email); ?>" class="block hover:text-gray-900 transition break-all">
                <div class="text-[10px] text-gray-500 tracking-widest uppercase mb-1"><?php echo e(__('contact_email_label')); ?></div>
                <?php echo e($email); ?>
            </a>
            <?php endif; ?>
            <?php if ($address): ?>
            <div>
                <div class="text-[10px] text-gray-500 tracking-widest uppercase mb-1"><?php echo e(__('contact_address_label')); ?></div>
                <?php echo e($address); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</aside>
