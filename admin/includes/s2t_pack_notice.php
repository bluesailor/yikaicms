<?php
/**
 * 繁体中文要靠「繁體中文語言包」插件提供简→繁转换表（2.0.4 起不随安装包）。
 * 可以勾选繁体（装了 lang/zh-TW.php）而转换表缺失时提示安装；繁体已在前台启用时用警示色。
 */
require_once ROOT_PATH . '/includes/i18n/S2T.php';

function renderS2TPackNotice(bool $traditionalEnabled): void
{
    if (!isset(availableLanguages()['zh-TW']) || S2T::available()) {
        return;
    }
    $tone = $traditionalEnabled ? 'bg-amber-50 text-amber-800' : 'bg-gray-50 text-gray-600';
    $message = __($traditionalEnabled ? 'slang_s2t_pack_missing' : 'slang_s2t_pack_hint');
    ?>
<p data-testid="s2t-pack-notice" class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded px-3 py-2 text-xs <?php echo $tone; ?>">
    <i class="ti ti-language text-sm" aria-hidden="true"></i>
    <span><?php echo e($message); ?></span>
    <a href="/admin/plugin.php?tab=market&amp;q=<?php echo e(S2T::PACK_PLUGIN); ?>" class="text-primary hover:underline whitespace-nowrap"><?php echo e(__('slang_s2t_pack_install')); ?> →</a>
</p>
    <?php
}
