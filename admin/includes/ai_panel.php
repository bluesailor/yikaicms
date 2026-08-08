<?php
/**
 * AI 助手 - 内嵌面板（放在文章标题上方，可展开收起）
 * 在编辑页面中 include 此文件
 */
if (!defined('ROOT_PATH') || !config('ai_api_key')) return;
$GLOBALS['_ai_panel_loaded'] = true;
?>

<style>
@keyframes ai-pulse { 0%,100% { opacity:1; filter:drop-shadow(0 0 2px rgba(59,130,246,.5)); } 50% { opacity:.6; filter:drop-shadow(0 0 6px rgba(59,130,246,.8)); } }
@keyframes ai-spark { 0% { transform:scale(0) rotate(0); opacity:1; } 100% { transform:scale(1.2) rotate(180deg); opacity:0; } }
#aiIcon { animation: ai-pulse 2s ease-in-out infinite; }
#aiIcon .ai-spark { position:absolute; width:4px; height:4px; background:#3b82f6; border-radius:50%; animation:ai-spark 1.5s ease-out infinite; }
#aiIcon .ai-spark:nth-child(2) { top:-2px; right:0; animation-delay:.3s; }
#aiIcon .ai-spark:nth-child(3) { bottom:0; left:-2px; animation-delay:.7s; }
#aiIcon .ai-spark:nth-child(4) { top:2px; right:-3px; animation-delay:1.1s; }
</style>

<div class="bg-white rounded-lg shadow mb-4" id="aiBox">
    <!-- 标题栏（点击展开/收起） -->
    <div class="px-5 py-2.5 flex items-center justify-between cursor-pointer hover:bg-gray-50 transition rounded-lg" onclick="toggleAiBox()">
        <div class="flex items-center gap-2.5">
            <div id="aiIcon" class="relative w-6 h-6 flex items-center justify-center">
                <i class="ti ti-bulb text-lg text-blue-500"></i>
                <span class="ai-spark"></span><span class="ai-spark"></span><span class="ai-spark"></span>
            </div>
            <span class="text-sm font-bold text-gray-700"><?php echo __('admin_ai_assistant'); ?></span>
            <span id="aiStatus" class="text-xs text-gray-400"></span>
        </div>
        <i class="ti ti-chevron-down text-base text-gray-400 transition-transform"></i>
    </div>

    <!-- 展开内容 -->
    <div id="aiBoxBody" class="hidden border-t">
        <div class="p-5 space-y-3">
            <input type="hidden" id="aiPanelAction" value="generate_all">

            <!-- 提示词 -->
            <div>
                <textarea id="aiPrompt" rows="2" class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo e(__('aip_prompt_ph')); ?>"></textarea>
            </div>

            <!-- 模式 + 生成项 -->
            <div class="flex items-start gap-6">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button type="button" data-action="generate_all" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition font-medium"><?php echo e(__('aip_generate_all')); ?></button>
                    <button type="button" data-action="generate_article" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_content_only')); ?></button>
                    <button type="button" data-action="polish" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_polish')); ?></button>
                    <button type="button" data-action="continue" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_continue')); ?></button>
                    <span class="text-gray-200">|</span>
                    <button type="button" onclick="aiQuick('generate_summary')" class="px-3 py-1 text-xs rounded-full border border-gray-200 text-gray-500 hover:text-blue-500 cursor-pointer transition"><?php echo e(__('aip_summary')); ?></button>
                    <button type="button" onclick="aiQuick('generate_seo')" class="px-3 py-1 text-xs rounded-full border border-gray-200 text-gray-500 hover:text-purple-500 cursor-pointer transition">SEO</button>
                </div>
            </div>

            <!-- 一键生成选项 -->
            <div id="aiAllOptions" class="flex items-center gap-4 text-xs text-gray-500">
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenTitle" checked class="w-3.5 h-3.5 rounded"> <?php echo e(__('label_title')); ?></label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenSummary" checked class="w-3.5 h-3.5 rounded"> <?php echo e(__('aip_summary')); ?></label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenTags" checked class="w-3.5 h-3.5 rounded"> <?php echo e(__('aip_tags')); ?></label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenSlug" checked class="w-3.5 h-3.5 rounded"> <?php echo e(__('admin_slug')); ?></label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenContent" checked class="w-3.5 h-3.5 rounded"> <?php echo e(__('aip_content')); ?></label>
            </div>

            <!-- 高级选项 + 生成按钮 -->
            <div class="flex items-center justify-between pt-2 border-t">
                <details class="inline">
                    <summary class="text-xs text-gray-400 cursor-pointer hover:text-blue-500 select-none"><?php echo e(__('aip_advanced')); ?></summary>
                    <div class="mt-3 space-y-3 p-4 bg-gray-50 rounded-lg">
                        <div class="grid grid-cols-4 gap-3">
                            <div><label class="block text-xs text-gray-500 mb-1"><?php echo e(__('aip_industry')); ?></label><input type="text" id="aiIndustry" class="w-full border rounded px-2 py-1 text-xs" placeholder="<?php echo e(__('aip_industry_ph')); ?>"></div>
                            <div><label class="block text-xs text-gray-500 mb-1"><?php echo e(__('aip_audience')); ?></label><input type="text" id="aiAudience" class="w-full border rounded px-2 py-1 text-xs" placeholder="<?php echo e(__('aip_audience_ph')); ?>"></div>
                            <div><label class="block text-xs text-gray-500 mb-1"><?php echo e(__('aip_keywords')); ?></label><input type="text" id="aiKeywords" class="w-full border rounded px-2 py-1 text-xs" placeholder="<?php echo e(__('aip_comma_sep')); ?>"></div>
                            <div><label class="block text-xs text-gray-500 mb-1"><?php echo e(__('aip_extra')); ?></label><input type="text" id="aiExtra" class="w-full border rounded px-2 py-1 text-xs" placeholder="<?php echo e(__('aip_extra_ph')); ?>"></div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-400"><?php echo e(__('aip_style')); ?></span>
                                <button type="button" data-val="professional" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_style_pro')); ?></button>
                                <button type="button" data-val="friendly" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_style_friendly')); ?></button>
                                <button type="button" data-val="marketing" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_style_marketing')); ?></button>
                                <button type="button" data-val="news" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition"><?php echo e(__('aip_style_news')); ?></button>
                            </div>
                            <input type="hidden" id="aiStyle" value="professional">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-400"><?php echo e(__('aip_length')); ?></span>
                                <button type="button" data-val="300" class="ai-len-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">~300</button>
                                <button type="button" data-val="800" class="ai-len-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">~800</button>
                                <button type="button" data-val="1500" class="ai-len-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">~1500</button>
                            </div>
                            <input type="hidden" id="aiLength" value="800">
                        </div>
                    </div>
                </details>

                <button type="button" id="aiPanelSubmit" onclick="submitAiPanel()" class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-1.5 rounded text-sm cursor-pointer inline-flex items-center gap-1.5">
                    <i class="ti ti-bolt text-base"></i>
                    <?php echo e(__('aip_start')); ?>
                </button>
            </div>
        </div>
    </div>
</div>
