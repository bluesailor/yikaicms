<?php
/**
 * Yikai CMS - 插件管理
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

// 确保 plugins 表存在
if (!db()->tableExists('plugins')) {
    db()->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "plugins` (
        `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `slug` varchar(100) NOT NULL COMMENT '插件标识',
        `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0禁用 1启用',
        `installed_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '安装时间',
        `activated_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '启用时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件表'");
}

// AJAX 操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $slug = trim($_POST['slug'] ?? '');

    // 验证 slug 格式
    if ($action !== 'upload' && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug)) {
        echo json_encode(['code' => 1, 'msg' => '无效的插件标识']);
        exit;
    }

    switch ($action) {
        case 'activate':
            $exists = pluginModel()->findBySlug($slug);
            if ($exists) {
                pluginModel()->updateById((int)$exists['id'], [
                    'status' => 1,
                    'activated_at' => time()
                ]);
            } else {
                pluginModel()->create([
                    'slug' => $slug,
                    'status' => 1,
                    'installed_at' => time(),
                    'activated_at' => time()
                ]);
            }
            adminLog('plugin', 'activate', '启用插件: ' . $slug);
            echo json_encode(['code' => 0, 'msg' => '插件已启用']);
            break;

        case 'deactivate':
            pluginModel()->deactivate($slug);
            adminLog('plugin', 'deactivate', '禁用插件: ' . $slug);
            echo json_encode(['code' => 0, 'msg' => '插件已禁用']);
            break;

        case 'delete':
            // 删除数据库记录
            pluginModel()->query(
                'DELETE FROM ' . pluginModel()->tableName() . ' WHERE slug = ?', [$slug]
            );
            // 删除目录
            $deleted = deletePluginDir($slug);
            adminLog('plugin', 'delete', '删除插件: ' . $slug);
            echo json_encode([
                'code' => 0,
                'msg' => $deleted ? '插件已删除' : '插件记录已清除，但目录删除失败'
            ]);
            break;

        case 'upload':
            if (empty($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['code' => 1, 'msg' => '请选择有效的ZIP文件']);
                exit;
            }

            $file = $_FILES['plugin_zip'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'zip') {
                echo json_encode(['code' => 1, 'msg' => '仅支持ZIP格式']);
                exit;
            }

            if (!class_exists('ZipArchive')) {
                echo json_encode(['code' => 1, 'msg' => 'PHP未安装zip扩展']);
                exit;
            }

            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) {
                echo json_encode(['code' => 1, 'msg' => 'ZIP文件无法打开']);
                exit;
            }

            // 查找 plugin.json 来确定插件 slug
            $pluginSlug = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                // 匹配 slug/plugin.json 格式
                if (preg_match('#^([a-z0-9][a-z0-9\-]*[a-z0-9]|[a-z0-9])/plugin\.json$#', $name, $m)) {
                    $pluginSlug = $m[1];
                    break;
                }
            }

            if (!$pluginSlug) {
                $zip->close();
                echo json_encode(['code' => 1, 'msg' => 'ZIP中未找到有效的 plugin.json，请确保ZIP结构为 插件名/plugin.json']);
                exit;
            }

            // 验证 plugin.json 内容
            $jsonContent = $zip->getFromName($pluginSlug . '/plugin.json');
            $meta = json_decode($jsonContent, true);
            if (!is_array($meta) || empty($meta['name'])) {
                $zip->close();
                echo json_encode(['code' => 1, 'msg' => 'plugin.json 格式无效，缺少 name 字段']);
                exit;
            }

            // 解压到 plugins 目录
            $pluginsDir = ROOT_PATH . '/plugins';
            if (!is_dir($pluginsDir)) {
                @mkdir($pluginsDir, 0755, true);
            }

            // 如果已存在，先删除旧版
            $targetDir = $pluginsDir . '/' . $pluginSlug;
            if (is_dir($targetDir)) {
                deletePluginDir($pluginSlug);
            }

            $zip->extractTo($pluginsDir);
            $zip->close();

            // 记录安装
            $exists = pluginModel()->findBySlug($pluginSlug);
            if (!$exists) {
                pluginModel()->create([
                    'slug' => $pluginSlug,
                    'status' => 0,
                    'installed_at' => time(),
                    'activated_at' => 0
                ]);
            }

            adminLog('plugin', 'upload', '上传安装插件: ' . $pluginSlug);
            echo json_encode(['code' => 0, 'msg' => '插件安装成功: ' . ($meta['name'] ?? $pluginSlug)]);
            break;

        default:
            echo json_encode(['code' => 1, 'msg' => '未知操作']);
    }
    exit;
}

// 获取所有插件
$plugins = getAllPlugins();

$pageTitle = '插件管理';
$currentMenu = 'plugin';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-4xl">
    <!-- 操作栏 -->
    <div class="flex items-center justify-between mb-6">
        <div class="text-sm text-gray-500">
            共 <?php echo count($plugins); ?> 个插件
        </div>
        <button onclick="document.getElementById('uploadModal').classList.remove('hidden')"
                class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
            </svg>
            上传安装插件
        </button>
    </div>

    <?php if (empty($plugins)): ?>
    <!-- 空状态 -->
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <svg class="w-16 h-16 mx-auto text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"></path>
        </svg>
        <p class="text-gray-500 text-lg mb-2">暂无插件</p>
        <p class="text-gray-400 text-sm">将插件文件夹放入 <code class="bg-gray-100 px-1 rounded">/plugins/</code> 目录，或点击上方按钮上传安装。</p>
    </div>
    <?php else: ?>
    <!-- 插件列表 -->
    <div class="space-y-4" id="pluginList">
        <?php foreach ($plugins as $slug => $p): ?>
        <div class="bg-white rounded-lg shadow" id="plugin-<?php echo e($slug); ?>">
            <div class="px-6 py-5 flex items-start gap-4">
                <!-- 插件图标 -->
                <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center <?php echo $p['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"></path>
                    </svg>
                </div>
                <!-- 插件信息 -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 mb-1">
                        <h3 class="font-semibold text-gray-800"><?php echo e($p['name'] ?? $slug); ?></h3>
                        <?php if (!empty($p['version'])): ?>
                        <span class="text-xs text-gray-400">v<?php echo e($p['version']); ?></span>
                        <?php endif; ?>
                        <?php if ($p['status']): ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">已启用</span>
                        <?php else: ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">未启用</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($p['description'])): ?>
                    <p class="text-sm text-gray-500 mb-2"><?php echo e($p['description']); ?></p>
                    <?php endif; ?>
                    <div class="text-xs text-gray-400 flex flex-wrap gap-4">
                        <?php if (!empty($p['author'])): ?>
                        <span>作者: <?php echo e($p['author']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($p['requires_php'])): ?>
                        <span>PHP: &ge; <?php echo e($p['requires_php']); ?></span>
                        <?php endif; ?>
                        <span>标识: <?php echo e($slug); ?></span>
                    </div>
                </div>
                <!-- 操作按钮 -->
                <div class="flex-shrink-0 flex items-center gap-2">
                    <?php if ($p['status']): ?>
                    <?php if (file_exists(ROOT_PATH . '/plugins/' . $slug . '/admin.php')): ?>
                    <a href="/admin/plugin_page.php?plugin=<?php echo e($slug); ?>"
                       class="px-3 py-1.5 text-sm bg-primary text-white rounded hover:bg-secondary transition inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        设置
                    </a>
                    <?php endif; ?>
                    <button onclick="pluginAction('deactivate', '<?php echo e($slug); ?>')"
                            class="px-3 py-1.5 text-sm border border-yellow-300 text-yellow-700 rounded hover:bg-yellow-50 transition">
                        禁用
                    </button>
                    <?php else: ?>
                    <button onclick="pluginAction('activate', '<?php echo e($slug); ?>')"
                            class="px-3 py-1.5 text-sm bg-primary text-white rounded hover:bg-secondary transition">
                        启用
                    </button>
                    <button onclick="pluginAction('delete', '<?php echo e($slug); ?>')"
                            class="px-3 py-1.5 text-sm border border-red-300 text-red-600 rounded hover:bg-red-50 transition">
                        删除
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 上传弹窗 -->
<div id="uploadModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800">上传安装插件</h3>
            <button onclick="document.getElementById('uploadModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <form id="uploadForm" class="p-6">
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary transition cursor-pointer" id="dropZone">
                <svg class="w-10 h-10 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                </svg>
                <p class="text-sm text-gray-500 mb-1">点击选择或拖拽上传</p>
                <p class="text-xs text-gray-400">支持 .zip 格式，ZIP内需包含 插件目录/plugin.json</p>
                <input type="file" id="pluginFile" name="plugin_zip" accept=".zip" class="hidden">
            </div>
            <div id="selectedFile" class="hidden mt-3 p-3 bg-gray-50 rounded flex items-center justify-between">
                <span id="fileName" class="text-sm text-gray-700 truncate"></span>
                <button type="button" onclick="clearFile()" class="text-gray-400 hover:text-red-500">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="mt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('uploadModal').classList.add('hidden')"
                        class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded transition">取消</button>
                <button type="button" id="btnUpload" onclick="uploadPlugin()"
                        class="px-4 py-2 text-sm bg-primary hover:bg-secondary text-white rounded transition">安装</button>
            </div>
        </form>
    </div>
</div>

<script>
// 文件选择 / 拖拽
var dropZone = document.getElementById('dropZone');
var fileInput = document.getElementById('pluginFile');

dropZone.addEventListener('click', function() { fileInput.click(); });
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('border-primary'); });
dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('border-primary'); });
dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropZone.classList.remove('border-primary');
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        showFile(e.dataTransfer.files[0].name);
    }
});
fileInput.addEventListener('change', function() {
    if (fileInput.files.length) showFile(fileInput.files[0].name);
});

function showFile(name) {
    document.getElementById('fileName').textContent = name;
    document.getElementById('selectedFile').classList.remove('hidden');
}
function clearFile() {
    fileInput.value = '';
    document.getElementById('selectedFile').classList.add('hidden');
}

// 上传安装
async function uploadPlugin() {
    if (!fileInput.files.length) {
        showMessage('请选择插件ZIP文件', 'error');
        return;
    }
    var btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.textContent = '安装中...';

    var formData = new FormData();
    formData.append('action', 'upload');
    formData.append('plugin_zip', fileInput.files[0]);

    try {
        var resp = await fetch('', { method: 'POST', body: formData });
        var data = await resp.json();
        if (data.code === 0) {
            showMessage(data.msg);
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败: ' + err.message, 'error');
    }
    btn.disabled = false;
    btn.textContent = '安装';
}

// 启用/禁用/删除
async function pluginAction(action, slug) {
    if (action === 'delete' && !confirm('确定要删除此插件吗？删除后插件文件将被移除。')) {
        return;
    }

    var formData = new FormData();
    formData.append('action', action);
    formData.append('slug', slug);

    try {
        var resp = await fetch('', { method: 'POST', body: formData });
        var data = await resp.json();
        if (data.code === 0) {
            showMessage(data.msg);
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('操作失败', 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
