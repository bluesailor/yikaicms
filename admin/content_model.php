<?php
/**
 * YikaiCMS - 自定义内容模型管理
 *
 * 定义内容模型（team/solution/faq…）：名称/标识/URL/模板。字段管理复用 extfield.php。
 * 设计见 yikaicms-docs/design-custom-content-model.md。
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

$presets = require ROOT_PATH . '/includes/content_model_presets.php';

// ============================================================
// AJAX（CSRF 由 checkLogin 统一校验）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $key = strtolower(trim(post('model_key')));
        $name = trim(post('name'));

        if ($name === '') {
            error(__('cm_err_name'));
        }

        $data = [
            'name'            => $name,
            'name_en'         => trim(post('name_en')),
            'name_ja'         => trim(post('name_ja')),
            'icon'            => trim(post('icon')),
            'url_prefix'      => strtolower(trim(post('url_prefix'))),
            'list_template'   => trim(post('list_template')),
            'detail_template' => trim(post('detail_template')),
            'has_detail'      => postInt('has_detail', 1),
            'sort_order'      => postInt('sort_order'),
            'status'          => postInt('status', 1),
        ];

        if ($id > 0) {
            // 编辑：key 不可改（防 owner_type / contents.type 漂移）
            contentModelModel()->updateById($id, $data);
            adminLog('content_model', 'update', '更新内容模型ID：' . $id);
            success(['id' => $id]);
        }

        // 新建：校验 key
        if (!contentModelModel()->isKeyValid($key)) {
            error(__('cm_err_key'));
        }
        $data['model_key'] = $key;
        $data['created_at'] = time();
        $newId = contentModelModel()->create($data);
        adminLog('content_model', 'create', '创建内容模型：' . $key);

        // 套用预置方案：批量建常用字段（仅当该模型尚无字段，避免重复）
        $presetKey = post('preset');
        if ($presetKey !== '' && isset($presets[$presetKey]) && empty(extFieldModel()->getByOwner($key, false))) {
            foreach ($presets[$presetKey]['fields'] as $i => $f) {
                extFieldModel()->create([
                    'owner_type'  => $key,
                    'field_key'   => $f['field_key'],
                    'field_name'  => presetText($f, 'field_name'),
                    'field_type'  => $f['field_type'],
                    'options'     => presetText($f, 'options'),
                    'placeholder' => '',
                    'help_text'   => presetText($f, 'help_text'),
                    'is_required' => (int) ($f['is_required'] ?? 0),
                    'sort_order'  => $i,
                    'status'      => 1,
                    'created_at'  => time(),
                ]);
            }
            adminLog('content_model', 'preset', "套用预置 {$presetKey}：建 " . count($presets[$presetKey]['fields']) . " 个字段");
        }

        success(['id' => $newId]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        $model = contentModelModel()->find($id);
        if (!$model) {
            error(__('cm_err_notfound'));
        }
        // 有内容的模型禁止删除，先清空
        if (contentModelModel()->contentCount($model['model_key']) > 0) {
            error(__('cm_err_has_content'));
        }
        contentModelModel()->deleteById($id);
        // 连带清理该模型的字段定义
        db()->execute("DELETE FROM " . DB_PREFIX . "extfields WHERE owner_type = ?", [$model['model_key']]);
        adminLog('content_model', 'delete', '删除内容模型：' . $model['model_key']);
        success();
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $newStatus = contentModelModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    error();
}

$models = contentModelModel()->all();
$fieldTable = DB_PREFIX . 'extfields';

$pageTitle = __('admin_content_model');
$currentMenu = 'content_model';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-4 flex justify-between items-center">
    <p class="text-sm text-gray-500"><?php echo __('cm_intro'); ?></p>
    <button onclick="openModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1 cursor-pointer">
        <i class="ti ti-plus text-base"></i><?php echo __('cm_add'); ?>
    </button>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
            <tr>
                <th class="px-6 py-3 font-medium"><?php echo __('cm_col_name'); ?></th>
                <th class="px-6 py-3 font-medium">Key</th>
                <th class="px-6 py-3 font-medium w-24 text-center"><?php echo __('cm_col_fields'); ?></th>
                <th class="px-6 py-3 font-medium w-24 text-center"><?php echo __('cm_col_contents'); ?></th>
                <th class="px-6 py-3 font-medium w-20 text-center"><?php echo __('cm_col_status'); ?></th>
                <th class="px-6 py-3 font-medium w-56 text-right"><?php echo __('cm_col_actions'); ?></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php if (empty($models)): ?>
            <tr><td colspan="6" class="px-6 py-12 text-center text-gray-400"><?php echo __('cm_empty'); ?></td></tr>
            <?php endif; ?>
            <?php foreach ($models as $m):
                $fieldCount = (int) db()->fetchColumn("SELECT COUNT(*) FROM {$fieldTable} WHERE owner_type = ?", [$m['model_key']]);
                $contentCount = contentModelModel()->contentCount($m['model_key']);
            ?>
            <tr class="hover:bg-gray-50" data-json='<?php echo e(json_encode($m, JSON_UNESCAPED_UNICODE)); ?>'>
                <td class="px-6 py-3">
                    <div class="font-medium text-gray-800"><?php echo e($m['name']); ?></div>
                    <?php if ($m['name_en'] || $m['name_ja']): ?><div class="text-xs text-gray-400"><?php echo e(trim($m['name_en'] . ' / ' . $m['name_ja'], ' /')); ?></div><?php endif; ?>
                </td>
                <td class="px-6 py-3 font-mono text-primary"><?php echo e($m['model_key']); ?></td>
                <td class="px-6 py-3 text-center">
                    <a href="/admin/extfield.php?owner_type=<?php echo e($m['model_key']); ?>" class="text-primary hover:underline"><?php echo $fieldCount; ?></a>
                </td>
                <td class="px-6 py-3 text-center text-gray-600"><?php echo $contentCount; ?></td>
                <td class="px-6 py-3 text-center">
                    <button onclick="toggleStatus(<?php echo (int) $m['id']; ?>, this)"
                            class="text-xs px-2 py-1 rounded cursor-pointer <?php echo $m['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                        <?php echo $m['status'] ? __('cm_on') : __('cm_off'); ?>
                    </button>
                </td>
                <td class="px-6 py-3 text-right space-x-2 whitespace-nowrap">
                    <a href="/admin/extfield.php?owner_type=<?php echo e($m['model_key']); ?>" class="text-gray-600 hover:text-primary inline-flex items-center gap-1"><i class="ti ti-list-details"></i><?php echo __('cm_fields'); ?></a>
                    <button onclick="editModel(this)" class="text-primary hover:text-secondary inline-flex items-center gap-1 cursor-pointer"><i class="ti ti-edit"></i><?php echo __('cm_edit'); ?></button>
                    <button onclick="delModel(<?php echo (int) $m['id']; ?>)" class="text-red-500 hover:text-red-600 inline-flex items-center gap-1 cursor-pointer"><i class="ti ti-trash"></i><?php echo __('cm_delete'); ?></button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 内置类型（系统预置，不可删；字段在共享的内容/产品字段里管理）-->
<div class="mt-6 bg-white rounded-lg shadow p-6">
    <h3 class="font-bold text-gray-800 mb-1"><?php echo __('cm_builtin_title'); ?></h3>
    <p class="text-sm text-gray-500 mb-4"><?php echo __('cm_builtin_intro'); ?></p>
    <div class="flex flex-wrap gap-2 text-sm">
        <?php foreach (['article' => __('admin_article'), 'case' => __('admin_case'), 'download' => __('admin_download'), 'job' => __('admin_job'), 'product' => __('admin_product')] as $bk => $bl): ?>
        <span class="inline-flex items-center gap-1 px-3 py-1.5 rounded-full bg-gray-100 text-gray-600">
            <?php echo e($bl); ?> <span class="text-gray-400 font-mono text-xs">(<?php echo $bk; ?>)</span>
        </span>
        <?php endforeach; ?>
    </div>
    <div class="mt-4 flex gap-4 text-sm">
        <a href="/admin/extfield.php?owner_type=content" class="text-primary hover:underline inline-flex items-center gap-1"><i class="ti ti-list-details"></i><?php echo __('cm_builtin_content_fields'); ?></a>
        <a href="/admin/extfield.php?owner_type=product" class="text-primary hover:underline inline-flex items-center gap-1"><i class="ti ti-list-details"></i><?php echo __('cm_builtin_product_fields'); ?></a>
    </div>
</div>

<!-- 编辑 Modal -->
<div id="cmModal" class="fixed inset-0 bg-black/40 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800" id="cmModalTitle"><?php echo __('cm_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 cursor-pointer"><i class="ti ti-x"></i></button>
        </div>
        <form id="cmForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="cm_id" value="0">
            <input type="hidden" name="preset" id="cm_preset" value="">

            <!-- 预置方案（仅新建时显示，点一下快速填充 + 自动建常用字段）-->
            <div id="cm_presetPicker">
                <label class="block text-gray-700 mb-2 text-sm"><?php echo __('cm_preset_pick'); ?></label>
                <div class="grid grid-cols-3 gap-2">
                    <?php foreach ($presets as $pk => $p): ?>
                    <button type="button" onclick="applyPreset('<?php echo e($pk); ?>')"
                            class="preset-card border rounded-lg px-3 py-2.5 text-left hover:border-primary hover:bg-blue-50 transition cursor-pointer">
                        <div class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-primary shrink-0" fill="none" viewBox="0 0 24 24"><?php echo $p['icon']; ?></svg>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-gray-800 truncate"><?php echo e(presetText($p, 'name')); ?></div>
                                <div class="text-xs text-gray-400"><?php echo count($p['fields']); ?> <?php echo __('cm_preset_fields'); ?></div>
                            </div>
                        </div>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="text-xs text-gray-400 mt-2"><?php echo __('cm_preset_hint'); ?></div>
                <hr class="my-4">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('cm_f_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="cm_name" required class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm">Key <span class="text-red-500">*</span></label>
                    <input type="text" name="model_key" id="cm_key" required class="w-full border rounded px-3 py-2 font-mono" placeholder="team">
                    <p class="text-xs text-gray-400 mt-1" id="cm_key_hint"><?php echo __('cm_f_key_hint'); ?></p>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('cm_f_name_en'); ?></label>
                    <input type="text" name="name_en" id="cm_name_en" class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('cm_f_name_ja'); ?></label>
                    <input type="text" name="name_ja" id="cm_name_ja" class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('cm_f_url_prefix'); ?></label>
                    <input type="text" name="url_prefix" id="cm_url_prefix" class="w-full border rounded px-3 py-2 font-mono" placeholder="team">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('cm_f_sort'); ?></label>
                    <input type="number" name="sort_order" id="cm_sort" value="0" class="w-full border rounded px-3 py-2">
                </div>
                <div class="col-span-2">
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('cm_f_list_tpl'); ?></label>
                    <select name="list_template" id="cm_list_tpl" class="w-full border rounded px-3 py-2">
                        <option value=""><?php echo __('cm_f_tpl_default'); ?></option>
                        <?php foreach (availableCardTemplates() as $tplPath => $tplName): ?>
                        <option value="<?php echo e($tplPath); ?>"><?php echo e($tplName); ?> (<?php echo e($tplPath); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-1"><?php echo __('cm_f_list_tpl_hint'); ?></p>
                </div>
                <input type="hidden" name="detail_template" id="cm_detail_tpl" value="">
            </div>
            <div class="flex items-center gap-6">
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="has_detail" id="cm_has_detail" value="1" checked> <?php echo __('cm_f_has_detail'); ?></label>
                <label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="status" id="cm_status" value="1" checked> <?php echo __('cm_f_status'); ?></label>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded text-gray-600 cursor-pointer"><?php echo __('cm_cancel'); ?></button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded cursor-pointer"><?php echo __('cm_save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const CSRF = <?php echo json_encode(csrfToken()); ?>, CSRF_NAME = <?php echo json_encode(CSRF_TOKEN_NAME); ?>;
<?php
// 只把表单需要的五个标量投影给 JS——整表下发会把三语字段名与选项全灌进页面，
// 既没人用，也让「后台英文界面无中文」的渲染态检查永远过不了。
$presetsJs = [];
foreach ($presets as $pk => $p) {
    $presetsJs[$pk] = [
        'name'       => $p['name'],
        'name_en'    => $p['name_en'] ?? '',
        'name_ja'    => $p['name_ja'] ?? '',
        'url_prefix' => $p['url_prefix'] ?? $pk,
        'has_detail' => $p['has_detail'] ?? 0,
    ];
}
?>
const PRESETS = <?php echo json_encode($presetsJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
const modal = document.getElementById('cmModal');

function openModal() {
    document.getElementById('cmForm').reset();
    document.getElementById('cm_id').value = '0';
    document.getElementById('cm_preset').value = '';
    document.getElementById('cm_key').readOnly = false;
    document.getElementById('cm_key').classList.remove('bg-gray-100');
    document.getElementById('cm_presetPicker').classList.remove('hidden'); // 新建显示预置
    document.getElementById('cmModalTitle').textContent = <?php echo json_encode(__('cm_add')); ?>;
    modal.classList.remove('hidden'); modal.classList.add('flex');
}
function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }

// 套用预置：填充表单基本信息（字段由后端按 preset 自动创建）
function applyPreset(key) {
    const p = PRESETS[key];
    if (!p) return;
    document.getElementById('cm_preset').value = key;
    document.getElementById('cm_name').value = p.name || '';
    document.getElementById('cm_key').value = key;
    document.getElementById('cm_name_en').value = p.name_en || '';
    document.getElementById('cm_name_ja').value = p.name_ja || '';
    document.getElementById('cm_url_prefix').value = p.url_prefix || key;
    document.getElementById('cm_has_detail').checked = String(p.has_detail) === '1';
    // 高亮选中卡片
    document.querySelectorAll('.preset-card').forEach(c => c.classList.remove('border-primary', 'bg-blue-50'));
    if (event && event.currentTarget) event.currentTarget.classList.add('border-primary', 'bg-blue-50');
}

function editModel(btn) {
    const m = JSON.parse(btn.closest('tr').dataset.json);
    document.getElementById('cm_preset').value = '';
    document.getElementById('cm_presetPicker').classList.add('hidden'); // 编辑隐藏预置
    document.getElementById('cm_id').value = m.id;
    document.getElementById('cm_name').value = m.name || '';
    document.getElementById('cm_key').value = m.model_key || '';
    document.getElementById('cm_key').readOnly = true;              // key 建后锁定
    document.getElementById('cm_key').classList.add('bg-gray-100');
    document.getElementById('cm_name_en').value = m.name_en || '';
    document.getElementById('cm_name_ja').value = m.name_ja || '';
    document.getElementById('cm_url_prefix').value = m.url_prefix || '';
    document.getElementById('cm_sort').value = m.sort_order || 0;
    document.getElementById('cm_list_tpl').value = m.list_template || '';
    document.getElementById('cm_detail_tpl').value = m.detail_template || '';
    document.getElementById('cm_has_detail').checked = String(m.has_detail) === '1';
    document.getElementById('cm_status').checked = String(m.status) === '1';
    document.getElementById('cmModalTitle').textContent = <?php echo json_encode(__('cm_edit')); ?>;
    modal.classList.remove('hidden'); modal.classList.add('flex');
}
async function cmPost(body) {
    body[CSRF_NAME] = CSRF;
    const res = await fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams(body) });
    return res.json().catch(() => ({ code: 1, msg: 'error' }));
}
document.getElementById('cmForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(this), body = {};
    fd.forEach((v, k) => body[k] = v);
    body.has_detail = document.getElementById('cm_has_detail').checked ? 1 : 0;
    body.status = document.getElementById('cm_status').checked ? 1 : 0;
    const data = await cmPost(body);
    if (data.code === 0) { location.reload(); } else { alert(data.msg || 'error'); }
});
async function delModel(id) {
    if (!confirm(<?php echo json_encode(__('cm_del_confirm')); ?>)) return;
    const data = await cmPost({ action: 'delete', id });
    if (data.code === 0) { location.reload(); } else { alert(data.msg || 'error'); }
}
async function toggleStatus(id, btn) {
    const data = await cmPost({ action: 'toggle', id });
    if (data.code === 0) { location.reload(); } else { alert(data.msg || 'error'); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
