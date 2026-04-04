<?php
/**
 * AI 助手插件
 *
 * 通过钩子注入 AI 浮动面板到后台编辑页面
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 加载 AI 服务类
require_once __DIR__ . '/AiService.php';

// 在后台页脚注入 AI 浮动面板（仅编辑页面）
add_action('ik_admin_footer_scripts', function () {
    // 仅在已配置 API Key 时生效
    if (!config('ai_api_key')) return;

    // 仅在文章/产品/内容编辑页面显示
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $editPages = ['article_edit.php', 'page_edit.php', 'product_edit.php'];
    if (!in_array($page, $editPages)) return;

    include __DIR__ . '/panel.php';
});

// 后台插件管理页入口
add_action('ik_admin_footer_scripts', function () {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'plugin.php') return;
    echo '<script>
    (function(){
        var card = document.getElementById("plugin-ai-assistant");
        if (!card) return;
        var info = card.querySelector(".text-xs.text-gray-400");
        if (info) {
            var a = document.createElement("a");
            a.href = "/admin/plugin_page.php?plugin=ai-assistant";
            a.className = "text-primary hover:underline ml-4";
            a.textContent = "AI 设置";
            info.appendChild(a);
        }
    })();
    </script>';
});
