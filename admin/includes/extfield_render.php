<?php
/**
 * Yikai CMS - 扩展字段渲染（2.0.4 起含高级字段）
 *
 * 在内容 / 产品 / 栏目 / 产品分类 / 选项页的编辑表单里 include 本文件，渲染该归属的字段并回填 metas 里的值。
 *
 * 调用前设置：
 *   $extFieldOwnerType (string) 归属：content / product / 自定义模型 key / channel / product_category / site
 *   $extFieldOwnerId   (int)    当前条目 id（新建为 0；选项页为 0）
 *   $extFieldTermId    (int)    可选：当前条目所在分类 / 栏目 id（挂载位置判断）
 *   $extFieldTermInput (string) 可选：表单里分类 / 栏目输入框的 CSS 选择器（切换时即时显隐挂载字段）
 *   $extFieldBare      (bool)   可选：只输出字段，不带外层卡片（选项页自己有外框）
 *   $extFieldNoScripts (bool)   可选：不输出脚本标签（弹窗里由接口取片段时，页面已用 efScriptsHtml() 加载脚本）
 *
 * 提交：name="ext_fields[<键>]"（组 / 链接是 [键][子键]，重复器是 [键][行][子键]）；
 * 保存统一走 ExtFields::save()（各编辑页的保存分支里调用）。
 */

if (!defined('ROOT_PATH')) exit('Access Denied');

$extFieldOwnerType = (string) ($extFieldOwnerType ?? '');
$extFieldOwnerId = (int) ($extFieldOwnerId ?? 0);
$extFieldTermId = (int) ($extFieldTermId ?? 0);
$extFieldTermInput = (string) ($extFieldTermInput ?? '');
$extFieldBare = !empty($extFieldBare);
$extFieldNoScripts = !empty($extFieldNoScripts);

if (!in_array($extFieldOwnerType, ExtFields::owners(), true)) {
    return;
}
$extFields = ExtFields::fields($extFieldOwnerType, true);
if ($extFields === []) {
    return;
}
$extValues = $extFieldOwnerId > 0 || $extFieldOwnerType === 'site' ? getAllMeta($extFieldOwnerType, $extFieldOwnerId) : [];

require_once __DIR__ . '/extfield_helpers.php';

$efValuesForConditions = [];
foreach ($extFields as $f) {
    $efValuesForConditions[$f['field_key']] = (string) ($extValues[$f['field_key']] ?? '');
}
?>

<?php if (!$extFieldBare): ?>
<div class="bg-white rounded-lg shadow" data-ef-form data-ef-owner="<?php echo e($extFieldOwnerType); ?>"<?php echo $extFieldTermInput !== '' ? ' data-ef-term-input="' . e($extFieldTermInput) . '"' : ''; ?>>
    <div class="px-6 py-4 border-b flex items-center">
        <h3 class="font-bold text-gray-800"><?php echo e(__('ef_box_title')); ?></h3>
        <?php if (hasPermission('*')): ?>
        <a href="/admin/extfield.php?owner_type=<?php echo e($extFieldOwnerType); ?>" target="_blank" class="ms-auto text-xs text-gray-400 hover:text-primary"><?php echo e(__('ef_manage_link')); ?></a>
        <?php endif; ?>
    </div>
    <div class="p-6 space-y-5">
<?php else: ?>
<div class="space-y-5" data-ef-form data-ef-owner="<?php echo e($extFieldOwnerType); ?>">
<?php endif; ?>
        <?php foreach ($extFields as $f):
            $key = (string) $f['field_key'];
            $type = (string) $f['field_type'];
            $val = (string) ($extValues[$key] ?? '');
            $name = 'ext_fields[' . $key . ']';
            $required = (int) $f['is_required'] === 1;
            $config = (array) $f['config'];
            $location = !empty($config['location']) ? ExtFields::locationTerms($config['location'], $extFieldOwnerType) : [];
            $visible = ExtFields::locationMatches($f, $extFieldOwnerType, $extFieldTermId) && ExtFields::conditionsMatch($f, $efValuesForConditions);
        ?>
        <div class="<?php echo $visible ? '' : 'hidden'; ?>" data-ef-field="<?php echo e($key); ?>"
             <?php if (!empty($config['conditions'])): ?>data-ef-conditions="<?php echo e((string) json_encode($config['conditions'], JSON_UNESCAPED_UNICODE)); ?>"<?php endif; ?>
             <?php if ($location !== []): ?>data-ef-location="<?php echo e(implode(',', $location)); ?>"<?php endif; ?>>
            <label class="block text-gray-700 mb-1" for="ef_<?php echo e($key); ?>"><?php echo e((string) $f['field_name']); ?><?php echo $required ? ' <span class="text-red-500">*</span>' : ''; ?></label>

            <?php if ($type === 'repeater'):
                $subs = (array) ($config['sub_fields'] ?? []);
                $rows = ExtFields::decode('repeater', $val);
                $buttonLabel = (string) ($config['button_label'] ?? '') ?: __('ef_add_row');
            ?>
            <div data-ef-repeater data-name="<?php echo e($name); ?>" data-min="<?php echo (int) ($config['min'] ?? 0); ?>" data-max="<?php echo (int) ($config['max'] ?? 0); ?>">
                <input type="hidden" name="<?php echo e(efPresentName($name)); ?>" value="1">
                <div class="space-y-2" data-ef-rows>
                    <?php foreach ($rows as $i => $row): echo efRepeaterRowHtml($subs, $name, (string) $i, $row, 'ef_' . $key); endforeach; ?>
                </div>
                <template data-ef-row-template><?php echo efRepeaterRowHtml($subs, $name, '__INDEX__', [], 'ef_' . $key); ?></template>
                <button type="button" class="mt-2 inline-flex items-center gap-1 rounded border border-dashed border-gray-300 px-3 py-1.5 text-sm text-gray-600 hover:border-primary hover:text-primary" data-ef-row-add>
                    <i class="ti ti-plus"></i> <?php echo e($buttonLabel); ?>
                </button>
                <?php if ($subs === []): ?><p class="mt-1 text-xs text-amber-600"><?php echo e(__('ef_no_sub_fields')); ?></p><?php endif; ?>
            </div>

            <?php elseif ($type === 'group'):
                $groupValues = ExtFields::decode('group', $val);
            ?>
            <div class="rounded border p-3"><?php echo efSubFieldsHtml((array) ($config['sub_fields'] ?? []), $name, is_array($groupValues) ? $groupValues : [], 'ef_' . $key); ?></div>

            <?php elseif ($type === 'relationship'): ?>
            <?php echo efRelationshipHtml($f, $name, $val); ?>

            <?php else: ?>
            <?php echo efInputHtml($f, $name, $val, 'ef_' . $key, $required && $visible && !in_array($type, ['richtext', 'image', 'images', 'multi_select', 'switch'], true)); ?>
            <?php endif; ?>

            <?php if (!empty($f['help_text'])): ?>
            <p class="text-xs text-gray-400 mt-1"><?php echo e((string) $f['help_text']); ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
<?php if (!$extFieldBare): ?>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>
<?php if (!$extFieldNoScripts) echo efScriptsHtml(); ?>
