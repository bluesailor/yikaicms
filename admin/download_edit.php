<?php
/**
 * Yikai CMS - 下载编辑
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

$id = getInt('id');
$isEdit = $id > 0;

// 获取数据
$download = $isEdit ? downloadModel()->find($id) : null;

if ($isEdit && !$download) {
    header('Location: /admin/download.php');
    exit;
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'category_id' => postInt('category_id'),
        'title' => post('title'),
        'description' => post('description'),
        'cover' => post('cover'),
        'file_url' => post('file_url'),
        'file_name' => post('file_name'),
        'file_size' => postInt('file_size'),
        'file_ext' => post('file_ext'),
        'is_external' => postInt('is_external'),
        'require_login' => postInt('require_login'),
        'sort_order' => postInt('sort_order'),
        'status' => postInt('status'),
        'updated_at' => time(),
    ];

    if (empty($data['title'])) {
        error('请输入文件名称');
    }

    if ($isEdit) {
        downloadModel()->updateById($id, $data);
        adminLog('download', 'edit', '编辑下载：' . $data['title']);
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = getAdminId();
        $id = downloadModel()->create($data);
        adminLog('download', 'add', '添加下载：' . $data['title']);
    }

    success(['id' => $id]);
}

// 获取分类
$categories = downloadCategoryModel()->getActive();

$pageTitle = $isEdit ? '编辑下载' : '添加下载';
$currentMenu = 'download';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <a href="/admin/download.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        返回下载列表
    </a>
</div>

<form id="editForm" class="space-y-6">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 左侧：基本信息 -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b">
                    <h2 class="font-bold text-gray-800">基本信息</h2>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">文件名称 <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($download['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2" placeholder="如：产品使用手册 V2.0">
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">文件描述</label>
                        <textarea name="description" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="简要描述文件内容..."><?php echo e($download['description'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">封面图片</label>
                        <input type="text" name="cover" id="coverInput" value="<?php echo e($download['cover'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2 text-sm mb-2" placeholder="可选，用于列表展示">
                        <div class="flex gap-2">
                            <button type="button" onclick="uploadCover()"
                                    class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                上传图片</button>
                            <button type="button" onclick="pickCoverFromMedia()"
                                    class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                媒体库</button>
                        </div>
                        <?php if (!empty($download['cover'])): ?>
                        <img src="<?php echo e($download['cover']); ?>" id="coverPreview" class="h-20 mt-2 rounded">
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b">
                    <h2 class="font-bold text-gray-800">文件上传</h2>
                </div>
                <div class="p-6 space-y-4">
                    <!-- 上传方式选择 -->
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_external" value="0" <?php echo empty($download['is_external']) ? 'checked' : ''; ?>
                                   onchange="toggleUploadMode()" class="text-primary">
                            <span>上传文件</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="is_external" value="1" <?php echo !empty($download['is_external']) ? 'checked' : ''; ?>
                                   onchange="toggleUploadMode()" class="text-primary">
                            <span>外部链接</span>
                        </label>
                    </div>

                    <!-- 上传文件区域 -->
                    <div id="uploadArea" class="<?php echo !empty($download['is_external']) ? 'hidden' : ''; ?>">
                        <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary transition cursor-pointer"
                             onclick="document.getElementById('fileInput').click()">
                            <svg class="w-12 h-12 mx-auto text-gray-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                            </svg>
                            <p class="text-gray-500 mb-2">点击或拖拽文件到此处上传</p>
                            <p class="text-xs text-gray-400">支持 ZIP, RAR, PDF, DOC, XLS, PPT 等格式，最大 50MB</p>
                        </div>
                        <input type="file" id="fileInput" class="hidden"
                               accept=".zip,.rar,.7z,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.exe,.msi">

                        <!-- 已上传文件信息 -->
                        <div id="uploadedFile" class="mt-4 <?php echo empty($download['file_url']) || !empty($download['is_external']) ? 'hidden' : ''; ?>">
                            <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-lg">
                                <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                                <div class="flex-1">
                                    <div class="font-medium" id="uploadedFileName"><?php echo e($download['file_name'] ?? ''); ?></div>
                                    <div class="text-sm text-gray-500" id="uploadedFileSize">
                                        <?php echo !empty($download['file_size']) ? formatFileSize((int)$download['file_size']) : ''; ?>
                                    </div>
                                </div>
                                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-600">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- 上传进度 -->
                        <div id="uploadProgress" class="mt-4 hidden">
                            <div class="flex items-center gap-3">
                                <div class="flex-1 bg-gray-200 rounded-full h-2">
                                    <div id="progressBar" class="bg-primary h-2 rounded-full transition-all" style="width: 0%"></div>
                                </div>
                                <span id="progressText" class="text-sm text-gray-500">0%</span>
                            </div>
                        </div>
                    </div>

                    <!-- 外部链接区域 -->
                    <div id="externalArea" class="<?php echo empty($download['is_external']) ? 'hidden' : ''; ?>">
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">下载地址</label>
                            <input type="text" id="externalUrl" value="<?php echo !empty($download['is_external']) ? e($download['file_url']) : ''; ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="https://example.com/file.zip"
                                   onchange="updateExternalUrl()">
                        </div>
                        <div class="grid grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">文件名</label>
                                <input type="text" id="externalFileName"
                                       value="<?php echo !empty($download['is_external']) ? e($download['file_name']) : ''; ?>"
                                       class="w-full border rounded px-4 py-2" placeholder="file.zip">
                            </div>
                            <div>
                                <label class="block text-sm text-gray-700 mb-1">文件类型</label>
                                <select id="externalFileExt" class="w-full border rounded px-4 py-2">
                                    <option value="">选择类型</option>
                                    <option value="pdf" <?php echo ($download['file_ext'] ?? '') === 'pdf' ? 'selected' : ''; ?>>PDF</option>
                                    <option value="zip" <?php echo ($download['file_ext'] ?? '') === 'zip' ? 'selected' : ''; ?>>ZIP</option>
                                    <option value="rar" <?php echo ($download['file_ext'] ?? '') === 'rar' ? 'selected' : ''; ?>>RAR</option>
                                    <option value="doc" <?php echo ($download['file_ext'] ?? '') === 'doc' ? 'selected' : ''; ?>>DOC</option>
                                    <option value="xls" <?php echo ($download['file_ext'] ?? '') === 'xls' ? 'selected' : ''; ?>>XLS</option>
                                    <option value="exe" <?php echo ($download['file_ext'] ?? '') === 'exe' ? 'selected' : ''; ?>>EXE</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- 隐藏字段 -->
                    <input type="hidden" name="file_url" id="fileUrlInput" value="<?php echo e($download['file_url'] ?? ''); ?>">
                    <input type="hidden" name="file_name" id="fileNameInput" value="<?php echo e($download['file_name'] ?? ''); ?>">
                    <input type="hidden" name="file_size" id="fileSizeInput" value="<?php echo $download['file_size'] ?? 0; ?>">
                    <input type="hidden" name="file_ext" id="fileExtInput" value="<?php echo e($download['file_ext'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <!-- 右侧：设置 -->
        <div class="space-y-6">
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b">
                    <h2 class="font-bold text-gray-800">发布设置</h2>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">所属分类</label>
                        <select name="category_id" class="w-full border rounded px-4 py-2">
                            <option value="0">未分类</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"
                                <?php echo ($download['category_id'] ?? 0) == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo e($cat['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">排序</label>
                        <input type="number" name="sort_order" value="<?php echo $download['sort_order'] ?? 0; ?>"
                               class="w-full border rounded px-4 py-2" placeholder="数字越大越靠前">
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">下载条件</label>
                        <div class="flex gap-4 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="require_login" value="0" class="text-primary"
                                       <?php echo empty($download['require_login']) ? 'checked' : ''; ?>>
                                <span class="text-sm">游客</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="require_login" value="1" class="text-primary"
                                       <?php echo !empty($download['require_login']) ? 'checked' : ''; ?>>
                                <span class="text-sm">限登录会员</span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">状态</label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($download['status'] ?? 1) == 1 ? 'selected' : ''; ?>>发布</option>
                            <option value="0" <?php echo ($download['status'] ?? 1) == 0 ? 'selected' : ''; ?>>隐藏</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <button type="submit" class="w-full bg-primary hover:bg-secondary text-white px-8 py-3 rounded transition inline-flex items-center justify-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存
                </button>
            </div>
        </div>
    </div>
</form>

<input type="file" id="coverFileInput" class="hidden" accept="image/*">

<script>
function toggleUploadMode() {
    const isExternal = document.querySelector('input[name="is_external"]:checked').value === '1';
    document.getElementById('uploadArea').classList.toggle('hidden', isExternal);
    document.getElementById('externalArea').classList.toggle('hidden', !isExternal);
}

function uploadCover() {
    document.getElementById('coverFileInput').click();
}

document.getElementById('coverFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('coverInput').value = data.data.url;
            let preview = document.getElementById('coverPreview');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'coverPreview';
                preview.className = 'h-20 mt-2 rounded';
                document.getElementById('coverInput').parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }
    this.value = '';
});

function pickCoverFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('coverInput').value = url;
        var preview = document.getElementById('coverPreview');
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'coverPreview';
            preview.className = 'h-20 mt-2 rounded';
            document.getElementById('coverInput').parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

// 文件上传
document.getElementById('fileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const file = this.files[0];
    const maxSize = 50 * 1024 * 1024; // 50MB

    if (file.size > maxSize) {
        showMessage('文件大小不能超过50MB', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', 'files');

    document.getElementById('uploadProgress').classList.remove('hidden');

    try {
        const xhr = new XMLHttpRequest();
        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                document.getElementById('progressBar').style.width = percent + '%';
                document.getElementById('progressText').textContent = percent + '%';
            }
        };

        xhr.onload = function() {
            document.getElementById('uploadProgress').classList.add('hidden');
            if (xhr.status === 200) {
                const data = JSON.parse(xhr.responseText);
                if (data.code === 0) {
                    document.getElementById('fileUrlInput').value = data.data.url;
                    document.getElementById('fileNameInput').value = file.name;
                    document.getElementById('fileSizeInput').value = file.size;
                    document.getElementById('fileExtInput').value = file.name.split('.').pop().toLowerCase();

                    document.getElementById('uploadedFileName').textContent = file.name;
                    document.getElementById('uploadedFileSize').textContent = formatFileSize(file.size);
                    document.getElementById('uploadedFile').classList.remove('hidden');

                    showMessage('上传成功');
                } else {
                    showMessage(data.msg, 'error');
                }
            }
        };

        xhr.onerror = function() {
            document.getElementById('uploadProgress').classList.add('hidden');
            showMessage('上传失败', 'error');
        };

        xhr.open('POST', '/admin/upload.php', true);
        xhr.send(formData);
    } catch (err) {
        document.getElementById('uploadProgress').classList.add('hidden');
        showMessage('上传失败', 'error');
    }

    this.value = '';
});

function clearFile() {
    document.getElementById('fileUrlInput').value = '';
    document.getElementById('fileNameInput').value = '';
    document.getElementById('fileSizeInput').value = 0;
    document.getElementById('fileExtInput').value = '';
    document.getElementById('uploadedFile').classList.add('hidden');
}

function updateExternalUrl() {
    const url = document.getElementById('externalUrl').value;
    document.getElementById('fileUrlInput').value = url;

    // 尝试从URL提取文件名
    if (url) {
        const fileName = url.split('/').pop().split('?')[0];
        if (fileName) {
            document.getElementById('externalFileName').value = fileName;
            document.getElementById('fileNameInput').value = fileName;
            const ext = fileName.split('.').pop().toLowerCase();
            document.getElementById('externalFileExt').value = ext;
            document.getElementById('fileExtInput').value = ext;
        }
    }
}

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(2) + ' KB';
    if (bytes < 1073741824) return (bytes / 1048576).toFixed(2) + ' MB';
    return (bytes / 1073741824).toFixed(2) + ' GB';
}

// 表单提交
document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    // 如果是外链模式，更新隐藏字段
    const isExternal = document.querySelector('input[name="is_external"]:checked').value === '1';
    if (isExternal) {
        document.getElementById('fileUrlInput').value = document.getElementById('externalUrl').value;
        document.getElementById('fileNameInput').value = document.getElementById('externalFileName').value;
        document.getElementById('fileExtInput').value = document.getElementById('externalFileExt').value;
    }

    const formData = new FormData(this);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('保存成功');
            <?php if (!$isEdit): ?>
            setTimeout(() => location.href = '/admin/download_edit.php?id=' + data.data.id, 1000);
            <?php endif; ?>
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
