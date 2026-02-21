<?php
/**
 * 搜索替换插件
 *
 * 在后台提供数据库批量搜索替换功能
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 注册后台管理菜单入口
add_action('ik_admin_footer_scripts', function () {
    // 在插件管理页面下方添加入口提示
    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentPage !== 'plugin.php') return;
    echo '<script>
    (function(){
        var list = document.getElementById("pluginList");
        if (!list) return;
        var card = document.getElementById("plugin-search-replace");
        if (!card) return;
        var info = card.querySelector(".text-xs.text-gray-400");
        if (info) {
            var link = document.createElement("a");
            link.href = "/admin/plugin_page.php?plugin=search-replace";
            link.className = "text-primary hover:underline ml-4";
            link.textContent = "进入管理页面";
            info.appendChild(link);
        }
    })();
    </script>';
});
