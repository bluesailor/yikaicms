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
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                <span class="ai-spark"></span><span class="ai-spark"></span><span class="ai-spark"></span>
            </div>
            <span class="text-sm font-bold text-gray-700">AI 助手</span>
            <span id="aiStatus" class="text-xs text-gray-400"></span>
        </div>
        <svg id="aiBoxArrow" class="w-4 h-4 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
    </div>

    <!-- 展开内容 -->
    <div id="aiBoxBody" class="hidden border-t">
        <div class="p-5 space-y-3">
            <input type="hidden" id="aiPanelAction" value="generate_all">

            <!-- 提示词 -->
            <div>
                <textarea id="aiPrompt" rows="2" class="w-full border rounded px-3 py-2 text-sm" placeholder="输入提示词，如：写一篇关于工业自动化PLC控制器的介绍，突出节能优势。留空则使用文章标题。"></textarea>
            </div>

            <!-- 模式 + 生成项 -->
            <div class="flex items-start gap-6">
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button type="button" data-action="generate_all" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition font-medium">一键生成</button>
                    <button type="button" data-action="generate_article" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition">仅内容</button>
                    <button type="button" data-action="polish" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition">润色</button>
                    <button type="button" data-action="continue" class="ai-mode-btn px-3 py-1 text-xs rounded-full border cursor-pointer transition">续写</button>
                    <span class="text-gray-200">|</span>
                    <button type="button" onclick="aiQuick('generate_summary')" class="px-3 py-1 text-xs rounded-full border border-gray-200 text-gray-500 hover:text-blue-500 cursor-pointer transition">摘要</button>
                    <button type="button" onclick="aiQuick('generate_seo')" class="px-3 py-1 text-xs rounded-full border border-gray-200 text-gray-500 hover:text-purple-500 cursor-pointer transition">SEO</button>
                </div>
            </div>

            <!-- 一键生成选项 -->
            <div id="aiAllOptions" class="flex items-center gap-4 text-xs text-gray-500">
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenTitle" checked class="w-3.5 h-3.5 rounded"> 标题</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenSummary" checked class="w-3.5 h-3.5 rounded"> 摘要</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenTags" checked class="w-3.5 h-3.5 rounded"> 标签</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenSlug" checked class="w-3.5 h-3.5 rounded"> 别名</label>
                <label class="flex items-center gap-1 cursor-pointer"><input type="checkbox" id="aiGenContent" checked class="w-3.5 h-3.5 rounded"> 内容</label>
            </div>

            <!-- 高级选项 + 生成按钮 -->
            <div class="flex items-center justify-between pt-2 border-t">
                <details class="inline">
                    <summary class="text-xs text-gray-400 cursor-pointer hover:text-blue-500 select-none">高级选项</summary>
                    <div class="mt-3 space-y-3 p-4 bg-gray-50 rounded-lg">
                        <div class="grid grid-cols-4 gap-3">
                            <div><label class="block text-xs text-gray-500 mb-1">行业</label><input type="text" id="aiIndustry" class="w-full border rounded px-2 py-1 text-xs" placeholder="工业自动化"></div>
                            <div><label class="block text-xs text-gray-500 mb-1">受众</label><input type="text" id="aiAudience" class="w-full border rounded px-2 py-1 text-xs" placeholder="企业采购"></div>
                            <div><label class="block text-xs text-gray-500 mb-1">关键词</label><input type="text" id="aiKeywords" class="w-full border rounded px-2 py-1 text-xs" placeholder="逗号分隔"></div>
                            <div><label class="block text-xs text-gray-500 mb-1">补充要求</label><input type="text" id="aiExtra" class="w-full border rounded px-2 py-1 text-xs" placeholder="末尾加号召"></div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-400">风格：</span>
                                <button type="button" data-val="professional" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">专业</button>
                                <button type="button" data-val="friendly" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">通俗</button>
                                <button type="button" data-val="marketing" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">营销</button>
                                <button type="button" data-val="news" class="ai-style-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">资讯</button>
                            </div>
                            <input type="hidden" id="aiStyle" value="professional">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-gray-400">字数：</span>
                                <button type="button" data-val="300" class="ai-len-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">~300</button>
                                <button type="button" data-val="800" class="ai-len-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">~800</button>
                                <button type="button" data-val="1500" class="ai-len-btn px-2 py-0.5 text-xs rounded-full border cursor-pointer transition">~1500</button>
                            </div>
                            <input type="hidden" id="aiLength" value="800">
                        </div>
                    </div>
                </details>

                <button type="button" id="aiPanelSubmit" onclick="submitAiPanel()" class="bg-blue-500 hover:bg-blue-600 text-white px-5 py-1.5 rounded text-sm cursor-pointer inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    开始生成
                </button>
            </div>
        </div>
    </div>
</div>
