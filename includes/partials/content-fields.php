<?php
/**
 * 前台：渲染内容的扩展字段（自定义模型 / 内置 content 字段）。
 *
 * 调用前设 $fieldContent（含 id + type）或复用 $content。有值才渲染，无字段/无值静默跳过。
 * owner_type 与保存端一致（resolveExtFieldOwner），保证前后台字段对应。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

$_fc = $fieldContent ?? ($content ?? null);
if (!is_array($_fc) || empty($_fc['id'])) {
    return;
}

try {
    if (!db()->tableExists('extfields')) {
        return;
    }
} catch (\Throwable $e) {
    return;
}

$_owner = function_exists('resolveExtFieldOwner') ? resolveExtFieldOwner((string) ($_fc['type'] ?? '')) : 'content';
$_defs  = extFieldModel()->getByOwner($_owner, true);
if (empty($_defs)) {
    return;
}

$_vals = getAllMeta($_owner, (int) $_fc['id']);
$_rows = [];
foreach ($_defs as $_f) {
    $_v = $_vals[$_f['field_key']] ?? '';
    if ($_v === '' || $_v === null) {
        continue;
    }
    $_rows[] = [$_f, (string) $_v];
}
if (empty($_rows)) {
    return;
}
?>
<div class="px-6 md:px-8 pb-6">
    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm border-t pt-6">
        <?php foreach ($_rows as [$_f, $_v]): ?>
        <div class="flex gap-3<?php echo in_array($_f['field_type'], ['textarea', 'richtext', 'images'], true) ? ' sm:col-span-2' : ''; ?>">
            <dt class="text-gray-500 shrink-0 min-w-[5rem]"><?php echo e($_f['field_name']); ?></dt>
            <dd class="text-gray-800 flex-1">
                <?php if ($_f['field_type'] === 'image'): ?>
                    <img src="<?php echo e($_v); ?>" alt="<?php echo e($_f['field_name']); ?>" class="max-h-40 rounded border">
                <?php elseif ($_f['field_type'] === 'images'): ?>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach (explode(',', $_v) as $_img): $_img = trim($_img); if ($_img === '') continue; ?>
                        <img src="<?php echo e($_img); ?>" alt="" class="max-h-32 rounded border">
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($_f['field_type'] === 'textarea'): ?>
                    <span class="whitespace-pre-line"><?php echo e($_v); ?></span>
                <?php elseif ($_f['field_type'] === 'richtext'): ?>
                    <div class="prose prose-sm max-w-none"><?php echo renderContent($_v); ?></div>
                <?php elseif ($_f['field_type'] === 'switch'): ?>
                    <?php echo $_v === '1' ? '✓' : '✗'; ?>
                <?php else: ?>
                    <?php echo e($_v); ?>
                <?php endif; ?>
            </dd>
        </div>
        <?php endforeach; ?>
    </dl>
</div>
