<?php
/**
 * Yikai CMS - 文章编辑
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
$article = null;

if ($id > 0) {
    $article = articleModel()->find($id);
    if (!$article) {
        header('Location: /admin/article.php');
        exit;
    }
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'category_id' => postInt('category_id'),
        'title' => post('title'),
        'subtitle' => post('subtitle'),
        'slug' => post('slug'),
        'cover' => post('cover'),
        'summary' => post('summary'),
        'content' => $_POST['content'] ?? '',
        'author' => post('author'),
        'source' => post('source'),
        'tags' => post('tags'),
        'is_top' => postInt('is_top'),
        'is_recommend' => postInt('is_recommend'),
        'is_hot' => postInt('is_hot'),
        'status' => postInt('status', 1),
        'updated_at' => time(),
    ];

    if (empty($data['title'])) {
        error('请输入文章标题');
    }

    $data['slug'] = resolveSlug($data['slug'], $data['title'], 'articles', $id);

    // 发布时间
    $publishTime = post('publish_time');
    if ($publishTime) {
        $data['publish_time'] = strtotime($publishTime);
    } elseif ($data['status'] == 1 && (!$article || !$article['publish_time'])) {
        $data['publish_time'] = time();
    }

    if ($id > 0) {
        articleModel()->updateById($id, $data);
        adminLog('article', 'update', "更新文章ID: $id");
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = $_SESSION['admin_id'];
        if (!isset($data['publish_time'])) {
            $data['publish_time'] = $data['status'] == 1 ? time() : 0;
        }
        $id = articleModel()->create($data);
        adminLog('article', 'create', "创建文章ID: $id");
    }

    success(['id' => $id]);
}

// 获取分类树
$categories = articleCategoryModel()->getFlatOptions();

$pageTitle = $article ? '编辑文章' : '添加文章';
$currentMenu = 'article';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<form id="editForm" class="space-y-6">
    <div class="flex gap-6">
        <!-- 主内容区 -->
        <div class="flex-1 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">文章标题 <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($article['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2 text-lg" placeholder="请输入文章标题">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">副标题</label>
                        <input type="text" name="subtitle" value="<?php echo e($article['subtitle'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="可选">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">文章摘要</label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="文章摘要，用于列表展示"><?php echo e($article['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">文章内容</label>
                        <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                        <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
                        <input type="hidden" name="content" id="contentInput">
                    </div>
                </div>
            </div>
        </div>

        <!-- 侧边栏 -->
        <div class="w-80 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">发布设置</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-2">所属分类</label>
                        <input type="hidden" name="category_id" id="categoryIdInput" value="<?php echo (int)($article['category_id'] ?? 0); ?>">
                        <div class="border rounded p-3 max-h-60 overflow-y-auto space-y-1" id="categoryTree">
                            <?php
                            $currentCatId = (int)($article['category_id'] ?? 0);
                            // 收集当前分类的所有父ID
                            $checkedIds = [];
                            if ($currentCatId > 0) {
                                $checkedIds[] = $currentCatId;
                                $tmpId = $currentCatId;
                                foreach (array_reverse($categories) as $c) {
                                    if ((int)$c['id'] === $tmpId && (int)$c['parent_id'] > 0) {
                                        $checkedIds[] = (int)$c['parent_id'];
                                        $tmpId = (int)$c['parent_id'];
                                    }
                                }
                            }
                            foreach ($categories as $cat):
                                $isChecked = in_array((int)$cat['id'], $checkedIds);
                                $ml = (int)($cat['_level'] ?? 0) * 20;
                            ?>
                            <label class="flex items-center gap-2 py-1 px-1 rounded hover:bg-gray-50 cursor-pointer" style="margin-left: <?php echo $ml; ?>px;">
                                <input type="checkbox" class="cat-checkbox rounded"
                                       value="<?php echo $cat['id']; ?>"
                                       data-parent="<?php echo (int)$cat['parent_id']; ?>"
                                       <?php echo $isChecked ? 'checked' : ''; ?>>
                                <span class="text-sm <?php echo (int)($cat['_level'] ?? 0) === 0 ? 'font-medium text-gray-800' : 'text-gray-600'; ?>">
                                    <?php echo e($cat['name']); ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                            <?php if (empty($categories)): ?>
                            <div class="text-sm text-gray-400 text-center py-2">暂无分类，请先在分类管理中创建</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">URL别名 (Slug)</label>
                        <input type="text" name="slug" value="<?php echo e($article['slug'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：company-news，留空自动生成">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">发布状态</label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($article['status'] ?? 1) == 1 ? 'selected' : ''; ?>>发布</option>
                            <option value="0" <?php echo ($article['status'] ?? 1) == 0 ? 'selected' : ''; ?>>草稿</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">发布时间</label>
                        <input type="datetime-local" name="publish_time"
                               value="<?php echo $article['publish_time'] ? date('Y-m-d\TH:i', (int)$article['publish_time']) : ''; ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_top" value="1"
                                   <?php echo ($article['is_top'] ?? 0) ? 'checked' : ''; ?>>
                            <span>置顶</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_recommend" value="1"
                                   <?php echo ($article['is_recommend'] ?? 0) ? 'checked' : ''; ?>>
                            <span>推荐</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_hot" value="1"
                                   <?php echo ($article['is_hot'] ?? 0) ? 'checked' : ''; ?>>
                            <span>热门</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">封面图片</h3>
                <div class="space-y-2">
                    <input type="text" name="cover" id="coverInput"
                           value="<?php echo e($article['cover'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2 text-sm" placeholder="图片地址">
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
                    <div id="coverPreview">
                        <?php if (!empty($article['cover'])): ?>
                        <img src="<?php echo e($article['cover']); ?>" class="w-full rounded">
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">其他信息</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">作者</label>
                        <input type="text" name="author" value="<?php echo e($article['author'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">来源</label>
                        <input type="text" name="source" value="<?php echo e($article['source'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">标签</label>
                        <input type="text" name="tags" value="<?php echo e($article['tags'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="多个标签用逗号分隔">
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存
                </button>
                <a href="/admin/article.php" class="flex-1 text-center border py-2 rounded hover:bg-gray-100 transition inline-flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    返回
                </a>
            </div>
        </div>
    </div>
</form>

<input type="file" id="coverFileInput" class="hidden" accept="image/*">

<!-- 分类复选框逻辑（不依赖 wangEditor，可提前执行） -->
<script>
(function() {
    const checkboxes = document.querySelectorAll('.cat-checkbox');
    const hiddenInput = document.getElementById('categoryIdInput');

    function updateHiddenInput() {
        let deepest = null;
        let maxLevel = -1;
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const label = cb.closest('label');
                const ml = parseInt(label.style.marginLeft) || 0;
                if (ml >= maxLevel) {
                    maxLevel = ml;
                    deepest = cb.value;
                }
            }
        });
        hiddenInput.value = deepest || 0;
    }

    function getChildren(parentId) {
        return document.querySelectorAll(`.cat-checkbox[data-parent="${parentId}"]`);
    }

    function getParent(cb) {
        const parentId = cb.dataset.parent;
        if (!parentId || parentId === '0') return null;
        return document.querySelector(`.cat-checkbox[value="${parentId}"]`);
    }

    function checkParents(cb) {
        const parent = getParent(cb);
        if (parent && !parent.checked) {
            parent.checked = true;
            checkParents(parent);
        }
    }

    function uncheckChildren(cb) {
        const children = getChildren(cb.value);
        children.forEach(child => {
            child.checked = false;
            uncheckChildren(child);
        });
    }

    function hasCheckedChild(parentId) {
        const children = getChildren(parentId);
        for (const child of children) {
            if (child.checked) return true;
        }
        return false;
    }

    function uncheckParentsIfNoChildren(cb) {
        const parent = getParent(cb);
        if (parent && parent.checked && !hasCheckedChild(parent.value)) {
            parent.checked = false;
            uncheckParentsIfNoChildren(parent);
        }
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            if (this.checked) {
                checkParents(this);
            } else {
                uncheckChildren(this);
                uncheckParentsIfNoChildren(this);
            }
            updateHiddenInput();
        });
    });
})();
</script>

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
            document.getElementById('coverPreview').innerHTML = '';
            const coverImg = document.createElement('img');
            coverImg.src = data.data.url;
            coverImg.className = 'w-full rounded';
            document.getElementById('coverPreview').appendChild(coverImg);
            showMessage('上传成功');
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
        document.getElementById('coverPreview').innerHTML = '';
        var coverImg = document.createElement('img');
        coverImg.src = url;
        coverImg.className = 'w-full rounded';
        document.getElementById('coverPreview').appendChild(coverImg);
    });
}
</script>

<?php
$articleContent = json_encode($article['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "请输入文章内容...",
    html: ' . $articleContent . ',
    uploadUrl: "/admin/upload.php",
    onChange: function(editor) {
        document.getElementById("contentInput").value = editor.getHtml();
    }
});

document.getElementById("editForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    document.getElementById("contentInput").value = editor.getHtml();

    const formData = new FormData(this);
    const response = await fetch("", { method: "POST", body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage("保存成功");
        setTimeout(function() { location.href = "/admin/article.php"; }, 1000);
    } else {
        showMessage(data.msg, "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
