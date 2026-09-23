<?php
/**
 * 「站点URL」输入框下方的提示：当前访问地址 + 一键填入 + 与实际地址对不上时的提醒。
 *
 * 判定与站点体检共用 SiteAddress::inspect()，这里只负责展示。
 *
 * @var array<string,mixed> $item  setting.php 渲染循环里的当前设置项（key 为 site_url）
 */

declare(strict_types=1);

require_once ROOT_PATH . '/includes/SiteAddress.php';

$__siteUrlCheck = SiteAddress::inspect((string) ($item['value'] ?? ''), SiteAddress::current());
$__siteUrlTone = match ($__siteUrlCheck['status']) {
    SiteAddress::LOCAL_LEAK => 'text-red-700',
    SiteAddress::MISMATCH => 'text-amber-700',
    default => 'text-gray-500',
};
$__siteUrlNote = match ($__siteUrlCheck['status']) {
    SiteAddress::LOCAL_LEAK => __('site_url_local_leak'),
    SiteAddress::MISMATCH => __('site_url_mismatch'),
    SiteAddress::EMPTY => __('site_url_auto'),
    default => '',
};
?>
<div class="mt-2 space-y-1 text-xs" data-testid="site-url-hint">
    <?php if ($__siteUrlCheck['current'] !== ''): ?>
    <div class="flex flex-wrap items-center gap-2 text-gray-500">
        <span><?= e(__('site_url_current')) ?>：</span>
        <code class="rounded bg-gray-100 px-1.5 py-0.5 text-gray-700"><?= e($__siteUrlCheck['current']) ?></code>
        <?php if ($__siteUrlCheck['status'] !== SiteAddress::MATCH): ?>
        <button type="button" class="text-primary hover:underline" data-testid="site-url-use-current"
                data-value="<?= e($__siteUrlCheck['current']) ?>"
                onclick="var i=document.querySelector('input[name=&quot;settings[site_url]&quot;]');if(i){i.value=this.dataset.value;i.dispatchEvent(new Event('input',{bubbles:true}));}">
            <?= e(__('site_url_use_current')) ?>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($__siteUrlNote !== ''): ?>
    <p class="<?= $__siteUrlTone ?>" data-testid="site-url-status" data-status="<?= e($__siteUrlCheck['status']) ?>"><?= e($__siteUrlNote) ?></p>
    <?php endif; ?>
    <p class="text-gray-400"><?= e(__('site_url_scope')) ?></p>
</div>
