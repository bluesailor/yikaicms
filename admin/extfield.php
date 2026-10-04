<?php
/**
 * Yikai CMS - 扩展字段管理（2.0.4 起含高级字段）
 *
 * 按归属维护字段定义：内容 / 产品 / 自定义模型，以及专业版的栏目、产品分类、全站选项页。
 * 字段值通过 MetaModel 存入 yikai_metas；类型专属配置（子字段、关联目标、条件逻辑、挂载位置）见 ExtFields。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$owners = ExtFields::owners();
$ownerType = (string) get('owner_type', 'content');
if (!in_array($ownerType, $owners, true)) {
    $ownerType = 'content';
}
$proAllowed = ExtFields::proAllowed();

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'owner_type'  => post('owner_type', 'content'),
            'field_key'   => post('field_key'),
            'field_name'  => post('field_name'),
            'field_type'  => post('field_type', 'text'),
            'options'     => post('options'),
            'placeholder' => post('placeholder'),
            'help_text'   => post('help_text'),
            'is_required' => postInt('is_required'),
            'sort_order'  => postInt('sort_order'),
            'status'      => postInt('status', 1),
        ];
        $rawConfig = json_decode((string) ($_POST['config'] ?? ''), true);
        $config = ExtFields::normalizeConfig($data['field_type'], is_array($rawConfig) ? $rawConfig : []);
        if (ExtFields::isProOwner($data['owner_type'])) {
            unset($config['location']);   // 栏目 / 分类 / 选项页本身就是位置
        }

        if (!in_array($data['owner_type'], $owners, true)) {
            error(__('ef_bad_owner'));
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $data['field_key'])) {
            error(__('ef_key_format'));
        }
        if (empty($data['field_name'])) {
            error(__('ef_name_required'));
        }
        if (!ExtFields::isType($data['field_type'])) {
            error(__('ef_bad_type'));
        }
        if (!extFieldModel()->isFieldKeyUnique($data['owner_type'], $data['field_key'], $id)) {
            error(__('ef_key_exists'));
        }
        $existing = $id > 0 ? extFieldModel()->find($id) : null;
        $existingConfig = is_array($existing) ? (array) (ExtFields::field((string) $existing['owner_type'], (string) $existing['field_key'])['config'] ?? []) : [];
        $usesPro = ExtFields::isProType($data['field_type']) || ExtFields::isProOwner($data['owner_type']) || ExtFields::configUsesPro($config);
        $wasPro = is_array($existing) && (ExtFields::isProType((string) $existing['field_type']) || ExtFields::isProOwner((string) $existing['owner_type']) || ExtFields::configUsesPro($existingConfig));
        if (($usesPro || $wasPro) && !$proAllowed) {
            error(__('ef_pro_required'));
        }
        if (in_array($data['field_type'], ['repeater', 'group'], true) && ($config['sub_fields'] ?? []) === []) {
            error(__('ef_sub_fields_required'));
        }
        foreach ((array) ($config['conditions'] ?? []) as $rule) {
            if ($rule['field'] === $data['field_key']) {
                error(__('ef_condition_self'));
            }
        }

        if ($id > 0) {
            extFieldModel()->updateById($id, $data);
            adminLog('extfield', 'update', "更新扩展字段: {$data['field_key']}");
        } else {
            $data['created_at'] = time();
            $id = extFieldModel()->create($data);
            adminLog('extfield', 'create', "创建扩展字段: {$data['field_key']}");
        }
        ExtFields::saveConfig((int) $id, $data['field_type'], $config);
        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        extFieldModel()->deleteById($id);
        delMeta('extfield', $id, 'config');
        adminLog('extfield', 'delete', "删除扩展字段ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = extFieldModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    exit;
}

$fields = ExtFields::fields($ownerType, false);
$typeLabels = ExtFields::typeLabels();
$subTypeLabels = array_intersect_key($typeLabels, array_flip(ExtFields::SUB_TYPES));

// 挂载位置候选：产品 → 产品分类；内容 / 模型 → 栏目
$locationTerms = [];
if (!ExtFields::isProOwner($ownerType)) {
    try {
        if ($ownerType === 'product') {
            $walk = static function (array $nodes, int $level) use (&$walk, &$locationTerms): void {
                foreach ($nodes as $node) {
                    $locationTerms[] = ['id' => (int) $node['id'], 'label' => str_repeat('— ', min(4, $level)) . (string) ($node['name'] ?? '')];
                    $walk((array) ($node['children'] ?? []), $level + 1);
                }
            };
            $walk(productCategoryModel()->getTree(0), 0);
        } else {
            foreach (channelModel()->getFlatList(0, 0) as $channel) {
                if (in_array((string) ($channel['type'] ?? ''), ['link', 'form', 'product'], true)) {
                    continue;
                }
                $locationTerms[] = ['id' => (int) $channel['id'], 'label' => str_repeat('— ', min(4, (int) ($channel['_level'] ?? 0))) . (string) ($channel['name'] ?? '')];
            }
        }
    } catch (Throwable) {
        $locationTerms = [];
    }
}

// 关联目标：产品、全部内容、各自定义模型
$relationTargets = ['product' => __('extfield_product'), 'content' => __('extfield_content')];
foreach ($owners as $owner) {
    if (!in_array($owner, ['content', 'product'], true) && !ExtFields::isProOwner($owner)) {
        $relationTargets[$owner] = ExtFields::ownerLabel($owner);
    }
}

$conditionFields = [];
foreach ($fields as $f) {
    if (!in_array($f['field_type'], ['repeater', 'group', 'relationship', 'richtext'], true)) {
        $conditionFields[] = ['key' => $f['field_key'], 'name' => $f['field_name'], 'type' => $f['field_type'], 'options' => ExtFields::parseOptions((string) $f['options'])];
    }
}

$pageTitle = __('ef_title');
$currentMenu = 'extfield';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div x-data="efManager()" x-cloak>

<?php /* 归属切换 */ ?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex flex-wrap border-b">
        <?php foreach ($owners as $owner): ?>
        <a href="?owner_type=<?php echo e($owner); ?>" class="px-5 py-3 text-sm font-medium border-b-2 inline-flex items-center gap-1 <?php echo $ownerType === $owner ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>">
            <?php echo e(ExtFields::ownerLabel($owner)); ?>
            <?php if (ExtFields::isProOwner($owner)): ?><span class="rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700">PRO</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="p-4 flex flex-wrap items-center gap-3">
        <p class="text-sm text-gray-500 flex-1 min-w-0"><?php echo e(__(['channel' => 'ef_owner_tip_channel', 'product_category' => 'ef_owner_tip_product_category', 'site' => 'ef_owner_tip_site'][$ownerType] ?? 'ef_owner_tip_item')); ?></p>
        <?php if (ExtFields::isProOwner($ownerType) && !$proAllowed): ?>
        <span class="text-sm text-amber-700"><?php echo e(__('ef_pro_locked')); ?></span>
        <?php else: ?>
        <button type="button" @click="openEdit(null)" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo e(__('ef_add')); ?>
        </button>
        <?php endif; ?>
    </div>
