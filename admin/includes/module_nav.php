<?php
/** Shared, explicitly opted-in navigation for admin work areas. */
declare(strict_types=1);

/** @param list<array{label:string,url:string,icon:string,active:bool,testid?:string,badge?:int}> $items */
function adminModuleStart(array $items, string $label, string $testId = 'admin-module-nav'): void
{
    ?>
    <div class="admin-module-layout" data-testid="admin-module-layout">
        <aside class="admin-module-sidebar">
            <div class="admin-module-mobile">
                <label for="admin-module-select"><?= e($label) ?></label>
                <select id="admin-module-select" data-testid="admin-module-select" onchange="window.location.assign(this.value)">
                    <?php foreach ($items as $item): ?>
                    <option value="<?= e($item['url']) ?>" <?= $item['active'] ? 'selected' : '' ?>><?= e($item['label']) ?><?= !empty($item['badge']) ? ' (' . (int) $item['badge'] . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="admin-module-links" aria-label="<?= e($label) ?>" data-testid="<?= e($testId) ?>">
                <p class="admin-module-label"><?= e($label) ?></p>
                <?php foreach ($items as $item): ?>
                <a href="<?= e($item['url']) ?>" <?= $item['active'] ? 'aria-current="page"' : '' ?> data-testid="<?= e($item['testid'] ?? '') ?>">
                    <i class="ti ti-<?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span>
                    <?php if (!empty($item['badge'])): ?><span class="admin-module-badge"><?= (int) $item['badge'] ?></span><?php endif; ?>
                </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <div class="admin-module-content" data-testid="admin-module-content">
    <?php
}

function adminModuleEnd(): void
{
    echo '</div></div>';
}

/** @param array<string, array{0:string,1:string}> $tabs @param array<string,string> $context */
function adminModuleTabStart(array $tabs, string $active, string $label, string $path, array $context = []): void
{
    $items = [];
    foreach ($tabs as $id => [$title, $icon]) {
        $items[] = [
            'label' => $title,
            'url' => $path . '?' . http_build_query(['tab' => $id] + $context, '', '&', PHP_QUERY_RFC3986),
            'icon' => $icon,
            'active' => $active === $id,
            'testid' => 'admin-module-tab-' . $id,
        ];
    }
    adminModuleStart($items, $label);
}
