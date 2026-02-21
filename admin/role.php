<?php
/**
 * Yikai CMS - 角色管理
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

// 可分配权限列表
$allPermissions = [
    '*'       => '全部权限（超级管理员）',
    'content' => '内容管理',
    'media'   => '媒体管理',
    'form'    => '表单管理',
    'banner'  => '横幅管理',
    'link'    => '友情链接',
    'member'  => '会员管理',
    'setting' => '系统设置',
];

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $name = trim(post('name'));
        $description = trim(post('description'));
        $permissions = $_POST['permissions'] ?? [];

        if (empty($name)) {
            error('请输入角色名称');
        }

        // 过滤无效权限
        $permissions = array_values(array_intersect($permissions, array_keys($allPermissions)));
        if (in_array('*', $permissions)) {
            $permissions = ['*'];
        }

        $data = [
            'name'        => $name,
            'description' => $description,
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
        ];

        if ($id > 0) {
            roleModel()->updateById($id, $data);
            adminLog('role', 'update', "更新角色ID: $id");
        } else {
            $data['status'] = 1;
            $data['created_at'] = time();
            $id = roleModel()->create($data);
            adminLog('role', 'create', "创建角色ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');

        $role = roleModel()->find($id);
        if (!$role) {
            error('角色不存在');
        }

        // 检查是否有用户关联
        $userCount = roleModel()->getUserCount($id);
        if ($userCount > 0) {
            error("该角色下有 {$userCount} 个管理员，请先转移后再删除");
        }

        // 检查是否是超级管理员角色
        $perms = json_decode($role['permissions'] ?? '[]', true) ?: [];
        if (in_array('*', $perms)) {
            error('超级管理员角色不可删除');
        }

        roleModel()->deleteById($id);
        adminLog('role', 'delete', "删除角色ID: $id");
        success();
    }

    exit;
}

// 获取角色列表
$roles = roleModel()->all('id ASC');

$pageTitle = '管理员';
$currentMenu = 'role';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/user.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">管理员列表</a>
        <a href="/admin/role.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">角色管理</a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加角色
        </button>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">角色名称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">描述</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">权限</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">管理员数</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($roles as $item):
                    $perms = json_decode($item['permissions'] ?? '[]', true) ?: [];
                    $isSuperRole = in_array('*', $perms);
                    $userCount = roleModel()->getUserCount((int)$item['id']);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3 font-medium"><?php echo e($item['name']); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo e($item['description']); ?></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap gap-1">
                            <?php if ($isSuperRole): ?>
                            <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded">全部权限</span>
                            <?php else: ?>
                            <?php foreach ($perms as $p): ?>
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded"><?php echo e($allPermissions[$p] ?? $p); ?></span>
                            <?php endforeach; ?>
                            <?php if (empty($perms)): ?>
                            <span class="text-xs text-gray-400">无权限</span>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-sm text-gray-600"><?php echo $userCount; ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2">编辑</button>
                        <?php if (!$isSuperRole): ?>
                        <button onclick="deleteRole(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm">删除</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($roles)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无角色</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="relative max-w-lg mx-auto my-10 bg-white rounded-lg shadow-xl">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white rounded-t-lg z-10">
            <h3 class="font-bold text-gray-800" id="modalTitle">添加角色</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">角色名称 <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editName" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">描述</label>
                <input type="text" name="description" id="editDescription" class="w-full border rounded px-4 py-2" placeholder="可选">
            </div>

            <div>
                <label class="block text-gray-700 mb-2">权限分配</label>
                <div class="border rounded p-4 space-y-3 bg-gray-50">
                    <?php foreach ($allPermissions as $key => $label): ?>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="permissions[]" value="<?php echo $key; ?>"
                               class="rounded border-gray-300 text-primary focus:ring-primary perm-checkbox"
                               data-perm="<?php echo $key; ?>"
                               onchange="handlePermChange(this)">
                        <span class="text-sm <?php echo $key === '*' ? 'font-bold text-red-600' : 'text-gray-700'; ?>"><?php echo e($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(item = null) {
    const isEdit = !!item;
    document.getElementById('modalTitle').textContent = isEdit ? '编辑角色' : '添加角色';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editName').value = item?.name || '';
    document.getElementById('editDescription').value = item?.description || '';

    // 重置所有复选框
    document.querySelectorAll('.perm-checkbox').forEach(cb => {
        cb.checked = false;
        cb.disabled = false;
    });

    // 设置权限
    if (item?.permissions) {
        const perms = JSON.parse(item.permissions);
        perms.forEach(p => {
            const cb = document.querySelector('.perm-checkbox[data-perm="' + p + '"]');
            if (cb) cb.checked = true;
        });
        // 如果是全部权限，禁用其他
        if (perms.includes('*')) {
            document.querySelectorAll('.perm-checkbox:not([data-perm="*"])').forEach(cb => {
                cb.disabled = true;
                cb.checked = false;
            });
        }
    }

    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function handlePermChange(checkbox) {
    if (checkbox.dataset.perm === '*') {
        // 勾选"全部权限"时禁用并取消其他
        document.querySelectorAll('.perm-checkbox:not([data-perm="*"])').forEach(cb => {
            cb.disabled = checkbox.checked;
            if (checkbox.checked) cb.checked = false;
        });
    }
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('保存成功');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败，请重试', 'error');
    }
});

async function deleteRole(id) {
    if (!confirm('确定要删除该角色吗？')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    try {
        const response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('删除成功');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败，请重试', 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
