<?php
/**
 * 首页区块：合作伙伴 / 友情链接（独立区段，置于页脚之上）
 * 由 index.php 在页脚之前渲染。有 Logo 显示 Logo，否则显示名称。
 */
// 显隐由首页「区块系统」的 enabled 开关控制（见 home_blocks_config）；此处只在无数据时跳过
$links = linkModel()->getActive();
if (empty($links)) return;
$bg = getBlockBg($block ?? [], '@auto');
?>
<section class="py-12 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?><?php if (!empty($_SESSION['admin_id'])) echo ' data-yk-partners'; ?>><?php echo $bg['overlay']; ?>
    <div class="container mx-auto px-4">
        <div class="text-center mb-8" data-animate="fade-up">
            <h2 class="blk-title mb-2"><?php echo configLang('home_links_title', 'footer_partners'); ?></h2>
            <?php echo homeTitleDeco(); ?>
        </div>
        <div class="flex flex-wrap justify-center items-center gap-x-8 gap-y-5">
            <?php foreach ($links as $link): ?>
            <a href="<?php echo e($link['url']); ?>" target="_blank" rel="nofollow" title="<?php echo e($link['name']); ?>"
               class="inline-flex items-center">
                <?php if (!empty($link['logo'])): ?>
                <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes((string) $link['logo'], 'medium', '160px'); ?> alt="<?php echo e($link['name']); ?>"
                     class="h-10 object-contain grayscale hover:grayscale-0 opacity-80 hover:opacity-100 transition">
                <?php else: ?>
                <span class="text-gray-500 hover:text-primary text-sm transition"><?php echo e($link['name']); ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
