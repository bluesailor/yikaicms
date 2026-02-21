<?php
/**
 * Yikai CMS - 产品编辑
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
$product = null;

if ($id > 0) {
    $product = productModel()->find($id);
    if (!$product) {
        header('Location: /admin/product.php');
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
        'model' => post('model'),
        'cover' => post('cover'),
        'images' => post('images'),
        'summary' => post('summary'),
        'content' => $_POST['content'] ?? '',
        'specs' => post('specs'),
        'tags' => post('tags'),
        'is_top' => postInt('is_top'),
        'is_recommend' => postInt('is_recommend'),
        'is_hot' => postInt('is_hot'),
        'is_new' => postInt('is_new'),
        'status' => postInt('status', 1),
        'updated_at' => time(),
    ];

    if (empty($data['title'])) {
        error('请输入产品名称');
    }

    // 仅在开启价格显示时更新价格字段
    if (config('show_price', '0') === '1') {
        $data['price'] = (float)($_POST['price'] ?? 0);
        $data['market_price'] = (float)($_POST['market_price'] ?? 0);
    }

    $data['slug'] = resolveSlug($data['slug'], $data['title'], 'products', $id);

    if ($id > 0) {
        productModel()->updateById($id, $data);
        adminLog('product', 'update', "更新产品ID: $id");
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = $_SESSION['admin_id'];
        $id = productModel()->create($data);
        adminLog('product', 'create', "创建产品ID: $id");
    }

    success(['id' => $id]);
}

// 获取分类树
$categories = productCategoryModel()->getFlatOptions();

// 获取标签数据
$allTags = productModel()->getAllTags();
$hotTags = $allTags;
usort($hotTags, fn($a, $b) => $b['count'] - $a['count']);
$hotTags = array_slice($hotTags, 0, 15);
$recentTags = $allTags;
usort($recentTags, fn($a, $b) => $b['latest'] - $a['latest']);
$recentTags = array_slice($recentTags, 0, 10);

$pageTitle = $product ? '编辑产品' : '添加产品';
$currentMenu = 'product';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<form id="editForm" class="space-y-6">
    <div class="flex gap-6">
        <!-- 主内容区 -->
        <div class="flex-1 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">产品名称 <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($product['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2 text-lg" placeholder="请输入产品名称">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-1">副标题</label>
                            <input type="text" name="subtitle" value="<?php echo e($product['subtitle'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="可选">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-1">产品型号</label>
                            <input type="text" name="model" value="<?php echo e($product['model'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="可选">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">产品摘要</label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="产品简介，用于列表展示"><?php echo e($product['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">产品详情</label>
                        <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                        <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
                        <input type="hidden" name="content" id="contentInput">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">规格参数</label>
                        <textarea name="specs" rows="4" class="w-full border rounded px-4 py-2"
                                  placeholder="每行一个参数，格式：参数名:参数值"><?php echo e($product['specs'] ?? ''); ?></textarea>
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
                        <input type="hidden" name="category_id" id="categoryIdInput" value="<?php echo (int)($product['category_id'] ?? 0); ?>">
                        <div class="border rounded p-3 max-h-60 overflow-y-auto space-y-1" id="categoryTree">
                            <?php
                            $currentCatId = (int)($product['category_id'] ?? 0);
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
                        <input type="text" name="slug" value="<?php echo e($product['slug'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：iot-gateway，留空自动生成">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">发布状态</label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($product['status'] ?? 1) == 1 ? 'selected' : ''; ?>>上架</option>
                            <option value="0" <?php echo ($product['status'] ?? 1) == 0 ? 'selected' : ''; ?>>下架</option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_top" value="1"
                                   <?php echo ($product['is_top'] ?? 0) ? 'checked' : ''; ?>>
                            <span>置顶</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_recommend" value="1"
                                   <?php echo ($product['is_recommend'] ?? 0) ? 'checked' : ''; ?>>
                            <span>推荐</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_hot" value="1"
                                   <?php echo ($product['is_hot'] ?? 0) ? 'checked' : ''; ?>>
                            <span>热门</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_new" value="1"
                                   <?php echo ($product['is_new'] ?? 0) ? 'checked' : ''; ?>>
                            <span>新品</span>
                        </label>
                    </div>
                </div>
            </div>

            <?php if (config('show_price', '0') === '1'): ?>
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">价格设置</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">销售价</label>
                        <input type="number" step="0.01" name="price" value="<?php echo $product['price'] ?? ''; ?>"
                               class="w-full border rounded px-4 py-2" placeholder="0表示面议">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">市场价</label>
                        <input type="number" step="0.01" name="market_price" value="<?php echo $product['market_price'] ?? ''; ?>"
                               class="w-full border rounded px-4 py-2" placeholder="可选，用于对比">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">产品图片</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">封面图</label>
                        <input type="text" name="cover" id="coverInput"
                               value="<?php echo e($product['cover'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2 text-sm" placeholder="图片地址">
                        <div class="flex gap-2 mt-2">
                            <button type="button" onclick="uploadCover()"
                                    class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                上传图片</button>
                            <button type="button" onclick="pickCoverFromMedia()"
                                    class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                媒体库</button>
                        </div>
                        <div id="coverPreview" class="mt-2">
                            <?php if (!empty($product['cover'])): ?>
                            <img src="<?php echo e($product['cover']); ?>" class="w-full rounded">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">产品图集</label>
                        <textarea name="images" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="多张图片URL，每行一个"><?php echo e($product['images'] ?? ''); ?></textarea>
                        <button type="button" onclick="uploadGallery()"
                                class="mt-2 text-sm text-primary hover:underline"><?php echo __('admin_upload_image'); ?></button>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">产品标签</h3>
                <div class="space-y-3">
                    <div>
                        <input type="text" name="tags" id="tagsInput" value="<?php echo e($product['tags'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="多个标签用逗号分隔">
                    </div>
                    <div id="selectedTags" class="flex flex-wrap gap-1.5"></div>
                    <?php if (!empty($hotTags)): ?>
                    <div>
                        <p class="text-xs text-gray-400 mb-1.5">热门标签</p>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($hotTags as $t): ?>
                            <button type="button" onclick="toggleTag(this)" data-tag="<?php echo e($t['tag']); ?>"
                                    class="tag-btn text-xs border rounded-full px-2.5 py-0.5 hover:border-primary hover:text-primary transition">
                                <?php echo e($t['tag']); ?><span class="text-gray-300 ml-0.5"><?php echo $t['count']; ?></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php
                    // 最近标签去掉与热门重复的
                    $hotTagNames = array_column($hotTags, 'tag');
                    $uniqueRecent = array_filter($recentTags, fn($t) => !in_array($t['tag'], $hotTagNames));
                    ?>
                    <?php if (!empty($uniqueRecent)): ?>
                    <div>
                        <p class="text-xs text-gray-400 mb-1.5">最近使用</p>
                        <div class="flex flex-wrap gap-1.5">
                            <?php foreach ($uniqueRecent as $t): ?>
                            <button type="button" onclick="toggleTag(this)" data-tag="<?php echo e($t['tag']); ?>"
                                    class="tag-btn text-xs border rounded-full px-2.5 py-0.5 hover:border-primary hover:text-primary transition">
                                <?php echo e($t['tag']); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存
                </button>
                <a href="/admin/product.php" class="flex-1 text-center border py-2 rounded hover:bg-gray-100 transition inline-flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    返回
                </a>
            </div>
        </div>
    </div>
</form>

<input type="file" id="coverFileInput" class="hidden" accept="image/*">
<input type="file" id="galleryFileInput" class="hidden" accept="image/*" multiple>

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

function uploadGallery() {
    document.getElementById('galleryFileInput').click();
}

document.getElementById('galleryFileInput').addEventListener('change', async function() {
    if (!this.files.length) return;

    const imagesField = document.querySelector('textarea[name="images"]');
    let urls = imagesField.value.trim() ? imagesField.value.trim().split('\n') : [];

    for (const file of this.files) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('type', 'images');

        try {
            const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
            const data = await safeJson(response);

            if (data.code === 0) {
                urls.push(data.data.url);
            }
        } catch (err) {
            console.error('Upload failed:', err);
        }
    }

    imagesField.value = urls.join('\n');
    showMessage('图片上传完成');
    this.value = '';
});
</script>

<!-- 标签逻辑 -->
<script>
(function() {
    const input = document.getElementById('tagsInput');
    const container = document.getElementById('selectedTags');

    function getTags() {
        return input.value.split(',').map(s => s.trim()).filter(Boolean);
    }

    function setTags(tags) {
        input.value = tags.join(',');
        renderPills();
        syncButtons();
    }

    function renderPills() {
        const tags = getTags();
        container.innerHTML = tags.map(tag =>
            `<span class="inline-flex items-center gap-1 bg-primary/10 text-primary text-xs px-2.5 py-1 rounded-full">
                ${tag}<button type="button" onclick="removeTag('${tag.replace(/'/g, "\\'")}')" class="hover:text-red-500">&times;</button>
            </span>`
        ).join('');
    }

    function syncButtons() {
        const tags = getTags();
        document.querySelectorAll('.tag-btn').forEach(btn => {
            if (tags.includes(btn.dataset.tag)) {
                btn.classList.add('border-primary', 'text-primary', 'bg-primary/5');
            } else {
                btn.classList.remove('border-primary', 'text-primary', 'bg-primary/5');
            }
        });
    }

    window.toggleTag = function(btn) {
        const tag = btn.dataset.tag;
        const tags = getTags();
        const idx = tags.indexOf(tag);
        if (idx >= 0) {
            tags.splice(idx, 1);
        } else {
            tags.push(tag);
        }
        setTags(tags);
    };

    window.removeTag = function(tag) {
        setTags(getTags().filter(t => t !== tag));
    };

    input.addEventListener('change', () => { renderPills(); syncButtons(); });

    // 初始化
    renderPills();
    syncButtons();
})();
</script>

<!-- 分类复选框逻辑 -->
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

<?php
$productContent = json_encode($product['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "请输入产品详情...",
    html: ' . $productContent . ',
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
        setTimeout(function() { location.href = "/admin/product.php"; }, 1000);
    } else {
        showMessage(data.msg, "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
