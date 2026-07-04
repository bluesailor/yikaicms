<?php
/**
 * YikaiCMS - 后台会员管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('member');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'nickname' => post('nickname'),
            'email'    => post('email'),
            'status'   => postInt('status', 1),
        ];

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            error(__('member_err_invalid_email'));
        }

        if (!memberModel()->isEmailUnique($data['email'], $id)) {
            error(__('member_err_email_taken'));
        }

        if ($id > 0) {
            // 重置密码
            $newPassword = post('new_password');
            if (!empty($newPassword)) {
                if (strlen($newPassword) < 8) {
                    error(__('member_err_password_short'));
                }
                $data['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            memberModel()->updateById($id, $data);
            adminLog('member', 'update', "更新会员ID: $id");
            success();
        }

        // 新增会员
        $username = trim((string)post('username'));
        $password = (string)post('new_password');

        if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
            error(__('member_err_username_length'));
        }
        if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
            error(__('member_err_username_format'));
        }
        if (!memberModel()->isUsernameUnique($username)) {
            error(__('member_err_username_taken'));
        }
        if (strlen($password) < 8) {
            error(__('member_err_password_short'));
        }

        $data['username']   = $username;
        $data['password']   = password_hash($password, PASSWORD_DEFAULT);
        $data['nickname']   = $data['nickname'] !== '' ? $data['nickname'] : $username;
        $data['created_at'] = time();

        $newId = (int)memberModel()->create($data);
        adminLog('member', 'create', "新增会员ID: $newId");
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');
        memberModel()->deleteById($id);
        adminLog('member', 'delete', "删除会员ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = memberModel()->toggle($id, 'status');
        adminLog('member', 'update', "切换会员状态ID: $id");
        success(['status' => $newStatus]);
    }

    exit;
}

// 列表参数
$page = max(1, getInt('page', 1));
$perPage = 20;
$keyword = get('keyword', '');

$conditions = [];
$params = [];

if (!empty($keyword)) {
    $conditions[] = '(username LIKE ? OR email LIKE ? OR nickname LIKE ?)';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$whereSQL = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$tableName = DB_PREFIX . 'members';

$total = (int)db()->fetchColumn(
    "SELECT COUNT(*) FROM {$tableName} {$whereSQL}",
    $params
);

$offset = ($page - 1) * $perPage;
$members = db()->fetchAll(
    "SELECT * FROM {$tableName} {$whereSQL} ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$totalPages = (int)ceil($total / $perPage);

$pageTitle = __('admin_member');
$currentMenu = 'member';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/member.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('member_list'); ?></a>
        <a href="/admin/setting_member.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('member_settings'); ?></a>
    </div>
</div>

<!-- 搜索栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <form method="get" class="p-4 flex gap-4 items-center">
        <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
               class="border rounded px-4 py-2 w-64" placeholder="<?php echo __('member_search_ph'); ?>">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded"><?php echo __('btn_search'); ?></button>
        <?php if ($keyword): ?>
        <a href="/admin/member.php" class="text-gray-500 hover:text-gray-700 text-sm"><?php echo __('member_clear'); ?></a>
        <?php endif; ?>
        <button type="button" onclick="openAddModal()" class="ml-auto bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo __('member_add'); ?>
        </button>
        <span class="text-gray-400 text-sm"><?php echo __('member_total', ['count' => $total]); ?></span>
    </form>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('member_field_username'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('member_field_nickname'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('member_field_email'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('member_reg_time'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('member_field_last_login'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($members as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3 font-medium"><?php echo e($item['username']); ?></td>
                    <td class="px-4 py-3 text-gray-600"><?php echo e($item['nickname']); ?></td>
                    <td class="px-4 py-3 text-gray-500 text-sm"><?php echo e($item['email']); ?></td>
                    <td class="px-4 py-3 text-center text-gray-500 text-sm"><?php echo $item['created_at'] ? date('Y-m-d', (int)$item['created_at']) : '-'; ?></td>
                    <td class="px-4 py-3 text-center text-gray-500 text-sm"><?php echo $item['last_login_time'] ? date('Y-m-d H:i', (int)$item['last_login_time']) : '-'; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                            <?php echo $item['status'] ? __('user_status_normal') : __('admin_disabled'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2"><?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteMember(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm"><?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500"><?php echo __('member_empty'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<div class="mt-6 flex justify-center">
    <nav class="flex items-center gap-1">
        <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>&keyword=<?php echo urlencode($keyword); ?>" class="px-3 py-2 border rounded hover:bg-gray-50 text-sm">&laquo;</a>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
        <a href="?page=<?php echo $i; ?>&keyword=<?php echo urlencode($keyword); ?>"
           class="px-3 py-2 border rounded text-sm <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>&keyword=<?php echo urlencode($keyword); ?>" class="px-3 py-2 border rounded hover:bg-gray-50 text-sm">&raquo;</a>
        <?php endif; ?>
    </nav>
</div>
<?php endif; ?>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('member_edit_title'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('member_field_username'); ?></label>
                <input type="text" name="username" id="editUsername" class="w-full border rounded px-4 py-2" placeholder="<?php echo __('member_username_ph'); ?>">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('member_field_nickname'); ?></label>
                <input type="text" name="nickname" id="editNickname" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('member_field_email'); ?></label>
                <input type="email" name="email" id="editEmail" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1" id="editPasswordLabel"><?php echo __('member_field_reset_password'); ?></label>
                <input type="text" name="new_password" id="editPassword" class="w-full border rounded px-4 py-2" placeholder="<?php echo __('member_reset_password_ph'); ?>">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                    <option value="1"><?php echo __('user_status_normal'); ?></option>
                    <option value="0"><?php echo __('admin_disabled'); ?></option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded"><?php echo __('btn_save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const I18N = {
    edit_title: <?php echo json_encode(__('member_edit_title'), JSON_UNESCAPED_UNICODE); ?>,
    add_title: <?php echo json_encode(__('member_add'), JSON_UNESCAPED_UNICODE); ?>,
    pwd_label_reset: <?php echo json_encode(__('member_field_reset_password'), JSON_UNESCAPED_UNICODE); ?>,
    pwd_label_set: <?php echo json_encode(__('member_field_password'), JSON_UNESCAPED_UNICODE); ?>,
    pwd_ph_reset: <?php echo json_encode(__('member_reset_password_ph'), JSON_UNESCAPED_UNICODE); ?>,
    pwd_ph_set: <?php echo json_encode(__('member_password_ph'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete: <?php echo json_encode(__('member_confirm_delete'), JSON_UNESCAPED_UNICODE); ?>,
    status_normal: <?php echo json_encode(__('user_status_normal'), JSON_UNESCAPED_UNICODE); ?>,
    status_disabled: <?php echo json_encode(__('admin_disabled'), JSON_UNESCAPED_UNICODE); ?>,
    saved: <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>,
    deleted: <?php echo json_encode(__('admin_deleted'), JSON_UNESCAPED_UNICODE); ?>,
};

function openEditModal(item) {
    document.getElementById('modalTitle').textContent = I18N.edit_title;
    document.getElementById('editId').value = item.id;
    const usernameInput = document.getElementById('editUsername');
    usernameInput.value = item.username;
    usernameInput.readOnly = true;
    usernameInput.classList.add('bg-gray-50', 'text-gray-500');
    document.getElementById('editNickname').value = item.nickname || '';
    document.getElementById('editEmail').value = item.email || '';
    const pwd = document.getElementById('editPassword');
    pwd.value = '';
    pwd.required = false;
    pwd.placeholder = I18N.pwd_ph_reset;
    document.getElementById('editPasswordLabel').textContent = I18N.pwd_label_reset;
    document.getElementById('editStatus').value = item.status;
    document.getElementById('editModal').classList.remove('hidden');
}

function openAddModal() {
    document.getElementById('modalTitle').textContent = I18N.add_title;
    document.getElementById('editId').value = 0;
    const usernameInput = document.getElementById('editUsername');
    usernameInput.value = '';
    usernameInput.readOnly = false;
    usernameInput.classList.remove('bg-gray-50', 'text-gray-500');
    document.getElementById('editNickname').value = '';
    document.getElementById('editEmail').value = '';
    const pwd = document.getElementById('editPassword');
    pwd.value = '';
    pwd.required = true;
    pwd.placeholder = I18N.pwd_ph_set;
    document.getElementById('editPasswordLabel').textContent = I18N.pwd_label_set;
    document.getElementById('editStatus').value = 1;
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
        showMessage(I18N.saved);
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
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-green-100 text-green-600';
            btn.textContent = I18N.status_normal;
        } else {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-red-100 text-red-600';
            btn.textContent = I18N.status_disabled;
        }
    }
}

async function deleteMember(id) {
    if (!confirm(I18N.confirm_delete)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage(I18N.deleted);
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
