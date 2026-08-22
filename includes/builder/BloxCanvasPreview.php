<?php
/** Shared Blox canvas preview response. */

declare(strict_types=1);

/**
 * 不写 `: never`——那是 PHP 8.1 才有的类型，而本项目承诺支持 8.0
 * （8.0 会把它当成一个不存在的类名，Psalm 也会如实报 UndefinedClass）。
 */
function outputBloxCanvasPreview(bool $isHomeLayout, int $id): void
{
    // blox=1：Blox 画布请求。开编辑上下文让渲染器输出 data-yk-sec 定位标记，
    // 并注入点选/高亮/空区块占位脚本；排版编辑器的纯预览不带此参数，输出不变。
    $bloxCanvas = (($_POST['blox'] ?? '') === '1');
    // 编辑器预览/画布里隐藏的区块照常显示（灰显标注），否则一隐藏就从画布消失、没法再点回来
    require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    BloxQueryLoopPolicy::assertJsonAllowed((string) ($_POST['blocks_data'] ?? '[]'));
    BloxDisplayConditions::assertJsonAllowed((string) ($_POST['blocks_data'] ?? '[]'));
    BlockRenderer::$showHidden = true;
    if ($bloxCanvas) {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        // 首页没有真实 channel id，但编辑态仍需要一个非零标记开关输出 data-yk-* 定位属性。
        BlockRenderer::$editChannelId = $isHomeLayout ? 1 : $id;
    }
    // 头尾模板画布：可编辑模板段 + 正文只读上下文（灰罩不可选）。
    $templateArea = (string) ($_GET['template_area'] ?? '');
    if ($isHomeLayout && in_array($templateArea, ['header', 'footer'], true)) {
        $previewJson = (string) ($_POST['blocks_data'] ?? '[]');
        $previewDocument = BloxAreaDocument::decode($templateArea, $previewJson);
        $editableArea = BloxAreaDocument::renderShell(
            $templateArea,
            $previewDocument['settings'],
            BlockRenderer::render($previewJson),
            (string) ($_POST['header_state'] ?? '')
        );

        // r9 上下文选择器：preview_context=home|channel:<id>|page:<id>。
        // 显式 DTO 解析，fail-closed（非法/查无此栏目 → 回退首页），不从全局变量猜上下文。
        $ctxType = 'home';
        $ctxRow = null;
        if (preg_match('/^(channel|page):(\d+)$/', (string) ($_GET['preview_context'] ?? ''), $cm)) {
            $ctxRow = channelModel()->findWhere(['id' => (int) $cm[2]]);
            if ($ctxRow && (string) $ctxRow['type'] !== 'redirect') {
                $ctxType = $cm[1];
            } else {
                $ctxRow = null;
            }
        }

        // 上下文正文：无编辑标记（editChannelId 归零后再渲染），画布上不可点选
        $savedEditChannel = BlockRenderer::$editChannelId;
        BlockRenderer::$editChannelId = 0;
        if ($ctxType === 'page' && $ctxRow !== null) {
            // 单页近似：优先该页排版数据（与前台/编辑器同源取法），回退富文本 content
            $pageContent = contentModel()->queryOne(
                'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 ORDER BY is_top DESC, id DESC LIMIT 1',
                [(int) $ctxRow['id']]
            );
            $inner = trim((string) ($pageContent['blocks_data'] ?? '')) !== ''
                ? renderBlocksToHtml((string) $pageContent['blocks_data'])
                : (string) ($ctxRow['content'] ?? ($pageContent['content'] ?? ''));
            $contextBody = '<div class="max-w-7xl mx-auto px-4 py-12"><h1 class="text-3xl font-bold mb-8">'
                . htmlspecialchars((string) $ctxRow['name'], ENT_QUOTES) . '</h1>' . $inner . '</div>';
        } elseif ($ctxType === 'channel' && $ctxRow !== null) {
            // 栏目近似：栏目名 + 最近内容标题列表（灰罩只读示意，不复刻主题列表版式）
            $items = contentModel()->getList((int) $ctxRow['id'], 6, 0, ['_skip_lang' => 1]);
            $lis = '';
            foreach ($items as $it) {
                $lis .= '<li class="border-b border-gray-100 py-3">' . htmlspecialchars((string) ($it['title'] ?? ''), ENT_QUOTES) . '</li>';
            }
            $contextBody = '<div class="max-w-7xl mx-auto px-4 py-12"><h1 class="text-3xl font-bold mb-8">'
                . htmlspecialchars((string) $ctxRow['name'], ENT_QUOTES) . '</h1><ul>'
                . ($lis !== '' ? $lis : '<li class="py-3 text-gray-400">…</li>') . '</ul></div>';
        } else {
            $homeDoc = HomeBloxDocument::load();
            $ctxContext = HomeBloxRenderContext::fromCurrentSite(false);
            $contextBody = HomeBloxRenderer::render($homeDoc['sections'], [$ctxContext, 'renderLegacyBlock']);
        }
        BlockRenderer::$editChannelId = $savedEditChannel;

        // 命中报告：按所选上下文跑与前台 bloxAreaHtml() 同一套 Resolver 评分，
        // 报告该上下文线上实际激活哪个已发布模板——保证「预览命中 = 线上命中」。
        $ctxHitId = 0;
        if (db()->tableExists('blox_templates')) {
            $areaTemplates = bloxTemplateModel()->publishedAreaTemplates($templateArea);
            $resolveHit = $areaTemplates === [] ? null : BloxAreaResolver::resolve($areaTemplates, [
                'home' => $ctxType === 'home',
                'channel_id' => $ctxType === 'channel' ? (int) ($ctxRow['id'] ?? 0) : 0,
                'page_id' => $ctxType === 'page' ? (int) ($ctxRow['id'] ?? 0) : 0,
            ]);
            $ctxHitId = (int) ($resolveHit['id'] ?? 0);
        }

        // data-yk-area：区域契约标记——画布侧点选/拖放的作用域边界（编辑器据此圈定可编辑区）
        $editableArea = '<div data-yk-area="' . htmlspecialchars($templateArea, ENT_QUOTES) . '"'
            . ' data-yk-ctx-hit="' . $ctxHitId . '">' . $editableArea . '</div>';
        $dim = '<div class="yk-ctx-dim" aria-hidden="true">' . $contextBody . '</div>';
        $body = $templateArea === 'header' ? $editableArea . $dim : $dim . $editableArea;
    } elseif ($isHomeLayout) {
        $previewSections = json_decode((string) ($_POST['blocks_data'] ?? '[]'), true);
        if (is_array($previewSections) && isset($previewSections['sections']) && is_array($previewSections['sections'])) {
            $previewSections = $previewSections['sections'];
        }
        $previewSections = is_array($previewSections) ? $previewSections : [];
        $homePreviewContext = HomeBloxRenderContext::fromCurrentSite($bloxCanvas);
        $homeBody = HomeBloxRenderer::render($previewSections, [$homePreviewContext, 'renderLegacyBlock']);

        // 首页画布同时展示当前生效的 Blox 页头/页尾，帮助管理员判断首屏和整页比例。
        // 它们是只读上下文，不进入首页 sections，也不参与首页保存；头尾模板仍在各自模板编辑器中修改。
        $renderPublishedArea = static function (string $area): string {
            if (!in_array($area, ['header', 'footer'], true)) {
                return '';
            }
            if (!db()->tableExists('blox_templates')) {
                return '';
            }
            $templates = bloxTemplateModel()->publishedAreaTemplates($area);
            $resolved = $templates === [] ? null : BloxAreaResolver::resolve($templates, [
                'home' => true,
                'channel_id' => 0,
                'page_id' => 0,
            ]);
            if ($resolved === null) {
                return '';
            }
            $publishedData = (string) ($resolved['published_data'] ?? '');
            if ($publishedData === '') {
                return '';
            }
            try {
                $document = BloxAreaDocument::decode($area, $publishedData);
                $html = BlockRenderer::render($publishedData);
                if ($html === '') {
                    return '';
                }
                return BloxAreaDocument::renderShell($area, $document['settings'], $html);
            } catch (Throwable $e) {
                error_log('[bloxCanvasPreview] area context: ' . $e->getMessage());
                return '';
            }
        };

        // 自定义区域未启用或没有命中时，画布仍需显示当前主题的默认头尾，
        // 否则管理员看到的页面比例会与前台不一致。这里只截取主题布局的 body 区域，
        // 不把主题的 html/head/main 外壳嵌入预览 iframe。
        /** @psalm-suppress UnusedVariable 本闭包内变量均供 require 的主题布局模板使用 */
        $renderThemeArea = static function (string $area): string {
            $layout = theme_path_optional('layouts/' . $area . '.php');
            if ($layout === null || !is_file($layout)) {
                return '';
            }
            $pageTitle = __('home');
            $pageDescription = '';
            $pageKeywords = '';
            $canonicalUrl = siteBaseUrl() . '/';
            $ogType = 'website';
            $ogImage = '';
            $jsonLd = [];
            $extraCss = '';
            $extraJs = '';
            $savedScriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            $savedChannelId = $GLOBALS['currentChannelId'] ?? null;
            $savedPageId = $GLOBALS['ykBloxPageId'] ?? null;
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $GLOBALS['currentChannelId'] = 0;
            $GLOBALS['ykBloxPageId'] = 0;
            ob_start();
            try {
                require $layout;
                $rendered = (string) ob_get_clean();
            } catch (Throwable $e) {
                ob_end_clean();
                error_log('[bloxCanvasPreview] theme ' . $area . ': ' . $e->getMessage());
                $rendered = '';
            } finally {
                $_SERVER['SCRIPT_NAME'] = $savedScriptName;
                if ($savedChannelId === null) {
                    unset($GLOBALS['currentChannelId']);
                } else {
                    $GLOBALS['currentChannelId'] = $savedChannelId;
                }
                if ($savedPageId === null) {
                    unset($GLOBALS['ykBloxPageId']);
                } else {
                    $GLOBALS['ykBloxPageId'] = $savedPageId;
                }
            }
            if ($rendered === '') {
                return '';
            }
            if ($area === 'header') {
                $bodyStart = stripos($rendered, '<body');
                $bodyEnd = $bodyStart === false ? false : strpos($rendered, '>', $bodyStart);
                $mainStart = $bodyEnd === false ? false : stripos($rendered, '<main', $bodyEnd);
                return $bodyEnd !== false && $mainStart !== false
                    ? substr($rendered, $bodyEnd + 1, $mainStart - $bodyEnd - 1)
                    : '';
            }
            $footerStart = stripos($rendered, '<footer');
            $footerEnd = $footerStart === false ? false : stripos($rendered, '</footer>', $footerStart);
            return $footerStart !== false && $footerEnd !== false
                ? substr($rendered, $footerStart, $footerEnd + strlen('</footer>') - $footerStart)
                : '';
        };

        $wrapContextArea = static function (string $area, string $html, string $source, string $editUrl): string {
            if ($html === '') {
                return '';
            }
            $label = $area === 'header' ? __('site_design_area_header') : __('site_design_area_footer');
            $label .= ' · ' . ($source === 'theme' ? __('blox_preview_theme_default') : __('blox_preview_readonly'));
            $editLabel = $area === 'header' ? __('blox_context_edit_header') : __('blox_context_edit_footer');
            $icon = $area === 'header' ? 'ti-layout-navbar' : 'ti-layout-bottombar';
            return '<div class="yk-home-context-area" data-yk-preview-label="'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '">'
                . '<a class="yk-home-context-edit" data-yk-area-edit="' . $area
                . '" data-testid="blox-context-edit-' . $area . '" href="'
                . htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') . '" target="_top" aria-label="'
                . htmlspecialchars($editLabel, ENT_QUOTES, 'UTF-8') . '"><i class="ti ' . $icon
                . '" aria-hidden="true"></i><span>' . htmlspecialchars($editLabel, ENT_QUOTES, 'UTF-8') . '</span></a>'
                . $html . '</div>';
        };

        $savedEditChannel = BlockRenderer::$editChannelId;
        BlockRenderer::$editChannelId = 0;
        $headerEnabled = (string) config('blox_custom_header_enabled', '1') === '1';
        $footerEnabled = (string) config('blox_custom_footer_enabled', '1') === '1';
        $homeAreaContext = ['home' => true, 'channel_id' => 0, 'page_id' => 0];
        $headerBlox = $headerEnabled ? $renderPublishedArea('header') : '';
        $footerBlox = $footerEnabled ? $renderPublishedArea('footer') : '';
        $headerBody = $wrapContextArea(
            'header',
            $headerBlox !== '' ? $headerBlox : $renderThemeArea('header'),
            $headerBlox !== '' ? 'blox' : 'theme',
            BloxAreaEditorTarget::url('header', $homeAreaContext, 'home')
        );
        $footerBody = $wrapContextArea(
            'footer',
            $footerBlox !== '' ? $footerBlox : $renderThemeArea('footer'),
            $footerBlox !== '' ? 'blox' : 'theme',
            BloxAreaEditorTarget::url('footer', $homeAreaContext, 'home')
        );
        BlockRenderer::$editChannelId = $savedEditChannel;
        $body = $headerBody . $homeBody . $footerBody;
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
.yk-home-context-area{position:relative;pointer-events:none;user-select:none;opacity:.86;border-top:1px dashed #cbd5e1;border-bottom:1px dashed #cbd5e1}
.yk-home-context-area:before{content:attr(data-yk-preview-label);position:absolute;z-index:40;top:6px;left:8px;padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;background:rgba(248,250,252,.94);color:#64748b;font:600 10px/1.4 system-ui,sans-serif;letter-spacing:0;pointer-events:none}
.yk-home-context-edit{position:absolute;z-index:60;top:6px;right:8px;display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border:1px solid #2563eb;border-radius:4px;background:#2563eb;color:#fff!important;font:600 11px/1.4 system-ui,sans-serif;text-decoration:none!important;pointer-events:auto;user-select:none;box-shadow:0 2px 8px rgba(37,99,235,.24)}
.yk-home-context-edit:hover,.yk-home-context-edit:focus{background:#1d4ed8;border-color:#1d4ed8;outline:2px solid rgba(147,197,253,.9);outline-offset:2px}
[data-yk-hide-on]{position:relative}
[data-yk-hide-on]:before{content:'\2298 ' attr(data-yk-hide-on);position:absolute;z-index:28;top:4px;left:4px;padding:2px 7px;border-radius:4px;background:#64748b;color:#fff;font:700 10px/1.4 system-ui,sans-serif;pointer-events:none;opacity:.85}
[data-yk-conditions]{position:relative}
[data-yk-conditions]:after{content:'\2699 ' attr(data-yk-conditions);position:absolute;z-index:29;top:4px;right:4px;padding:2px 7px;border-radius:4px;background:#7c3aed;color:#fff;font:700 10px/1.4 system-ui,sans-serif;pointer-events:none;opacity:.9}
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
.yk-insert-rail{position:absolute;top:-14px;left:0;right:0;height:28px;z-index:35;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .12s ease;pointer-events:auto}
.yk-insert-rail:hover{opacity:1}
.yk-insert-rail:before{content:'';position:absolute;left:8px;right:8px;top:50%;height:2px;background:#2563eb;border-radius:999px;transform:translateY(-50%)}
.yk-insert-rail .yk-insert-btn{position:relative;z-index:1;width:26px;height:26px;border-radius:999px;border:none;background:#2563eb;color:#fff;font:700 15px/1 system-ui,sans-serif;cursor:pointer;box-shadow:0 2px 8px rgba(37,99,235,.35)}
.yk-insert-pop{position:fixed;z-index:2147483644;display:flex;align-items:center;gap:6px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:8px;box-shadow:0 10px 30px rgba(15,23,42,.16)}
.yk-insert-pop-btn{display:flex;align-items:center;justify-content:center;min-width:44px;height:36px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;transition:border-color .12s}
.yk-insert-pop-btn:hover{border-color:#2563eb}
.yk-insert-pop-bars{display:flex;gap:2px}
.yk-insert-pop-bars i{width:7px;height:18px;border-radius:2px;background:#cbd5e1}
.yk-insert-pop-btn:hover .yk-insert-pop-bars i{background:#2563eb}
.yk-insert-pop-tpl{padding:0 12px;font:500 12px/1 system-ui,sans-serif;color:#475569}
.yk-empty-doc{margin:48px auto;max-width:520px;padding:48px 24px}
.yk-empty-doc p{margin:0 0 18px;font-size:14px}
.yk-empty-btn{display:inline-flex;align-items:center;margin:0 6px;padding:7px 16px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#475569;font:500 13px/1.4 system-ui,sans-serif;cursor:pointer;transition:border-color .15s,color .15s}
.yk-empty-btn:hover{border-color:#94a3b8;color:#1e293b}
.yk-empty-btn-primary{border-color:#2563eb;background:#2563eb;color:#fff}
.yk-empty-btn-primary:hover{border-color:#1d4ed8;background:#1d4ed8;color:#fff}
.yk-empty-hint .yk-empty-btn{margin-left:8px;padding:4px 12px;font-size:12px}
.yk-empty-doc .yk-empty-btn{margin:0 6px;padding:7px 16px;font-size:13px}
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

    // Report the published header/footer template IDs matched by the current preview context.
    var ykAreaHost = document.querySelector('[data-yk-area][data-yk-ctx-hit]');
    if (ykAreaHost) {
        postToEditor({ ykAreaHit: parseInt(ykAreaHost.getAttribute('data-yk-ctx-hit'), 10) || 0 });
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
            'custom_title', 'custom_subtitle',
            'override_title', 'override_description', 'override_content', 'override_image',
            'override_tag_title', 'override_tag_description', 'override_button_text'
        ].indexOf(field) !== -1;
        var nested = /^(?:stats_items\.[0-3]\.(?:icon|number|label)|advantage_items\.[0-3]\.(?:icon|title|description))$/.test(field);
        var customNested = /^custom_overrides\.[a-zA-Z0-9_]+\.\d+\.columns\.\d+\.(?:card_bg|elements\.\d+\.data\.(?:text|html|url|accordion_items\.\d+\.(?:question|answer)))$/.test(field);
        if (pathParts(path).length < 3 || (!topLevel && !nested && !customNested)) return null;
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
            label.textContent = payload.field === 'subtitle' ? @@pea_subtitle@@ : @@pea_section_title@@;
        } else if (payload.kind === 'homeField') {
            label.textContent = @@pea_home_field@@;
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
        label.textContent = @@pea_text_edit@@;
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
        var contextEdit = e.target.closest('.yk-home-context-edit');
        if (contextEdit) {
            e.preventDefault();
            postToEditor({ ykEditArea: {
                area: contextEdit.getAttribute('data-yk-area-edit') || '',
                url: contextEdit.getAttribute('href') || ''
            } });
            return;
        }
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
        if (!s) {
            // 点空白 = 取消选择（Bricks/Elementor 同款 UX）：清本地描边并通知编辑器
            document.querySelectorAll('.yk-selected, .yk-con-selected, .yk-col-selected, .yk-el-selected').forEach(function (node) {
                node.classList.remove('yk-selected', 'yk-con-selected', 'yk-col-selected', 'yk-el-selected');
            });
            postToEditor({ ykClear: true });
            return;
        }
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
            if (homeField.getAttribute('data-yk-home-inline') !== '0'
                && homeTarget.field !== 'override_image' && !homeTarget.field.endsWith('.icon') && beginInlineEdit(homeField, {
                kind: 'homeField', path: homeTarget.path, field: homeTarget.field, format: 'text'
            }, ['custom_subtitle', 'override_description', 'override_content'].indexOf(homeTarget.field) === -1
                && !homeTarget.field.endsWith('.answer'))) {
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
            var bannerNode = null;
            if (typeof d.ykBannerPath === 'string' && /^\d+\.\d+\.\d+$/.test(d.ykBannerPath)) {
                var bannerHost = document.querySelector('[data-yk-el="' + cssEscape(d.ykBannerPath) + '"]');
                bannerNode = bannerHost ? bannerHost.querySelector('.banner-swiper') : null;
            }
            if (!bannerNode) bannerNode = document.querySelector('.banner-swiper');
            if (window.BloxBanner && typeof window.BloxBanner.show === 'function') {
                window.BloxBanner.show(bannerNode, d.ykBannerSlide);
            } else {
                var bannerSwiper = bannerNode ? (bannerNode.bloxBanner || bannerNode.swiper) : null;
                var bannerSlides = bannerNode ? bannerNode.querySelectorAll('.swiper-wrapper > .swiper-slide').length : 0;
                if (bannerSwiper && bannerSlides > 0 && typeof bannerSwiper.slideTo === 'function') {
                    bannerSwiper.slideTo(Math.min(bannerSlides - 1, Math.max(0, d.ykBannerSlide)), 0);
                }
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
        label.textContent = field === 'subtitle' ? @@pea_subtitle@@ : @@pea_section_title@@;
        syncOverlay();
        startOverlayTracking();
    }
    function highlightHomeField(path, field) {
        clearLayerSelections();
        activeEl = document.querySelector(
            '[data-yk-home-path="' + cssEscape(path) + '"]' +
            '[data-yk-home-field="' + cssEscape(field) + '"]'
        );
        label.textContent = @@pea_home_field@@;
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
            ? (activeEl.getAttribute('data-yk-home-column-label') || @@pea_column@@)
            : @@pea_column@@;
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
    // r14 具名拒因：{valid, reason}——reason 随 drop 上报编辑器 toast（craft.js 的
    // onError 思想：拒绝要告诉用户为什么，不再是红线一闪的静默失败）
    function dropTargetVerdict(target) {
        if (!ykDragRules || !ykDragType) return { valid: true }; // 规则未下发时不拦（编辑器端仍会校验）
        var draggedIsContainer = !!(ykDragRules.isContainer || {})[ykDragType];
        if (target.kind === 'element') {
            var parts = pathParts(target.path);
            if (parts.length >= 4) { // 目标在容器内：插入的是该容器的子元素
                var parentNode = document.querySelector('[data-yk-el="' + parts.slice(0, 3).join('.') + '"]');
                var parentType = parentNode ? (parentNode.getAttribute('data-yk-el-type') || '') : '';
                var allowed = (ykDragRules.containers || {})[parentType];
                if (Array.isArray(allowed)) {
                    if (allowed.indexOf(ykDragType) !== -1) return { valid: true };
                    if (allowed.indexOf('*') !== -1 && !draggedIsContainer && (ykDragRules.generic || {})[ykDragType] !== false) return { valid: true };
                    return { valid: false, reason: 'restricted-children' };
                }
                if (draggedIsContainer) return { valid: false, reason: 'no-nested-container' };
            }
        }
        return { valid: true }; // 列级/顶级元素前后：任何元素均可
    }
    function dropTargetValid(target) {
        return dropTargetVerdict(target).valid;
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
        if (!target) return;
        var verdict = dropTargetVerdict(target);
        if (!verdict.valid) {
            postToEditor({ ykDropRejected: verdict.reason || 'invalid' });
            return;
        }
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
        if (window.BloxBanner && typeof window.BloxBanner.init === 'function') {
            window.BloxBanner.init(root || document);
            return;
        }
        if (typeof window.Swiper !== 'function') return;
        contentNodes(root, '.banner-swiper').forEach(function (node) {
            if (node.bloxBanner || node.swiper) return;
            node.bloxBanner = new window.Swiper(node, {
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
    function emptyActionButton(action, label, primary) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'yk-empty-btn' + (primary ? ' yk-empty-btn-primary' : '');
        b.textContent = label;
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            postToEditor({ ykEmptyAction: action });
        });
        return b;
    }
    function quickAddButton(target) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'yk-empty-btn yk-empty-btn-primary';
        b.textContent = @@pea_add_element@@;
        b.setAttribute('data-yk-quick-add', target.kind + ':' + (target.path || (target.sec + '.' + target.col)));
        b.addEventListener('click', function (e) {
            e.stopPropagation();
            postToEditor({ ykQuickAdd: target });
        });
        return b;
    }
    function setupEmptyDocHint() {
        // 文档级空态：一个区块都没有时画布纯空白，给「从零搭建 / 模板库起步」双入口。
        // 每次预览更新先清旧卡再按需重建（删光区块要出现、插入内容要消失）。
        var exist = document.querySelector('.yk-empty-doc');
        if (exist) exist.remove();
        if (document.querySelectorAll('[data-yk-sec]').length > 0) return;
        var host = document.querySelector('[data-yk-area]') || document.body;
        var d = document.createElement('div');
        d.className = 'yk-empty-hint yk-empty-doc';
        var p = document.createElement('p');
        p.textContent = @@pea_canvas_empty@@;
        d.appendChild(p);
        if (@@templates_enabled@@) {
            d.appendChild(emptyActionButton('templates', @@pea_import_template@@, true));
        }
        d.appendChild(emptyActionButton('section', @@pea_add_blank_section@@, !@@templates_enabled@@));
        host.appendChild(d);
    }
    function setupEmptyHints(root) {
        // 辅助节点只存在于画布。空列/空容器的动作通过 bridge 定位到文档节点，
        // 再复用编辑器元素库和统一插入命令。
        contentNodes(root, '[data-yk-col]').forEach(function (c) {
            if ((c.innerText || '').trim() !== '') return;
            if (c.querySelector('img,svg,iframe,video,picture,[data-yk-el]')) return;
            var parts = (c.getAttribute('data-yk-col') || '').split('.').map(function (v) { return parseInt(v, 10); });
            if (parts.length !== 2 || parts.some(function (v) { return isNaN(v); })) return;
            var d = document.createElement('div');
            d.className = 'yk-empty-hint yk-empty-hint-sm';
            d.textContent = @@pea_empty_column@@;
            d.appendChild(quickAddButton({ kind: 'column', sec: parts[0], col: parts[1] }));
            c.appendChild(d);
        });
        contentNodes(root, '.yk-container, .yk-div').forEach(function (c) {
            if ((c.innerText || '').trim() !== '') return;
            if (c.querySelector('img,svg,iframe,video,picture')) return;
            var wrapper = c.closest('[data-yk-el]');
            var path = wrapper ? (wrapper.getAttribute('data-yk-el') || '') : '';
            if (!/^\d+\.\d+\.\d+(?:\.\d+)?$/.test(path)) return;
            var d = document.createElement('div');
            d.className = 'yk-empty-hint yk-empty-hint-sm';
            d.textContent = c.classList.contains('yk-div') ? @@pea_empty_div@@ : @@pea_empty_container@@;
            d.appendChild(quickAddButton({ kind: 'container', path: path }));
            c.appendChild(d);
        });
        contentNodes(root, '[data-yk-sec]').forEach(function (sec) {
            if (sec.querySelector('.yk-container, .yk-div')) return;
            if ((sec.innerText || '').trim() !== '') return;
            if (sec.querySelector('img,svg,iframe,video,picture')) return;
            if (sec.querySelector('.yk-empty-hint')) return; // 预览局部补丁重跑时防重复
            var n = parseInt(sec.getAttribute('data-yk-sec'), 10) + 1;
            var d = document.createElement('div');
            d.className = 'yk-empty-hint';
            d.textContent = @@pea_empty_section@@.replace(':n', n);
            d.appendChild(quickAddButton({ kind: 'column', sec: n - 1, col: 0 }));
            if (@@templates_enabled@@) {
                d.appendChild(emptyActionButton('templates', @@pea_import_template@@, false));
            }
            sec.appendChild(d);
        });
        setupEmptyDocHint();
        setupInsertRails();
    }
    // ── r13 画布就地添加：section 边界插入轨道（VvvebJs NewSection 机制的 bridge 版）──
    // 辅助节点只存在于画布，不进保存文档；动作经 ykInsertAt postMessage 白名单出画布。
    function insertPopover(index, anchorRect) {
        var old = document.querySelector('.yk-insert-pop');
        if (old) old.remove();
        var pop = document.createElement('div');
        pop.className = 'yk-insert-pop';
        var layouts = [[12], [6, 6], [4, 4, 4], [3, 3, 3, 3]];
        layouts.forEach(function (spans) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'yk-insert-pop-btn';
            b.title = @@pea_n_columns@@.replace(':n', spans.length);
            var bars = document.createElement('span');
            bars.className = 'yk-insert-pop-bars';
            spans.forEach(function () {
                var i = document.createElement('i');
                bars.appendChild(i);
            });
            b.appendChild(bars);
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                pop.remove();
                postToEditor({ ykInsertAt: { index: index, kind: 'layout', spans: spans } });
            });
            pop.appendChild(b);
        });
        if (@@templates_enabled@@) {
            var tpl = document.createElement('button');
            tpl.type = 'button';
            tpl.className = 'yk-insert-pop-btn yk-insert-pop-tpl';
            tpl.textContent = @@pea_template_library@@;
            tpl.addEventListener('click', function (e) {
                e.stopPropagation();
                pop.remove();
                postToEditor({ ykInsertAt: { index: index, kind: 'templates' } });
            });
            pop.appendChild(tpl);
        }
        pop.style.left = Math.max(8, anchorRect.left + anchorRect.width / 2 - 120) + 'px';
        pop.style.top = Math.max(8, anchorRect.top + 18) + 'px';
        document.body.appendChild(pop);
        setTimeout(function () {
            document.addEventListener('click', function close() {
                pop.remove();
                document.removeEventListener('click', close);
            }, { once: true });
        }, 0);
    }
    function makeRail(index) {
        var rail = document.createElement('div');
        rail.className = 'yk-insert-rail';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'yk-insert-btn';
        btn.textContent = '＋';
        btn.setAttribute('data-yk-insert', String(index));
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            insertPopover(index, btn.getBoundingClientRect());
        });
        rail.appendChild(btn);
        return rail;
    }
    function setupInsertRails() {
        document.querySelectorAll('.yk-insert-rail, .yk-insert-pop').forEach(function (n) { n.remove(); });
        var secs = Array.prototype.slice.call(document.querySelectorAll('[data-yk-sec]'));
        if (secs.length === 0) return; // 空文档由 yk-empty-doc 双入口负责
        secs.forEach(function (sec) {
            var i = parseInt(sec.getAttribute('data-yk-sec'), 10);
            if (isNaN(i)) return;
            sec.appendChild(makeRail(i)); // 上缘：插到该区块之前
        });
        // 末尾常驻条：插到最后
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
        // nowdoc 不解析 PHP，文案一律走 __TOKEN__ / @@key@@ 占位，在这里换成 JSON
        // 字面量。曾在 nowdoc 里直接写 PHP 开标签，结果标签原样进了浏览器，
        // 整块画布脚本语法报错、编辑器 e2e 全线 pageerror。
        $bloxInject = strtr($bloxInject, [
            '@@templates_enabled@@' => bloxPageEditorEnabled() ? 'true' : 'false',
            '__YK_COLUMN_RESIZE_LABEL__' => json_encode(__('blox_canvas_column_resize'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '__YK_COLUMN_RESIZE_HINT__' => json_encode(__('blox_canvas_column_resize_hint'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '@@pea_add_blank_section@@' => json_encode(__('pea_add_blank_section'), JSON_UNESCAPED_UNICODE),
            '@@pea_add_element@@' => json_encode(__('pea_add_element'), JSON_UNESCAPED_UNICODE),
            '@@pea_canvas_empty@@' => json_encode(__('pea_canvas_empty'), JSON_UNESCAPED_UNICODE),
            '@@pea_column@@' => json_encode(__('pea_column'), JSON_UNESCAPED_UNICODE),
            '@@pea_empty_column@@' => json_encode(__('pea_empty_column'), JSON_UNESCAPED_UNICODE),
            '@@pea_empty_container@@' => json_encode(__('pea_empty_container'), JSON_UNESCAPED_UNICODE),
            '@@pea_empty_div@@' => json_encode(__('pea_empty_div'), JSON_UNESCAPED_UNICODE),
            '@@pea_empty_section@@' => json_encode(__('pea_empty_section'), JSON_UNESCAPED_UNICODE),
            '@@pea_home_field@@' => json_encode(__('pea_home_field'), JSON_UNESCAPED_UNICODE),
            '@@pea_import_template@@' => json_encode(__('pea_import_template'), JSON_UNESCAPED_UNICODE),
            '@@pea_n_columns@@' => json_encode(__('pea_n_columns'), JSON_UNESCAPED_UNICODE),
            '@@pea_section_title@@' => json_encode(__('pea_section_title'), JSON_UNESCAPED_UNICODE),
            '@@pea_subtitle@@' => json_encode(__('pea_subtitle'), JSON_UNESCAPED_UNICODE),
            '@@pea_template_library@@' => json_encode(__('pea_template_library'), JSON_UNESCAPED_UNICODE),
            '@@pea_text_edit@@' => json_encode(__('pea_text_edit'), JSON_UNESCAPED_UNICODE),
        ]);
    }

    $previewStyles = BloxAssetCollector::renderStyles();
    // 画布与后台同源，Code 元素中的脚本不能继承管理员权限运行。nonce 按会话稳定，
    // 既让可信画布脚本执行，也保证连续预览的 head 签名一致、仍可做局部 DOM patch。
    $scriptNonce = base64_encode(hash('sha256', csrfToken(), true));
    $nonceAttr = ' nonce="' . htmlspecialchars($scriptNonce, ENT_QUOTES) . '"';
    $previewScripts = (string) preg_replace(
        '/<script\b(?![^>]*\bnonce=)/i',
        '<script' . $nonceAttr,
        BloxAssetCollector::renderScripts()
    );
    $body = (string) preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script' . $nonceAttr, $body);
    $bloxInject = (string) preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script' . $nonceAttr, $bloxInject);
    $csp = "default-src 'self'; script-src 'self' 'nonce-" . $scriptNonce . "' 'strict-dynamic'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: http: https:; "
        . "font-src 'self' data:; media-src 'self' blob: http: https:; connect-src 'self'; "
        . "frame-src 'self' https://www.youtube.com https://player.bilibili.com; object-src 'none'; "
        . "base-uri 'self'; form-action 'none'";
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Security-Policy: ' . $csp . "; frame-ancestors 'self'");
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="' . htmlspecialchars(siteLang()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta http-equiv="Content-Security-Policy" content="' . htmlspecialchars($csp, ENT_QUOTES) . '">'
        . '<link rel="stylesheet" href="' . assetVer('/assets/css/tailwind.css') . '">'
        . '<link rel="stylesheet" href="' . assetVer('/assets/css/style.css') . '">'
        . '<link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">'
        . '<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">'
        . '<base target="_blank">'
        . BloxDesignSystem::styleTag()
        . $previewStyles
        . '<style>body{margin:0;background:#fff}</style></head><body>'
        . $body
        . '<script' . $nonceAttr . ' src="' . assetVer('/assets/swiper/swiper-bundle.min.js') . '"></script>'
        . $previewScripts
        . $bloxInject
        . '</body></html>';
    exit;
}
