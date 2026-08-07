<?php
/**
 * YikaiCMS - 单页排版编辑器（高级模式）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/HtmlCache.php';

checkLogin();
requirePermission('edit_page');

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/presets.php';

$id = getInt('id');
$isHomeLayout = (string) ($_GET['home'] ?? '') === '1';
// 联系页标记：首页版式分支不会赋值，先给默认，避免下游用 empty() 判布尔
$isContactPage = false;
$homeProductOptions = [];

if (!$id && !$isHomeLayout) {
    header('Location: /admin/page.php');
    exit;
}

if ($isHomeLayout) {
    // 首页排版与「首页设置」「首页发布 API」同权限：edit_page 只够改单页，
    // 不足以改首页——这里若只沿用页面级的 edit_page，等于绕开那两处的 * 校验。
    requirePermission('*');

    $homeDocument = HomeLayoutDocument::load();
    try {
        foreach (productModel()->getList(0, 500, 0, []) as $product) {
            $productId = (int) ($product['id'] ?? 0);
            if ($productId > 0) {
                $homeProductOptions[] = [
                    'id' => $productId,
                    'title' => (string) ($product['title'] ?? ('#' . $productId)),
                ];
            }
        }
    } catch (Throwable) {
        $homeProductOptions = [];
    }

    $page = [
        'id' => 0,
        'name' => __('admin_home'),
        'slug' => '',
        'description' => '',
        'content' => '',
        'image' => '',
        'seo_title' => '',
        'seo_keywords' => '',
        'seo_description' => '',
        'lang' => siteLang(),
    ];
    $contentRecord = null;
    $contentType = 'blocks';
    $blocksData = json_encode(
        $homeDocument['sections'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $htmlContent = '';
    $autoConvert = false;
    // 打开时的排版草稿时间戳；保存时比对基线，避免多个标签页互相静默覆盖。
    // 免费版排版草稿与 Blox 草稿使用不同 setting key。
    $homeBaseUpdatedAt = (int) ($homeDocument['updated_at'] ?? 0);
    $homeBannerSeeds = [];
    foreach (getBanners('home', HomeBloxBlockSchema::MAX_ITEMS) as $banner) {
        if (is_array($banner)) {
            $homeBannerSeeds[] = HomeBannerItemElement::fromLegacy($banner);
        }
    }
} else {
    $homeBannerSeeds = [];
    $page = channelModel()->findWhere(['id' => $id, 'type' => 'page']);

    if (!$page) {
        header('Location: /admin/page.php');
        exit;
    }

    // 父栏目若会自动跳到子栏目，这里编的内容前台根本看不到——必须在编辑器里说明，
    // 否则用户改半天没效果还以为是 bug。redirect_type=url 同理。
    $redirectNotice = '';
    $redirectTarget = '';
    $_rt = (string) ($page['redirect_type'] ?? 'auto');
    if ($_rt === 'url' && trim((string) ($page['redirect_url'] ?? '')) !== '') {
        $redirectNotice = 'url';
        $redirectTarget = (string) $page['redirect_url'];
    } elseif ($_rt === 'auto') {
        $_kids = channelModel()->getByParent((int) $page['id'], true);
        if (!empty($_kids[0])) {
            $redirectNotice = 'auto';
            $redirectTarget = (string) ($_kids[0]['name'] ?? '');
        }
    }

    // 联系页允许进排版编辑器：这里编的是「附加内容区块」（渲染在卡片/表单/地图下方），
    // 卡片、表单、地图仍在「联系我们设置」里维护。进来时给出说明，避免误以为
    // 在这儿能改联系方式。（原先无条件跳转到 setting_contact.php，等于无法排版。）
    // 联系页判定必须涵盖多语言版本：/en/contact-en.html、/ja/contact-ja.html 前台同样
    // 委托 contact.php 渲染（判定逻辑见 page.php），其 content 字段一样是死数据。
    // 只认 slug='contact' 会漏掉译版，把入口留在会误导人的地方。
    $isContactPage = ($page['slug'] ?? '') === 'contact';
    if (!$isContactPage && !empty($page['translation_group_id'])) {
        $__src = channelModel()->queryOne(
            'SELECT slug FROM ' . channelModel()->tableName() . ' WHERE id = ? LIMIT 1',
            [(int) $page['translation_group_id']]
        );
        $isContactPage = ($__src['slug'] ?? '') === 'contact';
    }

    if (($page['slug'] ?? '') === 'history') {
        header('Location: /admin/timeline.php');
        exit;
    }

    $children = channelModel()->getByParent($id, true);

    $contentRecord = contentModel()->queryOne(
        'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 ORDER BY is_top DESC, id DESC LIMIT 1',
        [$id]
    );

    $contentType = 'html';
    $blocksData = '';
    $htmlContent = '';

    if ($contentRecord) {
        $contentType = $contentRecord['content_type'] ?? 'html';
        $blocksData = $contentRecord['blocks_data'] ?? '';
        $htmlContent = $contentRecord['content'] ?? '';
    }

    $autoConvert = false;
    if ($contentType === 'html' && $htmlContent && !$blocksData) {
        $autoConvert = true;
    }

    // 联系页尚未排版：画布按当前前台版式预置（卡片 + 表单/地图两列），打开即所见即所编，
    // 不必先点按钮。此处只影响编辑器画布——未点保存前，库里仍是原样、前台仍走固定版式。
    // 同时关掉 HTML 自动转换：联系页的 content 字段前台从不渲染，转出来的是一个看不见的死区块。
    $contactSeeded = false;
    if ($isContactPage) {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        if (!$blocksData) {
            $blocksData   = json_encode(contactSeedSections(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $autoConvert  = false;
            $contactSeeded = true;
        }
    }
}if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    // blox=1：Blox 画布请求。开编辑上下文让渲染器输出 data-yk-sec 定位标记，
    // 并注入点选/高亮/空区块占位脚本；排版编辑器的纯预览不带此参数，输出不变。
    $bloxCanvas = (($_POST['blox'] ?? '') === '1');
    // 编辑器预览/画布里隐藏的区块照常显示（灰显标注），否则一隐藏就从画布消失、没法再点回来
    require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    BlockRenderer::$showHidden = true;
    if ($bloxCanvas) {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        // 首页没有真实 channel id，但编辑态仍需要一个非零标记开关输出 data-yk-* 定位属性。
        BlockRenderer::$editChannelId = $isHomeLayout ? 1 : $id;
    }
    // 头尾模板画布：可编辑模板段 + 首页正文只读上下文（灰罩不可选）。
    $templateArea = (string) ($_GET['template_area'] ?? '');
    if ($isHomeLayout && in_array($templateArea, ['header', 'footer'], true)) {
        $editableArea = BlockRenderer::render((string) ($_POST['blocks_data'] ?? '[]'));

        // 上下文正文：无编辑标记（editChannelId 归零后再渲染），画布上不可点选
        $savedEditChannel = BlockRenderer::$editChannelId;
        BlockRenderer::$editChannelId = 0;
        $homeDoc = HomeBloxDocument::load();
        $ctxContext = HomeBloxRenderContext::fromCurrentSite(false);
        $contextBody = HomeBloxRenderer::render($homeDoc['sections'], [$ctxContext, 'renderLegacyBlock']);
        BlockRenderer::$editChannelId = $savedEditChannel;

        $dim = '<div class="yk-ctx-dim" aria-hidden="true">' . $contextBody . '</div>';
        $body = $templateArea === 'header' ? $editableArea . $dim : $dim . $editableArea;
    } elseif ($isHomeLayout) {
        $previewSections = json_decode((string) ($_POST['blocks_data'] ?? '[]'), true);
        if (is_array($previewSections) && isset($previewSections['sections']) && is_array($previewSections['sections'])) {
            $previewSections = $previewSections['sections'];
        }
        $previewSections = is_array($previewSections) ? $previewSections : [];
        $homePreviewContext = HomeBloxRenderContext::fromCurrentSite($bloxCanvas);
        $body = HomeBloxRenderer::render($previewSections, [$homePreviewContext, 'renderLegacyBlock']);
    } else {
        $body = renderBlocksToHtml($_POST['blocks_data'] ?? '[]');
    }

    $bloxInject = '';
    if ($bloxCanvas) {
        // 与 blox_editor.php 的协议：iframe 点击段落 → parent 收 ykPick；
        // parent 发 ykHighlight 恢复选中描边，只有 ykScroll=true 才主动定位。
        // 空段落（白底无内容）在画布上不可见，编辑态补一块虚线占位——只存在于画布，不进保存的 HTML。
        $bloxInject = <<<'HTML'
<style>
[data-yk-sec]{position:relative;cursor:pointer}
[data-yk-sec]:hover{outline:2px dashed #93c5fd;outline-offset:-2px}
[data-yk-sec].yk-selected{outline:2px solid #3b82f6;outline-offset:-2px}
.yk-ctx-dim{opacity:.42;pointer-events:none;user-select:none;filter:grayscale(.35);position:relative}
.yk-ctx-dim:before{content:'';position:absolute;inset:0;z-index:20;background:repeating-linear-gradient(135deg,transparent 0 14px,rgba(100,116,139,.05) 14px 28px)}
[data-yk-hide-on]{position:relative}
[data-yk-hide-on]:before{content:'\2298 ' attr(data-yk-hide-on);position:absolute;z-index:28;top:4px;left:4px;padding:2px 7px;border-radius:4px;background:#64748b;color:#fff;font:700 10px/1.4 system-ui,sans-serif;pointer-events:none;opacity:.85}
[data-yk-con]{position:relative;cursor:pointer;outline:1px dashed rgba(245,158,11,.55);outline-offset:-3px;box-shadow:inset 0 0 0 1px rgba(245,158,11,.08)}
[data-yk-con]:hover{outline:2px dashed #f59e0b;outline-offset:-2px}
[data-yk-con].yk-con-selected{outline:2px solid #f59e0b;outline-offset:-2px}
[data-yk-col]{position:relative;cursor:pointer;min-height:56px;border-radius:8px;outline:1px dashed rgba(34,197,94,.32);outline-offset:-2px}
[data-yk-col]:empty:before{content:'\5217';position:absolute;inset:8px;border-radius:6px;background:rgba(34,197,94,.06);display:flex;align-items:center;justify-content:center;color:#86efac;font:12px/1.4 system-ui,sans-serif}
[data-yk-col]:hover{outline:2px dashed #22c55e;outline-offset:-2px}
[data-yk-col].yk-col-selected{outline:2px solid #22c55e;outline-offset:-2px;background:rgba(34,197,94,.04)}
.yk-edit-el{cursor:pointer}
[data-yk-sec-field]{cursor:text}
[data-yk-sec-field]:hover{outline:2px dashed #60a5fa;outline-offset:4px;border-radius:4px}
[data-yk-home-field]{cursor:pointer}
[data-yk-home-field="override_title"],[data-yk-home-field="override_content"],[data-yk-home-field="override_button_text"]{cursor:text}
[data-yk-home-field]:hover{outline:2px dashed #60a5fa;outline-offset:4px;border-radius:4px}
[data-yk-home="about"] [data-yk-home-columns]{position:relative;isolation:isolate}
[data-yk-home="about"] [data-yk-home-columns]:after{content:attr(data-yk-home-layout-label);position:absolute;z-index:30;top:-30px;left:50%;transform:translateX(-50%);padding:4px 9px;border:1px solid #bfdbfe;border-radius:4px;background:#eff6ff;color:#1d4ed8;font:700 11px/1.4 system-ui,sans-serif;white-space:nowrap;box-shadow:0 2px 8px rgba(37,99,235,.12);pointer-events:none}
[data-yk-home="about"] [data-yk-home-column]{position:relative;min-height:180px;outline:2px dashed rgba(37,99,235,.68);outline-offset:6px;background:rgba(37,99,235,.045);box-shadow:inset 0 0 0 1px rgba(37,99,235,.08);cursor:pointer;transition:outline-color .15s ease,background-color .15s ease}
[data-yk-home="about"] [data-yk-home-column]:before{content:attr(data-yk-home-column-label);position:absolute;z-index:25;top:8px;right:8px;max-width:calc(100% - 16px);padding:4px 8px;border-radius:4px;background:#2563eb;color:#fff;font:700 11px/1.4 system-ui,sans-serif;white-space:nowrap;box-shadow:0 2px 8px rgba(37,99,235,.22);pointer-events:none}
[data-yk-home="about"] [data-yk-home-column="image"]{outline-color:rgba(8,145,178,.72);background:rgba(8,145,178,.045);box-shadow:inset 0 0 0 1px rgba(8,145,178,.09)}
[data-yk-home="about"] [data-yk-home-column="image"]:before{background:#0891b2;box-shadow:0 2px 8px rgba(8,145,178,.22)}
[data-yk-home="about"] [data-yk-home-column]:hover{outline-style:solid;outline-color:#2563eb;background-color:rgba(37,99,235,.08)}
[data-yk-home="about"] [data-yk-home-column="image"]:hover{outline-color:#0891b2;background-color:rgba(8,145,178,.08)}
.yk-column-resize-host{position:relative}
.yk-column-resizer{position:absolute;z-index:45;width:24px;transform:translateX(-50%);cursor:col-resize;touch-action:none;display:flex;align-items:center;justify-content:center;user-select:none}
.yk-column-resizer:before{content:'';position:absolute;inset:0 10px;border-radius:999px;background:#2563eb;box-shadow:0 0 0 2px rgba(255,255,255,.92),0 3px 10px rgba(37,99,235,.3)}
.yk-column-resizer span{position:relative;width:10px;height:22px;border:1px solid #93c5fd;border-radius:4px;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.18)}
.yk-column-resizer span:before{content:'\22ee';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#2563eb;font:700 13px/1 system-ui,sans-serif}
.yk-column-resizer:hover span,.yk-column-resizer:focus span,.yk-column-resizer.yk-resizing span{background:#2563eb;color:#fff;outline:none}
body.yk-column-resizing{cursor:col-resize!important;user-select:none!important}
@media(max-width:1023px){.yk-column-resizer{display:none!important}}
.yk-inline-editing{outline:2px solid #2563eb!important;outline-offset:4px;border-radius:4px;cursor:text!important;caret-color:#2563eb}
.yk-inline-editing:focus{box-shadow:0 0 0 4px rgba(37,99,235,.12)}
.yk-pick-overlay{position:fixed;z-index:2147483646;pointer-events:none;border:2px solid #3b82f6;border-radius:4px;box-shadow:0 0 0 1px rgba(255,255,255,.8),0 6px 18px rgba(37,99,235,.18)}
.yk-pick-label{position:fixed;z-index:2147483647;pointer-events:none;background:#2563eb;color:#fff;font:12px/1.4 system-ui,sans-serif;padding:2px 6px;border-radius:4px;box-shadow:0 4px 12px rgba(37,99,235,.25)}
.yk-drop-line{position:fixed;z-index:2147483645;display:none;height:3px;min-width:36px;background:#2563eb;border-radius:999px;box-shadow:0 0 0 2px rgba(255,255,255,.9),0 2px 8px rgba(37,99,235,.35);pointer-events:none}
.yk-drop-line:before,.yk-drop-line:after{content:'';position:absolute;top:50%;width:8px;height:8px;background:#2563eb;border-radius:50%;transform:translateY(-50%)}
.yk-drop-line:before{left:-2px}.yk-drop-line:after{right:-2px}
.yk-drop-line.yk-drop-invalid{background:#dc2626}
.yk-drop-line.yk-drop-invalid:before,.yk-drop-line.yk-drop-invalid:after{background:#dc2626;content:'\00d7';width:auto;height:auto;font:700 12px/1 system-ui;color:#dc2626;background:transparent;top:-14px}
.yk-empty-hint{border:2px dashed #cbd5e1;border-radius:8px;margin:8px;padding:32px 16px;text-align:center;color:#94a3b8;font-size:13px;font-family:system-ui,sans-serif}
.yk-empty-hint-sm{margin:0;padding:12px 8px;font-size:12px}
.banner-swiper{height:min(52vw,520px)}
@media(max-width:767px){.banner-swiper{height:300px}}
</style>
<script>
(function () {
    var overlay = document.createElement('div');
    overlay.className = 'yk-pick-overlay';
    overlay.style.display = 'none';
    var label = document.createElement('div');
    label.className = 'yk-pick-label';
    label.style.display = 'none';
    document.body.appendChild(overlay);
    document.body.appendChild(label);
    var dropLine = document.createElement('div');
    dropLine.className = 'yk-drop-line';
    dropLine.style.display = 'none';
    document.body.appendChild(dropLine);
    var inlineEdit = null;
    var dropState = null;
    var dropSequence = 0;
    var columnResizeState = null;

    var editorOrigin = window.parent.location.origin;
    function postToEditor(message) {
        window.parent.postMessage(message, editorOrigin);
    }

    function pathParts(path) {
        var parts = String(path || '').split('.').map(function (n) { return parseInt(n, 10); });
        return parts.every(function (n) { return !isNaN(n); }) ? parts : [];
    }
    function sectionFieldParts(value) {
        var match = String(value || '').match(/^(\d+)\.(title|subtitle)$/);
        return match ? { si: parseInt(match[1], 10), field: match[2] } : null;
    }
    function homeFieldTarget(node) {
        if (!node) return null;
        var path = node.getAttribute('data-yk-home-path') || '';
        var field = node.getAttribute('data-yk-home-field') || '';
        var topLevel = [
            'override_title', 'override_content', 'override_image',
            'override_tag_title', 'override_tag_description', 'override_button_text'
        ].indexOf(field) !== -1;
        var nested = /^(?:stats_items\.[0-3]\.(?:icon|number|label)|advantage_items\.[0-3]\.(?:icon|title|description))$/.test(field);
        if (pathParts(path).length < 3 || (!topLevel && !nested)) return null;
        return { path: path, field: field };
    }
    function homeColumnTarget(node) {
        if (!node) return null;
        var path = node.getAttribute('data-yk-home-path') || '';
        var column = node.getAttribute('data-yk-home-column') || '';
        if (pathParts(path).length < 3 || ['text', 'image'].indexOf(column) === -1) return null;
        return {
            path: path,
            column: column,
            label: node.getAttribute('data-yk-home-column-label') || column
        };
    }
    function clampColumnSpan(value, min, max) {
        return Math.min(max, Math.max(min, Math.round(value)));
    }
    function physicalColumnSpan(grid, clientX, min, max) {
        var rect = grid.getBoundingClientRect();
        if (!rect.width) return 6;
        return clampColumnSpan(((clientX - rect.left) / rect.width) * 12, min, max);
    }
    function orderedColumns(columns) {
        return columns.slice().sort(function (a, b) {
            return a.offsetLeft - b.offsetLeft;
        });
    }
    function columnSpans(columns) {
        var spans = columns.map(function (column) {
            return parseInt(column.getAttribute('data-yk-col-span') || '0', 10);
        });
        var total = spans.reduce(function (sum, span) { return sum + span; }, 0);
        if (spans.some(function (span) { return span < 1; }) || total > 12) {
            var base = Math.floor(12 / columns.length);
            var remainder = 12 % columns.length;
            spans = columns.map(function (_column, index) { return base + (index < remainder ? 1 : 0); });
        }
        return spans;
    }
    function syncColumnResizer(handle) {
        var grid = handle.parentElement;
        var leftColumn = handle._ykLeftColumn;
        var rightColumn = handle._ykRightColumn;
        if (!grid || !leftColumn || !rightColumn) return;
        var gridWidth = grid.clientWidth;
        var leftEnd = leftColumn.offsetLeft + leftColumn.offsetWidth;
        var rightStart = rightColumn.offsetLeft;
        if (!gridWidth || leftEnd > rightStart) {
            handle.style.display = 'none';
            return;
        }
        handle.style.display = '';
        handle.style.left = (((leftEnd + rightStart) / 2) / gridWidth * 100) + '%';
        handle.style.top = Math.min(leftColumn.offsetTop, rightColumn.offsetTop) + 'px';
        handle.style.height = Math.max(
            leftColumn.offsetTop + leftColumn.offsetHeight,
            rightColumn.offsetTop + rightColumn.offsetHeight
        ) - Math.min(leftColumn.offsetTop, rightColumn.offsetTop) + 'px';
    }
    function syncColumnResizers(grid) {
        grid.querySelectorAll(':scope > .yk-column-resizer').forEach(syncColumnResizer);
    }
    function restoreColumnResize(state) {
        if (state.gridStyle) state.grid.setAttribute('style', state.gridStyle); else state.grid.removeAttribute('style');
        state.columns.forEach(function (column, index) {
            if (state.columnStyles[index]) column.setAttribute('style', state.columnStyles[index]); else column.removeAttribute('style');
        });
        state.handle.classList.remove('yk-resizing');
        document.body.classList.remove('yk-column-resizing');
        syncColumnResizers(state.grid);
    }
    function previewColumnResize(state, physicalSpan) {
        var ordered = orderedColumns(state.columns);
        if (state.kind === 'home') {
            state.grid.style.gridTemplateColumns = physicalSpan + 'fr ' + (12 - physicalSpan) + 'fr';
            state.physicalSpan = physicalSpan;
        } else {
            var pairTotal = state.spans[state.dividerIndex] + state.spans[state.dividerIndex + 1];
            var leftSpan = clampColumnSpan(physicalSpan, state.min, pairTotal - state.min);
            state.spans[state.dividerIndex] = leftSpan;
            state.spans[state.dividerIndex + 1] = pairTotal - leftSpan;
            state.grid.style.gridTemplateColumns = state.spans.map(function (span) { return span + 'fr'; }).join(' ');
        }
        ordered.forEach(function (column) { column.style.gridColumn = 'auto'; });
        syncColumnResizers(state.grid);
    }
    function standardDividerSpan(state, clientX) {
        var rect = state.grid.getBoundingClientRect();
        var prefix = state.spans.slice(0, state.dividerIndex).reduce(function (sum, span) { return sum + span; }, 0);
        var pairTotal = state.spans[state.dividerIndex] + state.spans[state.dividerIndex + 1];
        var boundary = rect.width ? Math.round(((clientX - rect.left) / rect.width) * 12) : prefix + state.spans[state.dividerIndex];
        return clampColumnSpan(boundary - prefix, state.min, pairTotal - state.min);
    }
    function commitColumnResize(state) {
        if (state.kind === 'home') {
            var textColumn = state.columns.find(function (column) { return column.getAttribute('data-yk-home-column') === 'text'; });
            var textOnLeft = textColumn === orderedColumns(state.columns)[0];
            var textSpan = textOnLeft ? state.physicalSpan : 12 - state.physicalSpan;
            postToEditor({ ykColumnRatio: {
                kind: 'home', path: state.path, index: clampColumnSpan(textSpan, 4, 8) - 4
            } });
            return;
        }
        postToEditor({ ykColumnRatio: {
            kind: 'section', si: state.si, spans: state.spans.slice()
        } });
    }
    function installColumnResizer(grid, columns, config) {
        var dividerIndex = parseInt(config.dividerIndex || 0, 10);
        var ordered = orderedColumns(columns);
        if (!grid || ordered.length < 2 || dividerIndex < 0 || dividerIndex >= ordered.length - 1) return;
        if (grid.querySelector(':scope > .yk-column-resizer[data-yk-divider="' + dividerIndex + '"]')) return;
        var handle = document.createElement('div');
        handle.className = 'yk-column-resizer';
        handle.setAttribute('data-yk-divider', String(dividerIndex));
        handle.setAttribute('role', 'separator');
        handle.setAttribute('tabindex', '0');
        handle.setAttribute('aria-orientation', 'vertical');
        handle.setAttribute('aria-label', __YK_COLUMN_RESIZE_LABEL__ + ' ' + (dividerIndex + 1));
        handle.setAttribute('title', __YK_COLUMN_RESIZE_HINT__);
        handle.innerHTML = '<span aria-hidden="true"></span>';
        handle._ykColumns = ordered;
        handle._ykLeftColumn = ordered[dividerIndex];
        handle._ykRightColumn = ordered[dividerIndex + 1];
        handle._ykConfig = config;
        grid.classList.add('yk-column-resize-host');
        grid.appendChild(handle);
        syncColumnResizer(handle);
        handle.addEventListener('pointerdown', function (e) {
            if (e.button !== 0 || window.innerWidth < 1024) return;
            e.preventDefault();
            e.stopPropagation();
            var spans = config.kind === 'home' ? [6, 6] : columnSpans(ordered);
            var min = config.kind === 'home' ? 4 : 2;
            var pairTotal = spans[dividerIndex] + spans[dividerIndex + 1];
            if (pairTotal < min * 2) return;
            columnResizeState = {
                kind: config.kind, path: config.path || '', si: config.si,
                dividerIndex: dividerIndex, spans: spans,
                grid: grid, columns: ordered, handle: handle,
                gridStyle: grid.getAttribute('style') || '',
                columnStyles: ordered.map(function (column) { return column.getAttribute('style') || ''; }),
                min: min, max: config.kind === 'home' ? 8 : pairTotal - min
            };
            var initialSpan = config.kind === 'home'
                ? physicalColumnSpan(grid, e.clientX, 4, 8)
                : standardDividerSpan(columnResizeState, e.clientX);
            handle.setPointerCapture(e.pointerId);
            handle.classList.add('yk-resizing');
            document.body.classList.add('yk-column-resizing');
            previewColumnResize(columnResizeState, initialSpan);
        });
        handle.addEventListener('pointermove', function (e) {
            if (!columnResizeState || columnResizeState.handle !== handle) return;
            var nextSpan = columnResizeState.kind === 'home'
                ? physicalColumnSpan(grid, e.clientX, 4, 8)
                : standardDividerSpan(columnResizeState, e.clientX);
            previewColumnResize(columnResizeState, nextSpan);
        });
        function finishResize(e, commit) {
            if (!columnResizeState || columnResizeState.handle !== handle) return;
            var state = columnResizeState;
            columnResizeState = null;
            if (handle.hasPointerCapture(e.pointerId)) handle.releasePointerCapture(e.pointerId);
            restoreColumnResize(state);
            if (commit) commitColumnResize(state);
        }
        handle.addEventListener('pointerup', function (e) { finishResize(e, true); });
        handle.addEventListener('pointercancel', function (e) { finishResize(e, false); });
        handle.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); });
        handle.addEventListener('keydown', function (e) {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            e.preventDefault();
            var spans = config.kind === 'home' ? [6, 6] : columnSpans(ordered);
            if (config.kind === 'home') {
                var widths = ordered.map(function (column) { return column.offsetWidth; });
                var current = clampColumnSpan((widths[0] / Math.max(1, widths[0] + widths[1])) * 12, 4, 8);
                commitColumnResize({
                    kind: config.kind, path: config.path || '', columns: ordered,
                    physicalSpan: clampColumnSpan(current + (e.key === 'ArrowRight' ? 1 : -1), 4, 8)
                });
                return;
            }
            var pairTotal = spans[dividerIndex] + spans[dividerIndex + 1];
            var nextLeft = clampColumnSpan(
                spans[dividerIndex] + (e.key === 'ArrowRight' ? 1 : -1),
                2,
                pairTotal - 2
            );
            if (nextLeft === spans[dividerIndex]) return;
            spans[dividerIndex] = nextLeft;
            spans[dividerIndex + 1] = pairTotal - nextLeft;
            commitColumnResize({
                kind: 'section', si: config.si, dividerIndex: dividerIndex,
                columns: ordered, spans: spans
            });
        });
    }
    function setupColumnResizers() {
        document.querySelectorAll('[data-yk-con]').forEach(function (container) {
            var si = parseInt(container.getAttribute('data-yk-con'), 10);
            var columns = Array.from(container.querySelectorAll('[data-yk-col]')).filter(function (column) {
                return column.parentElement && column.getAttribute('data-yk-col').indexOf(si + '.') === 0;
            });
            if (columns.length >= 2 && columns.every(function (column) { return column.parentElement === columns[0].parentElement; })) {
                for (var dividerIndex = 0; dividerIndex < columns.length - 1; dividerIndex++) {
                    installColumnResizer(columns[0].parentElement, columns, {
                        kind: 'section', si: si, dividerIndex: dividerIndex
                    });
                }
            }
        });
        document.querySelectorAll('[data-yk-home-columns="2"]').forEach(function (grid) {
            var columns = Array.from(grid.querySelectorAll('[data-yk-home-column]')).filter(function (column) { return column.parentElement === grid; });
            var path = columns[0] ? (columns[0].getAttribute('data-yk-home-path') || '') : '';
            if (columns.length === 2 && path) installColumnResizer(grid, columns, {
                kind: 'home', path: path, dividerIndex: 0
            });
        });
    }    function inlineValue(node, format) {
        if (format === 'plain') {
            return String(node.innerText || '').replace(/\r/g, '')
                .replace(/[ \t]+\n/g, '\n').replace(/\n[ \t]+/g, '\n').trim();
        }
        return String(node.textContent || '').replace(/\s+/g, ' ').trim();
    }
    function isPlainTextBody(node) {
        if (!node) return false;
        var descendants = node.querySelectorAll('*');
        for (var i = 0; i < descendants.length; i++) {
            if (descendants[i].tagName !== 'P' && descendants[i].tagName !== 'BR') return false;
        }
        return true;
    }
    function inlineElementTarget(wrapper) {
        var type = wrapper ? (wrapper.getAttribute('data-yk-el-type') || '') : '';
        if (type === 'heading') {
            var heading = wrapper.querySelector('h1,h2,h3,h4');
            return heading ? { node: heading, field: 'text', format: 'text', singleLine: true } : null;
        }
        if (type === 'text') {
            var body = wrapper.firstElementChild;
            return isPlainTextBody(body)
                ? { node: body, field: 'html', format: 'plain', singleLine: false }
                : null;
        }
        if (type === 'button') {
            var button = wrapper.querySelector('a');
            return button ? { node: button, field: 'text', format: 'text', singleLine: true } : null;
        }
        return null;
    }
    function restoreInlineLabel(payload) {
        if (payload.kind === 'sectionField') {
            label.textContent = payload.field === 'subtitle' ? '副标题' : '区块标题';
        } else if (payload.kind === 'homeField') {
            label.textContent = '首页字段';
        } else {
            label.textContent = 'Element ' + payload.path;
        }
    }
    function finishInlineEdit(save) {
        var state = inlineEdit;
        if (!state) return;
        inlineEdit = null;
        state.node.removeEventListener('keydown', state.onKeydown);
        state.node.removeEventListener('blur', state.onBlur);
        state.node.removeAttribute('contenteditable');
        state.node.removeAttribute('spellcheck');
        state.node.classList.remove('yk-inline-editing');
        if (!save) state.node.innerHTML = state.originalHtml;
        var value = inlineValue(state.node, state.payload.format);
        restoreInlineLabel(state.payload);
        syncOverlay();
        if (save && value !== state.originalValue) {
            var message = {};
            Object.keys(state.payload).forEach(function (key) { message[key] = state.payload[key]; });
            message.value = value;
            postToEditor({ ykInlineEdit: message });
        }
    }
    function beginInlineEdit(node, payload, singleLine) {
        if (!node) return false;
        if (inlineEdit && inlineEdit.node === node) return true;
        if (inlineEdit) finishInlineEdit(true);
        var state = {
            node: node,
            payload: payload,
            originalHtml: node.innerHTML,
            originalValue: inlineValue(node, payload.format)
        };
        state.onKeydown = function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                finishInlineEdit(false);
                return;
            }
            if (e.key === 'Enter' && (singleLine || e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                e.stopPropagation();
                finishInlineEdit(true);
            }
        };
        state.onBlur = function () {
            setTimeout(function () {
                if (inlineEdit === state) finishInlineEdit(true);
            }, 0);
        };
        inlineEdit = state;
        node.setAttribute('contenteditable', singleLine ? 'plaintext-only' : 'true');
        node.setAttribute('spellcheck', 'true');
        node.classList.add('yk-inline-editing');
        activeEl = node;
        label.textContent = '文字编辑';
        syncOverlay();
        startOverlayTracking();
        node.addEventListener('keydown', state.onKeydown);
        node.addEventListener('blur', state.onBlur);
        node.focus();
        return true;
    }
    function contextTargetFromEvent(e) {
        var emptyHint = e.target.closest('.yk-empty-hint');
        var emptySection = emptyHint ? emptyHint.closest('[data-yk-sec]') : null;
        if (emptySection) {
            var emptySi = parseInt(emptySection.getAttribute('data-yk-sec'), 10);
            if (!isNaN(emptySi)) return { kind: 'section', target: { si: emptySi } };
        }
        var field = e.target.closest('[data-yk-sec-field]');
        if (field) {
            var fieldTarget = sectionFieldParts(field.getAttribute('data-yk-sec-field'));
            if (fieldTarget) return { kind: 'sectionField', target: fieldTarget };
        }

        var el = e.target.closest('[data-yk-el]');
        if (el) {
            var path = el.getAttribute('data-yk-el') || '';
            var parts = pathParts(path);
            if (parts.length >= 4) return { kind: 'child', target: { si: parts[0], ci: parts[1], ei: parts[2], cei: parts[3] }, path: path };
            if (parts.length >= 3) return { kind: 'element', target: { si: parts[0], ci: parts[1], ei: parts[2] }, path: path };
        }
        var col = e.target.closest('[data-yk-col]');
        if (col) {
            var cp = pathParts(col.getAttribute('data-yk-col') || '');
            if (cp.length >= 2) return { kind: 'column', target: { si: cp[0], ci: cp[1] }, col: col.getAttribute('data-yk-col') || '' };
        }
        var con = e.target.closest('[data-yk-con]');
        if (con) {
            var csi = parseInt(con.getAttribute('data-yk-con'), 10);
            if (!isNaN(csi)) return { kind: 'container', target: { si: csi } };
        }
        var sec = e.target.closest('[data-yk-sec]');
        if (sec) {
            var si = parseInt(sec.getAttribute('data-yk-sec'), 10);
            if (!isNaN(si)) return { kind: 'section', target: { si: si } };
        }
        return null;
    }

    document.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        var field = e.target.closest('[data-yk-sec-field]');
        if (!field) return;
        var fieldTarget = sectionFieldParts(field.getAttribute('data-yk-sec-field'));
        if (!fieldTarget) return;
        highlightSectionField(fieldTarget.si, fieldTarget.field);
        postToEditor({ ykPickSectionField: fieldTarget });
    }, true);

    document.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (a) e.preventDefault(); // 画布内不跳转，链接编辑去设置面板
        var homeField = e.target.closest('[data-yk-home-field]');
        var homeTarget = homeFieldTarget(homeField);
        if (homeTarget) {
            highlightHomeField(homeTarget.path, homeTarget.field);
            postToEditor({ ykPickHomeField: homeTarget });
            return;
        }
        var homeColumn = e.target.closest('[data-yk-home-column]');
        var columnTarget = homeColumnTarget(homeColumn);
        if (columnTarget) {
            highlightHomeColumn(columnTarget.path, columnTarget.column);
            postToEditor({ ykPickHomeColumn: columnTarget });
            return;
        }
        var field = e.target.closest('[data-yk-sec-field]');
        if (field) {
            var fieldTarget = sectionFieldParts(field.getAttribute('data-yk-sec-field'));
            if (fieldTarget) {
                highlightSectionField(fieldTarget.si, fieldTarget.field);
                postToEditor({ ykPickSectionField: fieldTarget });
                return;
            }
        }
        var el = e.target.closest('[data-yk-el]');
        if (el) {
            var path = el.getAttribute('data-yk-el') || '';
            highlightEl(path);
            postToEditor({ ykPickEl: path });
            return;
        }
        var col = e.target.closest('[data-yk-col]');
        if (col) {
            var cp = col.getAttribute('data-yk-col') || '';
            highlightColumn(cp);
            postToEditor({ ykPickCol: cp });
            return;
        }
        var con = e.target.closest('[data-yk-con]');
        if (con) {
            var ci = parseInt(con.getAttribute('data-yk-con'), 10);
            highlightContainer(ci);
            postToEditor({ ykPickCon: ci });
            return;
        }
        var s = e.target.closest('[data-yk-sec]');
        if (!s) return;
        var i = parseInt(s.getAttribute('data-yk-sec'), 10);
        highlightSection(i);
        postToEditor({ ykPick: i });
    }, true);

    document.addEventListener('contextmenu', function (e) {
        var hit = contextTargetFromEvent(e);
        if (!hit) hit = { kind: 'canvas', target: {} };
        e.preventDefault();
        if (hit.path) highlightEl(hit.path);
        else if (hit.col) highlightColumn(hit.col);
        else if (hit.kind === 'container') highlightContainer(hit.target.si);
        else if (hit.kind === 'sectionField') highlightSectionField(hit.target.si, hit.target.field);
        else if (hit.kind === 'section') highlightSection(hit.target.si);
        postToEditor({ ykContext: { kind: hit.kind, target: hit.target, x: e.clientX, y: e.clientY } });
    }, true);

    document.addEventListener('dblclick', function (e) {
        var homeField = e.target.closest('[data-yk-home-field]');
        var homeTarget = homeFieldTarget(homeField);
        if (homeTarget) {
            highlightHomeField(homeTarget.path, homeTarget.field);
            if (homeTarget.field !== 'override_image' && !homeTarget.field.endsWith('.icon') && beginInlineEdit(homeField, {
                kind: 'homeField', path: homeTarget.path, field: homeTarget.field, format: 'text'
            }, homeTarget.field !== 'override_content')) {
                e.stopPropagation();
                return;
            }
            postToEditor({ ykPickHomeField: homeTarget });
            return;
        }
        var homeColumn = e.target.closest('[data-yk-home-column]');
        var columnTarget = homeColumnTarget(homeColumn);
        if (columnTarget) {
            e.preventDefault();
            e.stopPropagation();
            highlightHomeColumn(columnTarget.path, columnTarget.column);
            postToEditor({ ykPickHomeColumn: columnTarget });
            return;
        }
        var field = e.target.closest('[data-yk-sec-field]');
        if (field) {
            var fieldTarget = sectionFieldParts(field.getAttribute('data-yk-sec-field'));
            if (fieldTarget) {
                highlightSectionField(fieldTarget.si, fieldTarget.field);
                if (beginInlineEdit(field, {
                    kind: 'sectionField', si: fieldTarget.si, field: fieldTarget.field, format: 'text'
                }, true)) {
                    e.stopPropagation();
                    return;
                }
                postToEditor({ ykEditSectionField: fieldTarget });
                return;
            }
        }
        var el = e.target.closest('[data-yk-el]');
        if (!el) return;
        var path = el.getAttribute('data-yk-el') || '';
        if (!pathParts(path).length) return;
        highlightEl(path);
        var editable = inlineElementTarget(el);
        if (editable && beginInlineEdit(editable.node, {
            kind: 'element', path: path, field: editable.field, format: editable.format
        }, editable.singleLine)) {
            e.stopPropagation();
            return;
        }
        e.preventDefault();
        postToEditor({ ykEditEl: path });
    }, true);

    var ykDragRules = null;   // {containers:{type:[childTypes]}, isContainer:{type:bool}, generic:{type:bool}}
    var ykDragType = '';      // 编辑器 palette dragstart 广播的当前拖拽类型（dragend 清空）
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        var shouldScroll = d.ykScroll === true;
        if (d.ykDragRules && typeof d.ykDragRules === 'object') { ykDragRules = d.ykDragRules; return; }
        if ('ykDragType' in d) { ykDragType = typeof d.ykDragType === 'string' ? d.ykDragType : ''; return; }
        if (Number.isInteger(d.ykBannerSlide)) {
            var bannerNode = document.querySelector('.banner-swiper');
            var bannerSwiper = bannerNode ? (bannerNode._ykSwiper || bannerNode.swiper) : null;
            if (bannerSwiper && typeof bannerSwiper.slideTo === 'function') {
                bannerSwiper.slideTo(Math.max(0, d.ykBannerSlide), 0);
            }
        }
        if (d.ykHighlightHomeField && typeof d.ykHighlightHomeField.path === 'string') {
            highlightHomeField(d.ykHighlightHomeField.path, d.ykHighlightHomeField.field || '');
            var homeTarget = document.querySelector(
                '[data-yk-home-path="' + cssEscape(d.ykHighlightHomeField.path) + '"]' +
                '[data-yk-home-field="' + cssEscape(d.ykHighlightHomeField.field || '') + '"]'
            );
            if (shouldScroll && homeTarget) homeTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (d.ykHighlightHomeColumn && typeof d.ykHighlightHomeColumn.path === 'string') {
            highlightHomeColumn(d.ykHighlightHomeColumn.path, d.ykHighlightHomeColumn.column || '');
            var homeColumnNode = document.querySelector(
                '[data-yk-home-path="' + cssEscape(d.ykHighlightHomeColumn.path) + '"]' +
                '[data-yk-home-column="' + cssEscape(d.ykHighlightHomeColumn.column || '') + '"]'
            );
            if (shouldScroll && homeColumnNode) homeColumnNode.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (d.ykHighlightSectionField && typeof d.ykHighlightSectionField.si === 'number') {
            highlightSectionField(d.ykHighlightSectionField.si, d.ykHighlightSectionField.field || 'title');
            var fieldTarget = document.querySelector('[data-yk-sec-field="' + d.ykHighlightSectionField.si + '.' + cssEscape(d.ykHighlightSectionField.field || 'title') + '"]');
            if (shouldScroll && fieldTarget) fieldTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (typeof d.ykHighlightEl === 'string') {
            highlightEl(d.ykHighlightEl);
            if (shouldScroll) scrollToPath(d.ykHighlightEl);
            return;
        }
        if (typeof d.ykHighlightCon === 'number') {
            highlightContainer(d.ykHighlightCon);
            var c = document.querySelector('[data-yk-con="' + d.ykHighlightCon + '"]');
            if (shouldScroll && c) c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (typeof d.ykHighlightCol === 'string') {
            highlightColumn(d.ykHighlightCol);
            var col = document.querySelector('[data-yk-col="' + cssEscape(d.ykHighlightCol) + '"]');
            if (shouldScroll && col) col.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return;
        }
        if (typeof d.ykHighlight === 'number') {
            highlightSection(d.ykHighlight);
            var t = document.querySelector('[data-yk-sec="' + d.ykHighlight + '"]');
            if (shouldScroll && t) t.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });

    window.addEventListener('scroll', syncOverlay, true);
    document.addEventListener('scroll', syncOverlay, true);
    window.addEventListener('resize', syncOverlay);
    if (window.visualViewport) window.visualViewport.addEventListener('resize', syncOverlay);

    var activeEl = null;
    var overlayRaf = 0;
    function rectFor(node) {
        if (!node) return null;
        var rects = Array.prototype.slice.call(node.getClientRects ? node.getClientRects() : []);
        rects = rects.filter(function (r) { return r.width > 0 && r.height > 0; });
        if (rects.length) return rects[0];
        var child = node.firstElementChild;
        return child ? rectFor(child) : null;
    }
    function viewportSize() {
        return {
            w: window.innerWidth || document.documentElement.clientWidth || 0,
            h: window.innerHeight || document.documentElement.clientHeight || 0
        };
    }
    function syncOverlay() {
        if (!activeEl) return;
        if (!document.documentElement.contains(activeEl)) { clearElementOverlay(); return; }
        var r = rectFor(activeEl);
        var vp = viewportSize();
        if (!r || r.bottom < 0 || r.right < 0 || r.top > vp.h || r.left > vp.w) {
            overlay.style.display = 'none';
            label.style.display = 'none';
            return;
        }
        overlay.style.display = 'block';
        overlay.style.left = (r.left - 2) + 'px';
        overlay.style.top = (r.top - 2) + 'px';
        overlay.style.width = Math.max(0, r.width + 4) + 'px';
        overlay.style.height = Math.max(0, r.height + 4) + 'px';
        label.style.display = 'block';
        label.style.left = Math.max(0, r.left) + 'px';
        label.style.top = Math.max(0, r.top - 24) + 'px';
    }
    function trackOverlay() {
        if (!activeEl) { overlayRaf = 0; return; }
        syncOverlay();
        overlayRaf = requestAnimationFrame(trackOverlay);
    }
    function startOverlayTracking() {
        if (!overlayRaf) overlayRaf = requestAnimationFrame(trackOverlay);
    }
    function clearElementOverlay() {
        activeEl = null;
        if (overlayRaf) cancelAnimationFrame(overlayRaf);
        overlayRaf = 0;
        overlay.style.display = 'none';
        label.style.display = 'none';
    }
    function clearLayerSelections() {
        document.querySelectorAll('[data-yk-sec].yk-selected').forEach(function (el) { el.classList.remove('yk-selected'); });
        document.querySelectorAll('[data-yk-con].yk-con-selected').forEach(function (el) { el.classList.remove('yk-con-selected'); });
        document.querySelectorAll('[data-yk-col].yk-col-selected').forEach(function (el) { el.classList.remove('yk-col-selected'); });
    }
    function highlightSection(i) {
        clearElementOverlay();
        clearLayerSelections();
        var t = document.querySelector('[data-yk-sec="' + i + '"]');
        if (t) t.classList.add('yk-selected');
    }
    function highlightContainer(i) {
        clearElementOverlay();
        clearLayerSelections();
        var t = document.querySelector('[data-yk-con="' + i + '"]');
        if (t) t.classList.add('yk-con-selected');
    }
    function highlightColumn(path) {
        clearElementOverlay();
        clearLayerSelections();
        var t = document.querySelector('[data-yk-col="' + cssEscape(path) + '"]');
        if (t) t.classList.add('yk-col-selected');
    }
    function highlightSectionField(si, field) {
        clearLayerSelections();
        activeEl = document.querySelector('[data-yk-sec-field="' + si + '.' + cssEscape(field) + '"]');
        label.textContent = field === 'subtitle' ? '副标题' : '区块标题';
        syncOverlay();
        startOverlayTracking();
    }
    function highlightHomeField(path, field) {
        clearLayerSelections();
        activeEl = document.querySelector(
            '[data-yk-home-path="' + cssEscape(path) + '"]' +
            '[data-yk-home-field="' + cssEscape(field) + '"]'
        );
        label.textContent = '首页字段';
        syncOverlay();
        startOverlayTracking();
    }
    function highlightHomeColumn(path, column) {
        clearLayerSelections();
        activeEl = document.querySelector(
            '[data-yk-home-path="' + cssEscape(path) + '"]' +
            '[data-yk-home-column="' + cssEscape(column) + '"]'
        );
        label.textContent = activeEl
            ? (activeEl.getAttribute('data-yk-home-column-label') || '列')
            : '列';
        syncOverlay();
        startOverlayTracking();
    }
    function highlightEl(path) {
        clearLayerSelections();
        activeEl = document.querySelector('[data-yk-el="' + cssEscape(path) + '"]');
        // Banner 子项由 Swiper 叠放。结构树选择某一项时先切到对应 slide，
        // 否则目标虽存在于 DOM，却仍隐藏在当前 slide 后面。
        var slide = activeEl && activeEl.closest ? activeEl.closest('.swiper-slide') : null;
        var swiper = slide && slide.closest('.swiper') ? slide.closest('.swiper').swiper : null;
        if (slide && swiper && slide.parentElement) {
            var slideIndex = Array.prototype.indexOf.call(slide.parentElement.children, slide);
            if (slideIndex >= 0) swiper.slideTo(slideIndex, 0);
        }
        label.textContent = 'Element ' + path;
        syncOverlay();
        startOverlayTracking();
    }
    function scrollToPath(path) {
        var t = document.querySelector('[data-yk-el="' + cssEscape(path) + '"]');
        if (!t) return;
        // data-yk-el wrapper uses display:contents, so scroll the rendered child box instead.
        var target = boxNode(t) || t;
        target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
    }
    function boxNode(node) {
        if (!node) return null;
        var r = node.getBoundingClientRect ? node.getBoundingClientRect() : null;
        if (r && r.width > 0 && r.height > 0) return node;
        for (var i = 0; i < node.children.length; i++) {
            var found = boxNode(node.children[i]);
            if (found) return found;
        }
        return null;
    }
    function cssEscape(v) {
        if (window.CSS && CSS.escape) return CSS.escape(v);
        return String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function hideDropLine() {
        dropState = null;
        dropLine.style.display = 'none';
    }
    function dataTransferType(e) {
        var transfer = e && e.dataTransfer;
        if (!transfer) return '';
        var raw = '';
        try { raw = transfer.getData('application/x-yikai-blox'); } catch (err) {}
        if (raw) {
            try {
                var payload = JSON.parse(raw);
                if (payload && payload.source === 'palette' && payload.version === 1 && payload.type) return String(payload.type);
            } catch (err2) {}
        }
        try { return transfer.getData('text/plain') || ''; } catch (err3) { return ''; }
    }
    function columnForTarget(section, target) {
        var col = target && target.closest ? target.closest('[data-yk-col]') : null;
        if (col && section.contains(col)) return col;
        var grid = section.querySelector(':scope > div > .grid');
        if (!grid) return null;
        var kids = Array.prototype.slice.call(grid.children);
        for (var i = 0; i < kids.length; i++) {
            if (kids[i] === target || kids[i].contains(target)) return kids[i];
        }
        return kids[0] || null;
    }
    function dropTargetFromEvent(e, section) {
        var el = e.target && e.target.closest ? e.target.closest('[data-yk-el]') : null;
        if (el && section.contains(el)) {
            var path = el.getAttribute('data-yk-el') || '';
            var parts = pathParts(path);
            var box = boxNode(el);
            if (parts.length >= 3 && box) {
                var rect = box.getBoundingClientRect();
                var computed = window.getComputedStyle(el);
                var horizontal = computed && computed.display === 'flex' && String(computed.flexDirection || '').indexOf('row') === 0;
                var before = horizontal
                    ? e.clientX < rect.left + rect.width / 2
                    : e.clientY < rect.top + rect.height / 2;
                return {
                    kind: 'element',
                    path: path,
                    position: before ? 'before' : 'after',
                    sec: parts[0],
                    col: parts[1]
                };
            }
        }
        var column = columnForTarget(section, e.target);
        if (!column) return null;
        var colPath = column.getAttribute('data-yk-col') || '';
        var colParts = pathParts(colPath);
        return {
            kind: 'column',
            sec: colParts.length >= 1 ? colParts[0] : parseInt(section.getAttribute('data-yk-sec'), 10),
            col: colParts.length >= 2 ? colParts[1] : 0,
            position: 'end'
        };
    }
    function showDropLine(target) {
        if (!target) { hideDropLine(); return; }
        var node = null;
        if (target.kind === 'element') node = document.querySelector('[data-yk-el="' + cssEscape(target.path) + '"]');
        else if (target.kind === 'column') node = document.querySelector('[data-yk-col="' + cssEscape(String(target.sec) + '.' + String(target.col)) + '"]');
        var box = boxNode(node);
        if (!box) { hideDropLine(); return; }
        var rect = box.getBoundingClientRect();
        var top = target.kind === 'element' && target.position === 'before' ? rect.top : rect.bottom;
        if (target.kind === 'column' && rect.height <= 56) top = rect.top + 12;
        dropLine.style.left = Math.max(0, Math.round(rect.left)) + 'px';
        dropLine.style.top = Math.max(0, Math.round(top - 1.5)) + 'px';
        dropLine.style.width = Math.max(36, Math.round(rect.width)) + 'px';
        dropLine.style.display = 'block';
        dropState = target;
    }

    // 拖放目标合法性：容器一层嵌套 + 容器 allowedChildren（如 stats-group 只收 stat-item）。
    // Chrome 在 dragover 期禁读 dataTransfer.getData，类型由编辑器 dragstart 经 postMessage 广播。
    function dropTargetValid(target) {
        if (!ykDragRules || !ykDragType) return true; // 规则未下发时不拦（编辑器端仍会校验）
        var draggedIsContainer = !!(ykDragRules.isContainer || {})[ykDragType];
        if (target.kind === 'element') {
            var parts = pathParts(target.path);
            if (parts.length >= 4) { // 目标在容器内：插入的是该容器的子元素
                var parentNode = document.querySelector('[data-yk-el="' + parts.slice(0, 3).join('.') + '"]');
                var parentType = parentNode ? (parentNode.getAttribute('data-yk-el-type') || '') : '';
                var allowed = (ykDragRules.containers || {})[parentType];
                if (Array.isArray(allowed)) {
                    if (allowed.indexOf(ykDragType) !== -1) return true;
                    return allowed.indexOf('*') !== -1 && !draggedIsContainer && (ykDragRules.generic || {})[ykDragType] !== false;
                }
                return !draggedIsContainer;
            }
        }
        return true; // 列级/顶级元素前后：任何元素均可
    }

    // Palette tiles use a versioned payload. The target is either a column end or an element before/after position.
    document.addEventListener('dragover', function (e) {
        var s = e.target.closest('[data-yk-sec]');
        if (!s || !dataTransferType(e)) return;
        e.preventDefault();
        var target = dropTargetFromEvent(e, s);
        if (!target) return;
        var valid = dropTargetValid(target);
        e.dataTransfer.dropEffect = valid ? 'copy' : 'none';
        if (target.kind === 'element') highlightEl(target.path);
        else highlightColumn(String(target.sec) + '.' + String(target.col));
        showDropLine(target);
        dropLine.classList.toggle('yk-drop-invalid', !valid);
    });
    document.addEventListener('drop', function (e) {
        var s = e.target.closest('[data-yk-sec]');
        if (!s || !dataTransferType(e)) return;
        e.preventDefault();
        var type = dataTransferType(e);
        if (!type) return;
        var target = dropTargetFromEvent(e, s) || dropState;
        hideDropLine();
        dropLine.classList.remove('yk-drop-invalid');
        if (!target || !dropTargetValid(target)) return;
        postToEditor({ ykDrop: {
            version: 1,
            source: 'palette',
            dropId: 'drop_' + Date.now() + '_' + (++dropSequence),
            sec: parseInt(s.getAttribute('data-yk-sec'), 10),
            col: parseInt(target.col, 10) || 0,
            type: type,
            target: target
        } });
    });
    document.addEventListener('dragend', hideDropLine, true);
    document.addEventListener('dragleave', function (e) {
        if (e.target === document.documentElement || e.target === document.body) hideDropLine();
    }, true);
    var animationObserver = null;
    function contentNodes(root, selector) {
        var nodes = [];
        if (root && root.matches && root.matches(selector)) nodes.push(root);
        if (root && root.querySelectorAll) {
            root.querySelectorAll(selector).forEach(function (node) { nodes.push(node); });
        }
        return nodes;
    }
    function setupPreviewSwipers(root) {
        if (typeof window.Swiper !== 'function') return;
        contentNodes(root, '.banner-swiper').forEach(function (node) {
            if (node._ykSwiper || node.swiper) return;
            node._ykSwiper = new window.Swiper(node, {
                loop: false, rewind: true, autoplay: false, effect: 'fade',
                fadeEffect: { crossFade: true },
                pagination: { el: node.querySelector('.swiper-pagination'), clickable: true },
                navigation: { nextEl: node.querySelector('.swiper-button-next'), prevEl: node.querySelector('.swiper-button-prev') }
            });
        });
    }
    function setupAnimations(root) {
        var animatedNodes = contentNodes(root, '[data-animate], [data-stagger]');
        if (!animatedNodes.length) return;
        var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || !('IntersectionObserver' in window)) {
            animatedNodes.forEach(function (node) { node.classList.add('animated'); });
            return;
        }
        if (!animationObserver) {
            animationObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('animated');
                    animationObserver.unobserve(entry.target);
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -24px 0px' });
        }
        animatedNodes.forEach(function (node) {
            if (!node.classList.contains('animated')) animationObserver.observe(node);
        });
    }
    function setupEmptyHints(root) {
        // 先标空容器（白底空 flex 在画布上不可见），再标空区块；
        // 含容器的区块不算「空区块」——它的空态由容器占位表达
        contentNodes(root, '.yk-container, .yk-div').forEach(function (c) {
            if ((c.innerText || '').trim() !== '') return;
            if (c.querySelector('img,svg,iframe,video,picture')) return;
            var d = document.createElement('div');
            d.className = 'yk-empty-hint yk-empty-hint-sm';
            d.textContent = c.classList.contains('yk-div') ? '空 Div —— 在结构树选中它，再从「＋ 元素」添加子元素' : '空容器 —— 在结构树选中它，再从「＋ 元素」添加子元素';
            c.appendChild(d);
        });
        contentNodes(root, '[data-yk-sec]').forEach(function (sec) {
            if (sec.querySelector('.yk-container, .yk-div')) return;
            if ((sec.innerText || '').trim() !== '') return;
            if (sec.querySelector('img,svg,iframe,video,picture')) return;
            var n = parseInt(sec.getAttribute('data-yk-sec'), 10) + 1;
            var d = document.createElement('div');
            d.className = 'yk-empty-hint';
            d.textContent = '空区块 ' + n + ' —— 点选后从左侧「元素库」添加内容';
            sec.appendChild(d);
        });
    }
    function setupCanvasContent(root) {
        setupColumnResizers();
        setupPreviewSwipers(root);
        setupAnimations(root);
        setupEmptyHints(root);
    }
    setupCanvasContent(document);
    document.addEventListener('blox:content-updated', function (event) {
        setupCanvasContent(event.detail && event.detail.root ? event.detail.root : document);
    });
    window.addEventListener('resize', function () {
        document.querySelectorAll('.yk-column-resizer').forEach(syncColumnResizer);
    });
})();
</script>
HTML;
        $bloxInject = strtr($bloxInject, [
            '__YK_COLUMN_RESIZE_LABEL__' => json_encode(__('blox_canvas_column_resize'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '__YK_COLUMN_RESIZE_HINT__' => json_encode(__('blox_canvas_column_resize_hint'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $previewStyles = BloxAssetCollector::renderStyles();
    $previewScripts = BloxAssetCollector::renderScripts();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . htmlspecialchars(siteLang()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<link rel="stylesheet" href="' . assetVer('/assets/css/tailwind.css') . '">'
        . '<link rel="stylesheet" href="' . assetVer('/assets/css/style.css') . '">'
        . '<link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">'
        . '<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">'
        . '<base target="_blank">'
        . $previewStyles
        . '<style>body{margin:0;background:#fff}</style></head><body>'
        . $body
        . '<script src="/assets/swiper/swiper-bundle.min.js"></script>'
        . '<script>document.querySelectorAll(".banner-swiper").forEach(function(node){if(node._ykSwiper||node.swiper)return;node._ykSwiper=new Swiper(node,{loop:false,rewind:true,autoplay:false,effect:"fade",fadeEffect:{crossFade:true},pagination:{el:node.querySelector(".swiper-pagination"),clickable:true},navigation:{nextEl:node.querySelector(".swiper-button-next"),prevEl:node.querySelector(".swiper-button-prev")}});});</script>'
        . $previewScripts
        . $bloxInject
        . '</body></html>';
    exit;
}

// 可复用块库（P2）：保存/列表/取块/删除。表缺失（未跑升级）时容错提示。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string) ($_POST['action'] ?? ''), 'lib_')) {
    $libAction = $_POST['action'];
    try {
        if ($libAction === 'lib_save') {
            $libName = trim(post('lib_name'));
            $libSection = json_decode($_POST['section_data'] ?? '', true);
            if ($libName === '' || !is_array($libSection)) {
                error('参数不完整');
            }
            if (!empty($libSection['library_id'])) {
                error('引用块请先转为副本再存入块库');
            }
            unset($libSection['library_name']);
            $now = time();
            $libId = db()->insert('blocks_library', [
                'name' => mb_substr($libName, 0, 100),
                'data' => json_encode($libSection, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            adminLog('page', 'edit', '保存可复用块：' . $libName);
            success(['id' => (int) $libId]);
        }
        if ($libAction === 'lib_list') {
            $rows = db()->fetchAll(
                'SELECT id, name, updated_at FROM ' . DB_PREFIX . 'blocks_library ORDER BY id DESC'
            );
            success(['items' => $rows]);
        }
        if ($libAction === 'lib_get') {
            $row = db()->fetchOne(
                'SELECT id, name, data FROM ' . DB_PREFIX . 'blocks_library WHERE id = ?',
                [(int) ($_POST['lib_id'] ?? 0)]
            );
            if (!$row) {
                error('块不存在（可能已被删除）');
            }
            success(['item' => $row]);
        }
        if ($libAction === 'lib_delete') {
            $libId = (int) ($_POST['lib_id'] ?? 0);
            db()->delete('blocks_library', 'id = ?', [$libId]);
            adminLog('page', 'delete', '删除可复用块 #' . $libId);
            success();
        }
    } catch (\Throwable $e) {
        error('块库不可用，请先在「系统 → 数据库升级」执行升级');
    }
    error('未知操作');
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isHomeLayout) {
        // 首页发布 / 回退。排版编辑器是免费能力，因此这条链路自成闭环，
        // 不依赖需要授权的 Blox 编辑器；权限已在页首收紧为 *。

        $homeAction = (string) ($_POST['home_action'] ?? '');
        if ($homeAction === 'publish' || $homeAction === 'rollback') {
            try {
                $result = $homeAction === 'publish'
                    ? HomeLayoutDocument::publishDraft()
                    : HomeLayoutDocument::rollbackToLegacy();
                adminLog('home', $homeAction, $homeAction === 'publish' ? 'publish home layout' : 'rollback to legacy home');
                success($result);
            } catch (Throwable $e) {
                error($e->getMessage());
            }
        }

        // 基线比对：打开本页后若别处（Blox 编辑器/另一个标签页）保存过同一份首页草稿，
        // 直接写入会把对方的改动整份吞掉。此时拒绝保存并提示重载，不做自动合并。
        $postedBase = (int) ($_POST['home_base_updated_at'] ?? 0);
        $currentBase = (int) (HomeLayoutDocument::load()['updated_at'] ?? 0);
        if ($postedBase > 0 && $currentBase > 0 && $postedBase !== $currentBase) {
            error(__('home_layout_conflict'));
        }

        try {
            $saved = HomeLayoutDocument::saveDraft((string) ($_POST['blocks_data'] ?? '[]'));
            adminLog('home', 'edit', 'save homepage layout draft');
            success(['home_base_updated_at' => (int) ($saved['updated_at'] ?? 0)]);
        } catch (Throwable $e) {
            error($e->getMessage());
        }
    }

    $slug = resolveSlug(post('slug'), post('name'), 'channels', $id);

    $postBlocksData = (string) ($_POST['blocks_data'] ?? '[]');
    try {
        $processedDocument = BloxDocumentPipeline::process($postBlocksData, 'page');
    } catch (RuntimeException $e) {
        error($e->getMessage());
    }
    $postBlocksData = $processedDocument['json'];
    $renderedHtml = renderBlocksToHtml($postBlocksData);

    $channelData = [
        'name' => post('name'),
        'slug' => $slug,
        'description' => post('description'),
        'content' => $renderedHtml,
        'image' => post('image'),
        'seo_title' => post('seo_title'),
        'seo_keywords' => post('seo_keywords'),
        'seo_description' => post('seo_description'),
        'updated_at' => time(),
    ];

    // 保存即存档：覆盖前把旧版本快照下来（channels + 同步的 contents 行，含 blocks_data）
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
            'content_type' => $contentRecord['content_type'] ?? 'blocks',
            'blocks_data'  => $contentRecord['blocks_data'] ?? null,
        ]];
    }
    recordContentRevision('page', $id, (string) ($page['lang'] ?? ''), $revTargets, (string) ($page['name'] ?? ''));

    channelModel()->updateById($id, $channelData);

    // 同步到 contents 表（向后兼容）
    if ($contentRecord) {
        contentModel()->updateById((int)$contentRecord['id'], [
            'content' => $renderedHtml,
            'content_type' => 'blocks',
            'blocks_data' => $postBlocksData,
            'updated_at' => time(),
        ]);
    } else {
        contentModel()->create([
            'channel_id' => $id,
            'lang' => siteLang(),
            'title' => post('name'),
            'content' => $renderedHtml,
            'content_type' => 'blocks',
            'blocks_data' => $postBlocksData,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
    adminLog('page', 'edit', '排版编辑单页：' . $channelData['name']);
    success();
}

$pageTitle = ($isHomeLayout ? __('page_mode_blocks_edit') : '排版编辑') . ' - ' . $page['name'];
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <i class="ti ti-chevron-left text-base"></i>
        <?php echo __('page_back_to_list'); ?>
    </a>
    <div class="flex items-center gap-2">
        <?php // Blox 全屏编辑器（实验 / 授权功能）：默认隐藏，见 bloxEditorEnabled() ?>
        <?php if (bloxEditorEnabled()): ?>
        <a href="<?php echo $isHomeLayout ? '/admin/blox_editor.php?home=1' : '/admin/blox_editor.php?id=' . $id; ?>"
           class="bg-gray-900 hover:bg-black text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1.5 cursor-pointer transition"
           title="<?php echo e(__('page_mode_blox_tip')); ?>">
            <i class="ti ti-stack-2 text-base text-blue-400"></i>
            <?php echo __('page_mode_blox'); ?>
            <span class="text-[10px] font-medium bg-blue-500/20 text-blue-300 px-1.5 py-0.5 rounded"><?php echo __('label_experimental'); ?></span>
        </a>
        <?php endif; ?>
        <?php if ($isHomeLayout): ?>
        <a href="/admin/setting_home.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
            <i class="ti ti-settings text-base"></i>
            <?php echo __('admin_setting_home'); ?>
        </a>
        <?php else: ?>
        <?php
        // 切回普通编辑器只对「尚未排版的富文本页」有意义：
        //   · 排版页（content_type=blocks）在普通编辑器里一保存就清掉 blocks_data，等于静默丢版式；
        //   · 联系页的 content 字段前台从不渲染（走 contact.php 模板），切过去编了也看不见。
        // 这两种情况不给入口——留着只会把人引到会丢数据的路上。
        $showSimpleSwitch = ($contentType ?? 'html') !== 'blocks' && !$isContactPage;
        ?>
        <?php if ($showSimpleSwitch): ?>
        <a href="/admin/page_edit.php?id=<?php echo $id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
            <i class="ti ti-pencil text-base"></i>
            <?php echo __('page_switch_simple'); ?>
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!$isHomeLayout): ?>
<?php $childEditBase = '/admin/page_edit_advance.php'; require ROOT_PATH . '/admin/includes/parent_page_notice.php'; ?>

<?php if ($isContactPage): ?>
<div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg px-5 py-4 text-sm text-blue-900 flex items-start gap-3">
    <i class="ti ti-info-circle text-base mt-0.5 shrink-0"></i>
    <div class="space-y-1 flex-1">
        <p class="font-medium"><?php echo __('page_contact_blocks_notice'); ?></p>
        <p class="text-blue-700"><?php echo __('page_contact_blocks_hint'); ?>
            <a href="/admin/setting_contact.php" class="underline hover:no-underline font-medium"><?php echo __('page_contact_settings_link'); ?></a>
        </p>
        <?php if (!empty($contactSeeded)): ?>
        <p class="text-blue-700"><?php echo __('page_contact_seeded_note'); ?></p>
        <?php endif; ?>
        <p class="pt-1">
            <button type="button" onclick="seedContactLayout()"
                    class="inline-flex items-center gap-1 bg-white border border-blue-300 text-blue-800 hover:bg-blue-100 rounded px-3 py-1.5 text-sm font-medium transition">
                <i class="ti ti-wand text-base"></i><?php echo __('page_contact_seed_reset'); ?>
            </button>
            <span class="text-blue-700 ml-2"><?php echo __('page_contact_seed_reset_hint'); ?></span>
        </p>
    </div>
</div>
<?php endif; ?>

<?php // 本页设置了跳转时，明确告知这里编辑的内容前台不会显示 ?>
<?php if (!empty($redirectNotice)): ?>
<div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg px-5 py-4 text-sm text-amber-900 flex items-start gap-3">
    <i class="ti ti-arrow-right-circle text-base mt-0.5 shrink-0"></i>
    <div class="space-y-1 flex-1">
        <p class="font-medium">
            <?php echo $redirectNotice === 'auto'
                ? sprintf(e(__('page_redirect_notice_auto')), '<strong>' . e($redirectTarget) . '</strong>')
                : sprintf(e(__('page_redirect_notice_url')), '<strong>' . e($redirectTarget) . '</strong>'); ?>
        </p>
        <p class="text-amber-700"><?php echo __('page_redirect_notice_hint'); ?>
            <a href="/admin/channel.php?edit=<?php echo (int) $id; ?>&tab=main" class="underline hover:no-underline font-medium"><?php echo __('admin_channel_edit'); ?></a>
        </p>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<form id="editForm" class="space-y-6" x-data="pageBuilder()" x-init="init()"
      @layout-save-started.window="saving = true"
      @layout-saved.window="markSaved()"
      @layout-save-finished.window="saving = false">
<?php if ($isHomeLayout): ?>
    <div class="bg-blue-50 border border-blue-200 rounded-lg px-5 py-4 text-sm text-blue-900">
        <div class="flex items-start gap-3">
            <i class="ti ti-home text-base mt-0.5 shrink-0"></i>
            <div>
                <p class="font-semibold"><?php echo e(__('home_layout_editor_title')); ?></p>
                <p class="mt-1 leading-relaxed"><?php echo e(__('home_layout_editor_help')); ?></p>
                <p class="mt-1 text-blue-700"><?php echo e(__('home_layout_editor_banner_note')); ?></p>
            </div>
        </div>
    </div>
<?php else: ?>
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
                           class="w-full border rounded px-4 py-2" placeholder="如：about-us，留空自动生成">
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
                        <?php echo __("admin_media_library"); ?></button>
                </div>
                <?php if ($page['image']): ?>
                <img src="<?php echo e($page['image']); ?>" id="imagePreview" class="h-24 mt-2 rounded">
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
    <!-- 排版编辑器 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <h2 class="font-bold text-gray-800"><?php echo __('label_layout_content'); ?></h2>
                <span class="text-xs text-gray-400" x-text="sections.length + ' <?php echo e(__('home_layout_section_unit')); ?>'"></span>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" @click="expandAllSections()"
                        class="text-xs text-gray-500 hover:text-primary cursor-pointer"><?php echo __('home_layout_expand_all'); ?></button>
                <button type="button" @click="collapseAllSections()"
                        class="text-xs text-gray-500 hover:text-primary cursor-pointer"><?php echo __('home_layout_collapse_all'); ?></button>
                <button type="button" @click="togglePreview()"
                        class="text-sm border px-3 py-1.5 rounded inline-flex items-center gap-1 cursor-pointer transition whitespace-nowrap"
                        :class="showPreview ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600 hover:border-primary hover:text-primary'">
                    <i class="ti ti-eye text-base"></i>实时预览
                </button>
                <button type="button" @click="showPresets = true"
                        class="text-sm border border-primary text-primary hover:bg-primary hover:text-white px-3 py-1.5 rounded inline-flex items-center gap-1 cursor-pointer transition whitespace-nowrap">
                    <i class="ti ti-layout-collage text-base"></i>预设库
                </button>
            </div>
        </div>
        <div class="p-6">
            <template x-if="sections.length === 0">
                <div class="text-center py-12 text-gray-400">
                    <i class="ti ti-lock text-5xl mx-auto mb-3 text-gray-300"></i>
                    <p>暂无区块，点击下方按钮添加</p>
                </div>
            </template>

            <!-- 区块列表 -->
            <div x-ref="sectionsContainer">
                <template x-for="(section, si) in sections" :key="section.id">
                    <div class="border rounded-lg mb-4 group/section hover:border-blue-300 transition" :data-si="si"
                         :class="section.settings && section.settings.hidden ? 'border-amber-200 bg-amber-50/40' : 'border-gray-200'">
                        <!-- 区块工具栏 -->
                        <div class="flex items-center justify-between px-4 py-2 bg-gray-50 rounded-t-lg" :class="isSectionOpen(section.id) ? 'border-b' : ''">
                            <div class="flex items-center gap-2">
                                <span class="section-drag-handle cursor-grab text-gray-300 hover:text-gray-500">
                                    <i class="ti ti-menu-2 text-base"></i>
                                </span>
                                <button type="button" @click="toggleSection(section.id)"
                                        class="min-w-0 text-left inline-flex items-center gap-2 cursor-pointer">
                                    <i class="ti text-gray-400" :class="isSectionOpen(section.id) ? 'ti-chevron-down' : 'ti-chevron-right'"></i>
                                    <span class="text-sm font-medium text-gray-700" x-text="sectionLabel(section, si)"></span>
                                </button>
                                <span class="text-xs text-gray-400" x-show="!section.library_id" x-text="sectionColumnLabel(section)"></span>
                                <span class="text-xs text-gray-400" x-show="!isSectionOpen(section.id)" x-text="sectionElementCount(section) + ' <?php echo e(__('home_layout_element_unit')); ?>'"></span>
                                <template x-if="section.library_id">
                                    <span class="text-xs text-purple-600 bg-purple-50 px-1.5 py-0.5 rounded inline-flex items-center gap-1">
                                        <i class="ti ti-link"></i><span x-text="'引用：' + (section.library_name || ('#' + section.library_id))"></span>
                                    </span>
                                </template>
                                <template x-if="section.settings.bg_color">
                                    <span class="inline-block w-3 h-3 rounded-full border" :style="'background:' + section.settings.bg_color"></span>
                                </template>
                                <template x-if="section.settings && section.settings.hidden">
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 inline-flex items-center gap-1"
                                          title="<?php echo e(__('page_section_hidden_tip')); ?>">
                                        <i class="ti ti-eye-off text-xs"></i><?php echo __('page_section_hidden'); ?>
                                    </span>
                                </template>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="moveSection(si, -1)" :disabled="si === 0"
                                        class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="上移">
                                    <i class="ti ti-chevron-up text-base"></i>
                                </button>
                                <button type="button" @click="moveSection(si, 1)" :disabled="si === sections.length - 1"
                                        class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="下移">
                                    <i class="ti ti-chevron-down text-base"></i>
                                </button>
                                <template x-if="!section.library_id">
                                    <button type="button" @click="saveToLibrary(si)"
                                            class="p-1 text-gray-400 hover:text-purple-600 cursor-pointer" title="存为可复用块">
                                        <i class="ti ti-bookmark-plus text-base"></i>
                                    </button>
                                </template>
                                <template x-if="section.library_id">
                                    <button type="button" @click="detachLibRef(si)"
                                            class="p-1 text-gray-400 hover:text-purple-600 cursor-pointer" title="转为独立副本（不再跟随块库更新）">
                                        <i class="ti ti-unlink text-base"></i>
                                    </button>
                                </template>
                                <template x-if="!section.library_id">
                                    <button type="button" @click="openSettings(si)"
                                            class="p-1 text-gray-400 hover:text-gray-600 cursor-pointer" title="设置">
                                        <i class="ti ti-settings text-base"></i>
                                    </button>
                                </template>
                                <button type="button" @click="toggleSectionHidden(si)"
                                        class="p-1 cursor-pointer"
                                        :class="section.settings && section.settings.hidden ? 'text-amber-500 hover:text-amber-600' : 'text-gray-400 hover:text-gray-600'"
                                        :title="section.settings && section.settings.hidden ? '<?php echo e(__('page_section_show')); ?>' : '<?php echo e(__('page_section_hide')); ?>'">
                                    <i class="ti text-base" :class="section.settings && section.settings.hidden ? 'ti-eye-off' : 'ti-eye'"></i>
                                </button>
                                <button type="button" @click="removeSection(si)"
                                        class="p-1 text-red-400 hover:text-red-600 cursor-pointer" title="<?php echo __('admin_delete'); ?>">
                                    <i class="ti ti-trash text-base"></i>
                                </button>
                            </div>
                        </div>
                        <!-- 引用块：内容在块库中维护，此处只显示占位 -->
                        <template x-if="section.library_id">
                            <div class="p-4" x-show="isSectionOpen(section.id)" x-cloak>
                                <div class="text-center py-6 text-sm text-purple-500 bg-purple-50/50 rounded border border-dashed border-purple-200">
                                    <i class="ti ti-library text-2xl block mb-1"></i>
                                    引用块内容在块库中统一维护，修改后所有引用页面同步生效
                                    <div class="text-xs text-purple-400 mt-1">如需单独编辑此页版本，点右上 <i class="ti ti-unlink"></i> 转为独立副本</div>
                                </div>
                            </div>
                        </template>
                        <!-- 列内容 -->
                        <div class="p-4" x-show="!section.library_id && isSectionOpen(section.id)" x-cloak>
                            <div class="grid gap-4" :class="section.columns.length > 1 ? 'grid-cols-' + section.columns.length : ''">
                                <template x-for="(col, ci) in section.columns" :key="col.id">
                                    <div class="border rounded-lg p-3 min-h-[100px]"
                                         :class="(section.settings && section.settings.col_card) ? 'border-gray-200 bg-white shadow-sm text-center' : 'border-dashed border-gray-300'"
                                         :data-section-index="si" :data-column-index="ci" data-sortable-elements>
                                        <!-- 元素列表 -->
                                        <template x-for="(el, ei) in col.elements" :key="el.id">
                                            <div class="mb-2 bg-white border rounded p-3 group/el relative hover:border-blue-300 transition block-element">
                                                <!-- 元素工具栏 -->
                                                <div class="absolute -top-2 -right-2 flex gap-0.5 z-10 bg-white border rounded shadow-sm px-1 py-0.5">
                                                    <span class="element-drag-handle cursor-grab p-1 text-gray-400 hover:text-gray-600" title="拖拽排序">
                                                        <i class="ti ti-menu-2 text-base"></i>
                                                    </span>
                                                    <button type="button" @click="moveElement(si,ci,ei,-1)" :disabled="ei===0"
                                                            class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="上移">
                                                        <i class="ti ti-chevron-up text-base"></i>
                                                    </button>
                                                    <button type="button" @click="moveElement(si,ci,ei,1)" :disabled="ei===col.elements.length-1"
                                                            class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="下移">
                                                        <i class="ti ti-chevron-down text-base"></i>
                                                    </button>
                                                    <button type="button" @click="removeElement(si,ci,ei)"
                                                            class="p-1 text-red-400 hover:text-red-600 cursor-pointer" title="<?php echo __('admin_delete'); ?>">
                                                        <i class="ti ti-x text-base"></i>
                                                    </button>
                                                </div>

                                                <!-- 标题 -->
                                                <template x-if="el.type === 'heading'">
                                                    <div>
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">标题</span>
                                                            <select x-model="el.data.level" class="text-xs border rounded px-1.5 py-0.5">
                                                                <option value="h1">H1</option>
                                                                <option value="h2">H2</option>
                                                                <option value="h3">H3</option>
                                                                <option value="h4">H4</option>
                                                            </select>
                                                        </div>
                                                        <input type="text" x-model="el.data.text" placeholder="输入标题..."
                                                               class="w-full border-0 border-b border-gray-200 font-bold text-lg focus:outline-none focus:border-primary py-1">
                                                    </div>
                                                </template>

                                                <!-- 富文本 -->
                                                <template x-if="el.type === 'text'">
                                                    <div x-data="{ full: false }">
                                                        <div class="flex items-center justify-between mb-1">
                                                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">富文本</span>
                                                            <div class="flex items-center gap-2">
                                                                <?php // 长文默认限高，但要让人看得出「下面还有」，可一键展开 ?>
                                                                <button type="button" @click="full = !full"
                                                                        class="text-xs text-gray-400 hover:text-primary cursor-pointer inline-flex items-center gap-0.5">
                                                                    <i class="ti text-sm" :class="full ? 'ti-chevrons-up' : 'ti-chevrons-down'"></i>
                                                                    <span x-text="full ? '<?php echo e(__('admin_collapse')); ?>' : '<?php echo e(__('admin_expand')); ?>'"></span>
                                                                </button>
                                                                <button type="button" @click="editText(si,ci,ei)" class="text-xs text-primary hover:underline cursor-pointer">编辑内容</button>
                                                            </div>
                                                        </div>
                                                        <div class="relative">
                                                            <div @dblclick="editText(si,ci,ei)"
                                                                 class="prose prose-sm max-w-none overflow-hidden text-gray-600 border-t pt-2 cursor-pointer transition-all"
                                                                 :class="full ? '' : 'max-h-32'"
                                                                 x-html="el.data.html || '<span class=\'text-gray-400 italic\'>双击或点击编辑添加内容</span>'"></div>
                                                            <!-- 未展开时底部渐隐，提示内容被截断 -->
                                                            <div x-show="!full" class="pointer-events-none absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-white to-transparent"></div>
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 图片 -->
                                                <template x-if="el.type === 'image'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-1 inline-block">图片</span>
                                                        <div x-show="el.data.src">
                                                            <img :src="el.data.src" class="max-h-40 rounded border">
                                                            <div class="flex items-center gap-2 mt-1">
                                                                <input type="text" x-model="el.data.alt" placeholder="图片描述(alt)"
                                                                       class="flex-1 text-xs border rounded px-2 py-1">
                                                                <button type="button" @click="pickImage(si,ci,ei)" class="text-xs text-primary hover:underline cursor-pointer whitespace-nowrap">更换</button>
                                                            </div>
                                                            <div class="flex items-center gap-2 mt-1.5">
                                                                <select x-model="el.data.click_action" class="text-xs border rounded px-2 py-1">
                                                                    <option value="">点击无动作</option>
                                                                    <option value="lightbox">点击弹出大图</option>
                                                                    <option value="link">点击跳转链接</option>
                                                                </select>
                                                                <template x-if="el.data.click_action === 'link'">
                                                                    <input type="text" x-model="el.data.link_url" placeholder="链接地址"
                                                                           class="flex-1 text-xs border rounded px-2 py-1">
                                                                </template>
                                                                <template x-if="el.data.click_action === 'link'">
                                                                    <label class="flex items-center gap-1 text-xs text-gray-500 cursor-pointer whitespace-nowrap">
                                                                        <input type="checkbox" x-model="el.data.link_new_tab"> 新窗口
                                                                    </label>
                                                                </template>
                                                            </div>
                                                        </div>
                                                        <div x-show="!el.data.src" @click="pickImage(si,ci,ei)"
                                                             class="h-20 bg-gray-50 rounded border-2 border-dashed border-gray-300 flex items-center justify-center text-gray-400 text-sm cursor-pointer hover:border-primary hover:text-primary transition">
                                                            点击选择图片
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 按钮 -->
                                                <template x-if="el.type === 'button'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-1 inline-block">按钮</span>
                                                        <div class="flex gap-2 mt-1">
                                                            <input type="text" x-model="el.data.text" placeholder="按钮文字"
                                                                   class="border rounded px-2 py-1 text-sm flex-1">
                                                            <input type="text" x-model="el.data.url" placeholder="链接地址"
                                                                   class="border rounded px-2 py-1 text-sm flex-1">
                                                        </div>
                                                        <label class="flex items-center gap-1 mt-1 text-xs text-gray-500 cursor-pointer">
                                                            <input type="checkbox" x-model="el.data.new_tab"> 新窗口打开
                                                        </label>
                                                    </div>
                                                </template>

                                                <!-- 图标（选择网格默认收起，选完自动收回） -->
                                                <template x-if="el.type === 'icon'">
                                                    <div x-data="{ pick: false }">
                                                        <div class="flex items-center gap-2 mb-2">
                                                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">图标</span>
                                                            <span class="w-9 h-9 border rounded flex items-center justify-center bg-gray-50"
                                                                  :style="el.data.color ? 'color:' + el.data.color : ''">
                                                                <i class="ti text-xl" :class="'ti-' + (el.data.icon || 'star')"></i>
                                                            </span>
                                                            <button type="button" @click="pick = !pick"
                                                                    class="text-xs text-primary hover:underline cursor-pointer"
                                                                    x-text="pick ? '收起' : '更换图标'"></button>
                                                        </div>
                                                        <div x-show="pick" x-cloak class="flex flex-wrap gap-1.5 mb-2 p-2 border rounded bg-gray-50 max-h-28 overflow-y-auto">
                                                            <template x-for="ic in ['star','heart','circle-check','phone','mail','map-pin','clock','shield','bolt','award','world','users','home','settings','camera','bell','bookmark','calendar','folder','gift','link','lock','search','tag','trending-up','thumb-up','eye','download','upload','share','code','coffee','feather','flag','info-circle','lifebuoy','microphone','device-desktop','music','package','pencil','printer','send','server','mood-smile','sun','target','terminal','truck','device-tv','umbrella','wifi']">
                                                                <button type="button" @click="el.data.icon = ic; pick = false"
                                                                        class="w-8 h-8 flex items-center justify-center border rounded text-gray-600 hover:bg-primary hover:text-white transition cursor-pointer"
                                                                        :class="el.data.icon === ic ? 'bg-primary text-white border-primary' : 'bg-white'">
                                                                    <i class="ti text-base" :class="'ti-' + ic"></i>
                                                                </button>
                                                            </template>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <div>
                                                                <label class="text-xs text-gray-500">大小</label>
                                                                <select x-model="el.data.size" class="w-full border rounded px-2 py-1 text-sm">
                                                                    <option value="sm">小 (24px)</option>
                                                                    <option value="md">中 (32px)</option>
                                                                    <option value="lg">大 (48px)</option>
                                                                    <option value="xl">超大 (64px)</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">颜色</label>
                                                                <div class="flex gap-1">
                                                                    <input type="color" x-model="el.data.color" class="w-8 h-8 rounded border cursor-pointer">
                                                                    <input type="text" x-model="el.data.color" placeholder="#主题色" class="flex-1 border rounded px-2 py-1 text-xs">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2">
                                                            <input type="text" x-model="el.data.text" placeholder="图标下方文字（可选）" class="w-full border rounded px-2 py-1 text-sm">
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 分隔线 -->
                                                <template x-if="el.type === 'divider'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-2 inline-block">分隔线</span>
                                                        <div class="flex items-center gap-3">
                                                            <div>
                                                                <label class="text-xs text-gray-500">样式</label>
                                                                <select x-model="el.data.style" class="border rounded px-2 py-1 text-sm">
                                                                    <option value="solid">实线</option>
                                                                    <option value="dashed">虚线</option>
                                                                    <option value="dotted">点线</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">粗细</label>
                                                                <select x-model="el.data.width" class="border rounded px-2 py-1 text-sm">
                                                                    <option value="1">1px</option>
                                                                    <option value="2">2px</option>
                                                                    <option value="3">3px</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">颜色</label>
                                                                <div class="flex gap-1">
                                                                    <input type="color" x-model="el.data.color" class="w-8 h-8 rounded border cursor-pointer">
                                                                    <input type="text" x-model="el.data.color" placeholder="#e5e7eb" class="w-20 border rounded px-2 py-1 text-xs">
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">间距</label>
                                                                <select x-model="el.data.spacing" class="border rounded px-2 py-1 text-sm">
                                                                    <option value="sm">小</option>
                                                                    <option value="md">中</option>
                                                                    <option value="lg">大</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2 px-2" :style="'border-top-style:' + el.data.style + ';border-top-width:' + el.data.width + 'px;border-top-color:' + (el.data.color || '#e5e7eb')"></div>
                                                    </div>
                                                </template>

                                                <!-- 代码/HTML -->
                                                <template x-if="el.type === 'code'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-1 inline-block">代码/HTML</span>
                                                        <textarea x-model="el.data.html" rows="4" placeholder="输入 HTML 代码、短码 [form-xxx]、iframe 等..."
                                                                  class="w-full border rounded px-3 py-2 text-sm font-mono bg-gray-50 text-gray-800"></textarea>
                                                    </div>
                                                </template>

                                                <!-- 间距（支持响应式三档） -->
                                                <template x-if="el.type === 'spacer'">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">间距</span>
                                                        <select x-effect="$el.value = respGet(el, 'size', 'md')"
                                                                @change="respSet(el, 'size', $event.target.value, 'md')"
                                                                class="border rounded px-2 py-1 text-sm">
                                                            <option value="sm">小 (16px)</option>
                                                            <option value="md">中 (32px)</option>
                                                            <option value="lg">大 (64px)</option>
                                                            <option value="xl">超大 (96px)</option>
                                                        </select>
                                                        <div class="flex gap-0.5">
                                                            <button type="button" @click="setRespDev(el, 'd')" title="桌面"
                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                    :class="respTab(el) === 'd' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-desktop"></i></button>
                                                            <button type="button" @click="setRespDev(el, 't')" title="平板"
                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                    :class="respTab(el) === 't' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-tablet"></i></button>
                                                            <button type="button" @click="setRespDev(el, 'm')" title="手机"
                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                    :class="respTab(el) === 'm' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-mobile"></i></button>
                                                        </div>
                                                        <span x-show="respIsSplit(el, 'size')" class="text-[10px] text-primary bg-blue-50 px-1 py-0.5 rounded">已分档</span>
                                                    </div>
                                                </template>

                                                <!-- 动态列表 -->
                                                <template x-if="el.type === 'list-dynamic'">
                                                    <div class="space-y-2">
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-xs text-primary bg-blue-50 px-1.5 py-0.5 rounded">动态列表</span>
                                                            <span class="text-xs text-gray-400">发布后按栏目/模型拉实时数据</span>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <label class="text-xs text-gray-500 block">类型
                                                                <input x-model="el.data.source_type" placeholder="article/case/product/模型key" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                            <label class="text-xs text-gray-500 block">栏目 slug/id
                                                                <input x-model="el.data.cat" placeholder="留空=全部" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                            <label class="text-xs text-gray-500 block">数量
                                                                <input type="number" x-model="el.data.limit" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                            <label class="text-xs text-gray-500 block">网格列数 (1-4)
                                                                <input type="number" min="1" max="4" x-model="el.data.columns" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                        </div>
                                                        <div class="flex flex-wrap gap-3 text-xs text-gray-600 pt-1">
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_image"> 封面</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_title"> 标题</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_summary"> 摘要</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_date"> 日期</label>
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 轮播图 -->
                                                <template x-if="el.type === 'banner'">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-xs text-primary bg-blue-50 px-1.5 py-0.5 rounded">轮播图</span>
                                                        <input x-model="el.data.group" placeholder="轮播分组标识 (banner group)" class="flex-1 border rounded px-2 py-1 text-sm">
                                                    </div>
                                                </template>

                                                <!-- 导航菜单 -->
                                                <template x-if="el.type === 'nav'">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-xs text-primary bg-blue-50 px-1.5 py-0.5 rounded">导航</span>
                                                        <input x-model="el.data.parent" placeholder="父栏目 slug/id，空=顶级" class="flex-1 border rounded px-2 py-1 text-sm">
                                                        <label class="inline-flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" x-model="el.data.nav_only"> 仅导航栏目</label>
                                                    </div>
                                                </template>

                                                <!-- 通用 schema 表单：controls() 是唯一字段来源，required 决定当前来源可见项 -->
                                                <template x-if="!hasCustomUI(el.type)">
                                                    <div class="space-y-2">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="text-xs text-gray-500 bg-gray-100 px-1.5 py-0.5 rounded inline-block" x-text="elementLabel(el.type)"></span>
                                                            <span x-show="isHomeBlock(el)" class="text-[11px] text-blue-600" x-text="homeBlockSourceLabel(el)"></span>
                                                        </div>

                                                        <template x-if="contactElementManage(el.type)">
                                                            <div class="flex items-center justify-between gap-3 border border-emerald-200 bg-emerald-50/70 px-3 py-2">
                                                                <p class="text-[11px] leading-relaxed text-emerald-800"><?php echo e(__('page_contact_dynamic_source')); ?></p>
                                                                <a :href="contactElementManage(el.type).url"
                                                                   class="shrink-0 inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:underline">
                                                                    <i class="ti ti-settings"></i>
                                                                    <span x-text="contactElementManage(el.type).label"></span>
                                                                </a>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeBannerBlock(el)">
                                                            <div class="border border-blue-200 bg-blue-50/60 px-3 py-3">
                                                                <div class="flex items-center justify-between gap-3 flex-wrap">
                                                                    <div class="min-w-0">
                                                                        <div class="text-xs text-gray-500 mb-1"><?php echo e(__('home_layout_banner_shortcode')); ?></div>
                                                                        <code class="inline-block bg-white border border-blue-200 text-blue-700 px-2 py-1 text-sm select-all">[banner-home]</code>
                                                                    </div>
                                                                    <a href="/admin/banner.php" class="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                                                                        <i class="ti ti-photo"></i><?php echo e(__('home_layout_edit_banner')); ?>
                                                                    </a>
                                                                </div>
                                                                <p class="mt-2 text-[11px] leading-relaxed text-gray-500"><?php echo e(__('home_layout_banner_help')); ?></p>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeAboutBlock(el)">
                                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 border border-gray-200 bg-gray-50/60 p-4">
                                                                <div class="space-y-2" :class="el.data.override_layout === 'image_left' ? 'lg:order-1' : 'lg:order-2'">
                                                                    <div class="relative aspect-[4/3] overflow-hidden bg-white border border-gray-200 flex items-center justify-center">
                                                                        <img x-show="String(el.data.override_image || '').trim()" :src="el.data.override_image" alt="" class="w-full h-full object-cover">
                                                                        <div x-show="!String(el.data.override_image || '').trim()" class="text-gray-300 text-center">
                                                                            <i class="ti ti-photo text-3xl"></i>
                                                                        </div>
                                                                        <div x-show="String(el.data.override_tag_title || '').trim() || String(el.data.override_tag_description || '').trim()"
                                                                             class="absolute bottom-3 left-3 max-w-[calc(100%-1.5rem)] bg-primary text-white px-3 py-2 shadow-lg">
                                                                            <div x-show="String(el.data.override_tag_title || '').trim()" x-text="el.data.override_tag_title" class="font-bold text-sm"></div>
                                                                            <div x-show="String(el.data.override_tag_description || '').trim()" x-text="el.data.override_tag_description" class="text-xs opacity-90 mt-0.5"></div>
                                                                        </div>
                                                                    </div>
                                                                    <label class="text-xs text-gray-500 block"><?php echo e(__('blox_home_override_image')); ?></label>
                                                                    <div class="flex gap-1.5">
                                                                        <input type="text" x-model="el.data.override_image" class="min-w-0 flex-1 border rounded px-2 py-1 text-sm">
                                                                        <button type="button" @click="pickSchemaImage(el, 'override_image')" class="px-2 border rounded text-xs text-primary bg-white cursor-pointer"><?php echo e(__('admin_media_library')); ?></button>
                                                                    </div>
                                                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_about_tag_title')); ?></label>
                                                                            <input type="text" x-model="el.data.override_tag_title" class="w-full border rounded px-2 py-1 text-sm">
                                                                        </div>
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_about_tag_description')); ?></label>
                                                                            <input type="text" x-model="el.data.override_tag_description" class="w-full border rounded px-2 py-1 text-sm">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="space-y-3" :class="el.data.override_layout === 'image_left' ? 'lg:order-2' : 'lg:order-1'">
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_about_layout')); ?></label>
                                                                        <select x-model="el.data.override_layout" class="w-full border rounded px-2 py-1 text-sm">
                                                                            <option value="text_left"><?php echo e(__('blox_home_about_text_left')); ?></option>
                                                                            <option value="image_left"><?php echo e(__('blox_home_about_image_left')); ?></option>
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_title')); ?></label>
                                                                        <input type="text" x-model="el.data.override_title" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_content')); ?></label>
                                                                        <textarea x-model="el.data.override_content" rows="5" class="w-full border rounded px-2 py-1 text-sm"></textarea>
                                                                    </div>
                                                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_button_text')); ?></label>
                                                                            <input type="text" x-model="el.data.override_button_text" class="w-full border rounded px-2 py-1 text-sm">
                                                                        </div>
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_button_url')); ?></label>
                                                                            <input type="text" x-model="el.data.override_button_url" placeholder="/about.html" class="w-full border rounded px-2 py-1 text-sm">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeStatsBlock(el)">
                                                            <div x-init="ensureStatsItems(el)" class="space-y-3 border border-gray-200 bg-gray-50/60 p-4">
                                                                <div class="relative overflow-hidden min-h-56 bg-gray-100" :style="String(el.data.bg_color || '').trim() ? 'background:' + el.data.bg_color : ''">
                                                                    <img x-show="String(el.data.override_background || '').trim()" :src="el.data.override_background" alt="" class="absolute inset-0 w-full h-full object-cover">
                                                                    <div class="absolute inset-0 bg-white/75"></div>
                                                                    <div class="relative grid grid-cols-2 lg:grid-cols-4 gap-3 p-4">
                                                                        <template x-for="(item, statIndex) in el.data.stats_items" :key="statIndex">
                                                                            <div class="bg-white/90 border border-gray-200 p-3 text-center shadow-sm backdrop-blur-sm">
                                                                                <label class="text-[11px] text-gray-500 block mb-1"><?php echo e(__('blox_home_stats_icon')); ?></label>
                                                                                <button type="button" @click="statIconPick = statIconPick === (el.id + ':' + statIndex) ? '' : (el.id + ':' + statIndex)" class="mx-auto w-11 h-11 border border-primary/20 bg-primary text-white flex items-center justify-center cursor-pointer hover:bg-secondary" :title="'<?php echo e(__('blox_home_stats_icon')); ?>'">
                                                                                    <i class="ti text-3xl" :class="item.icon && item.icon !== 'none' ? 'ti-' + item.icon : 'ti-ban'"></i>
                                                                                </button>
                                                                                <div x-show="statIconPick === (el.id + ':' + statIndex)" x-cloak class="mt-2 grid grid-cols-5 gap-1.5 border border-gray-200 bg-white/95 p-2 shadow-sm">
                                                                                    <template x-for="icon in HOME_STATS_ICONS" :key="icon">
                                                                                        <button type="button" @click="item.icon = icon; statIconPick = ''" class="w-8 h-8 border flex items-center justify-center cursor-pointer" :class="item.icon === icon ? 'bg-primary text-white border-primary' : 'bg-gray-50 text-gray-600 border-gray-200 hover:bg-blue-50 hover:text-primary'" :title="icon">
                                                                                            <i class="ti text-lg" :class="icon === 'none' ? 'ti-ban' : 'ti-' + icon"></i>
                                                                                        </button>
                                                                                    </template>
                                                                                </div>
                                                                                <label class="text-[11px] text-gray-500 block mt-3 mb-1"><?php echo e(__('blox_home_stats_number')); ?></label>
                                                                                <input type="text" x-model="item.number" class="w-full border border-gray-200 bg-white text-gray-900 rounded px-2 py-1 text-lg font-bold text-center">
                                                                                <label class="text-[11px] text-gray-500 block mt-2 mb-1"><?php echo e(__('blox_home_stats_label')); ?></label>
                                                                                <input type="text" x-model="item.label" class="w-full border border-gray-200 bg-white text-gray-900 rounded px-2 py-1 text-xs text-center">
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_stats_background')); ?></label>
                                                                    <div class="flex gap-1.5">
                                                                        <input type="text" x-model="el.data.override_background" class="min-w-0 flex-1 border rounded px-2 py-1 text-sm">
                                                                        <button type="button" @click="pickSchemaImage(el, 'override_background')" class="px-2 border rounded text-xs text-primary bg-white cursor-pointer"><?php echo e(__('admin_media_library')); ?></button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isProductCarouselBlock(el)">
                                                            <div x-init="ensureProductCarousel(el)" class="space-y-3 border border-gray-200 bg-gray-50/60 p-4">
                                                                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_product_title')); ?></label>
                                                                        <input type="text" x-model="el.data.title" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_product_per_row')); ?></label>
                                                                        <input type="number" min="1" max="6" x-model.number="el.data.per_row" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_product_autoplay')); ?></label>
                                                                        <input type="number" min="0" max="30" x-model.number="el.data.autoplay" class="w-full border rounded px-2 py-1 text-sm">
                                                                        <p class="text-[11px] text-gray-400 mt-1"><?php echo e(__('blox_home_product_autoplay_help')); ?></p>
                                                                    </div>
                                                                </div>
                                                                <div>
                                                                    <div class="flex items-center justify-between gap-2 mb-2">
                                                                        <label class="text-xs text-gray-500"><?php echo e(__('blox_home_product_selected')); ?> (<span x-text="el.data.product_ids.length"></span>)</label>
                                                                        <select @change="addCarouselProduct(el, $event.target.value); $event.target.value=''" class="border rounded px-2 py-1 text-xs bg-white max-w-xs">
                                                                            <option value=""><?php echo e(__('blox_home_product_add')); ?></option>
                                                                            <template x-for="product in HOME_PRODUCT_OPTIONS" :key="product.id">
                                                                                <option :value="product.id" x-text="product.title" :disabled="el.data.product_ids.map(Number).indexOf(Number(product.id)) !== -1"></option>
                                                                            </template>
                                                                        </select>
                                                                    </div>
                                                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                                                        <template x-for="(productId, productIndex) in el.data.product_ids" :key="productId">
                                                                            <div class="flex items-center gap-1.5 bg-white border rounded px-2 py-2 text-xs">
                                                                                <span class="w-5 h-5 bg-blue-50 text-primary flex items-center justify-center shrink-0" x-text="productIndex + 1"></span>
                                                                                <span class="min-w-0 flex-1 truncate" x-text="carouselProductName(productId)"></span>
                                                                                <button type="button" @click="moveCarouselProduct(el, productIndex, -1)" :disabled="productIndex === 0" class="text-gray-400 hover:text-gray-700 disabled:opacity-30"><i class="ti ti-chevron-up"></i></button>
                                                                                <button type="button" @click="moveCarouselProduct(el, productIndex, 1)" :disabled="productIndex === el.data.product_ids.length - 1" class="text-gray-400 hover:text-gray-700 disabled:opacity-30"><i class="ti ti-chevron-down"></i></button>
                                                                                <button type="button" @click="removeCarouselProduct(el, productIndex)" class="text-red-400 hover:text-red-600"><i class="ti ti-x"></i></button>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                    <p x-show="el.data.product_ids.length === 0" class="text-xs text-gray-400 py-3 text-center"><?php echo e(__('blox_home_product_empty')); ?></p>
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeChannelBlock(el)">
                                                            <div class="space-y-3 border border-gray-200 bg-gray-50/60 p-4">
                                                                <div class="flex items-center justify-between gap-3">
                                                                    <div>
                                                                        <div class="text-sm font-medium text-gray-700" x-text="homeBlockSourceLabel(el)"></div>
                                                                        <p class="text-[11px] text-gray-400 mt-0.5"><?php echo e(__('blox_home_channel_live_help')); ?></p>
                                                                    </div>
                                                                    <a href="/admin/channel.php" class="text-xs text-primary hover:underline inline-flex items-center gap-1"><i class="ti ti-list-details"></i><?php echo e(__('blox_home_manage_channel')); ?></a>
                                                                </div>
                                                                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                                                                    <div class="md:col-span-2">
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_title')); ?></label>
                                                                        <input type="text" x-model="el.data.override_title" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_limit')); ?></label>
                                                                        <input type="number" min="1" max="24" x-model.number="el.data.limit" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_columns')); ?></label>
                                                                        <input type="number" min="1" max="8" x-model.number="el.data.per_row" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_sort')); ?></label>
                                                                        <select x-model="el.data.sort" class="w-full border rounded px-2 py-1 text-sm">
                                                                            <option value="inherit"><?php echo e(__('blox_home_inherit')); ?></option>
                                                                            <option value="recommend"><?php echo e(__('blox_home_sort_recommend')); ?></option>
                                                                            <option value="latest"><?php echo e(__('blox_home_sort_latest')); ?></option>
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_button_text')); ?></label>
                                                                        <input type="text" x-model="el.data.override_button_text" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div class="md:col-span-2">
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_button_url')); ?></label>
                                                                        <input type="text" x-model="el.data.override_button_url" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeAdvantageBlock(el)">
                                                            <div x-init="ensureAdvantageItems(el)" class="space-y-3 border border-gray-200 bg-gray-900 p-4" :style="String(el.data.bg_color || '').trim() ? 'background:' + el.data.bg_color : ''">
                                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                                    <div>
                                                                        <label class="text-xs text-white/70 block mb-0.5"><?php echo e(__('blox_home_override_title')); ?></label>
                                                                        <input type="text" x-model="el.data.override_title" class="w-full border border-white/20 bg-black/20 text-white rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-white/70 block mb-0.5"><?php echo e(__('blox_home_override_description')); ?></label>
                                                                        <input type="text" x-model="el.data.override_description" class="w-full border border-white/20 bg-black/20 text-white rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                </div>
                                                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                                                    <template x-for="(item, advantageIndex) in el.data.advantage_items" :key="advantageIndex">
                                                                        <div class="border border-white/20 bg-white/10 p-3 text-center">
                                                                            <i class="ti text-3xl text-white" :class="'ti-' + item.icon"></i>
                                                                            <input type="text" x-model="item.icon" :placeholder="'<?php echo e(__('blox_home_advantage_icon')); ?>'" class="mt-2 w-full border border-white/20 bg-black/20 text-white rounded px-2 py-1 text-xs text-center">
                                                                            <input type="text" x-model="item.title" :placeholder="'<?php echo e(__('blox_home_advantage_item_title')); ?>'" class="mt-2 w-full border border-white/20 bg-black/20 text-white rounded px-2 py-1 text-sm font-medium text-center">
                                                                            <textarea x-model="item.description" rows="3" :placeholder="'<?php echo e(__('blox_home_advantage_item_description')); ?>'" class="mt-2 w-full border border-white/20 bg-black/20 text-white rounded px-2 py-1 text-xs"></textarea>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeCtaBlock(el)">
                                                            <div x-init="ensureCtaSettings(el)" class="overflow-hidden border border-gray-200 bg-white">
                                                                <div class="relative min-h-52 flex items-center justify-center px-6 py-10 text-center text-white bg-primary"
                                                                     :style="ctaPreviewStyle(el)">
                                                                    <div class="relative z-10 max-w-2xl">
                                                                        <h3 class="text-2xl md:text-3xl font-bold leading-tight" x-text="el.data.override_title || '<?php echo e(__('home_cta_title')); ?>'"></h3>
                                                                        <p class="mt-3 text-sm md:text-base text-white/85" x-text="el.data.override_description || '<?php echo e(__('home_cta_desc')); ?>'"></p>
                                                                        <span class="mt-6 inline-flex items-center gap-2 bg-white text-primary px-5 py-2.5 rounded font-medium shadow-sm">
                                                                            <span x-text="el.data.override_button_text || '<?php echo e(__('detail_consult')); ?>'"></span>
                                                                            <i class="ti ti-arrow-right"></i>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                                <div class="space-y-4 p-4">
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('blox_home_override_title')); ?></label>
                                                                        <input type="text" x-model="el.data.override_title" class="w-full border border-gray-200 bg-white text-gray-900 rounded px-3 py-2 text-base font-semibold">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('blox_home_override_description')); ?></label>
                                                                        <textarea x-model="el.data.override_description" rows="2" class="w-full border border-gray-200 bg-white text-gray-800 rounded px-3 py-2 text-sm"></textarea>
                                                                    </div>
                                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('blox_home_override_button_text')); ?></label>
                                                                            <input type="text" x-model="el.data.override_button_text" class="w-full border border-gray-200 bg-white text-gray-800 rounded px-3 py-2 text-sm">
                                                                        </div>
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('blox_home_override_button_url')); ?></label>
                                                                            <input type="text" x-model="el.data.override_button_url" placeholder="/contact.html" class="w-full border border-gray-200 bg-white text-gray-800 rounded px-3 py-2 text-sm">
                                                                        </div>
                                                                    </div>
                                                                    <div class="border-t border-gray-100 pt-4 space-y-3">
                                                                        <div class="flex items-center justify-between gap-3">
                                                                            <span class="text-xs font-medium text-gray-600"><?php echo e(__('blox_home_cta_background')); ?></span>
                                                                            <button type="button" @click="resetCtaBackground(el)" class="text-xs text-gray-400 hover:text-gray-700 cursor-pointer"><?php echo e(__('blox_home_cta_background_reset')); ?></button>
                                                                        </div>
                                                                        <div class="flex items-center gap-2">
                                                                            <button type="button" @click="setCtaBackground(el, '')" class="w-8 h-8 border border-gray-300 bg-white cursor-pointer" title="<?php echo e(__('home_bg_default')); ?>"><i class="ti ti-color-swatch text-gray-400"></i></button>
                                                                            <button type="button" @click="setCtaBackground(el, '#2563eb')" class="w-8 h-8 border border-blue-700 bg-blue-600 cursor-pointer" title="#2563eb"></button>
                                                                            <button type="button" @click="setCtaBackground(el, '#0f172a')" class="w-8 h-8 border border-slate-900 bg-slate-900 cursor-pointer" title="#0f172a"></button>
                                                                            <button type="button" @click="setCtaBackground(el, '#0f766e')" class="w-8 h-8 border border-teal-800 bg-teal-700 cursor-pointer" title="#0f766e"></button>
                                                                            <input type="color" :value="ctaColorValue(el)" @input="setCtaBackground(el, $event.target.value)" class="w-9 h-8 border border-gray-300 bg-white cursor-pointer" title="<?php echo e(__('home_bg_color_label')); ?>">
                                                                            <input type="text" x-model="el.data.bg_color" placeholder="#2563eb" class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-xs font-mono">
                                                                        </div>
                                                                        <div>
                                                                            <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('blox_home_cta_background_image')); ?></label>
                                                                            <div class="flex gap-2">
                                                                                <input type="text" x-model="el.data.bg_image" placeholder="https://..." class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                                                <button type="button" @click="pickCtaBackground(el)" class="shrink-0 border border-gray-200 bg-gray-50 hover:bg-gray-100 px-3 py-1.5 rounded text-xs text-gray-700 cursor-pointer"><i class="ti ti-photo mr-1"></i><?php echo e(__('admin_media_library')); ?></button>
                                                                            </div>
                                                                        </div>
                                                                        <div x-show="String(el.data.bg_image || '').trim()" x-cloak>
                                                                            <div class="flex items-center justify-between mb-1">
                                                                                <label class="text-xs text-gray-500"><?php echo e(__('blox_home_cta_overlay')); ?></label>
                                                                                <span class="text-xs text-gray-400" x-text="Number(el.data.bg_opacity ?? 55) + '%'"></span>
                                                                            </div>
                                                                            <input type="range" min="0" max="90" step="5" x-model.number="el.data.bg_opacity" class="w-full cursor-pointer">
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomePartnersBlock(el)">
                                                            <div class="space-y-3 border border-gray-200 bg-gray-50/60 p-4">
                                                                <div class="flex items-center justify-between gap-3">
                                                                    <div>
                                                                        <div class="text-sm font-medium text-gray-700"><?php echo e(__('blox_home_source_partners')); ?></div>
                                                                        <p class="text-[11px] text-gray-400 mt-0.5"><?php echo e(__('blox_home_partners_live_help')); ?></p>
                                                                    </div>
                                                                    <a href="/admin/link.php" class="text-xs text-primary hover:underline inline-flex items-center gap-1"><i class="ti ti-link"></i><?php echo e(__('blox_home_manage_partners')); ?></a>
                                                                </div>
                                                                <div>
                                                                    <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_title')); ?></label>
                                                                    <input type="text" x-model="el.data.override_title" class="w-full border rounded px-2 py-1 text-sm">
                                                                </div>
                                                            </div>
                                                        </template>

                                                        <template x-if="isHomeTestimonialsBlock(el)">
                                                            <div x-init="ensureTestimonialItems(el)" class="space-y-3 border border-gray-200 bg-gray-50/60 p-4">
                                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_title')); ?></label>
                                                                        <input type="text" x-model="el.data.override_title" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                    <div>
                                                                        <label class="text-xs text-gray-500 block mb-0.5"><?php echo e(__('blox_home_override_description')); ?></label>
                                                                        <input type="text" x-model="el.data.override_description" class="w-full border rounded px-2 py-1 text-sm">
                                                                    </div>
                                                                </div>
                                                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                                                                    <template x-for="(item, testimonialIndex) in el.data.testimonial_items" :key="testimonialIndex">
                                                                        <div class="bg-white border p-3 space-y-2">
                                                                            <div class="flex items-center gap-2">
                                                                                <i class="ti ti-quote text-2xl text-primary"></i>
                                                                                <input type="text" x-model="item.name" :placeholder="'<?php echo e(__('blox_home_testimonial_name')); ?>'" class="min-w-0 flex-1 border rounded px-2 py-1 text-sm font-medium">
                                                                                <button type="button" @click="moveTestimonialItem(el, testimonialIndex, -1)" :disabled="testimonialIndex === 0" class="text-gray-400 hover:text-gray-700 disabled:opacity-30"><i class="ti ti-chevron-up"></i></button>
                                                                                <button type="button" @click="moveTestimonialItem(el, testimonialIndex, 1)" :disabled="testimonialIndex === el.data.testimonial_items.length - 1" class="text-gray-400 hover:text-gray-700 disabled:opacity-30"><i class="ti ti-chevron-down"></i></button>
                                                                            </div>
                                                                            <input type="text" x-model="item.company" :placeholder="'<?php echo e(__('blox_home_testimonial_company')); ?>'" class="w-full border rounded px-2 py-1 text-xs">
                                                                            <textarea x-model="item.content" rows="3" :placeholder="'<?php echo e(__('blox_home_testimonial_content')); ?>'" class="w-full border rounded px-2 py-1 text-xs"></textarea>
                                                                            <div class="flex gap-1.5">
                                                                                <input type="text" x-model="item.avatar" :placeholder="'<?php echo e(__('blox_home_testimonial_avatar')); ?>'" class="min-w-0 flex-1 border rounded px-2 py-1 text-xs">
                                                                                <button type="button" @click="pickObjectImage(item, 'avatar')" class="px-2 border rounded text-xs text-primary bg-white cursor-pointer"><?php echo e(__('admin_media_library')); ?></button>
                                                                                <button type="button" @click="removeTestimonialItem(el, testimonialIndex)" class="px-2 border rounded text-xs text-red-500 bg-white cursor-pointer" title="<?php echo e(__('admin_delete')); ?>"><i class="ti ti-trash"></i></button>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                                <button type="button" @click="addTestimonialItem(el)" :disabled="el.data.testimonial_items.length >= 12" class="inline-flex items-center gap-1 px-3 py-1.5 border rounded text-xs text-primary bg-white cursor-pointer disabled:opacity-40"><i class="ti ti-plus"></i><?php echo e(__('blox_home_testimonial_add')); ?></button>
                                                            </div>
                                                        </template>
                                                        <?php // 普通容器的子元素继续交给 Blox；首页动态区块在本页有专用轻量管理。 ?>
                                                        <template x-if="(BUILDER_ELEMENTS[el.type] || {}).container && !isHomeBlock(el)">
                                                            <p class="text-[11px] text-amber-600 bg-amber-50 rounded px-2 py-1.5 leading-relaxed">
                                                                容器内含 <span x-text="(el.data.children || []).length"></span> 个子元素，请在 Blox 编辑器中管理；此处仅可调容器样式，保存不影响子元素。
                                                            </p>
                                                        </template>
                                                        <template x-for="ctrl in visibleElementControls(el)" :key="ctrl.key">
                                                            <div>
                                                                <template x-if="ctrl.type !== 'checkbox'">
                                                                    <label class="text-xs text-gray-500 block mb-0.5" x-text="ctrl.label"></label>
                                                                </template>
                                                                <template x-if="ctrl.type === 'text'">
                                                                    <input type="text" x-model="el.data[ctrl.key]" :placeholder="ctrl.placeholder||''" class="w-full border rounded px-2 py-1 text-sm">
                                                                </template>
                                                                <template x-if="ctrl.type === 'textarea'">
                                                                    <textarea x-model="el.data[ctrl.key]" :placeholder="ctrl.placeholder||''" :rows="ctrl.rows||3" class="w-full border rounded px-2 py-1 text-sm"></textarea>
                                                                </template>
                                                                <template x-if="ctrl.type === 'number'">
                                                                    <input type="number" x-model="el.data[ctrl.key]" :min="ctrl.min" :max="ctrl.max" class="w-full border rounded px-2 py-1 text-sm">
                                                                </template>
                                                                <template x-if="ctrl.type === 'select' && !ctrl.responsive">
                                                                    <select @change="el.data[ctrl.key] = $event.target.value" class="w-full border rounded px-2 py-1 text-sm">
                                                                        <template x-for="(lbl,val) in ctrl.options" :key="val"><option :value="val" :selected="String(el.data[ctrl.key] === undefined ? (ctrl.default || '') : el.data[ctrl.key]) === String(val)" x-text="lbl"></option></template>
                                                                    </select>
                                                                </template>
                                                                <template x-if="ctrl.type === 'select' && ctrl.responsive">
                                                                    <div class="flex items-center gap-1.5">
                                                                        <select x-effect="$el.value = respGet(el, ctrl.key, ctrl.default || '')" @change="respSet(el, ctrl.key, $event.target.value, ctrl.default || '')" class="flex-1 border rounded px-2 py-1 text-sm">
                                                                            <template x-for="(lbl,val) in ctrl.options" :key="val"><option :value="val" x-text="lbl"></option></template>
                                                                        </select>
                                                                        <div class="flex gap-0.5">
                                                                            <button type="button" @click="setRespDev(el, 'd')" title="桌面" class="px-1.5 py-0.5 border rounded text-xs cursor-pointer" :class="respTab(el) === 'd' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-desktop"></i></button>
                                                                            <button type="button" @click="setRespDev(el, 't')" title="平板" class="px-1.5 py-0.5 border rounded text-xs cursor-pointer" :class="respTab(el) === 't' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-tablet"></i></button>
                                                                            <button type="button" @click="setRespDev(el, 'm')" title="手机" class="px-1.5 py-0.5 border rounded text-xs cursor-pointer" :class="respTab(el) === 'm' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-mobile"></i></button>
                                                                        </div>
                                                                        <span x-show="respIsSplit(el, ctrl.key)" class="text-[10px] text-primary bg-blue-50 px-1 py-0.5 rounded">已分档</span>
                                                                    </div>
                                                                </template>
                                                                <template x-if="ctrl.type === 'color'">
                                                                    <input type="color" x-model="el.data[ctrl.key]" class="border rounded h-8 w-16">
                                                                </template>
                                                                <template x-if="ctrl.type === 'checkbox'">
                                                                    <label class="inline-flex items-center gap-1 text-sm text-gray-600"><input type="checkbox" x-model="el.data[ctrl.key]"> <span x-text="ctrl.label"></span></label>
                                                                </template>
                                                                <template x-if="ctrl.type === 'image'">
                                                                    <div class="flex gap-1.5"><input type="text" x-model="el.data[ctrl.key]" :placeholder="ctrl.placeholder || ''" class="min-w-0 flex-1 border rounded px-2 py-1 text-sm"><button type="button" @click="pickSchemaImage(el, ctrl.key)" class="px-2 border rounded text-xs text-primary bg-white cursor-pointer">媒体库</button></div>
                                                                </template>
                                                                <template x-if="ctrl.type === 'icon'">
                                                                    <div x-data="{ pick: false }">
                                                                        <div class="flex items-center gap-2">
                                                                            <span class="w-8 h-8 border rounded flex items-center justify-center bg-gray-50 shrink-0"><i class="ti text-lg" :class="'ti-' + (el.data[ctrl.key] || 'star')"></i></span>
                                                                            <input type="text" x-model="el.data[ctrl.key]" placeholder="Tabler 图标名" class="flex-1 border rounded px-2 py-1 text-sm">
                                                                            <button type="button" @click="pick = !pick" class="text-xs text-primary hover:underline cursor-pointer shrink-0" x-text="pick ? '收起' : '选择'"></button>
                                                                        </div>
                                                                        <div x-show="pick" x-cloak class="flex flex-wrap gap-1.5 mt-2 p-2 border rounded bg-gray-50 max-h-28 overflow-y-auto">
                                                                            <template x-for="ic in ['star','heart','circle-check','phone','mail','map-pin','clock','shield','bolt','award','world','users','home','settings','camera','bell','bookmark','calendar','folder','gift','link','lock','search','tag','trending-up','thumb-up','eye','download','upload','share','code','coffee','feather','flag','info-circle','lifebuoy','microphone','device-desktop','music','package','pencil','printer','send','server','mood-smile','sun','target','terminal','truck','device-tv','umbrella','wifi']">
                                                                                <button type="button" @click="el.data[ctrl.key] = ic; pick = false" class="w-8 h-8 flex items-center justify-center border rounded text-gray-600 hover:bg-primary hover:text-white transition cursor-pointer" :class="el.data[ctrl.key] === ic ? 'bg-primary text-white border-primary' : 'bg-white'"><i class="ti text-base" :class="'ti-' + ic"></i></button>
                                                                            </template>
                                                                        </div>
                                                                    </div>
                                                                </template>
                                                                <p x-show="ctrl.help" class="mt-1 text-[11px] leading-relaxed text-gray-400" x-text="ctrl.help"></p>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>                                            </div>
                                        </template>

                                        <!-- 添加元素 -->
                                        <div x-data="{ open: false }" class="relative add-element-btn">
                                            <button type="button" @click="open = !open"
                                                    class="w-full border-2 border-dashed border-gray-300 rounded py-2 text-gray-400 hover:border-primary hover:text-primary transition text-sm cursor-pointer">
                                                + 添加元素
                                            </button>
                                            <!-- palette 由 BuilderRegistry 元数据生成，按分类分组；加元素类即自动出现 -->
                                            <div x-show="open" @click.away="open = false" x-cloak
                                                 class="absolute z-10 mt-1 bg-white border rounded-lg shadow-lg py-1 w-40 left-1/2 -translate-x-1/2 max-h-80 overflow-y-auto">
                                                <template x-for="(els, cat) in elementsByCategory()" :key="cat">
                                                    <div>
                                                        <div class="px-3 py-1 text-[10px] text-gray-400 uppercase" x-text="categoryLabel(cat)"></div>
                                                        <template x-for="meta in els" :key="meta.type">
                                                            <button type="button" @click="addElement(si,ci,meta.type); open=false"
                                                                    :class="meta.dynamic ? 'text-primary hover:bg-blue-50' : 'hover:bg-gray-50'"
                                                                    class="block w-full text-left px-3 py-2 text-sm cursor-pointer" x-text="meta.label"></button>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- 添加区块 -->
            <div x-data="{ showPicker: false }" class="mt-4">
                <button type="button" @click="showPicker = !showPicker; showPicker && libRefresh()"
                        class="w-full border-2 border-dashed border-gray-300 rounded-lg py-4 text-gray-400 hover:border-primary hover:text-primary transition text-sm cursor-pointer">
                    + 添加区块
                </button>
                <div x-show="showPicker" x-cloak class="mt-2 border rounded-lg p-4 bg-gray-50">
                    <p class="text-sm text-gray-600 mb-3">选择列布局：</p>
                    <div class="flex gap-3 justify-center">
                        <button type="button" @click="addSection(1); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="w-20 h-10 bg-blue-100 rounded"></div>
                            <span class="text-xs mt-1 text-gray-600">1 列</span>
                        </button>
                        <button type="button" @click="addSection(2); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="flex gap-1 w-20 h-10">
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                            </div>
                            <span class="text-xs mt-1 text-gray-600">2 列</span>
                        </button>
                        <button type="button" @click="addSection(3); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="flex gap-1 w-20 h-10">
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                            </div>
                            <span class="text-xs mt-1 text-gray-600">3 列</span>
                        </button>
                        <button type="button" @click="addSection(4); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="flex gap-1 w-20 h-10">
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                            </div>
                            <span class="text-xs mt-1 text-gray-600">4 列</span>
                        </button>
                    </div>
                    <!-- 从块库插入（P2 可复用块）：引用=改库全站生效；副本=独立编辑 -->
                    <div class="mt-4 border-t pt-3">
                        <p class="text-sm text-gray-600 mb-2">或从块库插入 <span class="text-xs text-gray-400">引用块随块库更新全站生效；副本插入后独立编辑</span></p>
                        <template x-if="libItems.length === 0">
                            <p class="text-xs text-gray-400">块库为空——在任意区块工具栏点 <i class="ti ti-bookmark-plus"></i> 可把该区块存入块库</p>
                        </template>
                        <div class="space-y-1">
                            <template x-for="item in libItems" :key="item.id">
                                <div class="flex items-center gap-2 bg-white border rounded px-3 py-1.5">
                                    <i class="ti ti-library text-purple-400"></i>
                                    <span class="text-sm text-gray-700 flex-1" x-text="item.name"></span>
                                    <button type="button" @click="insertLibRef(item); showPicker = false"
                                            class="text-xs text-purple-600 hover:underline cursor-pointer">引用插入</button>
                                    <button type="button" @click="insertLibCopy(item); showPicker = false"
                                            class="text-xs text-primary hover:underline cursor-pointer">副本插入</button>
                                    <button type="button" @click="libDelete(item)"
                                            class="text-xs text-red-400 hover:text-red-600 cursor-pointer" title="从块库删除">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 实时预览面板 -->
    <div x-show="showPreview" x-cloak class="bg-white rounded-lg shadow">
        <div class="px-6 py-3 border-b flex items-center justify-between gap-3">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2"><i class="ti ti-eye"></i>实时预览</h2>
            <div class="flex items-center gap-2">
                <template x-for="d in previewDevices" :key="d.key">
                    <button type="button" @click="previewDevice = d.key"
                            class="text-xs px-2.5 py-1 rounded border cursor-pointer transition inline-flex items-center gap-1"
                            :class="previewDevice === d.key ? 'border-primary text-primary' : 'border-gray-200 text-gray-400 hover:text-gray-600'">
                        <i class="ti" :class="d.icon"></i><span x-text="d.label"></span>
                    </button>
                </template>
                <span class="text-xs text-gray-300" x-show="previewLoading">刷新中…</span>
            </div>
        </div>
        <div class="p-4 bg-gray-100 flex justify-center overflow-auto">
            <iframe x-ref="previewFrame" class="bg-white shadow-sm border-0 transition-all duration-300"
                    :style="'width:' + previewWidth() + ';height:70vh'"></iframe>
        </div>
    </div>

    <!-- 预设库弹窗（P2）：区块预设 + 整页模板一键插入；插件可用 builder_presets 过滤器扩展 -->
    <div x-show="showPresets" x-cloak @click.self="showPresets = false" @keydown.escape.window="showPresets = false"
         class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 max-h-[80vh] flex flex-col">
            <div class="px-6 py-4 border-b flex items-center justify-between shrink-0">
                <h3 class="font-bold text-gray-800 inline-flex items-center gap-2"><i class="ti ti-layout-collage"></i>预设库</h3>
                <button type="button" @click="showPresets = false" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                    <i class="ti ti-x text-xl"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto space-y-6">
                <div>
                    <p class="text-xs text-gray-400 uppercase tracking-wide mb-2">区块预设 · 插入单个区块</p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <template x-for="p in presetSections()" :key="p.key">
                            <button type="button" @click="insertPreset(p)"
                                    class="border rounded-lg p-3 text-left hover:border-primary hover:bg-blue-50/40 transition cursor-pointer">
                                <i class="ti text-xl text-primary" :class="'ti-' + (p.icon || 'square')"></i>
                                <div class="text-sm font-medium text-gray-700 mt-1" x-text="p.label"></div>
                                <div class="text-xs text-gray-400 mt-0.5" x-text="p.desc || ''"></div>
                            </button>
                        </template>
                    </div>
                </div>
                <div>
                    <p class="text-xs text-gray-400 uppercase tracking-wide mb-2">整页模板 · 插入整套区块</p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <template x-for="p in presetPages()" :key="p.key">
                            <button type="button" @click="insertPreset(p)"
                                    class="border rounded-lg p-3 text-left hover:border-primary hover:bg-blue-50/40 transition cursor-pointer">
                                <i class="ti text-xl text-primary" :class="'ti-' + (p.icon || 'file')"></i>
                                <div class="text-sm font-medium text-gray-700 mt-1" x-text="p.label"></div>
                                <div class="text-xs text-gray-400 mt-0.5" x-text="p.desc || ''"></div>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- hidden -->
    <input type="hidden" name="blocks_data" :value="JSON.stringify(sections)">
<?php if (!$isHomeLayout): ?>
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
<?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6 flex flex-wrap items-center gap-3">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer">
            <i class="ti ti-device-floppy text-base"></i>
            <?php echo e($isHomeLayout ? __('home_layout_save_draft') : __('btn_save')); ?>
        </button>
        <?php if ($isHomeLayout): ?>
        <button id="homePublishBtn" type="button"
                class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
            <i class="ti ti-world-upload text-base"></i>
            <?php echo e(__('home_layout_publish')); ?>
        </button>
        <button id="homeRollbackBtn" type="button"
                <?php echo !($homeDocument['active'] ?? false) ? 'disabled' : ''; ?>
                class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">
            <i class="ti ti-history text-base"></i>
            <?php echo e(__('home_layout_rollback')); ?>
        </button>
        <span class="text-xs px-2.5 py-1 rounded <?php echo ($homeDocument['active'] ?? false) ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'; ?>">
            <?php echo e(($homeDocument['active'] ?? false) ? __('home_layout_state_active') : __('home_layout_state_legacy')); ?>
        </span>
        <span id="homeActionMsg" class="text-xs hidden"></span>
        <?php endif; ?>
        <span id="saveMsg" class="text-sm hidden"></span>
    </div>
</form>

<!-- 区块设置弹窗 -->
<div id="sectionSettingsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 max-h-[88vh] flex flex-col">
        <div class="px-6 py-4 border-b flex items-center justify-between shrink-0">
            <h3 class="font-bold text-gray-800">区块设置</h3>
            <button type="button" onclick="closeSectionSettings()" class="text-gray-400 hover:text-gray-600">
                <i class="ti ti-x text-xl"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-700 mb-1">列数</label>
                <div class="flex gap-2">
                    <button type="button" onclick="setSectionCols(1)" data-cols="1" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">1 列</button>
                    <button type="button" onclick="setSectionCols(2)" data-cols="2" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">2 列</button>
                    <button type="button" onclick="setSectionCols(3)" data-cols="3" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">3 列</button>
                    <button type="button" onclick="setSectionCols(4)" data-cols="4" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">4 列</button>
                </div>
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-700 mb-1">区块标题 <span class="text-xs text-gray-400">（可选，居中大标题 + 装饰条；留空则不显示，适合"总标题 + 多列"版块如价格表）</span></label>
                <input id="settingTitle" type="text" class="w-full border rounded px-3 py-2" placeholder="如：价格方案 / 我们的产品">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm text-gray-700 mb-1">区块副标题 <span class="text-xs text-gray-400">（可选）</span></label>
                <input id="settingSubtitle" type="text" class="w-full border rounded px-3 py-2" placeholder="如：选择最适合你的套餐，随时可升级">
            </div>
            <div class="md:col-span-2 flex items-center justify-between gap-2">
                <label class="text-sm text-gray-700">设备分档 <span class="text-xs text-gray-400">内边距/列间距可按设备分别设置</span></label>
                <div class="flex gap-1">
                    <button type="button" onclick="setSettingDevice('d')" data-dev="d" class="dev-btn px-2 py-1 border rounded text-xs cursor-pointer hover:bg-gray-50 inline-flex items-center gap-1"><i class="ti ti-device-desktop"></i>桌面</button>
                    <button type="button" onclick="setSettingDevice('t')" data-dev="t" class="dev-btn px-2 py-1 border rounded text-xs cursor-pointer hover:bg-gray-50 inline-flex items-center gap-1"><i class="ti ti-device-tablet"></i>平板</button>
                    <button type="button" onclick="setSettingDevice('m')" data-dev="m" class="dev-btn px-2 py-1 border rounded text-xs cursor-pointer hover:bg-gray-50 inline-flex items-center gap-1"><i class="ti ti-device-mobile"></i>手机</button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">内边距</label>
                <select id="settingPadding" class="w-full border rounded px-3 py-2">
                    <option value="none">无</option>
                    <option value="sm">小</option>
                    <option value="md">中（默认）</option>
                    <option value="lg">大</option>
                    <option value="xl">超大</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">最大宽度</label>
                <select id="settingMaxWidth" class="w-full border rounded px-3 py-2">
                    <option value="default">默认 (1152px)</option>
                    <option value="narrow">窄 (896px)</option>
                    <option value="wide">宽 (1280px)</option>
                    <option value="full">全宽</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">垂直对齐</label>
                <div class="flex gap-2">
                    <button type="button" onclick="setAlignItems('start')" data-val="start" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="20" y2="4"/><rect x="6" y="4" width="4" height="10" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="4" width="4" height="6" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">顶部</span>
                    </button>
                    <button type="button" onclick="setAlignItems('center')" data-val="center" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="12" x2="20" y2="12" stroke-dasharray="2 2"/><rect x="6" y="7" width="4" height="10" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="9" width="4" height="6" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">居中</span>
                    </button>
                    <button type="button" onclick="setAlignItems('end')" data-val="end" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="20" x2="20" y2="20"/><rect x="6" y="10" width="4" height="10" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="14" width="4" height="6" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">底部</span>
                    </button>
                    <button type="button" onclick="setAlignItems('stretch')" data-val="stretch" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="20" y2="4"/><line x1="4" y1="20" x2="20" y2="20"/><rect x="6" y="4" width="4" height="16" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="4" width="4" height="16" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">拉伸</span>
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">水平对齐</label>
                <div class="flex gap-2">
                    <button type="button" onclick="setJustifyItems('start')" data-val="start" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="4" y2="20"/><rect x="4" y="6" width="10" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="4" y="14" width="6" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">左对齐</span>
                    </button>
                    <button type="button" onclick="setJustifyItems('center')" data-val="center" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="4" x2="12" y2="20" stroke-dasharray="2 2"/><rect x="7" y="6" width="10" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="9" y="14" width="6" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">居中</span>
                    </button>
                    <button type="button" onclick="setJustifyItems('end')" data-val="end" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="20" y1="4" x2="20" y2="20"/><rect x="10" y="6" width="10" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="14" width="6" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">右对齐</span>
                    </button>
                    <button type="button" onclick="setJustifyItems('stretch')" data-val="stretch" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="4" y2="20"/><line x1="20" y1="4" x2="20" y2="20"/><rect x="4" y="6" width="16" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="4" y="14" width="16" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">拉伸</span>
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">列间距</label>
                <select id="settingGap" class="w-full border rounded px-3 py-2">
                    <option value="none">无间距</option>
                    <option value="sm">小 (8px)</option>
                    <option value="md">中 (16px)</option>
                    <option value="lg">大 (32px) - 默认</option>
                    <option value="xl">超大 (48px)</option>
                </select>
            </div>
            <div>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="settingColCard" class="rounded border-gray-300 text-primary focus:ring-primary">
                    <span class="text-sm text-gray-700">每列显示为卡片（白底/边框/阴影，适合多列特性区）</span>
                </label>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">背景颜色</label>
                <div class="flex gap-2 items-center">
                    <input type="color" id="settingBgColor" value="#ffffff" class="w-10 h-10 rounded border cursor-pointer">
                    <input type="text" id="settingBgColorText" placeholder="如 #f3f4f6 或留空"
                           class="flex-1 border rounded px-3 py-2 text-sm">
                </div>
                <div class="flex items-center gap-2 mt-2">
                    <label class="text-xs text-gray-500 whitespace-nowrap">透明度</label>
                    <input type="range" id="settingBgOpacity" min="0" max="100" value="100" class="flex-1 cursor-pointer">
                    <span id="settingBgOpacityVal" class="text-xs text-gray-500 w-8 text-right">100%</span>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">背景图片</label>
                <div class="flex gap-2">
                    <input type="text" id="settingBgImage" placeholder="图片URL" class="flex-1 border rounded px-3 py-2 text-sm">
                    <button type="button" onclick="uploadSectionBgImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm cursor-pointer" title="上传本地图片">上传</button>
                    <button type="button" onclick="pickSectionBgImage()" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm cursor-pointer" title="从媒体库选择"><?php echo __('admin_media_library'); ?></button>
                </div>
                <input type="file" id="sectionBgFileInput" class="hidden" accept="image/*">
            </div>
            <button type="button" onclick="saveSectionSettings()" class="md:col-span-2 w-full bg-primary hover:bg-secondary text-white py-2 rounded transition cursor-pointer">确定</button>
        </div>
    </div>
</div>

<!-- 富文本编辑弹窗 -->
<div id="textEditorModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 flex flex-col" style="max-height: calc(100vh - 3rem)">
        <div class="px-6 py-4 border-b flex items-center justify-between gap-4 shrink-0">
            <h3 class="font-bold text-gray-800">编辑富文本内容</h3>
            <div class="flex items-center gap-3">
                <div class="inline-flex rounded border border-gray-200 bg-gray-100 p-0.5" aria-label="编辑模式">
                    <button type="button" id="textEditorVisualBtn" onclick="setTextEditorMode('visual')"
                            class="px-3 py-1.5 rounded-sm bg-white text-gray-800 shadow-sm text-xs font-medium cursor-pointer">
                        <i class="ti ti-eye mr-1"></i>可视化
                    </button>
                    <button type="button" id="textEditorSourceBtn" onclick="setTextEditorMode('source')"
                            class="px-3 py-1.5 rounded-sm text-gray-500 text-xs font-medium cursor-pointer">
                        <i class="ti ti-code mr-1"></i>HTML 源码
                    </button>
                </div>
                <button type="button" onclick="closeTextEditor()" class="text-gray-400 hover:text-gray-600" title="关闭">
                    <i class="ti ti-x text-xl"></i>
                </button>
            </div>
        </div>
        <div class="flex-1 overflow-y-auto p-6">
            <div id="textEditorVisualPane">
                <div id="modal-toolbar" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                <div id="modal-editor" class="border rounded-b-lg" style="min-height: 300px;"></div>
            </div>
            <div id="textEditorSourcePane" class="hidden">
                <label for="textEditorSource" class="sr-only">HTML 源码</label>
                <textarea id="textEditorSource" spellcheck="false"
                          class="w-full min-h-80 border border-gray-300 rounded px-4 py-3 font-mono text-sm leading-6 text-gray-800 bg-gray-50 focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary"
                          placeholder="在这里编辑 HTML 源码"></textarea>
                <p class="mt-2 text-xs text-gray-500">切回可视化模式时会应用当前源码。</p>
            </div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2 shrink-0">
            <button type="button" onclick="closeTextEditor()" class="px-4 py-2 border rounded hover:bg-gray-100 text-sm cursor-pointer"><?php echo __('admin_cancel'); ?></button>
            <button type="button" onclick="saveTextEditor()" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded text-sm cursor-pointer">确定</button>
        </div>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 封面图片上传
function uploadImage() {
    document.getElementById('imageFileInput').click();
}
document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        var response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('imageInput').value = data.data.url;
            var preview = document.getElementById('imagePreview');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'imagePreview';
                preview.className = 'h-24 mt-2 rounded';
                document.getElementById('imageInput').parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('<?php echo __('admin_success'); ?>');
        } else { showMessage(data.msg, 'error'); }
    } catch (err) { showMessage('<?php echo __('admin_fail'); ?>', 'error'); }
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

// ===== 区块设置弹窗 =====
var _settingSi = -1;

// 响应式三档：设置值标量=全设备统一，{d,t,m}=按 桌面/平板/手机 分档（渲染出 md:/lg: 前缀）
var _settingDev = 'd';
var _respVals = { padding: null, gap: null };
function normResp(v, dflt) {
    if (v && typeof v === 'object') {
        var d = v.d || dflt;
        return { d: d, t: v.t || d, m: v.m || v.t || d };
    }
    v = v || dflt;
    return { d: v, t: v, m: v };
}
function collapseResp(v) {
    return (v.d === v.t && v.t === v.m) ? v.d : v;
}
function _commitSettingDevice() {
    if (!_respVals.padding) return;
    _respVals.padding[_settingDev] = document.getElementById('settingPadding').value;
    _respVals.gap[_settingDev] = document.getElementById('settingGap').value;
}
function setSettingDevice(dev, skipCommit) {
    if (!skipCommit) _commitSettingDevice();
    _settingDev = dev;
    document.querySelectorAll('.dev-btn').forEach(function(b) {
        var on = b.dataset.dev === dev;
        b.classList.toggle('bg-primary', on);
        b.classList.toggle('text-white', on);
        b.classList.toggle('border-primary', on);
    });
    document.getElementById('settingPadding').value = _respVals.padding[dev];
    document.getElementById('settingGap').value = _respVals.gap[dev];
}
function closeSectionSettings() {
    var m = document.getElementById('sectionSettingsModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
function pickSectionBgImage() {
    openMediaPicker(function(url) { document.getElementById('settingBgImage').value = url; });
}
function uploadSectionBgImage() {
    document.getElementById('sectionBgFileInput').click();
}
document.getElementById('sectionBgFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        var resp = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(resp);
        if (data.code === 0) {
            document.getElementById('settingBgImage').value = data.data.url;
            showMessage('<?php echo __('admin_success'); ?>');
        } else { showMessage(data.msg || '上传失败', 'error'); }
    } catch (e) { showMessage('<?php echo __('admin_fail'); ?>', 'error'); }
    this.value = '';
});
function setSectionCols(n) {
    document.querySelectorAll('.col-btn').forEach(function(b) { b.classList.remove('bg-primary', 'text-white'); });
    document.querySelector('.col-btn[data-cols="' + n + '"]').classList.add('bg-primary', 'text-white');
}
function setAlignItems(val) {
    document.querySelectorAll('.align-btn').forEach(function(b) { b.classList.remove('bg-primary', 'text-white'); });
    var btn = document.querySelector('.align-btn[data-val="' + val + '"]');
    if (btn) btn.classList.add('bg-primary', 'text-white');
}
function setJustifyItems(val) {
    document.querySelectorAll('.justify-btn').forEach(function(b) { b.classList.remove('bg-primary', 'text-white'); });
    var btn = document.querySelector('.justify-btn[data-val="' + val + '"]');
    if (btn) btn.classList.add('bg-primary', 'text-white');
}
function saveSectionSettings() {
    var data = Alpine.$data(document.getElementById('editForm'));
    if (_settingSi < 0 || !data.sections[_settingSi]) return;
    var section = data.sections[_settingSi];
    _commitSettingDevice();
    section.settings.title = document.getElementById('settingTitle').value.trim();
    section.settings.subtitle = document.getElementById('settingSubtitle').value.trim();
    section.settings.padding = collapseResp(_respVals.padding);
    section.settings.max_width = document.getElementById('settingMaxWidth').value;
    section.settings.bg_color = document.getElementById('settingBgColorText').value.trim();
    section.settings.bg_opacity = parseInt(document.getElementById('settingBgOpacity').value);
    section.settings.bg_image = document.getElementById('settingBgImage').value.trim();
    section.settings.gap = collapseResp(_respVals.gap);
    section.settings.col_card = document.getElementById('settingColCard').checked;
    var alignBtn = document.querySelector('.align-btn.bg-primary');
    section.settings.align_items = alignBtn ? alignBtn.dataset.val : 'stretch';
    var justifyBtn = document.querySelector('.justify-btn.bg-primary');
    section.settings.justify_items = justifyBtn ? justifyBtn.dataset.val : 'stretch';
    // 列数变更
    var activeBtn = document.querySelector('.col-btn.bg-primary');
    var newCols = activeBtn ? parseInt(activeBtn.dataset.cols) : section.columns.length;
    var curCols = section.columns.length;
    if (newCols > curCols) {
        for (var i = curCols; i < newCols; i++) {
            section.columns.push({ id: data.uid('c'), elements: [] });
        }
    } else if (newCols < curCols) {
        for (var i = newCols; i < curCols; i++) {
            var extra = section.columns[i].elements;
            for (var j = 0; j < extra.length; j++) {
                section.columns[newCols - 1].elements.push(extra[j]);
            }
        }
        section.columns.splice(newCols);
    }
    closeSectionSettings();
    data.$nextTick(function() { data.initSortable(); });
}

// 背景颜色同步
document.getElementById('settingBgColor').addEventListener('input', function() {
    document.getElementById('settingBgColorText').value = this.value === '#ffffff' ? '' : this.value;
});
document.getElementById('settingBgColorText').addEventListener('input', function() {
    if (/^#[0-9a-fA-F]{6}$/.test(this.value.trim())) {
        document.getElementById('settingBgColor').value = this.value.trim();
    }
});
document.getElementById('settingBgOpacity').addEventListener('input', function() {
    document.getElementById('settingBgOpacityVal').textContent = this.value + '%';
});

// ===== 富文本弹窗 =====
var _modalEditor = null;
var _editingPath = null;
var _textEditorMode = 'visual';
function setTextEditorMode(mode, presetHtml) {
    if (mode !== 'visual' && mode !== 'source') return;
    var visualPane = document.getElementById('textEditorVisualPane');
    var sourcePane = document.getElementById('textEditorSourcePane');
    var source = document.getElementById('textEditorSource');
    var visualBtn = document.getElementById('textEditorVisualBtn');
    var sourceBtn = document.getElementById('textEditorSourceBtn');
    if (mode === 'source') {
        // TinyMCE 异步初始化：实例在渲染完成前就已注册，此时 getContent() 返回空串。
        // 首次打开弹窗正好撞上这个窗口 → 源码框空白，一保存就把区块内容清掉。
        // 故取空时回落到调用方传入的原始 HTML（元素数据本身），以数据为准。
        var _h = _modalEditor ? _modalEditor.getHtml() : '';
        if (_h === '' && typeof presetHtml === 'string') _h = presetHtml;
        source.value = _h;
    }
    if (mode === 'visual' && _textEditorMode === 'source' && _modalEditor) {
        _modalEditor.setHtml(source.value || '<p><br></p>');
    }
    _textEditorMode = mode;
    visualPane.classList.toggle('hidden', mode !== 'visual');
    sourcePane.classList.toggle('hidden', mode !== 'source');
    visualBtn.classList.toggle('bg-white', mode === 'visual');
    visualBtn.classList.toggle('text-gray-800', mode === 'visual');
    visualBtn.classList.toggle('shadow-sm', mode === 'visual');
    visualBtn.classList.toggle('text-gray-500', mode !== 'visual');
    sourceBtn.classList.toggle('bg-white', mode === 'source');
    sourceBtn.classList.toggle('text-gray-800', mode === 'source');
    sourceBtn.classList.toggle('shadow-sm', mode === 'source');
    sourceBtn.classList.toggle('text-gray-500', mode !== 'source');
    if (mode === 'source') source.focus();
}
// 联系页「从当前版式开始」：把写死的版式还原成区块，客户在此基础上自由增删、
// 调顺序、加别的元素。已有区块时先确认，避免误覆盖。
function seedContactLayout() {
    var d = Alpine.$data(document.getElementById('editForm'));
    if (d.sections && d.sections.length > 0
        && !confirm(<?php echo json_encode(__('page_contact_seed_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    d.sections = CONTACT_SEED_SECTIONS.map(function(section) {
        return d.freshSection(section);
    });
    showMessage(<?php echo json_encode(__('page_contact_seed_done'), JSON_UNESCAPED_UNICODE); ?>, 'success');
}

function closeTextEditor() {
    var m = document.getElementById('textEditorModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
function saveTextEditor() {
    if (_modalEditor && _editingPath) {
        var data = Alpine.$data(document.getElementById('editForm'));
        var el = data.sections[_editingPath.si].columns[_editingPath.ci].elements[_editingPath.ei];
        var html = _textEditorMode === 'source'
            ? document.getElementById('textEditorSource').value
            : _modalEditor.getHtml();
        // 双保险：编辑器/源码框异常返回空，而元素原本有内容时不写入，
        // 宁可这次编辑不生效，也不能静默清空已有内容（要清空请用删除元素）。
        if (html === '' && (el.data.html || '') !== '') {
            showMessage(<?php echo json_encode(__('page_text_editor_empty_guard'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            return;
        }
        el.data.html = html;
    }
    closeTextEditor();
}
</script>

<?php
// 初始化区块数据
$blocksDataJson = $blocksData ?: '[]';
if ($blocksData && json_decode($blocksData) === null) {
    $blocksDataJson = '[]';
}

// 如果从富文本自动转换
$initBlocks = $blocksDataJson;
if ($autoConvert && $htmlContent) {
    $autoSection = [[
        'id' => 's_auto',
        'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'md', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'lg'],
        'columns' => [[
            'id' => 'c_auto',
            'elements' => [[
                'id' => 'e_auto',
                'type' => 'text',
                'data' => ['html' => $htmlContent],
            ]],
        ]],
    ]];
    $initBlocks = json_encode($autoSection, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

$extraJs = '<scr' . 'ipt>
// 预设库（区块预设 + 整页模板，见 includes/builder/presets.php；插件可用 builder_presets 过滤器扩展）
var BUILDER_PRESETS = ' . json_encode(builderPresets(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
// 元素元数据（由 BuilderRegistry 生成）：palette + 新元素设置表单据此驱动，加元素即插即用
var BUILDER_ELEMENTS = ' . json_encode(BuilderRegistry::meta($isHomeLayout ? 'home' : 'page'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
// 已有手写设置 UI 的元素（保留其精细编辑器）；其余（新/插件元素）走通用 schema 表单
var BUILDER_CUSTOM_UI = ["heading","text","image","button","icon","divider","code","spacer","list-dynamic","banner","nav"];
var CONTACT_SEED_SECTIONS = ' . json_encode($isContactPage ? contactSeedSections() : [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
var CONTACT_ELEMENT_MANAGE = {
    contact_cards: { url: "/admin/setting_contact.php", label: ' . json_encode(__('page_contact_manage_cards'), JSON_UNESCAPED_UNICODE) . ' },
    contact_form: { url: "/admin/form_design.php", label: ' . json_encode(__('page_contact_manage_form'), JSON_UNESCAPED_UNICODE) . ' },
    contact_map: { url: "/admin/setting_contact.php#map", label: ' . json_encode(__('page_contact_manage_map'), JSON_UNESCAPED_UNICODE) . ' }
};
var HOME_LAYOUT_MODE = ' . ($isHomeLayout ? 'true' : 'false') . ';
var HOME_PRODUCT_OPTIONS = ' . json_encode($homeProductOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
var HOME_ADVANTAGE_DEFAULTS = ' . json_encode([
    ['icon' => 'check-circle', 'title' => __('home_adv_1_title'), 'description' => __('home_adv_1_desc')],
    ['icon' => 'academic-cap', 'title' => __('home_adv_2_title'), 'description' => __('home_adv_2_desc')],
    ['icon' => 'briefcase', 'title' => __('home_adv_3_title'), 'description' => __('home_adv_3_desc')],
    ['icon' => 'users', 'title' => __('home_adv_4_title'), 'description' => __('home_adv_4_desc')],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
var HOME_STATS_DEFAULTS = ' . json_encode([
    ['icon' => 'award', 'number' => '10+', 'label' => __('home_stat_1')],
    ['icon' => 'users', 'number' => '1000+', 'label' => __('home_stat_2')],
    ['icon' => 'briefcase', 'number' => '50+', 'label' => __('home_stat_3')],
    ['icon' => 'thumb-up', 'number' => '100%', 'label' => __('home_stat_4')],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
var HOME_STATS_ICONS = ["award","users","briefcase","thumb-up","star","heart","target","shield-check","trending-up","world","building","calendar","clock","rocket","trophy","chart-bar","headset","check","none"];
var HOME_BANNER_SEEDS = ' . json_encode($homeBannerSeeds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
var HOME_BANNER_TEXT = {
    newTitle: ' . json_encode(__('blox_home_banner_new_title'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ',
    restoreConfirm: ' . json_encode(__('blox_home_banner_restore_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . '
};
function pageBuilder() {
    return {
        sections: ' . $initBlocks . ',
        homeBannerSeeds: HOME_BANNER_SEEDS,
        homeBannerOpen: {},
        homeBannerItemOpen: {},
        statIconPick: "",
        openSections: {},
        isDirty: false,
        saving: false,
        savedSnapshot: "",

        // === 实时预览 ===
        showPreview: false,
        previewLoading: false,
        previewDevice: "desktop",
        previewDevices: [
            { key: "desktop", label: "桌面", icon: "ti-device-desktop" },
            { key: "tablet", label: "平板", icon: "ti-device-tablet" },
            { key: "mobile", label: "手机", icon: "ti-device-mobile" }
        ],
        _previewTimer: null,
        previewWidth() {
            return ({ desktop: "100%", tablet: "768px", mobile: "390px" })[this.previewDevice] || "100%";
        },
        togglePreview() {
            this.showPreview = !this.showPreview;
            if (this.showPreview) { var self = this; this.$nextTick(function() { self.refreshPreview(); }); }
        },
        schedulePreview() {
            if (!this.showPreview) return;
            var self = this;
            clearTimeout(this._previewTimer);
            this._previewTimer = setTimeout(function() { self.refreshPreview(); }, 600);
        },
        refreshPreview() {
            var frame = this.$refs.previewFrame;
            if (!frame) return;
            this.previewLoading = true;
            var self = this;
            var body = new URLSearchParams();
            body.set("action", "preview");
            body.set("blocks_data", JSON.stringify(this.sections));
            // 传 URLSearchParams 实例（勿 toString）：footer 的 fetch 包装器只给实例自动附加 CSRF _token
            fetch(window.location.href, {
                method: "POST",
                body: body
            }).then(function(r) { return r.text(); })
              .then(function(html) { frame.srcdoc = html; })
              .catch(function() {})
              .finally(function() { self.previewLoading = false; });
        },

        uid(prefix) {
            return prefix + "_" + Math.random().toString(36).substr(2, 9);
        },

        // === 元素元数据（schema 驱动） ===
        elementTypes() { return Object.keys(BUILDER_ELEMENTS); },
        elementsByCategory() {
            var groups = {};
            for (var t of Object.keys(BUILDER_ELEMENTS)) {
                if (t === "home-banner-item") continue;
                if (t === "home-block" && !HOME_LAYOUT_MODE) continue;
                // 首页排版允许插入动态区块；其余容器仍由 Blox 管理子元素。
                if (BUILDER_ELEMENTS[t].container && !(HOME_LAYOUT_MODE && t === "home-block")) continue;
                var c = BUILDER_ELEMENTS[t].category || "basic";
                (groups[c] = groups[c] || []).push(BUILDER_ELEMENTS[t]);
            }
            return groups;
        },
        categoryLabel(c) {
            return ({ basic: "基础", media: "媒体", layout: "布局", advanced: "高级", dynamic: "动态" })[c] || c;
        },
        hasCustomUI(type) { return BUILDER_CUSTOM_UI.indexOf(type) !== -1; },
        elementControls(type) { return (BUILDER_ELEMENTS[type] || {}).controls || []; },
        elementLabel(type) { return (BUILDER_ELEMENTS[type] || {}).label || type; },
        contactElementManage(type) { return CONTACT_ELEMENT_MANAGE[type] || null; },
        controlRequirementMet(el, ctrl) {
            var rule = ctrl.required;
            if (!Array.isArray(rule) || rule.length < 3) return true;
            var actual = (el.data || {})[rule[0]];
            if (actual === undefined) actual = ((BUILDER_ELEMENTS[el.type] || {}).defaults || {})[rule[0]];
            var expected = Array.isArray(rule[2]) ? rule[2] : [rule[2]];
            var matched = expected.map(String).indexOf(String(actual)) !== -1;
            return rule[1] === "!=" ? !matched : matched;
        },
        visibleElementControls(el) {
            var self = this;
            return this.elementControls(el.type).filter(function(ctrl) {
                if (self.isHomeBannerBlock(el) && ctrl.key !== "enabled") return false;
                if (self.isHomeAboutBlock(el) && ["override_layout","override_title","override_content","override_image","override_tag_title","override_tag_description","override_button_text","override_button_url"].indexOf(ctrl.key) !== -1) return false;
                if (self.isHomeStatsBlock(el) && ctrl.key === "override_background") return false;
                if (self.isHomeChannelBlock(el) && ["override_title","override_button_text","override_button_url","limit","sort","per_row"].indexOf(ctrl.key) !== -1) return false;
                if ((self.isHomeAdvantageBlock(el) || self.isHomeCtaBlock(el)) && ["override_title","override_description","override_button_text","override_button_url"].indexOf(ctrl.key) !== -1) return false;
                if (self.isHomePartnersBlock(el) && ctrl.key === "override_title") return false;
                if (self.isHomeTestimonialsBlock(el) && ["override_title","override_description"].indexOf(ctrl.key) !== -1) return false;
                return self.controlRequirementMet(el, ctrl);
            });
        },
        isHomeBlock(el) { return !!(HOME_LAYOUT_MODE && el && el.type === "home-block"); },
        isHomeBannerBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "banner"); },
        isHomeAboutBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "about"); },
        isHomeStatsBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "stats"); },
        isProductCarouselBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "product_carousel"); },
        isHomeChannelBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "").indexOf("channel:") === 0); },
        isHomeAdvantageBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "advantage"); },
        isHomeCtaBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "cta"); },
        ensureCtaSettings(el) {
            if (el.data.bg_opacity === undefined || el.data.bg_opacity === null) el.data.bg_opacity = 55;
            if (el.data.text_light === undefined) el.data.text_light = true;
        },
        ctaColorValue(el) {
            var color = String((el.data || {}).bg_color || "").trim();
            return /^#[0-9a-f]{6}$/i.test(color) ? color : "#2563eb";
        },
        ctaPreviewStyle(el) {
            var data = el.data || {};
            var color = String(data.bg_color || "").trim() || "#2563eb";
            var image = Array.from(String(data.bg_image || "").trim()).filter(function(character) {
                return [10, 13, 34, 40, 41, 92].indexOf(character.charCodeAt(0)) === -1;
            }).join("");
            if (!image) return "background:" + color;
            var opacity = Math.max(0, Math.min(90, Number(data.bg_opacity ?? 55))) / 100;
            var hex = /^#([0-9a-f]{6})$/i.exec(color);
            var overlay = "rgba(15,23,42," + opacity + ")";
            if (hex) {
                var value = parseInt(hex[1], 16);
                overlay = "rgba(" + ((value >> 16) & 255) + "," + ((value >> 8) & 255) + "," + (value & 255) + "," + opacity + ")";
            }
            return "background-image:linear-gradient(" + overlay + "," + overlay + "),url(" + image + ");background-size:cover;background-position:center";
        },
        setCtaBackground(el, color) {
            el.data.bg_color = color;
            el.data.text_light = true;
        },
        resetCtaBackground(el) {
            el.data.bg_color = "";
            el.data.bg_image = "";
            el.data.bg_opacity = 55;
            el.data.text_light = true;
        },
        pickCtaBackground(el) {
            openMediaPicker(function(url) {
                el.data.bg_image = url;
                if (!String(el.data.bg_color || "").trim()) el.data.bg_color = "#0f172a";
                if (el.data.bg_opacity === undefined || el.data.bg_opacity === null) el.data.bg_opacity = 55;
                el.data.text_light = true;
            });
        },
        isHomePartnersBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "partners"); },
        isHomeTestimonialsBlock(el) { return !!(this.isHomeBlock(el) && String((el.data || {}).block_type || "") === "testimonials"); },
        ensureTestimonialItems(el) {
            if (!Array.isArray(el.data.testimonial_items)) el.data.testimonial_items = [];
            el.data.testimonial_items = el.data.testimonial_items.slice(0, 12);
        },
        addTestimonialItem(el) {
            this.ensureTestimonialItems(el);
            if (el.data.testimonial_items.length < 12) el.data.testimonial_items.push({ avatar: "", name: "", company: "", content: "" });
        },
        removeTestimonialItem(el, index) { el.data.testimonial_items.splice(index, 1); },
        moveTestimonialItem(el, index, direction) {
            var target = index + direction;
            if (target < 0 || target >= el.data.testimonial_items.length) return;
            var item = el.data.testimonial_items.splice(index, 1)[0];
            el.data.testimonial_items.splice(target, 0, item);
        },
        ensureAdvantageItems(el) {
            var defaults = JSON.parse(JSON.stringify(HOME_ADVANTAGE_DEFAULTS));
            if (!Array.isArray(el.data.advantage_items)) el.data.advantage_items = [];
            while (el.data.advantage_items.length < 4) el.data.advantage_items.push(defaults[el.data.advantage_items.length]);
            if (el.data.advantage_items.length > 4) el.data.advantage_items = el.data.advantage_items.slice(0, 4);
        },
        ensureProductCarousel(el) {
            if (!Array.isArray(el.data.product_ids)) el.data.product_ids = [];
            el.data.product_ids = el.data.product_ids.map(Number).filter(function(id, index, ids) {
                return id > 0 && ids.indexOf(id) === index;
            });
            el.data.per_row = Math.max(1, Math.min(6, Number(el.data.per_row) || 4));
            el.data.autoplay = Math.max(0, Math.min(30, Number(el.data.autoplay) || 0));
        },
        carouselProductName(id) {
            var product = HOME_PRODUCT_OPTIONS.find(function(item) { return Number(item.id) === Number(id); });
            return product ? product.title : ("#" + id);
        },
        addCarouselProduct(el, id) {
            id = Number(id);
            if (id > 0 && el.data.product_ids.map(Number).indexOf(id) === -1) el.data.product_ids.push(id);
        },
        removeCarouselProduct(el, index) { el.data.product_ids.splice(index, 1); },
        moveCarouselProduct(el, index, direction) {
            var target = index + direction;
            if (target < 0 || target >= el.data.product_ids.length) return;
            var item = el.data.product_ids.splice(index, 1)[0];
            el.data.product_ids.splice(target, 0, item);
        },
        ensureStatsItems(el) {
            var defaults = JSON.parse(JSON.stringify(HOME_STATS_DEFAULTS));
            if (!Array.isArray(el.data.stats_items)) el.data.stats_items = [];
            while (el.data.stats_items.length < 4) el.data.stats_items.push(defaults[el.data.stats_items.length]);
            if (el.data.stats_items.length > 4) el.data.stats_items = el.data.stats_items.slice(0, 4);
        },
        homeBlockSourceLabel(el) {
            var source = this.elementControls("home-block").find(function(ctrl) { return ctrl.key === "block_type"; });
            var type = String((el.data || {}).block_type || "");
            return source && source.options && source.options[type] ? source.options[type] : type;
        },

        // === 响应式三档（元素级）：值标量=全设备统一，{d,t,m}=分档；respDev 记录每个元素当前编辑档 ===
        respDev: {},
        respTab(el) { return this.respDev[el.id] || "d"; },
        setRespDev(el, dev) { this.respDev[el.id] = dev; },
        respIsSplit(el, key) { var v = el.data[key]; return !!v && typeof v === "object"; },
        respGet(el, key, dflt) {
            var v = el.data[key];
            if (v && typeof v === "object") return v[this.respTab(el)] || v.d || dflt;
            return v || dflt;
        },
        respSet(el, key, val, dflt) {
            var v = el.data[key];
            if (!v || typeof v !== "object") { v = { d: v || dflt, t: v || dflt, m: v || dflt }; }
            else { v = { d: v.d || dflt, t: v.t || dflt, m: v.m || dflt }; }
            v[this.respTab(el)] = val;
            // 全档一致折叠回标量，保持旧数据形态与渲染输出
            el.data[key] = (v.d === v.t && v.t === v.m) ? v.d : v;
        },

        // === 可复用块库（P2）：引用块 {library_id} 渲染时展开，改库一处全站生效 ===
        libItems: [],
        libPost(params) {
            var body = new URLSearchParams();
            for (var k in params) body.set(k, params[k]);
            return fetch(window.location.href, { method: "POST", body: body })
                .then(function(r) { return safeJson(r); });
        },
        libRefresh() {
            var self = this;
            this.libPost({ action: "lib_list" }).then(function(res) {
                if (res.code === 0) self.libItems = (res.data && res.data.items) || [];
            }).catch(function() {});
        },
        saveToLibrary(si) {
            var name = prompt("块名称（存入块库后可在其他页面复用）：");
            if (!name) return;
            var self = this;
            this.libPost({
                action: "lib_save",
                lib_name: name,
                section_data: JSON.stringify(this.sections[si])
            }).then(function(res) {
                if (res.code === 0) { showMessage("已存入块库"); self.libRefresh(); }
                else showMessage(res.msg, "error");
            }).catch(function() { showMessage("请求失败", "error"); });
        },
        insertLibRef(item) {
            this.sections.push({ id: this.uid("s"), library_id: item.id, library_name: item.name, settings: {}, columns: [] });
        },
        insertLibCopy(item) {
            var self = this;
            this.libPost({ action: "lib_get", lib_id: item.id }).then(function(res) {
                if (res.code !== 0) { showMessage(res.msg, "error"); return; }
                var sec = JSON.parse(res.data.item.data);
                self.sections.push(self.freshSection(sec));
                self.$nextTick(function() { self.initSortable(); });
            }).catch(function() { showMessage("请求失败", "error"); });
        },
        detachLibRef(si) {
            var s = this.sections[si];
            if (!s.library_id) return;
            var self = this;
            this.libPost({ action: "lib_get", lib_id: s.library_id }).then(function(res) {
                if (res.code !== 0) { showMessage(res.msg, "error"); return; }
                var sec = JSON.parse(res.data.item.data);
                self.sections.splice(si, 1, self.freshSection(sec));
                self.$nextTick(function() { self.initSortable(); });
            }).catch(function() { showMessage("请求失败", "error"); });
        },
        libDelete(item) {
            if (!confirm("从块库删除「" + item.name + "」？已引用它的页面该区块将不再显示")) return;
            var self = this;
            this.libPost({ action: "lib_delete", lib_id: item.id }).then(function(res) {
                if (res.code === 0) { showMessage("已删除"); self.libRefresh(); }
                else showMessage(res.msg, "error");
            }).catch(function() { showMessage("请求失败", "error"); });
        },

        // 深拷贝元素并递归刷新子元素 id，避免复制含 Banner 子项的模板后产生重复定位键。
        freshElement(e) {
            var self = this;
            var data = JSON.parse(JSON.stringify(e.data || {}));
            if (Array.isArray(data.children)) {
                data.children = data.children.map(function(child) { return self.freshElement(child); });
            }
            return { id: self.uid("e"), type: e.type, data: data };
        },

        // 深拷贝 section 并重生成各级 id（块库副本/模板插入共用）
        freshSection(s) {
            var self = this;
            s = JSON.parse(JSON.stringify(s));
            return {
                id: self.uid("s"),
                settings: s.settings || {},
                columns: (s.columns || []).map(function(c) {
                    var column = JSON.parse(JSON.stringify(c || {}));
                    column.id = self.uid("c");
                    column.elements = (c.elements || []).map(function(e) { return self.freshElement(e); });
                    return column;
                })
            };
        },

        init() {
            var self = this;
            this.savedSnapshot = JSON.stringify(this.sections);
            this.$nextTick(function() { self.initSortable(); });
            // 区块变化同时驱动未保存状态与实时预览。
            this.$watch("sections", function() {
                self.isDirty = JSON.stringify(self.sections) !== self.savedSnapshot;
                self.schedulePreview();
            });
            window.addEventListener("beforeunload", function(e) {
                if (!self.isDirty) return;
                e.preventDefault();
                e.returnValue = "";
            });
            document.addEventListener("keydown", function(e) {
                if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === "s") {
                    e.preventDefault();
                    document.getElementById("editForm").requestSubmit();
                }
            });
            // 前台就地编辑深链：?focus=N → 滚动到并高亮第 N 个区块
            this.$nextTick(function() { self.focusSection(); });
        },

        markSaved() {
            this.savedSnapshot = JSON.stringify(this.sections);
            this.isDirty = false;
        },

        isSectionOpen(id) { return this.openSections[id] === true; },
        toggleSection(id) {
            this.openSections[id] = !this.isSectionOpen(id);
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },
        expandAllSections() {
            for (var section of this.sections) this.openSections[section.id] = true;
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },
        collapseAllSections() { this.openSections = {}; },
        sectionElementCount(section) {
            return (section.columns || []).reduce(function(total, column) {
                return total + (column.elements || []).length;
            }, 0);
        },
        sectionColumnLabel(section) {
            for (var column of (section.columns || [])) {
                for (var el of (column.elements || [])) {
                    if (this.isHomeAboutBlock(el)) return "2 列";
                    if (this.isHomeStatsBlock(el)) return "4 列";
                    if (this.isProductCarouselBlock(el)) return (Math.max(1, Math.min(6, Number((el.data || {}).per_row) || 4))) + " 列";
                    if (this.isHomeChannelBlock(el)) return (Math.max(1, Math.min(8, Number((el.data || {}).per_row) || 4))) + " 列";
                    if (this.isHomeAdvantageBlock(el)) return "4 列";
                    if (this.isHomeTestimonialsBlock(el)) return "3 列";
                }
            }
            return (section.columns || []).length + " 列";
        },
        sectionLabel(section, si) {
            if (section.library_id) return section.library_name || ("#" + section.library_id);
            var title = String((section.settings || {}).title || "").trim();
            if (title) return title;
            for (var column of (section.columns || [])) {
                for (var el of (column.elements || [])) {
                    if (this.isHomeBlock(el)) return String((el.data || {}).label || this.homeBlockSourceLabel(el) || ("区块 " + (si + 1)));
                    if (el.type === "heading" && String((el.data || {}).text || "").trim()) return String(el.data.text).trim();
                }
            }
            return "区块 " + (si + 1);
        },

        // 定位 URL ?focus=N 指定的区块（前台悬停编辑跳转而来）
        focusSection() {
            var m = new URLSearchParams(location.search).get("focus");
            if (m === null || m === "") return;
            var el = document.querySelector("[data-si=\"" + parseInt(m, 10) + "\"]");
            if (!el) return;
            var section = this.sections[parseInt(m, 10)];
            if (section) this.openSections[section.id] = true;
            el.scrollIntoView({ behavior: "smooth", block: "center" });
            el.style.transition = "box-shadow .3s, border-color .3s";
            el.style.boxShadow = "0 0 0 4px rgba(37,99,235,.35)";
            el.style.borderColor = "#2563eb";
            setTimeout(function() { el.style.boxShadow = ""; el.style.borderColor = ""; }, 2200);
        },

        // === 区块操作 ===
        addSection(colCount) {
            var cols = [];
            for (var i = 0; i < colCount; i++) {
                cols.push({ id: this.uid("c"), elements: [] });
            }
            this.sections.push({
                id: this.uid("s"),
                settings: { bg_color: "", bg_image: "", padding: "md", max_width: "default", align_items: "stretch", justify_items: "stretch", gap: "lg" },
                columns: cols
            });
            this.openSections[this.sections[this.sections.length - 1].id] = true;
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        // === 预设库：区块预设/整页模板一键插入（重生成各级 id，避免与现有内容 key 冲突） ===
        showPresets: false,
        presetSections() { return BUILDER_PRESETS.sections || []; },
        presetPages() { return BUILDER_PRESETS.pages || []; },
        insertPreset(p) {
            var tpl = p && p.sections;
            if (!tpl || !tpl.length) return;
            var self = this;
            var fresh = tpl.map(function(s) { return self.freshSection(s); });
            if (this.sections.length > 0 && fresh.length > 1) {
                if (!confirm("将整页模板追加到当前内容末尾？")) return;
            }
            this.sections = this.sections.concat(fresh);
            for (var section of fresh) this.openSections[section.id] = true;
            this.showPresets = false;
            this.$nextTick(function() { self.initSortable(); });
        },

        // 隐藏/显示区块：前台不输出，编辑器里灰显保留，随时点回来。
        // 比「删掉再重建」友好得多——季节性内容、临时下架的活动区块都用得上。
        toggleSectionHidden(si) {
            var sec = this.sections[si];
            if (!sec.settings) sec.settings = {};
            if (sec.settings.hidden) {
                delete sec.settings.hidden;   // 显示态不留键，保持数据干净、与老数据一致
            } else {
                sec.settings.hidden = true;
            }
        },

        removeSection(si) {
            if (!confirm("确定删除此区块？")) return;
            this.sections.splice(si, 1);
        },

        moveSection(si, dir) {
            var ni = si + dir;
            if (ni < 0 || ni >= this.sections.length) return;
            var tmp = this.sections.splice(si, 1)[0];
            this.sections.splice(ni, 0, tmp);
        },

        openSettings(si) {
            _settingSi = si;
            var s = this.sections[si].settings;
            // 响应式三档：标量→全档同值展开；保存时全档一致会折叠回标量
            _respVals.padding = normResp(s.padding, "md");
            _respVals.gap = normResp(s.gap, "lg");
            setSettingDevice("d", true);
            document.getElementById("settingTitle").value = s.title || "";
            document.getElementById("settingSubtitle").value = s.subtitle || "";
            document.getElementById("settingMaxWidth").value = s.max_width || "default";
            document.getElementById("settingBgColorText").value = s.bg_color || "";
            document.getElementById("settingBgColor").value = s.bg_color || "#ffffff";
            document.getElementById("settingBgImage").value = s.bg_image || "";
            var opacity = s.bg_opacity !== undefined ? s.bg_opacity : 100;
            document.getElementById("settingBgOpacity").value = opacity;
            document.getElementById("settingBgOpacityVal").textContent = opacity + "%";
            document.getElementById("settingColCard").checked = !!s.col_card;
            setAlignItems(s.align_items || "stretch");
            setJustifyItems(s.justify_items || "stretch");
            document.querySelectorAll(".col-btn").forEach(function(b) { b.classList.remove("bg-primary", "text-white"); });
            var btn = document.querySelector(".col-btn[data-cols=\"" + this.sections[si].columns.length + "\"]");
            if (btn) btn.classList.add("bg-primary", "text-white");
            var m = document.getElementById("sectionSettingsModal");
            m.classList.remove("hidden"); m.classList.add("flex");
        },

        // === 元素操作 ===
        addElement(si, ci, type) {
            // 默认 data 来自元素 schema（BuilderRegistry），新增元素无需在此登记
            var meta = BUILDER_ELEMENTS[type] || {};
            var data = JSON.parse(JSON.stringify(meta.defaults || {}));
            if (type === "home-block") {
                data.items_mode = "inherit";
                data.children = [];
            }
            this.sections[si].columns[ci].elements.push({
                id: this.uid("e"),
                type: type,
                data: data
            });
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        removeElement(si, ci, ei) {
            this.sections[si].columns[ci].elements.splice(ei, 1);
        },

        moveElement(si, ci, ei, dir) {
            var els = this.sections[si].columns[ci].elements;
            var ni = ei + dir;
            if (ni < 0 || ni >= els.length) return;
            var tmp = els.splice(ei, 1)[0];
            els.splice(ni, 0, tmp);
        },

        editText(si, ci, ei) {
            _editingPath = { si: si, ci: ci, ei: ei };
            // 默认进源码模式：排版编辑器里的富文本多是既有 HTML，
            // 直接看源码比先渲染再猜结构更直观，也避免可视化编辑器改写标签。
            _textEditorMode = "source";
            var el = this.sections[si].columns[ci].elements[ei];
            var m = document.getElementById("textEditorModal");
            m.classList.remove("hidden"); m.classList.add("flex");
            if (!_modalEditor) {
                _modalEditor = initWangEditor("#modal-toolbar", "#modal-editor", {
                    placeholder: "请输入内容...",
                    html: el.data.html || "",
                    uploadUrl: "/admin/upload.php",
                    onChange: function() {}
                });
            } else {
                _modalEditor.setHtml(el.data.html || "<p><br></p>");
            }
            setTextEditorMode("source", el.data.html || "");
        },

        pickImage(si, ci, ei) {
            var self = this;
            openMediaPicker(function(url) {
                self.sections[si].columns[ci].elements[ei].data.src = url;
            });
        },

        pickSchemaImage(node, key) {
            openMediaPicker(function(url) { node.data[key] = url; });
        },

        pickObjectImage(item, key) {
            openMediaPicker(function(url) { item[key] = url; });
        },

        toggleHomeBanner(el) {
            this.homeBannerOpen[el.id] = !this.homeBannerOpen[el.id];
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        toggleHomeBannerItem(child) {
            this.homeBannerItemOpen[child.id] = !this.homeBannerItemOpen[child.id];
        },

        homeBannerIsCustom(el) {
            return !!(this.isHomeBannerBlock(el) && (el.data || {}).items_mode === "custom");
        },

        homeBannerCount(el) {
            return el && el.data && Array.isArray(el.data.children) ? el.data.children.length : 0;
        },

        adoptHomeBanner(el) {
            if (!this.isHomeBannerBlock(el)) return;
            var self = this;
            el.data.items_mode = "custom";
            el.data.children = this.homeBannerSeeds.map(function(item) {
                return { id: self.uid("e"), type: "home-banner-item", data: JSON.parse(JSON.stringify(item)) };
            });
            if (el.data.children.length === 0) this.addHomeBannerItem(el);
            this.homeBannerOpen[el.id] = true;
            if (el.data.children[0]) this.homeBannerItemOpen[el.data.children[0].id] = true;
            this.$nextTick(function() { self.initSortable(); });
        },

        addHomeBannerItem(el) {
            if (!this.isHomeBannerBlock(el)) return;
            el.data.items_mode = "custom";
            el.data.children = Array.isArray(el.data.children) ? el.data.children : [];
            var defaults = JSON.parse(JSON.stringify(((BUILDER_ELEMENTS["home-banner-item"] || {}).defaults || {})));
            defaults.title = defaults.title || HOME_BANNER_TEXT.newTitle;
            var child = { id: this.uid("e"), type: "home-banner-item", data: defaults };
            el.data.children.push(child);
            this.homeBannerOpen[el.id] = true;
            this.homeBannerItemOpen[child.id] = true;
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        restoreHomeBanner(el) {
            if (!this.isHomeBannerBlock(el)) return;
            if (this.homeBannerCount(el) && !confirm(HOME_BANNER_TEXT.restoreConfirm)) return;
            el.data.items_mode = "inherit";
            el.data.children = [];
            this.homeBannerOpen[el.id] = false;
        },

        moveHomeBannerItem(el, index, direction) {
            var children = el.data.children || [];
            var next = index + direction;
            if (next < 0 || next >= children.length) return;
            var item = children.splice(index, 1)[0];
            children.splice(next, 0, item);
        },

        removeHomeBannerItem(el, index) {
            (el.data.children || []).splice(index, 1);
        },

        findElementById(id) {
            for (var section of this.sections) {
                for (var column of (section.columns || [])) {
                    for (var el of (column.elements || [])) {
                        if (el.id === id) return el;
                    }
                }
            }
            return null;
        },

        // === SortableJS ===
        initSortable() {
            var self = this;
            var container = this.$refs.sectionsContainer;
            if (container) {
                if (container._sortable) container._sortable.destroy();
                container._sortable = new Sortable(container, {
                    handle: ".section-drag-handle",
                    animation: 150,
                    ghostClass: "opacity-30",
                    onEnd: function(evt) {
                        var item = self.sections.splice(evt.oldIndex, 1)[0];
                        self.sections.splice(evt.newIndex, 0, item);
                    }
                });
            }
            this.$nextTick(function() {
                document.querySelectorAll("[data-sortable-elements]").forEach(function(el) {
                    if (el._sortable) el._sortable.destroy();
                    el._sortable = new Sortable(el, {
                        group: "elements",
                        handle: ".element-drag-handle",
                        animation: 150,
                        ghostClass: "opacity-30",
                        filter: ".add-element-btn",
                        onEnd: function(evt) {
                            var fromSi = parseInt(evt.from.dataset.sectionIndex);
                            var fromCi = parseInt(evt.from.dataset.columnIndex);
                            var toSi = parseInt(evt.to.dataset.sectionIndex);
                            var toCi = parseInt(evt.to.dataset.columnIndex);
                            var item = self.sections[fromSi].columns[fromCi].elements.splice(evt.oldIndex, 1)[0];
                            self.sections[toSi].columns[toCi].elements.splice(evt.newIndex, 0, item);
                        }
                    });
                });
                document.querySelectorAll("[data-sortable-home-banner]").forEach(function(el) {
                    if (el._sortable) el._sortable.destroy();
                    el._sortable = new Sortable(el, {
                        handle: ".home-banner-drag-handle",
                        draggable: "[data-home-banner-item]",
                        animation: 150,
                        ghostClass: "opacity-30",
                        onEnd: function(evt) {
                            var host = self.findElementById(el.dataset.bannerParentId || "");
                            if (!host || !host.data || !Array.isArray(host.data.children)) return;
                            var oldIndex = evt.oldDraggableIndex === undefined ? evt.oldIndex : evt.oldDraggableIndex;
                            var newIndex = evt.newDraggableIndex === undefined ? evt.newIndex : evt.newDraggableIndex;
                            var item = host.data.children.splice(oldIndex, 1)[0];
                            host.data.children.splice(newIndex, 0, item);
                        }
                    });
                });
            });
        }
    };
}

// === 表单提交 ===
document.getElementById("editForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    window.dispatchEvent(new CustomEvent("layout-save-started"));
    var formData = new FormData(this);
    var msgEl = document.getElementById("saveMsg");
    try {
        var response = await fetch("", { method: "POST", body: formData });
        var result = await safeJson(response);
        if (result.code === 0) {
            // 首页草稿：把基线推进到本次保存的时间戳，否则连续保存会被自己的旧基线判成冲突
            var baseEl = this.querySelector("input[name=home_base_updated_at]");
            if (baseEl && result.data && result.data.home_base_updated_at) {
                baseEl.value = result.data.home_base_updated_at;
            }
            msgEl.textContent = "保存成功";
            msgEl.className = "text-sm text-green-600";
            window.dispatchEvent(new CustomEvent("layout-saved"));
        } else {
            msgEl.textContent = result.msg || "保存失败";
            msgEl.className = "text-sm text-red-600";
        }
    } catch (err) {
        msgEl.textContent = "请求失败";
        msgEl.className = "text-sm text-red-600";
    }
    window.dispatchEvent(new CustomEvent("layout-save-finished"));
    setTimeout(function() { msgEl.className = "text-sm hidden"; }, 3000);
});

// === 首页发布 / 回退（仅首页模式存在这两个按钮）===
(function () {
    var pubBtn = document.getElementById("homePublishBtn");
    var backBtn = document.getElementById("homeRollbackBtn");
    if (!pubBtn && !backBtn) return;
    var form = document.getElementById("editForm");
    var msgEl = document.getElementById("homeActionMsg");

    function say(text, ok) {
        msgEl.textContent = text;
        msgEl.className = "text-xs " + (ok ? "text-green-700" : "text-red-600");
    }

    async function run(action, confirmText, btn) {
        if (confirmText && !confirm(confirmText)) return;
        btn.disabled = true;
        try {
            // 发布前先把当前编辑内容存成草稿，避免发布的是上一次保存的旧版本
            if (action === "publish") {
                var saveBody = new FormData(form);
                var saveResp = await fetch("", { method: "POST", body: saveBody });
                var saveResult = await safeJson(saveResp);
                if (saveResult.code !== 0) { say(saveResult.msg || "保存失败", false); return; }
                var baseEl = form.querySelector("input[name=home_base_updated_at]");
                if (baseEl && saveResult.data && saveResult.data.home_base_updated_at) {
                    baseEl.value = saveResult.data.home_base_updated_at;
                }
            }
            // CSRF 由 footer 的 fetch 包装器自动附加到 FormData
            var body = new FormData();
            body.append("home_action", action);
            var resp = await fetch("", { method: "POST", body: body });
            var result = await safeJson(resp);
            if (result.code === 0) {
                say(action === "publish" ? "已发布到线上首页" : "已回退到旧首页", true);
                setTimeout(function () { location.reload(); }, 900);
            } else {
                say(result.msg || "操作失败", false);
            }
        } catch (err) {
            say("请求失败", false);
        } finally {
            btn.disabled = false;
        }
    }

    if (pubBtn) pubBtn.addEventListener("click", function () { run("publish", "确定用当前排版替换线上首页？", pubBtn); });
    if (backBtn) backBtn.addEventListener("click", function () { run("rollback", "确定回退到旧版首页？草稿会保留。", backBtn); });
})();

</scr' . 'ipt>';

// 历史版本面板（仅已保存单页）
if ($id > 0) {
    // 供 require 的 revision_panel.php 使用（豁免见 psalm.xml issueHandlers）
    $revType = 'page';
    $revTargetId = (int) $id;
    require ROOT_PATH . '/admin/includes/revision_panel.php';
}

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
