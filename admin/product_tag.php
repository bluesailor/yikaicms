<?php
/**
 * 产品标签管理
 */
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requirePermission('content');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'group_name' => post('group_name'),
            'name' => post('name'),
            'slug' => post('slug') ?: 'tag-' . time(),
            'sort_order' => postInt('sort_order'),
            'status' => postInt('status', 1),
        ];
        if (empty($data['name']) || empty($data['group_name'])) error('请填写标签组和名称');

        if ($id > 0) {
            db()->update('product_tags', $data, 'id = ?', [$id]);
        } else {
            db()->insert('product_tags', $data);
        }
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');
        db()->delete('product_tags', 'id = ?', [$id]);
        db()->delete('product_tag_map', 'tag_id = ?', [$id]);
        success();
    }

    if ($action === 'add_group') {
        $groupName = post('group_name');
        if (empty($groupName)) error('请输入标签组名称');
        success(['group_name' => $groupName]);
    }
}

// 获取所有标签按组分类
$allTags = db()->fetchAll("SELECT t.*, (SELECT COUNT(*) FROM " . DB_PREFIX . "product_tag_map WHERE tag_id = t.id) as product_count FROM " . DB_PREFIX . "product_tags t ORDER BY t.group_name, t.sort_order, t.id");

$groups = [];
foreach ($allTags as $tag) {
    $groups[$tag['group_name']][] = $tag;
}

$groupNames = array_keys($groups);

$editTag = null;
$editId = getInt('edit');
if ($editId > 0) {
    $editTag = db()->fetchOne("SELECT * FROM " . DB_PREFIX . "product_tags WHERE id = ?", [$editId]);
}

$pageTitle = '标签管理';
$currentMenu = 'product';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_list'); ?></a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_category'); ?></a>
        <a href="/admin/product_brand.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_brand'); ?></a>
        <a href="/admin/product_tag.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('product_tab_tag'); ?></a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_setting'); ?></a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- 标签列表 -->
    <div class="lg:col-span-2">
        <?php foreach ($groups as $groupName => $tags): ?>
        <div class="bg-white rounded-lg shadow mb-4">
            <div class="px-5 py-3 border-b flex justify-between items-center bg-gray-50">
                <h3 class="font-bold text-sm"><?php echo e($groupName); ?> <span class="text-gray-400 font-normal">(<?php echo count($tags); ?>)</span></h3>
            </div>
            <div class="p-4 flex flex-wrap gap-2">
                <?php foreach ($tags as $tag): ?>
                <div class="group relative inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 rounded-full text-sm hover:bg-blue-50">
                    <span><?php echo e($tag['name']); ?></span>
                    <span class="text-xs text-gray-400">(<?php echo $tag['product_count']; ?>)</span>
                    <div class="hidden group-hover:flex items-center gap-1 ml-1">
                        <a href="?edit=<?php echo $tag['id']; ?>" class="text-blue-500 hover:text-blue-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></a>
                        <button onclick="deleteTag(<?php echo $tag['id']; ?>)" class="text-red-500 hover:text-red-700"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($groups)): ?>
        <div class="bg-white rounded-lg shadow p-12 text-center text-gray-400">暂无标签，请在右侧添加</div>
        <?php endif; ?>
    </div>

    <!-- 添加/编辑 -->
    <div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="font-bold mb-4"><?php echo $editTag ? '编辑标签' : '添加标签'; ?></h3>
            <form id="tagForm" class="space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editTag['id'] ?? 0; ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">标签组 *</label>
                    <div class="flex gap-2">
                        <select name="group_name" id="groupSelect" class="flex-1 border rounded px-3 py-2 text-sm">
                            <?php foreach ($groupNames as $gn): ?>
                            <option value="<?php echo e($gn); ?>" <?php echo ($editTag['group_name'] ?? '') === $gn ? 'selected' : ''; ?>><?php echo e($gn); ?></option>
                            <?php endforeach; ?>
                            <option value="__new__">+ 新建标签组</option>
                        </select>
                    </div>
                    <input type="text" id="newGroupInput" class="hidden w-full border rounded px-3 py-2 text-sm mt-2" placeholder="输入新标签组名称">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">标签名 *</label>
                    <input type="text" name="name" value="<?php echo e($editTag['name'] ?? ''); ?>" required class="w-full border rounded px-3 py-2 text-sm" placeholder="如：PU聚氨酯、食品级">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input type="text" name="slug" value="<?php echo e($editTag['slug'] ?? ''); ?>" class="w-full border rounded px-3 py-2 text-sm" placeholder="留空自动生成">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                        <input type="number" name="sort_order" value="<?php echo $editTag['sort_order'] ?? 0; ?>" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                        <select name="status" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="1"><?php echo __('admin_enabled'); ?></option>
                            <option value="0" <?php echo ($editTag['status'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo __('admin_disabled'); ?></option>
                        </select>
                    </div>
                </div>
                <button type="button" onclick="saveTag()" class="w-full bg-primary hover:bg-secondary text-white py-2 rounded text-sm"><?php echo __('btn_save'); ?></button>
                <?php if ($editTag): ?>
                <a href="/admin/product_tag.php" class="block text-center text-gray-500 text-sm">取消编辑</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="bg-white rounded-lg shadow p-6 mt-4">
            <h3 class="font-bold mb-3 text-sm">快速批量添加</h3>
            <textarea id="batchTags" rows="4" class="w-full border rounded px-3 py-2 text-sm" placeholder="每行一个标签名&#10;会添加到选中的标签组"></textarea>
            <button type="button" onclick="batchAddTags()" class="mt-2 w-full bg-gray-600 hover:bg-gray-700 text-white py-2 rounded text-sm">批量添加</button>
        </div>
    </div>
</div>

<script>
document.getElementById('groupSelect').addEventListener('change', function() {
    document.getElementById('newGroupInput').classList.toggle('hidden', this.value !== '__new__');
});

async function saveTag() {
    const fd = new FormData(document.getElementById('tagForm'));
    const groupSelect = document.getElementById('groupSelect');
    if (groupSelect.value === '__new__') {
        const newGroup = document.getElementById('newGroupInput').value.trim();
        if (!newGroup) { showMessage('请输入新标签组名称', 'error'); return; }
        fd.set('group_name', newGroup);
    }
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage('<?php echo __('admin_saved'); ?>'); setTimeout(() => location.href = '/admin/product_tag.php', 1000); }
    else showMessage(d.msg, 'error');
}

async function deleteTag(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage('已删除'); setTimeout(() => location.reload(), 1000); }
}

async function batchAddTags() {
    const text = document.getElementById('batchTags').value.trim();
    if (!text) { showMessage('请输入标签', 'error'); return; }
    const groupSelect = document.getElementById('groupSelect');
    let groupName = groupSelect.value;
    if (groupName === '__new__') {
        groupName = document.getElementById('newGroupInput').value.trim();
        if (!groupName) { showMessage('请选择或输入标签组', 'error'); return; }
    }
    const names = text.split('\n').map(s => s.trim()).filter(Boolean);
    for (const name of names) {
        const fd = new FormData();
        fd.append('action', 'save'); fd.append('id', '0');
        fd.append('group_name', groupName); fd.append('name', name);
        fd.append('slug', ''); fd.append('sort_order', '0'); fd.append('status', '1');
        await fetch('', { method: 'POST', body: fd });
    }
    showMessage(`已添加 ${names.length} 个标签`);
    setTimeout(() => location.reload(), 1000);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
