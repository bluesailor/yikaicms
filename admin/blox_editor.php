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

/**
 * 元素库（第一批）。
 *
 * 数据源是 BuilderRegistry::meta()——18 种元素的 label / icon / category / defaults
 * 都在里面，这里只做**筛选**，不另抄一份清单（抄一份就会漂）。
 *
 * 第一批只放「用默认值就能在画布上看见效果」的：blox 目前还没有元素级设置面板，
 * 插入后只能跳到高级构建器改内容。若放 image / card / video 这类默认渲染为空的，
 * 插进去画布毫无变化，看着像坏了。
 * 同理动态类（轮播图 / 导航菜单 / 动态列表）需要先选数据源，也押后。
 *
 * 加元素只需往这个数组里添 type —— 面板、分组、图标都会自动跟上。
 */
$bloxElementTypes = ['heading', 'text', 'button', 'quote', 'alert', 'icon', 'icon-box', 'divider', 'spacer'];

/**
 * 插入时的占位内容。
 *
 * 注册表的 defaults 把主内容字段留空（heading.text / text.html / quote.text 都是 ""），
 * 高级构建器照搬即可——它把元素显示成可编辑的卡片，空着也看得见。但 blox 的画布是
 * **渲染后的预览**：插入一个空标题，画布上什么都不会出现，像是没插进去。
 *
 * 所以这里给主内容字段种占位文本，和 Bricks / Elementor 的行为一致——插入即可见，
 * 再去改文字。只覆盖列出的字段，其余仍用注册表的 defaults。
 *
 * ⚠ 这是 blox 与高级构建器**有意**的行为差异（那边插入仍为空）。两者写进同一份
 *   blocks_data，占位文本只是普通内容、不影响渲染一致性。若日后统一，改这里即可。
 */
$bloxPlaceholders = [
    'heading' => ['text' => '标题文字'],
    'text'    => ['html' => '<p>在这里输入正文内容。</p>'],
    'quote'   => ['text' => '引用内容', 'author' => ''],
    'alert'   => ['text' => '提示内容'],
    'icon-box' => ['title' => '标题', 'text' => '描述文字'],
];

