<?php
/**
 * SQL执行器插件 - 后台管理页面
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// === 辅助函数 ===

/**
 * 判断是否是查询语句（返回结果集）
 */
function sqlr_isQuery(string $sql): bool
{
    return (bool)preg_match('/^\s*(SELECT|SHOW|PRAGMA|EXPLAIN|DESCRIBE|DESC)\b/i', $sql);
}

/**
 * 拆分SQL为多条语句（正确处理字符串中的分号和注释）
 */
function sqlr_split(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];

        // 字符串内部
        if ($inString) {
            $current .= $char;
            if ($char === $stringChar) {
                // 转义引号 '' 或 ""
                if ($i + 1 < $len && $sql[$i + 1] === $stringChar) {
                    $current .= $sql[++$i];
                } else {
                    $inString = false;
                }
            } elseif ($char === '\\' && $stringChar === "'") {
                // 反斜杠转义
                if ($i + 1 < $len) {
                    $current .= $sql[++$i];
                }
            }
            continue;
        }

        // 单行注释 --
        if ($char === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $eol = strpos($sql, "\n", $i);
            if ($eol === false) break;
            $i = $eol;
            continue;
        }

        // 多行注释 /* */
        if ($char === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            if ($end === false) break;
            $i = $end + 1;
            continue;
        }

        // 字符串开始
        if ($char === "'" || $char === '"') {
            $inString = true;
            $stringChar = $char;
            $current .= $char;
            continue;
        }

        // 语句结束
        if ($char === ';') {
            $t = trim($current);
            if ($t !== '') $statements[] = $t;
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $t = trim($current);
    if ($t !== '') $statements[] = $t;

    return $statements;
}

// === AJAX: 执行SQL ===

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sql_action'] ?? '') === 'execute') {
    header('Content-Type: application/json; charset=utf-8');
    $sql = trim($_POST['sql'] ?? '');

    if ($sql === '') {
        echo json_encode(['code' => 1, 'msg' => '请输入SQL语句']);
        exit;
    }

    $statements = sqlr_split($sql);
    if (empty($statements)) {
        echo json_encode(['code' => 1, 'msg' => '没有有效的SQL语句']);
        exit;
    }

    $results = [];
    foreach ($statements as $stmt) {
        $start = microtime(true);
        $item = ['sql' => mb_substr($stmt, 0, 500), 'time' => 0];

        try {
            if (sqlr_isQuery($stmt)) {
                $rows = db()->fetchAll($stmt);
                $item['type'] = 'query';
                $item['columns'] = !empty($rows) ? array_keys($rows[0]) : [];
                // 截断长值，限制行数
                $displayRows = array_slice($rows, 0, 200);
                foreach ($displayRows as &$row) {
                    foreach ($row as &$val) {
                        if (is_string($val) && mb_strlen($val) > 500) {
                            $val = mb_substr($val, 0, 500) . '…';
                        }
                    }
                }
                unset($row, $val);
                $item['rows'] = $displayRows;
                $item['count'] = count($rows);
                $item['truncated'] = count($rows) > 200;
                $item['success'] = true;
            } else {
                $affected = db()->execute($stmt);
                $item['type'] = 'execute';
                $item['affected'] = $affected;
                $item['success'] = true;
            }
        } catch (\Throwable $e) {
            $item['type'] = 'error';
            $item['success'] = false;
            $item['error'] = $e->getMessage();
        }

        $item['time'] = round(microtime(true) - $start, 4);
        $results[] = $item;
    }

    adminLog('plugin', 'sql-runner', '执行SQL: ' . count($statements) . '条语句');
    echo json_encode(['code' => 0, 'results' => $results], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    exit;
}

