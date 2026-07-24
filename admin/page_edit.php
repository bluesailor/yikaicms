<?php
/**
 * YikaiCMS - 单页编辑（富文本模式）
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
    'table'         => 'channels',
    'model'         => channelModel(),
    'title_field'   => 'name',
    'summary_field' => 'description',
];
require_once ROOT_PATH . '/admin/includes/translate_action.php';

$id = getInt('id');

if (!$id) {
    header('Location: /admin/page.php');
    exit;
}

// 获取页面数据
$page = channelModel()->findWhere(['id' => $id, 'type' => 'page']);

if (!$page) {
    header('Location: /admin/page.php');
    exit;
}

// 联系我们栏目跳转到联系设置页
if (($page['slug'] ?? '') === 'contact') {
    header('Location: /admin/setting_contact.php');
    exit;
}

if (($page['slug'] ?? '') === 'history') {
    header('Location: /admin/timeline.php');
    exit;
}

// 检查跳转设置
$redirectType = $page['redirect_type'] ?? 'auto';
$redirectTarget = null;

// 父页有子级：不再静默跳转到第一个子页（那样父页无法编辑、也违背「不跳转」设置），
// 改为在页面顶部显示醒目横幅（见 parent_page_notice.php），父页本身可直接编辑。
$children = channelModel()->getByParent($id, true);

if ($redirectType === 'url' && !empty($page['redirect_url'])) {
    $redirectTarget = ['name' => $page['redirect_url'], '_is_url' => true];
}

// 从 contents 表获取该栏目的内容（与前端一致）
$contentRecord = contentModel()->queryOne(
    'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 ORDER BY is_top DESC, id DESC LIMIT 1',
    [$id]
);

$contentType = 'html';

if ($contentRecord) {
    $page['content'] = $contentRecord['content'];
    $contentType = $contentRecord['content_type'] ?? 'html';
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = resolveSlug(post('slug'), post('name'), 'channels', $id);

    $newContent = $_POST['content'] ?? '';

    $channelData = [
        'name' => post('name'),
        'slug' => $slug,
        'description' => post('description'),
        'content' => $newContent,
        'image' => post('image'),
        'show_sidebar' => isset($_POST['show_sidebar']) ? 1 : 0,
        'show_cover' => isset($_POST['show_cover']) ? 1 : 0,
        'seo_title' => post('seo_title'),
        'seo_keywords' => post('seo_keywords'),
        'seo_description' => post('seo_description'),
        'updated_at' => time(),
    ];

    // 保存即存档：覆盖前把旧版本快照下来（channels + 同步的 contents 行）
    $revTargets = [[
        'table'  => 'channels',
        'id'     => $id,
        'fields' => [
            'name'            => $page['name'] ?? '',
            'content'         => $page['content'] ?? '',
            'description'     => $page['description'] ?? '',
            'image'           => $page['image'] ?? '',
            'seo_title'       => $page['seo_title'] ?? '',
            'seo_keywords'    => $page['seo_keywords'] ?? '',
            'seo_description' => $page['seo_description'] ?? '',
        ],
    ]];
    if ($contentRecord) {
        $revTargets[] = ['table' => 'contents', 'id' => (int) $contentRecord['id'], 'fields' => [
            'content'      => $contentRecord['content'] ?? '',
            'content_type' => $contentRecord['content_type'] ?? 'html',
            'blocks_data'  => $contentRecord['blocks_data'] ?? null,
        ]];
    }
    recordContentRevision('page', $id, (string) ($page['lang'] ?? ''), $revTargets, (string) ($page['name'] ?? ''));

    channelModel()->updateById($id, $channelData);

    // 同步到 contents 表（向后兼容）
    if ($contentRecord) {
        contentModel()->updateById((int)$contentRecord['id'], [
            'content' => $newContent,
            'content_type' => 'html',
            'blocks_data' => null,
            'updated_at' => time(),
        ]);
    } else {
        contentModel()->create([
            'channel_id' => $id,
            'lang' => siteLang(),
            'title' => post('name'),
            'content' => $newContent,
            'content_type' => 'html',
            'blocks_data' => null,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
    adminLog('page', 'edit', sprintf(__('pe_log_edit'), $channelData['name']));
    success();
}

$pageTitle = sprintf(__('pe_edit_page_title'), $page['name']);
$currentMenu = 'page';

// widget 用：当前编辑行 + URL 信息
$langSwitcher['item']     = $page;
$langSwitcher['edit_url'] = '/admin/page_edit.php';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php require ROOT_PATH . '/admin/includes/lang_switcher_edit.php'; ?>

<div class="mb-6 flex items-center justify-between">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <i class="ti ti-chevron-left text-base"></i>
        <?php echo __('admin_back'); ?>
    </a>
    <div class="flex items-center gap-2">
        <a href="<?php echo channelUrl($page); ?>" target="_blank" rel="noopener" class="border border-gray-300 text-gray-700 hover:border-primary hover:text-primary px-4 py-2 rounded text-sm inline-flex items-center gap-1 transition">
            <i class="ti ti-eye text-base"></i>
            <?php echo __('page_preview'); ?>
        </a>
        <a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
            <i class="ti ti-layout-columns text-base"></i>
            <?php echo __('page_switch_advance'); ?>
        </a>
    </div>
</div>

<?php $childEditBase = '/admin/page_edit.php'; require ROOT_PATH . '/admin/includes/parent_page_notice.php'; ?>

<?php if ($contentType === 'blocks'): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <i class="ti ti-info-circle text-lg text-amber-500 mt-0.5 shrink-0"></i>
    <div class="text-sm text-amber-800">
        <p><?php echo __('pe_advance_warning'); ?></p>
        <p class="mt-1"><a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>" class="text-primary hover:underline font-medium"><?php echo __('pe_go_advance'); ?></a></p>
    </div>
</div>
<?php endif; ?>

<?php if ($redirectTarget): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <i class="ti ti-info-circle text-lg text-amber-500 mt-0.5 shrink-0"></i>
    <div class="text-sm text-amber-800">
        <?php if (!empty($redirectTarget['_is_url'])): ?>
        <p><?php echo __('pe_redirect_to'); ?><strong><?php echo e($redirectTarget['name']); ?></strong></p>
        <?php else: ?>
        <p><?php echo sprintf(__('pe_redirect_child_intro'), e($page['name']), e($redirectTarget['name'])); ?></p>
        <?php endif; ?>
        <p class="mt-1 text-amber-600"><?php echo sprintf(__('pe_redirect_change_hint'), '<a href="/admin/channel.php?edit=' . $id . '" class="underline hover:text-amber-800">' . __('pe_channel_management') . '</a>'); ?></p>
    </div>
</div>
<?php endif; ?>

<form id="editForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_basic_info'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo __('page_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?php echo e($page['name']); ?>" required
                           class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_slug'); ?> (Slug)</label>
                    <input type="text" name="slug" value="<?php echo e($page['slug']); ?>"
                           class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_slug_ph'); ?>">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('label_page_desc'); ?></label>
                <textarea name="description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($page['description']); ?></textarea>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('label_cover_image'); ?></label>
                <input type="text" name="image" id="imageInput" value="<?php echo e($page['image']); ?>"
                       class="w-full border rounded px-3 py-2 text-sm mb-2">
                <div class="flex gap-2">
                    <button type="button" onclick="uploadImage()"
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <i class="ti ti-upload text-base"></i>
                        <?php echo __('admin_upload_image'); ?></button>
                    <button type="button" onclick="pickImageFromMedia()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <i class="ti ti-photo text-base"></i>
                        <?php echo __('admin_media_library'); ?></button>
                </div>
                <?php if ($page['image']): ?>
                <img src="<?php echo e($page['image']); ?>" id="imagePreview" class="h-24 mt-2 rounded">
                <?php endif; ?>
                <label class="flex items-center gap-2 cursor-pointer mt-3">
                    <input type="checkbox" name="show_cover" value="1" <?php echo (int)($page['show_cover'] ?? 1) === 1 ? 'checked' : ''; ?>
                           class="rounded border-gray-300 text-primary focus:ring-primary">
                    <span class="text-sm text-gray-700"><?php echo __('page_show_cover'); ?></span>
                </label>
                <p class="text-xs text-gray-400 mt-1"><?php echo __('page_show_cover_tip'); ?></p>
            </div>

            <div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="show_sidebar" value="1" <?php echo (int)($page['show_sidebar'] ?? 1) === 1 ? 'checked' : ''; ?>
                           class="rounded border-gray-300 text-primary focus:ring-primary">
                    <span class="text-sm text-gray-700"><?php echo __('page_show_sidebar'); ?></span>
                </label>
                <p class="text-xs text-gray-400 mt-1"><?php echo __('page_show_sidebar_tip'); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_content'); ?></h2>
            <?php if (str_starts_with((string)($page['slug'] ?? ''), 'organization')): ?>
            <div class="flex items-center gap-2">
                <select id="orgStyleSel" onchange="applyOrgStyle(this.value)"
                        class="border border-gray-300 rounded text-sm px-2 py-1.5 text-gray-700">
                    <option value=""><?php echo __('page_org_style'); ?>…</option>
                    <option value="default"><?php echo __('page_org_style_default'); ?></option>
                    <option value="teal"><?php echo __('page_org_style_teal'); ?></option>
                    <option value="dark"><?php echo __('page_org_style_dark'); ?></option>
                    <option value="purple"><?php echo __('page_org_style_purple'); ?></option>
                    <option value="amber"><?php echo __('page_org_style_amber'); ?></option>
                    <option value="minimal"><?php echo __('page_org_style_minimal'); ?></option>
                </select>
                <button type="button" onclick="insertOrgDemo()"
                        class="border border-gray-300 text-gray-700 hover:border-primary hover:text-primary px-3 py-1.5 rounded text-sm inline-flex items-center gap-1 transition">
                    <i class="ti ti-file-text text-base"></i>
                    <?php echo __('page_insert_org_demo'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
            <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
            <input type="hidden" name="content" id="contentInput">
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_seo_settings'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_seo_title'); ?></label>
                <input type="text" name="seo_title" value="<?php echo e($page['seo_title']); ?>"
                       class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_seo_title_ph'); ?>">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_seo_keywords'); ?></label>
                <input type="text" name="seo_keywords" value="<?php echo e($page['seo_keywords']); ?>"
                       class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_seo_keywords_ph'); ?>">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_seo_description'); ?></label>
                <textarea name="seo_description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($page['seo_description']); ?></textarea>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <i class="ti ti-check text-base"></i>
            <?php echo __('admin_save'); ?>
        </button>
    </div>
</form>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script>
function uploadImage() {
    document.getElementById('imageFileInput').click();
}

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('imageInput').value = data.data.url;
            let preview = document.getElementById('imagePreview');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'imagePreview';
                preview.className = 'h-24 mt-2 rounded';
                document.getElementById('imageInput').parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }
    this.value = '';
});

function pickImageFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('imageInput').value = url;
        var preview = document.getElementById('imagePreview');
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'imagePreview';
            preview.className = 'h-24 mt-2 rounded';
            document.getElementById('imageInput').parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

<?php
// 组织架构示例（仅结构，样式由前端 style.css 的 .org-chart 提供，TinyMCE 删不掉）
$orgDemoHtml = <<<'HTML'
<div class="org-chart">
  <ul style="padding-top:0">
    <li style="padding-top:0">
      <div class="org-node org-ceo">张伟<span class="org-title">董事长 / CEO</span></div>
      <ul>
        <li>
          <div class="org-node org-vp">李明<span class="org-title">副总裁 · 技术</span></div>
          <ul>
            <li><div class="org-node org-dept">研发部</div></li>
            <li><div class="org-node org-dept">测试部</div></li>
            <li><div class="org-node org-dept">运维部</div></li>
          </ul>
        </li>
        <li>
          <div class="org-node org-vp">王芳<span class="org-title">副总裁 · 营销</span></div>
          <ul>
            <li><div class="org-node org-dept">市场部</div></li>
            <li><div class="org-node org-dept">销售部</div></li>
            <li><div class="org-node org-dept">客服部</div></li>
          </ul>
        </li>
        <li>
          <div class="org-node org-vp">赵强<span class="org-title">副总裁 · 运营</span></div>
          <ul>
            <li><div class="org-node org-dept">财务部</div></li>
            <li><div class="org-node org-dept">人力资源部</div></li>
            <li><div class="org-node org-dept">行政部</div></li>
          </ul>
        </li>
      </ul>
    </li>
  </ul>
</div>
<p style="margin-top:32px;padding:20px;background:#f8fafc;border-radius:8px;">公司设有<strong>技术中心</strong>、<strong>营销中心</strong>、<strong>运营中心</strong>三大业务板块，下辖 9 个职能部门。请按实际情况修改上方图中的姓名与部门。</p>
HTML;
?>
const ORG_DEMO_HTML = <?php echo json_encode($orgDemoHtml, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function insertOrgDemo() {
    if (typeof editor === 'undefined' || !editor) return;
    var cur = (editor.getHtml() || '').replace(/<[^>]*>/g, '').trim();
    if (cur && !confirm('<?php echo __('page_insert_org_demo_confirm'); ?>')) return;
    editor.setHtml(ORG_DEMO_HTML);
    document.getElementById('contentInput').value = ORG_DEMO_HTML;
    showMessage('<?php echo __('admin_success'); ?>');
}

// 切换组织架构图配色：已有图则只换修饰类（保留姓名/部门），无图则插入示例并套用
function applyOrgStyle(style) {
    var sel = document.getElementById('orgStyleSel');
    if (!style) return;
    if (typeof editor === 'undefined' || !editor) { if (sel) sel.value = ''; return; }
    var cls = (style === 'default') ? 'org-chart' : 'org-chart org-style-' + style;
    var re = /class\s*=\s*["']org-chart[^"']*["']/;
    var html = editor.getHtml() || '';
    html = re.test(html) ? html.replace(re, 'class="' + cls + '"')
                         : ORG_DEMO_HTML.replace(re, 'class="' + cls + '"');
    editor.setHtml(html);
    document.getElementById('contentInput').value = html;
    if (sel) sel.value = '';
    showMessage('<?php echo __('admin_success'); ?>');
}
</script>

<?php
$pageContent = json_encode($page['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "' . __('label_content') . '...",
    html: ' . $pageContent . ',
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
            showMessage("' . __('msg_save_success') . '");
        } else {
            showMessage(data.msg, "error");
        }
    } catch (err) {
        showMessage("' . __('btn_request_failed') . '", "error");
    }
});
</script>';

// 历史版本面板（仅已保存单页）
if ($id > 0) {
    $revType = 'page';
    $revTargetId = (int) $id;
    require ROOT_PATH . '/admin/includes/revision_panel.php';
}

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
