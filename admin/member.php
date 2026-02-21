<?php
/**
 * Yikai CMS - 后台会员管理
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

        if ($id <= 0) error('无效的会员ID');

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            error('请输入正确的邮箱');
        }

        if (!memberModel()->isEmailUnique($data['email'], $id)) {
            error('邮箱已被其他账号使用');
        }

        // 重置密码
        $newPassword = post('new_password');
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                error('密码至少8位');
            }
            $data['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }

        memberModel()->updateById($id, $data);
        adminLog('member', 'update', "更新会员ID: $id");
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

$pageTitle = '会员管理';
$currentMenu = 'member';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/member.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">会员列表</a>
        <a href="/admin/setting_member.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">会员设置</a>
    </div>
</div>

<!-- 搜索栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <form method="get" class="p-4 flex gap-4 items-center">
        <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
               class="border rounded px-4 py-2 w-64" placeholder="搜索用户名 / 邮箱 / 昵称">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded">搜索</button>
        <?php if ($keyword): ?>
        <a href="/admin/member.php" class="text-gray-500 hover:text-gray-700 text-sm">清除</a>
        <?php endif; ?>
        <span class="text-gray-400 text-sm ml-auto">共 <?php echo $total; ?> 位会员</span>
    </form>
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
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">注册时间</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">最后登录</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
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
                            <?php echo $item['status'] ? '正常' : '禁用'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2">编辑</button>
                        <button onclick="deleteMember(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($members)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">暂无会员</td>
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
            <h3 class="font-bold text-gray-800" id="modalTitle">编辑会员</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">用户名</label>
                <input type="text" id="editUsername" disabled class="w-full border rounded px-4 py-2 bg-gray-50 text-gray-500">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">昵称</label>
                <input type="text" name="nickname" id="editNickname" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">邮箱</label>
                <input type="email" name="email" id="editEmail" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">重置密码</label>
                <input type="text" name="new_password" id="editPassword" class="w-full border rounded px-4 py-2" placeholder="留空不修改，至少8位">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">状态</label>
                <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                    <option value="1">正常</option>
                    <option value="0">禁用</option>
                </select>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(item) {
    document.getElementById('editId').value = item.id;
    document.getElementById('editUsername').value = item.username;
    document.getElementById('editNickname').value = item.nickname || '';
    document.getElementById('editEmail').value = item.email || '';
    document.getElementById('editPassword').value = '';
    document.getElementById('editStatus').value = item.status;
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
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-green-100 text-green-600';
            btn.textContent = '正常';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-red-100 text-red-600';
            btn.textContent = '禁用';
        }
    }
}

async function deleteMember(id) {
    if (!confirm('确定要删除该会员吗？')) return;
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
