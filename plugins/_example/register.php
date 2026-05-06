<?php
/**
 * 示例插件 - 早期注册文件
 *
 * 可用钩子参考：
 *   动作 (do_action):
 *     init, admin_init, plugins_loaded
 *     after_save_content($id, $data, $isUpdate)
 *     after_save_product($id, $data, $isUpdate)
 *     before_delete_content($id) / after_delete_content($id)
 *     before_delete_product($id) / after_delete_product($id)
 *     form_submitted($formId, $data)
 *     setting_saved($settings)
 *     admin_menu($currentMenu)
 *     render_head / render_footer
 *     ik_head / ik_footer_scripts / ik_admin_head / ik_admin_footer_scripts
 *
 *   过滤器 (apply_filters):
 *     before_save_content($data, $id) : array
 *     before_save_product($data, $id) : array
 *     before_save_form($data) : array
 *     content_output($html, $content) : string
 *     sitemap_urls($urls) : array
 *     mail_notify($mail) : array
 */

if (!defined('ROOT_PATH')) exit;

// 示例：为内容正文追加版权声明
add_filter('content_output', function (string $html, array $content): string {
    return $html . "\n<p class=\"text-sm text-gray-400 mt-6\">本文由 " . htmlspecialchars($content['author'] ?? '编辑部') . " 编辑发布</p>";
}, 20);

// 示例：内容保存后清除静态缓存
add_action('after_save_content', function (int $id, array $data, bool $isUpdate): void {
    if (function_exists('htmlCacheInvalidate')) {
        htmlCacheInvalidate();
    }
});
