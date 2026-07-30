<?php
/**
 * Blox 全屏可视化编辑器（实验）—— 对标 Bricks 的三栏画布界面。
 *
 * 隔离设计：不套后台 header/footer 外壳，自渲染全屏 shell；预览与保存全部复用
 * page_edit_advance.php 的现有端点（action=preview 已含画布点选注入；主 POST 为保存+存档），
 * 因此本文件零渲染/存储逻辑重复，只提供全屏 UI 与画布交互。
 *
 * 演进路线（blox 分期）：①画布点选(已) → ②画布拖拽排序 → ③元素拖入 → ④文字内联编辑。
 * 当前版本：全屏三栏 + 结构树 + 画布双向点选 + 区块级设置 + 保存。元素级编辑暂由「高级构建器」承接。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

requirePermission('edit_page');

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$id = getInt('id');
// 无 id 时默认落到 blox 沙盒页，方便直接进来试
if (!$id) {
    $sandbox = channelModel()->findWhere(['slug' => 'blox-sandbox', 'type' => 'page']);
    if ($sandbox) {
        header('Location: /admin/blox_editor.php?id=' . (int) $sandbox['id']);
        exit;
    }
    header('Location: /admin/page.php');
    exit;
}

$page = channelModel()->findWhere(['id' => $id, 'type' => 'page']);
if (!$page) {
    header('Location: /admin/page.php');
    exit;
}

// 读取 blocks_data（与 page_edit_advance 同源）
$contentRecord = contentModel()->queryOne(
    'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 ORDER BY is_top DESC, id DESC LIMIT 1',
    [$id]
);
$blocksData = $contentRecord['blocks_data'] ?? '';
$initBlocks = trim((string) $blocksData) !== '' ? $blocksData : '[]';
// 校验是 JSON 数组，避免注入进 JS 出错
json_decode($initBlocks, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $initBlocks = '[]';
}

$saveEndpoint = '/admin/page_edit_advance.php?id=' . $id;
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(siteLang()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Blox 编辑器 · <?php echo e($page['name']); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <script defer src="/assets/alpinejs/collapse.min.js"></script>
    <script defer src="/assets/alpinejs/alpine.min.js"></script>
    <script src="/assets/sortable/Sortable.min.js"></script>
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; }
        [x-cloak] { display: none !important; }
        .blox-scroll { scrollbar-width: thin; }
        .blox-scroll::-webkit-scrollbar { width: 6px; }
        .blox-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800" x-data="bloxEditor()" x-init="init()" x-cloak>

    <!-- ===== 顶栏 ===== -->
    <header class="h-14 bg-gray-900 text-white flex items-center justify-between px-4 gap-4 select-none">
        <div class="flex items-center gap-3 min-w-0">
            <a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>"
               class="text-gray-300 hover:text-white inline-flex items-center gap-1 text-sm shrink-0" title="返回高级构建器">
                <i class="ti ti-chevron-left text-lg"></i>
            </a>
            <span class="inline-flex items-center gap-1.5 font-bold tracking-wide shrink-0">
                <i class="ti ti-stack-2 text-blue-400"></i>Blox
                <span class="text-[10px] font-medium bg-blue-500/20 text-blue-300 px-1.5 py-0.5 rounded">实验</span>
            </span>
            <span class="text-gray-400 text-sm truncate">/ <?php echo e($page['name']); ?></span>
        </div>

        <!-- 设备切换 -->
        <div class="flex items-center gap-1 bg-gray-800 rounded-lg p-1">
            <template x-for="d in devices" :key="d.key">
                <button type="button" @click="previewDevice = d.key" :title="d.label"
                        class="w-8 h-7 rounded-md inline-flex items-center justify-center transition"
                        :class="previewDevice === d.key ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'">
                    <i class="ti text-base" :class="d.icon"></i>
                </button>
            </template>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <span class="text-xs text-gray-400" x-show="previewLoading">刷新中…</span>
            <a :href="'/' + '<?php echo e($page['slug']); ?>' + '.html'" target="_blank"
               class="text-gray-300 hover:text-white text-sm inline-flex items-center gap-1 px-2 py-1.5" title="前台预览">
                <i class="ti ti-external-link text-base"></i>
            </a>
            <button type="button" @click="save()" :disabled="saving"
                    class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-sm font-medium px-4 py-1.5 rounded-lg inline-flex items-center gap-1.5 transition">
                <i class="ti text-base" :class="saving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'"></i>
                <span x-text="saving ? '保存中' : '保存'"></span>
            </button>
        </div>
    </header>

    <!-- ===== 三栏主体 ===== -->
    <div class="flex" style="height: calc(100vh - 3.5rem);">

        <!-- 左：结构树 + 加区块 -->
        <aside class="w-64 shrink-0 bg-white border-r border-gray-200 flex flex-col">
            <div class="h-10 px-3 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1">
                    <i class="ti ti-list-tree text-sm"></i>结构
                </span>
                <span class="text-[10px] text-gray-400" x-text="sections.length + ' 区块'"></span>
            </div>
            <div class="flex-1 overflow-y-auto blox-scroll p-2 space-y-1" x-ref="tree">
                <template x-if="sections.length === 0">
                    <p class="text-xs text-gray-400 text-center py-8">还没有区块，从下方添加</p>
                </template>
                <template x-for="(section, si) in sections" :key="section.id">
                    <div @click="selectSection(si)"
                         class="rounded-lg border cursor-pointer transition group"
                         :class="selectedSi === si ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:border-blue-200'">
                        <div class="flex items-center gap-2 px-2.5 py-2">
                            <i class="ti ti-layout-board text-sm shrink-0"
                               :class="selectedSi === si ? 'text-blue-500' : 'text-gray-400'"></i>
                            <span class="text-sm truncate flex-1" x-text="'区块 ' + (si + 1)"></span>
                            <span class="text-[10px] text-gray-400" x-text="elCount(section) + ' 元素'"></span>
                        </div>
                        <!-- 该区块的操作（选中时展开） -->
                        <div x-show="selectedSi === si" class="flex items-center gap-1 px-2 pb-2" x-collapse>
                            <button type="button" @click.stop="moveSection(si,-1)" :disabled="si===0"
                                    class="p-1 text-gray-400 hover:text-blue-500 disabled:opacity-30" title="上移">
                                <i class="ti ti-arrow-up text-sm"></i></button>
                            <button type="button" @click.stop="moveSection(si,1)" :disabled="si===sections.length-1"
                                    class="p-1 text-gray-400 hover:text-blue-500 disabled:opacity-30" title="下移">
                                <i class="ti ti-arrow-down text-sm"></i></button>
                            <button type="button" @click.stop="duplicateSection(si)"
                                    class="p-1 text-gray-400 hover:text-blue-500" title="复制">
                                <i class="ti ti-copy text-sm"></i></button>
                            <div class="flex-1"></div>
                            <button type="button" @click.stop="deleteSection(si)"
                                    class="p-1 text-gray-400 hover:text-red-500" title="删除">
                                <i class="ti ti-trash text-sm"></i></button>
                        </div>
                    </div>
                </template>
            </div>
            <!-- 加区块 -->
            <div class="border-t border-gray-100 p-2 shrink-0">
                <div class="text-[10px] text-gray-400 mb-1.5 px-1">添加区块（列数）</div>
                <div class="grid grid-cols-4 gap-1">
                    <template x-for="n in [1,2,3,4]" :key="n">
                        <button type="button" @click="addSection(n)"
                                class="h-9 rounded-md border border-gray-200 text-gray-500 hover:border-blue-400 hover:text-blue-500 text-xs font-medium transition"
                                x-text="n + ' 列'"></button>
                    </template>
                </div>
            </div>
        </aside>

        <!-- 中：画布 -->
        <main class="flex-1 min-w-0 bg-gray-200 overflow-auto flex items-start justify-center p-6">
            <iframe x-ref="canvas"
                    class="bg-white shadow-xl border-0 transition-all duration-300 rounded"
                    :style="'width:' + previewWidth() + '; max-width:100%; height: calc(100vh - 6.5rem);'"></iframe>
        </main>

        <!-- 右：设置面板 -->
        <aside class="w-72 shrink-0 bg-white border-l border-gray-200 flex flex-col">
            <div class="h-10 px-3 flex items-center border-b border-gray-100 shrink-0">
                <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1">
                    <i class="ti ti-adjustments text-sm"></i>
                    <span x-text="sel ? ('区块 ' + (selectedSi + 1) + ' 设置') : '设置'"></span>
                </span>
            </div>
            <div class="flex-1 overflow-y-auto blox-scroll p-4">
                <template x-if="!sel">
                    <div class="text-center text-gray-400 pt-16 px-4">
                        <i class="ti ti-click text-3xl mb-2 block"></i>
                        <p class="text-sm">在画布或左侧结构里点选一个区块，这里显示它的设置</p>
                    </div>
                </template>

                <template x-if="sel">
                    <div class="space-y-5">
                        <!-- 背景色 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">背景颜色</label>
                            <div class="flex items-center gap-2">
                                <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                       :value="sel.settings.bg_color || '#ffffff'"
                                       @input="sel.settings.bg_color = $event.target.value">
                                <input type="text" class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm"
                                       placeholder="留空=透明" x-model="sel.settings.bg_color">
                                <button type="button" @click="sel.settings.bg_color = ''"
                                        class="text-gray-400 hover:text-red-500 p-1" title="清除">
                                    <i class="ti ti-x text-sm"></i></button>
                            </div>
                        </div>
                        <!-- 上下内边距 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">上下间距</label>
                            <div class="grid grid-cols-5 gap-1">
                                <template x-for="opt in padOptions" :key="opt.k">
                                    <button type="button" @click="sel.settings.padding = opt.k"
                                            class="h-8 rounded text-xs border transition"
                                            :class="(sel.settings.padding || 'md') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                            x-text="opt.label"></button>
                                </template>
                            </div>
                        </div>
                        <!-- 内容宽度 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">内容宽度</label>
                            <select x-model="sel.settings.max_width"
                                    class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                                <option value="default">标准（1152px）</option>
                                <option value="narrow">窄（896px）</option>
                                <option value="wide">宽（1280px）</option>
                                <option value="full">通栏（全宽）</option>
                            </select>
                        </div>
                        <!-- 列间距 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">列间距</label>
                            <div class="grid grid-cols-5 gap-1">
                                <template x-for="opt in padOptions" :key="'g'+opt.k">
                                    <button type="button" @click="sel.settings.gap = opt.k"
                                            class="h-8 rounded text-xs border transition"
                                            :class="(sel.settings.gap || 'lg') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                            x-text="opt.label"></button>
                                </template>
                            </div>
                        </div>

                        <!-- 元素编辑（v1 暂由高级构建器承接） -->
                        <div class="pt-3 border-t border-gray-100">
                            <div class="text-xs text-gray-400 mb-2" x-text="'本区块含 ' + elCount(sel) + ' 个元素'"></div>
                            <a :href="'/admin/page_edit_advance.php?id=<?php echo $id; ?>&focus=' + selectedSi"
                               class="w-full inline-flex items-center justify-center gap-1.5 text-sm border border-gray-200 rounded-lg py-2 text-gray-600 hover:border-blue-400 hover:text-blue-500 transition">
                                <i class="ti ti-pencil text-base"></i>编辑元素（高级构建器）
                            </a>
                            <p class="text-[10px] text-gray-400 mt-1.5 leading-relaxed">
                                元素级增删改与内联编辑正在 blox 里逐步实现，当前先用高级构建器编辑内部元素。
                            </p>
                        </div>
                    </div>
                </template>
            </div>
        </aside>
    </div>

    <!-- toast -->
    <div x-show="toastMsg" x-transition
         class="fixed bottom-5 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-sm px-4 py-2 rounded-lg shadow-lg z-50"
         x-text="toastMsg" style="display:none"></div>

    <script>
    function bloxEditor() {
        return {
            sections: <?php echo $initBlocks; ?>,
            selectedSi: -1,
            previewDevice: "desktop",
            previewLoading: false,
            saving: false,
            toastMsg: "",
            _debounce: null,
            _tt: null,
            csrf: "<?php echo csrfToken(); ?>",
            endpoint: "<?php echo $saveEndpoint; ?>",
            devices: [
                { key: "desktop", label: "桌面", icon: "ti-device-desktop" },
                { key: "tablet",  label: "平板", icon: "ti-device-tablet" },
                { key: "mobile",  label: "手机", icon: "ti-device-mobile" },
            ],
            padOptions: [
                { k: "none", label: "无" }, { k: "sm", label: "小" }, { k: "md", label: "中" },
                { k: "lg", label: "大" }, { k: "xl", label: "特" },
            ],

            get sel() { return this.selectedSi >= 0 && this.sections[this.selectedSi] ? this.sections[this.selectedSi] : null; },

            init() {
                var self = this;
                this.$nextTick(function() { self.refreshPreview(); });
                // 区块/设置变化 → 防抖刷新画布
                this.$watch("sections", function() { self.schedulePreview(); });
                // 画布点选 → 回传 → 选中
                window.addEventListener("message", function(e) {
                    if (e && e.data && typeof e.data.ykPick === "number") self.selectedSi = e.data.ykPick;
                });
            },

            schedulePreview() {
                var self = this;
                clearTimeout(this._debounce);
                this._debounce = setTimeout(function() { self.refreshPreview(); }, 400);
            },

            previewWidth() {
                return ({ desktop: "100%", tablet: "768px", mobile: "390px" })[this.previewDevice] || "100%";
            },

            refreshPreview() {
                var self = this, frame = this.$refs.canvas;
                if (!frame) return;
                this.previewLoading = true;
                var body = new URLSearchParams();
                body.set("action", "preview");
                body.set("blocks_data", JSON.stringify(this.sections));
                body.set("_token", this.csrf);
                fetch(this.endpoint, { method: "POST", body: body })
                    .then(function(r) { return r.text(); })
                    .then(function(html) {
                        frame.srcdoc = html;
                        // 预览重载后，若有选中项，重新在画布里高亮
                        if (self.selectedSi >= 0) {
                            frame.addEventListener("load", function once() {
                                frame.removeEventListener("load", once);
                                if (frame.contentWindow) frame.contentWindow.postMessage({ ykHighlight: self.selectedSi }, "*");
                            });
                        }
                    })
                    .catch(function() {})
                    .finally(function() { self.previewLoading = false; });
            },

            selectSection(si) {
                this.selectedSi = si;
                var frame = this.$refs.canvas;
                if (frame && frame.contentWindow) frame.contentWindow.postMessage({ ykHighlight: si }, "*");
            },

            elCount(section) {
                return (section.columns || []).reduce(function(n, c) { return n + ((c.elements || []).length); }, 0);
            },

            uid(p) { return p + "_" + Math.random().toString(36).substr(2, 9); },

            moveSection(si, dir) {
                var ni = si + dir;
                if (ni < 0 || ni >= this.sections.length) return;
                var s = this.sections.splice(si, 1)[0];
                this.sections.splice(ni, 0, s);
                this.selectedSi = ni;
            },

            duplicateSection(si) {
                var copy = JSON.parse(JSON.stringify(this.sections[si]));
                copy.id = this.uid("s");
                (copy.columns || []).forEach(function(c) { c.id = "c_" + Math.random().toString(36).substr(2, 9); });
                this.sections.splice(si + 1, 0, copy);
                this.selectedSi = si + 1;
            },

            deleteSection(si) {
                if (!confirm("删除这个区块？其内元素会一并删除。")) return;
                this.sections.splice(si, 1);
                if (this.selectedSi === si) this.selectedSi = -1;
                else if (this.selectedSi > si) this.selectedSi--;
            },

            addSection(cols) {
                var c = [];
                for (var i = 0; i < cols; i++) c.push({ id: this.uid("c"), elements: [] });
                this.sections.push({
                    id: this.uid("s"), type: "section",
                    settings: { bg_color: "", bg_image: "", padding: "md", max_width: "default", align_items: "stretch", justify_items: "stretch", gap: "lg" },
                    columns: c,
                });
                this.selectedSi = this.sections.length - 1;
            },

            save() {
                var self = this;
                this.saving = true;
                var body = new URLSearchParams();
                body.set("name", <?php echo json_encode($page['name'], JSON_UNESCAPED_UNICODE); ?>);
                body.set("slug", <?php echo json_encode($page['slug'], JSON_UNESCAPED_UNICODE); ?>);
                body.set("description", <?php echo json_encode((string) ($page['description'] ?? ''), JSON_UNESCAPED_UNICODE); ?>);
                body.set("image", <?php echo json_encode((string) ($page['image'] ?? ''), JSON_UNESCAPED_UNICODE); ?>);
                body.set("seo_title", <?php echo json_encode((string) ($page['seo_title'] ?? ''), JSON_UNESCAPED_UNICODE); ?>);
                body.set("seo_keywords", <?php echo json_encode((string) ($page['seo_keywords'] ?? ''), JSON_UNESCAPED_UNICODE); ?>);
                body.set("seo_description", <?php echo json_encode((string) ($page['seo_description'] ?? ''), JSON_UNESCAPED_UNICODE); ?>);
                body.set("blocks_data", JSON.stringify(this.sections));
                body.set("_token", this.csrf);
                fetch(this.endpoint, { method: "POST", body: body })
                    .then(function(r) { return r.json().catch(function() { return { success: false }; }); })
                    .then(function(res) { self.toast(res && res.success !== false ? "已保存" : "保存失败：" + (res.message || "")); })
                    .catch(function() { self.toast("保存失败"); })
                    .finally(function() { self.saving = false; });
            },

            toast(msg) {
                var self = this;
                this.toastMsg = msg;
                clearTimeout(this._tt);
                this._tt = setTimeout(function() { self.toastMsg = ""; }, 2200);
            },
        };
    }
    </script>
</body>
</html>
