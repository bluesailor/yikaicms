<?php
/**
 * Business 主题 — 右侧导航 + 联系卡（深色 header + 锐利边框）
 *
 *   @var string $rightSidebarTitle
 *   @var array  $rightSidebarChannels
 *   @var int    $channelId
 *   @var ?int   $rightSidebarActiveId  覆盖高亮 id
 *   @var ?array $rightSidebarItems     预构建链接 [{label,url,active}]，用于下载分类等
 */
$activeId = $rightSidebarActiveId ?? $channelId;
$sidebarItems = $rightSidebarItems ?? null;
?>
<aside class="w-full lg:w-72 space-y-6<?php echo is_array($sidebarItems) ? ' business-download-sidebar' : ''; ?>">

    <!-- 子栏目导航 -->
    <div class="bg-white border border-slate-200">
        <div class="bg-slate-900 px-5 py-3 flex items-center gap-3">
            <span class="block w-1 h-5 bg-primary"></span>
            <span class="text-white font-bold tracking-wide uppercase text-sm">
                <?php echo e($rightSidebarTitle); ?>
            </span>
        </div>
        <ul class="divide-y divide-slate-100">
            <?php if (is_array($sidebarItems)): ?>
            <?php foreach ($sidebarItems as $item): ?>
            <li>
                <a href="<?php echo e((string) ($item['url'] ?? '')); ?>"
                   class="flex items-center justify-between gap-2 px-5 py-3 text-sm transition
                          <?php echo !empty($item['active'])
                              ? 'bg-primary/5 text-primary font-bold border-l-2 border-primary -ml-px'
                              : 'text-slate-700 hover:bg-slate-50 hover:text-primary border-l-2 border-transparent'; ?>">
                    <span class="truncate"><?php echo e((string) ($item['label'] ?? '')); ?></span>
                    <svg class="w-3.5 h-3.5 flex-shrink-0 <?php echo !empty($item['active']) ? '' : 'opacity-40'; ?>" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </a>
            </li>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($rightSidebarChannels as $sub):
                $active = (int)$sub['id'] === $activeId;
            ?>
            <li>
                <a href="<?php echo channelUrl($sub); ?>"
                   class="flex items-center justify-between gap-2 px-5 py-3 text-sm transition
                          <?php echo $active
                              ? 'bg-primary/5 text-primary font-bold border-l-2 border-primary -ml-px'
                              : 'text-slate-700 hover:bg-slate-50 hover:text-primary border-l-2 border-transparent'; ?>">
                    <span class="truncate"><?php echo e($sub['name']); ?></span>
                    <svg class="w-3.5 h-3.5 flex-shrink-0 <?php echo $active ? '' : 'opacity-40'; ?>" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                </a>
            </li>
            <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>

    <!-- 联系卡：深色块 -->
    <?php
    $phone   = configRawLang('contact_phone');
    $email   = configRawLang('contact_email');
    $address = configRawLang('contact_address');
    $hasContact = $phone || $email || $address;
    ?>
    <?php if ($hasContact): ?>
    <div class="bg-slate-900 text-slate-200">
        <div class="px-5 py-5">
            <div class="flex items-center gap-3 mb-4">
                <span class="block w-1 h-5 bg-primary"></span>
                <span class="text-white font-bold tracking-wide uppercase text-sm">
                    <?php echo __('footer_contact'); ?>
                </span>
            </div>

            <div class="space-y-3 text-sm">
                <?php if ($phone): ?>
                <a href="tel:<?php echo e($phone); ?>" class="flex items-center gap-2 hover:text-white transition">
                    <svg class="w-4 h-4 text-primary flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    <span class="font-mono tracking-wide"><?php echo e($phone); ?></span>
                </a>
                <?php endif; ?>
                <?php if ($email): ?>
                <a href="mailto:<?php echo e($email); ?>" class="flex items-center gap-2 hover:text-white transition break-all">
                    <svg class="w-4 h-4 text-primary flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span class="font-mono"><?php echo e($email); ?></span>
                </a>
                <?php endif; ?>
                <?php if ($address): ?>
                <div class="flex items-start gap-2">
                    <svg class="w-4 h-4 text-primary flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span class="leading-relaxed"><?php echo e($address); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</aside>
