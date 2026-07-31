<?php
/**
 * Blox 全屏可视化编辑器（实验）—— 对标 Bricks 的三栏画布界面。
 *
 * 隔离设计：不套后台 header/footer 外壳，自渲染全屏 shell；预览与保存全部复用
 * page_edit_advance.php 的现有端点（action=preview 带 blox=1 时注入画布点选/高亮/
 * 空区块占位脚本；主 POST 为保存+存档），因此本文件零渲染/存储逻辑重复。
 *
 * 三栏分工与 Bricks 一致：左=元素库↔设置（选中自动切换，libOpen 强制回元素库）、
 * 中=画布、右=结构树常驻（图层式）。
 *
 * 演进路线（blox 分期）：①画布点选(已) → ②画布拖拽排序 → ③元素拖入 → ④文字内联编辑。
 * 当前版本：三栏 + 画布双向点选 + 区块/元素设置（内容/样式页签、设置搜索、只看已修改、
 * 元素重命名）+ 保存。
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
// 校验是 JSON 数组，避免注入进 JS 出错（非数组一律回退空——比只查解析错误更严）
if (!is_array(json_decode($initBlocks, true))) {
    $initBlocks = '[]';
}

$saveEndpoint = '/admin/page_edit_advance.php?id=' . $id;

/**
 * 元素库（第一批）。
 *
 * 数据源是 BuilderRegistry::meta()——18 种元素的 label / icon / category / defaults
 * 都在里面，这里只做**筛选**，不另抄一份清单（抄一份就会漂）。
 *
 * 入选标准：「插入即所见」或「插入即可配」。image / video 默认渲染为空，但媒体库
 * 选择器已进 blox（插入后设置面板自动打开，选图/贴链接马上可见），故放开。
 * card / 动态类（轮播图 / 导航菜单 / 动态列表）需要先配数据源或多字段内容，仍押后。
 *
 * 加元素只需往这个数组里添 type —— 面板、分组、图标都会自动跟上。
 */
// code = 代码/HTML/短码入口：贴 [form-xxx]、{yk:banner} 等短码即可挂表单/轮播图
$bloxElementTypes = ['heading', 'text', 'button', 'quote', 'alert', 'icon', 'icon-box', 'divider', 'spacer', 'container', 'image', 'video', 'code'];

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
    // 容器的子元素数组：defaults 由 controls 推导不含它，这里补上空数组骨架
    'container' => ['children' => []],
];

$registryMeta = BuilderRegistry::meta();

