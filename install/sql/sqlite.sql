-- ============================================================
-- Yikai CMS - SQLite 数据库结构
-- PHP 8.0+, SQLite 3
-- ============================================================

PRAGMA foreign_keys = OFF;

-- -----------------------------------------------------------
-- 用户表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_users;
CREATE TABLE yikai_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    nickname TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    avatar TEXT NOT NULL DEFAULT '',
    role_id INTEGER NOT NULL DEFAULT 1,
    status INTEGER NOT NULL DEFAULT 1,
    last_login_time INTEGER NOT NULL DEFAULT 0,
    last_login_ip TEXT NOT NULL DEFAULT '',
    login_count INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_users_status ON yikai_users(status);
CREATE INDEX idx_users_role ON yikai_users(role_id);

-- -----------------------------------------------------------
-- 角色表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_roles;
CREATE TABLE yikai_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    permissions TEXT,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0
);

-- -----------------------------------------------------------
-- 栏目表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_channels;
CREATE TABLE yikai_channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL DEFAULT 'list',
    album_id INTEGER NOT NULL DEFAULT 0,
    icon TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    description TEXT,
    content TEXT,
    redirect_type TEXT NOT NULL DEFAULT 'auto',
    redirect_url TEXT NOT NULL DEFAULT '',
    link_url TEXT NOT NULL DEFAULT '',
    link_target TEXT NOT NULL DEFAULT '_self',
    seo_title TEXT NOT NULL DEFAULT '',
    seo_keywords TEXT NOT NULL DEFAULT '',
    seo_description TEXT NOT NULL DEFAULT '',
    is_nav INTEGER NOT NULL DEFAULT 1,
    is_home INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    is_system INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_channels_parent ON yikai_channels(parent_id);
CREATE INDEX idx_channels_type ON yikai_channels(type);
CREATE INDEX idx_channels_status ON yikai_channels(status);
CREATE INDEX idx_channels_sort ON yikai_channels(sort_order);

-- -----------------------------------------------------------
-- 内容表（通用）
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_contents;
CREATE TABLE yikai_contents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL DEFAULT 0,
    type TEXT NOT NULL DEFAULT 'article',
    title TEXT NOT NULL,
    subtitle TEXT NOT NULL DEFAULT '',
    slug TEXT NOT NULL DEFAULT '',
    cover TEXT NOT NULL DEFAULT '',
    images TEXT,
    summary TEXT,
    content TEXT,
    author TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT '',
    tags TEXT NOT NULL DEFAULT '',
    attachment TEXT NOT NULL DEFAULT '',
    download_count INTEGER NOT NULL DEFAULT 0,
    price REAL NOT NULL DEFAULT 0,
    specs TEXT,
    location TEXT NOT NULL DEFAULT '',
    salary TEXT NOT NULL DEFAULT '',
    requirements TEXT,
    headcount TEXT NOT NULL DEFAULT '',
    job_type TEXT NOT NULL DEFAULT '',
    education TEXT NOT NULL DEFAULT '',
    experience TEXT NOT NULL DEFAULT '',
    is_top INTEGER NOT NULL DEFAULT 0,
    is_recommend INTEGER NOT NULL DEFAULT 0,
    is_hot INTEGER NOT NULL DEFAULT 0,
    views INTEGER NOT NULL DEFAULT 0,
    likes INTEGER NOT NULL DEFAULT 0,
    seo_title TEXT NOT NULL DEFAULT '',
    seo_keywords TEXT NOT NULL DEFAULT '',
    seo_description TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL DEFAULT 1,
    publish_time INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    admin_id INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_contents_channel ON yikai_contents(channel_id);
CREATE INDEX idx_contents_type ON yikai_contents(type);
CREATE INDEX idx_contents_status ON yikai_contents(status);
CREATE INDEX idx_contents_publish ON yikai_contents(publish_time);
CREATE INDEX idx_contents_top ON yikai_contents(is_top);
CREATE INDEX idx_contents_recommend ON yikai_contents(is_recommend);
CREATE INDEX idx_contents_hot ON yikai_contents(is_hot);

-- -----------------------------------------------------------
-- 媒体库
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_media;
CREATE TABLE yikai_media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    path TEXT NOT NULL,
    url TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'image',
    ext TEXT NOT NULL DEFAULT '',
    mime TEXT NOT NULL DEFAULT '',
    size INTEGER NOT NULL DEFAULT 0,
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    md5 TEXT NOT NULL DEFAULT '',
    admin_id INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_media_type ON yikai_media(type);
CREATE INDEX idx_media_admin ON yikai_media(admin_id);
CREATE INDEX idx_media_md5 ON yikai_media(md5);

-- -----------------------------------------------------------
-- 配置表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_settings;
CREATE TABLE yikai_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    "group" TEXT NOT NULL DEFAULT 'basic',
    "key" TEXT NOT NULL UNIQUE,
    value TEXT,
    type TEXT NOT NULL DEFAULT 'text',
    name TEXT NOT NULL DEFAULT '',
    tip TEXT NOT NULL DEFAULT '',
    options TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_settings_group ON yikai_settings("group");

-- -----------------------------------------------------------
-- 表单数据
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_forms;
CREATE TABLE yikai_forms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL DEFAULT 'contact',
    name TEXT NOT NULL DEFAULT '',
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    company TEXT NOT NULL DEFAULT '',
    content TEXT,
    extra TEXT,
    ip TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL DEFAULT 0,
    follow_admin INTEGER NOT NULL DEFAULT 0,
    follow_note TEXT,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_forms_type ON yikai_forms(type);
CREATE INDEX idx_forms_status ON yikai_forms(status);
CREATE INDEX idx_forms_created ON yikai_forms(created_at);

-- -----------------------------------------------------------
-- 操作日志
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_admin_logs;
CREATE TABLE yikai_admin_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL DEFAULT 0,
    admin_name TEXT NOT NULL DEFAULT '',
    module TEXT NOT NULL DEFAULT '',
    action TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '',
    method TEXT NOT NULL DEFAULT '',
    request_data TEXT,
    ip TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_logs_admin ON yikai_admin_logs(admin_id);
CREATE INDEX idx_logs_module ON yikai_admin_logs(module);
CREATE INDEX idx_logs_created ON yikai_admin_logs(created_at);

-- -----------------------------------------------------------
-- 合作伙伴
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_links;
CREATE TABLE yikai_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    logo TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_links_status ON yikai_links(status);
CREATE INDEX idx_links_sort ON yikai_links(sort_order);

