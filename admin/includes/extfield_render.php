<?php
/**
 * Yikai CMS - 扩展字段渲染
 *
 * 在 content_edit / product_edit 等表单中 include 本文件，
 * 会自动渲染指定 owner_type 的扩展字段，并回填 yikai_metas 里的值。
 *
 * 调用前需要设置:
 *   $extFieldOwnerType (string) - 'content' | 'product'
 *   $extFieldOwnerId   (int)    - 当前资源 ID（新建时为 0）
 *
 * 表单提交时使用 name="ext_fields[<field_key>]"，保存逻辑由 content_edit.php/product_edit.php 处理。
 */

if (!defined('ROOT_PATH')) exit('Access Denied');

$extFieldOwnerType = $extFieldOwnerType ?? '';
$extFieldOwnerId   = (int)($extFieldOwnerId ?? 0);

if (!in_array($extFieldOwnerType, ['content', 'product'], true)) {
    return;
}

try {
    if (!db()->tableExists('extfields')) return;
} catch (\Throwable $e) {
    return;
}

$extFields = extFieldModel()->getByOwner($extFieldOwnerType, true);
if (empty($extFields)) return;

$extValues = $extFieldOwnerId > 0 ? getAllMeta($extFieldOwnerType, $extFieldOwnerId) : [];

// 解析 options：支持 JSON 或每行 "key|label"
$parseOptions = function (?string $raw): array {
    if (!$raw) return [];
    $raw = trim($raw);
    if ($raw === '') return [];
    if ($raw[0] === '{') {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    $out = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '|') !== false) {
            [$k, $v] = explode('|', $line, 2);
            $out[trim($k)] = trim($v);
        } else {
            $out[$line] = $line;
        }
    }
    return $out;
};
?>

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b flex items-center">
        <h3 class="font-bold text-gray-800">扩展字段</h3>
        <a href="/admin/extfield.php?owner_type=<?php echo e($extFieldOwnerType); ?>" target="_blank" class="ml-auto text-xs text-gray-400 hover:text-primary">管理字段 →</a>
    </div>
    <div class="p-6 space-y-4">
        <?php foreach ($extFields as $f):
            $key = $f['field_key'];
            $val = $extValues[$key] ?? '';
            $required = ((int)$f['is_required']) ? 'required' : '';
            $requiredMark = ((int)$f['is_required']) ? '<span class="text-red-500">*</span>' : '';
            $ph = e($f['placeholder']);
            $nameAttr = 'ext_fields[' . e($key) . ']';
        ?>
        <div>
            <label class="block text-gray-700 mb-1"><?php echo e($f['field_name']); ?> <?php echo $requiredMark; ?></label>

            <?php if ($f['field_type'] === 'textarea'): ?>
                <textarea name="<?php echo $nameAttr; ?>" rows="3" placeholder="<?php echo $ph; ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>><?php echo e($val); ?></textarea>

            <?php elseif ($f['field_type'] === 'richtext'): ?>
                <textarea name="<?php echo $nameAttr; ?>" rows="6" placeholder="<?php echo $ph; ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>><?php echo e($val); ?></textarea>

            <?php elseif ($f['field_type'] === 'number'): ?>
                <input type="number" name="<?php echo $nameAttr; ?>" value="<?php echo e($val); ?>" placeholder="<?php echo $ph; ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>>

            <?php elseif ($f['field_type'] === 'date'): ?>
                <input type="date" name="<?php echo $nameAttr; ?>" value="<?php echo e($val); ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>>

            <?php elseif ($f['field_type'] === 'switch'): ?>
                <select name="<?php echo $nameAttr; ?>" class="w-full border rounded px-4 py-2">
                    <option value="0" <?php echo (string)$val === '0' ? 'selected' : ''; ?>>关</option>
                    <option value="1" <?php echo (string)$val === '1' ? 'selected' : ''; ?>>开</option>
                </select>

            <?php elseif ($f['field_type'] === 'select'): ?>
                <?php $opts = $parseOptions($f['options']); ?>
                <select name="<?php echo $nameAttr; ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>>
                    <option value="">-- 请选择 --</option>
                    <?php foreach ($opts as $k => $label): ?>
                    <option value="<?php echo e((string)$k); ?>" <?php echo (string)$val === (string)$k ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($f['field_type'] === 'multi_select'): ?>
                <?php
                    $opts = $parseOptions($f['options']);
                    $selectedArr = $val ? explode(',', (string)$val) : [];
                ?>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($opts as $k => $label): ?>
                    <label class="inline-flex items-center gap-1 text-sm">
                        <input type="checkbox" name="<?php echo $nameAttr; ?>[]" value="<?php echo e((string)$k); ?>" <?php echo in_array((string)$k, $selectedArr, true) ? 'checked' : ''; ?>>
                        <?php echo e($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($f['field_type'] === 'image' || $f['field_type'] === 'images'): ?>
                <input type="text" name="<?php echo $nameAttr; ?>" value="<?php echo e($val); ?>" placeholder="<?php echo $ph ?: '图片 URL（支持多个，英文逗号分隔）'; ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>>
                <?php if ($val): ?>
                <div class="mt-2 flex flex-wrap gap-2">
                    <?php foreach (explode(',', (string)$val) as $imgUrl): $imgUrl = trim($imgUrl); if (!$imgUrl) continue; ?>
                    <img src="<?php echo e($imgUrl); ?>" class="h-16 rounded border">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            <?php else: // text ?>
                <input type="text" name="<?php echo $nameAttr; ?>" value="<?php echo e($val); ?>" placeholder="<?php echo $ph; ?>" class="w-full border rounded px-4 py-2" <?php echo $required; ?>>
            <?php endif; ?>

            <?php if (!empty($f['help_text'])): ?>
            <p class="text-xs text-gray-400 mt-1"><?php echo e($f['help_text']); ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
