    </main>

<?php
// 页脚设置
$footerBgColor = config('footer_bg_color', '#1f2937');
$footerTextColor = config('footer_text_color', '#9ca3af');
?>

    <?php do_action('ik_footer_before'); ?>

    <!-- Footer -->
    <footer class="mt-auto border-t border-gray-200 bg-white">
        <div class="container mx-auto px-6 lg:px-8 py-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4 text-sm text-gray-400">
                <div>
                    &copy; <?php echo date('Y'); ?> <?php echo e(configRawLang('site_name', 'Yikai CMS')); ?>
                </div>
                <div class="flex items-center gap-6">
                    <?php if (siteLang() === 'zh-CN'): ?>
                        <?php if ($icp = config('site_icp')): ?>
                        <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow" class="hover:text-gray-600 transition">
                            <?php echo e($icp); ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($police = config('site_police')): ?>
                        <a href="http://www.beian.gov.cn/" target="_blank" rel="nofollow" class="hover:text-gray-600 transition">
                            <?php echo e($police); ?>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn')?.addEventListener('click', function() {
            const menu = document.getElementById('mobileMenu');
            const hamburger = document.getElementById('hamburgerIcon');
            menu?.classList.toggle('hidden');
            hamburger?.classList.toggle('active');
        });
    </script>

    <!-- Lightbox -->
    <div id="ik-lightbox" class="fixed inset-0 z-[200] bg-black/80 hidden items-center justify-center cursor-zoom-out" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');document.body.style.overflow=''}">
        <button onclick="this.parentElement.classList.add('hidden');this.parentElement.classList.remove('flex');document.body.style.overflow=''" class="absolute top-4 right-4 text-white/80 hover:text-white text-4xl leading-none cursor-pointer">&times;</button>
        <img id="ik-lightbox-img" src="" class="max-w-[90vw] max-h-[90vh] shadow-2xl" onclick="event.stopPropagation()">
    </div>
    <script>
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a[data-lightbox]');
        if (!link) return;
        if (link.dataset.lightbox === 'album') return;
        e.preventDefault();
        var box = document.getElementById('ik-lightbox');
        document.getElementById('ik-lightbox-img').src = link.href;
        box.classList.remove('hidden');
        box.classList.add('flex');
        document.body.style.overflow = 'hidden';
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var box = document.getElementById('ik-lightbox');
            if (!box.classList.contains('hidden')) {
                box.classList.add('hidden');
                box.classList.remove('flex');
                document.body.style.overflow = '';
            }
        }
    });
    </script>

    <?php if (!empty($extraJs)): ?>
    <?php echo $extraJs; ?>
    <?php endif; ?>
    <?php do_action('ik_footer_scripts'); ?>
    <?php echo config('custom_body_code', ''); ?>
</body>
</html>
