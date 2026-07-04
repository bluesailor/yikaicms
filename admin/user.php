<?php
/**
 * YikaiCMS - 用户管理
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

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $username = post('username');
        $password = post('password');
        $nickname = post('nickname');
        $email = post('email');
        $roleId = postInt('role_id', 1);
        $status = postInt('status', 1);

        if (empty($username)) {
            error('请输入用户名');
        }

        // 检查用户名重复
        if (!userModel()->isUsernameUnique($username, $id)) {
            error('用户名已存在');
        }

        $data = [
            'username' => $username,
            'nickname' => $nickname ?: $username,
            'email' => $email,
            'role_id' => $roleId,
            'status' => $status,
            'updated_at' => time(),
        ];

        if ($id > 0) {
            if (!empty($password)) {
                if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                    error('密码至少8位，且必须包含字母和数字');
                }
                $data['password'] = password_hash($password, PASSWORD_BCRYPT);
            }
            userModel()->updateById($id, $data);
            adminLog('user', 'update', "更新用户ID: $id");
        } else {
            if (empty($password)) {
                error('请输入密码');
            }
            if (strlen($password) < 8 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
                error('密码至少8位，且必须包含字母和数字');
            }
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
            $data['created_at'] = time();
            $id = userModel()->create($data);
            adminLog('user', 'create', "创建用户ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');

        if ($id === (int)$_SESSION['admin_id']) {
            error('不能删除当前登录用户');
        }

        userModel()->deleteById($id);
        adminLog('user', 'delete', "删除用户ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');

        if ($id === (int)$_SESSION['admin_id']) {
            error('不能禁用当前登录用户');
        }

        $newStatus = userModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    exit;
}

// 获取角色列表
$roles = roleModel()->getActive();
$roleMap = [];
foreach ($roles as $role) {
    $roleMap[$role['id']] = $role['name'];
}

// 获取用户列表
$users = userModel()->all('id ASC');

$pageTitle = '管理员';
$currentMenu = 'user';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/user.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('user_list'); ?></a>
        <a href="/admin/role.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('user_roles'); ?></a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            添加管理员
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('user_username'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('user_nickname'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('user_email'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('user_role'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('user_last_login'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($users as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3 font-medium"><?php echo e($item['username']); ?></td>
                    <td class="px-4 py-3"><?php echo e($item['nickname'] ?: '-'); ?></td>
                    <td class="px-4 py-3 text-sm"><?php echo e($item['email'] ?: '-'); ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                            <?php echo $roleMap[$item['role_id']] ?? '未知'; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>"
                                <?php echo $item['id'] === (int)$_SESSION['admin_id'] ? 'disabled' : ''; ?>>
                            <?php echo $item['status'] ? '正常' : '禁用'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500">
                        <?php echo $item['last_login_time'] ? date('Y-m-d H:i', (int)$item['last_login_time']) : '-'; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo __('admin_edit'); ?></button>
                        <?php if ($item['id'] !== (int)$_SESSION['admin_id']): ?>
                        <button onclick="deleteUser(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i>
                            <?php echo __('admin_delete'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('user_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">用户名 <span class="text-red-500">*</span></label>
                <input type="text" name="username" id="editUsername" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">密码 <span id="pwdRequired" class="text-red-500">*</span></label>
                <div class="relative pwd-toggle">
                    <input type="password" name="password" id="editPassword" class="w-full border rounded px-4 py-2 pr-10" minlength="6">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="ti ti-eye text-lg eye-open hidden"></i>
                        <i class="ti ti-eye-off text-lg eye-closed"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1" id="pwdHint">至少6位</p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('user_nickname'); ?></label>
                <input type="text" name="nickname" id="editNickname" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('user_email'); ?></label>
                <input type="email" name="email" id="editEmail" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('user_role'); ?></label>
                    <select name="role_id" id="editRoleId" class="w-full border rounded px-4 py-2">
                        <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo e($role['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo __('user_status_normal'); ?></option>
                        <option value="0"><?php echo __('admin_disabled'); ?></option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('admin_save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(item = null) {
    const isEdit = !!item;
    document.getElementById('modalTitle').textContent = isEdit ? '编辑管理员' : '添加管理员';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editUsername').value = item?.username || '';
    document.getElementById('editPassword').value = '';
    document.getElementById('editNickname').value = item?.nickname || '';
    document.getElementById('editEmail').value = item?.email || '';
    document.getElementById('editRoleId').value = item?.role_id || 1;
    document.getElementById('editStatus').value = item?.status ?? 1;

    // 编辑时密码非必填
    document.getElementById('pwdRequired').style.display = isEdit ? 'none' : 'inline';
    document.getElementById('editPassword').required = !isEdit;
    document.getElementById('pwdHint').textContent = isEdit ? '留空则不修改密码' : '至少6位';

    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_saved'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
});

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '正常';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '<?php echo __('admin_disabled'); ?>';
        }
    } else {
        showMessage(data.msg, 'error');
    }
}

async function deleteUser(id) {
    if (!confirm('确定要删除该用户吗？')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
