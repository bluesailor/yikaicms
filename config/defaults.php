<?php
/**
 * Yikai CMS - 系统默认值配置
 *
 * 定义所有设置项的默认值。
 * 用途：恢复默认值、安装初始化。
 * 如不清楚含义请勿手动修改。
 *
 * 结构：group => [ key => [value, type, name, tip, options?, sort_order] ]
 */

return [

    // ============================================================
    // 基本设置
    // ============================================================
    'basic' => [
        // section：本页分区名（setting.php 按此分区渲染 + 生成吸顶快速导航）
        'site_url'              => ['value' => '', 'type' => 'text', 'name' => '站点URL', 'tip' => '例: https://www.example.com（末尾不要加斜杠）', 'section' => '站点信息', 'sort_order' => 0],
        'site_name'             => ['value' => 'Yikai CMS', 'type' => 'text', 'name' => '站点名称', 'tip' => '', 'section' => '站点信息', 'sort_order' => 1],
        'site_keywords'         => ['value' => '企业网站,CMS,内容管理', 'type' => 'textarea', 'name' => 'SEO关键词', 'tip' => '多个关键词用逗号分隔', 'section' => '站点信息', 'sort_order' => 2],
        'site_description'      => ['value' => '专业的企业内容管理系统，助力企业数字化转型', 'type' => 'textarea', 'name' => 'SEO描述', 'tip' => '', 'section' => '站点信息', 'sort_order' => 3],
        'site_logo'             => ['value' => '', 'type' => 'image', 'name' => '站点Logo', 'tip' => '', 'section' => '站点标识', 'sort_order' => 4],
        'site_favicon'          => ['value' => '/favicon.ico', 'type' => 'image', 'name' => '站点图标', 'tip' => '浏览器标签页图标，支持 .ico/.png 格式', 'section' => '站点标识', 'sort_order' => 5],
        'primary_color'         => ['value' => '#3B82F6', 'type' => 'color', 'name' => '主题色', 'tip' => '十六进制颜色值', 'section' => '主题外观', 'sort_order' => 8],
        'secondary_color'       => ['value' => '#1D4ED8', 'type' => 'color', 'name' => '辅助色', 'tip' => '十六进制颜色值', 'section' => '主题外观', 'sort_order' => 8],
        'banner_height_pc'      => ['value' => '650', 'type' => 'number', 'name' => 'Banner高度(PC)', 'tip' => '像素', 'section' => '主题外观', 'sort_order' => 9],
        'banner_height_mobile'  => ['value' => '300', 'type' => 'number', 'name' => 'Banner高度(移动端)', 'tip' => '像素', 'section' => '主题外观', 'sort_order' => 10],
        'banner_fullscreen'     => ['value' => '0', 'type' => 'select', 'name' => '全屏大Banner', 'tip' => '开启后 PC 端首页轮播图满屏高(100vh-头部)，忽略 PC 高度；移动端仍用移动端高度', 'options' => '{"0":"关闭","1":"开启"}', 'section' => '主题外观', 'sort_order' => 11],
        'site_icp'              => ['value' => '', 'type' => 'text', 'name' => 'ICP备案号', 'tip' => '', 'section' => '备案信息', 'sort_order' => 6],
        'site_police'           => ['value' => '', 'type' => 'text', 'name' => '公安备案号', 'tip' => '', 'section' => '备案信息', 'sort_order' => 7],
        'upload_max_width'      => ['value' => '1920', 'type' => 'select', 'name' => '上传图片最大宽度', 'tip' => '客户上传图片超过此宽度时自动等比压缩，节省空间与带宽；选「不压缩」保留原图', 'options' => '{"0":"不压缩","1280":"1280px","1600":"1600px","1920":"1920px (推荐)","2048":"2048px","2560":"2560px"}', 'section' => '上传设置', 'sort_order' => 18],
        'upload_jpeg_quality'   => ['value' => '85', 'type' => 'select', 'name' => '图片压缩质量', 'tip' => 'JPEG/WebP 重新编码质量，越高越清晰但文件越大', 'options' => '{"75":"75 (更小)","85":"85 (推荐)","92":"92 (更清晰)"}', 'section' => '上传设置', 'sort_order' => 19],
        'admin_title'           => ['value' => 'Yikai CMS', 'type' => 'text', 'name' => '后台名称', 'tip' => '后台左上角显示的名称', 'section' => '后台品牌', 'sort_order' => 15],
        'admin_copyright'       => ['value' => '', 'type' => 'text', 'name' => '后台版权', 'tip' => '后台底部版权信息，留空不显示', 'section' => '后台品牌', 'sort_order' => 16],
        'admin_logo'            => ['value' => '', 'type' => 'image', 'name' => '后台Logo', 'tip' => '留空显示文字', 'section' => '后台品牌', 'sort_order' => 21],
        'current_theme'         => ['value' => 'default', 'type' => 'text', 'name' => '当前主题', 'tip' => 'themes/ 目录下的主题文件夹名', 'sort_order' => 17],
    ],

    // ============================================================
    // 页头设置
    // ============================================================
    'header' => [
        'page_hero_default_bg'  => ['value' => '', 'type' => 'image', 'name' => '内页头部默认背景图', 'tip' => '除首页外，栏目未单独设头图时，页面顶部横幅（面包屑区）统一用此图；留空则显示暗色渐变（minimal 主题除外，其内页头部不使用背景图）', 'sort_order' => -1],
        'topbar_enabled'        => ['value' => '0', 'type' => 'select', 'name' => '顶部通栏', 'tip' => 'Logo上方的通栏', 'options' => '{"0":"隐藏","1":"显示"}', 'sort_order' => 0],
        'topbar_bg_color'       => ['value' => '#f3f4f6', 'type' => 'color', 'name' => '通栏背景色', 'tip' => '', 'sort_order' => 1],
        'topbar_left'           => ['value' => '', 'type' => 'code', 'name' => '通栏左侧内容', 'tip' => '支持HTML（电话、公告等）', 'sort_order' => 2],
        'show_member_entry'     => ['value' => '0', 'type' => 'select', 'name' => '会员入口', 'tip' => '开启通栏时在右侧，否则在导航栏内', 'options' => '{"0":"隐藏","1":"显示"}', 'sort_order' => 3],
        'header_nav_layout'     => ['value' => 'right', 'type' => 'select', 'name' => '导航布局', 'tip' => 'Logo右侧或Logo下方', 'options' => '{"right":"Logo右侧","below":"Logo下方通栏"}', 'sort_order' => 10],
        'header_sticky'         => ['value' => '0', 'type' => 'select', 'name' => '吸顶固定', 'tip' => '导航是否固定在页面顶部', 'options' => '{"1":"是","0":"否"}', 'sort_order' => 11],
        'header_scroll_opacity' => ['value' => '100', 'type' => 'select', 'name' => '滚动时头部透明度', 'tip' => '开启吸顶后，向下滚动时头部略微透明（位置不变）。需先开启「吸顶固定」', 'options' => '{"100":"关闭（不透明）","97":"97%","94":"94%","90":"90%","85":"85%"}', 'sort_order' => 12],
        'header_bg_color'       => ['value' => '#ffffff', 'type' => 'color', 'name' => '背景色', 'tip' => '', 'sort_order' => 12],
        'header_text_color'     => ['value' => '#4b5563', 'type' => 'color', 'name' => '文字色', 'tip' => '', 'sort_order' => 13],
    ],

    // ============================================================
    // 页脚设置
    // ============================================================
    'footer' => [
        'footer_columns'        => ['value' => '[{"title":"关于我们","content":"{{site_description}}","col_span":2},{"title":"联系方式","content":"{{contact_info}}","col_span":1},{"title":"关注我们","content":"{{qrcode}}","col_span":1}]', 'type' => 'footer_columns', 'name' => '页脚栏目', 'tip' => '页脚各列内容（最多4列）', 'sort_order' => 1],
        'footer_bg_color'       => ['value' => '#1f2937', 'type' => 'color', 'name' => '背景色', 'tip' => '', 'sort_order' => 2],
        'footer_bg_image'       => ['value' => '', 'type' => 'image', 'name' => '背景图', 'tip' => '设置后覆盖背景色', 'sort_order' => 3],
        'footer_text_color'     => ['value' => '#9ca3af', 'type' => 'color', 'name' => '文字色', 'tip' => '', 'sort_order' => 4],
        'footer_nav'            => ['value' => '[]', 'type' => 'footer_nav', 'name' => '页脚导航', 'tip' => '版权上方的导航链接', 'sort_order' => 5],
        'footer_copyright_text' => ['value' => '© {year} {site_name} 版权所有.', 'type' => 'text', 'name' => '版权文字', 'tip' => '{year}=年份 {site_name}=站点名', 'sort_order' => 6],
    ],

    // ============================================================
    // 代码注入
    // ============================================================
    'code' => [
        'custom_head_code'      => ['value' => '', 'type' => 'code', 'name' => 'Head代码', 'tip' => '插入到</head>前的代码（SEO标签等）', 'sort_order' => 1],
        'custom_body_code'      => ['value' => '', 'type' => 'code', 'name' => 'Body代码', 'tip' => '插入到</body>前的代码（统计代码等）', 'sort_order' => 2],
    ],

    // ============================================================
    // 联系我们
    // ============================================================
    'contact' => [
        'contact_cards'         => ['value' => '[{"icon":"phone","label":"联系电话","value":"400-000-0000"},{"icon":"email","label":"电子邮箱","value":"info@example.com"},{"icon":"location","label":"公司地址","value":"上海市浦东新区XX路XX号"}]', 'type' => 'contact_cards', 'name' => '联系卡片', 'tip' => '联系页面的信息卡片（最多4个）', 'sort_order' => 0],
        'contact_phone'         => ['value' => '400-000-0000', 'type' => 'text', 'name' => '联系电话', 'tip' => '', 'sort_order' => 1],
        'contact_email'         => ['value' => 'info@example.com', 'type' => 'text', 'name' => '电子邮箱', 'tip' => '', 'sort_order' => 2],
        'contact_address'       => ['value' => '上海市浦东新区XX路XX号', 'type' => 'textarea', 'name' => '公司地址', 'tip' => '', 'sort_order' => 3],
        'contact_hours'         => ['value' => '周一至周五 9:00-18:00', 'type' => 'text', 'name' => '工作时间', 'tip' => '', 'sort_order' => 4],
        'contact_qrcode'        => ['value' => '', 'type' => 'image', 'name' => '二维码', 'tip' => '', 'sort_order' => 5],
        'contact_map'           => ['value' => '', 'type' => 'image', 'name' => '地图图片（兜底）', 'tip' => '未配置交互地图时显示此静态图', 'sort_order' => 5],
        'map_zh_provider'       => ['value' => '', 'type' => 'text', 'name' => '中文版地图', 'tip' => '留空=用上方静态地图图片；amap=高德；baidu=百度（需填对应 Key）。日/英文版固定用 Google 地图（免 Key）', 'sort_order' => 6],
        'map_lat'               => ['value' => '', 'type' => 'text', 'name' => '地图纬度 lat', 'tip' => '如 31.2304。在所选地图开放平台拾取坐标（高德/Google 与百度坐标系不同，会略偏）', 'sort_order' => 6],
        'map_lng'               => ['value' => '', 'type' => 'text', 'name' => '地图经度 lng', 'tip' => '如 121.4737', 'sort_order' => 6],
        'map_zoom'              => ['value' => '15', 'type' => 'text', 'name' => '地图缩放级别', 'tip' => '默认 15，数字越大越近', 'sort_order' => 7],
        'map_amap_key'          => ['value' => '', 'type' => 'text', 'name' => '高德地图 JS Key', 'tip' => '中文版选 amap 时填，lbs.amap.com 申请「Web端(JS API)」Key', 'sort_order' => 8],
        'map_baidu_ak'          => ['value' => '', 'type' => 'text', 'name' => '百度地图 ak', 'tip' => '中文版选 baidu 时填，lbsyun.baidu.com 申请「JavaScript API」ak', 'sort_order' => 8],
        'contact_form_title'    => ['value' => '在线留言', 'type' => 'text', 'name' => '表单标题', 'tip' => '', 'sort_order' => 10],
        'contact_form_desc'     => ['value' => '', 'type' => 'textarea', 'name' => '表单描述', 'tip' => '标题下方的说明文字', 'sort_order' => 11],
        'contact_form_fields'   => ['value' => '[{"key":"name","label":"您的姓名","type":"text","required":true,"enabled":true},{"key":"phone","label":"联系电话","type":"tel","required":true,"enabled":true},{"key":"email","label":"电子邮箱","type":"email","required":false,"enabled":true},{"key":"company","label":"公司名称","type":"text","required":false,"enabled":true},{"key":"content","label":"留言内容","type":"textarea","required":true,"enabled":true}]', 'type' => 'contact_form_fields', 'name' => '表单字段', 'tip' => '联系表单的字段配置', 'sort_order' => 12],
        'contact_form_success'  => ['value' => '提交成功，我们会尽快与您联系！', 'type' => 'text', 'name' => '提交成功提示', 'tip' => '表单提交后显示的消息', 'sort_order' => 13],
    ],

    // ============================================================
    // 邮件设置
    // ============================================================
    'email' => [
        'smtp_host'             => ['value' => '', 'type' => 'text', 'name' => 'SMTP服务器', 'tip' => '例：smtp.qq.com', 'sort_order' => 1],
        'smtp_port'             => ['value' => '465', 'type' => 'text', 'name' => 'SMTP端口', 'tip' => 'SSL:465, TLS:587', 'sort_order' => 2],
        'smtp_secure'           => ['value' => 'ssl', 'type' => 'text', 'name' => '加密方式', 'tip' => 'ssl/tls/空', 'sort_order' => 3],
        'smtp_user'             => ['value' => '', 'type' => 'text', 'name' => 'SMTP用户名', 'tip' => '通常是邮箱地址', 'sort_order' => 4],
        'smtp_pass'             => ['value' => '', 'type' => 'text', 'name' => 'SMTP密码', 'tip' => '部分邮箱需要使用授权码', 'sort_order' => 5],
        'mail_from'             => ['value' => '', 'type' => 'text', 'name' => '发件人邮箱', 'tip' => '留空则使用SMTP用户名', 'sort_order' => 6],
        'mail_from_name'        => ['value' => '', 'type' => 'text', 'name' => '发件人名称', 'tip' => '留空则使用站点名称', 'sort_order' => 7],
        'mail_admin'            => ['value' => '', 'type' => 'text', 'name' => '管理员邮箱', 'tip' => '接收表单提交通知的邮箱', 'sort_order' => 8],
        'mail_notify_form'      => ['value' => '0', 'type' => 'text', 'name' => '表单通知', 'tip' => '1:开启/0:关闭', 'sort_order' => 9],
        'mail_tpl_register_subject'  => ['value' => '注册成功 — {{site_name}}', 'type' => 'text', 'name' => '注册邮件标题', 'sort_order' => 20],
        'mail_tpl_register_body'     => ['value' => "{{username}} 您好：\n\n感谢您注册 {{site_name}}，您的账户已创建成功。\n\n您可以在会员中心管理账户：\n{{site_url}}/member/\n\n{{site_name}}\n{{date}}", 'type' => 'textarea', 'name' => '注册邮件内容', 'sort_order' => 21],
        'mail_tpl_forgot_subject'    => ['value' => '重置密码 — {{site_name}}', 'type' => 'text', 'name' => '重置密码邮件标题', 'sort_order' => 22],
        'mail_tpl_forgot_body'       => ['value' => "{{username}} 您好：\n\n我们收到了您的密码重置请求，请点击以下链接重置密码：\n{{reset_link}}\n\n该链接30分钟内有效。如非本人操作，请忽略此邮件。\n\n{{site_name}}\n{{date}}", 'type' => 'textarea', 'name' => '重置密码邮件内容', 'sort_order' => 23],
        'mail_tpl_reset_subject'     => ['value' => '密码已重置 — {{site_name}}', 'type' => 'text', 'name' => '密码重置成功标题', 'sort_order' => 24],
        'mail_tpl_reset_body'        => ['value' => "{{username}} 您好：\n\n您的密码已成功重置。如非本人操作，请立即联系我们。\n\n{{site_name}}\n{{date}}", 'type' => 'textarea', 'name' => '密码重置成功内容', 'sort_order' => 25],
        'mail_tpl_inquiry_subject'   => ['value' => '新询盘：{{product_title}} — {{site_name}}', 'type' => 'text', 'name' => '询盘通知标题', 'sort_order' => 26],
        'mail_tpl_inquiry_body'      => ['value' => "收到新的产品询盘：\n\n产品：{{product_title}}\n姓名：{{name}}\n电话：{{phone}}\n邮箱：{{email}}\n公司：{{company}}\n内容：{{content}}\n\n时间：{{date}}\nIP：{{ip}}\n\n管理后台：{{site_url}}/admin/form.php", 'type' => 'textarea', 'name' => '询盘通知内容', 'sort_order' => 27],
    ],

    // ============================================================
    // 首页设置
    // ============================================================
    'home' => [
        'home_about_title'          => ['value' => '', 'type' => 'text', 'name' => '关于版块标题', 'tip' => '首页"关于我们"区块大标题；留空 = 「关于」+ 站点名称', 'sort_order' => 0],
        'home_about_content'        => ['value' => '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。经过多年发展，已成为行业内具有影响力的企业之一。', 'type' => 'textarea', 'name' => '关于我们简介', 'tip' => '首页关于我们区块的描述文字', 'sort_order' => 1],
        'home_about_image'          => ['value' => 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 'type' => 'image', 'name' => '关于我们图片', 'tip' => '', 'sort_order' => 2],
        'home_about_tag_title'      => ['value' => '专业服务', 'type' => 'text', 'name' => '角标标题', 'tip' => '图片左下角标标题', 'sort_order' => 3],
        'home_about_tag_desc'       => ['value' => '品质 · 创新 · 共赢', 'type' => 'text', 'name' => '角标描述', 'tip' => '图片左下角标描述', 'sort_order' => 4],
        'home_stat_1_num'           => ['value' => '10+', 'type' => 'text', 'name' => '统计数值1', 'tip' => '', 'sort_order' => 5],
        'home_stat_1_text'          => ['value' => '年行业经验', 'type' => 'text', 'name' => '统计文字1', 'tip' => '', 'sort_order' => 6],
        'home_about_layout'         => ['value' => 'text_left', 'type' => 'select', 'name' => '关于我们布局', 'tip' => '文字在左或图片在左', 'options' => '{"text_left":"文字在左","image_left":"图片在左"}', 'sort_order' => 6],
        'home_stat_2_num'           => ['value' => '1000+', 'type' => 'text', 'name' => '统计数值2', 'tip' => '', 'sort_order' => 7],
        'home_stat_2_text'          => ['value' => '服务客户', 'type' => 'text', 'name' => '统计文字2', 'tip' => '', 'sort_order' => 8],
        'home_stat_3_num'           => ['value' => '50+', 'type' => 'text', 'name' => '统计数值3', 'tip' => '', 'sort_order' => 9],
        'home_stat_3_text'          => ['value' => '专业团队', 'type' => 'text', 'name' => '统计文字3', 'tip' => '', 'sort_order' => 10],
        'home_stat_4_num'           => ['value' => '100%', 'type' => 'text', 'name' => '统计数值4', 'tip' => '', 'sort_order' => 11],
        'home_stat_bg'              => ['value' => '', 'type' => 'image', 'name' => '统计区块背景', 'tip' => '统计横栏的背景图', 'sort_order' => 12],
        'home_stat_4_text'          => ['value' => '客户满意', 'type' => 'text', 'name' => '统计文字4', 'tip' => '', 'sort_order' => 12],
        'home_advantage_title'      => ['value' => '我们的优势', 'type' => 'text', 'name' => '优势区块标题', 'tip' => '首页"优势"区块的大标题', 'sort_order' => 13],
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
        'home_cta_title'            => ['value' => '准备好开始合作了吗？', 'type' => 'text', 'name' => 'CTA标题', 'tip' => '', 'sort_order' => 22],
        'home_cta_desc'             => ['value' => '联系我们，获取专业的解决方案', 'type' => 'text', 'name' => 'CTA描述', 'tip' => '', 'sort_order' => 23],
        'home_show_links'           => ['value' => '0', 'type' => 'select', 'name' => '显示合作伙伴', 'tip' => '页脚是否显示合作伙伴', 'sort_order' => 24],
        'home_links_title'          => ['value' => '合作伙伴', 'type' => 'text', 'name' => '链接区块标题', 'tip' => '', 'sort_order' => 25],
        'home_testimonials'         => ['value' => '[{"avatar":"","name":"张先生","company":"某科技有限公司","content":"非常专业的服务团队，合作非常愉快！产品质量令人满意。"},{"avatar":"","name":"李女士","company":"某贸易公司","content":"产品质量优秀，售后服务及时，值得信赖的合作伙伴。"},{"avatar":"","name":"王总","company":"某集团公司","content":"多年合作，一直保持高品质的服务水准，强烈推荐！"}]', 'type' => 'home_testimonials', 'name' => '客户评价', 'tip' => '首页客户评价数据', 'sort_order' => 26],
        'home_testimonials_title'   => ['value' => '客户评价', 'type' => 'text', 'name' => '评价标题', 'tip' => '', 'sort_order' => 27],
        'home_testimonials_desc'    => ['value' => '听听合作伙伴怎么说', 'type' => 'text', 'name' => '评价描述', 'tip' => '', 'sort_order' => 28],
        'home_show_banner'          => ['value' => '1', 'type' => 'select', 'name' => '显示Banner', 'tip' => '', 'sort_order' => 30],
        'home_show_about'           => ['value' => '1', 'type' => 'select', 'name' => '显示关于我们', 'tip' => '', 'sort_order' => 31],
        'home_show_stats'           => ['value' => '1', 'type' => 'select', 'name' => '显示统计', 'tip' => '', 'sort_order' => 32],
        'home_show_channels'        => ['value' => '1', 'type' => 'select', 'name' => '显示栏目区块', 'tip' => '', 'sort_order' => 33],
        'home_show_advantage'       => ['value' => '1', 'type' => 'select', 'name' => '显示优势', 'tip' => '', 'sort_order' => 34],
        'home_show_cta'             => ['value' => '1', 'type' => 'select', 'name' => '显示CTA', 'tip' => '', 'sort_order' => 35],
        'home_blocks_config'        => ['value' => '[{"type":"banner","enabled":true},{"type":"about","enabled":true},{"type":"stats","enabled":true},{"type":"channels","enabled":true},{"type":"testimonials","enabled":true},{"type":"advantage","enabled":true},{"type":"cta","enabled":true}]', 'type' => 'home_blocks', 'name' => '首页区块配置', 'tip' => '区块顺序和显示设置', 'sort_order' => 40],
    ],

    // ============================================================
    // 会员设置
    // ============================================================
    'member' => [
        'allow_member_register' => ['value' => '0', 'type' => 'switch', 'name' => '允许会员注册', 'tip' => '关闭后前台不可注册', 'sort_order' => 1],
        'download_require_login' => ['value' => '0', 'type' => 'switch', 'name' => '下载需登录', 'tip' => '开启后下载需要会员登录', 'sort_order' => 2],
    ],

    // ============================================================
    // 产品设置
    // ============================================================
    'product' => [
        'product_layout'        => ['value' => 'sidebar', 'type' => 'select', 'name' => '产品列表布局', 'tip' => '', 'options' => '{"sidebar":"侧边栏","top":"顶部栏"}', 'sort_order' => 1],
        'show_price'            => ['value' => '0', 'type' => 'select', 'name' => '显示产品价格', 'tip' => '前台是否显示价格', 'options' => '{"0":"隐藏","1":"显示"}', 'sort_order' => 2],
        'product_sort_options'  => ['value' => '["default","newest","views"]', 'type' => 'text', 'name' => '可用排序选项', 'tip' => 'JSON数组，可选：default/newest/updated/views/price_asc/price_desc', 'sort_order' => 3],
    ],

    // ============================================================
    // SNS设置
    // ============================================================
    'social' => [
        'social_links'          => ['value' => '[]', 'type' => 'social_links', 'name' => '社交链接', 'tip' => '页脚等位置显示的社交媒体图标链接', 'sort_order' => 1],
    ],

    // ============================================================
    // SEO 设置
    // ============================================================
    'seo' => [
        'seo_title' => ['value' => '', 'type' => 'text', 'name' => '首页SEO标题', 'tip' => '留空则用站点名称', 'sort_order' => 1],
    ],

    // ============================================================
    // 在线客服（由 admin/setting_customer_service.php 管理）
    // ============================================================
    'customer_service' => [
        'cs_enabled'     => ['value' => '0', 'type' => 'switch', 'name' => '启用在线客服', 'tip' => '', 'sort_order' => 1],
        'cs_position'    => ['value' => 'right', 'type' => 'select', 'name' => '悬浮位置', 'tip' => '', 'options' => '{"left":"左侧","right":"右侧"}', 'sort_order' => 2],
        'cs_show_mobile' => ['value' => '1', 'type' => 'switch', 'name' => '移动端显示', 'tip' => '', 'sort_order' => 3],
        'cs_button_text' => ['value' => '在线客服', 'type' => 'text', 'name' => '按钮文字', 'tip' => '', 'sort_order' => 4],
        'cs_panel_title' => ['value' => '欢迎咨询，期待与您合作', 'type' => 'text', 'name' => '面板标题', 'tip' => '', 'sort_order' => 5],
        'cs_items'       => ['value' => '[]', 'type' => 'text', 'name' => '客服项目', 'tip' => '客服渠道列表(JSON)，在「在线客服」页面编辑', 'sort_order' => 6],
    ],

    // 系统内部项（不挂常规设置页，由 admin/license.php 等专用页维护）
    'system' => [
        'license_key'   => ['value' => '', 'type' => 'text', 'name' => '授权码', 'tip' => '在「授权管理」页填写', 'sort_order' => 1],
        'license_state' => ['value' => '', 'type' => 'text', 'name' => '授权缓存', 'tip' => '系统自动维护，请勿手动修改', 'sort_order' => 2],
    ],

];
