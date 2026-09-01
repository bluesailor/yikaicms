<?php
/**
 * Aurora 主题 — 深色栏目导航与联系信息。
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
<aside class="w-full lg:w-64 space-y-6">
    <div class="bg-slate-900/50 border border-slate-800 rounded-lg overflow-hidden">
        <div class="bg-primary px-5 py-4 text-white font-semibold">
            <?php echo e($rightSidebarTitle); ?>
        </div>
        <ul class="divide-y divide-slate-800">
            <?php if (is_array($sidebarItems)): ?>
            <?php foreach ($sidebarItems as $item): ?>
            <li>
                <a href="<?php echo e((string) ($item['url'] ?? '')); ?>"
                   class="flex items-center justify-between gap-2 px-5 py-3.5 text-sm transition-colors
                          <?php echo !empty($item['active'])
                              ? 'bg-primary/10 text-white font-medium'
                              : 'text-slate-400 hover:bg-white/5 hover:text-white'; ?>">
                    <span class="truncate"><?php echo e((string) ($item['label'] ?? '')); ?></span>
                    <span aria-hidden class="text-violet-400">&rarr;</span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($rightSidebarChannels as $sub):
                $active = (int) $sub['id'] === $activeId;
            ?>
            <li>
                <a href="<?php echo channelUrl($sub); ?>"
                   class="flex items-center justify-between gap-2 px-5 py-3.5 text-sm transition-colors
                          <?php echo $active
                              ? 'bg-primary/10 text-white font-medium'
                              : 'text-slate-400 hover:bg-white/5 hover:text-white'; ?>">
                    <span class="truncate"><?php echo e($sub['name']); ?></span>
                    <span aria-hidden class="text-violet-400">&rarr;</span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <?php
    $phone = configRawLang('contact_phone');
    $email = configRawLang('contact_email');
    $address = configRawLang('contact_address');
    ?>
    <?php if ($phone || $email || $address): ?>
    <div class="bg-slate-900/50 border border-slate-800 rounded-lg overflow-hidden">
        <div class="bg-primary px-5 py-4 text-white font-semibold">
            <?php echo e(__('footer_contact')); ?>
        </div>
        <div class="px-5 py-5 space-y-4 text-sm text-slate-300">
            <?php if ($phone): ?>
            <a href="tel:<?php echo e($phone); ?>" class="flex items-center gap-3 hover:text-white transition-colors">
                <span class="text-violet-400"><?php echo e(__('contact_phone_label')); ?></span>
                <span class="break-all"><?php echo e($phone); ?></span>
            </a>
            <?php endif; ?>
            <?php if ($email): ?>
            <a href="mailto:<?php echo e($email); ?>" class="flex flex-col gap-1 hover:text-white transition-colors">
                <span class="text-xs text-violet-400"><?php echo e(__('contact_email_label')); ?></span>
                <span class="break-all"><?php echo e($email); ?></span>
            </a>
            <?php endif; ?>
            <?php if ($address): ?>
            <div class="flex flex-col gap-1">
                <span class="text-xs text-violet-400"><?php echo e(__('contact_address_label')); ?></span>
                <span class="leading-relaxed"><?php echo e($address); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</aside>