</div>

<?php /* 字段列表 */ ?>
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase"><?php echo __('label_sort_order'); ?></th>
                    <th class="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_slug')); ?></th>
                    <th class="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase"><?php echo e(__('label_name')); ?></th>
                    <th class="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase"><?php echo e(__('scontact_col_type')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('ef_required')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('label_status')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_actions')); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($fields as $f): $config = (array) $f['config']; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo (int) $f['sort_order']; ?></td>
                    <td class="px-4 py-3 font-mono text-sm"><?php echo e($f['field_key']); ?></td>
                    <td class="px-4 py-3">
                        <?php echo e($f['field_name']); ?>
                        <?php if (!empty($config['location'])): ?><span class="ms-1 text-xs text-gray-400" title="<?php echo e(__('ef_location')); ?>"><i class="ti ti-map-pin"></i><?php echo count($config['location']); ?></span><?php endif; ?>
                        <?php if (!empty($config['conditions'])): ?><span class="ms-1 text-xs text-gray-400" title="<?php echo e(__('ef_conditions')); ?>"><i class="ti ti-git-branch"></i></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600"><?php echo e($typeLabels[$f['field_type']] ?? $f['field_type']); ?></span>
                        <?php if (ExtFields::isProType((string) $f['field_type'])): ?><span class="rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700">PRO</span><?php endif; ?>
                        <?php if (!empty($config['sub_fields'])): ?><span class="text-xs text-gray-400"><?php echo e(implode(' · ', array_column($config['sub_fields'], 'name'))); ?></span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center"><?php echo $f['is_required'] ? '<span class="text-red-500">' . e(__('setting_yes')) . '</span>' : e(__('setting_no')); ?></td>
                    <td class="px-4 py-3 text-center">
                        <button type="button" onclick="toggleStatus(<?php echo (int) $f['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $f['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $f['status'] ? e(__('admin_enabled')) : e(__('ef_disabled')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button type="button" @click='openEdit(<?php echo json_encode($f, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-primary hover:underline text-sm me-2"><?php echo e(__('edit')); ?></button>
                        <button type="button" onclick="deleteField(<?php echo (int) $f['id']; ?>)" class="text-red-600 hover:underline text-sm"><?php echo e(__('admin_delete')); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fields)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo __('extfield_empty'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* 编辑弹窗 */ ?>
<div class="fixed inset-0 z-50" x-show="open" x-transition.opacity style="display:none">
    <div class="absolute inset-0 bg-black/50" @click="open = false"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-[calc(100%-2rem)] max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" x-text="item.id ? i18n.edit : i18n.add"></h3>
            <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600" aria-label="<?php echo e(__('cancel')); ?>">&times;</button>
        </div>
        <form class="p-6 space-y-4" @submit.prevent="save($event)">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" :value="item.id || 0">
            <input type="hidden" name="owner_type" value="<?php echo e($ownerType); ?>">

            <div class="grid sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('extfield_key'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="field_key" x-model="item.field_key" required class="w-full border rounded px-4 py-2 font-mono" placeholder="e.g. material">
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('ef_key_tip')); ?></p>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('extfield_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="field_name" x-model="item.field_name" required class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('ef_name_ph')); ?>">
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('extfield_type'); ?></label>
                <select name="field_type" x-model="item.field_type" class="w-full border rounded px-4 py-2">
                    <optgroup label="<?php echo e(__('ef_types_basic')); ?>">
                        <?php foreach (ExtFields::CORE_TYPES as $k => $_): ?>
                        <option value="<?php echo e($k); ?>"><?php echo e($typeLabels[$k]); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="<?php echo e(__('ef_types_pro')); ?>">
                        <?php foreach (ExtFields::PRO_TYPES as $k => $_): ?>
                        <option value="<?php echo e($k); ?>" <?php echo $proAllowed ? '' : 'disabled'; ?>><?php echo e($typeLabels[$k]); ?><?php echo $proAllowed ? '' : ' (PRO)'; ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <p class="text-xs text-gray-400 mt-1" x-show="hints[item.field_type]" x-text="hints[item.field_type]"></p>
            </div>

            <div x-show="['select', 'multi_select'].includes(item.field_type)">
                <label class="block text-gray-700 mb-1"><?php echo e(__('ef_options')); ?></label>
                <textarea name="options" x-model="item.options" rows="4" class="w-full border rounded px-4 py-2 font-mono text-xs" placeholder="<?php echo e(__('ef_options_lines_ph')); ?>"></textarea>
            </div>

            <?php /* 子字段（重复器 / 字段组） */ ?>
            <div x-show="['repeater', 'group'].includes(item.field_type)" class="rounded border p-4 space-y-3">
                <div class="flex items-center">
                    <h4 class="font-medium text-gray-800"><?php echo e(__('ef_sub_fields')); ?></h4>
                    <button type="button" @click="addSub()" class="ms-auto text-sm text-primary hover:underline"><i class="ti ti-plus"></i> <?php echo e(__('ef_add_sub_field')); ?></button>
                </div>
                <template x-for="(sub, i) in subs" :key="i">
                    <div class="grid grid-cols-[1fr_1fr_8rem_auto] gap-2 items-start">
                        <input type="text" x-model="sub.name" class="border rounded px-2 py-1.5 text-sm" placeholder="<?php echo e(__('extfield_name')); ?>">
                        <input type="text" x-model="sub.key" class="border rounded px-2 py-1.5 font-mono text-sm" placeholder="<?php echo e(__('extfield_key')); ?>">
                        <select x-model="sub.type" class="border rounded px-2 py-1.5 text-sm">
                            <?php foreach ($subTypeLabels as $k => $v): ?>
                            <option value="<?php echo e($k); ?>"><?php echo e($v); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="flex gap-1 pt-1">
                            <button type="button" @click="moveSub(i, -1)" class="text-gray-400 hover:text-gray-700" aria-label="<?php echo e(__('ef_move_up')); ?>"><i class="ti ti-arrow-up"></i></button>
                            <button type="button" @click="subs.splice(i, 1)" class="text-gray-400 hover:text-red-600" aria-label="<?php echo e(__('admin_delete')); ?>"><i class="ti ti-trash"></i></button>
                        </span>
                        <textarea x-show="sub.type === 'select'" x-model="sub.options" rows="2" class="col-span-4 border rounded px-2 py-1.5 font-mono text-xs" placeholder="<?php echo e(__('ef_options_lines_ph')); ?>"></textarea>
                    </div>
                </template>
                <p x-show="subs.length === 0" class="text-sm text-gray-400"><?php echo e(__('ef_no_sub_fields')); ?></p>
                <div x-show="item.field_type === 'repeater'" class="grid sm:grid-cols-3 gap-3 pt-2 border-t">
                    <label class="text-sm text-gray-600"><?php echo e(__('ef_min_rows')); ?><input type="number" min="0" x-model.number="min" class="mt-1 w-full border rounded px-2 py-1.5"></label>
                    <label class="text-sm text-gray-600"><?php echo e(__('ef_max_rows_label')); ?><input type="number" min="0" x-model.number="max" class="mt-1 w-full border rounded px-2 py-1.5"></label>
                    <label class="text-sm text-gray-600"><?php echo e(__('ef_button_label')); ?><input type="text" x-model="buttonLabel" class="mt-1 w-full border rounded px-2 py-1.5" placeholder="<?php echo e(__('ef_add_row')); ?>"></label>
                </div>
            </div>

            <?php /* 关联 */ ?>
            <div x-show="item.field_type === 'relationship'" class="grid sm:grid-cols-2 gap-4 rounded border p-4">
                <label class="text-sm text-gray-600"><?php echo e(__('ef_rel_target')); ?>
                    <select x-model="target" class="mt-1 w-full border rounded px-2 py-1.5">
                        <?php foreach ($relationTargets as $k => $v): ?>
                        <option value="<?php echo e($k); ?>"><?php echo e($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="text-sm text-gray-600"><?php echo e(__('ef_rel_max_label')); ?><input type="number" min="0" x-model.number="relMax" class="mt-1 w-full border rounded px-2 py-1.5"></label>
            </div>

            <div class="grid sm:grid-cols-2 gap-4" x-show="!['repeater', 'group', 'relationship', 'switch', 'multi_select', 'images'].includes(item.field_type)">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('extfield_placeholder'); ?></label>
                    <input type="text" name="placeholder" x-model="item.placeholder" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('ef_help_text')); ?></label>
                <input type="text" name="help_text" x-model="item.help_text" class="w-full border rounded px-4 py-2">
            </div>

            <?php /* 专业版：条件逻辑 / 挂载位置 */ ?>
            <details class="rounded border" <?php echo $proAllowed ? '' : 'data-locked'; ?> :open="conditions.length > 0 || location.length > 0">
                <summary class="cursor-pointer px-4 py-2 text-sm font-medium text-gray-700"><?php echo e(__('ef_advanced')); ?> <span class="rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-700">PRO</span></summary>
                <div class="space-y-4 border-t p-4">
                    <?php if (!$proAllowed): ?><p class="text-sm text-amber-700"><?php echo e(__('ef_pro_locked')); ?></p><?php endif; ?>
                    <div>
                        <div class="flex items-center">
                            <h4 class="text-sm font-medium text-gray-800"><?php echo e(__('ef_conditions')); ?></h4>
                            <button type="button" @click="conditions.push({field: '', op: '==', value: ''})" class="ms-auto text-sm text-primary hover:underline" <?php echo $proAllowed ? '' : 'disabled'; ?>><i class="ti ti-plus"></i> <?php echo e(__('ef_add_condition')); ?></button>
                        </div>
                        <p class="text-xs text-gray-400 mb-2"><?php echo e(__('ef_conditions_tip')); ?></p>
                        <template x-for="(rule, i) in conditions" :key="i">
                            <div class="mb-2 grid grid-cols-[1fr_8rem_1fr_auto] gap-2">
                                <select x-model="rule.field" class="border rounded px-2 py-1.5 text-sm">
                                    <option value=""><?php echo e(__('ef_choose')); ?></option>
                                    <template x-for="f in conditionFields.filter(f => f.key !== item.field_key)" :key="f.key">
                                        <option :value="f.key" x-text="f.name + ' (' + f.key + ')'" :selected="f.key === rule.field"></option>
                                    </template>
                                </select>
                                <select x-model="rule.op" class="border rounded px-2 py-1.5 text-sm">
                                    <option value="=="><?php echo e(__('ef_op_eq')); ?></option>
                                    <option value="!="><?php echo e(__('ef_op_ne')); ?></option>
                                    <option value="not_empty"><?php echo e(__('ef_op_not_empty')); ?></option>
                                    <option value="empty"><?php echo e(__('ef_op_empty')); ?></option>
                                </select>
                                <input type="text" x-model="rule.value" x-show="rule.op === '==' || rule.op === '!='" class="border rounded px-2 py-1.5 text-sm" :placeholder="valueHint(rule.field)">
                                <button type="button" @click="conditions.splice(i, 1)" class="text-gray-400 hover:text-red-600" aria-label="<?php echo e(__('admin_delete')); ?>"><i class="ti ti-trash"></i></button>
                            </div>
                        </template>
                    </div>
                    <?php if ($locationTerms !== []): ?>
                    <div>
                        <h4 class="text-sm font-medium text-gray-800"><?php echo e(__('ef_location')); ?></h4>
                        <p class="text-xs text-gray-400 mb-2"><?php echo e(__($ownerType === 'product' ? 'ef_location_tip_product' : 'ef_location_tip_content')); ?></p>
                        <div class="max-h-48 overflow-y-auto rounded border p-2 grid sm:grid-cols-2 gap-1">
                            <?php foreach ($locationTerms as $term): ?>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" value="<?php echo (int) $term['id']; ?>" x-model.number="location" <?php echo $proAllowed ? '' : 'disabled'; ?>> <?php echo e($term['label']); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </details>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" x-model="item.sort_order" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('ef_required')); ?></label>
                    <select name="is_required" x-model="item.is_required" class="w-full border rounded px-4 py-2">
                        <option value="0"><?php echo e(__('setting_no')); ?></option>
                        <option value="1"><?php echo e(__('setting_yes')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('label_status')); ?></label>
                    <select name="status" x-model="item.status" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo e(__('admin_enabled')); ?></option>
                        <option value="0"><?php echo e(__('ef_disabled')); ?></option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" @click="open = false" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo e(__('cancel')); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded"><?php echo e(__('btn_save')); ?></button>
            </div>
        </form>
    </div>
