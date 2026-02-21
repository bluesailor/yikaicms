    </main>

<?php
// 页脚设置
$footerColumns = json_decode(config('footer_columns') ?: '[]', true) ?: [];
$footerBgColor = config('footer_bg_color', '#1f2937');
$footerBgImage = config('footer_bg_image', '');
$footerTextColor = config('footer_text_color', '#9ca3af');

// 计算总列数
$totalCols = 0;
foreach ($footerColumns as $col) {
    $totalCols += (int)($col['col_span'] ?? 1);
}
if ($totalCols < 1) $totalCols = 4;

// 占位符替换函数
function renderFooterContent(string $content): string {
    // {{site_description}}
    if (str_contains($content, '{{site_description}}')) {
        $desc = e(config('site_description', ''));
        $content = str_replace('{{site_description}}', $desc, $content);
    }

    // {{contact_info}}
    if (str_contains($content, '{{contact_info}}')) {
        $html = '<ul class="space-y-2 text-sm">';
        if ($phone = config('contact_phone')) {
            $html .= '<li class="flex items-center gap-2"><svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>' . e($phone) . '</li>';
        }
        if ($email = config('contact_email')) {
            $html .= '<li class="flex items-center gap-2"><svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>' . e($email) . '</li>';
        }
        if ($address = config('contact_address')) {
            $html .= '<li class="flex items-start gap-2"><svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>' . e($address) . '</li>';
        }
        $html .= '</ul>';
        $content = str_replace('{{contact_info}}', $html, $content);
    }

    // {{qrcode}}
    if (str_contains($content, '{{qrcode}}')) {
        $qrcode = config('contact_qrcode');
        $html = $qrcode ? '<img src="' . e($qrcode) . '" alt="二维码" class="w-24 h-24">' : '';
        $content = str_replace('{{qrcode}}', $html, $content);
    }

    // 如果内容不含HTML标签，则做 nl2br 处理
    if ($content === strip_tags($content)) {
        $content = nl2br(e($content));
    }

    return $content;
}

$footerBgStyle = 'background-color: ' . e($footerBgColor) . ';';
if ($footerBgImage) {
    $footerBgStyle .= ' background-image: url(' . e($footerBgImage) . '); background-size: cover; background-position: center;';
}
?>

    <?php do_action('ik_footer_before'); ?>

    <!-- 页脚 -->
    <footer class="mt-auto" style="<?php echo $footerBgStyle; ?> color: <?php echo e($footerTextColor); ?>">
        <div class="container mx-auto px-4 py-12">
            <?php if (!empty($footerColumns)): ?>
            <div class="grid grid-cols-1 md:grid-cols-<?php echo $totalCols; ?> gap-8">
                <?php foreach ($footerColumns as $col): ?>
                <?php $span = (int)($col['col_span'] ?? 1); ?>
                <div class="<?php echo $span > 1 ? 'md:col-span-' . $span : ''; ?>">
                    <?php if (!empty($col['title'])): ?>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo e($col['title']); ?></h3>
                    <?php endif; ?>
                    <div class="text-sm leading-relaxed">
                        <?php echo renderFooterContent($col['content'] ?? ''); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <!-- 无自定义列时的默认布局 -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div class="md:col-span-2">
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo e(config('site_name', 'Yikai CMS')); ?></h3>
                    <p class="text-sm leading-relaxed"><?php echo e(config('site_description', '')); ?></p>
                </div>
                <div>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo __('footer_contact'); ?></h3>
                    <?php echo renderFooterContent('{{contact_info}}'); ?>
                </div>
                <div>
                    <?php if (config('contact_qrcode')): ?>
                    <h3 class="text-white text-lg font-bold mb-4"><?php echo __('footer_follow'); ?></h3>
                    <?php echo renderFooterContent('{{qrcode}}'); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 合作伙伴 -->
            <?php
            $showLinks = config('home_show_links', '1') === '1';
            $links = $showLinks ? linkModel()->getActive() : [];
            if (!empty($links)):
            ?>
            <div class="border-t border-gray-700 mt-8 pt-8">
                <h4 class="text-white font-medium mb-4"><?php echo config('home_links_title', '合作伙伴'); ?></h4>
                <div class="flex flex-wrap gap-4">
                    <?php foreach ($links as $link): ?>
                    <a href="<?php echo e($link['url']); ?>" target="_blank" rel="nofollow"
                       class="text-sm hover:text-white transition">
                        <?php echo e($link['name']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 版权信息 -->
        <div class="border-t border-gray-700">
            <div class="container mx-auto px-4 py-4 flex flex-wrap gap-4 items-center justify-between text-sm">
                <div>
                    &copy; <?php echo date('Y'); ?> <?php echo e(config('site_name', 'Yikai CMS')); ?> <?php echo __('footer_copyright'); ?>.
                </div>
                <div class="flex flex-wrap gap-4">
                    <?php if ($icp = config('site_icp')): ?>
                    <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow" class="hover:text-white transition">
                        <?php echo e($icp); ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($police = config('site_police')): ?>
                    <a href="http://www.beian.gov.cn/" target="_blank" rel="nofollow" class="hover:text-white transition flex items-center gap-1">
                        <img src="/images/gaba.png" alt="" class="w-4 h-4">
                        <?php echo e($police); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // 移动端菜单切换
        document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
            const menu = document.getElementById('mobileMenu');
            const hamburger = document.getElementById('hamburgerIcon');
            menu?.classList.toggle('hidden');
            hamburger?.classList.toggle('active');
        });
    </script>

    <?php if (!empty($extraJs)): ?>
    <?php echo $extraJs; ?>
    <?php endif; ?>
    <?php do_action('ik_footer_scripts'); ?>
    <?php echo config('custom_body_code', ''); ?>
</body>
</html>
