<?php
/**
 * ikaiCMS - 产品编辑
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
    $postedType = post('product_type', 'custom');
    if (!in_array($postedType, ['standard', 'custom'], true)) $postedType = 'custom';
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
        'product_type' => $postedType,
        'material' => trim(post('material')),
        'scene'    => trim(post('scene')),
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
    <div class="flex flex-col lg:flex-row gap-6">
        <!-- 主内容区 -->
        <div class="flex-1 min-w-0 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_product_name'); ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($product['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2 text-lg" placeholder="请输入产品名称">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-1"><?php echo __('label_subtitle'); ?></label>
                            <input type="text" name="subtitle" value="<?php echo e($product['subtitle'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="<?php echo __('optional'); ?>">
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-1"><?php echo __('label_product_model'); ?></label>
                            <input type="text" name="model" value="<?php echo e($product['model'] ?? ''); ?>"
                                   class="w-full border rounded px-4 py-2" placeholder="<?php echo __('optional'); ?>">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_product_summary'); ?></label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="产品简介，用于列表展示"><?php echo e($product['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_product_detail'); ?></label>
                        <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                        <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
                        <input type="hidden" name="content" id="contentInput">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_product_specs') ?: '规格参数'; ?></label>
                        <input type="hidden" name="specs" id="specsInput" value="<?php echo e($product['specs'] ?? '{}'); ?>">
                        <div id="specsList" class="space-y-2 mb-3"></div>
                        <button type="button" onclick="addSpecRow()" class="text-sm text-primary hover:underline">+ <?php echo __('admin_add_spec') ?: '添加参数'; ?></button>
                    </div>
                </div>
            </div>

            <!-- 图片画廊（多图，lightbox 前端展示） -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-2">图片画廊</h3>
                <p class="text-xs text-gray-500 mb-3">除封面外的附加展示图，前台详情页会以画廊形式呈现。鼠标悬停可排序/删除，支持拖拽排序。</p>
                <input type="hidden" name="images" id="imagesInput" value="<?php echo e($product['images'] ?? ''); ?>">
                <div id="galleryPreview" class="grid grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 mb-3"></div>
                <div class="flex gap-2">
                    <button type="button" onclick="uploadGalleryImage()"
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <?php echo __('admin_upload_image') ?: '上传图片'; ?>（可多选）
                    </button>
                    <button type="button" onclick="pickGalleryFromMedia()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <?php echo __('admin_media_library') ?: '媒体库'; ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- 侧边栏 -->
        <div class="w-full lg:w-80 flex-shrink-0 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_publish_settings'); ?></h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-2"><?php echo __('label_category'); ?></label>
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
                            <div class="text-sm text-gray-400 text-center py-2"><?php echo __('empty_no_category'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_slug'); ?> (Slug)</label>
                        <input type="text" name="slug" value="<?php echo e($product['slug'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：iot-gateway，留空自动生成">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-2">商品类型</label>
                        <?php $ptype = $product['product_type'] ?? 'custom'; ?>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex items-start gap-2 border rounded-lg p-3 cursor-pointer hover:bg-gray-50 <?php echo $ptype === 'standard' ? 'border-primary bg-blue-50' : 'border-gray-200'; ?>">
                                <input type="radio" name="product_type" value="standard" class="mt-1" <?php echo $ptype === 'standard' ? 'checked' : ''; ?>>
                                <div>
                                    <div class="text-sm font-medium text-gray-800">標準製品</div>
                                    <div class="text-xs text-gray-500 mt-0.5">既製品・定型文言、直接下单</div>
                                </div>
                            </label>
                            <label class="flex items-start gap-2 border rounded-lg p-3 cursor-pointer hover:bg-gray-50 <?php echo $ptype === 'custom' ? 'border-primary bg-blue-50' : 'border-gray-200'; ?>">
                                <input type="radio" name="product_type" value="custom" class="mt-1" <?php echo $ptype === 'custom' ? 'checked' : ''; ?>>
                                <div>
                                    <div class="text-sm font-medium text-gray-800">オーダー製作</div>
                                    <div class="text-xs text-gray-500 mt-0.5">定制品・文字/尺寸可协商</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">素材 (Material)</label>
                        <?php
                        $materialOptions = ['木材', 'アクリル', 'アルミ複合板', 'ステンレス', '複合板', 'その他'];
                        $curMaterial = $product['material'] ?? '';
                        ?>
                        <input type="text" name="material" value="<?php echo e($curMaterial); ?>"
                               list="materialList"
                               class="w-full border rounded px-4 py-2" placeholder="例：木材、アクリル、ステンレス">
                        <datalist id="materialList">
                            <?php foreach ($materialOptions as $opt): ?>
                            <option value="<?php echo e($opt); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="text-xs text-gray-400 mt-1">用于前台"素材で絞り込み"筛选，可自由填写或从候选中选</p>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">使用シーン (Scene)</label>
                        <?php
                        $sceneOptions = ['オフィス', '店舗', 'レストラン', 'カフェ', '美容院', 'クリニック', '住宅'];
                        $curScene = $product['scene'] ?? '';
                        ?>
                        <input type="text" name="scene" value="<?php echo e($curScene); ?>"
                               list="sceneList"
                               class="w-full border rounded px-4 py-2" placeholder="例：オフィス、店舗、住宅">
                        <datalist id="sceneList">
                            <?php foreach ($sceneOptions as $opt): ?>
                            <option value="<?php echo e($opt); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="text-xs text-gray-400 mt-1">用于前台"使用シーンで絞り込み"筛选</p>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_publish_status'); ?></label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($product['status'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo __('status_on'); ?></option>
                            <option value="0" <?php echo ($product['status'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo __('status_off'); ?></option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_top" value="1"
                                   <?php echo ($product['is_top'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('admin_top'); ?></span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_recommend" value="1"
                                   <?php echo ($product['is_recommend'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('admin_recommend'); ?></span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_hot" value="1"
                                   <?php echo ($product['is_hot'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('admin_hot'); ?></span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_new" value="1"
                                   <?php echo ($product['is_new'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('status_new'); ?></span>
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
                        <label class="block text-gray-700 mb-1"><?php echo __('label_market_price'); ?></label>
                        <input type="number" step="0.01" name="market_price" value="<?php echo $product['market_price'] ?? ''; ?>"
                               class="w-full border rounded px-4 py-2" placeholder="可选，用于对比">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_product_images'); ?></h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">封面图</label>
                        <input type="text" name="cover" id="coverInput"
                               value="<?php echo e($product['cover'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo __('label_image_url'); ?>">
                        <div class="flex gap-2 mt-2">
                            <button type="button" onclick="uploadCover()"
                                    class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                <?php echo __('admin_upload_image'); ?></button>
                            <button type="button" onclick="pickCoverFromMedia()"
                                    class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                <?php echo __("admin_media_library"); ?></button>
                        </div>
                        <div id="coverPreview" class="mt-2">
                            <?php if (!empty($product['cover'])): ?>
                            <img src="<?php echo e($product['cover']); ?>" class="w-full rounded">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_product_tags'); ?></h3>
                <div class="space-y-3">
                    <div>
                        <input type="text" name="tags" id="tagsInput" value="<?php echo e($product['tags'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo __('label_tags_hint'); ?>">
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

        </div>
    </div>

    <!-- 底部 sticky 操作栏 -->
    <div class="sticky bottom-0 z-30 -mx-6 -mb-6 mt-8 bg-white border-t shadow-[0_-4px_12px_rgba(0,0,0,0.05)] px-6 py-3">
        <div class="flex gap-3 justify-end items-center">
            <span class="text-xs text-gray-400 mr-auto hidden sm:inline">请确认后保存</span>
            <a href="/admin/product.php" class="px-5 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                <?php echo __('admin_back'); ?>
            </a>
            <button type="submit" class="px-8 py-2 bg-primary hover:bg-secondary text-white rounded transition inline-flex items-center gap-1 text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                <?php echo __("btn_save"); ?>
            </button>
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
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
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

// === 多图ギャラリー管理 ===
// 兼容读取：JSON 数组 / 换行分隔 / 历史的 || 分隔
function parseGalleryInput(raw) {
    raw = (raw || '').trim();
    if (!raw) return [];
    try {
        var arr = JSON.parse(raw);
        if (Array.isArray(arr)) return arr.filter(Boolean);
    } catch(e) {}
    if (raw.indexOf('||') !== -1) return raw.split('||').filter(Boolean);
    return raw.split(/\r?\n/).map(function(s){return s.trim();}).filter(Boolean);
}
var galleryImages = parseGalleryInput(document.getElementById('imagesInput').value);

function syncGallery() {
    // 统一存 JSON 数组
    document.getElementById('imagesInput').value = JSON.stringify(galleryImages);
    renderGallery();
}

function renderGallery() {
    var box = document.getElementById('galleryPreview');
    if (!box) return;
    if (galleryImages.length === 0) {
        box.innerHTML = '<div class="col-span-full text-sm text-gray-400 py-8 text-center border-2 border-dashed border-gray-300 rounded"><?php echo __('admin_gallery_empty') ?: '暂无图片，点击下方按钮添加'; ?></div>';
        return;
    }
    box.innerHTML = galleryImages.map(function(url, i) {
        var safe = url.replace(/"/g, '&quot;');
        return '<div class="relative group aspect-square bg-gray-100 rounded overflow-hidden" draggable="true" data-index="' + i + '">' +
               '  <img src="' + safe + '" class="w-full h-full object-cover pointer-events-none">' +
               '  <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-1">' +
               (i > 0 ? '    <button type="button" onclick="galleryMove(' + i + ',-1)" class="text-white bg-gray-700/80 hover:bg-gray-600 rounded w-7 h-7 text-sm" title="左移">←</button>' : '') +
               (i < galleryImages.length - 1 ? '    <button type="button" onclick="galleryMove(' + i + ',1)" class="text-white bg-gray-700/80 hover:bg-gray-600 rounded w-7 h-7 text-sm" title="右移">→</button>' : '') +
               '    <button type="button" onclick="removeGalleryImage(' + i + ')" class="text-white bg-red-500 hover:bg-red-600 rounded w-7 h-7 text-sm" title="<?php echo __('btn_delete'); ?>">×</button>' +
               '  </div>' +
               '  <div class="absolute top-1 left-1 bg-black/60 text-white text-xs px-1.5 py-0.5 rounded pointer-events-none">' + (i + 1) + '</div>' +
               '</div>';
    }).join('');
    // 拖拽排序
    box.querySelectorAll('[draggable="true"]').forEach(function(el) {
        el.addEventListener('dragstart', function(e) { e.dataTransfer.setData('text/plain', el.dataset.index); el.style.opacity='0.4'; });
        el.addEventListener('dragend', function() { el.style.opacity='1'; });
        el.addEventListener('dragover', function(e) { e.preventDefault(); el.style.outline='2px solid #3b82f6'; });
        el.addEventListener('dragleave', function() { el.style.outline=''; });
        el.addEventListener('drop', function(e) {
            e.preventDefault(); el.style.outline='';
            var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
            var to = parseInt(el.dataset.index, 10);
            if (!isNaN(from) && !isNaN(to) && from !== to) {
                var item = galleryImages.splice(from, 1)[0];
                galleryImages.splice(to, 0, item);
                syncGallery();
            }
        });
    });
}

function galleryMove(i, dir) {
    var j = i + dir;
    if (j < 0 || j >= galleryImages.length) return;
    var tmp = galleryImages[i];
    galleryImages[i] = galleryImages[j];
    galleryImages[j] = tmp;
    syncGallery();
}

function addGalleryImage(url) {
    galleryImages.push(url);
    syncGallery();
}

function removeGalleryImage(i) {
    galleryImages.splice(i, 1);
    syncGallery();
}

function uploadGalleryImage() {
    var input = document.createElement('input');
    input.type = 'file'; input.accept = 'image/*'; input.multiple = true;
    input.onchange = async function() {
        for (var f of this.files) {
            var formData = new FormData();
            formData.append('file', f);
            formData.append('type', 'images');
            try {
                var response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
                var data = await safeJson(response);
                if (data.code === 0) addGalleryImage(data.data.url);
                else showMessage(data.msg, 'error');
            } catch (err) { showMessage('<?php echo __('admin_upload_failed') ?: '上传失败'; ?>', 'error'); }
        }
    };
    input.click();
}

function pickGalleryFromMedia() {
    openMediaPicker(function(url) { addGalleryImage(url); });
}

// 首次渲染前把历史 || 格式规整为 JSON
syncGallery();

// === 规格参数管理 ===
var specsData = {};
try { specsData = JSON.parse(document.getElementById('specsInput').value || '{}'); } catch(e) { specsData = {}; }

// 规格键名标签映射
var specLabels = {
    'material': '<?php echo __("spec_material") ?: "素材分类"; ?>',
    'material_label': '<?php echo __("spec_material_label") ?: "素材名称"; ?>',
    'scene': '<?php echo __("spec_scene") ?: "使用场景"; ?>',
    'size': '<?php echo __("spec_size") ?: "サイズ"; ?>',
    'method': '<?php echo __("spec_method") ?: "工法"; ?>',
    'finish': '<?php echo __("spec_finish") ?: "仕上げ"; ?>',
    'mount': '<?php echo __("spec_mount") ?: "取付方法"; ?>',
    'use': '<?php echo __("spec_use") ?: "用途"; ?>',
    'corner': '<?php echo __("spec_corner") ?: "角丸"; ?>',
};

function renderSpecs() {
    var list = document.getElementById('specsList');
    list.innerHTML = '';
    var keys = Object.keys(specsData);
    if (keys.length === 0) {
        list.innerHTML = '<div class="text-sm text-gray-400 py-2"><?php echo __("admin_no_specs") ?: "暂无参数"; ?></div>';
    }
    keys.forEach(function(key) {
        var div = document.createElement('div');
        div.className = 'flex items-center gap-2';
        var label = specLabels[key] || key;
        div.innerHTML = '<input type="text" value="' + escapeAttr(key) + '" class="spec-key w-32 border rounded px-3 py-1.5 text-sm bg-gray-50" placeholder="键" onchange="updateSpecKey(this)" data-old="' + escapeAttr(key) + '">' +
            '<span class="text-gray-300">:</span>' +
            '<input type="text" value="' + escapeAttr(specsData[key]) + '" class="spec-val flex-1 border rounded px-3 py-1.5 text-sm" placeholder="值" onchange="updateSpecVal(this)" data-key="' + escapeAttr(key) + '">' +
            '<span class="text-xs text-gray-400 w-16 truncate" title="' + escapeAttr(label) + '">' + escapeAttr(label) + '</span>' +
            '<button type="button" onclick="removeSpec(\'' + escapeAttr(key) + '\')" class="text-red-400 hover:text-red-600 text-lg font-bold">&times;</button>';
        list.appendChild(div);
    });
}

function syncSpecs() {
    document.getElementById('specsInput').value = JSON.stringify(specsData);
    renderSpecs();
}

function addSpecRow() {
    var key = prompt('<?php echo __("admin_spec_key_prompt") ?: "参数名（英文键名，如 size、method）"; ?>');
    if (!key) return;
    key = key.trim();
    if (specsData.hasOwnProperty(key)) { alert('<?php echo __("admin_spec_exists") ?: "该参数已存在"; ?>'); return; }
    specsData[key] = '';
    syncSpecs();
    // 聚焦到新行的值输入框
    var inputs = document.querySelectorAll('.spec-val');
    if (inputs.length) inputs[inputs.length - 1].focus();
}

function updateSpecKey(el) {
    var oldKey = el.dataset.old;
    var newKey = el.value.trim();
    if (!newKey || newKey === oldKey) return;
    if (specsData.hasOwnProperty(newKey)) { alert('<?php echo __("admin_spec_exists") ?: "该参数已存在"; ?>'); el.value = oldKey; return; }
    specsData[newKey] = specsData[oldKey];
    delete specsData[oldKey];
    syncSpecs();
}

function updateSpecVal(el) {
    var key = el.dataset.key;
    specsData[key] = el.value;
    document.getElementById('specsInput').value = JSON.stringify(specsData);
}

function removeSpec(key) {
    delete specsData[key];
    syncSpecs();
}

function escapeAttr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;');
}

renderSpecs();
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
try {
    var editor = initWangEditor("#toolbar-container", "#editor-container", {
        placeholder: "请输入产品详情...",
        html: ' . $productContent . ',
        uploadUrl: "/admin/upload.php",
        onChange: function(editor) {
            document.getElementById("contentInput").value = editor.getHtml();
        }
    });
} catch(e) { console.warn("Editor init error:", e); }

document.getElementById("editForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    try { document.getElementById("contentInput").value = editor.getHtml(); } catch(ex) {}

    const formData = new FormData(this);
    const response = await fetch("", { method: "POST", body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage("<?php echo __('msg_save_success'); ?>");
        setTimeout(function() { location.href = "/admin/product.php"; }, 1000);
    } else {
        showMessage(data.msg, "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