// 元素库（对齐 Bricks：布局组里 区块/容器 并排为瓦片）。__section 是合成项，
// 点击走 addSection(1)——它不是注册表元素，只是「插区块」在库里的入口。
$elementLib = [[
    'type'     => '__section',
    'label'    => '区块',
    'category' => 'layout',
    'icon'     => 'crop-landscape',
    'defaults' => [],
]];
foreach ($registryMeta as $type => $m) {
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

/**
 * 元素 schema（全量 18 种，不受插入白名单限制）。
 *
 * 设置面板要能编辑**页面里已有的任意元素**——同一份 blocks_data 也会被高级构建器
 * 编辑，页面里完全可能存在 image / card / 轮播这些暂不开放插入的类型。
 * 只带白名单的话，选中它们设置面板会一片空白，像是坏了。
 */
$elementSchemas = [];
foreach ($registryMeta as $type => $m) {
    $elementSchemas[$type] = [
        'label'    => $m['label'],
        'icon'     => $m['icon'],
        'controls' => array_values($m['controls']),
        'dynamic'  => $m['dynamic'],
        // 注册表原始默认值：设置面板「只看已修改」按它对比（注意元素库插入时
        // 种了占位文本，占位字段会被视为已修改——它确实改了）
        'defaults' => $m['defaults'],
        // 容器标记：结构树嵌套显示、插入目标判定用
        'container' => $m['container'],
    ];
}
// 分类显示名：category 是机器值，界面要中文
$catLabels = ['basic' => '基本', 'media' => '媒体', 'layout' => '布局', 'advanced' => '高级', 'dynamic' => '动态'];

/**
 * Tabler 图标名全集：从随包的字体 CSS 提取（单一真相——装了什么字体就能选什么，
 * 不手抄清单，字体升级自动跟上）。约 5100 个、序列化 ~70KB，仅本编辑器页加载；
 * 选择器网格搜索驱动，不会一次渲染全量。
 */
$tablerIcons = [];
if (preg_match_all('/\.ti-([a-z0-9-]+):before/', (string) @file_get_contents(ROOT_PATH . '/assets/tabler/tabler-icons.min.css'), $_tiM)) {
    $tablerIcons = array_values(array_unique($_tiM[1]));
}
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
    <?php // 系统富文本编辑器（richtext 控件的「可视化编辑」弹窗用；按需 init） ?>
    <script src="/assets/tinymce/tinymce.min.js"></script>
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

        <?php // 左栏（Bricks 式）：元素库 ↔ 设置 同容器切换。选中区块/元素自动进设置，
              // 「＋ 元素」把 libOpen 置真强制回元素库；结构树移右栏常驻。 ?>
        <aside class="w-72 shrink-0 bg-white border-r border-gray-200 flex flex-col">

            <!-- ── 元素库（无选中或 libOpen） ── -->
            <div x-show="!sel || libOpen" class="flex-1 flex flex-col min-h-0">
                <div class="h-10 px-3 flex items-center justify-between border-b border-gray-100 shrink-0">
                    <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1">
                        <i class="ti ti-category text-sm"></i>元素库
                    </span>
                    <button type="button" x-show="sel && libOpen" @click="libOpen = false"
                            class="text-[10px] text-gray-400 hover:text-blue-500 inline-flex items-center gap-0.5">
                        <i class="ti ti-arrow-back-up text-xs"></i>返回设置
                    </button>
                </div>
                <div class="p-2 border-b border-gray-100 shrink-0">
                    <div class="relative">
                        <i class="ti ti-search text-sm text-gray-300 absolute left-2 top-1/2 -translate-y-1/2"></i>
                        <input type="text" x-model="libQuery" placeholder="搜索元素…"
                               class="w-full border border-gray-200 rounded pl-7 pr-2 py-1.5 text-xs">
                    </div>
                    <?php // 插入目标：没选区块时先提示，避免点了没反应 ?>
                    <template x-if="selectedSi < 0">
                        <p class="text-[10px] text-amber-600 mt-1.5 leading-relaxed">
                            先在右侧「结构」或画布里点选一个区块，元素会插进去
                        </p>
                    </template>
                    <template x-if="selTopEl && elSchema(selTopEl.type).container">
                        <p class="text-[10px] text-blue-500 mt-1.5 leading-relaxed">
                            <i class="ti ti-corner-down-right"></i> 将插入到选中的容器内
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
                            <?php // 分类标题可折叠（Bricks 式）；搜索时忽略折叠态全部展开 ?>
                            <button type="button" @click="catOpen[grp.cat] = !isCatOpen(grp.cat)"
                                    class="w-full flex items-center justify-between px-1 mb-1.5 text-[11px] font-medium text-gray-500 hover:text-gray-700">
                                <span x-text="grp.label"></span>
                                <i class="ti ti-chevron-down text-xs transition-transform" :class="isCatOpen(grp.cat) || libQuery.trim() ? '' : '-rotate-90'"></i>
                            </button>
                            <div x-show="isCatOpen(grp.cat) || libQuery.trim()" class="grid grid-cols-2 gap-1.5">
                                <template x-for="el in grp.items" :key="el.type">
                                    <?php // 点击=插入到选中目标；拖拽=拖到结构树或画布的目标位置（路线图③）。
                                          // 拖拽自带目标，所以瓦片不再因未选中而禁用 ?>
                                    <button type="button" @click="addElement(el)"
                                            draggable="true"
                                            @dragstart="dragEl = el; $event.dataTransfer.effectAllowed = 'copy'; $event.dataTransfer.setData('text/plain', el.type)"
                                            @dragend="dragEl = null; dragOver = ''"
                                            class="h-16 rounded-md border border-gray-200 text-gray-700 hover:border-blue-400 hover:text-blue-500 hover:bg-blue-50/40 transition flex flex-col items-center justify-center gap-1 cursor-grab active:cursor-grabbing"
                                            :title="el.label + '（点击插入，或拖到结构树/画布）'">
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

            <!-- ── 设置（选中区块/元素且未打开元素库） ── -->
            <div x-show="sel && !libOpen" class="flex-1 flex flex-col min-h-0">
                <div class="h-10 px-3 flex items-center gap-2 border-b border-gray-100 shrink-0">
                    <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1 min-w-0">
                        <i class="ti ti-adjustments text-sm shrink-0"></i>
                        <span class="truncate" x-text="panelTitle()"></span>
                    </span>
                    <?php // 元素设置里给「回到区块」出口，否则选了元素就没法改区块本身 ?>
                    <button type="button" x-show="selEl" @click="selectSection(selectedSi)"
                            class="text-[10px] text-gray-400 hover:text-blue-500 inline-flex items-center gap-0.5 shrink-0">
                        <i class="ti ti-arrow-back-up text-xs"></i>区块
                    </button>
                    <button type="button" @click="libOpen = true"
                            class="ml-auto shrink-0 text-xs font-medium text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2.5 py-1 inline-flex items-center gap-1">
                        <i class="ti ti-plus text-sm"></i>元素
                    </button>
                </div>

                <!-- 内容 / 样式 页签 -->
                <div class="flex items-stretch border-b border-gray-100 shrink-0">
                    <button type="button" @click="panelTab = 'content'"
                            class="flex-1 h-9 text-xs font-semibold border-b-2 transition"
                            :class="panelTab === 'content' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-600'">内容</button>
                    <button type="button" @click="panelTab = 'style'"
                            class="flex-1 h-9 text-xs font-semibold border-b-2 transition"
                            :class="panelTab === 'style' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-600'">样式</button>
                </div>

                <?php // 设置搜索 + 只看已修改：仅元素设置（数据驱动才筛得动）；区块设置项少不筛 ?>
                <div x-show="selEl" class="p-2 border-b border-gray-100 shrink-0 flex items-center gap-1">
                    <div class="relative flex-1">
                        <i class="ti ti-search text-sm text-gray-300 absolute left-2 top-1/2 -translate-y-1/2"></i>
                        <input type="text" x-model="ctrlQuery" placeholder="搜索设置…"
                               class="w-full border border-gray-200 rounded pl-7 pr-2 py-1.5 text-xs">
                    </div>
                    <button type="button" @click="modifiedOnly = !modifiedOnly" title="只看已修改"
                            class="w-7 h-7 rounded border inline-flex items-center justify-center transition shrink-0"
                            :class="modifiedOnly ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-400 hover:text-gray-600'">
                        <i class="ti ti-adjustments-check text-sm"></i>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto blox-scroll p-4">
                    <!-- ── 元素设置：按 BuilderRegistry 的 controls() 生成 ── -->
                    <template x-if="selEl">
                        <div class="space-y-4">
                            <?php // 元素重命名：标题即输入框（借鉴思路来自可视化构建器惯例）；
                                  // 存 el.name（blocks_data 顶层扩展键，渲染器只读 type/data 不受影响） ?>
                            <div class="flex items-center gap-2 pb-2 border-b border-gray-100">
                                <i class="ti text-base text-blue-500 shrink-0" :class="'ti-' + elIcon(selEl.type)"></i>
                                <input type="text" x-model="selEl.name"
                                       :placeholder="elSchema(selEl.type).label || selEl.type"
                                       title="元素命名（结构树里显示）"
                                       class="flex-1 min-w-0 text-sm font-medium text-gray-700 border-0 border-b border-transparent focus:border-blue-300 outline-none p-0 bg-transparent">
                            </div>

                            <template x-if="visibleCtrls().length === 0">
                                <p class="text-xs text-gray-400 leading-relaxed"
                                   x-text="ctrlQuery.trim() || modifiedOnly ? '没有匹配的设置项。'
                                       : (elSchema(selEl.type).container && panelTab === 'content'
                                           ? '容器的内容就是它的子元素——选中容器后从「＋ 元素」添加，在右侧结构树里排序；外观调整去「样式」页签。'
                                           : (panelTab === 'style' ? '该元素暂无样式设置。' : '该元素没有可配置项，或需要在高级构建器里设置。'))"></p>
                            </template>

                            <template x-for="ctrl in visibleCtrls()" :key="ctrl.key">
                                <div>
                                    <template x-if="ctrl.type !== 'checkbox'">
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5" x-text="ctrl.label"></label>
                                    </template>

                                    <template x-if="ctrl.type === 'text'">
                                        <input type="text" x-model="selEl.data[ctrl.key]" :placeholder="ctrl.placeholder || ''"
                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                                    </template>

                                    <template x-if="ctrl.type === 'textarea'">
                                        <textarea x-model="selEl.data[ctrl.key]" rows="3" :placeholder="ctrl.placeholder || ''"
                                                  class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm"></textarea>
                                    </template>

                                    <?php // richtext：系统编辑器是主入口（点开即 TinyMCE 弹窗），
                                          // 下方摘要预览让人不点开也知道内容；HTML 源码收进折叠当备用 ?>
                                    <template x-if="ctrl.type === 'richtext'">
                                        <div x-data="{ showSrc: false }">
                                            <button type="button"
                                                    @click="openRte(() => selEl.data[ctrl.key], v => selEl.data[ctrl.key] = v)"
                                                    class="w-full inline-flex items-center justify-center gap-1.5 text-sm text-white bg-blue-600 hover:bg-blue-500 rounded-lg py-2 transition">
                                                <i class="ti ti-edit text-base"></i>编辑内容
                                            </button>
                                            <p class="mt-1.5 text-xs text-gray-500 bg-gray-50 border border-gray-100 rounded px-2 py-1.5 leading-relaxed break-words"
                                               x-text="(String(selEl.data[ctrl.key] || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || '（暂无内容）').slice(0, 80)"></p>
                                            <button type="button" @click="showSrc = !showSrc"
                                                    class="mt-1 text-[10px] text-gray-400 hover:text-gray-600"
                                                    x-text="showSrc ? '收起 HTML 源码' : 'HTML 源码'"></button>
                                            <textarea x-show="showSrc" x-cloak x-model="selEl.data[ctrl.key]" rows="5"
                                                      class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs font-mono mt-1"></textarea>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'select' && !ctrl.option_icons">
                                        <select x-model="selEl.data[ctrl.key]"
                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                                            <template x-for="(lbl, val) in (ctrl.options || {})" :key="val">
                                                <option :value="val" x-text="lbl"></option>
                                            </template>
                                        </select>
                                    </template>

                                    <?php // schema 带 option_icons 的 select → 图标按钮组（方向/对齐这类
                                          // 方位语义选项，图标比文字下拉直观；悬停出完整文字说明） ?>
                                    <template x-if="ctrl.type === 'select' && ctrl.option_icons">
                                        <div class="flex gap-1">
                                            <template x-for="(lbl, val) in (ctrl.options || {})" :key="val">
                                                <button type="button" @click="selEl.data[ctrl.key] = val" :title="lbl"
                                                        class="flex-1 h-8 rounded border inline-flex items-center justify-center transition"
                                                        :class="(selEl.data[ctrl.key] || ctrl.default) === val ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                    <i class="ti text-base" :class="'ti-' + ctrl.option_icons[val]"></i>
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    <template x-if="ctrl.type === 'number'">
                                        <input type="number" x-model.number="selEl.data[ctrl.key]"
                                               :min="ctrl.min ?? null" :max="ctrl.max ?? null"
                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                                    </template>

                                    <template x-if="ctrl.type === 'checkbox'">
                                        <label class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" class="rounded border-gray-300"
                                                   :checked="!!selEl.data[ctrl.key]"
                                                   @change="selEl.data[ctrl.key] = $event.target.checked">
                                            <span class="text-xs font-medium text-gray-600" x-text="ctrl.label"></span>
                                        </label>
                                    </template>

                                    <template x-if="ctrl.type === 'color'">
                                        <div class="flex items-center gap-2">
                                            <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                                   :value="selEl.data[ctrl.key] || '#000000'"
                                                   @input="selEl.data[ctrl.key] = $event.target.value">
                                            <input type="text" x-model="selEl.data[ctrl.key]" placeholder="留空=默认"
                                                   class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                            <button type="button" @click="selEl.data[ctrl.key] = ''"
                                                    class="text-gray-400 hover:text-red-500 p-1" title="清除">
                                                <i class="ti ti-x text-sm"></i></button>
                                        </div>
                                    </template>

                                    <?php // icon：存 Tabler 图标名（不带 ti- 前缀）。实时预览 + 手填 +
                                          // 可搜索图标库（全集从字体 CSS 提取，见 $tablerIcons） ?>
                                    <template x-if="ctrl.type === 'icon'">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="w-9 h-9 rounded border border-gray-200 flex items-center justify-center text-gray-600 shrink-0">
                                                    <i class="ti text-lg" :class="'ti-' + (selEl.data[ctrl.key] || 'star')"></i>
                                                </span>
                                                <input type="text" x-model="selEl.data[ctrl.key]" placeholder="图标名，或从库中选"
                                                       class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                                <button type="button"
                                                        @click="iconPick = (iconPick === ctrl.key ? '' : ctrl.key); iconQuery = ''"
                                                        class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition"
                                                        x-text="iconPick === ctrl.key ? '收起' : '图标库'"></button>
                                            </div>
                                            <div x-show="iconPick === ctrl.key" x-cloak
                                                 class="mt-2 border border-gray-200 rounded-lg p-2 bg-gray-50">
                                                <input type="text" x-model="iconQuery" placeholder="搜索图标（英文名）"
                                                       class="w-full border border-gray-200 rounded px-2 py-1 text-xs mb-2">
                                                <div class="flex flex-wrap gap-1 max-h-40 overflow-y-auto blox-scroll">
                                                    <template x-for="ic in iconMatches()" :key="ic">
                                                        <button type="button" @click="selEl.data[ctrl.key] = ic" :title="ic"
                                                                class="w-8 h-8 flex items-center justify-center rounded border transition"
                                                                :class="selEl.data[ctrl.key] === ic ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 bg-white text-gray-600 hover:border-blue-400 hover:text-blue-500'">
                                                            <i class="ti text-base" :class="'ti-' + ic"></i>
                                                        </button>
                                                    </template>
                                                </div>
                                                <p class="text-[10px] text-gray-400 mt-1.5" x-text="iconHint()"></p>
                                            </div>
                                        </div>
                                    </template>

                                    <?php // image：图片地址 + 缩略预览 + 媒体库选图（复用 openMedia 弹窗） ?>
                                    <template x-if="ctrl.type === 'image'">
                                        <div class="flex items-center gap-2">
                                            <template x-if="selEl.data[ctrl.key]">
                                                <img :src="selEl.data[ctrl.key]" class="w-9 h-9 rounded border border-gray-200 object-cover shrink-0" alt="">
                                            </template>
                                            <input type="text" x-model="selEl.data[ctrl.key]" placeholder="/uploads/images/xx.jpg"
                                                   class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                            <button type="button" @click="openMedia(u => selEl.data[ctrl.key] = u)"
                                                    class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition">媒体库</button>
                                        </div>
                                    </template>

                                    <?php // 未覆盖的控件类型：明说，而不是静默留空 ?>
                                    <template x-if="['text','textarea','richtext','select','number','checkbox','color','icon','image'].indexOf(ctrl.type) === -1">
                                        <p class="text-[10px] text-amber-600 leading-relaxed">
                                            该项（<span x-text="ctrl.type"></span>）暂未在 blox 支持，请到高级构建器编辑。
                                        </p>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- ── 区块设置：内容 / 样式 两页签 ── -->
                    <template x-if="sel && !selEl">
                        <div>
                            <div x-show="panelTab === 'content'" class="space-y-5">
                                <template x-if="selLayer === 'con'">
                                    <p class="text-xs text-gray-400 leading-relaxed">
                                        容器的内容就是它的列与元素——在右侧结构树里管理；宽度、背景等外观在「样式」页签。
                                    </p>
                                </template>
                                <!-- 区块标题 / 副标题：渲染器会输出成居中的段落头 -->
                                <div x-show="selLayer === 'sec'">
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">区块标题</label>
                                    <input type="text" x-model="sel.settings.title" placeholder="留空则不显示"
                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm">
                                    <input type="text" x-model="sel.settings.subtitle" placeholder="副标题（可选）"
                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm mt-1.5">
                                </div>
                                <!-- 元素编辑（内联编辑逐步实现，重活先由高级构建器承接） -->
                                <div x-show="selLayer === 'sec'" class="pt-3 border-t border-gray-100">
                                    <div class="text-xs text-gray-400 mb-2" x-text="'本区块含 ' + elCount(sel) + ' 个元素，点右侧结构树逐个编辑'"></div>
                                    <a :href="'/admin/page_edit_advance.php?id=<?php echo $id; ?>&focus=' + selectedSi"
                                       class="w-full inline-flex items-center justify-center gap-1.5 text-sm border border-gray-200 rounded-lg py-2 text-gray-600 hover:border-blue-400 hover:text-blue-500 transition">
                                        <i class="ti ti-pencil text-base"></i>在高级构建器中编辑
                                    </a>
                                </div>
                            </div>

                            <div x-show="panelTab === 'style'" class="space-y-5">
                                <?php // 分层随结构树选中（Bricks 心智）：树里选「区块」→ 全宽背景层设置，
                                      // 选「容器」节点 → 内容层设置。一次只显示当前层。 ?>
                                <div x-show="selLayer === 'sec'" class="space-y-5">
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
                                <!-- 渐变背景：无/预置色板/自定义双色。叠在背景色/背景图之上 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">渐变背景</label>
                                    <div class="grid grid-cols-5 gap-1.5">
                                        <?php // 显式「无」清除项：比「再点一次取消」可发现得多 ?>
                                        <button type="button" title="无渐变" @click="sel.settings.bg_gradient = ''"
                                                class="h-8 rounded border transition inline-flex items-center justify-center"
                                                :class="!sel.settings.bg_gradient ? 'border-blue-500 ring-2 ring-blue-200 text-blue-500' : 'border-gray-200 text-gray-400 hover:border-blue-300'">
                                            <i class="ti ti-ban text-sm"></i>
                                        </button>
                                        <template x-for="g in gradientPresets" :key="g.label">
                                            <button type="button" :title="g.label"
                                                    @click="sel.settings.bg_gradient = g.css"
                                                    class="h-8 rounded border transition"
                                                    :class="sel.settings.bg_gradient === g.css ? 'border-blue-500 ring-2 ring-blue-200' : 'border-gray-200 hover:border-blue-300'"
                                                    :style="'background:' + g.css"></button>
                                        </template>
                                    </div>
                                    <?php // 自定义双色渐变：改任意一项立即生效（画布实时预览即所见） ?>
                                    <div class="mt-2 flex items-center gap-1.5">
                                        <span class="text-[10px] text-gray-400 shrink-0">自定义</span>
                                        <input type="color" x-model="gradA" @input="applyCustomGrad()"
                                               class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5" title="起始色">
                                        <input type="color" x-model="gradB" @input="applyCustomGrad()"
                                               class="w-8 h-8 rounded border border-gray-200 cursor-pointer p-0.5" title="结束色">
                                        <select x-model="gradDir" @change="applyCustomGrad()"
                                                class="flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-xs bg-white">
                                            <option value="135">↘ 斜向</option>
                                            <option value="90">→ 横向</option>
                                            <option value="180">↓ 纵向</option>
                                        </select>
                                        <span class="w-8 h-8 rounded border border-gray-200 shrink-0"
                                              :style="'background:linear-gradient(' + gradDir + 'deg,' + gradA + ' 0%,' + gradB + ' 100%)'"
                                              title="自定义预览"></span>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1" x-show="sel.settings.bg_gradient"
                                       x-text="'当前：' + ((gradientPresets.find(g => g.css === sel.settings.bg_gradient) || {}).label || '自定义渐变') + '（点 ⃠ 取消）'"></p>
                                </div>
                                <!-- 背景图 + 遮罩不透明度 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">背景图</label>
                                    <div class="flex items-center gap-2">
                                        <template x-if="sel.settings.bg_image">
                                            <img :src="sel.settings.bg_image" class="w-9 h-9 rounded border border-gray-200 object-cover shrink-0" alt="">
                                        </template>
                                        <input type="text" x-model="sel.settings.bg_image" placeholder="/uploads/images/xx.jpg"
                                               class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <button type="button" @click="openMedia(u => sel.settings.bg_image = u)"
                                                class="shrink-0 text-xs text-blue-500 hover:text-blue-600 border border-blue-200 hover:border-blue-400 rounded px-2 py-1.5 transition">媒体库</button>
                                        <button type="button" @click="sel.settings.bg_image = ''"
                                                class="text-gray-400 hover:text-red-500 p-1 shrink-0" title="清除">
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

                                </div>

                                <div x-show="selLayer === 'con'" class="space-y-5">
                                <!-- 容器宽度：预设四档 + 自定义 px -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">容器宽度</label>
                                    <select x-model="sel.settings.max_width"
                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                                        <option value="default">标准（1152px）</option>
                                        <option value="narrow">窄（896px）</option>
                                        <option value="wide">宽（1280px）</option>
                                        <option value="full">通栏（全宽）</option>
                                        <option value="custom">自定义…</option>
                                    </select>
                                    <div x-show="sel.settings.max_width === 'custom'" class="mt-1.5 flex items-center gap-2">
                                        <input type="number" min="320" max="3840" step="10" placeholder="1280"
                                               x-model.number="sel.settings.max_width_px"
                                               class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <span class="text-xs text-gray-400 shrink-0">px（320–3840）</span>
                                    </div>
                                </div>
                                <!-- 容器背景：与区块背景分层，常用「区块深色 + 容器白底圆角」 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">容器背景</label>
                                    <div class="flex items-center gap-2">
                                        <input type="color" class="w-9 h-9 rounded border border-gray-200 cursor-pointer p-0.5"
                                               :value="sel.settings.container_bg || '#ffffff'"
                                               @input="sel.settings.container_bg = $event.target.value">
                                        <input type="text" x-model="sel.settings.container_bg" placeholder="留空=透明"
                                               class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                                        <button type="button" @click="sel.settings.container_bg = ''"
                                                class="text-gray-400 hover:text-red-500 p-1" title="清除">
                                            <i class="ti ti-x text-sm"></i></button>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5">容器内边距</label>
                                        <div class="grid grid-cols-4 gap-1">
                                            <template x-for="opt in [{k:'',l:'无'},{k:'sm',l:'小'},{k:'md',l:'中'},{k:'lg',l:'大'}]" :key="'cp'+opt.k">
                                                <button type="button" @click="sel.settings.container_padding = opt.k"
                                                        class="h-8 rounded text-xs border transition"
                                                        :class="(sel.settings.container_padding || '') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                        x-text="opt.l"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1.5">容器圆角</label>
                                        <div class="grid grid-cols-3 gap-1">
                                            <template x-for="opt in [{k:'',l:'无'},{k:'md',l:'中'},{k:'xl',l:'大'}]" :key="'cr'+opt.k">
                                                <button type="button" @click="sel.settings.container_radius = opt.k"
                                                        class="h-8 rounded text-xs border transition"
                                                        :class="(sel.settings.container_radius || '') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200'"
                                                        x-text="opt.l"></button>
                                            </template>
                                        </div>
                                    </div>
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

                                <!-- 对齐 -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 mb-1.5">列内对齐</label>
                                    <div class="text-[10px] text-gray-400 mb-1">垂直</div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <template x-for="opt in alignVOptions" :key="'a'+opt.k">
                                            <button type="button" @click="sel.settings.align_items = opt.k" :title="opt.label"
                                                    class="h-8 rounded border inline-flex items-center justify-center transition"
                                                    :class="(sel.settings.align_items || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                <i class="ti text-base" :class="'ti-' + opt.icon"></i>
                                            </button>
                                        </template>
                                    </div>
                                    <div class="text-[10px] text-gray-400 mb-1 mt-2">水平</div>
                                    <div class="grid grid-cols-4 gap-1">
                                        <template x-for="opt in alignHOptions" :key="'j'+opt.k">
                                            <button type="button" @click="sel.settings.justify_items = opt.k" :title="opt.label"
                                                    class="h-8 rounded border inline-flex items-center justify-center transition"
                                                    :class="(sel.settings.justify_items || 'stretch') === opt.k ? 'border-blue-400 bg-blue-50 text-blue-600' : 'border-gray-200 text-gray-500 hover:border-blue-200 hover:text-blue-500'">
                                                <i class="ti text-base" :class="'ti-' + opt.icon"></i>
                                            </button>
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
                                </div>
                            </div>
                        </div>
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

        <!-- 右：结构树（常驻，Bricks / PS 图层式） -->
        <aside class="w-64 shrink-0 bg-white border-l border-gray-200 flex flex-col">
            <div class="h-10 px-3 flex items-center border-b border-gray-100 shrink-0">
                <span class="text-xs font-semibold text-gray-500 tracking-wide inline-flex items-center gap-1">
                    <i class="ti ti-list-tree text-sm"></i>结构
                    <span class="text-[10px] font-normal opacity-70" x-text="sections.length"></span>
                </span>
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
                        <!-- 该区块展开：容器节点 → 列 → 元素（Bricks 的 Section/Container 分层树） -->
                        <div x-show="selectedSi === si" x-collapse>
                            <div class="px-2 pb-1">
                                <div @click.stop="selectContainer(si)"
                                     @dragover.prevent="dragOver = 'con' + si" @dragleave="dragOver = ''"
                                     @drop.prevent="treeDrop(si, 0, null)"
                                     class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer transition"
                                     :class="dragOver === 'con' + si ? 'ring-2 ring-blue-300 bg-blue-50' : (isContainerSelected(si) ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600')">
                                    <i class="ti ti-box-margin text-xs shrink-0"></i>
                                    <span class="text-xs flex-1">容器</span>
                                    <span class="text-[10px] text-gray-400" x-text="section.columns.length > 1 ? section.columns.length + ' 列' : ''"></span>
                                </div>
                            <div class="ml-3 pl-1.5 border-l border-gray-100 space-y-1">
                                <template x-for="(col, ci) in section.columns" :key="col.id">
                                    <div @dragover.prevent="dragOver = 'c' + si + '-' + ci" @dragleave="dragOver = ''"
                                         @drop.prevent="treeDrop(si, ci, null)"
                                         class="rounded transition"
                                         :class="dragOver === 'c' + si + '-' + ci ? 'ring-2 ring-blue-300 bg-blue-50' : ''">
                                        <?php // 单列时不显示列标题——只有一列，说「列1」是噪音 ?>
                                        <div x-show="section.columns.length > 1"
                                             class="text-[10px] text-gray-400 pl-1 pt-1" x-text="'列 ' + (ci + 1)"></div>
                                        <template x-if="col.elements.length === 0">
                                            <p class="text-[10px] text-gray-300 pl-2 py-1">空</p>
                                        </template>
                                        <template x-for="(el, ei) in col.elements" :key="el.id">
                                            <div>
                                                <div @click.stop="selectElement(si, ci, ei)"
                                                     @dragover.prevent.stop="elSchema(el.type).container && (dragOver = 'ce' + si + '-' + ci + '-' + ei)"
                                                     @drop.prevent.stop="elSchema(el.type).container ? treeDrop(si, ci, ei) : treeDrop(si, ci, null)"
                                                     class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer group/el transition"
                                                     :class="dragOver === 'ce' + si + '-' + ci + '-' + ei ? 'ring-2 ring-blue-300 bg-blue-50' : (isElSelected(si,ci,ei) ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600')">
                                                    <i class="ti text-xs shrink-0" :class="'ti-' + elIcon(el.type)"></i>
                                                    <span class="text-xs truncate flex-1" x-text="elLabel(el)"></span>
                                                    <span class="hidden group-hover/el:flex items-center gap-0.5 shrink-0">
                                                        <button type="button" @click.stop="moveElement(si,ci,ei,-1)" :disabled="ei===0"
                                                                class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="上移">
                                                            <i class="ti ti-arrow-up text-xs"></i></button>
                                                        <button type="button" @click.stop="moveElement(si,ci,ei,1)" :disabled="ei===col.elements.length-1"
                                                                class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="下移">
                                                            <i class="ti ti-arrow-down text-xs"></i></button>
                                                        <button type="button" @click.stop="deleteElement(si,ci,ei)"
                                                                class="p-0.5 hover:text-red-500" title="删除">
                                                            <i class="ti ti-trash text-xs"></i></button>
                                                    </span>
                                                </div>
                                                <!-- 容器：子元素嵌套一层（图层式） -->
                                                <template x-if="elSchema(el.type).container">
                                                    <div class="ml-3 pl-1.5 border-l border-gray-200">
                                                        <template x-if="(el.data.children || []).length === 0">
                                                            <p class="text-[10px] text-gray-300 pl-2 py-0.5">空容器</p>
                                                        </template>
                                                        <template x-for="(cel, cei) in (el.data.children || [])" :key="cel.id">
                                                            <div @click.stop="selectChild(si, ci, ei, cei)"
                                                                 class="flex items-center gap-1.5 pl-2 pr-1 py-1 rounded cursor-pointer group/cel transition"
                                                                 :class="isChildSelected(si,ci,ei,cei) ? 'bg-blue-100 text-blue-700' : 'hover:bg-gray-100 text-gray-600'">
                                                                <i class="ti text-xs shrink-0" :class="'ti-' + elIcon(cel.type)"></i>
                                                                <span class="text-xs truncate flex-1" x-text="elLabel(cel)"></span>
                                                                <span class="hidden group-hover/cel:flex items-center gap-0.5 shrink-0">
                                                                    <button type="button" @click.stop="moveChild(si,ci,ei,cei,-1)" :disabled="cei===0"
                                                                            class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="上移">
                                                                        <i class="ti ti-arrow-up text-xs"></i></button>
                                                                    <button type="button" @click.stop="moveChild(si,ci,ei,cei,1)" :disabled="cei===(el.data.children||[]).length-1"
                                                                            class="p-0.5 hover:text-blue-600 disabled:opacity-25" title="下移">
                                                                        <i class="ti ti-arrow-down text-xs"></i></button>
                                                                    <button type="button" @click.stop="deleteChild(si,ci,ei,cei)"
                                                                            class="p-0.5 hover:text-red-500" title="删除">
                                                                        <i class="ti ti-trash text-xs"></i></button>
                                                                </span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            </div>
                        </div>
                        <!-- 该区块的操作（选中时展开） -->
                        <div x-show="selectedSi === si" class="flex items-center gap-1 px-2 pb-2 border-t border-gray-100 pt-1.5" x-collapse>
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
                <div class="flex items-center justify-between mb-1.5 px-1">
                    <span class="text-[10px] text-gray-400">添加区块（列数）</span>
                    <span class="text-[10px] text-blue-500" x-text="insertHint()"></span>
                </div>
                <div class="grid grid-cols-6 gap-1">
                    <template x-for="n in [1,2,3,4,5,6]" :key="n">
                        <button type="button" @click="addSection(n)" :title="n + ' 列区块'"
                                class="h-9 rounded-md border border-gray-200 text-gray-500 hover:border-blue-400 hover:text-blue-500 text-xs font-medium transition"
                                x-text="n"></button>
                    </template>
                </div>
                <button type="button" x-show="selectedSi >= 0" @click="selectedSi = -1"
                        class="w-full mt-1.5 text-[10px] text-gray-400 hover:text-gray-600 py-1">
                    取消选中（改为插入到末尾）
                </button>
            </div>
        </aside>
    </div>

    <!-- 富文本编辑弹窗（系统 TinyMCE；不做点遮罩关闭——误点会丢内容） -->
    <div x-show="rteOpen" x-cloak class="fixed inset-0 z-[90] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-[860px] max-w-full flex flex-col">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-edit text-base text-blue-500"></i>可视化编辑
                </span>
                <button type="button" @click="rteOpen = false" class="text-gray-400 hover:text-gray-600 p-1" title="取消">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="p-3">
                <textarea id="bloxRte"></textarea>
            </div>
            <div class="h-14 px-4 flex items-center justify-end gap-2 border-t border-gray-100 shrink-0">
                <button type="button" @click="rteOpen = false"
                        class="text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded px-4 py-1.5 transition">取消</button>
                <button type="button" @click="saveRte()"
                        class="text-sm text-white bg-blue-600 hover:bg-blue-500 rounded px-4 py-1.5 transition">应用</button>
            </div>
        </div>
    </div>

    <?php // z-[1500]：媒体库弹窗还会从 TinyMCE 的图片对话框（z≈1100+）里被唤起，必须压在其上 ?>
    <!-- 媒体库选择弹窗 -->
    <div x-show="mediaOpen" x-cloak @keydown.escape.window="mediaOpen = false"
         class="fixed inset-0 z-[1500] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="mediaOpen = false"></div>
        <?php // 固定紧凑尺寸：内容区定高滚动，弹窗不随视口撑大（约 860×520） ?>
        <div class="relative bg-white rounded-xl shadow-2xl w-[860px] max-w-[90vw] flex flex-col">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-photo text-base text-blue-500"></i>从媒体库选择图片
                </span>
                <button type="button" @click="mediaOpen = false" class="text-gray-400 hover:text-gray-600 p-1">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="p-3 border-b border-gray-100 shrink-0 flex gap-2">
                <input type="text" x-model="mediaKeyword" @keydown.enter.prevent="loadMedia(1)"
                       placeholder="搜索文件名…" class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                <button type="button" @click="loadMedia(1)"
                        class="shrink-0 text-sm text-white bg-blue-600 hover:bg-blue-500 rounded px-3 py-1.5 transition">搜索</button>
                <?php // 上传即选用：上传的目的就是马上要用这张图 ?>
                <label class="shrink-0 text-sm border rounded px-3 py-1.5 inline-flex items-center gap-1 transition"
                       :class="mediaUploading ? 'border-gray-200 text-gray-400 cursor-wait' : 'border-blue-200 text-blue-500 hover:border-blue-400 hover:text-blue-600 cursor-pointer'">
                    <i class="ti text-base" :class="mediaUploading ? 'ti-loader-2 animate-spin' : 'ti-upload'"></i>
                    <span x-text="mediaUploading ? '上传中…' : '上传图片'"></span>
                    <input type="file" accept="image/*" class="hidden" :disabled="mediaUploading"
                           @change="uploadMedia($event.target.files[0]); $event.target.value = ''">
                </label>
            </div>
            <div class="h-[400px] overflow-y-auto blox-scroll p-3">
                <p x-show="mediaLoading" class="text-center text-gray-400 text-sm py-12">加载中…</p>
                <p x-show="!mediaLoading && mediaItems.length === 0" class="text-center text-gray-400 text-sm py-12">
                    暂无图片，可到「媒体库」页面上传
                </p>
                <div x-show="!mediaLoading && mediaItems.length > 0" class="grid grid-cols-6 gap-2">
                    <template x-for="it in mediaItems" :key="it.id">
                        <button type="button" @click="pickMedia(it.url)" :title="it.name"
                                class="group/mp border-2 border-transparent hover:border-blue-400 rounded-lg overflow-hidden transition text-left">
                            <span class="block aspect-square bg-gray-100">
                                <img :src="it.url" class="w-full h-full object-cover" loading="lazy" alt="">
                            </span>
                            <span class="block px-1.5 py-1 text-[11px] text-gray-600 truncate" x-text="it.name"></span>
                        </button>
                    </template>
                </div>
            </div>
            <div class="h-11 px-4 flex items-center justify-between border-t border-gray-100 shrink-0 text-xs text-gray-500">
                <span x-text="'共 ' + mediaTotal + ' 张'"></span>
                <div class="flex items-center gap-2">
                    <button type="button" :disabled="mediaPage <= 1" @click="loadMedia(mediaPage - 1)"
                            class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40">上一页</button>
                    <span x-text="mediaPage + ' / ' + Math.max(mediaPages, 1)"></span>
                    <button type="button" :disabled="mediaPage >= mediaPages" @click="loadMedia(mediaPage + 1)"
                            class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40">下一页</button>
                </div>
            </div>
        </div>
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
            // stretch 是默认值（渲染器映射表里没有它 = 不加对齐类，即拉伸）。
            // 图标按钮组显示，label 只做悬停提示——按轴分两组图标
            alignVOptions: [
                { k: "stretch", label: "拉伸填满", icon: "arrows-vertical" },
                { k: "start", label: "顶对齐", icon: "layout-align-top" },
                { k: "center", label: "垂直居中", icon: "layout-align-middle" },
                { k: "end", label: "底对齐", icon: "layout-align-bottom" },
            ],
            alignHOptions: [
                { k: "stretch", label: "拉伸填满", icon: "arrows-horizontal" },
                { k: "start", label: "左对齐", icon: "layout-align-left" },
                { k: "center", label: "水平居中", icon: "layout-align-center" },
                { k: "end", label: "右对齐", icon: "layout-align-right" },
            ],

            // ── 左栏（Bricks 式：元素库 ↔ 设置） ───────────────
            libOpen: false,             // true = 有选中项时仍显示元素库（「＋ 元素」按钮）
            panelTab: "content",        // 设置面板页签：content | style
            ctrlQuery: "",              // 设置搜索关键词（仅元素设置）
            modifiedOnly: false,        // 只看已修改的设置项
            libQuery: "",

            // ── 图标选择器（icon 控件） ─────────────────────
            tablerIcons: <?php echo json_encode($tablerIcons); ?>,
            iconPick: "",               // 当前展开选择器的控件 key（"" = 都收起）
            iconQuery: "",
            // 无搜索词时的常用集（与高级构建器的精选集一致，双方手感统一）
            iconCommon: ["star","heart","circle-check","phone","mail","map-pin","clock","shield","bolt","award","world","users","home","settings","camera","bell","bookmark","calendar","folder","gift","link","lock","search","tag","trending-up","thumb-up","eye","download","upload","share","code","coffee","feather","flag","info-circle","lifebuoy","microphone","device-desktop","music","package","pencil","printer","send","server","mood-smile","sun","target","terminal","truck","device-tv","umbrella","wifi"],

            /** 选择器网格内容：无词=常用集；有词=全量前缀/包含匹配，最多 96 个防卡 */
            iconMatches() {
                var q = this.iconQuery.trim().toLowerCase();
                if (!q) return this.iconCommon;
                var out = [];
                for (var i = 0; i < this.tablerIcons.length && out.length < 96; i++) {
                    if (this.tablerIcons[i].indexOf(q) !== -1) out.push(this.tablerIcons[i]);
                }
                return out;
            },

            iconHint() {
                var q = this.iconQuery.trim();
                if (!q) return "常用图标；输入英文关键词可搜索全部 " + this.tablerIcons.length + " 个（如 arrow / user / cart）";
                var n = this.iconMatches().length;
                if (n === 0) return "没有匹配「" + q + "」的图标";
                return n >= 96 ? "匹配较多，仅显示前 96 个，继续输入缩小范围" : "共 " + n + " 个匹配";
            },

            // ── 自定义渐变（双色+方向，改动即写入 bg_gradient） ──
            gradA: "#667eea",
            gradB: "#764ba2",
            gradDir: "135",
            applyCustomGrad() {
                if (!this.sel) return;
                this.sel.settings.bg_gradient = "linear-gradient(" + this.gradDir + "deg," + this.gradA + " 0%," + this.gradB + " 100%)";
            },

            // ── 渐变背景预置（值原样存 settings.bg_gradient；渲染器有白名单校验） ──
            gradientPresets: [
                { label: "蓝紫", css: "linear-gradient(135deg,#667eea 0%,#764ba2 100%)" },
                { label: "海洋", css: "linear-gradient(135deg,#2193b0 0%,#6dd5ed 100%)" },
                { label: "青翠", css: "linear-gradient(135deg,#11998e 0%,#38ef7d 100%)" },
                { label: "日落", css: "linear-gradient(135deg,#f6d365 0%,#fda085 100%)" },
                { label: "绯红", css: "linear-gradient(135deg,#eb3349 0%,#f45c43 100%)" },
                { label: "粉樱", css: "linear-gradient(135deg,#fbc2eb 0%,#a6c1ee 100%)" },
                { label: "夜空", css: "linear-gradient(135deg,#141e30 0%,#243b55 100%)" },
                { label: "灰石", css: "linear-gradient(135deg,#8e9eab 0%,#eef2f3 100%)" },
            ],

            // ── 媒体库选择器（复用 media_api.php，与后台其它页的选图弹窗同一数据源） ──
            mediaOpen: false,
            mediaItems: [],
            mediaPage: 1,
            mediaPages: 1,
            mediaTotal: 0,
            mediaKeyword: "",
            mediaLoading: false,
            _mediaTarget: null,   // 选中回调：拿到 url 写进哪个字段

            openMedia(setter) {
                this._mediaTarget = setter;
                this.mediaOpen = true;
                this.mediaKeyword = "";
                this.loadMedia(1);
            },

            loadMedia(page) {
                var self = this;
                this.mediaLoading = true;
                this.mediaPage = page;
                var kw = this.mediaKeyword.trim();
                fetch("/admin/media_api.php?action=list&type=image&page=" + page
                        + (kw ? "&keyword=" + encodeURIComponent(kw) : ""))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.code === 0) {
                            self.mediaItems = d.data.items || [];
                            self.mediaPages = d.data.pages || 1;
                            self.mediaTotal = d.data.total || 0;
                        } else {
                            self.mediaItems = [];
                            self.toast(d.msg || "媒体库加载失败");
                        }
                    })
                    .catch(function () { self.mediaItems = []; self.toast("媒体库请求失败"); })
                    .finally(function () { self.mediaLoading = false; });
            },

            pickMedia(url) {
                if (this._mediaTarget) this._mediaTarget(url);
                this.mediaOpen = false;
                this._mediaTarget = null;
            },

            mediaUploading: false,

            // ── 富文本弹窗（系统 TinyMCE，按需初始化一次，多控件共用） ──
            rteOpen: false,
            _rteTarget: null,
            _rteInited: false,

            openRte(getter, setter) {
                this._rteTarget = setter;
                this.rteOpen = true;
                var initial = getter() || "";
                var self = this;
                this.$nextTick(function () {
                    if (self._rteInited) {
                        var ed = tinymce.get("bloxRte");
                        if (ed) ed.setContent(initial);
                        return;
                    }
                    self._rteInited = true;
                    tinymce.init({
                        selector: "#bloxRte",
                        language: (document.documentElement.lang || "zh-CN") === "ja" ? "ja" : "zh_CN",
                        height: 420,
                        menubar: false,
                        plugins: "autolink lists link image charmap searchreplace visualblocks code codesample insertdatetime media table wordcount",
                        toolbar: "undo redo | styles fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist | link image media codesample | table | removeformat code",
                        branding: false, promotion: false, convert_urls: false,
                        images_upload_handler: function (blobInfo) {
                            return new Promise(function (resolve, reject) {
                                var fd = new FormData();
                                fd.append("file", blobInfo.blob(), blobInfo.filename());
                                fd.append("type", "images");
                                fetch("/admin/upload.php", { method: "POST", body: fd })
                                    .then(function (r) { return r.json(); })
                                    .then(function (d) { d.code === 0 ? resolve(d.data.url) : reject(d.msg || "上传失败"); })
                                    .catch(function () { reject("上传失败"); });
                            });
                        },
                        // 图片对话框的「浏览」→ blox 自己的媒体库弹窗（z-index 已压在 TinyMCE 之上）
                        file_picker_types: "image",
                        file_picker_callback: function (cb, value, meta) {
                            if (meta.filetype === "image") self.openMedia(function (u) { cb(u, { alt: "" }); });
                        },
                        setup: function (ed) {
                            ed.on("init", function () { ed.setContent(initial); });
                        }
                    });
                });
            },

            saveRte() {
                var ed = tinymce.get("bloxRte");
                if (ed && this._rteTarget) this._rteTarget(ed.getContent());
                this.rteOpen = false;
                this._rteTarget = null;
            },

            /** 上传成功直接选用（上传的目的就是马上用）；失败提示原因留在弹窗里重试 */
            uploadMedia(file) {
                if (!file || this.mediaUploading) return;
                var self = this;
                this.mediaUploading = true;
                var fd = new FormData();
                fd.append("file", file);
                fd.append("type", "images");
                fetch("/admin/media_api.php?action=upload", { method: "POST", body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.code === 0 && d.data && d.data.url) {
                            self.toast("已上传并选用");
                            self.pickMedia(d.data.url);
                        } else {
                            self.toast(d.msg || "上传失败");
                        }
                    })
                    .catch(function () { self.toast("上传请求失败"); })
                    .finally(function () { self.mediaUploading = false; });
            },
            targetCi: 0,                // 插入到选中区块的第几列
            elementLib: <?php echo json_encode($elementLib, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            catLabels: <?php echo json_encode($catLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            elementSchemas: <?php echo json_encode($elementSchemas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,

            // 元素选中：-1 表示当前选的是区块本身；selectedSubEi ≥0 = 选的是容器内的子元素
            selectedCi: -1,
            selectedEi: -1,
            selectedSubEi: -1,
            // 区块的选中层（Bricks 分层树）：sec=全宽背景层，con=内容容器层
            selLayer: "sec",

            get sel() { return this.selectedSi >= 0 && this.sections[this.selectedSi] ? this.sections[this.selectedSi] : null; },

            /** 列级选中元素（容器场景下=容器本身，不下钻子元素） */
            get selTopEl() {
                var s = this.sel;
                if (!s || this.selectedCi < 0 || this.selectedEi < 0) return null;
                var col = s.columns[this.selectedCi];
                return (col && col.elements[this.selectedEi]) ? col.elements[this.selectedEi] : null;
            },

            /** 当前选中的元素对象；子元素选中时下钻到 children（设置面板据此切换显示） */
            get selEl() {
                var el = this.selTopEl;
                if (el && this.selectedSubEi >= 0) {
                    var kids = (el.data && el.data.children) || [];
                    return kids[this.selectedSubEi] || null;
                }
                return el;
            },

            /** 元素 schema。未知类型（插件卸载后残留等）也要给个兜底，不能让设置面板炸掉 */
            elSchema(type) { return this.elementSchemas[type] || { label: type, icon: "box", controls: [] }; },
            elIcon(type) { return this.elSchema(type).icon || "box"; },

            /** 树里显示的元素名：自定义命名 > 自己的文字 > 类型名 */
            elLabel(el) {
                if (el.name) return String(el.name);
                var d = el.data || {};
                var txt = d.text || d.title || d.html || "";
                txt = String(txt).replace(/<[^>]*>/g, "").trim();
                var name = this.elSchema(el.type).label || el.type;
                return txt ? (name + "：" + (txt.length > 12 ? txt.slice(0, 12) + "…" : txt)) : name;
            },

            panelTitle() {
                if (this.selEl) return (this.elSchema(this.selEl.type).label || "元素") + " 设置";
                if (!this.sel) return "设置";
                var n = this.selectedSi + 1;
                return this.selLayer === "con" ? ("容器（区块 " + n + "）") : ("区块 " + n + " 设置");
            },

            isElSelected(si, ci, ei) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubEi < 0;
            },

            isChildSelected(si, ci, ei, k) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubEi === k;
            },

            selectElement(si, ci, ei) {
                this.selectedSi = si;
                this.selectedCi = ci;
                this.selectedEi = ei;
                this.selectedSubEi = -1;
                this.targetCi = ci;   // 插入新元素时默认跟着当前所在列
                // 选中即进设置（Bricks 动线）；换选中项就重置面板筛选状态
                this.libOpen = false;
                this.panelTab = "content";
                this.ctrlQuery = "";
                this.iconPick = "";
                this.iconQuery = "";
            },

            selectChild(si, ci, ei, k) {
                this.selectElement(si, ci, ei);
                this.selectedSubEi = k;
            },

            moveChild(si, ci, ei, k, dir) {
                var kids = this.sections[si].columns[ci].elements[ei].data.children || [];
                var nk = k + dir;
                if (nk < 0 || nk >= kids.length) return;
                var tmp = kids[k]; kids[k] = kids[nk]; kids[nk] = tmp;
                if (this.isChildSelected(si, ci, ei, k)) this.selectedSubEi = nk;
            },

            deleteChild(si, ci, ei, k) {
                (this.sections[si].columns[ci].elements[ei].data.children || []).splice(k, 1);
                if (this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei) {
                    if (this.selectedSubEi === k) this.selectedSubEi = -1;      // 回到容器本身
                    else if (this.selectedSubEi > k) this.selectedSubEi--;
                }
            },

            /** 元素设置的可见控件：页签归属（color→样式，其余→内容）+ 搜索 + 只看已修改 */
            visibleCtrls() {
                if (!this.selEl) return [];
                var self = this;
                var q = this.ctrlQuery.trim().toLowerCase();
                return (this.elSchema(this.selEl.type).controls || []).filter(function (c) {
                    // 页签归属：控件可在 schema 里显式标 tab（如容器的布局控件全在样式页）；
                    // 未标注的按类型推断——color 归样式，其余归内容
                    var tab = c.tab || (c.type === "color" ? "style" : "content");
                    if (tab !== self.panelTab) return false;
                    if (q && String(c.label || "").toLowerCase().indexOf(q) === -1
                          && String(c.key).toLowerCase().indexOf(q) === -1) return false;
                    if (self.modifiedOnly && !self.isCtrlModified(c)) return false;
                    return true;
                });
            },

            /** 与注册表默认值对比（undefined/null 一律按空串归一，避免「没设过」误报已修改） */
            isCtrlModified(c) {
                var d = (this.selEl && this.selEl.data) || {};
                var defs = this.elSchema(this.selEl.type).defaults || {};
                var norm = function (v) { return (v === undefined || v === null) ? "" : v; };
                return JSON.stringify(norm(d[c.key])) !== JSON.stringify(norm(defs[c.key]));
            },

            moveElement(si, ci, ei, dir) {
                var els = this.sections[si].columns[ci].elements;
                var ni = ei + dir;
                if (ni < 0 || ni >= els.length) return;
                var tmp = els[ei]; els[ei] = els[ni]; els[ni] = tmp;
                if (this.isElSelected(si, ci, ei)) this.selectedEi = ni;
            },

            deleteElement(si, ci, ei) {
                var el = this.sections[si].columns[ci].elements[ei];
                var kids = (el && el.data && el.data.children) ? el.data.children.length : 0;
                if (kids > 0 && !confirm("删除这个容器？其内 " + kids + " 个子元素会一并删除。")) return;
                this.sections[si].columns[ci].elements.splice(ei, 1);
                // 选中项要跟着修正，否则设置面板会指向已删除或错位的元素
                if (this.selectedSi === si && this.selectedCi === ci) {
                    if (this.selectedEi === ei) { this.selectedCi = -1; this.selectedEi = -1; this.selectedSubEi = -1; }
                    else if (this.selectedEi > ei) { this.selectedEi--; }
                }
            },

            init() {
                var self = this;
                // 先归一化 id 再渲染：老数据（排版编辑器早期格式）可能缺 id 或 id 重复，
                // x-for 的 :key 遇到 undefined/重复会让 Alpine 崩掉、结构树整个不渲染
                this.normalizeIds();
                this.$nextTick(function() { self.refreshPreview(); });
                // 区块/设置变化 → 防抖刷新画布
                this.$watch("sections", function() { self.schedulePreview(); });
                // 画布点选 → 回传 → 选中
                window.addEventListener("message", function(e) {
                    // 画布放置：iframe 注入脚本算好 {sec, col, type} 后回传
                    if (e && e.data && e.data.ykDrop) {
                        var d = e.data.ykDrop;
                        var lib = self.elementLib.find(function (x) { return x.type === d.type; });
                        self.dragEl = null;
                        if (!lib) return;
                        if (lib.type === "__section") { self.selectSection(d.sec); self.addSection(1); return; }
                        self.selectSection(d.sec);
                        self.targetCi = d.col || 0;
                        self.addElement(lib);
                        return;
                    }
                    if (e && e.data && typeof e.data.ykPick === "number") {
                        self.selectedSi = e.data.ykPick;
                        self.targetCi = 0;
                        self.selectedCi = -1;   // 与 selectSection 一致：回到区块设置
                        self.selectedEi = -1;
                        self.selectedSubEi = -1;
                        self.selLayer = "sec";
                        self.libOpen = false;   // 画布点选也直达设置
                        self.panelTab = "content";
                        self.ctrlQuery = "";
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
                body.set("blox", "1");   // 要求注入画布点选/高亮/空区块占位脚本
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
                this.targetCi = 0;    // 换区块就回到第一列，避免沿用上一个区块的列号
                this.selectedCi = -1; // 清掉元素选中 → 左栏回到区块设置
                this.selectedEi = -1;
                this.selectedSubEi = -1;
                this.selLayer = "sec";
                this.libOpen = false;
                this.panelTab = "content";
                this.ctrlQuery = "";
                var frame = this.$refs.canvas;
                if (frame && frame.contentWindow) frame.contentWindow.postMessage({ ykHighlight: si }, "*");
            },

            /** 选中区块的容器层：树里的「容器」节点，设置面板显示内容层样式 */
            selectContainer(si) {
                this.selectSection(si);
                this.selLayer = "con";
                this.panelTab = "style";   // 容器的重点是外观，直达样式页签
            },

            isContainerSelected(si) {
                return this.selectedSi === si && this.selectedCi < 0 && this.selLayer === "con";
            },

            elCount(section) {
                // 容器的子元素也计数：树和「本区块含 N 个元素」都按可见节点算
                return (section.columns || []).reduce(function(n, c) {
                    return n + (c.elements || []).reduce(function(m, e) {
                        return m + 1 + (((e.data || {}).children) || []).length;
                    }, 0);
                }, 0);
            },

            uid(p) { return p + "_" + Math.random().toString(36).substr(2, 9); },

            /** 补齐/去重所有层级的 id（区块/列/元素/容器子元素）。只在本地补，保存时自然带上 */
            normalizeIds() {
                var self = this;
                var seen = {};
                var fix = function (obj, prefix) {
                    if (!obj.id || seen[obj.id]) obj.id = self.uid(prefix);
                    seen[obj.id] = true;
                };
                (this.sections || []).forEach(function (s) {
                    fix(s, "s");
                    (s.columns || []).forEach(function (c) {
                        fix(c, "c");
                        (c.elements || []).forEach(function (e) {
                            fix(e, "e");
                            (((e.data || {}).children) || []).forEach(function (k) { fix(k, "e"); });
                        });
                    });
                });
            },

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
                        bg_color: "", bg_image: "", bg_gradient: "", bg_opacity: 100,
                        // 新区块默认宽容器（1280px）——用户定的现代默认；旧区块存的值不受影响
                        padding: "md", max_width: "wide", max_width_px: 1280,
                        container_bg: "", container_padding: "", container_radius: "",
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

            // ── 元素库分类折叠态（Bricks 式；默认全开，搜索时强制全开） ──
            catOpen: {},
            isCatOpen(cat) { return this.catOpen[cat] !== false; },

            // ── 拖拽插入（路线图③）：库瓦片拖到结构树/画布 ──
            dragEl: null,          // 正在拖的库条目
            dragOver: "",          // 当前悬停的放置目标 key（高亮用）

            /**
             * 结构树放置：ei=null → 插到 si 区块的 ci 列末尾；ei≥0 → 插进该列 ei 号容器。
             * 通过先选中目标再走 addElement，复用其全部规则（容器嵌套约束、插入即选中、toast）。
             */
            treeDrop(si, ci, ei) {
                var el = this.dragEl;
                this.dragEl = null;
                this.dragOver = "";
                if (!el) return;
                if (el.type === "__section") { this.selectSection(si); this.addSection(1); return; }
                if (ei === null) {
                    this.selectSection(si);
                    this.targetCi = ci;
                } else {
                    this.selectElement(si, ci, ei);
                }
                this.addElement(el);
            },

            /** 按分类分组 + 关键词过滤；布局组置顶、组内 区块/容器 领先（对齐 Bricks） */
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
                var li = groups.findIndex(function (g) { return g.cat === "layout"; });
                if (li > 0) groups.unshift(groups.splice(li, 1)[0]);
                groups.forEach(function (g) {
                    if (g.cat !== "layout") return;
                    var w = { "__section": 0, "container": 1 };
                    g.items.sort(function (a, b) { return (w[a.type] ?? 5) - (w[b.type] ?? 5); });
                });
                return groups;
            },

            /**
             * 插入元素到选中区块的目标列。
             * data 用注册表给的 defaults 深拷贝——直接引用会让多次插入共享同一个对象，
             * 改一个全变。
             */
            addElement(el) {
                // 合成项「区块」：插顶层 section（1 列起步；多列预设在右下角）
                if (el.type === "__section") { this.addSection(1); return; }
                var s = this.sel;
                if (!s) { this.toast("请先选择一个区块"); return; }
                var node = {
                    id: this.uid("e"),
                    type: el.type,
                    data: JSON.parse(JSON.stringify(el.defaults || {})),
                };
                // 选中容器（或其子元素）时插进该容器；一层嵌套约束：容器里不能再放容器
                var host = this.selTopEl;
                if (host && this.elSchema(host.type).container) {
                    if (this.elSchema(el.type).container) { this.toast("容器内不能再放容器"); return; }
                    host.data.children = host.data.children || [];
                    host.data.children.push(node);
                    this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, host.data.children.length - 1);
                    this.toast(el.label + " 已插入容器");
                    return;
                }
                var ci = this.colIndex();
                s.columns[ci].elements.push(node);
                // 插入即选中：左栏自动切到它的设置（selectElement 会收起元素库），接着就能改文字
                this.selectElement(this.selectedSi, ci, s.columns[ci].elements.length - 1);
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
