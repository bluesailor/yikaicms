    </main>

    <?php do_action('ik_footer_before'); ?>

    <!-- Footer -->
    <footer class="mt-auto border-t border-slate-800/60 bg-slate-950/50 backdrop-blur-sm relative">
        <!-- Subtle top accent line -->
        <div class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-indigo-500/50 to-transparent"></div>

        <div class="container mx-auto px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-10">
                <!-- Brand column -->
                <div class="md:col-span-2">
                    <a href="/" class="inline-flex items-center gap-2.5 group">
                        <?php $_siteName = configRawLang('site_name', 'Yikai CMS'); ?>
                        <?php $_siteLogo = configRawLang('site_logo', ''); ?>
                        <?php if ($_siteLogo): ?>
                        <img src="<?php echo e($_siteLogo); ?>" alt="<?php echo e($_siteName); ?>" class="h-7">
                        <?php else: ?>
                        <span class="w-7 h-7 rounded-lg bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white text-xs font-bold">
                            <?php echo e(mb_substr($_siteName, 0, 1)); ?>
                        </span>
                        <span class="text-sm font-semibold text-slate-100"><?php echo e($_siteName); ?></span>
                        <?php endif; ?>
                    </a>
                    <p class="mt-4 text-sm text-slate-500 leading-relaxed max-w-md">
                        <?php echo e(config('site_description', '')); ?>
                    </p>
                </div>

                <!-- Contact -->
                <?php if ($_phone = config('site_phone', '')): ?>
                <div>
                    <h4 class="text-xs uppercase tracking-wider text-slate-300 font-semibold mb-3">联系我们</h4>
                    <ul class="space-y-2 text-sm text-slate-500">
                        <?php if ($_phone): ?>
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-slate-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <span><?php echo e($_phone); ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if ($_email = config('site_email', '')): ?>
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-slate-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <a href="mailto:<?php echo e($_email); ?>" class="hover:text-white transition"><?php echo e($_email); ?></a>
                        </li>
                        <?php endif; ?>
                        <?php if ($_address = config('site_address', '')): ?>
                        <li class="flex items-start gap-2">
                            <svg class="w-4 h-4 mt-0.5 flex-shrink-0 text-slate-600" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span><?php echo e($_address); ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Quick links / nav (top 5 channels) -->
                <?php
                $_footerNav = $navChannels ?? (function_exists('getNavChannels') ? getNavChannels() : []);
                $_footerNav = array_slice($_footerNav, 0, 5);
                ?>
                <?php if ($_footerNav): ?>
                <div>
                    <h4 class="text-xs uppercase tracking-wider text-slate-300 font-semibold mb-3">导航</h4>
                    <ul class="space-y-2 text-sm">
                        <?php foreach ($_footerNav as $_nav): ?>
                        <li>
                            <a href="<?php echo channelUrl($_nav); ?>" class="text-slate-500 hover:text-white transition">
                                <?php echo e($_nav['name']); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bottom bar -->
            <div class="pt-6 border-t border-slate-800/60 flex flex-col md:flex-row items-center justify-between gap-3 text-xs text-slate-500">
                <div>
                    &copy; <?php echo date('Y'); ?> <?php echo e($_siteName); ?>. All rights reserved.
                </div>
                <div class="flex items-center gap-4">
                    <?php if (siteLang() === 'zh-CN'): ?>
                        <?php if ($icp = config('site_icp')): ?>
                        <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow" class="hover:text-slate-300 transition">
                            <?php echo e($icp); ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($police = config('site_police')): ?>
                        <a href="http://www.beian.gov.cn/" target="_blank" rel="nofollow" class="hover:text-slate-300 transition">
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
            document.getElementById('mobileMenu')?.classList.toggle('hidden');
        });
    </script>

    <!-- Lightbox -->
    <div id="ik-lightbox" class="fixed inset-0 z-[200] bg-black/90 hidden items-center justify-center cursor-zoom-out" onclick="if(event.target===this){this.classList.add('hidden');this.classList.remove('flex');document.body.style.overflow=''}">
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
