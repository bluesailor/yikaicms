<?php
/**
 * SEO 工坊 - 管理页面
 * 由 /admin/plugin_page.php?plugin=seo 加载（已 checkLogin + requirePermission('*') + CSRF）。
 *
 * 免费：llms.txt 生成器（写站点根 /llms.txt）。
 * 专业版（license_has_module('seo-pro')）：AI 一键优化 meta / 重定向管理 / 自动推送 / 内链建议 —— 就地上锁展示。
 *
 * PHP 8.0+
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/redirects.php';

$seoHasPro = function_exists('license_has_module') && license_has_module('seo-pro');

// ============================================================
// POST：生成 /llms.txt（在输出 HTML 前处理，返回 JSON）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_llms') {
    [$ok, $msg] = seo_write_llms_txt();
    if (!$ok) {
        error($msg);
    }
    settingModel()->set('seo_llms_generated_at', (string) time());
    adminLog('plugin', 'seo', '生成 llms.txt');
    success(['at' => date('Y-m-d H:i')], $msg);
}

// 保存推送配置（百度站点/token）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_push') {
    settingModel()->set('seo_baidu_site', trim((string) ($_POST['baidu_site'] ?? '')));
    settingModel()->set('seo_baidu_token', trim((string) ($_POST['baidu_token'] ?? '')));
    success([], '已保存');
}

// 生成 IndexNow 密钥文件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_indexnow_key') {
    [$ok, $msg, $key] = seo_ensure_indexnow_key();
    if (!$ok) {
        error($msg);
    }
    success(['key' => $key], $msg);
}

// 重定向管理器（专业版）CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['redirect_add', 'redirect_delete', 'log_clear', 'log_delete'], true)) {
    if (!$seoHasPro) {
        error('该功能需要 SEO 工坊专业版');
    }
    seo_redirect_ensure_tables();
    $act = $_POST['action'];
    if ($act === 'redirect_add') {
        [$ok, $msg] = seo_redirect_add((string) ($_POST['source'] ?? ''), (string) ($_POST['target'] ?? ''), (int) ($_POST['type'] ?? 301));
        $ok ? success([], $msg) : error($msg);
    } elseif ($act === 'redirect_delete') {
        seo_redirect_delete((int) ($_POST['id'] ?? 0));
        success([], '已删除');
    } elseif ($act === 'log_delete') {
        seo_404_delete((int) ($_POST['id'] ?? 0));
        success([], '已删除');
    } else { // log_clear
        seo_404_clear();
        success([], '已清空');
    }
}

// 推送到百度 / IndexNow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['push_baidu', 'push_indexnow'], true)) {
    $urls = seo_all_urls(500);
    if (($_POST['action']) === 'push_baidu') {
        [$ok, $msg] = seo_submit_baidu((string) config('seo_baidu_site', ''), (string) config('seo_baidu_token', ''), $urls);
    } else {
        $host = parse_url(siteBaseUrl(), PHP_URL_HOST) ?: '';
        [$ok, $msg] = seo_submit_indexnow((string) $host, (string) config('seo_indexnow_key', ''), $urls);
    }
    if (!$ok) {
        error($msg);
    }
    adminLog('plugin', 'seo', $_POST['action'] . ': ' . $msg);
    success(['count' => count($urls)], $msg);
}

// —— 页面数据 ——
$llmsPreview = seo_build_llms_txt();
$llmsPath    = seo_llms_path();
$llmsExists  = file_exists($llmsPath);
$llmsGenAt   = (int) config('seo_llms_generated_at', 0);
$siteUrl     = rtrim(siteBaseUrl(), '/');
$siteUrlSet  = trim((string) config('site_url', '')) !== '';

// 搜索引擎推送
$baiduSite         = (string) config('seo_baidu_site', '');
$baiduToken        = (string) config('seo_baidu_token', '');
$indexnowKey       = (string) config('seo_indexnow_key', '');
$indexnowHost      = (string) (parse_url(siteBaseUrl(), PHP_URL_HOST) ?: '');
$indexnowKeyExists = $indexnowKey !== '' && file_exists(seo_indexnow_key_path($indexnowKey));
$pushUrlCount      = count(seo_all_urls(500));

// 重定向管理器（专业版）
$redirectRules = [];
$log404 = [];
if ($seoHasPro) {
    seo_redirect_ensure_tables();
    $redirectRules = seo_redirect_list(500);
    $log404 = seo_404_list(200);
}

$pageTitle   = 'SEO 工坊';
$currentMenu = 'plugin';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <div class="flex items-center gap-2">
        <a href="/admin/plugin.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <i class="ti ti-chevron-left text-base"></i> 插件管理
        </a>
    </div>
    <?php if ($seoHasPro): ?>
        <span class="text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded inline-flex items-center gap-1">
            <i class="ti ti-crown text-sm"></i> 专业版已激活
        </span>
    <?php else: ?>
        <a href="/admin/plugin.php" class="text-xs font-medium bg-gray-900 text-white px-3 py-1.5 rounded inline-flex items-center gap-1 hover:bg-black">
            <i class="ti ti-crown text-sm text-amber-400"></i> 升级专业版
        </a>
    <?php endif; ?>
</div>

<div x-data="seoWorkshop()">

    <!-- ===== 免费：llms.txt 生成器 ===== -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-robot text-blue-500"></i> llms.txt 生成器
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">生成站点根 <code>/llms.txt</code>，用简洁 Markdown 指引 AI 助手理解你的站点结构与重点内容。</p>
            </div>
            <span class="text-xs font-medium bg-green-100 text-green-700 px-2 py-1 rounded">免费</span>
        </div>
        <div class="p-6">
            <?php if (!$siteUrlSet): ?>
            <div class="mb-4 text-sm bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-2.5 flex items-start gap-2">
                <i class="ti ti-alert-triangle text-base mt-0.5"></i>
                <span>未配置站点网址（系统设置 → 站点网址），llms.txt 里的链接可能不完整。当前推断为 <code><?php echo e($siteUrl); ?></code>。</span>
            </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center gap-3 mb-4">
                <button type="button" @click="generate()" :disabled="loading"
                        class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg inline-flex items-center gap-1.5">
                    <i class="ti text-base" :class="loading ? 'ti-loader-2 animate-spin' : 'ti-file-download'"></i>
                    <span x-text="loading ? '生成中…' : '生成 / 更新 llms.txt'"></span>
                </button>

                <template x-if="exists">
                    <a href="/llms.txt" target="_blank"
                       class="text-sm border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 px-3 py-2 rounded-lg inline-flex items-center gap-1.5">
                        <i class="ti ti-external-link text-base"></i> 查看 /llms.txt
                    </a>
                </template>

                <span class="text-xs text-gray-400" x-show="genAt" x-text="'上次生成：' + genAt"></span>
                <template x-if="!exists && !genAt">
                    <span class="text-xs text-gray-400">尚未生成</span>
                </template>
            </div>

            <details class="border border-gray-200 rounded-lg">
                <summary class="cursor-pointer select-none px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 flex items-center gap-1.5">
                    <i class="ti ti-eye text-base"></i> 预览内容
                </summary>
                <pre class="text-xs bg-gray-50 border-t border-gray-100 p-4 overflow-x-auto leading-relaxed text-gray-700 whitespace-pre-wrap"><?php echo e($llmsPreview); ?></pre>
            </details>

            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                提示：内容变化后需重新点击「生成 / 更新」刷新 llms.txt（自动刷新将在后续版本加入）。全站完整清单请用
                <a href="/sitemap.php" target="_blank" class="text-blue-500 hover:underline">sitemap.xml</a>，二者互补。
            </p>
        </div>
    </div>

    <!-- ===== 免费：搜索引擎主动推送 ===== -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-send text-blue-500"></i> 搜索引擎主动推送
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">把站点 URL 主动提交给搜索引擎，加快收录。当前可推送约 <strong x-text="urlCount"></strong> 条 URL。</p>
            </div>
            <span class="text-xs font-medium bg-green-100 text-green-700 px-2 py-1 rounded">免费</span>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- 百度 -->
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="font-medium text-gray-800 text-sm mb-3 flex items-center gap-1.5">
                    <i class="ti ti-brand-baidu text-blue-600"></i> 百度（普通收录）
                </div>
                <label class="block text-xs text-gray-500 mb-1">站点（与百度资源平台一致，如 http://www.example.com）</label>
                <input type="text" x-model="baiduSite" placeholder="http://www.example.com"
                       class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm mb-2">
                <label class="block text-xs text-gray-500 mb-1">推送 token</label>
                <input type="text" x-model="baiduToken" placeholder="百度资源平台 → 普通收录 → API 提交"
                       class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm mb-3">
                <div class="flex items-center gap-2">
                    <button type="button" @click="savePush()" :disabled="busy"
                            class="text-sm border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 px-3 py-1.5 rounded-lg">保存</button>
                    <button type="button" @click="push('baidu')" :disabled="busy"
                            class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-sm px-3 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                        <i class="ti ti-send text-base"></i> 推送到百度
                    </button>
                </div>
            </div>

            <!-- IndexNow -->
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="font-medium text-gray-800 text-sm mb-3 flex items-center gap-1.5">
                    <i class="ti ti-brand-bing text-teal-600"></i> IndexNow（Bing / Yandex 等）
                </div>
                <p class="text-xs text-gray-500 mb-2">一次提交，多引擎共享。需在站点根放一个密钥文件供验证。</p>
                <div class="text-xs mb-2">
                    <span class="text-gray-500">域名：</span><code><?php echo e($indexnowHost ?: '未配置站点网址'); ?></code>
                </div>
                <div class="text-xs mb-3 flex items-center gap-2">
                    <span class="text-gray-500">密钥文件：</span>
                    <template x-if="keyReady">
                        <a :href="'/' + keyName + '.txt'" target="_blank" class="text-green-600 inline-flex items-center gap-1"><i class="ti ti-circle-check"></i><span x-text="'/' + keyName + '.txt'"></span></a>
                    </template>
                    <template x-if="!keyReady">
                        <span class="text-amber-600 inline-flex items-center gap-1"><i class="ti ti-alert-circle"></i>未生成</span>
                    </template>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="genKey()" :disabled="busy"
                            class="text-sm border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 px-3 py-1.5 rounded-lg">
                        <span x-text="keyReady ? '重新生成密钥' : '生成密钥文件'"></span>
                    </button>
                    <button type="button" @click="push('indexnow')" :disabled="busy || !keyReady"
                            class="bg-teal-600 hover:bg-teal-500 disabled:opacity-50 text-white text-sm px-3 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                        <i class="ti ti-send text-base"></i> 推送 IndexNow
                    </button>
                </div>
            </div>
        </div>
        <div class="px-6 pb-4 -mt-2">
            <p class="text-xs text-gray-400" x-show="pushMsg" x-text="pushMsg"></p>
        </div>
    </div>

    <?php if ($seoHasPro): ?>
    <!-- ===== 专业版：重定向管理器 ===== -->
    <div id="seo-redirects" class="bg-white rounded-lg shadow mb-6" x-data="seoRedirects()">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-arrows-right-left text-amber-500"></i> 重定向管理器
            </h2>
            <span class="text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded inline-flex items-center gap-1"><i class="ti ti-crown text-sm"></i> Pro</span>
        </div>
        <div class="p-6">
            <!-- 添加规则 -->
            <div class="flex flex-wrap items-end gap-2 mb-4">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs text-gray-500 mb-1">来源路径</label>
                    <input type="text" x-model="src" x-ref="src" placeholder="/old-page.html"
                           class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm">
                </div>
                <div class="text-gray-300 pb-1.5"><i class="ti ti-arrow-right"></i></div>
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs text-gray-500 mb-1">目标（路径或完整 URL）</label>
                    <input type="text" x-model="dst" placeholder="/new-page.html 或 https://…"
                           class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">类型</label>
                    <select x-model="type" class="border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                        <option value="301">301 永久</option>
                        <option value="302">302 临时</option>
                    </select>
                </div>
                <button type="button" @click="add()" :disabled="busy"
                        class="bg-amber-500 hover:bg-amber-400 disabled:opacity-50 text-white text-sm px-4 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                    <i class="ti ti-plus text-base"></i> 添加
                </button>
            </div>
            <p class="text-xs text-red-500 mb-3" x-show="msg" x-text="msg"></p>

            <!-- 规则列表 -->
            <?php if (!$redirectRules): ?>
                <p class="text-sm text-gray-400 text-center py-6">还没有重定向规则。改版换链接时，在此把旧地址跳到新地址，保住 SEO 权重与流量。</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400 border-b">
                        <th class="py-2 pr-3">来源</th><th class="py-2 pr-3">目标</th>
                        <th class="py-2 pr-3 whitespace-nowrap">类型</th><th class="py-2 pr-3 whitespace-nowrap">命中</th><th class="py-2 w-10"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($redirectRules as $r): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-2 pr-3 font-mono text-xs text-gray-700 break-all"><?php echo e($r['source']); ?></td>
                            <td class="py-2 pr-3 font-mono text-xs text-blue-600 break-all"><?php echo e($r['target']); ?></td>
                            <td class="py-2 pr-3"><span class="text-xs px-1.5 py-0.5 rounded <?php echo (int) $r['type'] === 302 ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-700'; ?>"><?php echo (int) $r['type']; ?></span></td>
                            <td class="py-2 pr-3 text-gray-500"><?php echo (int) $r['hits']; ?></td>
                            <td class="py-2 text-right">
                                <button type="button" @click="del(<?php echo (int) $r['id']; ?>)" class="text-gray-400 hover:text-red-500 p-1" title="删除"><i class="ti ti-trash text-sm"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- 404 监控 -->
        <div class="px-6 py-4 border-t border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-alert-triangle text-red-400"></i> 404 监控
                <span class="text-xs font-normal text-gray-400">（访客碰到的死链，点「建重定向」一键修复）</span>
            </h3>
            <?php if ($log404): ?>
            <button type="button" @click="clearLog()" class="text-xs text-gray-400 hover:text-red-500 inline-flex items-center gap-1"><i class="ti ti-trash text-sm"></i> 清空</button>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <?php if (!$log404): ?>
                <p class="text-sm text-gray-400 text-center py-6">暂无 404 记录。访客访问到不存在的页面时会记录在此。</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400 border-b">
                        <th class="py-2 pr-3">路径</th><th class="py-2 pr-3 whitespace-nowrap">次数</th>
                        <th class="py-2 pr-3 whitespace-nowrap">最近</th><th class="py-2 w-28"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($log404 as $l): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-2 pr-3 font-mono text-xs text-gray-700 break-all"><?php echo e($l['path']); ?></td>
                            <td class="py-2 pr-3 text-gray-500"><?php echo (int) $l['hits']; ?></td>
                            <td class="py-2 pr-3 text-gray-400 text-xs whitespace-nowrap"><?php echo $l['last_seen'] ? date('m-d H:i', (int) $l['last_seen']) : '—'; ?></td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <button type="button" @click="fixFrom(<?php echo htmlspecialchars(json_encode($l['path']), ENT_QUOTES); ?>)" class="text-xs text-amber-600 hover:text-amber-500 px-2 py-1">建重定向</button>
                                <button type="button" @click="delLog(<?php echo (int) $l['id']; ?>)" class="text-gray-400 hover:text-red-500 p-1" title="删除"><i class="ti ti-x text-sm"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function seoRedirects() {
            return {
                src: "", dst: "", type: "301", busy: false, msg: "",
                _post(action, extra) {
                    var b = new URLSearchParams(); b.set("action", action);
                    Object.keys(extra || {}).forEach(function (k) { b.set(k, extra[k]); });
                    return fetch(window.location.href, { method: "POST", body: b }).then(function (r) { return r.json(); });
                },
                add() {
                    var self = this; this.busy = true; this.msg = "";
                    this._post("redirect_add", { source: this.src, target: this.dst, type: this.type })
                        .then(function (res) { if (res && res.code === 0) location.reload(); else { self.msg = (res && res.msg) || "添加失败"; self.busy = false; } })
                        .catch(function () { self.msg = "添加失败"; self.busy = false; });
                },
                del(id) {
                    if (!confirm("删除这条重定向规则？")) return;
                    this._post("redirect_delete", { id: id }).then(function () { location.reload(); });
                },
                delLog(id) { this._post("log_delete", { id: id }).then(function () { location.reload(); }); },
                clearLog() { if (confirm("清空全部 404 记录？")) this._post("log_clear").then(function () { location.reload(); }); },
                fixFrom(path) {
                    this.src = path; this.dst = "";
                    this.$refs.src.scrollIntoView({ behavior: "smooth", block: "center" });
                    this.$nextTick(function () {}); var self = this; setTimeout(function () { self.$refs.src.focus(); }, 300);
                },
            };
        }
        </script>
    </div>
    <?php endif; ?>

    <!-- ===== 专业版功能（就地上锁展示） ===== -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-crown text-amber-500"></i> 专业版功能
            </h2>
            <?php if (!$seoHasPro): ?>
            <span class="text-xs text-gray-400">升级后解锁</span>
            <?php endif; ?>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php
            $proCards = [
                ['ti-sparkles',   'AI 一键优化 meta', '基于站内 AI，一键生成 / 改写 SEO 标题与描述、推荐关键词。', ''],
                ['ti-arrows-right-left', '重定向管理器', '301/404 监控与重定向规则，改版换链接不丢权重、不丢流量。', '#seo-redirects'],
                ['ti-send',       '搜索引擎自动推送', '发布 / 更新 / 删除时自动 ping 百度、IndexNow（Bing/Yandex），带历史与配额。', ''],
                ['ti-link',       '内链建议 + 基石内容', '基于内容相似度推荐内链、标记基石内容，优化站点结构。', ''],
            ];
            foreach ($proCards as [$icon, $title, $desc, $liveAnchor]):
                $isLive = $liveAnchor !== '';
            ?>
            <div class="relative border rounded-lg p-4 <?php echo $seoHasPro ? 'border-gray-200' : 'border-gray-200 bg-gray-50'; ?>">
                <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center shrink-0">
                        <i class="ti <?php echo $icon; ?> text-lg"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="font-medium text-gray-800 text-sm flex items-center gap-1.5">
                            <?php echo e($title); ?>
                            <?php if (!$seoHasPro): ?><i class="ti ti-lock text-xs text-gray-400"></i><?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?php echo e($desc); ?></p>
                        <?php if ($seoHasPro && $isLive): ?>
                        <a href="<?php echo e($liveAnchor); ?>" class="inline-flex items-center gap-1 mt-2 text-xs text-green-600 hover:text-green-500"><i class="ti ti-circle-check text-sm"></i> 已上线 · 前往设置</a>
                        <?php elseif ($seoHasPro): ?>
                        <span class="inline-block mt-2 text-xs text-gray-400">即将上线</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$seoHasPro): ?>
        <div class="px-6 pb-6">
            <div class="bg-gradient-to-r from-gray-900 to-gray-700 rounded-lg px-5 py-4 flex items-center justify-between gap-4">
                <div class="text-white text-sm">
                    <div class="font-medium">解锁 SEO 工坊专业版</div>
                    <div class="text-gray-300 text-xs mt-0.5">购买注册码后即可开启以上全部高级功能。</div>
                </div>
                <a href="/admin/plugin.php" class="bg-amber-400 hover:bg-amber-300 text-gray-900 text-sm font-medium px-4 py-2 rounded-lg whitespace-nowrap">
                    了解专业版
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    function seoWorkshop() {
        return {
            loading: false,
            exists: <?php echo $llmsExists ? 'true' : 'false'; ?>,
            genAt: <?php echo $llmsGenAt ? ('"' . date('Y-m-d H:i', $llmsGenAt) . '"') : '""'; ?>,
            // 搜索引擎推送
            busy: false,
            pushMsg: "",
            urlCount: <?php echo (int) $pushUrlCount; ?>,
            baiduSite: <?php echo json_encode($baiduSite, JSON_UNESCAPED_UNICODE); ?>,
            baiduToken: <?php echo json_encode($baiduToken, JSON_UNESCAPED_UNICODE); ?>,
            keyName: <?php echo json_encode($indexnowKey, JSON_UNESCAPED_UNICODE); ?>,
            keyReady: <?php echo $indexnowKeyExists ? 'true' : 'false'; ?>,
            _post(action, extra) {
                var body = new URLSearchParams();
                body.set("action", action);
                Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
                return fetch(window.location.href, { method: "POST", body: body }).then(function (r) { return r.json(); });
            },
            savePush() {
                var self = this; this.busy = true; this.pushMsg = "";
                this._post("save_push", { baidu_site: this.baiduSite, baidu_token: this.baiduToken })
                    .then(function (res) { self.pushMsg = (res && res.msg) || "已保存"; })
                    .catch(function () { self.pushMsg = "保存失败"; })
                    .finally(function () { self.busy = false; });
            },
            genKey() {
                var self = this; this.busy = true; this.pushMsg = "";
                this._post("gen_indexnow_key")
                    .then(function (res) {
                        if (res && res.code === 0) { self.keyName = res.data.key; self.keyReady = true; self.pushMsg = res.msg; }
                        else self.pushMsg = (res && res.msg) || "生成失败";
                    })
                    .catch(function () { self.pushMsg = "生成失败"; })
                    .finally(function () { self.busy = false; });
            },
            push(engine) {
                var self = this; this.busy = true; this.pushMsg = "推送中…";
                this._post(engine === "baidu" ? "push_baidu" : "push_indexnow")
                    .then(function (res) { self.pushMsg = (res && res.msg) || "完成"; })
                    .catch(function () { self.pushMsg = "推送失败"; })
                    .finally(function () { self.busy = false; });
            },
            generate() {
                var self = this;
                this.loading = true;
                var body = new URLSearchParams();
                body.set("action", "gen_llms");
                fetch(window.location.href, { method: "POST", body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.code === 0) {
                            self.exists = true;
                            self.genAt = (res.data && res.data.at) || "";
                            if (window.showToast) window.showToast(res.msg || "已生成");
                            else alert(res.msg || "已生成");
                        } else {
                            alert((res && res.msg) || "生成失败");
                        }
                    })
                    .catch(function() { alert("生成失败"); })
                    .finally(function() { self.loading = false; });
            },
        };
    }
    </script>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
