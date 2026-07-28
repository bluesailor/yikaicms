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

// —— 页面数据 ——
$llmsPreview = seo_build_llms_txt();
$llmsPath    = seo_llms_path();
$llmsExists  = file_exists($llmsPath);
$llmsGenAt   = (int) config('seo_llms_generated_at', 0);
$siteUrl     = rtrim(siteBaseUrl(), '/');
$siteUrlSet  = trim((string) config('site_url', '')) !== '';

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
                ['ti-sparkles',   'AI 一键优化 meta', '基于站内 AI，一键生成 / 改写 SEO 标题与描述、推荐关键词。'],
                ['ti-arrows-right-left', '重定向管理器', '301/404 监控与重定向规则，改版换链接不丢权重、不丢流量。'],
                ['ti-send',       '搜索引擎自动推送', '发布 / 更新 / 删除时自动 ping 百度、IndexNow（Bing/Yandex），带历史与配额。'],
                ['ti-link',       '内链建议 + 基石内容', '基于内容相似度推荐内链、标记基石内容，优化站点结构。'],
            ];
            foreach ($proCards as [$icon, $title, $desc]):
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
                        <?php if ($seoHasPro): ?>
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
