<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) exit('Access Denied');
$visibilityChannel = $visibilityChannel ?? $page ?? [];
if (($visibilityChannel['type'] ?? '') !== 'page') return;
$visibilityType = (string) ($visibilityChannel['redirect_type'] ?? 'auto');
$visibilityTarget = '';
if ($visibilityType === 'auto') {
    $visibilityChildren = channelModel()->getByParent((int) $visibilityChannel['id'], true);
    $visibilityTarget = (string) ($visibilityChildren[0]['name'] ?? '');
} elseif ($visibilityType === 'url') {
    $visibilityTarget = (string) ($visibilityChannel['redirect_url'] ?? '');
}
if ($visibilityTarget === '') return;
?>
<div role="status" data-testid="page-visibility-notice" class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-900 shrink-0">
    <p><?= e(__('usability_page_redirect', ['name' => (string) $visibilityChannel['name'], 'target' => $visibilityTarget])) ?></p>
    <p class="mt-1"><?= e(__('usability_page_redirect_hint')) ?></p>
    <?php if (hasPermission('*')): ?>
    <a href="/admin/channel.php?edit=<?= (int) $visibilityChannel['id'] ?>#redirectType" target="_blank" rel="noopener" class="text-primary underline"><?= e(__('usability_page_redirect_settings')) ?></a>
    <?php endif; ?>
</div>
