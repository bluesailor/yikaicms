<?php
declare(strict_types=1);
if (!defined('ROOT_PATH') || !hasPermission('*')) return;
require_once ROOT_PATH . '/includes/SettingSources.php';
$sourceFile = configOverrides();
$sourceRuntime = $GLOBALS['yikai_config_runtime_overrides'] ?? [];
if (!is_array($sourceRuntime)) $sourceRuntime = [];
$sourceKeys = array_unique(array_merge(array_keys($sourceFile), array_keys($sourceRuntime)));
if ($sourceKeys === []) return;
$sourceStored = settingModel()->getAll();
?>
<details class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-900" data-testid="setting-sources">
    <summary class="cursor-pointer font-medium"><?= e(__('usability_sources_title', ['count' => (string) count($sourceKeys)])) ?></summary>
    <p class="mt-2"><?= e(__('usability_sources_hint')) ?></p>
    <p class="mt-1"><?= e(__('usability_sources_private')) ?></p>
    <div class="overflow-x-auto mt-2">
        <table class="w-full text-sm">
            <thead><tr><?php foreach (['key', 'source', 'stored', 'effective'] as $sourceHeading): ?><th scope="col" class="text-left p-2"><?= e(__('usability_sources_' . $sourceHeading)) ?></th><?php endforeach; ?></tr></thead>
            <tbody><?php foreach (array_slice($sourceKeys, 0, 100) as $sourceKey): ?>
            <?php $sourceInfo = SettingSources::inspect((string) $sourceKey, $sourceStored, $sourceFile, $sourceRuntime); ?>
            <tr><td class="p-2 break-all"><?= e(settingLabel((string) $sourceKey, (string) $sourceKey)) ?><br><code><?= e((string) $sourceKey) ?></code></td><td class="p-2"><?= e(__('usability_sources_' . $sourceInfo['source'])) ?></td><td class="p-2 break-all"><?= e($sourceInfo['stored']) ?></td><td class="p-2 break-all"><?= e($sourceInfo['effective']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
</details>
