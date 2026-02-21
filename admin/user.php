<?php
/**
 * Yikai CMS - 用户管理
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
        <a href="/admin/user.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">管理员列表</a>
        <a href="/admin/role.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">角色管理</a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">用户名</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">昵称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">邮箱</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">角色</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">最后登录</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
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
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            编辑</button>
                        <?php if ($item['id'] !== (int)$_SESSION['admin_id']): ?>
                        <button onclick="deleteUser(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            删除</button>
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
            <h3 class="font-bold text-gray-800" id="modalTitle">添加管理员</h3>
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
                        <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-1" id="pwdHint">至少6位</p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">昵称</label>
                <input type="text" name="nickname" id="editNickname" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">邮箱</label>
                <input type="email" name="email" id="editEmail" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">角色</label>
                    <select name="role_id" id="editRoleId" class="w-full border rounded px-4 py-2">
                        <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo e($role['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">状态</label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1">正常</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存</button>
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
        showMessage('保存成功');
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
            btn.textContent = '禁用';
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
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
