    </main>

<?php
$siteName = config('site_name', 'Yikai CMS');
// lang-aware：根据当前 siteLang 自动读 footer_nav_en / footer_nav_ja
$footerNavRaw = function_exists('configJsonLang')
    ? configJsonLang('footer_nav')
    : (config('footer_nav') ?: '');
$footerNav = $footerNavRaw ? (json_decode($footerNavRaw, true) ?: []) : [];
?>

    <!-- CTA Contact Area -->
    <?php if (!isset($hideCta) || !$hideCta): ?>
    <section class="cta-gradient py-16 text-white text-center">
        <div class="container mx-auto px-4">
            <h2 class="text-3xl font-bold mb-4"><?php echo e(config('home_cta_title', '') ?: __('home_cta_title')); ?></h2>
            <p class="text-xl opacity-90 mb-8"><?php echo e(config('home_cta_desc', '') ?: __('home_cta_desc')); ?></p>
            <?php if ($qrcode = config('contact_qrcode')): ?>
            <div class="inline-block bg-white p-3 rounded-xl mb-4">
                <img src="<?php echo e($qrcode); ?>" alt="QR Code" class="w-32 h-32">
            </div>
            <?php endif; ?>
            <?php if ($phone = configRawLang('contact_phone')): ?>
            <p class="opacity-80 mb-6"><?php echo __('contact_phone'); ?>：<?php echo e($phone); ?></p>
            <?php endif; ?>
            <a href="/contact.html" class="inline-block bg-white text-primary hover:bg-gray-100 px-8 py-3 rounded-full font-bold transition">
                <?php echo __('detail_consult'); ?>
            </a>
        </div>
    </section>
    <?php endif; ?>

    <!-- footer -->
    <footer class="bg-slate-900 text-gray-400">
        <?php if (!empty($footerNav)): ?>
        <div class="border-b border-slate-800">
            <div class="container mx-auto px-4 py-4">
                <div class="flex flex-wrap gap-x-8 gap-y-3 justify-center text-sm">
                    <?php foreach ($footerNav as $group): ?>
                    <?php foreach (($group['links'] ?? []) as $li => $link): ?>
                    <?php if ($li > 0): ?><span class="opacity-30">|</span><?php endif; ?>
                    <a href="<?php echo e($link['url']); ?>" class="hover:text-white transition"><?php echo e($link['name']); ?></a>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="container mx-auto px-4 py-6">
            <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
                <div>
                    <?php
                    $copyrightTpl = function_exists('configRawLang') ? configRawLang('footer_copyright_text', '') : config('footer_copyright_text', '');
                    if ($copyrightTpl) {
                        echo e(str_replace(['{year}', '{site_name}'], [date('Y'), $siteName], $copyrightTpl));
                    } else {
                        echo '&copy; ' . date('Y') . ' ' . e($siteName) . ' ' . __('footer_copyright') . '.';
                    }
                    ?>
                </div>
                <div class="flex flex-wrap gap-4">
                    <?php if ($icp = config('site_icp')): ?>
                    <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow" class="hover:text-white transition"><?php echo e($icp); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script>
    document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
        document.getElementById('mobileMenu').classList.toggle('hidden');
    });

    // 滚动动画
    (function() {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animated');
                    observer.unobserve(entry.target);
                    entry.target.querySelectorAll('.stat-number[data-count]').forEach(function(el) {
                        animateNumber(el);
                    });
                }
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

        document.querySelectorAll('[data-animate], [data-stagger]').forEach(function(el) {
            observer.observe(el);
        });

        function animateNumber(el) {
            var text = el.dataset.count;
            var num = parseInt(text.replace(/[^0-9]/g, ''));
            if (!num || num < 2) return;
            var suffix = text.replace(/[0-9]/g, '');
            var duration = Math.min(1500, Math.max(800, num * 2));
            var start = performance.now();
            function tick(now) {
                var progress = Math.min((now - start) / duration, 1);
                var eased = 1 - Math.pow(1 - progress, 3);
                el.textContent = Math.floor(num * eased) + suffix;
                if (progress < 1) requestAnimationFrame(tick);
                else el.textContent = text;
            }
            requestAnimationFrame(tick);
        }
    })();
    </script>
    <?php if (!empty($extraJs)): ?><?php echo $extraJs; ?><?php endif; ?>
    <?php do_action('ik_footer_scripts'); ?>
    <?php do_action('render_footer'); ?>
    <?php do_action('ik_footer_before'); ?>
    <?php echo config('custom_body_code', ''); ?>
</body>
</html>