$elementLib = [];
foreach (BuilderRegistry::meta() as $type => $m) {
    if (!in_array($type, $bloxElementTypes, true)) {
        continue;
    }
    $defaults = $m['defaults'];
    foreach ($bloxPlaceholders[$type] ?? [] as $k => $v) {
        $defaults[$k] = $v;
    }
    $elementLib[] = [
        'type'     => $type,
        'label'    => $m['label'],
        'category' => $m['category'],
        'icon'     => $m['icon'],
        'defaults' => $defaults,
    ];
}
// 分类显示名：category 是机器值，界面要中文
$catLabels = ['basic' => '基本', 'media' => '媒体', 'layout' => '布局', 'advanced' => '高级', 'dynamic' => '动态'];
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

        <!-- 左：结构树 / 元素库 -->
        <aside class="w-64 shrink-0 bg-white border-r border-gray-200 flex flex-col">
            <!-- 页签 -->
            <div class="h-10 flex items-stretch border-b border-gray-100 shrink-0">
                <button type="button" @click="leftTab = 'tree'"
                        class="flex-1 text-xs font-semibold inline-flex items-center justify-center gap-1 border-b-2 transition"
                        :class="leftTab === 'tree' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-600'">
                    <i class="ti ti-list-tree text-sm"></i>结构
                    <span class="text-[10px] font-normal opacity-70" x-text="sections.length"></span>
                </button>
                <button type="button" @click="leftTab = 'lib'"
                        class="flex-1 text-xs font-semibold inline-flex items-center justify-center gap-1 border-b-2 transition"
                        :class="leftTab === 'lib' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-600'">
                    <i class="ti ti-category text-sm"></i>元素
                </button>
            </div>

            <!-- ── 元素库 ── -->
            <div x-show="leftTab === 'lib'" class="flex-1 flex flex-col min-h-0">
                <div class="p-2 border-b border-gray-100 shrink-0">
                    <div class="relative">
                        <i class="ti ti-search text-sm text-gray-300 absolute left-2 top-1/2 -translate-y-1/2"></i>
                        <input type="text" x-model="libQuery" placeholder="搜索元素…"
                               class="w-full border border-gray-200 rounded pl-7 pr-2 py-1.5 text-xs">
                    </div>
                    <?php // 插入目标：没选区块时先提示，避免点了没反应 ?>
                    <template x-if="selectedSi < 0">
                        <p class="text-[10px] text-amber-600 mt-1.5 leading-relaxed">
                            先在「结构」里选一个区块，元素会插进去
                        </p>
                    </template>
                    <template x-if="sel && sel.columns.length > 1">
                        <div class="mt-2">
                            <div class="text-[10px] text-gray-400 mb-1">插入到哪一列</div>
                            <div class="flex gap-1">
                                <template x-for="(col, ci) in sel.columns" :key="col.id">
                                    <button type="button" @click="targetCi = ci"
                                            class="flex-1 h-7 rounded text-[11px] border transition"
                                            :class="colIndex() === ci ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                            x-text="'列' + (ci + 1)"></button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="flex-1 overflow-y-auto blox-scroll p-2">
                    <template x-for="grp in filteredLib()" :key="grp.cat">
                        <div class="mb-3">
                            <div class="text-[10px] text-gray-400 px-1 mb-1.5" x-text="grp.label"></div>
                            <div class="grid grid-cols-2 gap-1.5">
                                <template x-for="el in grp.items" :key="el.type">
                                    <button type="button" @click="addElement(el)"
                                            :disabled="selectedSi < 0"
                                            class="h-16 rounded-md border border-gray-200 text-gray-500 hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50/40 transition flex flex-col items-center justify-center gap-1 disabled:opacity-40 disabled:hover:border-gray-200 disabled:hover:text-gray-500 disabled:hover:bg-transparent disabled:cursor-not-allowed"
                                            :title="el.label">
                                        <i class="ti text-lg" :class="'ti-' + el.icon"></i>
                                        <span class="text-[11px] leading-none" x-text="el.label"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                    <template x-if="filteredLib().length === 0">
                        <p class="text-xs text-gray-400 text-center py-8">没有匹配的元素</p>
                    </template>
                    <p class="text-[10px] text-gray-400 leading-relaxed border-t border-gray-100 pt-2 mt-1">
                        第一批只放默认值就能看出效果的元素。图片、卡片、轮播等需要先配置内容，
                        待元素设置面板做好后再开放。
                    </p>
                </div>
            </div>

            <!-- ── 结构树 ── -->
            <div x-show="leftTab === 'tree'" class="flex-1 overflow-y-auto blox-scroll p-2 space-y-1" x-ref="tree">
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
            <!-- 加区块（仅结构页签） -->
            <div x-show="leftTab === 'tree'" class="border-t border-gray-100 p-2 shrink-0">
                <div class="flex items-center justify-between mb-1.5 px-1">
                    <span class="text-[10px] text-gray-400">添加区块（列数）</span>
                    <span class="text-[10px] text-blue-500" x-text="insertHint()"></span>
                </div>
                <div class="grid grid-cols-4 gap-1">
                    <template x-for="n in [1,2,3,4]" :key="n">
                        <button type="button" @click="addSection(n)"
                                class="h-9 rounded-md border border-gray-200 text-gray-500 hover:border-blue-400 hover:text-blue-500 text-xs font-medium transition"
                                x-text="n + ' 列'"></button>
                    </template>
                </div>
                <button type="button" x-show="selectedSi >= 0" @click="selectedSi = -1"
                        class="w-full mt-1.5 text-[10px] text-gray-400 hover:text-gray-600 py-1">
                    取消选中（改为插入到末尾）
                </button>
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
                        <!-- 区块标题 / 副标题：渲染器会输出成居中的段落头 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">区块标题</label>
                            <input type="text" x-model="sel.settings.title" placeholder="留空则不显示"
                                   class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                            <input type="text" x-model="sel.settings.subtitle" placeholder="副标题（可选）"
                                   class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm mt-1.5">
                        </div>
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

                        <!-- 背景图 + 遮罩不透明度 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">背景图</label>
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="sel.settings.bg_image" placeholder="/uploads/images/xx.jpg"
                                       class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                <button type="button" @click="sel.settings.bg_image = ''"
                                        class="text-gray-400 hover:text-red-500 p-1" title="清除">
                                    <i class="ti ti-x text-sm"></i></button>
                            </div>
                            <?php // bg_opacity 只在有背景图时才有意义——渲染器用它做背景色遮罩的透明度 ?>
                            <div x-show="sel.settings.bg_image" class="mt-2">
                                <div class="flex items-center justify-between text-[10px] text-gray-400 mb-1">
                                    <span>遮罩不透明度</span>
                                    <span x-text="(sel.settings.bg_opacity ?? 100) + '%'"></span>
                                </div>
                                <input type="range" min="0" max="100" step="5" class="w-full"
                                       :value="sel.settings.bg_opacity ?? 100"
                                       @input="sel.settings.bg_opacity = parseInt($event.target.value, 10)">
                            </div>
                        </div>
                        <!-- 对齐 -->
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1.5">列内对齐</label>
                            <div class="text-[10px] text-gray-400 mb-1">垂直</div>
                            <div class="grid grid-cols-4 gap-1">
                                <template x-for="opt in alignOptions" :key="'a'+opt.k">
                                    <button type="button" @click="sel.settings.align_items = opt.k"
                                            class="h-8 rounded text-xs border transition"
                                            :class="(sel.settings.align_items || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                            x-text="opt.label"></button>
                                </template>
                            </div>
                            <div class="text-[10px] text-gray-400 mb-1 mt-2">水平</div>
                            <div class="grid grid-cols-4 gap-1">
                                <template x-for="opt in alignOptions" :key="'j'+opt.k">
                                    <button type="button" @click="sel.settings.justify_items = opt.k"
                                            class="h-8 rounded text-xs border transition"
                                            :class="(sel.settings.justify_items || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                            x-text="opt.label"></button>
                                </template>
                            </div>
                        </div>
                        <!-- 列卡片化：渲染器仅在列数 > 1 时生效 -->
                        <div x-show="sel.columns.length > 1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="rounded border-gray-300"
                                       :checked="!!sel.settings.col_card"
                                       @change="sel.settings.col_card = $event.target.checked">
                                <span class="text-xs font-medium text-gray-600">每列显示为卡片</span>
                            </label>
                            <p class="text-[10px] text-gray-400 mt-1">白底、圆角、投影。单列区块不生效。</p>
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
            // 取值与 BlockRenderer 的 ALIGN_ITEMS_MAP / JUSTIFY_ITEMS_MAP 对齐；
            // stretch 是默认值（渲染器映射表里没有它 = 不加对齐类，即拉伸）
            alignOptions: [
                { k: "stretch", label: "拉伸" }, { k: "start", label: "起" },
                { k: "center", label: "中" }, { k: "end", label: "末" },
            ],

            // ── 元素库 ──────────────────────────────────────
            leftTab: "tree",            // tree | lib
            libQuery: "",
            targetCi: 0,                // 插入到选中区块的第几列
            elementLib: <?php echo json_encode($elementLib, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            catLabels: <?php echo json_encode($catLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,

            get sel() { return this.selectedSi >= 0 && this.sections[this.selectedSi] ? this.sections[this.selectedSi] : null; },

            init() {
                var self = this;
                this.$nextTick(function() { self.refreshPreview(); });
                // 区块/设置变化 → 防抖刷新画布
                this.$watch("sections", function() { self.schedulePreview(); });
                // 画布点选 → 回传 → 选中
                window.addEventListener("message", function(e) {
                    if (e && e.data && typeof e.data.ykPick === "number") {
                        self.selectedSi = e.data.ykPick;
                        self.targetCi = 0;   // 与 selectSection 一致
                    }
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
                this.targetCi = 0;   // 换区块就回到第一列，避免沿用上一个区块的列号
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

            /**
             * 插入区块。
             * 位置：有选中项 → 插到它**之后**；没有 → 追加到末尾。
             * 这比「永远追加」符合直觉——在中间调整版面时不必插完再一路上移。
             * settings 与高级构建器（page_edit_advance.php:1469）渲染等价：那边不写
             * title/subtitle/bg_opacity/col_card，这里写成显式默认值，渲染器对
             * 「缺键」与这些值的处理相同（空标题不输出、bg_opacity 默认 100、
             * col_card 空即不启用）。两个编辑器共用同一份 blocks_data，
             * 默认值若真不一致，来回切换会造成版面漂移。
             */
            addSection(cols) {
                var c = [];
                for (var i = 0; i < cols; i++) c.push({ id: this.uid("c"), elements: [] });
                var sec = {
                    id: this.uid("s"), type: "section",
                    settings: {
                        title: "", subtitle: "",
                        bg_color: "", bg_image: "", bg_opacity: 100,
                        padding: "md", max_width: "default",
                        align_items: "stretch", justify_items: "stretch",
                        gap: "lg", col_card: false,
                    },
                    columns: c,
                };
                var at = this.insertIndex();
                this.sections.splice(at, 0, sec);
                this.selectedSi = at;
                this.toast(cols + " 列区块已插入");
            },

            /** 下一个区块插入到的下标（选中项之后，否则末尾） */
            insertIndex() {
                return (this.selectedSi >= 0 && this.selectedSi < this.sections.length)
                    ? this.selectedSi + 1
                    : this.sections.length;
            },

            /** 插入位置的人话描述，显示在「添加区块」上方 */
            insertHint() {
                var at = this.insertIndex();
                return at >= this.sections.length ? "插入到末尾" : ("插入到区块 " + at + " 之后");
            },

            // ── 元素库 ──────────────────────────────────────

            /**
             * 目标列下标。夹在有效范围内——换了区块后 targetCi 可能越界
             * （比如从 3 列区块切到 1 列），越界会把元素塞进不存在的列里丢掉。
             */
            colIndex() {
                var s = this.sel;
                if (!s || !s.columns.length) return 0;
                return Math.min(Math.max(this.targetCi, 0), s.columns.length - 1);
            },

            /** 按分类分组 + 关键词过滤，供左栏渲染 */
            filteredLib() {
                var q = this.libQuery.trim().toLowerCase();
                var self = this;
                var groups = [];
                this.elementLib.forEach(function (el) {
                    if (q && el.label.toLowerCase().indexOf(q) === -1 && el.type.indexOf(q) === -1) return;
                    var g = groups.find(function (x) { return x.cat === el.category; });
                    if (!g) {
                        g = { cat: el.category, label: self.catLabels[el.category] || el.category, items: [] };
                        groups.push(g);
                    }
                    g.items.push(el);
                });
                return groups;
            },

            /**
             * 插入元素到选中区块的目标列。
             * data 用注册表给的 defaults 深拷贝——直接引用会让多次插入共享同一个对象，
             * 改一个全变。
             */
            addElement(el) {
                var s = this.sel;
                if (!s) { this.toast("请先选择一个区块"); return; }
                var ci = this.colIndex();
                s.columns[ci].elements.push({
                    id: this.uid("e"),
                    type: el.type,
                    data: JSON.parse(JSON.stringify(el.defaults || {})),
                });
                this.toast(el.label + " 已插入" + (s.columns.length > 1 ? "（列" + (ci + 1) + "）" : ""));
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
