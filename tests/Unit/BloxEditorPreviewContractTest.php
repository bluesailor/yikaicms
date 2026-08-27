<?php
/**
 * Blox 预览刷新协议回归测试。
 *
 * 这些断言锁定长页面编辑的两个关键约束：旧预览响应不能覆盖新数据，
 * 预览重载只恢复高亮和滚动位置，只有显式点选结构节点才主动定位。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxEditorPreviewContractTest extends TestCase
{
    public function testSchemaDefaultsDriveCheckboxesAndConditionalControls(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString(':checked="!!controlValue(ctrl)"', $editor);
        // r15 起条件显示走 BloxControlRules 模块（依赖值解析仍取 schema 控件的当前值）
        $this->assertStringContainsString('var dependency = (self.elSchema(self.selEl.type).controls || []).find(function (item) { return item.key === key; });', $editor);
        $this->assertStringContainsString('return dependency ? self.controlValue(dependency) : (self.selEl.data || {})[key];', $editor);
    }
    public function testDynamicFieldOptionsFollowSourceAndPreviewLimitIsEditorOnly(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $element = $this->source('includes/builder/elements/ListDynamicElement.php');

        $this->assertStringContainsString('x-for="(lbl, val) in controlOptions(ctrl)"', $editor);
        $this->assertStringContainsString('ctrl.source_kind && this.dynamicSourceKind()', $editor);
        $this->assertStringContainsString('if (!source && data.source_type)', $editor);
        $this->assertStringContainsString('this.normalizeSourceControls();', $editor);
        $this->assertStringContainsString('public const EDITOR_PREVIEW_LIMIT = 12;', $element);
        $this->assertStringContainsString("BlockRenderer::\$editChannelId > 0", $element);
        $this->assertStringContainsString('$this->buildMarkup($data, $previewLimit, $context)', $element);
    }
    public function testControlledLoopTemplateUsesOneLevelWhitelistAndParentSource(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $renderer = $this->source('includes/builder/DynamicLoopTemplateRenderer.php');
        $element = $this->source('includes/builder/elements/ListDynamicElement.php');

        $this->assertStringContainsString("private const ALLOWED_TYPES = ['heading', 'text', 'image', 'button', 'div'];", $renderer);
        $this->assertStringContainsString('return DynamicLoopTemplateRenderer::allowedTypes();', $element);
        $this->assertStringContainsString('var allowed = this.allowedChildTypes(host);', $editor);
        $this->assertStringNotContainsString('loopChildTypes', $editor);
        $this->assertStringContainsString('c.loop_only && !self.isLoopTemplateChild()', $editor);
        $this->assertStringContainsString('this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : this.selEl', $editor);
        $this->assertStringContainsString('self.selEl.type === "list-dynamic" && self.hasLoopTemplate()', $editor);
        $this->assertStringContainsString('blox_loop_child_invalid', $editor);
    }
    public function testReusableStatsGroupSeedsAndRestrictsSelectableItems(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $group = $this->source('includes/builder/elements/StatsGroupElement.php');
        $footer = $this->source('themes/default/layouts/footer.php');
        $counter = $this->source('assets/js/blox-counter.js');

        $this->assertStringNotContainsString('isStatsGroupHost', $editor);
        $this->assertStringContainsString("allowedChildren(array \$data = []): array { return ['stat-item']; }", $group);
        $this->assertStringContainsString('public function defaultChildren(): array', $group);
        $this->assertStringContainsString('schema.treeLabelField', $editor);
        $this->assertStringContainsString('((node.data && node.data.children) || []).forEach(assignChildIds);', $editor);
        $this->assertStringContainsString("['type' => 'stat-item'", $group);
        $this->assertStringContainsString('[data-blox-counter]', $counter);
        $this->assertStringContainsString("group.getAttribute('data-blox-counter')", $counter);
        $this->assertStringContainsString("do_action('ik_footer_scripts')", $footer);
    }

    public function testBuilderBootstrapLoadsTagEngineForStandalonePreviewEndpoints(): void
    {
        $bootstrap = $this->source('includes/builder/bootstrap.php');

        $this->assertStringContainsString("require_once __DIR__ . '/../TagEngine.php';", $bootstrap);
    }

    /** r9：头尾模板预览上下文选择器——DTO 解析、命中上报、编辑器切换 三端协议对齐 */
    public function testAreaPreviewContextSelectorContract(): void
    {
        $advance = $this->source('admin/page_edit_advance.php');
        $editor = $this->source('admin/blox_editor.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        // 服务端：显式 DTO 解析（fail-closed 回退首页），redirect 栏目不可作上下文
        $this->assertStringContainsString("preg_match('/^(channel|page):(\\d+)\$/', (string) (\$_GET['preview_context']", $advance);
        $this->assertStringContainsString("(string) \$ctxRow['type'] !== 'redirect'", $advance);
        // 命中报告与前台同一套 Resolver 评分，结果写入区域标记
        $this->assertStringContainsString('BloxAreaResolver::resolve($areaTemplates', $advance);
        $this->assertStringContainsString("' data-yk-ctx-hit='", str_replace('"', "'", $advance));
        $this->assertStringContainsString('postToEditor({ ykAreaHit:', $advance);

        // 桥：命中上报只接受非负整数
        $this->assertStringContainsString('Number.isInteger(data.ykAreaHit) && data.ykAreaHit >= 0', $bridge);
        $this->assertStringContainsString('this.onAreaHit = options.onAreaHit || noop;', $bridge);

        // 编辑器：切换重建预览客户端（先 cancel 防旧上下文响应覆盖），黄条按命中 id 显隐
        $this->assertStringContainsString('data-testid="blox-ctx-select"', $editor);
        $this->assertStringContainsString('this._previewClient.cancel();', $editor);
        $this->assertStringContainsString('onAreaHit: function (id)', $editor);
        $this->assertStringContainsString('self.ctxHit = id;', $editor);
        $this->assertStringContainsString('data-testid="blox-ctx-warn"', $editor);
    }

    /** 空画布双入口 + 顶栏元素/预制区块分流。 */
    public function testEmptyCanvasEntryPointsAndTemplateButtonLabel(): void
    {
        $advance = $this->source('admin/page_edit_advance.php');
        $editor = $this->source('admin/blox_editor.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        // 画布注入：文档级空态卡（每次预览更新先清后建），动作经 postMessage 白名单出画布
        $this->assertStringContainsString('yk-empty-doc', $advance);
        $this->assertStringContainsString("postToEditor({ ykEmptyAction: action })", $advance);
        $this->assertStringContainsString("emptyActionButton('templates'", $advance);

        $this->assertStringContainsString('data.ykEmptyAction === "templates" || data.ykEmptyAction === "section"', $bridge);

        // 编辑器：空态接线到模板库/插空白区块；工具栏提供两个语义明确的独立入口。
        $this->assertStringContainsString('onEmptyAction: function (action)', $editor);
        $this->assertStringContainsString('data-testid="blox-elements-open"', $editor);
        $this->assertStringContainsString('data-testid="blox-prebuilt-open"', $editor);
        $this->assertStringContainsString('ti-layout-grid-add', $editor);
    }

    public function testDesktopElementPanelHasAccessiblePersistentResizer(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        $this->assertStringContainsString('data-testid="blox-left-panel-resizer"', $workspace);
        $this->assertStringContainsString('role="separator" aria-orientation="vertical"', $workspace);
        $this->assertStringContainsString('@keydown.left.prevent="resizeLeftPanelBy(-16)"', $workspace);
        $this->assertStringContainsString('@dblclick="resetLeftPanelWidth()"', $workspace);
        $this->assertStringContainsString('yikai:blox:left-panel-width:v1', $editor);
        $this->assertStringContainsString('body.blox-panel-resizing iframe { pointer-events: none; }', $editor);
    }

    public function testDesktopStructurePanelHasAccessiblePersistentResizerAndCollapseControl(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        $this->assertStringContainsString('data-testid="blox-right-panel-resizer"', $workspace);
        $this->assertStringContainsString('@keydown.left.prevent="resizeRightPanelBy(16)"', $workspace);
        $this->assertStringContainsString('@dblclick="resetRightPanelWidth()"', $workspace);
        $this->assertStringContainsString('data-testid="blox-right-panel-toggle"', $workspace);
        $this->assertStringContainsString(':aria-expanded="String(!rightPanelCollapsed)"', $workspace);
        $this->assertStringContainsString('yikai:blox:right-panel-width:v1', $editor);
        $this->assertStringContainsString('yikai:blox:right-panel-collapsed:v1', $editor);
    }

    /**
     * 空画布提示只能有一处。
     *
     * 由来 2026-08-24（用户反馈，新建单页可复现）：编辑器侧另有一层浮层
     * （快捷加区块/分栏 + 一行提示），与 iframe 内的 .yk-empty-doc 卡片触发条件相同、
     * 位置几乎重合，两段文字直接叠在一起糊成一团。留 iframe 内那套：它随预览缩放定位、
     * 带「从模板库导入」这个最有用的首动作，并且有本文件上面那条测试覆盖。
     */
    public function testEmptyCanvasHasExactlyOneHintLayer(): void
    {
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        // 画布宿主里不得再有 sections.length === 0 的浮层
        $this->assertStringNotContainsString('x-show="sections.length === 0"', $workspace);
        // 浮层专属的快捷入口也应随之消失（元素库仍可从工具栏与左侧面板进入）
        $this->assertStringNotContainsString('data-testid="blox-canvas-library-open"', $workspace);

        // 右侧结构树的同名占位是另一回事，必须保留——它说明的是「结构为空」
        $this->assertStringContainsString('blox_click_any_element', $workspace);
    }

    /** r10：文档 v1 信封——编辑器 boot 解包 settings/sections，保存封回；sticky 开关只在 header 模板 */
    public function testDocumentEnvelopeContract(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $functions = $this->source('includes/functions.php');
        $areaDocument = $this->source('includes/builder/BloxAreaDocument.php');

        $this->assertStringContainsString('BloxDocumentPipeline::decode($initBlocks)', $editor);
        $this->assertStringContainsString('$docSettings = $bootDoc[\'settings\'];', $editor);
        $this->assertStringContainsString('documentData() {', $editor);
        $this->assertStringContainsString('return JSON.stringify({ schema: 1, settings: this.docSettings || {}, sections: this.sections || [] });', $editor);
        $this->assertStringContainsString('var payload = this.documentData();', $editor);
        $this->assertStringNotContainsString('body.set("blocks_data", savedData);', $editor); // 三分支全部走信封 payload
        $this->assertStringContainsString('data-testid="blox-sticky-toggle"', $editor);
        $this->assertStringContainsString("\$templateId && \$templateType === 'header'", $editor);

        // 前台壳层：sticky 输出类 + 按需注入脚本
        $this->assertStringContainsString('BloxAreaDocument::renderShell(', $functions);
        $this->assertStringContainsString("yk-sticky-header", $areaDocument);
        $this->assertStringContainsString("BloxAssetCollector::addScript('/assets/js/blox-sticky-header.js')", $areaDocument);
        $this->assertStringContainsString(
            'BloxDocumentPipeline::decode($blocksDataJson)',
            $this->source('admin/page_edit_advance.php')
        );
        $this->assertStringContainsString(
            'BloxDocumentPipeline::decode($blocksJson)',
            $this->source('includes/builder/BlockRenderer.php')
        );
    }

    public function testNumberControlsExposeSchemaDefaultsAndSteps(): void
    {
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        $this->assertStringContainsString(':step="ctrl.step ?? null"', $workspace);
        $this->assertStringContainsString(':placeholder="ctrl.placeholder ?? (ctrl.default ?? \'\')"', $workspace);
    }

    public function testFrontendPreviewLinkUsesCleanPublishedPageMode(): void
    {
        $header = $this->source('admin/blox_editor/partials/header.php');

        // v1.18.6：预览目标服务端按编辑对象决定——页头/页尾模板指首页，
        // 区块/弹窗模板不显示（此前模板模式拼出坏链接 /.html?preview）
        $this->assertStringContainsString("in_array(\$templateType ?? '', ['header', 'footer'], true)", $header);
        $this->assertStringContainsString("'.html?preview'", $header);
        $this->assertStringContainsString('if ($frontPreviewUrl !== null):', $header);
        $this->assertStringContainsString('data-testid="blox-front-preview"', $header);
        $this->assertStringContainsString('ti ti-eye text-base', $header);
        $this->assertStringContainsString("__('blox_front_preview')", $header);
    }

    /** r11：薄命令层——结构命令入口委托 runCommand，失败回滚复用历史快照协议 */
    public function testCommandRunnerWiring(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $runner = $this->source('assets/js/blox-command-runner.js');

        $this->assertStringContainsString('<script src="/assets/js/blox-command-runner.js?v=', $editor);
        // capture/restore 复用历史快照协议（回滚不产生新历史、选择与 dirty 一并恢复）
        $this->assertStringContainsString('return self.historyStore().snapshot(self.historyData());', $editor);
        $this->assertStringContainsString('self.applyHistorySnapshot(snapshot);', $editor);
        // 七个结构命令全部走委托入口
        foreach (['delete-section', 'delete-element', 'paste', 'canvas-drop', 'apply-layout', 'add-section', 'add-element'] as $cmd) {
            $this->assertStringContainsString('this.runCommand("' . $cmd . '"', $editor);
        }
        // 模板插入应用段 silent 执行，错误提示走既有 catch 面板
        $this->assertStringContainsString('self.commandRunner().execute("insert-template"', $editor);
        $this->assertStringContainsString('}, { silent: true });', $editor);
        // 嵌套吸收：只有最外层捕获快照（addElement→addSection 等仍是一个命令组）
        $this->assertStringContainsString('if (this.depth > 0) {', $runner);
    }

    /** r13：画布插入轨道——注入层辅助节点、bridge 白名单、编辑器定点覆盖位 三端对齐 */
    public function testCanvasInsertRailContract(): void
    {
        $advance = $this->source('admin/page_edit_advance.php');
        $canvasPreview = $this->source('includes/builder/BloxCanvasPreview.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');
        $editor = $this->source('admin/blox_editor.php');

        // 注入层：轨道每次画布更新先清后建（不进保存文档），动作全部经 postMessage
        $this->assertStringContainsString("querySelectorAll('.yk-insert-rail, .yk-insert-pop').forEach", $advance);
        $this->assertStringContainsString("postToEditor({ ykInsertAt: { index: index, kind: 'layout', spans: spans } })", $advance);
        $this->assertStringContainsString("postToEditor({ ykInsertAt: { index: index, kind: 'templates' } })", $advance);
        $this->assertStringNotContainsString('yk-insert-rail-tail', $advance);
        $this->assertStringContainsString("querySelectorAll('[data-yk-sec]')", $canvasPreview);
        $this->assertStringNotContainsString('yk-insert-rail-tail', $canvasPreview);

        $this->assertStringContainsString('payload.index >= 0 && payload.index <= 500', $bridge);
        $this->assertStringContainsString('payload.kind === "layout" || payload.kind === "templates" || payload.kind === "blank"', $bridge);

        // 编辑器：_insertAt 一次性覆盖 insertIndex，watcher 失效；既有插入函数零改动
        $this->assertStringContainsString('if (this._insertAt !== null) {', $editor);
        $this->assertStringContainsString('self._insertAt = null; // 定点插入覆盖位一次性生效', $editor);
        $this->assertStringContainsString('onInsertAt: function (payload) { self.insertAtBoundary(payload); }', $editor);
    }

    /** r14：面包屑/另存模板/具名拒因 三件 P1 的协议锚点 */
    public function testR14BreadcrumbSaveAsAndNamedRejection(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $advance = $this->source('admin/page_edit_advance.php');
        $api = $this->source('admin/blox_template_api.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        // 面包屑：状态派生（不存索引副本），末层高亮，点父级走既有 select*
        $this->assertStringContainsString('data-testid="blox-breadcrumb"', $editor);
        $this->assertStringContainsString('breadcrumb() {', $editor);
        $this->assertStringContainsString('crumbGo(c) {', $editor);

        // 另存模板：服务端组包走 Importer 安全链 + 发布即插回 + 目录强刷
        $this->assertStringContainsString("BloxTemplateImporter::importJson(\$package", $api);
        $this->assertStringContainsString('bloxTemplateModel()->publishDraft($templateId);', $api);
        $this->assertStringContainsString("'key' => 'local:' . \$templateId", $api);
        $this->assertStringContainsString('body.set("action", "save_section");', $editor);
        $this->assertStringContainsString('window.BloxTemplateLibrary.upsertLocal(self.templateItems, item)', $editor);
        $this->assertStringContainsString('self.templateScope = "local";', $editor);
        $this->assertStringContainsString('self.templateFilter = "section";', $editor);
        $this->assertStringContainsString('this.templateReloadPending = true;', $editor);
        $this->assertStringContainsString('self.loadTemplates(true);', $editor);

        // 具名拒因：注入层 verdict → drop 上报 → bridge 白名单 → toast
        $this->assertStringContainsString("function dropTargetVerdict(target)", $advance);
        $this->assertStringContainsString("postToEditor({ ykDropRejected: verdict.reason || 'invalid' });", $advance);
        $this->assertStringContainsString('data.ykDropRejected === "restricted-children"', $bridge);
        $this->assertStringContainsString('onDropRejected: function (reason)', $editor);
    }

    public function testFreeEditionDocumentAndTemplateCapabilityBoundary(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $homeApi = $this->source('admin/blox_home_api.php');
        $templateApi = $this->source('admin/blox_template_api.php');

        $this->assertStringContainsString('if (!bloxPageEditorEnabled())', $editor);
        $this->assertStringNotContainsString('$isBasicPageRequest', $editor);
        $this->assertStringContainsString("!in_array(\$templateType, ['section', 'page'], true) && !\$advancedBloxEnabled", $editor);
        $this->assertStringContainsString('data-testid="blox-elements-open"', $editor);
        $this->assertStringContainsString('data-testid="blox-prebuilt-open"', $editor);
        $this->assertStringNotContainsString("openTemplates() {\n                if (!this.advancedMode)", $editor);
        $this->assertStringContainsString('if (!bloxPageEditorEnabled())', $homeApi);

        $this->assertStringContainsString('if (!bloxPageEditorEnabled())', $templateApi);
        $this->assertStringContainsString("\$item['locked_reason'] = 'license_missing';", $templateApi);
        $remoteGate = strpos($templateApi, "str_starts_with(\$key, 'remote:')");
        $resolve = strpos($templateApi, 'BloxTemplateCatalog::resolve($key, $context)');
        $this->assertNotFalse($remoteGate);
        $this->assertNotFalse($resolve);
        $this->assertLessThan($resolve, $remoteGate);
    }

    /** r15：声明式控件显示规则——编辑器走可单测求值器模块，required 归一为兼容别名 */
    public function testDeclarativeControlRulesContract(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $rules = $this->source('assets/js/blox-control-rules.js');

        $this->assertStringContainsString('<script src="/assets/js/blox-control-rules.js?v=', $editor);
        $this->assertStringContainsString('return window.BloxControlRules.visibleWhenMet(ctrl, function (key) {', $editor);
        // 模块契约：required 兼容归一 + fail-closed 未知操作符
        $this->assertStringContainsString('function normalizeRule(ctrl)', $rules);
        $this->assertStringContainsString('if (OPS.indexOf(op) === -1) return false;', $rules);
    }

    public function testTemplateInsertTreatsMissingLockStateAsUnlocked(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString(
            ':disabled="templateInserting !== \'\' || !!item.locked"',
            $editor
        );
    }

    private function source(string $path): string
    {
        if ($path === 'admin/blox_editor.php') {
            return implode("\n", array_map(function (string $editorPath): string {
                $source = file_get_contents(ROOT_PATH . '/' . $editorPath);
                $this->assertNotFalse($source, "无法读取 {$editorPath}");
                return (string) $source;
            }, [
                'admin/blox_editor.php',
                'admin/blox_editor/partials/header.php',
                'admin/blox_editor/partials/workspace.php',
                'admin/blox_editor/partials/overlays.php',
            ]));
        }
        if ($path === 'admin/page_edit_advance.php') {
            $pathSource = file_get_contents(ROOT_PATH . '/' . $path);
            $previewSource = file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php');
            $this->assertNotFalse($pathSource, "无法读取 {$path}");
            $this->assertNotFalse($previewSource, '无法读取共享 Blox 画布预览 helper');
            return (string) $pathSource . "\n" . (string) $previewSource;
        }
        $source = file_get_contents(ROOT_PATH . '/' . $path);
        $this->assertNotFalse($source, "无法读取 {$path}");

        return (string) $source;
    }

    public function testPreviewRefreshPreservesScrollAndRejectsStaleResponses(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $client = $this->source('assets/js/blox-preview-client.js');

        $this->assertStringContainsString('<script src="/assets/js/blox-preview-client.js?v=', $editor);
        $this->assertStringContainsString('return this.previewClient().refresh();', $editor);
        $this->assertStringContainsString('var shouldScroll = self._pendingInitialFocus;', $editor);
        $this->assertStringContainsString('if (shouldScroll) {', $editor);
        $this->assertStringContainsString('self.highlightCanvasSelection(true);', $editor);
        $this->assertStringContainsString('self.highlightCanvasSelection(false);', $editor);

        $this->assertStringContainsString('this.controller.abort()', $client);
        $this->assertStringContainsString('var sequence = ++this.sequence;', $client);
        $this->assertStringContainsString('if (sequence !== self.sequence) return false;', $client);
        $this->assertStringContainsString('self.captureScroll(frame)', $client);
        $this->assertStringContainsString('self.finishUpdate(frame, scrollState)', $client);
        $this->assertStringContainsString('if (sequence !== self.sequence) return;', $client);
        $this->assertStringContainsString('if (self.patchFrame(frame, html))', $client);
        $this->assertStringContainsString('new currentDoc.defaultView.CustomEvent("blox:content-updated"', $client);

        $listener = strpos($client, 'frame.addEventListener("load"');
        $srcdoc = strpos($client, 'frame.srcdoc = html;');
        $this->assertNotFalse($listener);
        $this->assertNotFalse($srcdoc);
        $this->assertLessThan($srcdoc, $listener, 'load 监听必须先注册，避免快速 srcdoc 加载丢事件');
    }
    public function testCanvasMessageRoutingUsesSourceCheckedBridge(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        $this->assertStringContainsString('<script src="/assets/js/blox-canvas-bridge.js?v=', $editor);
        $this->assertStringContainsString('new window.BloxCanvasBridge({', $editor);
        $this->assertStringContainsString('this.canvasBridge().start();', $editor);
        $this->assertStringContainsString('if (self._canvasBridge) self._canvasBridge.dispose();', $editor);
        $this->assertStringNotContainsString('window.addEventListener("message", function(e)', $editor);

        $this->assertStringContainsString('event.source !== source', $bridge);
        $this->assertStringContainsString('value.version !== 1', $bridge);
        $this->assertStringContainsString('this.lastDropId === payload.dropId', $bridge);
        $this->assertStringContainsString('global.removeEventListener("message", this.boundMessage)', $bridge);
    }

    public function testCanvasHighlightOnlyScrollsForExplicitSelection(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');

        $this->assertStringContainsString('var message = { ykScroll: scrollToSelection === true };', $editor);
        $this->assertStringContainsString('this.highlightCanvasSelection(true);', $editor);
        $this->assertStringContainsString('self.highlightCanvasSelection(false);', $editor);

        $start = strpos($canvas, "    window.addEventListener('message', function (e) {");
        $end = strpos($canvas, "    window.addEventListener('scroll', syncOverlay, true);", $start ?: 0);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $protocol = substr($canvas, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString("var shouldScroll = d.ykScroll === true;", $protocol);
        $this->assertStringContainsString('if (shouldScroll) scrollToPath(d.ykHighlightEl);', $protocol);
        $this->assertStringContainsString('if (shouldScroll && fieldTarget) fieldTarget.scrollIntoView', $protocol);
        $this->assertStringContainsString('if (shouldScroll && c) c.scrollIntoView', $protocol);
        $this->assertStringContainsString('if (shouldScroll && col) col.scrollIntoView', $protocol);
        $this->assertStringContainsString('if (shouldScroll && t) t.scrollIntoView', $protocol);
        $this->assertStringContainsString('if (shouldScroll) stableTarget.scrollIntoView', $protocol);
    }

    public function testFooterTemplateDefaultsCanvasToEditableFooterArea(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString('$templateType === \'footer\' ? \'true\' : \'false\'', $editor);
        $this->assertStringContainsString('if (this.applyInitialNodeFocus()) this._pendingInitialFooterScroll = false;', $editor);
        $this->assertStringContainsString('onAreaHit: function (id)', $editor);
        $this->assertStringContainsString('self.scrollInitialFooterIntoView();', $editor);
        $this->assertStringContainsString('self.highlightCanvasSelection(false);', $editor);
        $this->assertStringContainsString("querySelector('[data-yk-area=\"footer\"]')", $editor);
        $this->assertStringContainsString('root.style.scrollBehavior = "auto";', $editor);
        $this->assertStringContainsString('(frame.contentWindow.scrollY || 0) + footerRect.bottom - frame.contentWindow.innerHeight', $editor);
    }

    public function testStableSectionLocatorUsesOpaqueIdsAcrossUrlDomAndCanvasMessages(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $renderer = $this->source('includes/builder/BlockRenderer.php');
        $canvas = $this->source('admin/page_edit_advance.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        foreach ([
            "get('focus_section', '')",
            'initialFocusSectionId:',
            'applyInitialNodeFocus()',
            'sectionIndexById(id, legacyIndex)',
            'message.ykHighlightSectionId = this.selectedSectionId()',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "editor locator token {$token} missing");
        }
        $this->assertStringContainsString('data-yk-sec-id="', $renderer);
        $this->assertStringContainsString("postToEditor({ ykPickSection: section })", $canvas);
        $this->assertStringContainsString('d.ykHighlightSectionId', $canvas);
        $this->assertStringContainsString('sectionTargetPayload(data.ykPickSection)', $bridge);
        $this->assertStringNotContainsString("getInt('focus')", $editor);
    }

    public function testFrontendReturnTargetSurvivesEditorNavigationWithoutOpeningRedirects(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $header = $this->source('admin/blox_editor/partials/header.php');

        foreach ([
            "normalizeReturnTo(\$_GET['return_to'] ?? '')",
            'withReturnTo($primaryEditUrl, $editorReturnTo)',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }
        foreach ([
            '$hasFrontendReturn',
            "__('blox_return_to_page')",
            'data-testid="blox-back"',
            'data-frontend-return=',
            "withReturnTo('/admin/blox_editor.php?id='",
            '$mobileLanguageUrl',
        ] as $token) {
            $this->assertStringContainsString($token, $header);
        }

        foreach ([
            'setEditorReturnReceipt(receipt)',
            'target.searchParams.set("yk_edit_receipt", token)',
            'self.setEditorReturnReceipt(res.data && res.data.return_receipt);',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }

        foreach ([
            'admin/blox_page_api.php' => ["issueReturnReceipt('draft')", "issueReturnReceipt('published')"],
            'admin/blox_template_api.php' => ["issueReturnReceipt('draft')", "issueReturnReceipt('published')"],
            'admin/blox_home_api.php' => ["issueReturnReceipt('draft')", "issueReturnReceipt('published')"],
        ] as $path => $tokens) {
            $api = $this->source($path);
            foreach ($tokens as $token) {
                $this->assertStringContainsString($token, $api, $path);
            }
        }
    }

    public function testStableElementLocatorUsesOpaqueIdsAcrossUrlDomAndCanvasMessages(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $renderer = $this->source('includes/builder/BlockRenderer.php');
        $canvas = $this->source('admin/page_edit_advance.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        foreach ([
            "get('focus_element', '')",
            'initialFocusElementId:',
            'elementPathById(id)',
            'selectElementTarget(target, notifyCanvas)',
            'message.ykHighlightElementId = this.selectedElementId()',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "editor element locator token {$token} missing");
        }
        $this->assertStringContainsString('data-yk-el-id="', $renderer);
        $this->assertStringContainsString('ykPickElement: target', $canvas);
        $this->assertStringContainsString('d.ykHighlightElementId', $canvas);
        $this->assertStringContainsString('elementTargetPayload(data.ykPickElement)', $bridge);
        $this->assertStringContainsString('elementTargetPayload(data.ykEditElement)', $bridge);
    }

    public function testNavigationAndSiteDataElementsExplainTheirContentSources(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        self::assertStringContainsString("\$canManageGlobalSettings = hasPermission('*');", $editor);
        foreach ([
            'data-testid="blox-nav-content-source"',
            'data-testid="blox-nav-content-manage"',
            "'/admin/nav_menu.php?group='",
            'data-testid="blox-contact-content-source"',
            'href="/admin/setting_contact.php"',
            'data-testid="blox-social-content-source"',
            'href="/admin/setting_social.php"',
            'data-testid="blox-search-content-source"',
            'data-testid="blox-language-content-source"',
            'href="/admin/setting_lang.php"',
            'data-testid="blox-copyright-content-source"',
            'href="/admin/setting.php?tab=footer#input_footer_copyright_text"',
            'href="/admin/setting.php?tab=basic#input_site_icp"',
            'target="_blank" rel="noopener"',
        ] as $token) {
            self::assertStringContainsString($token, $workspace, "content source token {$token} missing");
        }
    }

    public function testCanvasAndRevisionPreviewLoadFrontendTypographyStyles(): void
    {
        $canvas = $this->source('admin/page_edit_advance.php');
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString("assetVer('/assets/css/style.css')", $canvas);
        $this->assertStringContainsString("<link rel='stylesheet' href='/assets/css/style.css'>", $editor);
    }

    public function testHomeBannerPreviewAndCompactManagerStayConnected(): void
    {
        $canvas = $this->source('admin/page_edit_advance.php');
        $editor = $this->source('admin/blox_editor.php');

        foreach ([
            '/assets/swiper/swiper-bundle.min.css',
            "assetVer('/assets/swiper/swiper-bundle.min.js')",
            'BloxAssetCollector::renderStyles()',
            'BloxAssetCollector::renderScripts()',
            'swiper.slideTo(slideIndex, 0)',
            'ykBannerSlide',
        ] as $token) {
            $this->assertStringContainsString($token, $canvas, "banner preview token {$token} missing");
        }
        $this->assertStringContainsString('window.BloxBanner.init(root || document);', $canvas);
        $this->assertStringContainsString('window.BloxBanner.show(bannerNode, d.ykBannerSlide);', $canvas);
        $this->assertStringContainsString('bannerSlides - 1', $canvas);
        $this->assertStringNotContainsString('node._ykSwiper', $canvas);
        $swiperPosition = strpos($canvas, "assetVer('/assets/swiper/swiper-bundle.min.js')");
        $runtimePosition = strrpos($canvas, '. $previewScripts');
        $this->assertIsInt($swiperPosition);
        $this->assertIsInt($runtimePosition);
        $this->assertLessThan($runtimePosition, $swiperPosition);

        foreach ([
            'data-banner-manager',
            'data-banner-thumb',
            'data-banner-replace',
            'data-banner-image-control',
            'selectBannerItem(bi)',
            'replaceBannerImage(bi)',
            'bannerPreviewItems()',
            'showBannerSlide(k)',
            'message.ykBannerSlide = this.selectedSubEi',
            'message.ykBannerPath = this.selectedSi + "." + this.selectedCi + "." + this.selectedEi',
            'data-testid="blox-banner-overall-settings"',
            '@click="selectElement(selectedSi, selectedCi, selectedEi)"',
            'aspect-[16/7]',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "banner editor token {$token} missing");
        }
    }

    public function testStructureTreeLabelsAndSortableRebindStayStable(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString('elLabel(col.elements[0])', $editor);
        $this->assertStringNotContainsString('elName(col.elements[0])', $editor);
        $this->assertStringContainsString('self.scheduleTreeSortable();', $editor);
        $this->assertStringContainsString('scheduleTreeSortable(delay) {', $editor);
        $this->assertStringContainsString('if (Sortable.active) {', $editor);
        $this->assertStringContainsString('this.scheduleTreeSortable(50);', $editor);
        $this->assertStringContainsString('Alpine.raw(s)', $editor);
        $this->assertStringContainsString('handle: "[data-section-drag-handle]"', $editor);
        $this->assertStringContainsString('handle: "[data-element-drag-handle]"', $editor);
        $this->assertStringContainsString('handle: "[data-child-drag-handle]"', $editor);
        $this->assertStringContainsString('self._treeSortableTimer = setTimeout(function() {', $editor);
    }

    public function testCommonSpacingAndFlexControlsStayConnectedToEditorState(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString("supportsBoxStyles(type)", $editor);
        $this->assertStringContainsString("setBoxSpacing(kind, side, value)", $editor);
        $this->assertStringContainsString('boxExactOpen: { margin: false, padding: false }', $editor);
        $this->assertStringContainsString('resetBoxPanelState()', $editor);
        $this->assertStringContainsString('kindExactVisible(kind)', $editor);
        $this->assertStringContainsString('setBoxOverall(kind, ev)', $editor);
        $this->assertStringContainsString(':value="controlValue(ctrl)"', $editor);
        $this->assertStringContainsString(':selected="controlValue(ctrl) === val"', $editor);
        $this->assertStringContainsString('controlValue(ctrl) {', $editor);
        $this->assertStringContainsString('delete this.selEl.data[key]', $editor);
        $this->assertStringContainsString("setBoxSide(kind, side, ev)", $editor);
        $this->assertStringContainsString("boxSideDisplay(kind, side)", $editor);
        $this->assertStringContainsString("spacingRegex(kind)", $editor);
        $this->assertStringContainsString('containerWrapOptions:', $editor);
        $this->assertStringContainsString('selEl.data.wrap = opt.k', $editor);
        $this->assertStringContainsString('justify-evenly', $editor);
        $this->assertStringContainsString('items-baseline', $editor);
    }
    public function testCanvasSelectionContextMenuAndInlineEditProtocolsCoverAllLayers(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');

        foreach (['ykPickSectionField', 'ykPickEl', 'ykPickCol', 'ykPickCon', 'ykPickSection', 'ykContext', 'ykInlineEdit'] as $message) {
            $this->assertStringContainsString($message, $canvas, "canvas message {$message} missing");
        }
        foreach ([
            "openCtx(\$event, 'canvas', {})",
            "openCtx(\$event, 'section', {si: si})",
            "openCtx(\$event, 'container', {si: si})",
            "openCtx(\$event, 'column', {si: si, ci: ci})",
            "openCtx(\$event, 'element', {si: si, ci: ci, ei: ei})",
            "openCtx(\$event, 'child', {si: si, ci: ci, ei: ei, cei: cei})",
            "openCtx(\$event, 'sectionField', {si: si, field: 'title'})",
        ] as $binding) {
            $this->assertStringContainsString($binding, $editor, "context menu binding {$binding} missing");
        }
        $this->assertStringContainsString('selectCtxTarget(d.kind, target, false);', $editor);
        $this->assertStringContainsString('selectChild(t.si, t.ci, t.ei, t.cei, notifyCanvas);', $editor);
        $this->assertStringContainsString('selectElement(t.si, t.ci, t.ei, notifyCanvas);', $editor);
        $this->assertStringContainsString('postToEditor({ ykContext:', $canvas);
        $this->assertStringContainsString('var editorOrigin = window.parent.location.origin;', $canvas);
        $this->assertStringContainsString('window.parent.postMessage(message, editorOrigin);', $canvas);
        $this->assertStringNotContainsString("parent.postMessage({ ykContext:", $canvas);
    }

    public function testInlineEditingCoversSectionTextHeadingAndButtonWithoutRawHtmlInjection(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');

        foreach (['sectionField', 'heading', 'text', 'button', 'contenteditable', 'Escape', 'ykInlineEdit'] as $token) {
            $this->assertStringContainsString($token, $canvas, "inline edit token {$token} missing");
        }
        $this->assertStringContainsString('this.sections[si].settings[data.field] = data.value;', $editor);
        $this->assertStringContainsString('el.data.text = data.value;', $editor);
        $this->assertStringContainsString('el.data.html = this.plainTextHtml(data.value);', $editor);
        $this->assertStringContainsString('escapeHtml', $editor);
        $this->assertStringContainsString('if (data.kind !== "element" || typeof data.path !== "string") return;', $editor);
    }
    public function testCanvasDropUsesVersionedBeforeAfterTargetProtocol(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');
        $preview = $this->source('includes/builder/BloxCanvasPreview.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        foreach (['application/x-yikai-blox', 'version: 1', 'source: "palette"'] as $token) {
            $this->assertStringContainsString($token, $editor, "editor drag payload {$token} missing");
        }
        foreach (['yk-drop-line', 'dropTargetFromEvent', "position: before ? 'before' : 'after'", 'target: target', 'dropId: ' ] as $token) {
            $this->assertStringContainsString($token, $canvas, "canvas drop contract {$token} missing");
        }
        foreach (['yk-drop-label', 'yk-drop-inside', "kind: 'container'", 'dropIndicatorText(target, verdict)', '__YK_DROP_TEXT__'] as $token) {
            $this->assertStringContainsString($token, $preview, "canvas drop intent token {$token} missing");
        }
        foreach (['handleCanvasDrop(payload)', 'if (!isNaN(targetSi)) this.selectSection(targetSi, false);', 'insertElementAt(node, target, el.label)', 'target.kind === "column"', 'target.kind === "container"', 'target.position === "before"'] as $token) {
            $this->assertStringContainsString($token, $editor, "editor insert contract {$token} missing");
        }
        foreach (['startTemplateDrag(item, event)', 'templateSectionsDocked()', 'if (this.templateDragItem) this.finishPaletteDrag();', 'onTemplateDrop: function (payload)', 'handleTemplateDrop(payload)', 'insertTemplateAt(item, payload.index)', 'requestedIndex === null'] as $token) {
            $this->assertStringContainsString($token, $editor, "prebuilt drag contract {$token} missing");
        }
        foreach ([':draggable="templateSectionDraggable(item)"', '@dragstart="startTemplateDrag(item, $event)"', "templateSectionsDocked() ? 'grid-cols-1'", "pointer-events-none"] as $token) {
            $this->assertStringContainsString($token, $workspace . $this->source('admin/blox_editor/partials/overlays.php'), "prebuilt dock token {$token} missing");
        }
        foreach (['startPaletteDrag(el, event)', 'createPaletteDragGhost(el, event)', 'clearPaletteDragGhost()', 'setDragImage(ghost, 18, 18)', 'blox-palette-drag-ghost', 'ghost.setAttribute("aria-hidden", "true")', 'e.key === "Escape" && self.canvasDragActive', 'canvasPaletteDragMessage(event, phase)', 'frame.contentWindow', 'frameWindow.innerWidth', 'frameWindow.innerHeight', 'ykPaletteDrag'] as $token) {
            $this->assertStringContainsString($token, $editor, "cross-frame drag bridge token {$token} missing");
        }
        foreach (['data-testid="blox-canvas-drop-bridge"', 'canvasPaletteDragOver($event)', 'canvasPaletteDrop($event)', 'pointer-events-none', "canvasDragActive ? 'overflow-hidden' : 'overflow-auto'"] as $token) {
            $this->assertStringContainsString($token, $workspace, "canvas drop overlay token {$token} missing");
        }
        foreach (['e.source !== window.parent', 'handlePaletteDragMessage(d.ykPaletteDrag)', 'document.elementFromPoint(payload.clientX, payload.clientY)', "payload.phase !== 'move' && payload.phase !== 'drop'"] as $token) {
            $this->assertStringContainsString($token, $preview, "preview coordinate drop token {$token} missing");
        }
        foreach (['renderTemplateDragTarget(payload, dropping)', "kind: 'section'", 'ykTemplateDrop', "position === 'after' ? 1 : 0"] as $token) {
            $this->assertStringContainsString($token, $preview, "prebuilt canvas target token {$token} missing");
        }
        foreach (["classList.add('yk-palette-dragging')", "classList.remove('yk-palette-dragging')", 'scrollbar-color:transparent transparent'] as $token) {
            $this->assertStringContainsString($token, $preview, "canvas drag preview token {$token} missing");
        }
        foreach (['paletteAutoPanSpeed(', 'setInterval(function autoPanTick()', 'window.scrollBy(0, speed)'] as $token) {
            $this->assertStringNotContainsString($token, $preview, "canvas drag must not auto-scroll: {$token}");
        }
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');
        foreach (['payload.dropId', 'this.lastDropId', 'onDrop', 'onTemplateDrop', 'templateDropPayload', 'isTopLevelElementPath(value.target.path)'] as $token) {
            $this->assertStringContainsString($token, $bridge, "canvas bridge drop contract {$token} missing");
        }
    }

    public function testStructureTreeDropUsesCanvasInsertionIntentProtocol(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        foreach ([
            'treeDropIntent: null',
            'treeSectionDragOver(event)',
            'treeSectionDrop(event)',
            'window.addEventListener("dragover", function (e) { self.treeSectionDragOver(e); }, true)',
            'treeElementDragOver(event, si, ci, ei, el)',
            'treeChildDragOver(event, si, ci, ei, cei)',
            'treeDropVerdict(target)',
            'target.kind === "template-section"',
            'this.insertTemplateAt(template, parseInt(drop.target.index, 10))',
            'this.addElement(el, drop.target)',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "structure tree intent token {$token} missing");
        }
        foreach ([
            'data-testid="blox-tree-drop-indicator"',
            'data-drop-intent="before"',
            'data-drop-intent="after"',
            "treeDropMatches('template-section:' + si + ':before')",
            "treeDropMatches('template-section:' + si + ':after')",
            'treeDrop($event)',
            'treeDropIntent ? treeDropIntent.label :',
        ] as $token) {
            $this->assertStringContainsString($token, $workspace, "structure tree marker token {$token} missing");
        }
    }

    public function testHomeCanvasAndFrontendUseSharedDynamicRenderContext(): void
    {
        $canvas = $this->source('admin/page_edit_advance.php');
        $editor = $this->source('admin/blox_editor.php');
        $api = $this->source('admin/blox_home_api.php');
        $preview = $this->source('includes/builder/BloxCanvasPreview.php');
        $frontend = $this->source('index.php');

        $this->assertStringContainsString('HomeBloxRenderContext::fromCurrentSite($bloxCanvas)', $canvas);
        $this->assertStringContainsString('HomeBloxRenderer::render($previewSections, [$homePreviewContext, \'renderLegacyBlock\'])', $canvas);
        $this->assertStringContainsString('$previewEndpoint = \'/admin/blox_preview.php?home=1\';', $editor);
        $this->assertStringContainsString('outputBloxCanvasPreview(true, 0)', $api);
        $this->assertStringContainsString('HomeBloxRenderContext::fromCurrentSite($bloxCanvas)', $preview);
        $this->assertStringContainsString('HomeBloxRenderer::render($previewSections, [$homePreviewContext, \'renderLegacyBlock\'])', $preview);
        $this->assertStringContainsString('HomeBloxRenderContext::fromHomePageData(', $frontend);
        $this->assertStringContainsString('HomeBloxRenderer::render($homeBloxDocument[\'sections\'], $renderLegacyHomeBlock)', $frontend);
    }

    public function testFreeHomeLayoutDraftIsSeparatedFromBloxDraft(): void
    {
        $bootstrap = $this->source('includes/builder/bootstrap.php');
        $layoutDocument = $this->source('includes/builder/HomeLayoutDocument.php');
        $bloxDocument = $this->source('includes/builder/HomeBloxDocument.php');
        $advanced = $this->source('admin/page_edit_advance.php');
        $api = $this->source('admin/blox_home_api.php');
        $editor = $this->source('admin/blox_editor.php');
        $frontend = $this->source('index.php');

        $this->assertStringContainsString("require_once __DIR__ . '/HomeLayoutDocument.php';", $bootstrap);
        foreach (['home_layout_data', 'home_layout_active', 'home_layout_published', 'home_layout_history'] as $key) {
            $this->assertStringContainsString($key, $layoutDocument);
        }
        foreach (['home_blox_data', 'home_blox_active', 'home_blox_published', 'home_blox_history'] as $key) {
            $this->assertStringContainsString($key, $bloxDocument);
        }
        $this->assertStringContainsString('HomeLayoutDocument::load()', $advanced);
        $this->assertStringContainsString('HomeLayoutDocument::saveDraft(', $advanced);
        $this->assertStringContainsString('HomeLayoutDocument::publishDraft()', $advanced);
        $this->assertStringContainsString('HomeBloxDocument::saveDraft(', $api);
        $this->assertStringContainsString('HomeBloxDocument::publishDraft()', $api);
        $this->assertStringContainsString('HomeBloxDocument::saveAndPublish(', $api);
        $this->assertStringContainsString('BloxDocumentPipeline::revisionMatches($currentDocumentJson(), $baseRevision)', $api);
        $this->assertStringContainsString('body.set("blocks_data", payload);', $editor);
        $this->assertStringContainsString('body.set("base_revision", this.baseRevision);', $editor);
        $this->assertStringContainsString('self.acceptSavedDocument(payload, savedData, res);', $editor);
        $header = $this->source('admin/blox_editor/partials/header.php');
        $this->assertStringContainsString(':disabled="homeActionBusy || saving" data-testid="blox-publish"', $header);
        $this->assertStringContainsString('data-testid="blox-save-publish-actions"', $header);
        $savePosition = strpos($header, 'data-testid="blox-save"');
        $publishPosition = strpos($header, 'data-testid="blox-publish"');
        $this->assertNotFalse($savePosition);
        $this->assertNotFalse($publishPosition);
        $this->assertLessThan($publishPosition, $savePosition);
        $this->assertStringContainsString('settingModel()->set(HomeBloxDocument::ACTIVE_KEY', $layoutDocument);
        $this->assertStringContainsString('settingModel()->set(HomeLayoutDocument::ACTIVE_KEY', $bloxDocument);
        $this->assertStringContainsString('$homeLayoutActive = HomeLayoutDocument::isActive() && HomeLayoutDocument::hasPublished();', $frontend);
        $this->assertStringContainsString('$homeBloxActive = !$homeLayoutActive && HomeBloxDocument::isActive() && HomeBloxDocument::hasPublished();', $frontend);
        $this->assertStringContainsString('HomeLayoutDocument::loadPublished()', $frontend);
    }

    public function testHomeAdvancedEditorUsesConditionalControlsAndCompactBannerReference(): void
    {
        $editor = $this->source('admin/page_edit_advance.php');

        foreach ([
            'visibleElementControls(el)',
            'controlRequirementMet(el, ctrl)',
            'HOME_LAYOUT_MODE',
            'isHomeBannerBlock(el)',
            '[banner-home]',
            'home_layout_banner_help',
            '/admin/banner.php',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }
    }
    public function testBloxEditorSharesReadableSectionLabelsWithFrontendNavigation(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        foreach ([
            'BlockRenderer::sectionLabelPolicy()',
            'sectionLabelText(value, maxLength)',
            'sectionElements(section)',
            'policy.decorativeTypes',
            'policy.elementTitleKeys',
            'typeLabel + " · " + title',
            'this.sectionLabel(this.sel, this.selectedSi)',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }
        $this->assertStringContainsString(':data-section-label="sectionLabel(section, si)"', $workspace);
        $this->assertStringContainsString('data-testid="blox-tree-section-label"', $workspace);
        $this->assertStringContainsString(':title="sectionLabel(section, si)"', $workspace);
    }

    public function testBloxEditorSupportsCustomSectionNamesAndAutomaticFallback(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        foreach ([
            'automaticSectionLabel(section, si)',
            'resolveSectionLabel(section, si, includeCustomName)',
            'title = this.sectionNameText(section && section.name || "", titleMax);',
            'normalizeSectionName(section)',
            'sectionNameText(value, maxLength)',
            'clearSectionName(section)',
            'delete target.name;',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }
        foreach ([
            'data-testid="blox-section-name-control"',
            'data-testid="blox-section-name"',
            'data-testid="blox-section-name-reset"',
            'data-testid="blox-section-auto-name"',
            '@blur="normalizeSectionName(sel)"',
            '@click="clearSectionName(sel)"',
            ':placeholder="automaticSectionLabel(sel, selectedSi)"',
        ] as $token) {
            $this->assertStringContainsString($token, $workspace);
        }
    }

    public function testFreeHomeLayoutEditorKeepsLargeDocumentsUsable(): void
    {
        $editor = $this->source('admin/page_edit_advance.php');

        foreach ([
            'openSections: {}',
            'toggleSection(section.id)',
            'expandAllSections()',
            'collapseAllSections()',
            'sectionLabel(section, si)',
            'sectionElementCount(section)',
            'isDirty: false',
            'beforeunload',
            'requestSubmit()',
            'layout-saved',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }
        $this->assertStringContainsString('@change="el.data[ctrl.key] = $event.target.value"', $editor);
        $this->assertStringContainsString(':selected="String(el.data[ctrl.key]', $editor);
    }
    public function testFreeHomeLayoutUsesGenericHomepageDataAndCompactBannerReference(): void
    {
        $editor = $this->source('admin/page_edit_advance.php');

        $this->assertStringNotContainsString('lumisignHomeLoadForAdmin', $editor);
        $this->assertStringNotContainsString('lumisignHomeSave', $editor);
        $this->assertStringNotContainsString('lumisign_home_editor.php', $editor);
        $this->assertStringNotContainsString('LumiSign 首页编辑', $editor);
        $this->assertStringContainsString('home_layout_editor_title', $editor);
        $this->assertStringContainsString('[banner-home]', $editor);
        $this->assertStringContainsString('/admin/banner.php', $editor);
        $this->assertStringContainsString('self.isHomeBannerBlock(el) && ctrl.key !== "enabled"', $editor);
        $this->assertStringContainsString('HomeLayoutDocument::saveDraft(', $editor);
        $this->assertStringContainsString('id="homePublishBtn"', $editor);
        $this->assertStringContainsString('id="homeRollbackBtn"', $editor);
        $this->assertStringContainsString('home_layout_state_active', $editor);
        $this->assertStringContainsString('template x-if="isHomeAboutBlock(el)"', $editor);
        $this->assertStringContainsString('grid grid-cols-1 lg:grid-cols-2', $editor);
        $this->assertStringContainsString('sectionColumnLabel(section)', $editor);
        // 列数标签走 lang 键（pea_cols_2），不再硬编码"2 列"——契约看的是「关于版块固定两列」这件事
        $this->assertStringContainsString("isHomeAboutBlock(el)) return ' . (string) json_encode(__('pea_cols_2')", $editor);
        $this->assertStringContainsString('x-model="el.data.override_content"', $editor);
        $this->assertStringContainsString('x-model="el.data.override_tag_title"', $editor);
        $this->assertStringContainsString('x-model="el.data.override_tag_description"', $editor);
        $this->assertStringContainsString('x-text="el.data.override_tag_title"', $editor);
        $this->assertStringContainsString('template x-if="isHomeStatsBlock(el)"', $editor);
        $this->assertStringContainsString('x-for="(item, statIndex) in el.data.stats_items"', $editor);
        $this->assertStringContainsString('x-for="icon in HOME_STATS_ICONS"', $editor);
        $this->assertStringContainsString("item.icon = icon; statIconPick = ''", $editor);
        $this->assertStringContainsString('x-model="item.number"', $editor);
        $this->assertStringContainsString('x-model="item.label"', $editor);
        $this->assertStringContainsString("isHomeStatsBlock(el)) return ' . (string) json_encode(__('pea_cols_4')", $editor);
        $this->assertStringContainsString('template x-if="isProductCarouselBlock(el)"', $editor);
        $this->assertStringContainsString('x-for="(productId, productIndex) in el.data.product_ids"', $editor);
        $this->assertStringContainsString('HOME_PRODUCT_OPTIONS', $editor);
        $this->assertStringContainsString('template x-if="isHomeChannelBlock(el)"', $editor);
        $this->assertStringContainsString('x-model.number="el.data.limit"', $editor);
        $this->assertStringContainsString('if (this.isHomeChannelBlock(el)) return', $editor);
        $this->assertStringContainsString('template x-if="isHomeAdvantageBlock(el)"', $editor);
        $this->assertStringContainsString('x-for="(item, advantageIndex) in el.data.advantage_items"', $editor);
        $this->assertStringContainsString('template x-if="isHomeCtaBlock(el)"', $editor);
        $this->assertStringContainsString('ctaPreviewStyle(el)', $editor);
        $this->assertStringContainsString('x-init="ensureCtaSettings(el)"', $editor);
        $this->assertStringContainsString('x-model="el.data.bg_color"', $editor);
        $this->assertStringContainsString('x-model="el.data.bg_image"', $editor);
        $this->assertStringContainsString('x-model.number="el.data.bg_opacity"', $editor);
        $this->assertStringContainsString('pickCtaBackground(el)', $editor);
        foreach (['aurora', 'business', 'default', 'minimal', 'trade'] as $theme) {
            $themeSource = $theme === 'default'
                ? 'themes/default/blocks/cta.php'
                : 'marketplace/themes/' . $theme . '/blocks/cta.php';
            $this->assertStringContainsString(
                'getBlockBg(',
                $this->source($themeSource),
                $theme . ' CTA must consume Builder background settings'
            );
        }
        $this->assertStringContainsString('template x-if="isHomePartnersBlock(el)"', $editor);
        $this->assertStringContainsString('/admin/link.php', $editor);
        $this->assertStringContainsString('template x-if="isHomeTestimonialsBlock(el)"', $editor);
        $this->assertStringContainsString('x-for="(item, testimonialIndex) in el.data.testimonial_items"', $editor);
        $this->assertStringContainsString("isHomeTestimonialsBlock(el)) return ' . (string) json_encode(__('pea_cols_3')", $editor);
        $this->assertStringContainsString(":class=\"el.data.override_layout === 'image_left' ? 'lg:order-1' : 'lg:order-2'\"", $editor);
        $this->assertStringContainsString(":class=\"el.data.override_layout === 'image_left' ? 'lg:order-2' : 'lg:order-1'\"", $editor);
        $this->assertStringContainsString('legacyBlockData($type', $this->source('includes/builder/HomeLayoutDocument.php'));
        $this->assertStringContainsString('legacyCustomSections($type)', $this->source('includes/builder/HomeLayoutDocument.php'));
        $this->assertStringContainsString('array_push($sections, ...$customSections)', $this->source('includes/builder/HomeLayoutDocument.php'));
    }
    public function testDynamicHomeBlockPanelUsesConditionalSchemaControls(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        // v1.18.6 拆分：controls 层在 SchemaControlsTrait（HomeBloxBlockSchema 为 use 四 trait 的类壳）
        $schema = $this->source('includes/builder/HomeBlox/HomeBloxSchemaControlsTrait.php');

        foreach ([
            'homeBlockSourceLabel()',
            'homeBlockSummary()',
            'controlRequirementMet(c)',
            'setControlValue(ctrl, $event.target.value)',
            'homeDynamicText.liveData',
        ] as $token) {
            $this->assertStringContainsString($token, $editor);
        }
        foreach ([
            "'key' => 'block_type'",
            "'key' => 'limit'",
            "'key' => 'sort'",
            "'key' => 'per_row'",
            "'key' => 'empty_state'",
            "'required' => ['empty_state', '=', 'message']",
        ] as $token) {
            $this->assertStringContainsString($token, $schema);
        }
    }

    public function testBloxCtaBackgroundUsesSchemaControlsAndMediaLibrary(): void
    {
        // v1.18.6 拆分：controls 层在 SchemaControlsTrait（HomeBloxBlockSchema 为 use 四 trait 的类壳）
        $schema = $this->source('includes/builder/HomeBlox/HomeBloxSchemaControlsTrait.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        $theme = $this->source('themes/default/blocks/cta.php');

        foreach ([
            "'key' => 'bg_image'",
            "'key' => 'bg_color'",
            "'key' => 'bg_opacity'",
            "'type' => 'range'",
            "'key' => 'text_light'",
            "'required' => ['block_type', '=', 'cta']",
            "'key' => 'background'",
            "__('blox_home_cta_background')",
        ] as $token) {
            $this->assertStringContainsString($token, $schema, "CTA schema token {$token} missing");
        }
        foreach ([
            "ctrl.type === 'range'",
            "@input=\"setControlValue(ctrl, Number(\$event.target.value))\"",
            "@click=\"openMedia(u => selEl.data[ctrl.key] = u)\"",
            "'blox-control-' + ctrl.key",
        ] as $token) {
            $this->assertStringContainsString($token, $workspace, "CTA control token {$token} missing");
        }
        $this->assertStringContainsString('getBlockBg(', $theme);
    }

    public function testAboutBlockUsesRealDesktopViewportAndClickableInternalFields(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');
        $context = $this->source('includes/builder/HomeBloxRenderContext.php');
        $about = $this->source('themes/default/blocks/about.php');

        foreach ([
            'previewScale()',
            'previewDesktopWidth()',
            'previewShellStyle()',
            'previewFrameStyle()',
            'homeEditorBlueprints',
            'homeFieldGroups(el)',
            'isProjectedHomeColumnsSection(section)',
            'projectedHomeElement(section)',
            'selectProjectedHomeColumn(si, group)',
            'selectHomeColumn(path, column, notifyCanvas)',
            'selectedHomeColumnLabel()',
            'homeFieldGroupKey(el, field)',
            'homeColumnProjection(el)',
            'homeGroupSpanLabel(projectedHomeElement(section), group)',
            'homeFieldAllowed(el, field)',
            'setHomeFieldValue(el, field, value)',
            'data-about-layout-control',
            "ctrl.type === 'about_breakpoint'",
            "selEl.data[ctrl.key] = 'md'",
            "selEl.data[ctrl.key] = 'lg'",
            'aboutRatioOptions',
            'swapAboutColumns()',
            'swapSelectedColumns()',
            'selectedHomeFieldDefinition()',
            'selectHomeField(path, field, notifyCanvas)',
            'selectedHomeField',
            'selectedHomeColumn',
            'data-home-column-editor',
            'data-home-column-tree',
            'data-control-key',
            'data-home-field-editor',
            'onPickHomeField',
            'ykHighlightHomeField',
            'onPickHomeColumn',
            'ykHighlightHomeColumn',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "editor about token {$token} missing");
        }
        foreach ([
            'data-yk-home-field',
            'data-yk-home-column',
            'data-yk-home-column-label',
            'data-yk-home-layout-label',
            'homeFieldTarget(node)',
            'homeColumnTarget(node)',
            'highlightHomeField(path, field)',
            'highlightHomeColumn(path, column)',
            'ykPickHomeField',
            'ykHighlightHomeField',
            'ykPickHomeColumn',
            'ykHighlightHomeColumn',
            "kind: 'homeField'",
        ] as $token) {
            $this->assertStringContainsString($token, $canvas, "canvas about token {$token} missing");
        }
        foreach ([
            '$aboutColumnPathAttr',
            "config('home_about_breakpoint', 'lg')",
            'data-yk-home-breakpoint=',
            'data-yk-home-column="text"',
            'data-yk-home-column="image"',
            'data-yk-home-path="',
        ] as $token) {
            $this->assertStringContainsString($token, $about, "about template token {$token} missing");
        }
        $this->assertStringContainsString('withAboutFieldMarkers($html, $path)', $context);
        $this->assertStringContainsString("'ykHomePath' => " . '$path', $context);
        $this->assertStringContainsString('$ykHomeFieldAttr', $context);
        $this->assertStringContainsString('HomeBloxBlockSchema::isEditableFieldPath($type, $field)', $context);
        $this->assertStringContainsString("'override_image'", $context);
    }

    public function testDesktopCanvasRecalculatesAfterLayoutAndUsesCompactGutters(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        $this->assertStringContainsString('self.observeCanvasHost();', $editor);
        $this->assertStringContainsString('new ResizeObserver(update)', $editor);
        $this->assertStringContainsString('requestAnimationFrame(update)', $editor);
        $this->assertStringContainsString("host.clientWidth : 1280) - 24", $editor);
        $this->assertStringContainsString('justify-center p-3', $editor);
        $this->assertStringContainsString('window.innerHeight - 80', $editor);
        $this->assertStringContainsString('Math.max(1280, Math.round(this.previewCanvasAvailable()))', $editor);
        $this->assertStringNotContainsString('Math.min(1600', $editor);
        $this->assertStringContainsString('this.previewCanvasAvailable() / this.previewDesktopWidth()', $editor);
        $this->assertStringContainsString('var desktopWidth = this.previewDesktopWidth();', $editor);
        $this->assertStringNotContainsString('class="relative transition-all duration-300" :style="previewShellStyle()"', $editor);
    }

    public function testCanvasShowsContainerBoundaryBeforeHoverOrSelection(): void
    {
        $canvas = $this->source('admin/page_edit_advance.php');

        $this->assertStringContainsString(
            '[data-yk-con]{position:relative;cursor:pointer;outline:1px dashed rgba(245,158,11,.55)',
            $canvas
        );
        $this->assertStringContainsString('[data-yk-con]:hover{outline:2px dashed #f59e0b', $canvas);
        $this->assertStringContainsString('[data-yk-con].yk-con-selected{outline:2px solid #f59e0b', $canvas);
    }

    public function testSectionContainerBackgroundImageHasACompleteEditAndRenderPath(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        $pipeline = $this->source('includes/builder/BloxDocumentPipeline.php');
        $renderer = $this->source('includes/builder/BlockRenderer.php');

        $this->assertStringContainsString('pickContainerBackgroundImage()', $editor);
        $this->assertStringContainsString('container_bg_image: ""', $editor);
        $this->assertStringContainsString('data-testid="blox-container-background-image"', $workspace);
        $this->assertStringContainsString('x-model="sel.settings.container_bg_image"', $workspace);
        $this->assertStringContainsString('@click="pickContainerBackgroundImage()"', $workspace);
        $this->assertStringContainsString('data-testid="blox-container-overlay-color"', $workspace);
        $this->assertStringContainsString('data-testid="blox-container-overlay-opacity"', $workspace);
        $this->assertStringContainsString('data-testid="blox-column-background-image"', $workspace);
        $this->assertStringContainsString('data-testid="blox-column-overlay-color"', $workspace);
        $this->assertStringContainsString('data-testid="blox-column-overlay-opacity"', $workspace);
        $this->assertStringContainsString("array_key_exists('container_bg_image', \$settings)", $pipeline);
        $this->assertStringContainsString("'container_bg_overlay_color'", $pipeline);
        $this->assertStringContainsString("'card_bg_overlay_color'", $pipeline);
        $this->assertStringContainsString("\$settings['container_bg_image']", $renderer);
        $this->assertStringContainsString("\$settings['container_bg_overlay_color']", $renderer);
        $this->assertStringContainsString("\$column['card_bg_overlay_color']", $renderer);
        $this->assertStringContainsString('background-size:cover;background-position:center', $renderer);
    }

    public function testEmptySectionContextMenuCanDeleteFromCanvasContainerOrColumn(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');

        $this->assertStringContainsString("e.target.closest('.yk-empty-hint')", $canvas);
        $this->assertStringContainsString("return { kind: 'section', target: { si: emptySi } };", $canvas);
        $this->assertStringContainsString('ctxSectionIsEmpty(target)', $editor);
        $this->assertStringContainsString('key: "deleteSection"', $editor);
        $this->assertStringContainsString('if (action === "deleteSection") { this.deleteSection(t.si); return; }', $editor);
        $this->assertStringContainsString(':disabled="item.disabled === true"', $editor);
    }

    public function testTwoColumnLayoutHasDirectRatioControls(): void
    {
        $editor = $this->source('admin/blox_editor.php');

        foreach ([
            'type="range" min="2" max="10" step="1"',
            'twoColumnLeftSpan()',
            'setTwoColumnDivider(span)',
            'adjustTwoColumnDivider(delta)',
            'twoColumnRatioLabel()',
            'this.writeSpan(s.columns[0], left, this.rawSpanT(s.columns[0]));',
            'this.writeSpan(s.columns[1], 12 - left, this.rawSpanT(s.columns[1]));',
            'aboutRatioIndex()',
            'setAboutRatioIndex(index)',
            'adjustAboutRatio(delta)',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "two-column ratio token {$token} missing");
        }
    }
    public function testCanvasTwoColumnDividerCommitsOneRatioChange(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');

        foreach ([
            '.yk-column-resizer',
            'setupColumnResizers()',
            'setPointerCapture(e.pointerId)',
            'grid.style.gridTemplateColumns',
            'ykColumnRatio',
            "kind: 'home'",
            "kind: 'section'",
        ] as $token) {
            $this->assertStringContainsString($token, $canvas, "canvas divider token {$token} missing");
        }
        $this->assertStringContainsString('onColumnRatio: function (payload) { self.applyCanvasColumnRatio(payload); }', $editor);
        $this->assertStringContainsString('applyCanvasColumnRatio(payload)', $editor);
        $this->assertStringContainsString('section.columns.length < 2', $editor);
    }

    public function testCanvasNestedHomeIconsOpenTheSearchableLibrary(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');

        $this->assertStringContainsString('stats_items\.[0-3]', $canvas);
        $this->assertStringContainsString("!homeTarget.field.endsWith('.icon')", $canvas);
        $this->assertStringContainsString('data-home-icon-library', $editor);
        $this->assertStringContainsString('selectedHomeFieldDefinition().options', $editor);
        $this->assertStringContainsString('iconMatches()', $editor);
        $this->assertStringContainsString('homeDynamicText.iconSearch', $editor);
    }

    public function testCanvasMultiColumnDividersPreserveTwelveColumnGrid(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $canvas = $this->source('admin/page_edit_advance.php');
        $renderer = $this->source('includes/builder/BlockRenderer.php');

        $this->assertStringContainsString('data-yk-col-span=', $renderer);
        $this->assertStringContainsString('Array.isArray(payload.spans)', $editor);
        $this->assertStringContainsString('spans.reduce(function (total, span)', $editor);
        $this->assertStringContainsString('dividerIndex', $canvas);
        $this->assertStringContainsString("spans: state.spans.slice()", $canvas);
        $this->assertStringContainsString('columns.length >= 2', $canvas);
        $this->assertStringContainsString('pairTotal - nextLeft', $canvas);
    }

    public function testContainerCanChooseTabletColumnBehavior(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $renderer = $this->source('includes/builder/BlockRenderer.php');

        foreach ([
            "__('blox_tablet_layout')",
            "__('blox_keep_columns')",
            "__('blox_stack_single')",
            'sel.settings.tablet_stack = false',
            'sel.settings.tablet_stack = true',
            'tablet_stack: false',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "tablet layout token {$token} missing");
        }
        $this->assertStringContainsString('GRIDCOL_DESKTOP_MAP', $renderer);
        $this->assertStringContainsString('COLSPAN_DESKTOP_MAP', $renderer);
        $this->assertStringContainsString("'tablet_stack'", $renderer);
    }

    public function testElementLibraryFavoritesAndRecentsStayLocalAndSearchable(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        foreach ([
            'favoriteElementsStorageKey: "yikai:blox:element-favorites:v1"',
            'recentElementsStorageKey: "yikai:blox:element-recent:v1"',
            'restoreElementLibraryPreferences()',
            'toggleElementFavorite(type)',
            'rememberRecentElement(type)',
            'quick: true, items: favoriteItems',
            'this.historyData() !== before',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "element library token {$token} missing");
        }
        $this->assertStringContainsString("'blox-quick-favorite-element-' : 'blox-favorite-element-'", $workspace);
        $this->assertStringContainsString('@click.stop="toggleElementFavorite(el.type)"', $workspace);
        $this->assertStringContainsString('focus-visible:ring-2', $workspace);
    }

    public function testElementLibraryCategoryFilterComposesWithSearch(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        foreach ([
            'elementCategoryOptions:',
            'libCategory: "all"',
            'var category = this.libCategory || "all";',
            'el.category !== category',
            'if (!q && category === "all")',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "element category token {$token} missing");
        }
        $this->assertStringContainsString('data-testid="blox-element-category"', $workspace);
        $this->assertStringContainsString('x-model="libCategory"', $workspace);
        $this->assertStringContainsString("libCategory !== 'all'", $workspace);
    }

    public function testPrebuiltLibraryFavoritesAndRecentsStayLocalAndOnlyTrackSuccessfulInserts(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        foreach ([
            'favoriteTemplatesStorageKey: "yikai:blox:template-favorites:v1"',
            'recentTemplatesStorageKey: "yikai:blox:template-recent:v1"',
            'restoreTemplateLibraryPreferences()',
            'toggleTemplateFavorite(key)',
            'rememberRecentTemplate(key)',
            'if (item.type === "section") self.rememberRecentTemplate(item.key);',
            'templateQuickCount(mode)',
            'rank: self.isTemplateFavorite(item.key) ? 0',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "prebuilt preference token {$token} missing");
        }
        foreach ([
            'data-testid="blox-template-quick-favorites"',
            'data-testid="blox-template-quick-recent"',
            "@click.stop=\"toggleTemplateFavorite(item.key)\"",
            "'blox-template-favorite-' + item.key",
            ':aria-pressed="isTemplateFavorite(item.key)"',
        ] as $token) {
            $this->assertStringContainsString($token, $overlays, "prebuilt preference UI token {$token} missing");
        }
    }

    public function testPrebuiltLibraryDensityIsPersistentAndCompactLayoutIsBounded(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        foreach ([
            'templateDensity: "standard"',
            'templateDensityStorageKey: "yikai:blox:template-density:v1"',
            'templateCompactSections()',
            'setTemplateDensity(density)',
            'density === "compact" ? "compact" : "standard"',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "prebuilt density token {$token} missing");
        }
        foreach ([
            'data-testid="blox-template-density-standard"',
            'data-testid="blox-template-density-compact"',
            '@click="setTemplateDensity(\'compact\')"',
            "templateCompactSections() ? 'h-24 min-h-0 flex-row'",
            "templateCompactSections() ? 'w-32 shrink-0 border-r border-gray-100 aspect-auto bg-white'",
            'data-testid="blox-template-section-bar"',
        ] as $token) {
            $this->assertStringContainsString($token, $overlays, "prebuilt density UI token {$token} missing");
        }
    }

    public function testPrebuiltCardsUseVisualFirstPreviewAndStableActionBar(): void
    {
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        foreach ([
            "templateEntry === 'sections' ? 'object-contain' : 'object-cover'",
            'data-testid="blox-template-section-bar"',
            'group-hover:bg-blue-50/50',
            "templateDragItem && templateDragItem.key === item.key ? 'border-blue-500 ring-2 ring-blue-100 shadow-sm'",
            'border border-blue-200 bg-white px-3',
            'data-testid="blox-template-edit"',
            'h-8 w-8 shrink-0 rounded border border-gray-200 bg-white',
        ] as $token) {
            $this->assertStringContainsString($token, $overlays, "visual-first prebuilt card token {$token} missing");
        }
        $this->assertStringNotContainsString('item.description && !templateCompactSections()', $overlays);
    }

    public function testPrebuiltLibraryRestoresSessionFiltersAndScrollPosition(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        foreach ([
            'templateSectionViewStorageKey: "yikai:blox:template-section-view:v1"',
            'templateSectionScrollTop: 0',
            'restoreTemplateSectionViewState()',
            'normalizeTemplateSectionViewState()',
            'rememberTemplateSectionScroll(scrollTop)',
            'persistTemplateSectionViewState()',
            'restoreTemplateSectionScroll()',
            'window.sessionStorage.setItem(this.templateSectionViewStorageKey',
            'this.persistTemplateSectionViewState();',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "prebuilt session state token {$token} missing");
        }
        foreach ([
            'x-ref="templateScroll"',
            '@scroll.passive="rememberTemplateSectionScroll($event.target.scrollTop)"',
        ] as $token) {
            $this->assertStringContainsString($token, $overlays, "prebuilt session scroll token {$token} missing");
        }
    }

    public function testPrebuiltLibraryEmptyStatesExplainAndClearActiveFilters(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        foreach ([
            'templateEmptyReason()',
            'templateEmptyMessage()',
            'templateEmptyIcon()',
            'templateCanClearFilters()',
            'clearTemplateSectionFilters()',
            'this.templateQuery = "";',
            'this.templateCategory = "all";',
            'this.templateQuickFilter = "all";',
            'this.templateSectionScrollTop = 0;',
            'this.persistTemplateSectionViewState();',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "prebuilt empty state token {$token} missing");
        }
        foreach ([
            'data-testid="blox-template-empty"',
            ':data-empty-reason="templateEmptyReason()"',
            'x-text="templateEmptyMessage()"',
            'data-testid="blox-template-clear-filters"',
            '@click="clearTemplateSectionFilters()"',
        ] as $token) {
            $this->assertStringContainsString($token, $overlays, "prebuilt empty UI token {$token} missing");
        }
    }

    public function testPaletteTapInsertionRequiresAnExplicitTarget(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        foreach ([
            'paletteTapMode: false',
            'syncPaletteInputMode()',
            'keyboard || this.paletteTapMode',
            'this.sections.length > 0 && this.selectedSi < 0',
            'this.toast(this.uiText.pickSectionFirst)',
        ] as $token) {
            $this->assertStringContainsString($token, $editor, "palette insertion token {$token} missing");
        }
        $this->assertStringContainsString('paletteTapMode && selectedSi < 0', $workspace);
        $this->assertStringContainsString('data-testid="blox-pick-section-hint"', $workspace);
        $this->assertStringContainsString('aria-live="polite"', $overlays);
    }
}