</div>
</div>

<script>
function efManager() {
    return {
        open: false,
        item: {},
        subs: [], min: 0, max: 0, buttonLabel: '',
        target: 'product', relMax: 0,
        conditions: [], location: [],
        conditionFields: <?php echo json_encode($conditionFields, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>,
        i18n: <?php echo json_encode(['add' => __('ef_add'), 'edit' => __('ef_edit'), 'saved' => __('save_success')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
        hints: <?php echo json_encode([
            'file' => __('ef_hint_file'), 'link' => __('ef_hint_link'), 'color' => __('ef_hint_color'),
            'repeater' => __('ef_hint_repeater'), 'group' => __('ef_hint_group'), 'relationship' => __('ef_hint_relationship'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
        openEdit(row) {
            const c = (row && row.config) || {};
            this.item = Object.assign({ id: 0, field_key: '', field_name: '', field_type: 'text', options: '', placeholder: '', help_text: '', sort_order: 0, is_required: 0, status: 1 }, row || {});
            this.item.is_required = String(this.item.is_required);
            this.item.status = String(this.item.status);
            this.subs = (c.sub_fields || []).map(s => Object.assign({ options: '' }, s));
            this.min = c.min || 0; this.max = c.max || 0; this.buttonLabel = c.button_label || '';
            this.target = c.target || 'product'; this.relMax = (row && row.field_type === 'relationship' && c.max) || 0;
            this.conditions = (c.conditions || []).map(r => Object.assign({}, r));
            this.location = (c.location || []).map(Number);
            this.open = true;
        },
        addSub() { this.subs.push({ key: '', name: '', type: 'text', options: '' }); },
        moveSub(i, d) { const j = i + d; if (j < 0 || j >= this.subs.length) return; const s = this.subs.splice(i, 1)[0]; this.subs.splice(j, 0, s); },
        valueHint(key) {
            const f = this.conditionFields.find(f => f.key === key);
            if (!f) return '';
            if (f.type === 'switch') return '1 / 0';
            const keys = Object.keys(f.options || {});
            return keys.length ? keys.slice(0, 4).join(' / ') : '';
        },
        config() {
            const t = this.item.field_type;
            const c = { conditions: this.conditions.filter(r => r.field), location: this.location };
            if (t === 'repeater' || t === 'group') c.sub_fields = this.subs;
            if (t === 'repeater') { c.min = this.min; c.max = this.max; c.button_label = this.buttonLabel; }
            if (t === 'relationship') { c.target = this.target; c.max = this.relMax; }
            return JSON.stringify(c);
        },
        async save(e) {
            const fd = new FormData(e.target);
            fd.set('options', this.item.options || '');
            fd.set('config', this.config());
            const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
            if (data.code === 0) {
                showMessage(this.i18n.saved);
                setTimeout(() => location.reload(), 600);
            } else {
                showMessage(data.msg, 'error');
            }
        }
    };
}

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: formData }));
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = <?php echo json_encode(__('admin_enabled'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = <?php echo json_encode(__('ef_disabled'), JSON_UNESCAPED_UNICODE); ?>;
        }
    }
}

async function deleteField(id) {
    if (!confirm(<?php echo json_encode(__('ef_del_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: formData }));
    if (data.code === 0) {
        showMessage(<?php echo json_encode(__('admin_deleted'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(() => location.reload(), 800);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
