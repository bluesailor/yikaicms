<?php
/**
 * Yikai CMS - 招聘编辑
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

$job = $isEdit ? jobModel()->find($id) : null;

if ($isEdit && !$job) {
    header('Location: /admin/job.php');
    exit;
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'title' => post('title'),
        'cover' => post('cover'),
        'summary' => post('summary'),
        'content' => $_POST['content'] ?? '',
        'location' => post('location'),
        'salary' => post('salary'),
        'job_type' => post('job_type'),
        'education' => post('education'),
        'experience' => post('experience'),
        'headcount' => post('headcount'),
        'requirements' => post('requirements'),
        'is_top' => postInt('is_top'),
        'sort_order' => postInt('sort_order'),
        'status' => postInt('status', 1),
        'publish_time' => strtotime(post('publish_time')) ?: time(),
        'updated_at' => time(),
    ];

    if (empty($data['title'])) {
        error('请输入职位名称');
    }

    if ($isEdit) {
        jobModel()->updateById($id, $data);
        adminLog('job', 'edit', '编辑职位：' . $data['title']);
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = getAdminId();
        $id = jobModel()->create($data);
        adminLog('job', 'add', '发布职位：' . $data['title']);
    }

    success(['id' => $id]);
}

$pageTitle = $isEdit ? '编辑职位' : '发布职位';
$currentMenu = 'job';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <a href="/admin/job.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        返回招聘列表
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
                        <label class="block text-sm text-gray-700 mb-1">职位名称 <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($job['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2" placeholder="如：PHP高级开发工程师">
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">职位摘要</label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="简要描述岗位..."><?php echo e($job['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">职位详情</label>
                        <input type="hidden" name="content" id="contentInput">
                        <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                        <div id="editor-container" class="border rounded-b-lg" style="min-height: 300px;"></div>
                    </div>
                </div>
            </div>

            <!-- 招聘信息 -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b">
                    <h2 class="font-bold text-gray-800">招聘信息</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">工作地点</label>
                            <input type="text" name="location" value="<?php echo e($job['location'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="如：上海、北京">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">薪资范围</label>
                            <input type="text" name="salary" value="<?php echo e($job['salary'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="如：8k-15k">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">招聘人数</label>
                            <input type="text" name="headcount" value="<?php echo e($job['headcount'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="如：2人、若干">
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">工作性质</label>
                            <select name="job_type" class="w-full border rounded px-4 py-2">
                                <option value="">请选择</option>
                                <option value="全职" <?php echo ($job['job_type'] ?? '') === '全职' ? 'selected' : ''; ?>>全职</option>
                                <option value="兼职" <?php echo ($job['job_type'] ?? '') === '兼职' ? 'selected' : ''; ?>>兼职</option>
                                <option value="实习" <?php echo ($job['job_type'] ?? '') === '实习' ? 'selected' : ''; ?>>实习</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">学历要求</label>
                            <select name="education" class="w-full border rounded px-4 py-2">
                                <option value="">请选择</option>
                                <option value="不限" <?php echo ($job['education'] ?? '') === '不限' ? 'selected' : ''; ?>>不限</option>
                                <option value="高中" <?php echo ($job['education'] ?? '') === '高中' ? 'selected' : ''; ?>>高中</option>
                                <option value="大专" <?php echo ($job['education'] ?? '') === '大专' ? 'selected' : ''; ?>>大专</option>
                                <option value="本科" <?php echo ($job['education'] ?? '') === '本科' ? 'selected' : ''; ?>>本科</option>
                                <option value="硕士" <?php echo ($job['education'] ?? '') === '硕士' ? 'selected' : ''; ?>>硕士</option>
                                <option value="博士" <?php echo ($job['education'] ?? '') === '博士' ? 'selected' : ''; ?>>博士</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-700 mb-1">经验要求</label>
                            <select name="experience" class="w-full border rounded px-4 py-2">
                                <option value="">请选择</option>
                                <option value="不限" <?php echo ($job['experience'] ?? '') === '不限' ? 'selected' : ''; ?>>不限</option>
                                <option value="应届生" <?php echo ($job['experience'] ?? '') === '应届生' ? 'selected' : ''; ?>>应届生</option>
                                <option value="1-3年" <?php echo ($job['experience'] ?? '') === '1-3年' ? 'selected' : ''; ?>>1-3年</option>
                                <option value="3-5年" <?php echo ($job['experience'] ?? '') === '3-5年' ? 'selected' : ''; ?>>3-5年</option>
                                <option value="5-10年" <?php echo ($job['experience'] ?? '') === '5-10年' ? 'selected' : ''; ?>>5-10年</option>
                                <option value="10年以上" <?php echo ($job['experience'] ?? '') === '10年以上' ? 'selected' : ''; ?>>10年以上</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm text-gray-700 mb-1">任职要求</label>
                        <textarea name="requirements" rows="5" class="w-full border rounded px-4 py-2"
                                  placeholder="请输入任职要求..."><?php echo e($job['requirements'] ?? ''); ?></textarea>
                    </div>
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
                        <label class="block text-sm text-gray-700 mb-1">状态</label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($job['status'] ?? 1) == 1 ? 'selected' : ''; ?>>招聘中</option>
                            <option value="0" <?php echo ($job['status'] ?? 1) == 0 ? 'selected' : ''; ?>>已关闭</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">发布时间</label>
                        <input type="datetime-local" name="publish_time"
                               value="<?php echo date('Y-m-d\TH:i', ($job['publish_time'] ?? 0) ?: time()); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">排序</label>
                        <input type="number" name="sort_order" value="<?php echo $job['sort_order'] ?? 0; ?>"
                               class="w-full border rounded px-4 py-2" placeholder="数字越大越靠前">
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_top" value="1" id="isTop"
                               <?php echo ($job['is_top'] ?? 0) ? 'checked' : ''; ?> class="text-primary">
                        <label for="isTop" class="text-sm text-gray-700">置顶</label>
                    </div>
                </div>
            </div>

            <!-- 封面图 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">封面图</h3>
                <div id="coverPreview" class="mb-4 <?php echo empty($job['cover']) ? 'hidden' : ''; ?>">
                    <img src="<?php echo e($job['cover'] ?? ''); ?>" class="w-full rounded">
                </div>
                <input type="hidden" name="cover" id="coverInput" value="<?php echo e($job['cover'] ?? ''); ?>">
                <div class="flex gap-2">
                    <button type="button" onclick="uploadCover()"
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        上传图片</button>
                    <button type="button" onclick="pickCoverFromMedia()"
                            class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        媒体库</button>
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
            const preview = document.getElementById('coverPreview');
            preview.classList.remove('hidden');
            preview.querySelector('img').src = data.data.url;
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
        preview.classList.remove('hidden');
        preview.querySelector('img').src = url;
    });
}
</script>

<?php
$contentHtml = json_encode($job['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "请输入职位详情...",
    html: ' . $contentHtml . ',
    uploadUrl: "/admin/upload.php",
    onChange: function(editor) {
        document.getElementById("contentInput").value = editor.getHtml();
    }
});

document.getElementById("editForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    document.getElementById("contentInput").value = editor.getHtml();

    const formData = new FormData(this);

    try {
        const response = await fetch("", { method: "POST", body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage("保存成功");
            ' . ($isEdit ? '' : 'setTimeout(function() { location.href = "/admin/job_edit.php?id=" + data.data.id; }, 1000);') . '
        } else {
            showMessage(data.msg, "error");
        }
    } catch (err) {
        showMessage("请求失败", "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
