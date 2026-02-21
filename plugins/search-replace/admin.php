<?php
/**
 * 搜索替换插件 - 后台管理页面
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 获取所有表（带前缀的）
$allTables = [];
if (db()->isSqlite()) {
    $rows = db()->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ?", [DB_PREFIX . '%']);
    foreach ($rows as $row) {
        $allTables[] = $row['name'];
    }
} else {
    $rows = db()->fetchAll("SHOW TABLES LIKE '" . DB_PREFIX . "%'");
    foreach ($rows as $row) {
        $allTables[] = array_values($row)[0];
    }
}
sort($allTables);

// 辅助函数：获取表的字段信息
function sr_getColumns(string $table): array {
    if (db()->isSqlite()) {
        $cols = db()->fetchAll("PRAGMA table_info(`{$table}`)");
        $result = [];
        foreach ($cols as $col) {
            $result[] = ['Field' => $col['name'], 'Type' => strtolower($col['type'] ?: 'text')];
        }
        return $result;
    }
    return db()->fetchAll("SHOW COLUMNS FROM `{$table}`");
}

// 辅助函数：检查字段是否是文本类型
function sr_isTextColumn(string $type): bool {
    $type = strtolower($type);
    return str_contains($type, 'char') || str_contains($type, 'text')
        || str_contains($type, 'json') || str_contains($type, 'clob')
        || $type === '';
}

// AJAX: 获取表的字段列表
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sr_action'] ?? '') === 'get_columns') {
    header('Content-Type: application/json; charset=utf-8');
    $table = $_POST['table'] ?? '';
    if (!in_array($table, $allTables)) {
        echo json_encode(['code' => 1, 'msg' => '无效的表']);
        exit;
    }
    $cols = sr_getColumns($table);
    $textCols = [];
    foreach ($cols as $col) {
        if (sr_isTextColumn($col['Type'])) {
            $textCols[] = $col['Field'];
        }
    }
    echo json_encode(['code' => 0, 'data' => $textCols]);
    exit;
}

// AJAX: 预览匹配数
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sr_action'] ?? '') === 'preview') {
    header('Content-Type: application/json; charset=utf-8');
    $table = $_POST['table'] ?? '';
    $column = $_POST['column'] ?? '';
    $search = $_POST['search'] ?? '';

    if (!in_array($table, $allTables) || empty($column) || $search === '') {
        echo json_encode(['code' => 1, 'msg' => '参数不完整']);
        exit;
    }

    // 验证字段存在
    $cols = sr_getColumns($table);
    $validCol = false;
    foreach ($cols as $col) {
        if ($col['Field'] === $column) {
            $validCol = true;
            break;
        }
    }
    if (!$validCol) {
        echo json_encode(['code' => 1, 'msg' => '无效的字段']);
        exit;
    }

    $count = db()->fetchOne(
        "SELECT COUNT(*) as cnt FROM `{$table}` WHERE `{$column}` LIKE ?",
        ['%' . $search . '%']
    );
    echo json_encode(['code' => 0, 'count' => (int)($count['cnt'] ?? 0)]);
    exit;
}

// AJAX: 执行替换
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sr_action'] ?? '') === 'replace') {
    header('Content-Type: application/json; charset=utf-8');
    $table = $_POST['table'] ?? '';
    $column = $_POST['column'] ?? '';
    $search = $_POST['search'] ?? '';
    $replace = $_POST['replace'] ?? '';

    if (!in_array($table, $allTables) || empty($column) || $search === '') {
        echo json_encode(['code' => 1, 'msg' => '参数不完整']);
        exit;
    }

    // 验证字段
    $cols = sr_getColumns($table);
    $validCol = false;
    foreach ($cols as $col) {
        if ($col['Field'] === $column) {
            $validCol = true;
            break;
        }
    }
    if (!$validCol) {
        echo json_encode(['code' => 1, 'msg' => '无效的字段']);
        exit;
    }

    try {
        $affected = db()->execute(
            "UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, ?, ?) WHERE `{$column}` LIKE ?",
            [$search, $replace, '%' . $search . '%']
        );
        adminLog('plugin', 'search-replace', "替换 {$table}.{$column}: '{$search}' => '{$replace}' ({$affected}行)");
        echo json_encode(['code' => 0, 'affected' => $affected, 'msg' => "成功替换 {$affected} 行数据"]);
    } catch (\Throwable $e) {
        echo json_encode(['code' => 1, 'msg' => '替换失败: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = '搜索替换';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl">
    <!-- 返回按钮 -->
    <div class="mb-4">
        <a href="/admin/plugin.php" class="text-sm text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            返回插件管理
        </a>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">数据库搜索替换</h2>
            <p class="text-sm text-gray-500 mt-1">在指定的数据库表和字段中批量搜索并替换文本内容。</p>
        </div>

        <div class="p-6 space-y-5">
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
                操作不可逆，请先备份数据库再执行替换。
            </div>

            <!-- 选择表 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">选择表</label>
                <select id="srTable" onchange="loadColumns()" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                    <option value="">-- 请选择表 --</option>
                    <?php foreach ($allTables as $t): ?>
                    <option value="<?php echo e($t); ?>"><?php echo e($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 选择字段 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">选择字段</label>
                <select id="srColumn" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary" disabled>
                    <option value="">-- 先选择表 --</option>
                </select>
            </div>

            <!-- 搜索文本 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">搜索文本</label>
                <input type="text" id="srSearch" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="输入要搜索的文本...">
            </div>

            <!-- 替换文本 -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">替换为</label>
                <input type="text" id="srReplace" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary" placeholder="输入替换后的文本...（留空则删除匹配内容）">
            </div>

            <!-- 操作按钮 -->
            <div class="flex items-center gap-3 pt-2">
                <button onclick="previewReplace()" class="bg-gray-600 hover:bg-gray-700 text-white px-5 py-2 rounded transition">
                    预览匹配
                </button>
                <button id="btnReplace" onclick="executeReplace()" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2 rounded transition" disabled>
                    执行替换
                </button>
                <span id="srResult" class="text-sm text-gray-500"></span>
            </div>
        </div>
    </div>
</div>

<script>
var _csrfToken = '<?php echo csrfToken(); ?>';

async function loadColumns() {
    var table = document.getElementById('srTable').value;
    var sel = document.getElementById('srColumn');
    sel.innerHTML = '<option value="">加载中...</option>';
    sel.disabled = true;

    if (!table) {
        sel.innerHTML = '<option value="">-- 先选择表 --</option>';
        return;
    }

    var fd = new FormData();
    fd.append('_token', _csrfToken);
    fd.append('sr_action', 'get_columns');
    fd.append('table', table);

    try {
        var resp = await fetch('', { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.code === 0 && data.data.length) {
            sel.innerHTML = '<option value="">-- 请选择字段 --</option>';
            data.data.forEach(function(col) {
                sel.innerHTML += '<option value="' + col + '">' + col + '</option>';
            });
            sel.disabled = false;
        } else {
            sel.innerHTML = '<option value="">该表没有文本字段</option>';
        }
    } catch(e) {
        sel.innerHTML = '<option value="">加载失败</option>';
    }
}

async function previewReplace() {
    var table = document.getElementById('srTable').value;
    var column = document.getElementById('srColumn').value;
    var search = document.getElementById('srSearch').value;
    var result = document.getElementById('srResult');
    var btn = document.getElementById('btnReplace');

    if (!table || !column || !search) {
        showMessage('请填写表、字段和搜索文本', 'error');
        return;
    }

    result.textContent = '查询中...';
    result.className = 'text-sm text-gray-500';

    var fd = new FormData();
    fd.append('_token', _csrfToken);
    fd.append('sr_action', 'preview');
    fd.append('table', table);
    fd.append('column', column);
    fd.append('search', search);

    try {
        var resp = await fetch('', { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.code === 0) {
            if (data.count > 0) {
                result.textContent = '找到 ' + data.count + ' 行匹配数据';
                result.className = 'text-sm text-blue-600 font-medium';
                btn.disabled = false;
            } else {
                result.textContent = '未找到匹配数据';
                result.className = 'text-sm text-gray-500';
                btn.disabled = true;
            }
        } else {
            result.textContent = data.msg;
            result.className = 'text-sm text-red-600';
            btn.disabled = true;
        }
    } catch(e) {
        result.textContent = '查询失败';
        result.className = 'text-sm text-red-600';
    }
}

async function executeReplace() {
    var table = document.getElementById('srTable').value;
    var column = document.getElementById('srColumn').value;
    var search = document.getElementById('srSearch').value;
    var replace = document.getElementById('srReplace').value;
    var result = document.getElementById('srResult');
    var btn = document.getElementById('btnReplace');

    if (!confirm('确定要执行替换吗？此操作不可逆！\n\n表: ' + table + '\n字段: ' + column + '\n搜索: ' + search + '\n替换: ' + (replace || '(删除)'))) {
        return;
    }

    btn.disabled = true;
    btn.textContent = '替换中...';
    result.textContent = '';

    var fd = new FormData();
    fd.append('_token', _csrfToken);
    fd.append('sr_action', 'replace');
    fd.append('table', table);
    fd.append('column', column);
    fd.append('search', search);
    fd.append('replace', replace);

    try {
        var resp = await fetch('', { method: 'POST', body: fd });
        var data = await resp.json();
        if (data.code === 0) {
            result.textContent = data.msg;
            result.className = 'text-sm text-green-600 font-medium';
            showMessage(data.msg);
        } else {
            result.textContent = data.msg;
            result.className = 'text-sm text-red-600';
            showMessage(data.msg, 'error');
        }
    } catch(e) {
        result.textContent = '替换失败';
        result.className = 'text-sm text-red-600';
    }

    btn.textContent = '执行替换';
    btn.disabled = true;
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