-- -----------------------------------------------------------
-- 轮播图
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_banners;
CREATE TABLE yikai_banners (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    position TEXT NOT NULL DEFAULT 'home',
    title TEXT NOT NULL DEFAULT '',
    subtitle TEXT NOT NULL DEFAULT '',
    btn1_text TEXT NOT NULL DEFAULT '',
    btn1_url TEXT NOT NULL DEFAULT '',
    btn2_text TEXT NOT NULL DEFAULT '',
    btn2_url TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL,
    image_mobile TEXT NOT NULL DEFAULT '',
    link_url TEXT NOT NULL DEFAULT '',
    link_target TEXT NOT NULL DEFAULT '_self',
    start_time INTEGER NOT NULL DEFAULT 0,
    end_time INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_banners_position ON yikai_banners(position);
CREATE INDEX idx_banners_status ON yikai_banners(status);
CREATE INDEX idx_banners_sort ON yikai_banners(sort_order);

-- -----------------------------------------------------------
-- 轮播图分组
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_banner_groups;
CREATE TABLE yikai_banner_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL,
    height_pc INTEGER NOT NULL DEFAULT 500,
    height_mobile INTEGER NOT NULL DEFAULT 250,
    autoplay_delay INTEGER NOT NULL DEFAULT 5000,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uk_banner_groups_slug ON yikai_banner_groups(slug);

INSERT INTO yikai_banner_groups VALUES (1, '首页', 'home', 650, 300, 5000, 0, 1, 0);
INSERT INTO yikai_banner_groups VALUES (2, '关于我们', 'about', 500, 250, 5000, 1, 1, 0);
INSERT INTO yikai_banner_groups VALUES (3, '产品中心', 'product', 500, 250, 5000, 2, 1, 0);
INSERT INTO yikai_banner_groups VALUES (4, '案例展示', 'case', 500, 250, 5000, 3, 1, 0);

-- -----------------------------------------------------------
-- 文章分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_article_categories;
CREATE TABLE yikai_article_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    slug TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    description TEXT,
    seo_title TEXT NOT NULL DEFAULT '',
    seo_keywords TEXT NOT NULL DEFAULT '',
    seo_description TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_article_cat_parent ON yikai_article_categories(parent_id);
CREATE INDEX idx_article_cat_status ON yikai_article_categories(status);
CREATE INDEX idx_article_cat_sort ON yikai_article_categories(sort_order);

-- -----------------------------------------------------------
-- 文章表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_articles;
CREATE TABLE yikai_articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL,
    subtitle TEXT NOT NULL DEFAULT '',
    slug TEXT NOT NULL DEFAULT '',
    cover TEXT NOT NULL DEFAULT '',
    summary TEXT,
    content TEXT,
    author TEXT NOT NULL DEFAULT '',
    source TEXT NOT NULL DEFAULT '',
    tags TEXT NOT NULL DEFAULT '',
    is_top INTEGER NOT NULL DEFAULT 0,
    is_recommend INTEGER NOT NULL DEFAULT 0,
    is_hot INTEGER NOT NULL DEFAULT 0,
    views INTEGER NOT NULL DEFAULT 0,
    likes INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    publish_time INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    admin_id INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_articles_category ON yikai_articles(category_id);
CREATE INDEX idx_articles_status ON yikai_articles(status);
CREATE INDEX idx_articles_publish ON yikai_articles(publish_time);
CREATE INDEX idx_articles_top ON yikai_articles(is_top);
CREATE INDEX idx_articles_recommend ON yikai_articles(is_recommend);

-- -----------------------------------------------------------
-- 产品分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_product_categories;
CREATE TABLE yikai_product_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    slug TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL DEFAULT '',
    description TEXT,
    seo_title TEXT NOT NULL DEFAULT '',
    seo_keywords TEXT NOT NULL DEFAULT '',
    seo_description TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL DEFAULT 1,
    is_nav INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_product_cat_parent ON yikai_product_categories(parent_id);
CREATE INDEX idx_product_cat_status ON yikai_product_categories(status);
CREATE INDEX idx_product_cat_sort ON yikai_product_categories(sort_order);

-- -----------------------------------------------------------
-- 产品表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_products;
CREATE TABLE yikai_products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL,
    subtitle TEXT NOT NULL DEFAULT '',
    slug TEXT NOT NULL DEFAULT '',
    cover TEXT NOT NULL DEFAULT '',
    images TEXT,
    summary TEXT,
    content TEXT,
    price REAL NOT NULL DEFAULT 0,
    market_price REAL NOT NULL DEFAULT 0,
    model TEXT NOT NULL DEFAULT '',
    specs TEXT,
    tags TEXT NOT NULL DEFAULT '',
    is_top INTEGER NOT NULL DEFAULT 0,
    is_recommend INTEGER NOT NULL DEFAULT 0,
    is_hot INTEGER NOT NULL DEFAULT 0,
    is_new INTEGER NOT NULL DEFAULT 0,
    views INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    admin_id INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_products_category ON yikai_products(category_id);
CREATE INDEX idx_products_status ON yikai_products(status);
CREATE INDEX idx_products_top ON yikai_products(is_top);
CREATE INDEX idx_products_recommend ON yikai_products(is_recommend);
CREATE INDEX idx_products_sort ON yikai_products(sort_order);

-- -----------------------------------------------------------
-- 相册
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_albums;
CREATE TABLE yikai_albums (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    slug TEXT NOT NULL DEFAULT '',
    cover TEXT NOT NULL DEFAULT '',
    description TEXT,
    photo_count INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_albums_category ON yikai_albums(category_id);
CREATE INDEX idx_albums_status ON yikai_albums(status);
CREATE INDEX idx_albums_sort ON yikai_albums(sort_order DESC, id DESC);

-- -----------------------------------------------------------
-- 相册图片
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_album_photos;
CREATE TABLE yikai_album_photos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    album_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    image TEXT NOT NULL,
    thumb TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_album_photos_album ON yikai_album_photos(album_id);
CREATE INDEX idx_album_photos_sort ON yikai_album_photos(sort_order DESC, id DESC);

-- -----------------------------------------------------------
-- 下载分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_download_categories;
CREATE TABLE yikai_download_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0
);

-- -----------------------------------------------------------
-- 下载管理
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_downloads;
CREATE TABLE yikai_downloads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL,
    description TEXT,
    cover TEXT NOT NULL DEFAULT '',
    file_url TEXT NOT NULL DEFAULT '',
    file_name TEXT NOT NULL DEFAULT '',
    file_size INTEGER NOT NULL DEFAULT 0,
    file_ext TEXT NOT NULL DEFAULT '',
    download_count INTEGER NOT NULL DEFAULT 0,
    is_external INTEGER NOT NULL DEFAULT 0,
    require_login INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    admin_id INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_downloads_category ON yikai_downloads(category_id);
CREATE INDEX idx_downloads_status ON yikai_downloads(status);
CREATE INDEX idx_downloads_sort ON yikai_downloads(sort_order DESC, id DESC);

-- -----------------------------------------------------------
-- 招聘管理
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_jobs;
CREATE TABLE yikai_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    cover TEXT NOT NULL DEFAULT '',
    summary TEXT,
    content TEXT,
    location TEXT NOT NULL DEFAULT '',
    salary TEXT NOT NULL DEFAULT '',
    job_type TEXT NOT NULL DEFAULT '',
    education TEXT NOT NULL DEFAULT '',
    experience TEXT NOT NULL DEFAULT '',
    headcount TEXT NOT NULL DEFAULT '',
    requirements TEXT,
    views INTEGER NOT NULL DEFAULT 0,
    is_top INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    publish_time INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0,
    admin_id INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_jobs_status ON yikai_jobs(status);
CREATE INDEX idx_jobs_top ON yikai_jobs(is_top DESC, sort_order DESC, id DESC);

-- -----------------------------------------------------------
-- 发展历程时间线
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_timelines;
CREATE TABLE yikai_timelines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL DEFAULT 0,
    day INTEGER NOT NULL DEFAULT 0,
    title TEXT NOT NULL,
    content TEXT,
    image TEXT NOT NULL DEFAULT '',
    icon TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_timelines_year ON yikai_timelines(year);
CREATE INDEX idx_timelines_status ON yikai_timelines(status);
CREATE INDEX idx_timelines_sort ON yikai_timelines(sort_order DESC, year DESC, month DESC);

-- -----------------------------------------------------------
-- 插件表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_plugins;
CREATE TABLE yikai_plugins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    status INTEGER NOT NULL DEFAULT 0,
    installed_at INTEGER NOT NULL DEFAULT 0,
    activated_at INTEGER NOT NULL DEFAULT 0
);

