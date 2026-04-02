<?php
/**
 * 翻译管理插件
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 注册后台管理入口
add_action('ik_admin_footer_scripts', function () {
    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($currentPage !== 'plugin.php') return;
    echo '<script>
    (function(){
        var card = document.getElementById("plugin-translate");
        if (!card) return;
        var info = card.querySelector(".text-xs.text-gray-400");
        if (info) {
            var link = document.createElement("a");
            link.href = "/admin/plugin_page.php?plugin=translate";
            link.className = "text-primary hover:underline ml-4";
            link.textContent = "进入翻译管理";
            info.appendChild(link);
        }
    })();
    </script>';
});
