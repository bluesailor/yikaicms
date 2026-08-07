<?php
/**
 * 产品导入插件 - 管理页面
 * 由 /admin/plugin_page.php?plugin=product-import 加载
 *
 * ?handler=upload  文件上传 + 解析（返回 JSON）
 * ?handler=import  分批写入（返回 JSON）
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

$handler = $_GET['handler'] ?? '';

if ($handler === 'upload') {
    require __DIR__ . '/upload_handler.php';
    return;
}
if ($handler === 'import') {
    require __DIR__ . '/import_handler.php';
    return;
}

// ── 产品字段定义 ──
$productFields = [
    ['key' => '_ignore',   'label' => '-- 忽略此列 --'],
    ['key' => 'title',     'label' => '产品名称', 'required' => true],
    ['key' => 'category',  'label' => '所属分类'],
    ['key' => 'subtitle',  'label' => '副标题'],
    ['key' => 'slug',      'label' => 'URL 别名'],
    ['key' => 'model',     'label' => '产品型号'],
    ['key' => 'cover',     'label' => '封面图片'],
    ['key' => 'images',    'label' => '产品图集'],
    ['key' => 'summary',   'label' => '摘要'],
    ['key' => 'content',   'label' => '详情内容'],
    ['key' => 'price',     'label' => '售价'],
    ['key' => 'market_price', 'label' => '市场价'],
    ['key' => 'specs',     'label' => '规格参数'],
    ['key' => 'tags',      'label' => '标签'],
    ['key' => 'brand',     'label' => '品牌'],
    ['key' => 'product_type', 'label' => '产品类型(standard/custom)'],
    ['key' => 'material',  'label' => '材质'],
    ['key' => 'scene',     'label' => '使用场景'],
    ['key' => 'status',    'label' => '状态(1上架/0下架)'],
    ['key' => 'is_top',    'label' => '置顶'],
    ['key' => 'is_recommend', 'label' => '推荐'],
    ['key' => 'is_hot',    'label' => '热门'],
    ['key' => 'is_new',    'label' => '新品'],
    ['key' => 'sort_order','label' => '排序'],
    ['key' => 'lang',      'label' => '语言(zh-CN/en/ja)'],
];

$fieldJson = json_encode($productFields, JSON_UNESCAPED_UNICODE);

$pageTitle = '产品导入';
$currentMenu = 'product';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div x-data="productImport()" class="max-w-5xl space-y-6">

    <!-- 步骤指示器 -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-center gap-2 text-sm">
            <template x-for="(step, i) in steps" :key="i">
                <div class="flex items-center gap-2">
                    <div class="flex items-center gap-1.5" :class="i <= currentStep ? 'text-primary font-medium' : 'text-gray-400'">
                        <span class="w-7 h-7 rounded-full inline-flex items-center justify-center text-xs font-bold border-2"
                              :class="i <= currentStep ? 'border-primary bg-primary text-white' : 'border-gray-300'"
                              x-text="i + 1"></span>
                        <span x-text="step"></span>
                    </div>
                    <template x-if="i < steps.length - 1">
                        <div class="w-8 h-px" :class="i < currentStep ? 'bg-primary' : 'bg-gray-200'"></div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <!-- Step 1: 上传文件 -->
    <div x-show="currentStep === 0" class="bg-white rounded-lg shadow p-6 space-y-5">
        <div>
            <h2 class="font-bold text-gray-800 mb-1">上传数据文件</h2>
            <p class="text-sm text-gray-500">支持 CSV（.csv）和 Excel（.xlsx / .xls）格式，文件不超过 10MB</p>
        </div>

        <div class="border-2 border-dashed rounded-lg p-10 text-center transition cursor-pointer"
             :class="dragOver ? 'border-primary bg-blue-50' : 'border-gray-300 hover:border-gray-400'"
             @dragover.prevent="dragOver = true"
             @dragleave="dragOver = false"
             @drop.prevent="handleDrop($event); dragOver = false"
             @click="$refs.fileInput.click()">
            <input type="file" x-ref="fileInput" class="hidden" accept=".csv,.xlsx,.xls"
                   @change="handleFileSelect($event)">
            <template x-if="!uploadFile">
                <div class="space-y-2">
                    <svg class="w-10 h-10 mx-auto text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                    </svg>
                    <p class="text-gray-600">拖拽文件到此处，或 <span class="text-primary underline">点击选择</span></p>
                    <p class="text-xs text-gray-400">CSV / XLSX / XLS，最大 10MB</p>
                </div>
            </template>
            <template x-if="uploadFile">
                <div class="space-y-1">
                    <p class="text-gray-800 font-medium" x-text="uploadFile.name"></p>
                    <p class="text-sm text-gray-500" x-text="formatSize(uploadFile.size)"></p>
                    <p class="text-xs text-primary cursor-pointer underline" @click.stop="uploadFile = null; uploadResult = null">重新选择</p>
                </div>
            </template>
        </div>

        <div class="flex justify-end pt-2">
            <button class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="!uploadFile || uploading"
                    @click="doUpload()"
                    x-text="uploading ? '解析中...' : '解析文件'"></button>
        </div>

        <div x-show="uploadError" class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 text-sm" x-text="uploadError"></div>

        <!-- 上传结果概览 -->
        <div x-show="uploadResult" class="border rounded-lg p-5 space-y-3">
            <h3 class="font-medium text-gray-800">解析结果</h3>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-2xl font-bold text-primary" x-text="uploadResult.total_rows"></div>
                    <div class="text-gray-500 mt-1">数据行</div>
                </div>
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-2xl font-bold text-primary" x-text="uploadResult.headers.length"></div>
                    <div class="text-gray-500 mt-1">识别列</div>
                </div>
                <div class="bg-gray-50 rounded p-3 text-center">
                    <div class="text-2xl font-bold text-primary" x-text="formatSize(uploadResult.file_size)"></div>
                    <div class="text-gray-500 mt-1">文件大小</div>
                </div>
            </div>
            <div x-show="uploadResult.parse_errors && uploadResult.parse_errors.length > 0" class="bg-yellow-50 border border-yellow-200 text-yellow-700 rounded p-3 text-sm">
                <template x-for="e in uploadResult.parse_errors" :key="e">
                    <p x-text="e"></p>
                </template>
            </div>
        </div>
    </div>

    <!-- Step 2: 字段映射 -->
    <div x-show="currentStep === 1" class="bg-white rounded-lg shadow p-6 space-y-5">
        <div>
            <h2 class="font-bold text-gray-800 mb-1">字段映射</h2>
            <p class="text-sm text-gray-500">将文件中的列映射到产品字段。同名列会自动匹配，<span class="text-red-500">*</span> 表示必填。</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-gray-500">
                        <th class="pb-3 font-medium w-1/3">文件列（<span x-text="uploadResult.headers.length"></span>列）</th>
                        <th class="pb-3 font-medium w-8 text-center">&rarr;</th>
                        <th class="pb-3 font-medium w-1/3">映射到产品字段</th>
                        <th class="pb-3 font-medium">预览（首行）</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(header, i) in uploadResult.headers" :key="i">
                        <tr class="border-b">
                            <td class="py-3 text-gray-800 font-medium" x-text="header || '(空列名)'"></td>
                            <td class="py-3 text-center text-gray-400">&rarr;</td>
                            <td class="py-3">
                                <select class="border rounded px-3 py-1.5 w-full text-sm"
                                        :class="mapping[header] === '_ignore' ? 'text-gray-400' : ''"
                                        x-model="mapping[header]">
                                    <template x-for="f in fields" :key="f.key">
                                        <option :value="f.key" x-text="f.label + (f.required ? ' *' : '')"></option>
                                    </template>
                                </select>
                            </td>
                            <td class="py-3 text-gray-500 text-xs max-w-[200px] truncate"
                                x-text="uploadResult.preview_rows[0] ? uploadResult.preview_rows[0][i] || '' : ''"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex justify-between pt-2">
            <button class="text-gray-600 hover:text-gray-800 px-4 py-2 rounded border transition"
                    @click="currentStep = 0">上一步</button>
            <button class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="!hasTitleMapping()"
                    @click="currentStep = 2">下一步：预览数据</button>
        </div>
    </div>

    <!-- Step 3: 预览校验 -->
    <div x-show="currentStep === 2" class="bg-white rounded-lg shadow p-6 space-y-5">
        <div>
            <h2 class="font-bold text-gray-800 mb-1">数据预览与校验</h2>
            <p class="text-sm text-gray-500">以下为前 10 行数据预览，确认无误后点击开始导入</p>
        </div>

        <!-- 导入设置 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">重复处理</label>
                <select x-model="strategy" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="skip">跳过已有记录</option>
                    <option value="update">覆盖更新已有记录</option>
                </select>
                <p class="text-xs text-gray-400 mt-1">按 slug 查重</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">导入语言</label>
                <select x-model="importLang" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="zh-CN">简体中文</option>
                    <option value="en">English</option>
                    <option value="ja">日本語</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">每批行数</label>
                <select x-model="batchSize" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="30">30 行/批</option>
                    <option value="50" selected>50 行/批</option>
                    <option value="100">100 行/批</option>
                </select>
            </div>
        </div>

        <!-- 预览表格 -->
        <div class="overflow-x-auto border rounded-lg">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left text-gray-600">
                        <th class="py-2 px-3 border-r w-12 text-center">#</th>
                        <template x-for="h in uploadResult.headers" :key="h">
                            <th class="py-2 px-3 border-r font-medium text-xs max-w-[160px] truncate">
                                <span x-text="h"></span>
                                <span x-show="mapping[h] && mapping[h] !== '_ignore'" class="text-primary block text-[10px]" x-text="'→ ' + fieldLabel(mapping[h])"></span>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, ri) in uploadResult.preview_rows" :key="ri">
                        <tr class="border-b" :class="ri % 2 === 0 ? 'bg-white' : 'bg-gray-50/50'">
                            <td class="py-2 px-3 border-r text-center text-gray-400 text-xs" x-text="ri + 1"></td>
                            <template x-for="(cell, ci) in row" :key="ci">
                                <td class="py-2 px-3 border-r text-xs max-w-[160px] truncate" x-text="cell || ''"></td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="flex justify-between pt-2">
            <button class="text-gray-600 hover:text-gray-800 px-4 py-2 rounded border transition"
                    @click="currentStep = 1">上一步</button>
            <button class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="importing"
                    @click="startImport()"
                    x-text="importing ? '导入中...' : '开始导入'"></button>
        </div>
    </div>

    <!-- Step 4: 导入进度与结果 -->
    <div x-show="currentStep === 3" class="bg-white rounded-lg shadow p-6 space-y-5">
        <h2 class="font-bold text-gray-800">导入进度</h2>

        <!-- 进度条 -->
        <div class="space-y-2">
            <div class="flex justify-between text-sm text-gray-600">
                <span x-text="'已处理 ' + importProgress.processed + ' / ' + importProgress.total + ' 行'"></span>
                <span x-text="importProgress.done ? '100%' : Math.round(importProgress.processed / importProgress.total * 100) + '%'"></span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div class="bg-primary h-3 rounded-full transition-all duration-300"
                     :style="{ width: importProgress.total > 0 ? (importProgress.processed / importProgress.total * 100) + '%' : '0%' }"></div>
            </div>
        </div>

        <!-- 实时统计 -->
        <div class="grid grid-cols-4 gap-3 text-sm">
            <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-center">
                <div class="text-xl font-bold text-green-600" x-text="importProgress.created"></div>
                <div class="text-gray-500 text-xs mt-1">新增</div>
            </div>
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 text-center">
                <div class="text-xl font-bold text-blue-600" x-text="importProgress.updated"></div>
                <div class="text-gray-500 text-xs mt-1">更新</div>
            </div>
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-center">
                <div class="text-xl font-bold text-gray-500" x-text="importProgress.skipped"></div>
                <div class="text-gray-500 text-xs mt-1">跳过</div>
            </div>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 text-center">
                <div class="text-xl font-bold text-red-500" x-text="importProgress.errors.length"></div>
                <div class="text-gray-500 text-xs mt-1">错误</div>
            </div>
        </div>

        <!-- 错误详情 -->
        <div x-show="importProgress.errors.length > 0" class="space-y-2">
            <h3 class="font-medium text-sm text-red-600">错误详情</h3>
            <div class="max-h-48 overflow-y-auto border rounded-lg divide-y text-sm">
                <template x-for="e in importProgress.errors" :key="e.row">
                    <div class="px-4 py-2 flex gap-3">
                        <span class="text-gray-400 font-mono text-xs mt-0.5" x-text="'第 ' + e.row + ' 行'"></span>
                        <span class="text-red-600" x-text="e.msg"></span>
                    </div>
                </template>
            </div>
        </div>

        <!-- 完成后的操作 -->
        <div x-show="importProgress.done" class="pt-3 flex gap-3">
            <a href="/admin/product.php" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                查看产品列表
            </a>
            <button class="text-gray-600 hover:text-gray-800 px-4 py-2 rounded border transition"
                    @click="resetImport()">导入新文件</button>
        </div>
    </div>

</div>

<script>
function productImport() {
    return {
        steps: ['上传文件', '字段映射', '数据预览', '执行导入'],
        currentStep: 0,

        // Step 1
        uploadFile: null,
        dragOver: false,
        uploading: false,
        uploadResult: null,
        uploadError: '',

        // Step 2
        fields: <?php echo $fieldJson; ?>,
        csrf: "<?php echo function_exists('csrfToken') ? csrfToken() : ''; ?>",
        mapping: {},

        // Step 3
        strategy: 'skip',
        importLang: '<?php echo e(siteLang()); ?>',
        batchSize: 50,

        // Step 4
        importing: false,
        importProgress: { total: 0, processed: 0, created: 0, updated: 0, skipped: 0, errors: [], done: false },

        handleDrop(e) {
            const files = e.dataTransfer.files;
            if (files.length > 0) this.setFile(files[0]);
        },

        handleFileSelect(e) {
            const files = e.target.files;
            if (files.length > 0) this.setFile(files[0]);
        },

        setFile(file) {
            const ext = file.name.split('.').pop().toLowerCase();
            if (!['csv', 'xlsx', 'xls'].includes(ext)) {
                this.uploadError = '仅支持 CSV / XLSX / XLS 格式';
                return;
            }
            if (file.size > 10 * 1024 * 1024) {
                this.uploadError = '文件不能超过 10MB';
                return;
            }
            this.uploadError = '';
            this.uploadFile = file;
            this.uploadResult = null;
        },

        async doUpload() {
            if (!this.uploadFile) return;
            this.uploading = true;
            this.uploadError = '';

            const formData = new FormData();
            formData.append('file', this.uploadFile);

            try {
                const resp = await fetch('?plugin=product-import&handler=upload', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf }
                });
                const data = await resp.json();
                if (data.code === 0) {
                    this.uploadResult = data.data;
                    this.autoMap();
                    this.uploadError = '';
                } else {
                    this.uploadError = data.msg;
                }
            } catch (e) {
                this.uploadError = '上传失败: ' + e.message;
            }
            this.uploading = false;
        },

        autoMap() {
            if (!this.uploadResult) return;
            const fieldLabels = {};
            this.fields.forEach(f => { fieldLabels[f.label] = f.key; fieldLabels[f.key] = f.key; });

            this.uploadResult.headers.forEach(h => {
                const lower = h.toLowerCase().trim();
                if (fieldLabels[h]) {
                    this.mapping[h] = fieldLabels[h];
                } else if (lower === 'product name' || lower === 'name' || h.includes('名称') || h.includes('标题')) {
                    this.mapping[h] = 'title';
                } else if (lower.includes('category') || lower.includes('分类')) {
                    this.mapping[h] = 'category';
                } else if (lower.includes('price') || lower.includes('价格') || lower.includes('售价')) {
                    this.mapping[h] = 'price';
                } else if (lower.includes('description') || lower.includes('描述') || lower.includes('摘要')) {
                    this.mapping[h] = 'summary';
                } else if (lower.includes('detail') || lower.includes('详情') || lower.includes('内容')) {
                    this.mapping[h] = 'content';
                } else if (lower.includes('image') || lower.includes('图片') || lower.includes('封面')) {
                    this.mapping[h] = 'cover';
                } else if (lower.includes('model') || lower.includes('型号')) {
                    this.mapping[h] = 'model';
                } else if (lower.includes('brand') || lower.includes('品牌')) {
                    this.mapping[h] = 'brand';
                } else if (lower.includes('tag') || lower.includes('标签')) {
                    this.mapping[h] = 'tags';
                } else if (lower.includes('spec') || lower.includes('规格')) {
                    this.mapping[h] = 'specs';
                } else if (lower.includes('status') || lower.includes('状态')) {
                    this.mapping[h] = 'status';
                } else if (lower.includes('sort') || lower.includes('排序')) {
                    this.mapping[h] = 'sort_order';
                } else {
                    this.mapping[h] = '_ignore';
                }
            });

            if (!this.hasTitleMapping()) {
                const titleCandidates = ['产品名称', 'title', 'product name', 'name', 'product_name'];
                for (const h of this.uploadResult.headers) {
                    if (titleCandidates.includes(h.toLowerCase().trim())) {
                        this.mapping[h] = 'title';
                        break;
                    }
                }
            }
        },

        hasTitleMapping() {
            return Object.values(this.mapping).includes('title');
        },

        fieldLabel(key) {
            const f = this.fields.find(x => x.key === key);
            return f ? f.label : key;
        },

        formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        },

        async startImport() {
            if (!this.hasTitleMapping()) {
                alert('请至少将一列映射到"产品名称"');
                return;
            }

            this.currentStep = 3;
            this.importing = true;
            this.importProgress = { total: this.uploadResult.total_rows, processed: 0, created: 0, updated: 0, skipped: 0, errors: [], done: false };

            let batch = 0;

            while (true) {
                try {
                    const resp = await fetch('?plugin=product-import&handler=import', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': this.csrf
                        },
                        body: JSON.stringify({
                            file_id: this.uploadResult.file_id,
                            mapping: this.mapping,
                            strategy: this.strategy,
                            lang: this.importLang,
                            batch: batch,
                            batch_size: parseInt(this.batchSize)
                        })
                    });

                    const data = await resp.json();
                    if (data.code !== 0) {
                        this.importProgress.errors.push({ row: 0, msg: data.msg });
                        break;
                    }

                    this.importProgress.processed = data.data.processed;
                    this.importProgress.created += data.data.created;
                    this.importProgress.updated += data.data.updated;
                    this.importProgress.skipped += data.data.skipped;
                    this.importProgress.errors = this.importProgress.errors.concat(data.data.errors);
                    this.importProgress.done = data.data.done;

                    if (data.data.done) break;
                    batch = data.data.next_batch;
                } catch (e) {
                    this.importProgress.errors.push({ row: 0, msg: '请求失败: ' + e.message });
                    break;
                }
            }

            this.importing = false;
        },

        resetImport() {
            this.currentStep = 0;
            this.uploadFile = null;
            this.uploadResult = null;
            this.uploadError = '';
            this.mapping = {};
            this.importing = false;
            this.importProgress = { total: 0, processed: 0, created: 0, updated: 0, skipped: 0, errors: [], done: false };
        }
    };
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
