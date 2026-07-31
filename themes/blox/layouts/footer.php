<?php
/**
 * Blox 实验主题 - 页脚（极简）
 *
 * 必需钩子齐全：ik_footer_before（前台就地编辑覆盖层挂这里）、ik_footer_scripts。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

$siteName = $siteName ?? configRawLang('site_name', 'Yikai CMS');
?>
    </main>
    <?php do_action('ik_footer_before'); ?>
    <footer class="border-t border-gray-100 mt-16">
        <div class="max-w-6xl mx-auto px-6 py-8 text-center text-sm text-gray-400">
            <?php echo e(config('site_copyright', '') ?: ('© ' . date('Y') . ' ' . $siteName)); ?>
            <span class="ml-2 text-gray-300">· Blox 实验主题</span>
        </div>
    </footer>
    <?php if (!empty($extraJs)): ?>
    <?php echo $extraJs; ?>
    <?php endif; ?>
    <?php do_action('ik_footer_scripts'); ?>
    <?php do_action('render_footer'); ?>
    <?php echo config('custom_body_code', ''); ?>
</body>
</html>