-- -----------------------------------------------------------
-- 前台会员表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_members;
CREATE TABLE yikai_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    email TEXT NOT NULL DEFAULT '',
    nickname TEXT NOT NULL DEFAULT '',
    avatar TEXT NOT NULL DEFAULT '',
    status INTEGER NOT NULL DEFAULT 1,
    last_login_time INTEGER NOT NULL DEFAULT 0,
    last_login_ip TEXT NOT NULL DEFAULT '',
    login_count INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX uk_members_email ON yikai_members(email);

-- -----------------------------------------------------------
-- 表单模板
-- -----------------------------------------------------------
DROP TABLE IF EXISTS yikai_form_templates;
CREATE TABLE yikai_form_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    fields TEXT,
    success_message TEXT NOT NULL DEFAULT '提交成功，感谢您的反馈！',
    status INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL DEFAULT 0
);

INSERT INTO yikai_form_templates (id, name, slug, fields, success_message, status, created_at) VALUES
(1, '联系表单', 'contact', '[{"key":"name","label":"姓名","type":"text","required":true,"placeholder":"请输入姓名"},{"key":"phone","label":"电话","type":"tel","required":true,"placeholder":"请输入电话"},{"key":"email","label":"邮箱","type":"email","required":false,"placeholder":"请输入邮箱"},{"key":"company","label":"公司","type":"text","required":false,"placeholder":"请输入公司名称"},{"key":"content","label":"留言内容","type":"textarea","required":true,"placeholder":"请输入留言内容"}]', '提交成功，感谢您的反馈！', 1, strftime('%s','now'));

PRAGMA foreign_keys = ON;

-- ============================================================
-- 初始数据
-- ============================================================

-- 默认角色
INSERT INTO yikai_roles (name, description, permissions, status, created_at) VALUES
('超级管理员', '拥有全部权限', '["*"]', 1, strftime('%s','now')),
('编辑', '内容编辑权限', '["content","media"]', 1, strftime('%s','now')),
('运营', '运营管理权限', '["content","media","form","banner","link"]', 1, strftime('%s','now'));