// === AJAX: 导入SQL ===

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sql_action'] ?? '') === 'import') {
    header('Content-Type: application/json; charset=utf-8');
    @set_time_limit(300);

    if (empty($_FILES['sqlfile']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE  => '文件超过 upload_max_filesize 限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL   => '文件上传不完整',
            UPLOAD_ERR_NO_FILE   => '未选择文件',
        ];
        $err = $_FILES['sqlfile']['error'] ?? UPLOAD_ERR_NO_FILE;
        echo json_encode(['code' => 1, 'msg' => $errMap[$err] ?? '文件上传失败']);
        exit;
    }

    $file = $_FILES['sqlfile'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'sql') {
        echo json_encode(['code' => 1, 'msg' => '只支持 .sql 文件']);
        exit;
    }

    $content = file_get_contents($file['tmp_name']);
    if ($content === false || trim($content) === '') {
        echo json_encode(['code' => 1, 'msg' => '文件内容为空']);
        exit;
    }

    $statements = sqlr_split($content);
    if (empty($statements)) {
        echo json_encode(['code' => 1, 'msg' => '文件中没有有效的SQL语句']);
        exit;
    }

    $startTime = microtime(true);
    $success = 0;
    $failed = 0;
    $errors = [];

    foreach ($statements as $i => $stmt) {
        try {
            if (sqlr_isQuery($stmt)) {
                db()->fetchAll($stmt);
            } else {
                db()->execute($stmt);
            }
            $success++;
        } catch (\Throwable $e) {
            $failed++;
            if (count($errors) < 50) {
                $errors[] = [
                    'index' => $i + 1,
                    'sql'   => mb_substr($stmt, 0, 200),
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    $totalTime = round(microtime(true) - $startTime, 3);
    adminLog('plugin', 'sql-runner', "导入SQL: {$file['name']} ({$success}成功/{$failed}失败)");

    echo json_encode([
        'code'     => 0,
        'total'    => count($statements),
        'success'  => $success,
        'failed'   => $failed,
        'errors'   => $errors,
        'time'     => $totalTime,
        'filename' => $file['name'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'SQL执行器';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-5xl">
    <!-- 返回按钮 -->
    <div class="mb-4">
        <a href="/admin/plugin.php" class="text-sm text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            返回插件管理
        </a>
    </div>

    <div class="bg-white rounded-lg shadow">
        <!-- Tab 导航 -->
        <div class="flex border-b">
            <button onclick="switchTab('execute')" id="tab-execute" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">执行SQL</button>
            <button onclick="switchTab('import')" id="tab-import" class="px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700">导入SQL</button>
        </div>

        <!-- Tab: 执行SQL -->
        <div id="panel-execute" class="p-6">
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mb-5">
                <strong>注意：</strong>SQL语句将直接在数据库上执行，请确认无误后再操作。
            </div>

            <textarea id="sqlInput" class="w-full border rounded-lg px-4 py-3 font-mono text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary resize-y" rows="10" placeholder="输入SQL语句，支持多条（用分号分隔）&#10;&#10;示例：&#10;SELECT * FROM <?php echo DB_PREFIX; ?>settings LIMIT 10;"></textarea>

            <div class="flex items-center gap-3 mt-3">
                <button onclick="executeSql()" id="btnExecute" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    执行
                </button>
                <button onclick="clearResults()" class="text-sm text-gray-500 hover:text-gray-700">清空结果</button>
                <span class="text-xs text-gray-400 ml-auto">Ctrl + Enter 执行</span>
            </div>

            <div id="executeResults" class="mt-5 space-y-4"></div>
        </div>

        <!-- Tab: 导入SQL -->
        <div id="panel-import" class="p-6" style="display:none">
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-sm text-amber-800 mb-5">
                <strong>注意：</strong>导入会逐条执行SQL文件中的语句，请确保文件来源可靠。建议先备份数据库。
            </div>

            <div class="border-2 border-dashed rounded-lg p-8 text-center" id="dropZone">
                <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                <p class="text-sm text-gray-500 mb-3">选择或拖拽 .sql 文件到此处</p>
                <input type="file" id="sqlFile" accept=".sql" class="hidden" onchange="onFileSelect()">
                <button type="button" onclick="document.getElementById('sqlFile').click()" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded text-sm transition">选择文件</button>
                <div id="fileInfo" class="mt-3 text-sm text-gray-600 hidden"></div>
            </div>

            <div class="mt-4">
                <button onclick="importSql()" id="btnImport" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition inline-flex items-center gap-2" disabled>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    开始导入
                </button>
            </div>

            <div id="importResults" class="mt-5"></div>
        </div>
    </div>
</div>

<script>
var _csrfToken = '<?php echo csrfToken(); ?>';

// === Tab 切换 ===

function switchTab(tab) {
    document.getElementById('panel-execute').style.display = tab === 'execute' ? '' : 'none';
    document.getElementById('panel-import').style.display = tab === 'import' ? '' : 'none';

    var active = 'px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary';
    var inactive = 'px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700';
    document.getElementById('tab-execute').className = tab === 'execute' ? active : inactive;
    document.getElementById('tab-import').className = tab === 'import' ? active : inactive;
}

// === 执行SQL ===

async function executeSql() {
    var sql = document.getElementById('sqlInput').value.trim();
    if (!sql) { showMessage('请输入SQL语句', 'error'); return; }

    var btn = document.getElementById('btnExecute');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> 执行中…';

    var fd = new FormData();
    fd.append('_token', _csrfToken);
    fd.append('sql_action', 'execute');
    fd.append('sql', sql);

    try {
        var resp = await fetch('', {method: 'POST', body: fd});
        var data = await resp.json();
        if (data.code !== 0) {
            showMessage(data.msg, 'error');
        } else {
            renderExecuteResults(data.results);
        }
    } catch (e) {
        showMessage('请求失败: ' + e.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg> 执行';
}

function renderExecuteResults(results) {
    var container = document.getElementById('executeResults');
    container.innerHTML = '';

    results.forEach(function (r, i) {
        var div = document.createElement('div');

        if (r.success && r.type === 'query') {
            div.className = 'border-l-4 border-blue-400 pl-4';
            var html = '<div class="text-xs text-gray-500 mb-2 font-mono truncate" title="' + esc(r.sql) + '">#' + (i + 1) + ' ' + esc(r.sql) + '</div>';

            if (r.columns.length === 0) {
                html += '<p class="text-sm text-gray-500">查询返回 0 行</p>';
            } else {
                html += '<div class="overflow-x-auto border rounded">';
                html += '<table class="min-w-full text-sm">';
                html += '<thead><tr class="bg-gray-50">';
                r.columns.forEach(function (col) {
                    html += '<th class="px-3 py-2 text-left font-medium text-gray-600 whitespace-nowrap border-b">' + esc(col) + '</th>';
                });
                html += '</tr></thead><tbody>';
                r.rows.forEach(function (row, ri) {
                    html += '<tr class="' + (ri % 2 ? 'bg-gray-50/50' : '') + '">';
                    r.columns.forEach(function (col) {
                        var val = row[col];
                        if (val === null) {
                            html += '<td class="px-3 py-1.5 border-b text-gray-400 italic whitespace-nowrap">NULL</td>';
                        } else {
                            var s = String(val);
                            var display = s.length > 200 ? esc(s.substring(0, 200)) + '…' : esc(s);
                            html += '<td class="px-3 py-1.5 border-b max-w-xs truncate" title="' + esc(s.substring(0, 500)) + '">' + display + '</td>';
                        }
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            }

            html += '<p class="text-xs text-gray-500 mt-1">' + r.count + ' 行' + (r.truncated ? '（仅显示前200行）' : '') + '，' + r.time + '秒</p>';
            div.innerHTML = html;
        } else if (r.success && r.type === 'execute') {
            div.className = 'border-l-4 border-green-400 pl-4';
            div.innerHTML = '<div class="text-xs text-gray-500 font-mono truncate" title="' + esc(r.sql) + '">#' + (i + 1) + ' ' + esc(r.sql) + '</div>'
                + '<p class="text-sm text-green-700 mt-1">执行成功，影响 ' + r.affected + ' 行 (' + r.time + '秒)</p>';
        } else {
            div.className = 'border-l-4 border-red-400 pl-4';
            div.innerHTML = '<div class="text-xs text-gray-500 font-mono truncate" title="' + esc(r.sql) + '">#' + (i + 1) + ' ' + esc(r.sql) + '</div>'
                + '<p class="text-sm text-red-600 mt-1">' + esc(r.error) + '</p>';
        }

        container.appendChild(div);
    });
}

function clearResults() {
    document.getElementById('executeResults').innerHTML = '';
}

// === 文件选择 ===

function onFileSelect() {
    var input = document.getElementById('sqlFile');
    var info = document.getElementById('fileInfo');
    var btn = document.getElementById('btnImport');

    if (input.files.length > 0) {
        var file = input.files[0];
        var size = file.size < 1024 ? file.size + ' B'
            : file.size < 1048576 ? (file.size / 1024).toFixed(1) + ' KB'
            : (file.size / 1048576).toFixed(1) + ' MB';
        info.textContent = file.name + ' (' + size + ')';
        info.classList.remove('hidden');
        btn.disabled = false;
    } else {
        info.classList.add('hidden');
        btn.disabled = true;
    }
}

// 拖拽上传
(function () {
    var zone = document.getElementById('dropZone');
    if (!zone) return;
    ['dragenter', 'dragover'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) {
            e.preventDefault();
            zone.classList.add('border-primary', 'bg-blue-50/50');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        zone.addEventListener(ev, function (e) {
            e.preventDefault();
            zone.classList.remove('border-primary', 'bg-blue-50/50');
        });
    });
    zone.addEventListener('drop', function (e) {
        var files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('sqlFile').files = files;
            onFileSelect();
        }
    });
})();

// === 导入SQL ===

async function importSql() {
    var input = document.getElementById('sqlFile');
    if (!input.files.length) { showMessage('请选择SQL文件', 'error'); return; }

    if (!confirm('确定要导入此SQL文件吗？\n文件中的语句将逐条执行，请确保已备份数据库。')) return;

    var btn = document.getElementById('btnImport');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> 导入中…';

    var fd = new FormData();
    fd.append('_token', _csrfToken);
    fd.append('sql_action', 'import');
    fd.append('sqlfile', input.files[0]);

    try {
        var resp = await fetch('', {method: 'POST', body: fd});
        var data = await resp.json();
        renderImportResults(data);
    } catch (e) {
        showMessage('导入失败: ' + e.message, 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg> 开始导入';
}

function renderImportResults(data) {
    var container = document.getElementById('importResults');

    if (data.code !== 0) {
        container.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">' + esc(data.msg) + '</div>';
        return;
    }

    var color = data.failed > 0 ? 'yellow' : 'green';
    var html = '<div class="bg-' + color + '-50 border border-' + color + '-200 rounded-lg p-4">';
    html += '<h3 class="font-medium text-gray-800 mb-2">导入完成</h3>';
    html += '<div class="text-sm text-gray-600 space-y-1">';
    html += '<p>文件：' + esc(data.filename) + '</p>';
    html += '<p>总语句：<strong>' + data.total + '</strong> | 成功：<strong class="text-green-700">' + data.success + '</strong> | 失败：<strong class="text-red-600">' + data.failed + '</strong> | 耗时：' + data.time + '秒</p>';
    html += '</div>';

    if (data.errors && data.errors.length > 0) {
        html += '<div class="mt-3 border-t pt-3">';
        html += '<p class="text-sm font-medium text-gray-700 mb-2">失败详情：</p>';
        html += '<div class="space-y-2 text-sm max-h-64 overflow-y-auto">';
        data.errors.forEach(function (err) {
            html += '<div class="bg-white/60 rounded p-2">';
            html += '<span class="text-gray-500">#' + err.index + '</span> ';
            html += '<code class="text-xs text-gray-600">' + esc(err.sql) + '</code>';
            html += '<br><span class="text-red-600">' + esc(err.error) + '</span>';
            html += '</div>';
        });
        html += '</div></div>';
    }

    html += '</div>';
    container.innerHTML = html;
}

// === 工具函数 ===

function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// 键盘快捷键
document.getElementById('sqlInput').addEventListener('keydown', function (e) {
    // Tab 插入4空格
    if (e.key === 'Tab') {
        e.preventDefault();
        var start = this.selectionStart;
        var end = this.selectionEnd;
        this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
        this.selectionStart = this.selectionEnd = start + 4;
    }
    // Ctrl+Enter 执行
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        executeSql();
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
