<?php
/**
 * 繁体中文要靠「繁體中文語言包」插件提供简→繁转换表（2.0.4 起不随安装包）。
 * 可以勾选繁体（装了 lang/zh-TW.php）而转换表缺失时提示安装；繁体已启用时用警示色。
 *
 * 调用前可设 $s2tNoticeEnabled（bool）：繁体是否已在前台启用。
 */
require_once ROOT_PATH . '/includes/i18n/S2T.php';
if (isset(availableLanguages()['zh-TW']) && !S2T::available()):
    $_s2tEnabled = !empty($s2tNoticeEnabled);
?>
<p data-testid="s2t-pack-notice" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded px-3 py-2 text-xs <?php echo $_s2tEnabled ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-600'; ?>">
    <i class="ti ti-language text-sm" aria-hidden="true"></i>
    <span><?php echo e(__($_s2tEnabled ? 'slang_s2t_pack_missing' : 'slang_s2t_pack_hint')); ?></span>
    <a href="/admin/plugin.php?tab=market&amp;q=<?php echo e(S2T::PACK_PLUGIN); ?>" class="text-primary hover:underline whitespace-nowrap"><?php echo e(__('slang_s2t_pack_install')); ?> →</a>
</p>
<?php endif;