-- 默认配置
INSERT INTO yikai_settings ("group", "key", value, type, name, tip, sort_order) VALUES
('basic', 'site_url', '', 'text', '站点域名', '如 https://www.example.com（不含末尾斜杠）', 0),
('basic', 'site_name', 'Yikai CMS', 'text', '站点名称', '', 1),
('basic', 'site_keywords', '企业官网,CMS系统,内容管理', 'textarea', 'SEO关键词', '多个关键词用逗号分隔', 2),
('basic', 'site_description', '专业的企业内容管理系统，助力企业数字化转型', 'textarea', 'SEO描述', '', 3),
('basic', 'site_logo', '/images/logo.png', 'image', '站点Logo', '', 4),
('basic', 'site_favicon', '/favicon.ico', 'image', '站点图标', '浏览器标签页图标', 5),
('basic', 'site_icp', '', 'text', 'ICP备案号', '', 5),
('basic', 'site_police', '', 'text', '公安备案号', '', 6),
('basic', 'primary_color', '#3B82F6', 'color', '主题色', '十六进制颜色值', 7),
('basic', 'secondary_color', '#1D4ED8', 'color', '次要色', '十六进制颜色值', 8),
('basic', 'banner_height_pc', '650', 'number', '轮播图高度(PC)', '单位像素', 9),
('basic', 'banner_height_mobile', '300', 'number', '轮播图高度(移动)', '单位像素', 10),
('basic', 'product_layout', 'sidebar', 'select', '产品列表版式', '', 11),
('basic', 'show_price', '0', 'select', '显示产品价格', '前台是否显示产品价格', 12),
-- 联系方式
('contact', 'contact_cards', '[{"icon":"phone","label":"联系电话","value":"400-888-8888"},{"icon":"email","label":"电子邮箱","value":"contact@example.com"},{"icon":"location","label":"公司地址","value":"上海市浦东新区XX路XX号"}]', 'contact_cards', '联系信息卡片', '联系我们页面顶部展示的信息卡片', 0),
('contact', 'contact_phone', '400-888-8888', 'text', '联系电话', '', 1),
('contact', 'contact_email', 'contact@example.com', 'text', '联系邮箱', '', 2),
('contact', 'contact_address', '上海市浦东新区XX路XX号', 'textarea', '联系地址', '', 3),
('contact', 'contact_qrcode', '', 'image', '微信二维码', '', 4),
('contact', 'contact_map', '', 'image', '地图图片', '', 5),
('contact', 'contact_form_title', '在线留言', 'text', '表单标题', '', 10),
('contact', 'contact_form_desc', '', 'textarea', '表单描述', '显示在标题下方的说明文字', 11),
('contact', 'contact_form_fields', '[{"key":"name","label":"您的姓名","type":"text","required":true,"enabled":true},{"key":"phone","label":"联系电话","type":"tel","required":true,"enabled":true},{"key":"email","label":"电子邮箱","type":"email","required":false,"enabled":true},{"key":"company","label":"公司名称","type":"text","required":false,"enabled":true},{"key":"content","label":"留言内容","type":"textarea","required":true,"enabled":true}]', 'contact_form_fields', '表单字段', '设置表单显示的字段', 12),
('contact', 'contact_form_success', '提交成功，我们会尽快与您联系！', 'text', '提交成功提示', '', 13),
-- 首页设置
('home', 'home_about_content', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。经过多年发展，已成为行业内具有影响力的企业之一。', 'textarea', '关于我们简介', '首页关于我们区块的介绍文字', 1),
('home', 'home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 'image', '关于我们图片', '首页关于我们区块的图片', 2),
('home', 'home_about_tag_title', '专业服务', 'text', '角标标题', '图片左下角标签标题', 3),
('home', 'home_about_tag_desc', '品质 · 创新 · 共赢', 'text', '角标描述', '图片左下角标签描述', 4),
('home', 'home_stat_1_num', '10+', 'text', '统计数字1', '', 5),
('home', 'home_stat_1_text', '年行业经验', 'text', '统计文字1', '', 6),
('home', 'home_about_layout', 'text_left', 'select', '关于我们布局', '左文右图或左图右文', 6),
('home', 'home_stat_2_num', '1000+', 'text', '统计数字2', '', 7),
('home', 'home_stat_2_text', '服务客户', 'text', '统计文字2', '', 8),
('home', 'home_stat_3_num', '50+', 'text', '统计数字3', '', 9),
('home', 'home_stat_3_text', '专业团队', 'text', '统计文字3', '', 10),
('home', 'home_stat_4_num', '100%', 'text', '统计数字4', '', 11),
('home', 'home_stat_4_text', '客户满意', 'text', '统计文字4', '', 12),
('home', 'home_stat_bg', '', 'image', '统计区背景图', '数据统计横栏的背景图片', 12),
('home', 'home_advantage_desc', '专业团队，优质服务，值得信赖', 'text', '优势区块描述', '', 13),
('home', 'home_adv_1_icon', 'check-circle', 'icon', '优势1图标', '', 14),
('home', 'home_adv_1_title', '品质保证', 'text', '优势1标题', '', 14),
('home', 'home_adv_1_desc', '严格把控产品质量，确保每一件产品都符合标准', 'text', '优势1描述', '', 15),
('home', 'home_adv_2_icon', 'academic-cap', 'icon', '优势2图标', '', 16),
('home', 'home_adv_2_title', '技术领先', 'text', '优势2标题', '', 16),
('home', 'home_adv_2_desc', '持续研发创新，保持技术的领先优势', 'text', '优势2描述', '', 17),
('home', 'home_adv_3_icon', 'briefcase', 'icon', '优势3图标', '', 18),
('home', 'home_adv_3_title', '专业服务', 'text', '优势3标题', '', 18),
('home', 'home_adv_3_desc', '专业团队7x24小时技术支持服务', 'text', '优势3描述', '', 19),
('home', 'home_adv_4_icon', 'users', 'icon', '优势4图标', '', 20),
('home', 'home_adv_4_title', '合作共赢', 'text', '优势4标题', '', 20),
('home', 'home_adv_4_desc', '与客户建立长期合作关系，实现互利共赢', 'text', '优势4描述', '', 21),
('home', 'home_cta_title', '准备好开始合作了吗？', 'text', 'CTA标题', '行动号召区块标题', 22),
('home', 'home_cta_desc', '联系我们，获取专业的解决方案', 'text', 'CTA描述', '行动号召区块描述', 23),
('home', 'home_show_links', '1', 'select', '显示合作伙伴', '是否在页脚显示合作伙伴', 24),
('home', 'home_links_title', '合作伙伴', 'text', '链接区块标题', '页脚合作伙伴区块的标题', 25),
('home', 'home_testimonials', '[{"avatar":"","name":"张先生","company":"某科技有限公司","content":"非常专业的服务团队，合作非常愉快！产品质量令人满意。"},{"avatar":"","name":"李女士","company":"某贸易公司","content":"产品质量优秀，售后服务及时，值得信赖的合作伙伴。"},{"avatar":"","name":"王总","company":"某集团公司","content":"多年合作，一直保持高品质的服务水准，强烈推荐！"}]', 'home_testimonials', '客户评价', '首页客户评价区块数据', 26),
('home', 'home_testimonials_title', '客户评价', 'text', '评价区标题', '客户评价区块的标题', 27),
('home', 'home_testimonials_desc', '听听合作伙伴怎么说', 'text', '评价区描述', '客户评价区块的副标题', 28),
('home', 'home_show_banner', '1', 'select', '显示轮播图', '首页Banner轮播图区块', 30),
('home', 'home_show_about', '1', 'select', '显示关于我们', '首页关于我们简介区块', 31),
('home', 'home_show_stats', '1', 'select', '显示数据统计', '首页数据统计横栏', 32),
('home', 'home_show_channels', '1', 'select', '显示栏目区块', '产品中心、新闻资讯等首页展示栏目', 33),
('home', 'home_show_advantage', '1', 'select', '显示优势展示', '首页我们的优势区块', 34),
('home', 'home_show_cta', '1', 'select', '显示行动号召', '首页底部CTA行动号召区块', 35),
('home', 'home_blocks_config', '[{"type":"banner","enabled":true},{"type":"about","enabled":true},{"type":"stats","enabled":true},{"type":"channels","enabled":true},{"type":"testimonials","enabled":true},{"type":"advantage","enabled":true},{"type":"cta","enabled":true}]', 'home_blocks', '首页区块配置', '首页区块顺序与显示配置', 40),
-- 页头设置
('header', 'topbar_enabled', '0', 'select', '显示顶部通栏', 'Logo上方的通栏区域', 0),
('header', 'topbar_bg_color', '#f3f4f6', 'color', '通栏背景色', '顶部通栏背景颜色', 1),
('header', 'topbar_left', '', 'code', '通栏左侧内容', '支持HTML代码，如电话、公告等', 2),
('header', 'show_member_entry', '0', 'select', '显示会员入口', '导航栏显示会员登录/注册入口', 3),
('header', 'header_nav_layout', 'right', 'select', '导航布局', 'Logo右侧或Logo下方通栏', 10),
('header', 'header_sticky', '0', 'select', '固定顶部', '导航栏是否固定在页面顶部', 11),
('header', 'header_bg_color', '#ffffff', 'color', '背景颜色', '十六进制颜色值', 12),
('header', 'header_text_color', '#4b5563', 'color', '文字颜色', '十六进制颜色值', 13),
-- 页脚设置
('footer', 'footer_columns', '[{"title":"关于我们","content":"{{site_description}}","col_span":2},{"title":"联系方式","content":"{{contact_info}}","col_span":1},{"title":"关注我们","content":"{{qrcode}}","col_span":1}]', 'footer_columns', '页脚栏目', '自定义页脚各列内容', 1),
('footer', 'footer_bg_color', '#1f2937', 'color', '背景颜色', '十六进制颜色值', 2),
('footer', 'footer_bg_image', '', 'image', '背景图片', '设置后覆盖背景颜色', 3),
('footer', 'footer_text_color', '#9ca3af', 'color', '文字颜色', '十六进制颜色值', 4),
('footer', 'footer_nav', '[{"title":"","links":[{"name":"隐私政策","url":"/privacy.html"},{"name":"服务条款","url":"/terms.html"}]}]', 'footer_nav', '页脚导航', '版权栏上方的导航链接分组', 5),
-- 代码注入
('code', 'custom_head_code', '', 'code', 'Head 代码', '插入到 </head> 前的代码', 1),
('code', 'custom_body_code', '', 'code', 'Body 代码', '插入到 </body> 前的代码', 2),
-- 邮件设置
('email', 'smtp_host', '', 'text', 'SMTP服务器', '如：smtp.qq.com', 1),
('email', 'smtp_port', '465', 'text', 'SMTP端口', 'SSL常用465，TLS常用587', 2),
('email', 'smtp_secure', 'ssl', 'text', '加密方式', 'ssl/tls/空', 3),
('email', 'smtp_user', '', 'text', 'SMTP用户名', '通常是完整邮箱地址', 4),
('email', 'smtp_pass', '', 'text', 'SMTP密码', 'QQ邮箱需使用授权码', 5),
('email', 'mail_from', '', 'text', '发件人邮箱', '留空则使用SMTP用户名', 6),
('email', 'mail_from_name', '', 'text', '发件人名称', '留空使用站点名称', 7),
('email', 'mail_admin', '', 'text', '管理员邮箱', '接收表单提交通知', 8),
('email', 'mail_notify_form', '0', 'text', '表单提交通知', '1开启/0关闭', 9),
-- 会员设置
('member', 'allow_member_register', '0', 'switch', '允许会员注册', '是否允许前台会员注册', 1),
('member', 'download_require_login', '0', 'switch', '下载需要登录', '下载文件是否需要会员登录', 2);

-- ============================================================
-- 初始栏目（标准企业站）
-- ============================================================

INSERT INTO yikai_channels (id, parent_id, name, slug, type, album_id, description, is_nav, is_home, status, is_system, sort_order, created_at) VALUES
-- 关于我们
(1, 0, '关于我们', 'about', 'page', 0, '了解我们的企业文化与发展历程', 1, 0, 1, 1, 1, strftime('%s','now')),
(2, 1, '公司简介', 'company', 'page', 0, '公司基本情况介绍', 1, 0, 1, 1, 1, strftime('%s','now')),
(3, 1, '企业文化', 'culture', 'page', 0, '企业核心价值观与文化理念', 1, 0, 1, 1, 2, strftime('%s','now')),
(4, 1, '发展历程', 'history', 'page', 0, '企业发展的重要里程碑', 1, 0, 1, 1, 3, strftime('%s','now')),
(23, 1, '荣誉资质', 'honor', 'album', 7, '企业获得的荣誉与资质认证', 1, 0, 1, 1, 4, strftime('%s','now')),
(28, 1, '组织架构', 'organization', 'page', 0, '公司组织架构', 1, 0, 1, 1, 5, strftime('%s','now')),

-- 产品中心
(6, 0, '产品中心', 'product', 'product', 0, '我们的产品与服务', 1, 1, 1, 1, 2, strftime('%s','now')),

-- 解决方案
(9, 0, '解决方案', 'solution', 'case', 0, '行业解决方案与成功案例', 1, 1, 1, 1, 3, strftime('%s','now')),
(10, 9, '行业方案', 'industry', 'case', 0, '针对不同行业的解决方案', 1, 0, 1, 1, 1, strftime('%s','now')),
(11, 9, '成功案例', 'cases', 'case', 0, '客户成功案例展示', 1, 0, 1, 1, 2, strftime('%s','now')),

-- 新闻资讯
(12, 0, '新闻资讯', 'news', 'list', 0, '最新动态与行业资讯', 1, 1, 1, 1, 4, strftime('%s','now')),
(13, 12, '公司新闻', 'company-news', 'list', 0, '公司最新动态', 1, 0, 1, 1, 1, strftime('%s','now')),
(14, 12, '行业动态', 'industry-news', 'list', 0, '行业最新资讯', 1, 0, 1, 1, 2, strftime('%s','now')),

-- 服务支持
(15, 0, '服务支持', 'service', 'page', 0, '专业的服务与技术支持', 1, 0, 1, 1, 5, strftime('%s','now')),
(16, 15, '服务流程', 'process', 'page', 0, '标准化服务流程', 1, 0, 1, 1, 1, strftime('%s','now')),
(17, 15, '常见问题', 'faq', 'list', 0, '常见问题解答', 1, 0, 1, 1, 2, strftime('%s','now')),
(18, 15, '下载中心', 'download', 'download', 0, '资料与软件下载', 1, 0, 1, 1, 3, strftime('%s','now')),

-- 人才招聘
(19, 0, '人才招聘', 'job', 'job', 0, '加入我们，共创未来', 1, 0, 1, 1, 6, strftime('%s','now')),

-- 联系我们
(20, 0, '联系我们', 'contact', 'page', 0, '联系方式与在线留言', 1, 0, 1, 1, 7, strftime('%s','now')),

-- 隐私政策
(29, 0, '隐私政策', 'privacy', 'page', 0, '网站隐私政策', 0, 0, 1, 1, 98, strftime('%s','now')),

-- 服务条款
(30, 0, '服务条款', 'terms', 'page', 0, '网站服务条款', 0, 0, 1, 1, 99, strftime('%s','now'));

-- 设置关于我们页面内容
UPDATE yikai_channels SET content = '<h2>关于我们</h2>
<p>我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。公司成立于2010年，总部位于上海，是一家集研发、生产、销售、服务于一体的高新技术企业。</p>

<h3>企业愿景</h3>
<p>成为行业领先的数字化解决方案提供商，用科技创造价值，助力企业实现智能化升级。</p>

<h3>核心价值观</h3>
<ul>
<li><strong>创新</strong> - 持续创新，追求卓越</li>
<li><strong>务实</strong> - 脚踏实地，专注品质</li>
<li><strong>共赢</strong> - 合作共赢，共创未来</li>
</ul>

<p>欢迎通过左侧菜单了解更多关于我们的信息。</p>' WHERE id = 1;

-- ============================================================
-- 初始文章分类（对应新闻栏目）
-- ============================================================

INSERT INTO yikai_article_categories (id, parent_id, name, slug, description, status, sort_order, created_at) VALUES
(1, 4, '公司新闻', 'company-news', '公司最新动态和重要公告', 1, 1, strftime('%s', 'now')),
(2, 4, '行业动态', 'industry-news', '行业最新资讯和趋势分析', 1, 2, strftime('%s', 'now')),
(3, 0, '技术分享', 'tech-share', '技术文章和经验分享', 1, 3, strftime('%s', 'now')),
(4, 0, '新闻资讯', 'news', '新闻资讯栏目', 1, 1, strftime('%s', 'now'));

-- ============================================================
-- 初始示例内容
-- ============================================================

INSERT INTO yikai_contents (channel_id, type, title, summary, content, cover, is_top, is_recommend, is_hot, views, status, publish_time, created_at, admin_id) VALUES

-- 关于我们 - 公司简介
(2, 'article', '公司简介', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。',
'<h2>企业概况</h2>
<p>公司成立于2010年，总部位于上海，是一家集研发、生产、销售、服务于一体的高新技术企业。经过多年发展，已成为行业内具有影响力的企业之一。</p>

<h2>核心优势</h2>
<ul>
<li><strong>技术领先</strong>：拥有多项核心专利技术</li>
<li><strong>品质保证</strong>：通过ISO9001质量管理体系认证</li>
<li><strong>服务专业</strong>：7x24小时技术支持</li>
<li><strong>经验丰富</strong>：服务超过1000家企业客户</li>
</ul>

<h2>发展愿景</h2>
<p>以技术创新为驱动，以客户需求为导向，致力于成为行业领先的解决方案提供商。</p>',
'', 1, 1, 0, 100, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 关于我们 - 企业文化
(3, 'article', '企业文化', '创新、务实、共赢是我们的核心价值观。',
'<h2>使命</h2>
<p>用科技创造价值，为客户提供卓越的产品与服务。</p>

<h2>愿景</h2>
<p>成为行业最受尊敬的企业，引领行业发展。</p>

<h2>核心价值观</h2>
<ul>
<li><strong>创新</strong>：持续创新，追求卓越</li>
<li><strong>务实</strong>：脚踏实地，精益求精</li>
<li><strong>共赢</strong>：合作共赢，共同发展</li>
<li><strong>诚信</strong>：诚实守信，言行一致</li>
</ul>',
'', 0, 0, 0, 50, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 产品中心 - 示例产品1
(7, 'product', '智能管理系统 Pro', '新一代企业智能管理系统，助力企业数字化升级',
'<h2>产品介绍</h2>
<p>智能管理系统 Pro 是我们推出的新一代企业管理解决方案，集成了先进的人工智能技术，帮助企业实现智能化运营。</p>

<h2>核心功能</h2>
<ul>
<li>智能数据分析</li>
<li>自动化流程管理</li>
<li>多端协同办公</li>
<li>实时监控预警</li>
</ul>

<h2>产品优势</h2>
<p>采用云原生架构，支持弹性扩展，安全可靠，部署便捷。</p>',
'/uploads/images/product-demo.jpg', 1, 1, 1, 500, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 产品中心 - 示例产品2
(7, 'product', '数据分析平台', '强大的数据分析与可视化平台',
'<h2>产品介绍</h2>
<p>数据分析平台提供全方位的数据采集、处理、分析和可视化能力，帮助企业洞察业务本质。</p>

<h2>核心功能</h2>
<ul>
<li>多源数据接入</li>
<li>智能数据清洗</li>
<li>可视化报表</li>
<li>预测分析</li>
</ul>',
'/uploads/images/product-demo.jpg', 0, 1, 0, 300, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 解决方案 - 示例案例
(11, 'case', '某大型制造企业数字化转型项目', '帮助客户实现生产效率提升30%',
'<h2>项目背景</h2>
<p>客户是一家大型制造企业，面临生产效率低、管理成本高等问题，急需进行数字化转型。</p>

<h2>解决方案</h2>
<p>我们为客户量身定制了一套智能制造解决方案，包括：</p>
<ul>
<li>生产计划智能排程</li>
<li>设备状态实时监控</li>
<li>质量追溯系统</li>
<li>供应链协同平台</li>
</ul>

<h2>实施效果</h2>
<ul>
<li>生产效率提升 30%</li>
<li>运营成本降低 20%</li>
<li>产品良率提高 15%</li>
</ul>',
'/images/case-demo.jpg', 1, 1, 0, 200, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 新闻资讯 - 公司新闻
(13, 'article', '公司荣获"年度最佳科技创新奖"', '在刚刚结束的行业年度评选中，我公司凭借出色的技术创新能力荣获殊荣。',
'<p>在日前举办的2024年度行业颁奖典礼上，我公司凭借在技术创新领域的突出表现，荣获"年度最佳科技创新奖"。</p>

<p>此次获奖是对我们多年来坚持技术创新的肯定。公司始终将技术研发作为核心竞争力，每年投入大量资源进行产品研发和技术升级。</p>

<p>公司CEO表示："这个奖项是对全体员工努力的认可，我们将继续保持创新精神，为客户创造更大价值。"</p>',
'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 1, 1, 1, 800, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 新闻资讯 - 行业动态
(14, 'article', '数字化转型趋势报告发布', '最新行业研究报告显示，企业数字化转型已成为必然趋势。',
'<p>近日，某权威研究机构发布了《2024年企业数字化转型趋势报告》，报告指出：</p>

<ul>
<li>超过80%的企业已启动数字化转型</li>
<li>云计算、大数据、AI成为转型三大核心技术</li>
<li>预计到2025年，数字化投入将增长50%</li>
</ul>

<p>报告建议企业应尽早规划数字化战略，选择合适的技术合作伙伴，稳步推进转型进程。</p>',
'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 0, 0, 0, 300, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 服务支持 - 服务流程
(16, 'article', '服务流程', '标准化的服务流程，确保服务质量',
'<h2>服务流程</h2>

<h3>1. 需求沟通</h3>
<p>深入了解客户需求，进行详细的需求分析和评估。</p>

<h3>2. 方案设计</h3>
<p>根据需求定制专属解决方案，提供详细的实施计划。</p>

<h3>3. 项目实施</h3>
<p>专业团队负责项目部署和实施，确保按时交付。</p>

<h3>4. 培训交付</h3>
<p>提供系统培训和使用指导，确保顺利上线。</p>

<h3>5. 售后服务</h3>
<p>7x24小时技术支持，定期回访和系统优化。</p>',
'', 1, 0, 0, 150, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 服务支持 - 常见问题
(17, 'article', '如何开始使用我们的产品？', '新用户快速入门指南',
'<h2>快速入门步骤</h2>

<ol>
<li><strong>注册账号</strong>：访问官网，点击注册按钮完成账号注册</li>
<li><strong>选择套餐</strong>：根据需求选择合适的产品套餐</li>
<li><strong>系统配置</strong>：按照向导完成基础配置</li>
<li><strong>开始使用</strong>：登录系统即可开始使用</li>
</ol>

<p>如有任何问题，请联系我们的客服团队。</p>',
'', 0, 0, 0, 200, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 隐私政策
(29, 'article', '隐私政策', '本隐私政策说明了我们如何收集、使用和保护您的个人信息。',
'<h2>隐私政策</h2>
<p>本隐私政策适用于本网站（以下简称"我们"）提供的所有服务。我们深知个人信息对您的重要性，并会尽全力保护您的个人信息安全。请您在使用我们的服务前，仔细阅读本隐私政策。</p>

<h3>一、信息收集</h3>
<p>我们可能会收集以下类型的信息：</p>
<ul>
<li><strong>个人身份信息</strong>：如姓名、电子邮件地址、电话号码等，仅在您主动提交表单或注册时收集。</li>
<li><strong>浏览信息</strong>：如IP地址、浏览器类型、访问时间、浏览页面等，通过日志自动记录。</li>
<li><strong>Cookie信息</strong>：用于改善用户体验和网站功能。</li>
</ul>

<h3>二、信息使用</h3>
<p>我们收集的信息将用于以下目的：</p>
<ul>
<li>提供、维护和改进我们的服务</li>
<li>处理您的咨询和请求</li>
<li>发送服务相关的通知</li>
<li>防止欺诈和滥用行为</li>
</ul>

<h3>三、信息保护</h3>
<p>我们采取合理的技术和管理措施保护您的个人信息安全，防止未经授权的访问、披露、修改或销毁。</p>

<h3>四、信息共享</h3>
<p>我们不会向第三方出售、出租或以其他方式分享您的个人信息，除非：</p>
<ul>
<li>获得您的明确同意</li>
<li>法律法规要求</li>
<li>保护我们的合法权益</li>
</ul>

<h3>五、Cookie使用</h3>
<p>本网站使用Cookie来改善您的浏览体验。您可以通过浏览器设置管理Cookie偏好。禁用Cookie可能会影响网站部分功能的正常使用。</p>

<h3>六、政策更新</h3>
<p>我们可能会不时更新本隐私政策。更新后的政策将在本页面发布，建议您定期查阅。</p>

<h3>七、联系我们</h3>
<p>如果您对本隐私政策有任何疑问，请通过网站联系方式与我们取得联系。</p>',
'', 0, 0, 0, 0, 1, strftime('%s','now'), strftime('%s','now'), 1),

-- 服务条款
(30, 'article', '服务条款', '使用本网站前请仔细阅读以下服务条款。',
'<h2>服务条款</h2>
<p>欢迎访问本网站。请在使用本网站服务之前，仔细阅读以下条款。使用本网站即表示您同意遵守以下条款和条件。</p>

<h3>一、服务说明</h3>
<p>本网站提供的信息和服务仅供参考。我们保留随时修改、暂停或终止服务的权利，恕不另行通知。</p>

<h3>二、用户行为规范</h3>
<p>在使用本网站时，您同意：</p>
<ul>
<li>不得利用本网站从事违法活动</li>
<li>不得上传或传播含有病毒、恶意代码的内容</li>
<li>不得侵犯他人的知识产权或其他合法权益</li>
<li>不得干扰或破坏网站的正常运行</li>
<li>遵守中华人民共和国相关法律法规</li>
</ul>

<h3>三、知识产权</h3>
<p>本网站的所有内容，包括但不限于文字、图片、音频、视频、软件、程序、版面设计等，均受著作权法和其他知识产权法律法规保护。未经我们书面许可，任何人不得复制、转载、修改或用于商业用途。</p>

<h3>四、免责声明</h3>
<ul>
<li>本网站内容仅供一般性参考，不构成任何建议或承诺。</li>
<li>我们不保证网站内容的准确性、完整性和及时性。</li>
<li>对于因使用本网站而产生的任何直接或间接损失，我们不承担责任。</li>
<li>本网站可能包含第三方网站的链接，我们对这些网站的内容不承担任何责任。</li>
</ul>

<h3>五、账号管理</h3>
<p>如果您在本网站注册了账号，您有责任妥善保管您的账号信息和密码。因账号信息泄露导致的任何损失由您自行承担。</p>

<h3>六、隐私保护</h3>
<p>我们重视您的隐私保护，具体请参阅我们的<a href="/privacy.html">隐私政策</a>。</p>

<h3>七、条款修改</h3>
<p>我们保留随时修改本服务条款的权利。修改后的条款将在本页面发布，继续使用本网站即表示您接受修改后的条款。</p>

<h3>八、适用法律</h3>
<p>本服务条款受中华人民共和国法律管辖。因本条款引起的任何争议，双方应友好协商解决。</p>

<h3>九、联系方式</h3>
<p>如果您对本服务条款有任何疑问，请通过网站联系方式与我们取得联系。</p>',
'', 0, 0, 0, 0, 1, strftime('%s','now'), strftime('%s','now'), 1);

-- ============================================================
-- 初始文章（对应文章分类）
-- ============================================================

INSERT INTO yikai_articles (category_id, title, slug, summary, content, cover, author, is_top, is_recommend, status, views, publish_time, created_at, updated_at, admin_id) VALUES
(1, '公司荣获"年度最佳科技创新奖"', 'company-award-2024', '在刚刚结束的行业年度评选中，我公司凭借出色的技术创新能力荣获殊荣。', '<p>在日前举办的2024年度行业颁奖典礼上，我公司凭借在技术创新领域的突出表现，荣获"年度最佳科技创新奖"。</p><p>此次获奖是对公司多年来坚持自主创新的肯定。</p><p>公司CEO表示："这个奖项是对全体员工努力的认可，我们将继续保持创新精神，为客户创造更大价值。"</p>', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', '管理员', 1, 1, 1, 128, strftime('%s','now'), strftime('%s','now'), strftime('%s','now'), 1),
(1, '公司与战略合作伙伴签署合作协议', 'partnership-agreement', '公司与多家行业领先企业达成战略合作，共同推进行业发展。', '<p>近日，公司与多家行业领先企业签署战略合作协议，将在技术研发、市场拓展等领域开展深度合作。</p><p>此次合作将有效整合各方资源优势，共同推进行业技术进步。</p>', '', '管理员', 0, 1, 1, 86, strftime('%s','now'), strftime('%s','now'), strftime('%s','now'), 1),
(2, '数字化转型趋势报告发布', 'digital-transformation-report', '最新行业研究报告显示，企业数字化转型已成为必然趋势。', '<p>近日，某权威研究机构发布了《2024年企业数字化转型趋势报告》。</p><p>报告指出，超过80%的企业已将数字化转型列入战略规划，预计未来三年内数字化投入将持续增长。</p>', '', '管理员', 0, 0, 1, 56, strftime('%s','now'), strftime('%s','now'), strftime('%s','now'), 1),
(3, 'PHP 8.0 新特性详解', 'php8-new-features', '详细介绍PHP 8.0版本带来的新特性和性能优化。', '<p>PHP 8.0 带来了众多新特性，包括JIT编译器、命名参数、联合类型等。</p><h3>主要新特性</h3><ul><li>JIT 编译器 - 显著提升性能</li><li>命名参数 - 更灵活的函数调用</li><li>联合类型 - 更强的类型系统</li></ul>', '', '技术部', 0, 0, 1, 234, strftime('%s','now'), strftime('%s','now'), strftime('%s','now'), 1);

-- ============================================================
-- 初始轮播图
-- ============================================================

INSERT INTO yikai_banners (position, title, subtitle, btn1_text, btn1_url, btn2_text, btn2_url, image, link_url, link_target, status, sort_order, created_at) VALUES
('home', '数字化转型解决方案', '助力企业实现智能化升级', '关于我们', '/about.html', '下载中心', '/download.html', 'https://picsum.photos/1920/600?random=1', '/about.html', '_self', 1, 1, strftime('%s','now')),
('home', '专业的技术服务团队', '7x24小时为您保驾护航', '查看详情', '/about.html', '', '', 'https://picsum.photos/1920/600?random=2', '/about.html', '_self', 1, 2, strftime('%s','now')),
('home', '创新引领未来', '持续创新，追求卓越', '', '', '', '', 'https://picsum.photos/1920/600?random=3', '/about.html', '_self', 1, 3, strftime('%s','now'));

-- ============================================================
-- 初始合作伙伴
-- ============================================================

INSERT INTO yikai_links (name, url, description, status, sort_order, created_at) VALUES
('易开网-域名注册', 'https://www.yikai.cn', '域名注册服务', 1, 1, strftime('%s','now'));

-- ============================================================
-- 初始产品分类
-- ============================================================

INSERT INTO yikai_product_categories (id, parent_id, name, slug, image, description, status, sort_order, created_at) VALUES
(1, 0, '智能设备', 'smart-device', '', '智能硬件设备产品系列', 1, 1, strftime('%s','now')),
(2, 0, '软件服务', 'software', '', '企业软件与云服务产品', 1, 2, strftime('%s','now')),
(3, 1, '传感器模块', 'sensor-module', '', '各类工业传感器产品', 1, 1, strftime('%s','now')),
(4, 1, '控制终端', 'control-terminal', '', '工业控制终端设备', 1, 2, strftime('%s','now'));

-- ============================================================
-- 初始产品
-- ============================================================

INSERT INTO yikai_products (id, category_id, title, subtitle, cover, summary, content, price, market_price, model, specs, tags, is_top, is_recommend, is_hot, is_new, views, status, sort_order, created_at, updated_at, admin_id) VALUES
(1, 1, '智能物联网网关', '工业级高性能网关设备', 'https://picsum.photos/800/600?random=10',
'新一代智能物联网网关，支持多协议接入，具备边缘计算能力，适用于工业物联网场景。',
'<h2>产品介绍</h2>
<p>本产品是一款高性能工业级物联网网关，专为工业4.0和智能制造场景设计。支持多种工业协议，具备强大的边缘计算能力。</p>

<h2>核心功能</h2>
<ul>
<li>支持Modbus、OPC UA、MQTT等多种协议</li>
<li>边缘计算，本地数据处理</li>
<li>远程配置与监控</li>
<li>工业级防护，IP65防护等级</li>
</ul>

<h2>技术参数</h2>
<ul>
<li>处理器：ARM Cortex-A53 四核</li>
<li>内存：4GB DDR4</li>
<li>存储：32GB eMMC</li>
<li>工作温度：-40°C ~ 85°C</li>
</ul>',
2999.00, 3599.00, 'IOT-GW-100', '处理器:ARM Cortex-A53
内存:4GB DDR4
存储:32GB eMMC
接口:4xRS485,2xCAN,2xETH', '物联网,网关,工业级', 1, 1, 1, 1, 520, 1, 1, strftime('%s','now'), strftime('%s','now'), 1),

(2, 2, '企业管理云平台', '一站式企业数字化解决方案', 'https://picsum.photos/800/600?random=11',
'集成ERP、CRM、OA等功能的企业管理云平台，助力企业数字化转型。',
'<h2>平台介绍</h2>
<p>企业管理云平台是一款面向中小企业的一站式数字化解决方案，整合了企业资源计划(ERP)、客户关系管理(CRM)、办公自动化(OA)等核心功能。</p>

<h2>功能模块</h2>
<ul>
<li><strong>ERP模块</strong>：采购、库存、生产、财务一体化管理</li>
<li><strong>CRM模块</strong>：客户管理、销售跟进、数据分析</li>
<li><strong>OA模块</strong>：流程审批、日程管理、协同办公</li>
<li><strong>BI报表</strong>：可视化数据分析与决策支持</li>
</ul>

<h2>服务优势</h2>
<ul>
<li>SaaS部署，无需服务器</li>
<li>按需付费，灵活扩展</li>
<li>7x24小时技术支持</li>
<li>数据安全，多重备份</li>
</ul>',
0.00, 0.00, 'EMS-CLOUD-V3', '部署方式:SaaS云端
用户数:不限
存储空间:100GB起
技术支持:7x24小时', '企业管理,云平台,SaaS', 0, 1, 0, 1, 380, 1, 2, strftime('%s','now'), strftime('%s','now'), 1),

(3, 3, '温湿度传感器 TH-200', '高精度工业级温湿度传感器', 'https://picsum.photos/800/600?random=12',
'采用瑞士进口芯片，精度±0.1°C，适用于工业环境监测、仓储管理、智慧农业等场景。',
NULL, 0.00, 0.00, 'TH-200', NULL, '', 0, 0, 0, 1, 4, 1, 0, strftime('%s','now'), strftime('%s','now'), 1),

(4, 3, '光照传感器 LS-100', '宽范围光照强度传感器', 'https://picsum.photos/800/600?random=13',
'检测范围0-200000Lux，支持RS485/Modbus通信，广泛应用于智慧农业、气象监测。',
NULL, 0.00, 0.00, 'LS-100', NULL, '', 0, 0, 1, 0, 4, 1, 0, strftime('%s','now'), strftime('%s','now'), 1),

(5, 4, '工业边缘控制器 EC-500', '高性能边缘计算控制终端', 'https://picsum.photos/800/600?random=14',
'搭载ARM Cortex-A72处理器，支持多种工业协议，可本地化运行AI推理模型。',
NULL, 0.00, 0.00, 'EC-500', NULL, '', 0, 1, 1, 1, 0, 1, 0, strftime('%s','now'), strftime('%s','now'), 1),

(6, 4, '智能网关控制器 GC-300', '多协议融合网关控制器', 'https://picsum.photos/800/600?random=15',
'同时支持Wi-Fi/Zigbee/LoRa/4G通信，内置边缘计算能力，一站式设备管理。',
NULL, 0.00, 0.00, 'GC-300', NULL, '', 0, 1, 0, 0, 0, 1, 0, strftime('%s','now'), strftime('%s','now'), 1);

-- ============================================================
-- 初始相册
-- ============================================================

INSERT INTO yikai_albums (id, category_id, name, slug, cover, description, photo_count, sort_order, status, created_at) VALUES
(7, 0, '荣誉资质', 'honor', '/images/cert-1.jpg', '企业荣誉证书与资质认证', 1, 100, 1, strftime('%s','now')),
(8, 0, '团队风采', 'team', '', '团队活动与员工风采展示', 0, 90, 1, strftime('%s','now')),
(9, 0, '企业环境', 'environment', '', '公司办公环境与生产车间', 0, 80, 1, strftime('%s','now'));

INSERT INTO yikai_album_photos (album_id, title, image, thumb, description, sort_order, status, created_at) VALUES
(7, '授权证书', '/images/cert-1.jpg', '/images/cert-1.jpg', '企业授权认证证书', 1, 1, strftime('%s','now'));

-- ============================================================
-- 初始下载分类
-- ============================================================

INSERT INTO yikai_download_categories (id, name, description, sort_order, status, created_at) VALUES
(1, '产品手册', '产品使用手册和说明文档', 1, 1, strftime('%s','now')),
(2, '软件下载', '软件安装包和工具', 2, 1, strftime('%s','now')),
(3, '技术文档', '技术规范和开发文档', 3, 1, strftime('%s','now'));

-- ============================================================
-- 初始下载
-- ============================================================

INSERT INTO yikai_downloads (id, category_id, title, description, file_ext, download_count, sort_order, status, created_at) VALUES
(1, 1, '产品使用手册 V2.0', '最新版产品使用说明书，包含安装、配置和常见问题解答。', 'pdf', 128, 100, 1, strftime('%s','now')),
(2, 2, '客户端软件 V3.5.1', '适用于Windows系统的客户端软件安装包。', 'zip', 256, 90, 1, strftime('%s','now')),
(3, 3, 'API接口文档', '完整的API接口说明文档，供开发者参考。', 'pdf', 89, 80, 1, strftime('%s','now'));

-- ============================================================
-- 初始招聘
-- ============================================================

INSERT INTO yikai_jobs (id, title, location, salary, job_type, education, experience, headcount, requirements, is_top, sort_order, status, publish_time, created_at) VALUES
(1, 'PHP高级开发工程师', '上海', '15k-25k', '全职', '本科', '3-5年', '2人', '1. 精通PHP语言，熟练使用Laravel/ThinkPHP等主流框架
2. 熟悉MySQL数据库设计与优化
3. 有大型项目开发经验者优先', 1, 100, 1, strftime('%s','now'), strftime('%s','now')),
(2, '前端开发工程师', '上海', '10k-18k', '全职', '本科', '1-3年', '3人', '1. 精通HTML5/CSS3/JavaScript
2. 熟练使用Vue/React等前端框架
3. 有良好的代码规范和团队协作能力', 0, 90, 1, strftime('%s','now'), strftime('%s','now'));

-- ============================================================
-- 初始时间线
-- ============================================================

INSERT INTO yikai_timelines (id, year, month, day, title, content, icon, color, sort_order, status, created_at) VALUES
(1, 2024, 6, 0, '品牌升级', '完成品牌全面升级，推出全新视觉形象系统，开启发展新篇章。', 'rocket', 'blue', 100, 1, strftime('%s','now')),
(2, 2024, 1, 0, '荣获殊荣', '荣获行业"最具创新力企业"称号，产品获得多项专利认证。', 'award', 'yellow', 95, 1, strftime('%s','now')),
(3, 2023, 8, 0, '战略合作', '与多家知名企业达成战略合作，业务版图进一步扩大。', 'handshake', 'green', 90, 1, strftime('%s','now')),
(4, 2023, 3, 0, '新品发布', '成功发布新一代核心产品，技术领先行业水平。', 'box', 'purple', 85, 1, strftime('%s','now')),
(5, 2022, 10, 0, '团队扩展', '团队规模突破100人，建立完善的研发和服务体系。', 'users', 'cyan', 80, 1, strftime('%s','now')),
(6, 2022, 5, 0, '获得融资', '完成A轮融资，获得知名投资机构数千万投资。', 'trending-up', 'red', 75, 1, strftime('%s','now')),
(7, 2021, 0, 0, '业务拓展', '业务范围扩展至全国主要城市，服务客户超过500家。', 'map', 'indigo', 70, 1, strftime('%s','now')),
(8, 2020, 0, 0, '公司成立', '公司正式成立，开始为客户提供专业服务。', 'flag', 'primary', 60, 1, strftime('%s','now'));

-- ============================================================
-- 初始插件
-- ============================================================

INSERT INTO yikai_plugins (slug, status, installed_at, activated_at) VALUES
('search-replace', 1, strftime('%s','now'), strftime('%s','now')),
('db-backup', 1, strftime('%s','now'), strftime('%s','now')),
('back-to-top', 1, strftime('%s','now'), strftime('%s','now'));
