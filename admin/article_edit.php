<?php
/**
 * YikaiCMS - 文章编辑
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

// 多语言翻译创建器：拦截 action=create_translation 的 POST
$langSwitcher = [
    'table' => 'contents',
    'model' => contentModel(),
];
require_once ROOT_PATH . '/admin/includes/translate_action.php';

// id 来源：GET ?id=（编辑打开）或 POST id（表单隐藏域，保存后回填，防重复插入）
$id = getInt('id') ?: postInt('id');
$article = null;

if ($id > 0) {
    $article = contentModel()->find($id);
    if (!$article) {
        header('Location: /admin/article.php');
        exit;
    }
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'channel_id' => postInt('channel_id'),
        'type' => 'article',
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

    // 未选分类 → 默认归到「新闻资讯」根栏目（WP 式兜底：文章至少有一个分类，不会变成
    // channel_id=0 的孤儿而在前后台"消失"）。按文章所属语言取对应语言的 news 栏目。
    if ($data['channel_id'] <= 0) {
        if ($id > 0) {
            $artLang = (string) ($article['lang'] ?? config('site_lang', 'zh-CN'));
        } else {
            $rl = (string) get('lang', config('site_lang', 'zh-CN'));
            $artLang = in_array($rl, array_keys(availableLanguages()), true) ? $rl : (string) config('site_lang', 'zh-CN');
        }
        $news = getChannelBySlug('news');
        if ($news) {
            $data['channel_id'] = (($news['lang'] ?? '') === $artLang)
                ? (int) $news['id']
                : (findTranslatedChannelId((int) $news['id'], $artLang) ?: (int) $news['id']);
        }
    }

    $data['slug'] = resolveSlug($data['slug'], $data['title'], 'contents', $id);

    // 发布时间
    $publishTime = post('publish_time');
    if ($publishTime) {
        $data['publish_time'] = strtotime($publishTime);
    } elseif ($data['status'] == 1 && (!$article || !$article['publish_time'])) {
        $data['publish_time'] = time();
    }

    if ($id > 0) {
        // 保存即存档：覆盖前把旧版本快照下来（供「历史版本」查看/一键恢复）
        if ($article) {
            recordContentRevision('article', $id, (string) ($article['lang'] ?? ''), [[
                'table'  => 'contents',
                'id'     => $id,
                'fields' => [
                    'title'       => $article['title'] ?? '',
                    'subtitle'    => $article['subtitle'] ?? '',
                    'content'     => $article['content'] ?? '',
                    'blocks_data' => $article['blocks_data'] ?? null,
                    'summary'     => $article['summary'] ?? '',
                    'cover'       => $article['cover'] ?? '',
                ],
            ]], (string) ($article['title'] ?? ''));
        }
        contentModel()->updateById($id, $data);
        adminLog('article', 'update', "更新文章ID: $id");
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = $_SESSION['admin_id'];
        // 新建时按 URL ?lang= 决定写入哪种语言；否则 DB 默认 'zh-CN' 会把
        // 在 ja/en 上下文里建的文章存成 zh-CN，导致列表页 ?lang=ja/en 看不到。
        // 防御：校验语言代码在 availableLanguages() 白名单内，避免写入任意字符串污染 DB。
        $_reqLang = (string)get('lang', config('site_lang', 'zh-CN'));
        $_allowedLangs = array_keys(availableLanguages());
        $data['lang'] = in_array($_reqLang, $_allowedLangs, true) ? $_reqLang : (string)config('site_lang', 'zh-CN');
        if (!isset($data['publish_time'])) {
            $data['publish_time'] = $data['status'] == 1 ? time() : 0;
        }
        $id = contentModel()->create($data);
        adminLog('article', 'create', "创建文章ID: $id");
    }

    success(['id' => $id]);
}

// 获取栏目树（news 下的子栏目）：按文章所属语言查 news 栏目；
// 新建文章时按 URL ?lang= 或默认语言。
// 注意：翻译后的栏目 slug 为 news-{lang}，不能按 slug 直查，必须经 translation_group_id 映射。
$_editLang = $article['lang'] ?? get('lang', config('site_lang', 'zh-CN'));
$newsChannel = null;
$srcNews = getChannelBySlug('news');
if ($srcNews) {
    if ($srcNews['lang'] === $_editLang) {
        $newsChannel = $srcNews;
    } else {
        $sisterId = findTranslatedChannelId((int)$srcNews['id'], $_editLang);
        if ($sisterId > 0) {
            $newsChannel = channelModel()->find($sisterId);
        }
    }
}
$newsChannelId = $newsChannel ? (int)$newsChannel['id'] : 0;
$categories = $newsChannelId > 0 ? channelModel()->getFlatList($newsChannelId) : [];

$pageTitle = $article ? __('admin_edit') : __('admin_add');
$currentMenu = 'article';

$langSwitcher['item']     = $article;
$langSwitcher['edit_url'] = '/admin/article_edit.php';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php require ROOT_PATH . '/admin/includes/lang_switcher_edit.php'; ?>

<form id="editForm" class="space-y-6">
    <!-- 保存后由 JS 回填新建记录的 id，使后续提交转为更新，避免重复插入 -->
    <input type="hidden" name="id" id="articleId" value="<?php echo (int)($article['id'] ?? 0); ?>">
    <div class="flex gap-6">
        <!-- 主内容区 -->
        <div class="flex-1 space-y-6">
            <?php include __DIR__ . '/includes/ai_panel.php'; ?>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_article_title'); ?> <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($article['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2 text-lg" placeholder="<?php echo __('label_article_title'); ?>">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_slug'); ?> (Slug)</label>
                        <input type="text" name="slug" value="<?php echo e($article['slug'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2 text-sm text-gray-500" placeholder="<?php echo __('label_slug_hint'); ?>">
                    </div>

                    <input type="hidden" name="subtitle" value="<?php echo e($article['subtitle'] ?? ''); ?>">

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_article_summary'); ?></label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"
                                  placeholder="<?php echo __('label_article_summary'); ?>"><?php echo e($article['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_article_content'); ?></label>
                        <textarea name="content" id="contentEditor" class="tinymce-editor"><?php echo e($article['content'] ?? ''); ?></textarea>
                    </div>

                    <?php /* AI 助手面板由 admin/includes/footer.php 自动注入 */ ?>

                </div>
            </div>
        </div>

        <!-- 侧边栏 -->
        <div class="w-80 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_publish_settings'); ?></h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-2"><?php echo __('label_category'); ?></label>
                        <input type="hidden" name="channel_id" id="categoryIdInput" value="<?php echo (int)($article['channel_id'] ?? 0); ?>">
                        <div class="border rounded p-3 max-h-60 overflow-y-auto space-y-1" id="categoryTree">
                            <?php
                            $currentCatId = (int)($article['channel_id'] ?? 0);
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
                            <div class="text-sm text-gray-400 text-center py-2"><?php echo __('empty_no_category'); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>


                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_publish_status'); ?></label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($article['status'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo __('admin_published'); ?></option>
                            <option value="0" <?php echo ($article['status'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo __('admin_draft'); ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_publish_time'); ?></label>
                        <input type="datetime-local" name="publish_time"
                               value="<?php echo $article['publish_time'] ? date('Y-m-d\TH:i', (int)$article['publish_time']) : ''; ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_top" value="1"
                                   <?php echo ($article['is_top'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('admin_top'); ?></span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_recommend" value="1"
                                   <?php echo ($article['is_recommend'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('admin_recommend'); ?></span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_hot" value="1"
                                   <?php echo ($article['is_hot'] ?? 0) ? 'checked' : ''; ?>>
                            <span><?php echo __('admin_hot'); ?></span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_cover_image'); ?></h3>
                <div class="space-y-2">
                    <input type="text" name="cover" id="coverInput"
                           value="<?php echo e($article['cover'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo __('label_image_url'); ?>">
                    <div class="flex gap-2">
                        <button type="button" onclick="uploadCover()"
                                class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                            <i class="ti ti-upload text-base"></i>
                            <?php echo __('admin_upload_image'); ?></button>
                        <button type="button" onclick="pickCoverFromMedia()"
                                class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                            <i class="ti ti-photo text-base"></i>
                            <?php echo __('admin_media_library'); ?></button>
                    </div>
                    <div id="coverPreview">
                        <?php if (!empty($article['cover'])): ?>
                        <img src="<?php echo e($article['cover']); ?>" class="w-full rounded">
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_other_info'); ?></h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_author'); ?></label>
                        <input type="text" name="author" value="<?php echo e($article['author'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_source'); ?></label>
                        <input type="text" name="source" value="<?php echo e($article['source'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_tags'); ?></label>
                        <input type="text" name="tags" value="<?php echo e($article['tags'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo __('label_tags_hint'); ?>">
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('btn_save'); ?>
                </button>
                <a href="/admin/article.php" class="flex-1 text-center border py-2 rounded hover:bg-gray-100 transition inline-flex items-center justify-center gap-1">
                    <i class="ti ti-arrow-left text-base"></i>
                    <?php echo __('admin_back'); ?>
                </a>
            </div>
        </div>
    </div>
</form>

<input type="file" id="coverFileInput" class="hidden" accept="image/*">

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
</script>

<?php
// 注意：下面是 heredoc（双引号语义），仅 {$msgSaveSuccess} 会被插值。
// 不要改回 nowdoc（单引号定界符），否则内嵌的 PHP 标签不会被执行、会原样输出到 JS。
// 切勿在本注释里写 PHP 闭合标签，否则会提前结束 PHP 块、把后面的代码当文本输出。
$msgSaveSuccess = json_encode(__('msg_save_success'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$extraJs = <<<JSEOF
<script>
initTinyEditor(".tinymce-editor");

(function () {
    const form = document.getElementById("editForm");
    let submitting = false;

    // 阻止在普通输入框（如"标签"）按回车触发表单隐式提交 —— 这会导致一次静默保存，
    // 再点"保存"就会重复插入。textarea / 富文本编辑器不受影响。
    form.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && e.target.tagName === "INPUT"
            && e.target.type !== "submit" && e.target.type !== "button") {
            e.preventDefault();
        }
    });

    form.addEventListener("submit", async function (e) {
        e.preventDefault();
        if (submitting) return;              // 防连点 / 并发重复提交
        submitting = true;
        tinymce.triggerSave();

        try {
            const formData = new FormData(this);
            const response = await fetch("", { method: "POST", body: formData });
            const data = await safeJson(response);

            if (data.code === 0) {
                // 回填新建记录的 id → 后续任何提交都走"更新"，不再重复插入
                if (data.data && data.data.id) {
                    document.getElementById("articleId").value = data.data.id;
                }
                showMessage({$msgSaveSuccess});
                setTimeout(function () { location.href = "/admin/article.php"; }, 1000);
            } else {
                submitting = false;          // 失败允许重试
                showMessage(data.msg, "error");
            }
        } catch (err) {
            submitting = false;
            showMessage("网络错误，请重试", "error");
        }
    });
})();

</script>
JSEOF;

// 历史版本面板（仅已保存文章）
if ($id > 0) {
    // 供 require 的 revision_panel.php 使用（豁免见 psalm.xml issueHandlers）
    $revType = 'article';
    $revTargetId = (int) $id;
    require ROOT_PATH . '/admin/includes/revision_panel.php';
}

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
