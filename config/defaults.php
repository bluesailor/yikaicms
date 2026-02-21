<?php
/**
 * Yikai CMS - 系统默认值配置
 *
 * 此文件定义所有设置项的出厂默认值。
 * 用于：恢复默认值、显示默认值提示、安装初始化。
 * 请勿手动修改此文件中的值，除非你知道自己在做什么。
 *
 * 结构：group => [ key => [value, type, name, tip, options?, sort_order] ]
 */

return [

    // ============================================================
    // 基本设置
    // ============================================================
    'basic' => [
        'site_url'              => ['value' => '', 'type' => 'text', 'name' => '站点域名', 'tip' => '如 https://www.example.com（不含末尾斜杠）', 'sort_order' => 0],
        'site_name'             => ['value' => 'Yikai CMS', 'type' => 'text', 'name' => '站点名称', 'tip' => '', 'sort_order' => 1],
        'site_keywords'         => ['value' => '企业官网,CMS系统,内容管理', 'type' => 'textarea', 'name' => 'SEO关键词', 'tip' => '多个关键词用逗号分隔', 'sort_order' => 2],
        'site_description'      => ['value' => '专业的企业内容管理系统，助力企业数字化转型', 'type' => 'textarea', 'name' => 'SEO描述', 'tip' => '', 'sort_order' => 3],
        'site_logo'             => ['value' => '', 'type' => 'image', 'name' => '站点Logo', 'tip' => '', 'sort_order' => 4],
        'site_favicon'          => ['value' => '/favicon.ico', 'type' => 'image', 'name' => '站点图标', 'tip' => '浏览器标签页图标，支持 .ico/.png 格式', 'sort_order' => 5],
        'site_icp'              => ['value' => '', 'type' => 'text', 'name' => 'ICP备案号', 'tip' => '', 'sort_order' => 5],
        'site_police'           => ['value' => '', 'type' => 'text', 'name' => '公安备案号', 'tip' => '', 'sort_order' => 6],
        'primary_color'         => ['value' => '#3B82F6', 'type' => 'color', 'name' => '主题色', 'tip' => '十六进制颜色值', 'sort_order' => 7],
        'secondary_color'       => ['value' => '#1D4ED8', 'type' => 'color', 'name' => '次要色', 'tip' => '十六进制颜色值', 'sort_order' => 8],
        'banner_height_pc'      => ['value' => '650', 'type' => 'number', 'name' => '轮播图高度(PC)', 'tip' => '单位像素', 'sort_order' => 9],
        'banner_height_mobile'  => ['value' => '300', 'type' => 'number', 'name' => '轮播图高度(移动)', 'tip' => '单位像素', 'sort_order' => 10],
        'product_layout'        => ['value' => 'sidebar', 'type' => 'select', 'name' => '产品列表版式', 'tip' => '', 'options' => '{"sidebar":"侧栏模式","top":"顶栏模式"}', 'sort_order' => 11],
        'show_price'            => ['value' => '0', 'type' => 'select', 'name' => '显示产品价格', 'tip' => '前台是否显示产品价格', 'options' => '{"0":"不显示","1":"显示"}', 'sort_order' => 12],
    ],

    // ============================================================
    // 页头设置
    // ============================================================
    'header' => [
        'topbar_enabled'        => ['value' => '0', 'type' => 'select', 'name' => '显示顶部通栏', 'tip' => 'Logo上方的通栏区域', 'options' => '{"0":"隐藏","1":"显示"}', 'sort_order' => 0],
        'topbar_bg_color'       => ['value' => '#f3f4f6', 'type' => 'color', 'name' => '通栏背景色', 'tip' => '顶部通栏背景颜色', 'sort_order' => 1],
        'topbar_left'           => ['value' => '', 'type' => 'code', 'name' => '通栏左侧内容', 'tip' => '支持HTML代码，如电话、公告等', 'sort_order' => 2],
        'show_member_entry'     => ['value' => '0', 'type' => 'select', 'name' => '显示会员入口', 'tip' => '顶部通栏开启时显示在通栏右侧，关闭时显示在导航栏内', 'options' => '{"0":"隐藏","1":"显示"}', 'sort_order' => 3],
        'header_nav_layout'     => ['value' => 'right', 'type' => 'select', 'name' => '导航布局', 'tip' => 'Logo右侧或Logo下方通栏', 'options' => '{"right":"Logo右侧","below":"Logo下方通栏"}', 'sort_order' => 10],
        'header_sticky'         => ['value' => '0', 'type' => 'select', 'name' => '固定顶部', 'tip' => '导航栏是否固定在页面顶部', 'options' => '{"1":"是","0":"否"}', 'sort_order' => 11],
        'header_bg_color'       => ['value' => '#ffffff', 'type' => 'color', 'name' => '背景颜色', 'tip' => '十六进制颜色值', 'sort_order' => 12],
        'header_text_color'     => ['value' => '#4b5563', 'type' => 'color', 'name' => '文字颜色', 'tip' => '十六进制颜色值', 'sort_order' => 13],
    ],

    // ============================================================
    // 页脚设置
    // ============================================================
    'footer' => [
        'footer_columns'        => ['value' => '[{"title":"关于我们","content":"{{site_description}}","col_span":2},{"title":"联系方式","content":"{{contact_info}}","col_span":1},{"title":"关注我们","content":"{{qrcode}}","col_span":1}]', 'type' => 'footer_columns', 'name' => '页脚栏目', 'tip' => '自定义页脚各列内容，最多4列', 'sort_order' => 1],
        'footer_bg_color'       => ['value' => '#1f2937', 'type' => 'color', 'name' => '背景颜色', 'tip' => '十六进制颜色值', 'sort_order' => 2],
        'footer_bg_image'       => ['value' => '', 'type' => 'image', 'name' => '背景图片', 'tip' => '设置后覆盖背景颜色', 'sort_order' => 3],
        'footer_text_color'     => ['value' => '#9ca3af', 'type' => 'color', 'name' => '文字颜色', 'tip' => '十六进制颜色值', 'sort_order' => 4],
    ],

    // ============================================================
    // 代码注入
    // ============================================================
    'code' => [
        'custom_head_code'      => ['value' => '', 'type' => 'code', 'name' => 'Head 代码', 'tip' => '插入到 </head> 前的代码，如网站验证、SEO meta 标签等', 'sort_order' => 1],
        'custom_body_code'      => ['value' => '', 'type' => 'code', 'name' => 'Body 代码', 'tip' => '插入到 </body> 前的代码，如统计代码、在线客服等', 'sort_order' => 2],
    ],

    // ============================================================
    // 联系方式
    // ============================================================
    'contact' => [
        'contact_cards'         => ['value' => '[{"icon":"phone","label":"联系电话","value":"400-888-8888"},{"icon":"email","label":"电子邮箱","value":"contact@example.com"},{"icon":"location","label":"公司地址","value":"上海市浦东新区XX路XX号"}]', 'type' => 'contact_cards', 'name' => '联系信息卡片', 'tip' => '联系我们页面顶部展示的信息卡片，最多4个', 'sort_order' => 0],
        'contact_phone'         => ['value' => '400-888-8888', 'type' => 'text', 'name' => '联系电话', 'tip' => '', 'sort_order' => 1],
        'contact_email'         => ['value' => 'contact@example.com', 'type' => 'text', 'name' => '联系邮箱', 'tip' => '', 'sort_order' => 2],
        'contact_address'       => ['value' => '上海市浦东新区XX路XX号', 'type' => 'textarea', 'name' => '联系地址', 'tip' => '', 'sort_order' => 3],
        'contact_qrcode'        => ['value' => '', 'type' => 'image', 'name' => '微信二维码', 'tip' => '', 'sort_order' => 4],
        'contact_map'           => ['value' => '', 'type' => 'image', 'name' => '地图图片', 'tip' => '', 'sort_order' => 5],
        'contact_form_title'    => ['value' => '在线留言', 'type' => 'text', 'name' => '表单标题', 'tip' => '', 'sort_order' => 10],
        'contact_form_desc'     => ['value' => '', 'type' => 'textarea', 'name' => '表单描述', 'tip' => '显示在标题下方的说明文字', 'sort_order' => 11],
        'contact_form_fields'   => ['value' => '[{"key":"name","label":"您的姓名","type":"text","required":true,"enabled":true},{"key":"phone","label":"联系电话","type":"tel","required":true,"enabled":true},{"key":"email","label":"电子邮箱","type":"email","required":false,"enabled":true},{"key":"company","label":"公司名称","type":"text","required":false,"enabled":true},{"key":"content","label":"留言内容","type":"textarea","required":true,"enabled":true}]', 'type' => 'contact_form_fields', 'name' => '表单字段', 'tip' => '设置表单显示的字段、标签和是否必填', 'sort_order' => 12],
        'contact_form_success'  => ['value' => '提交成功，我们会尽快与您联系！', 'type' => 'text', 'name' => '提交成功提示', 'tip' => '表单提交成功后显示的文字', 'sort_order' => 13],
    ],

    // ============================================================
    // 邮件设置
    // ============================================================
    'email' => [
        'smtp_host'             => ['value' => '', 'type' => 'text', 'name' => 'SMTP服务器', 'tip' => '如：smtp.qq.com', 'sort_order' => 1],
        'smtp_port'             => ['value' => '465', 'type' => 'text', 'name' => 'SMTP端口', 'tip' => 'SSL常用465，TLS常用587', 'sort_order' => 2],
        'smtp_secure'           => ['value' => 'ssl', 'type' => 'text', 'name' => '加密方式', 'tip' => 'ssl/tls/空', 'sort_order' => 3],
        'smtp_user'             => ['value' => '', 'type' => 'text', 'name' => 'SMTP用户名', 'tip' => '通常是完整邮箱地址', 'sort_order' => 4],
        'smtp_pass'             => ['value' => '', 'type' => 'text', 'name' => 'SMTP密码', 'tip' => 'QQ邮箱需使用授权码', 'sort_order' => 5],
        'mail_from'             => ['value' => '', 'type' => 'text', 'name' => '发件人邮箱', 'tip' => '留空则使用SMTP用户名', 'sort_order' => 6],
        'mail_from_name'        => ['value' => '', 'type' => 'text', 'name' => '发件人名称', 'tip' => '留空使用站点名称', 'sort_order' => 7],
        'mail_admin'            => ['value' => '', 'type' => 'text', 'name' => '管理员邮箱', 'tip' => '接收表单提交通知', 'sort_order' => 8],
        'mail_notify_form'      => ['value' => '0', 'type' => 'text', 'name' => '表单提交通知', 'tip' => '1开启/0关闭', 'sort_order' => 9],
    ],

    // ============================================================
    // 首页设置
    // ============================================================
    'home' => [
        'home_about_content'        => ['value' => '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。经过多年发展，已成为行业内具有影响力的企业之一。', 'type' => 'textarea', 'name' => '关于我们简介', 'tip' => '首页关于我们区块的介绍文字', 'sort_order' => 1],
        'home_about_image'          => ['value' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 'type' => 'image', 'name' => '关于我们图片', 'tip' => '首页关于我们区块的图片', 'sort_order' => 2],
        'home_about_tag_title'      => ['value' => '专业服务', 'type' => 'text', 'name' => '角标标题', 'tip' => '图片左下角标签标题', 'sort_order' => 3],
        'home_about_tag_desc'       => ['value' => '品质 · 创新 · 共赢', 'type' => 'text', 'name' => '角标描述', 'tip' => '图片左下角标签描述', 'sort_order' => 4],
        'home_stat_1_num'           => ['value' => '10+', 'type' => 'text', 'name' => '统计数字1', 'tip' => '', 'sort_order' => 5],
        'home_stat_1_text'          => ['value' => '年行业经验', 'type' => 'text', 'name' => '统计文字1', 'tip' => '', 'sort_order' => 6],
        'home_about_layout'         => ['value' => 'text_left', 'type' => 'select', 'name' => '关于我们布局', 'tip' => '左文右图或左图右文', 'options' => '{"text_left":"左文右图","image_left":"左图右文"}', 'sort_order' => 6],
        'home_stat_2_num'           => ['value' => '1000+', 'type' => 'text', 'name' => '统计数字2', 'tip' => '', 'sort_order' => 7],
        'home_stat_2_text'          => ['value' => '服务客户', 'type' => 'text', 'name' => '统计文字2', 'tip' => '', 'sort_order' => 8],
        'home_stat_3_num'           => ['value' => '50+', 'type' => 'text', 'name' => '统计数字3', 'tip' => '', 'sort_order' => 9],
        'home_stat_3_text'          => ['value' => '专业团队', 'type' => 'text', 'name' => '统计文字3', 'tip' => '', 'sort_order' => 10],
        'home_stat_4_num'           => ['value' => '100%', 'type' => 'text', 'name' => '统计数字4', 'tip' => '', 'sort_order' => 11],
        'home_stat_bg'              => ['value' => '', 'type' => 'image', 'name' => '统计区背景图', 'tip' => '数据统计横栏的背景图片', 'sort_order' => 12],
        'home_stat_4_text'          => ['value' => '客户满意', 'type' => 'text', 'name' => '统计文字4', 'tip' => '', 'sort_order' => 12],
        'home_advantage_desc'       => ['value' => '专业团队，优质服务，值得信赖', 'type' => 'text', 'name' => '优势区块描述', 'tip' => '', 'sort_order' => 13],
        'home_adv_1_icon'           => ['value' => 'check-circle', 'type' => 'icon', 'name' => '优势1图标', 'tip' => '', 'sort_order' => 14],
        'home_adv_1_title'          => ['value' => '品质保证', 'type' => 'text', 'name' => '优势1标题', 'tip' => '', 'sort_order' => 14],
        'home_adv_1_desc'           => ['value' => '严格把控产品质量，确保每一件产品都符合标准', 'type' => 'text', 'name' => '优势1描述', 'tip' => '', 'sort_order' => 15],
        'home_adv_2_icon'           => ['value' => 'academic-cap', 'type' => 'icon', 'name' => '优势2图标', 'tip' => '', 'sort_order' => 16],
        'home_adv_2_title'          => ['value' => '技术领先', 'type' => 'text', 'name' => '优势2标题', 'tip' => '', 'sort_order' => 16],
        'home_adv_2_desc'           => ['value' => '持续研发创新，保持技术的领先优势', 'type' => 'text', 'name' => '优势2描述', 'tip' => '', 'sort_order' => 17],
        'home_adv_3_icon'           => ['value' => 'briefcase', 'type' => 'icon', 'name' => '优势3图标', 'tip' => '', 'sort_order' => 18],
        'home_adv_3_title'          => ['value' => '专业服务', 'type' => 'text', 'name' => '优势3标题', 'tip' => '', 'sort_order' => 18],
        'home_adv_3_desc'           => ['value' => '专业团队7x24小时技术支持服务', 'type' => 'text', 'name' => '优势3描述', 'tip' => '', 'sort_order' => 19],
        'home_adv_4_icon'           => ['value' => 'users', 'type' => 'icon', 'name' => '优势4图标', 'tip' => '', 'sort_order' => 20],
        'home_adv_4_title'          => ['value' => '合作共赢', 'type' => 'text', 'name' => '优势4标题', 'tip' => '', 'sort_order' => 20],
        'home_adv_4_desc'           => ['value' => '与客户建立长期合作关系，实现互利共赢', 'type' => 'text', 'name' => '优势4描述', 'tip' => '', 'sort_order' => 21],
        'home_cta_title'            => ['value' => '准备好开始合作了吗？', 'type' => 'text', 'name' => 'CTA标题', 'tip' => '行动号召区块标题', 'sort_order' => 22],
        'home_cta_desc'             => ['value' => '联系我们，获取专业的解决方案', 'type' => 'text', 'name' => 'CTA描述', 'tip' => '行动号召区块描述', 'sort_order' => 23],
        'home_show_links'           => ['value' => '1', 'type' => 'select', 'name' => '显示合作伙伴', 'tip' => '是否在页脚显示合作伙伴', 'sort_order' => 24],
        'home_links_title'          => ['value' => '合作伙伴', 'type' => 'text', 'name' => '链接区块标题', 'tip' => '页脚合作伙伴区块的标题', 'sort_order' => 25],
        'home_testimonials'         => ['value' => '[{"avatar":"","name":"张先生","company":"某科技有限公司","content":"非常专业的服务团队，合作非常愉快！产品质量令人满意。"},{"avatar":"","name":"李女士","company":"某贸易公司","content":"产品质量优秀，售后服务及时，值得信赖的合作伙伴。"},{"avatar":"","name":"王总","company":"某集团公司","content":"多年合作，一直保持高品质的服务水准，强烈推荐！"}]', 'type' => 'home_testimonials', 'name' => '客户评价', 'tip' => '首页客户评价区块数据', 'sort_order' => 26],
        'home_testimonials_title'   => ['value' => '客户评价', 'type' => 'text', 'name' => '评价区标题', 'tip' => '客户评价区块的标题', 'sort_order' => 27],
        'home_testimonials_desc'    => ['value' => '听听合作伙伴怎么说', 'type' => 'text', 'name' => '评价区描述', 'tip' => '客户评价区块的副标题', 'sort_order' => 28],
        'home_show_banner'          => ['value' => '1', 'type' => 'select', 'name' => '显示轮播图', 'tip' => '首页Banner轮播图区块', 'sort_order' => 30],
        'home_show_about'           => ['value' => '1', 'type' => 'select', 'name' => '显示关于我们', 'tip' => '首页关于我们简介区块', 'sort_order' => 31],
        'home_show_stats'           => ['value' => '1', 'type' => 'select', 'name' => '显示数据统计', 'tip' => '首页数据统计横栏', 'sort_order' => 32],
        'home_show_channels'        => ['value' => '1', 'type' => 'select', 'name' => '显示栏目区块', 'tip' => '产品中心、新闻资讯等首页展示栏目', 'sort_order' => 33],
        'home_show_advantage'       => ['value' => '1', 'type' => 'select', 'name' => '显示优势展示', 'tip' => '首页我们的优势区块', 'sort_order' => 34],
        'home_show_cta'             => ['value' => '1', 'type' => 'select', 'name' => '显示行动号召', 'tip' => '首页底部CTA行动号召区块', 'sort_order' => 35],
        'home_blocks_config'        => ['value' => '[{"type":"banner","enabled":true},{"type":"about","enabled":true},{"type":"stats","enabled":true},{"type":"channels","enabled":true},{"type":"testimonials","enabled":true},{"type":"advantage","enabled":true},{"type":"cta","enabled":true}]', 'type' => 'home_blocks', 'name' => '首页区块配置', 'tip' => '首页区块顺序与显示配置', 'sort_order' => 40],
    ],

    // ============================================================
    // 会员设置
    // ============================================================
    'member' => [
        'allow_member_register' => ['value' => '0', 'type' => 'switch', 'name' => '允许会员注册', 'tip' => '关闭后前台将无法注册新会员', 'sort_order' => 1],
        'download_require_login' => ['value' => '0', 'type' => 'switch', 'name' => '下载需要登录', 'tip' => '开启后下载文件需要会员登录', 'sort_order' => 2],
    ],

];
