<?php
/**
 * Yikai CMS - 后台页面目录
 *
 * 单一数据源：被 cms_navigate_admin ability、/admin/api_search.php 端点共用，
 * 避免维护两套清单。
 */

declare(strict_types=1);

if (!function_exists('adminPagesCatalog')) {
    /**
     * @return list<array{url:string,title:string,keywords:string,group:string}>
     */
    function adminPagesCatalog(): array
    {
        return [
            // 内容
            ['url'=>'/admin/channel.php',          'title'=>'栏目管理',     'keywords'=>'栏目 分类 导航 菜单 频道 排序 首页栏目',                                  'group'=>'内容'],
            ['url'=>'/admin/page.php',             'title'=>'单页管理',     'keywords'=>'单页 关于我们 隐私政策 服务条款 公司简介 about privacy terms',         'group'=>'内容'],
            ['url'=>'/admin/article.php',          'title'=>'文章管理',     'keywords'=>'文章 新闻 资讯 博客 动态 article news blog',                            'group'=>'内容'],

            // 产品
            ['url'=>'/admin/product.php',          'title'=>'产品管理',     'keywords'=>'产品 商品 product item',                                               'group'=>'产品'],
            ['url'=>'/admin/product_category.php', 'title'=>'产品分类',     'keywords'=>'产品分类 产品类别 category',                                            'group'=>'产品'],
            ['url'=>'/admin/product_setting.php',  'title'=>'产品设置',     'keywords'=>'产品布局 询盘 产品价格显示',                                            'group'=>'产品'],
            ['url'=>'/admin/case.php',             'title'=>'案例管理',     'keywords'=>'案例 项目 客户案例 case study',                                          'group'=>'产品'],

            // 资料
            ['url'=>'/admin/download.php',         'title'=>'下载中心',     'keywords'=>'下载 文件 资料 download',                                              'group'=>'资料'],
            ['url'=>'/admin/job.php',              'title'=>'招聘管理',     'keywords'=>'招聘 职位 人才 hire recruit',                                           'group'=>'资料'],

            // 媒体
            ['url'=>'/admin/banner.php',           'title'=>'幻灯片',       'keywords'=>'banner 轮播 首页大图 头图 slideshow',                                  'group'=>'媒体'],
            ['url'=>'/admin/media.php',            'title'=>'媒体库',       'keywords'=>'媒体 图片 文件 上传 media upload',                                       'group'=>'媒体'],
            ['url'=>'/admin/album.php',            'title'=>'相册',         'keywords'=>'相册 照片集 photo gallery',                                             'group'=>'媒体'],
            ['url'=>'/admin/timeline.php',         'title'=>'时间轴',       'keywords'=>'时间轴 历程 大事记 timeline history',                                   'group'=>'媒体'],
            ['url'=>'/admin/link.php',             'title'=>'友情链接',     'keywords'=>'友链 link 友情链接',                                                    'group'=>'媒体'],

            // 数据
            ['url'=>'/admin/form.php',             'title'=>'表单数据',     'keywords'=>'留言 表单 询盘 反馈 contact form submission',                            'group'=>'数据'],
            ['url'=>'/admin/form_design.php',      'title'=>'表单设计',     'keywords'=>'表单设计 自定义表单 联系表单 form builder',                              'group'=>'数据'],
            ['url'=>'/admin/member.php',           'title'=>'会员管理',     'keywords'=>'会员 用户 注册用户 member user',                                         'group'=>'数据'],
            ['url'=>'/admin/setting_member.php',   'title'=>'会员设置',     'keywords'=>'会员注册 登录策略 是否开放注册',                                         'group'=>'数据'],

            // 站点
            ['url'=>'/admin/setting.php?tab=pagination', 'title'=>'列表与分页', 'keywords'=>'分页 每页 列表数量 产品 新闻 案例 下载 招聘 pagination page size', 'group'=>'站点'],
            ['url'=>'/admin/setting.php?tab=basic', 'title'=>'基本设置',     'keywords'=>'网站标题 网站名称 网站描述 关键词 LOGO favicon 备案 ICP 后台标题 颜色',       'group'=>'站点'],
            ['url'=>'/admin/setting.php?tab=url',   'title'=>'URL 与链接',   'keywords'=>'URL 链接 网址 伪静态 Rewrite rewrite 动态URL index.php 无需Rewrite',              'group'=>'站点'],
            ['url'=>'/admin/setting.php?tab=header','title'=>'页头设置',     'keywords'=>'页头 头部 header 导航 logo 吸顶 sticky 透明度',                         'group'=>'站点'],
            ['url'=>'/admin/setting.php?tab=footer','title'=>'页脚设置',     'keywords'=>'页脚 footer 版权 底部',                                           'group'=>'站点'],
            ['url'=>'/admin/setting.php?tab=code',  'title'=>'代码注入',     'keywords'=>'代码 注入 head script css js 统计代码',                             'group'=>'站点'],
            ['url'=>'/admin/setting.php?tab=lang',  'title'=>'站点语言',     'keywords'=>'语言 多语言 默认语言 前台语言 language',                            'group'=>'站点'],
            ['url'=>'/admin/setting_contact.php',  'title'=>'联系方式',     'keywords'=>'联系方式 电话 邮箱 邮件 公司地址 微信 二维码 客服 contact phone email address', 'group'=>'站点'],
            ['url'=>'/admin/setting_social.php',   'title'=>'社交账号',     'keywords'=>'社交 微博 抖音 知乎 facebook twitter linkedin instagram social',         'group'=>'站点'],
            ['url'=>'/admin/setting_email.php',    'title'=>'邮件 / SMTP',  'keywords'=>'SMTP 邮件发送 发件 邮箱配置 mail email server',                          'group'=>'站点'],
            ['url'=>'/admin/setting_seo.php',      'title'=>'SEO 设置',     'keywords'=>'SEO sitemap robots 站长 统计代码 google analytics',                       'group'=>'站点'],

            // 外观
            ['url'=>'/admin/theme.php',            'title'=>'主题切换',     'keywords'=>'主题 模板 皮肤 外观 theme template',                                     'group'=>'外观'],
            ['url'=>'/admin/plugin.php',           'title'=>'插件管理',     'keywords'=>'插件 扩展 plugin extension',                                            'group'=>'外观'],
            ['url'=>'/admin/extfield.php',         'title'=>'扩展字段',     'keywords'=>'自定义字段 扩展字段 custom field',                                       'group'=>'外观'],
            ['url'=>'/admin/setting_ai.php',       'title'=>'AI 设置',      'keywords'=>'AI API Key 大模型 OpenAI Claude DeepSeek 模型选择',                       'group'=>'外观'],
            ['url'=>'/admin/ai_assistant.php',     'title'=>'AI 助手',      'keywords'=>'AI 助手 智能助手 对话 chatbot',                                          'group'=>'外观'],

            // 系统
            ['url'=>'/admin/user.php',             'title'=>'后台用户',     'keywords'=>'后台账号 管理员 admin user 后台用户',                                    'group'=>'系统'],
            ['url'=>'/admin/role.php',             'title'=>'角色权限',     'keywords'=>'角色 权限 RBAC permission role',                                         'group'=>'系统'],
            ['url'=>'/admin/setting_security.php', 'title'=>'安全设置',     'keywords'=>'安全 登录限流 密码策略 security',                                         'group'=>'系统'],
            ['url'=>'/admin/setting_api.php',      'title'=>'开放接口',     'keywords'=>'API 开放接口 公开接口 json 小程序 headless api key 接口',                 'group'=>'系统'],
            ['url'=>'/admin/system.php',           'title'=>'系统信息',     'keywords'=>'系统信息 服务器 PHP 数据库环境 system info',                              'group'=>'系统'],
            ['url'=>'/admin/system_log.php',       'title'=>'系统日志',     'keywords'=>'系统日志 操作记录 audit log',                                            'group'=>'系统'],
            ['url'=>'/admin/database.php',         'title'=>'数据库管理',   'keywords'=>'数据库 备份 恢复 database backup',                                       'group'=>'系统'],
            ['url'=>'/admin/static_html.php',      'title'=>'静态HTML生成', 'keywords'=>'静态 静态化 生成html 纯静态 缓存 static html generate 性能 抗压',         'group'=>'系统'],
            ['url'=>'/admin/upgrade.php',          'title'=>'系统升级',     'keywords'=>'升级 更新 补丁 upgrade patch',                                           'group'=>'系统'],
        ];
    }

    /**
     * 计算查询匹配分数（共用打分函数）。
     */
    function adminPagesSearch(string $query, int $limit = 8): array
    {
        $q = mb_strtolower(trim($query));
        if ($q === '') return [];
        $terms = array_filter(preg_split('/\s+/u', $q));
        $scored = [];
        foreach (adminPagesCatalog() as $page) {
            $haystack = mb_strtolower($page['title'] . ' ' . $page['keywords']);
            $score = 0;
            foreach ($terms as $t) {
                if (mb_strpos($haystack, $t) !== false) $score += 10;
                foreach (mb_str_split($t) as $ch) {
                    if (mb_strlen($ch) > 0 && mb_strpos($haystack, $ch) !== false) $score += 1;
                }
            }
            if ($score > 0) {
                $scored[] = $page + ['score' => $score];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] - $a['score']);
        return array_slice($scored, 0, $limit);
    }
}
