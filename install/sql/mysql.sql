-- ============================================================
-- Yikai CMS - MySQL 数据库结构
-- PHP 8.0+, MySQL 5.7+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 用户表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_users`;
CREATE TABLE `yikai_users` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL COMMENT '用户名',
    `password` varchar(255) NOT NULL COMMENT '密码',
    `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
    `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
    `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
    `role_id` int(11) UNSIGNED NOT NULL DEFAULT 1 COMMENT '角色ID',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0禁用 1启用',
    `last_login_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后登录时间',
    `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
    `login_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '登录次数',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    KEY `idx_status` (`status`),
    KEY `idx_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- -----------------------------------------------------------
-- 角色表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_roles`;
CREATE TABLE `yikai_roles` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL COMMENT '角色名称',
    `description` varchar(255) NOT NULL DEFAULT '' COMMENT '角色描述',
    `permissions` text COMMENT '权限JSON',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色表';

-- -----------------------------------------------------------
-- 栏目表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_channels`;
CREATE TABLE `yikai_channels` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父栏目ID',
    `name` varchar(100) NOT NULL COMMENT '栏目名称',
    `slug` varchar(100) NOT NULL COMMENT 'URL别名',
    `type` varchar(20) NOT NULL DEFAULT 'list' COMMENT '类型：list/page/link/product/case/download/job/album',
    `album_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联相册ID',
    `icon` varchar(100) NOT NULL DEFAULT '' COMMENT '图标',
    `image` varchar(255) NOT NULL DEFAULT '' COMMENT '栏目图片',
    `description` text COMMENT '栏目描述',
    `content` longtext COMMENT '单页内容',
    `redirect_type` varchar(10) NOT NULL DEFAULT 'auto' COMMENT '跳转方式：auto自动跳转子栏目/none不跳转/url指定地址',
    `redirect_url` varchar(255) NOT NULL DEFAULT '' COMMENT '跳转地址(redirect_type=url时使用)',
    `link_url` varchar(255) NOT NULL DEFAULT '' COMMENT '外链地址',
    `link_target` varchar(20) NOT NULL DEFAULT '_self' COMMENT '打开方式',
    `seo_title` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO标题',
    `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
    `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO描述',
    `is_nav` tinyint(1) NOT NULL DEFAULT 1 COMMENT '显示在导航',
    `is_home` tinyint(1) NOT NULL DEFAULT 0 COMMENT '显示在首页',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
    `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT '系统预设：1是 0否',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`),
    KEY `idx_parent` (`parent_id`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='栏目表';

-- -----------------------------------------------------------
-- 内容表（通用）
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_contents`;
CREATE TABLE `yikai_contents` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '栏目ID',
    `type` varchar(20) NOT NULL DEFAULT 'article' COMMENT '类型：article/product/case/download/job',
    `title` varchar(255) NOT NULL COMMENT '标题',
    `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
    `slug` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL别名',
    `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
    `images` text COMMENT '图片组JSON',
    `summary` text COMMENT '摘要',
    `content` longtext COMMENT '内容',
    `author` varchar(50) NOT NULL DEFAULT '' COMMENT '作者',
    `source` varchar(100) NOT NULL DEFAULT '' COMMENT '来源',
    `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签',
    `attachment` varchar(255) NOT NULL DEFAULT '' COMMENT '附件',
    `download_count` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载次数',
    -- 产品特有
    `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '价格',
    `specs` text COMMENT '规格JSON',
    -- 招聘特有
    `location` varchar(100) NOT NULL DEFAULT '' COMMENT '工作地点',
    `salary` varchar(50) NOT NULL DEFAULT '' COMMENT '薪资范围',
    `requirements` text COMMENT '任职要求',
    `headcount` varchar(20) NOT NULL DEFAULT '' COMMENT '招聘人数',
    `job_type` varchar(20) NOT NULL DEFAULT '' COMMENT '工作性质',
    `education` varchar(50) NOT NULL DEFAULT '' COMMENT '学历要求',
    `experience` varchar(50) NOT NULL DEFAULT '' COMMENT '经验要求',
    -- 通用字段
    `is_top` tinyint(1) NOT NULL DEFAULT 0 COMMENT '置顶',
    `is_recommend` tinyint(1) NOT NULL DEFAULT 0 COMMENT '推荐',
    `is_hot` tinyint(1) NOT NULL DEFAULT 0 COMMENT '热门',
    `views` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
    `likes` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数',
    `seo_title` varchar(255) NOT NULL DEFAULT '',
    `seo_keywords` varchar(255) NOT NULL DEFAULT '',
    `seo_description` varchar(500) NOT NULL DEFAULT '',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0草稿 1发布 2归档',
    `publish_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '发布时间',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
    PRIMARY KEY (`id`),
    KEY `idx_channel` (`channel_id`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`),
    KEY `idx_publish` (`publish_time`),
    KEY `idx_top` (`is_top`),
    KEY `idx_recommend` (`is_recommend`),
    KEY `idx_hot` (`is_hot`),
    FULLTEXT KEY `ft_search` (`title`, `summary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容表';

-- -----------------------------------------------------------
-- 媒体库
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_media`;
CREATE TABLE `yikai_media` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL COMMENT '文件名',
    `path` varchar(255) NOT NULL COMMENT '存储路径',
    `url` varchar(255) NOT NULL COMMENT '访问URL',
    `type` varchar(20) NOT NULL DEFAULT 'image' COMMENT '类型：image/file/video',
    `ext` varchar(20) NOT NULL DEFAULT '' COMMENT '扩展名',
    `mime` varchar(100) NOT NULL DEFAULT '' COMMENT 'MIME类型',
    `size` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小',
    `width` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '图片宽度',
    `height` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '图片高度',
    `md5` varchar(32) NOT NULL DEFAULT '' COMMENT 'MD5',
    `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_admin` (`admin_id`),
    KEY `idx_md5` (`md5`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='媒体库';

-- -----------------------------------------------------------
-- 配置表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_settings`;
CREATE TABLE `yikai_settings` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `group` varchar(50) NOT NULL DEFAULT 'basic' COMMENT '分组',
    `key` varchar(100) NOT NULL COMMENT '键名',
    `value` text COMMENT '值',
    `type` varchar(20) NOT NULL DEFAULT 'text' COMMENT '类型：text/textarea/number/select/image/editor',
    `name` varchar(100) NOT NULL DEFAULT '' COMMENT '显示名称',
    `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '提示',
    `options` text COMMENT '选项JSON',
    `sort_order` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`),
    KEY `idx_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置表';

-- -----------------------------------------------------------
-- 表单数据
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_forms`;
CREATE TABLE `yikai_forms` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` varchar(20) NOT NULL DEFAULT 'contact' COMMENT '类型：contact/apply/custom',
    `name` varchar(50) NOT NULL DEFAULT '' COMMENT '姓名',
    `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '电话',
    `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
    `company` varchar(100) NOT NULL DEFAULT '' COMMENT '公司',
    `content` text COMMENT '内容',
    `extra` text COMMENT '额外字段JSON',
    `ip` varchar(45) NOT NULL DEFAULT '',
    `user_agent` varchar(500) NOT NULL DEFAULT '',
    `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0待处理 1已处理 2无效',
    `follow_admin` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '跟进人',
    `follow_note` text COMMENT '跟进备注',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_type` (`type`),
    KEY `idx_status` (`status`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表单数据';

-- -----------------------------------------------------------
-- 操作日志
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_admin_logs`;
CREATE TABLE `yikai_admin_logs` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `admin_name` varchar(50) NOT NULL DEFAULT '',
    `module` varchar(50) NOT NULL DEFAULT '' COMMENT '模块',
    `action` varchar(50) NOT NULL DEFAULT '' COMMENT '动作',
    `description` varchar(500) NOT NULL DEFAULT '' COMMENT '描述',
    `url` varchar(255) NOT NULL DEFAULT '',
    `method` varchar(10) NOT NULL DEFAULT '',
    `request_data` text,
    `ip` varchar(45) NOT NULL DEFAULT '',
    `user_agent` varchar(500) NOT NULL DEFAULT '',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_admin` (`admin_id`),
    KEY `idx_module` (`module`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

-- -----------------------------------------------------------
-- 合作伙伴
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_links`;
CREATE TABLE `yikai_links` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL COMMENT '名称',
    `url` varchar(255) NOT NULL COMMENT '链接',
    `logo` varchar(255) NOT NULL DEFAULT '' COMMENT 'Logo',
    `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='合作伙伴';

-- -----------------------------------------------------------
-- 轮播图
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_banners`;
CREATE TABLE `yikai_banners` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `position` varchar(50) NOT NULL DEFAULT 'home' COMMENT '位置',
    `title` varchar(100) NOT NULL DEFAULT '' COMMENT '标题',
    `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
    `btn1_text` varchar(50) NOT NULL DEFAULT '' COMMENT '按钮1文字',
    `btn1_url` varchar(255) NOT NULL DEFAULT '' COMMENT '按钮1链接',
    `btn2_text` varchar(50) NOT NULL DEFAULT '' COMMENT '按钮2文字',
    `btn2_url` varchar(255) NOT NULL DEFAULT '' COMMENT '按钮2链接',
    `image` varchar(255) NOT NULL COMMENT '图片',
    `image_mobile` varchar(255) NOT NULL DEFAULT '' COMMENT '移动端图片',
    `link_url` varchar(255) NOT NULL DEFAULT '' COMMENT '链接',
    `link_target` varchar(20) NOT NULL DEFAULT '_self',
    `start_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '开始时间',
    `end_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '结束时间',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_position` (`position`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮播图';

-- -----------------------------------------------------------
-- 轮播图分组
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_banner_groups`;
CREATE TABLE `yikai_banner_groups` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL COMMENT '分组名称',
    `slug` varchar(50) NOT NULL COMMENT '短码标识',
    `height_pc` smallint(5) UNSIGNED NOT NULL DEFAULT 500 COMMENT 'PC端高度',
    `height_mobile` smallint(5) UNSIGNED NOT NULL DEFAULT 250 COMMENT '移动端高度',
    `autoplay_delay` int(11) UNSIGNED NOT NULL DEFAULT 5000 COMMENT '自动播放间隔ms',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮播图分组';

INSERT INTO `yikai_banner_groups` VALUES (1, '首页', 'home', 650, 300, 5000, 0, 1, 0);
INSERT INTO `yikai_banner_groups` VALUES (2, '关于我们', 'about', 500, 250, 5000, 1, 1, 0);
INSERT INTO `yikai_banner_groups` VALUES (3, '产品中心', 'product', 500, 250, 5000, 2, 1, 0);
INSERT INTO `yikai_banner_groups` VALUES (4, '案例展示', 'case', 500, 250, 5000, 3, 1, 0);

-- -----------------------------------------------------------
-- 文章分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_article_categories`;
CREATE TABLE `yikai_article_categories` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类ID',
    `name` varchar(100) NOT NULL COMMENT '分类名称',
    `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL别名',
    `image` varchar(255) NOT NULL DEFAULT '' COMMENT '分类图片',
    `description` text COMMENT '分类描述',
    `seo_title` varchar(255) NOT NULL DEFAULT '',
    `seo_keywords` varchar(255) NOT NULL DEFAULT '',
    `seo_description` varchar(500) NOT NULL DEFAULT '',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_parent` (`parent_id`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章分类';

-- -----------------------------------------------------------
-- 文章表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_articles`;
CREATE TABLE `yikai_articles` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
    `title` varchar(255) NOT NULL COMMENT '标题',
    `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
    `slug` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL别名',
    `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
    `summary` text COMMENT '摘要',
    `content` longtext COMMENT '内容',
    `author` varchar(50) NOT NULL DEFAULT '' COMMENT '作者',
    `source` varchar(100) NOT NULL DEFAULT '' COMMENT '来源',
    `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签',
    `is_top` tinyint(1) NOT NULL DEFAULT 0 COMMENT '置顶',
    `is_recommend` tinyint(1) NOT NULL DEFAULT 0 COMMENT '推荐',
    `is_hot` tinyint(1) NOT NULL DEFAULT 0 COMMENT '热门',
    `views` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
    `likes` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '点赞数',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0草稿 1发布',
    `publish_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '发布时间',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`),
    KEY `idx_publish` (`publish_time`),
    KEY `idx_top` (`is_top`),
    KEY `idx_recommend` (`is_recommend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章表';

-- -----------------------------------------------------------
-- 产品分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_product_categories`;
CREATE TABLE `yikai_product_categories` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `parent_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父分类ID',
    `name` varchar(100) NOT NULL COMMENT '分类名称',
    `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL别名',
    `image` varchar(255) NOT NULL DEFAULT '' COMMENT '分类图片',
    `description` text COMMENT '分类描述',
    `seo_title` varchar(255) NOT NULL DEFAULT '',
    `seo_keywords` varchar(255) NOT NULL DEFAULT '',
    `seo_description` varchar(500) NOT NULL DEFAULT '',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `is_nav` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否导航显示',
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_parent` (`parent_id`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品分类';

-- -----------------------------------------------------------
-- 产品表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_products`;
CREATE TABLE `yikai_products` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID',
    `title` varchar(255) NOT NULL COMMENT '产品名称',
    `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
    `slug` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL别名',
    `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
    `images` text COMMENT '产品图片JSON',
    `summary` text COMMENT '简介',
    `content` longtext COMMENT '详情',
    `price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '价格',
    `market_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '市场价',
    `model` varchar(100) NOT NULL DEFAULT '' COMMENT '型号',
    `specs` text COMMENT '规格参数JSON',
    `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签',
    `is_top` tinyint(1) NOT NULL DEFAULT 0 COMMENT '置顶',
    `is_recommend` tinyint(1) NOT NULL DEFAULT 0 COMMENT '推荐',
    `is_hot` tinyint(1) NOT NULL DEFAULT 0 COMMENT '热门',
    `is_new` tinyint(1) NOT NULL DEFAULT 0 COMMENT '新品',
    `views` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0下架 1上架',
    `sort_order` int(11) NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`),
    KEY `idx_top` (`is_top`),
    KEY `idx_recommend` (`is_recommend`),
    KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品表';

-- -----------------------------------------------------------
-- 相册
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_albums`;
CREATE TABLE `yikai_albums` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` int(10) UNSIGNED DEFAULT 0 COMMENT '分类ID',
    `name` varchar(100) NOT NULL COMMENT '相册名称',
    `slug` varchar(100) DEFAULT '' COMMENT 'URL别名',
    `cover` varchar(500) DEFAULT '' COMMENT '封面图',
    `description` text COMMENT '相册描述',
    `photo_count` int(10) UNSIGNED DEFAULT 0 COMMENT '图片数量',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序(越大越靠前)',
    `status` tinyint(4) DEFAULT 1 COMMENT '状态：1显示 0隐藏',
    `created_at` int(10) UNSIGNED DEFAULT 0,
    `updated_at` int(10) UNSIGNED DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order` DESC, `id` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册';

-- -----------------------------------------------------------
-- 相册图片
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_album_photos`;
CREATE TABLE `yikai_album_photos` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `album_id` int(11) UNSIGNED NOT NULL COMMENT '所属相册',
    `title` varchar(255) NOT NULL DEFAULT '' COMMENT '图片标题',
    `image` varchar(500) NOT NULL COMMENT '图片地址',
    `thumb` varchar(500) NOT NULL DEFAULT '' COMMENT '缩略图',
    `description` varchar(500) NOT NULL DEFAULT '' COMMENT '图片描述',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序(越大越靠前)',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1显示 0隐藏',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_album` (`album_id`),
    KEY `idx_sort` (`sort_order` DESC, `id` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册图片';

-- -----------------------------------------------------------
-- 下载分类
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_download_categories`;
CREATE TABLE `yikai_download_categories` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL COMMENT '分类名称',
    `description` varchar(255) NOT NULL DEFAULT '' COMMENT '分类描述',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='下载分类';

-- -----------------------------------------------------------
-- 下载管理
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_downloads`;
CREATE TABLE `yikai_downloads` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id` int(10) UNSIGNED DEFAULT 0 COMMENT '分类ID',
    `title` varchar(255) NOT NULL COMMENT '文件名称',
    `description` text COMMENT '文件描述',
    `cover` varchar(500) DEFAULT '' COMMENT '封面图',
    `file_url` varchar(500) DEFAULT '' COMMENT '文件地址(上传或外链)',
    `file_name` varchar(255) DEFAULT '' COMMENT '原始文件名',
    `file_size` bigint(20) UNSIGNED DEFAULT 0 COMMENT '文件大小(字节)',
    `file_ext` varchar(20) DEFAULT '' COMMENT '文件扩展名',
    `download_count` int(10) UNSIGNED DEFAULT 0 COMMENT '下载次数',
    `is_external` tinyint(1) DEFAULT 0 COMMENT '是否外链：0本地 1外链',
    `require_login` tinyint(1) NOT NULL DEFAULT 0 COMMENT '下载条件：0游客 1需登录',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序(越大越靠前)',
    `status` tinyint(4) DEFAULT 1 COMMENT '状态：1显示 0隐藏',
    `created_at` int(10) UNSIGNED DEFAULT 0,
    `updated_at` int(10) UNSIGNED DEFAULT 0,
    `admin_id` int(10) UNSIGNED DEFAULT 0 COMMENT '创建人',
    PRIMARY KEY (`id`),
    KEY `idx_category` (`category_id`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order` DESC, `id` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='下载管理';

-- -----------------------------------------------------------
-- 招聘管理
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_jobs`;
CREATE TABLE `yikai_jobs` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` varchar(255) NOT NULL COMMENT '职位名称',
    `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
    `summary` text COMMENT '职位摘要',
    `content` longtext COMMENT '职位详情',
    `location` varchar(100) NOT NULL DEFAULT '' COMMENT '工作地点',
    `salary` varchar(50) NOT NULL DEFAULT '' COMMENT '薪资范围',
    `job_type` varchar(20) NOT NULL DEFAULT '' COMMENT '工作性质',
    `education` varchar(50) NOT NULL DEFAULT '' COMMENT '学历要求',
    `experience` varchar(50) NOT NULL DEFAULT '' COMMENT '经验要求',
    `headcount` varchar(20) NOT NULL DEFAULT '' COMMENT '招聘人数',
    `requirements` text COMMENT '任职要求',
    `views` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
    `is_top` tinyint(1) NOT NULL DEFAULT 0 COMMENT '置顶',
    `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：1招聘中 0已关闭',
    `publish_time` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '发布时间',
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人',
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_top` (`is_top` DESC, `sort_order` DESC, `id` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='招聘管理';

-- -----------------------------------------------------------
-- 发展历程时间线
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_timelines`;
CREATE TABLE `yikai_timelines` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `year` smallint(5) UNSIGNED NOT NULL COMMENT '年份',
    `month` tinyint(3) UNSIGNED DEFAULT 0 COMMENT '月份(0表示仅显示年)',
    `day` tinyint(3) UNSIGNED DEFAULT 0 COMMENT '日期(0表示不显示)',
    `title` varchar(200) NOT NULL COMMENT '标题',
    `content` text COMMENT '内容描述',
    `image` varchar(500) DEFAULT '' COMMENT '配图',
    `icon` varchar(50) DEFAULT '' COMMENT '图标(可选)',
    `color` varchar(20) DEFAULT '' COMMENT '颜色标记',
    `sort_order` int(11) DEFAULT 0 COMMENT '排序(越大越靠前)',
    `status` tinyint(4) DEFAULT 1 COMMENT '状态：1显示 0隐藏',
    `created_at` int(10) UNSIGNED DEFAULT 0,
    `updated_at` int(10) UNSIGNED DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_year` (`year`),
    KEY `idx_status` (`status`),
    KEY `idx_sort` (`sort_order` DESC, `year` DESC, `month` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发展历程时间线';

-- -----------------------------------------------------------
-- 插件表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_plugins`;
CREATE TABLE `yikai_plugins` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` varchar(100) NOT NULL COMMENT '插件标识',
    `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0禁用 1启用',
    `installed_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '安装时间',
    `activated_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '启用时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件表';

-- -----------------------------------------------------------
-- 前台会员表
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_members`;
CREATE TABLE `yikai_members` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` varchar(50) NOT NULL,
    `password` varchar(255) NOT NULL,
    `email` varchar(100) NOT NULL DEFAULT '',
    `nickname` varchar(50) NOT NULL DEFAULT '',
    `avatar` varchar(255) NOT NULL DEFAULT '',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `last_login_time` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `last_login_ip` varchar(45) NOT NULL DEFAULT '',
    `login_count` int(11) UNSIGNED NOT NULL DEFAULT 0,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`),
    UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台会员表';

-- -----------------------------------------------------------
-- 表单模板
-- -----------------------------------------------------------
DROP TABLE IF EXISTS `yikai_form_templates`;
CREATE TABLE `yikai_form_templates` (
    `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(50) NOT NULL COMMENT '表单名称',
    `slug` varchar(50) NOT NULL COMMENT '短码标识',
    `fields` text COMMENT '字段配置JSON',
    `success_message` varchar(255) NOT NULL DEFAULT '提交成功，感谢您的反馈！' COMMENT '成功提示',
    `status` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表单模板';

INSERT INTO `yikai_form_templates` (`id`, `name`, `slug`, `fields`, `success_message`, `status`, `created_at`) VALUES
(1, '联系表单', 'contact', '[{\"key\":\"name\",\"label\":\"姓名\",\"type\":\"text\",\"required\":true,\"placeholder\":\"请输入姓名\"},{\"key\":\"phone\",\"label\":\"电话\",\"type\":\"tel\",\"required\":true,\"placeholder\":\"请输入电话\"},{\"key\":\"email\",\"label\":\"邮箱\",\"type\":\"email\",\"required\":false,\"placeholder\":\"请输入邮箱\"},{\"key\":\"company\",\"label\":\"公司\",\"type\":\"text\",\"required\":false,\"placeholder\":\"请输入公司名称\"},{\"key\":\"content\",\"label\":\"留言内容\",\"type\":\"textarea\",\"required\":true,\"placeholder\":\"请输入留言内容\"}]', '提交成功，感谢您的反馈！', 1, UNIX_TIMESTAMP());

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 演示数据（从当前数据库导出）
-- ============================================================

INSERT INTO `yikai_roles` (`id`, `name`, `description`, `permissions`, `status`, `created_at`) VALUES
('1', '超级管理员', '拥有全部权限', '["*"]', '1', '1770899116'),
('2', '编辑', '内容编辑权限', '["content","media"]', '1', '1770899116'),
('3', '运营', '运营管理权限', '["content","media","form","banner","link"]', '1', '1770899116');

INSERT INTO `yikai_settings` (`id`, `group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
('1', 'basic', 'site_name', 'Yikai CMS', 'text', '站点名称', '', NULL, '1'),
('2', 'basic', 'site_keywords', '企业官网,CMS系统,内容管理', 'textarea', 'SEO关键词', '多个关键词用逗号分隔', NULL, '2'),
('3', 'basic', 'site_description', '专业的企业内容管理系统，助力企业数字化转型', 'textarea', 'SEO描述', '', NULL, '3'),
('4', 'basic', 'site_logo', '/images/logo.png', 'image', '站点Logo', '', NULL, '4'),
('5', 'basic', 'site_icp', '', 'text', 'ICP备案号', '', NULL, '5'),
('6', 'basic', 'site_police', '', 'text', '公安备案号', '', NULL, '6'),
('7', 'basic', 'primary_color', '#3B82F6', 'color', '主题色', '十六进制颜色值', NULL, '7'),
('8', 'basic', 'secondary_color', '#1D4ED8', 'color', '次要色', '十六进制颜色值', NULL, '8'),
('9', 'contact', 'contact_phone', '400-888-8888', 'text', '联系电话', '', NULL, '1'),
('10', 'contact', 'contact_email', 'contact@example.com', 'text', '联系邮箱', '', NULL, '2'),
('11', 'contact', 'contact_address', '上海市浦东新区XX路XX号', 'textarea', '联系地址', '', NULL, '3'),
('12', 'contact', 'contact_qrcode', '', 'image', '微信二维码', '', NULL, '4'),
('13', 'contact', 'contact_map', '', 'image', '地图图片', '', NULL, '5'),
('14', 'basic', 'banner_height_pc', '650', 'number', '轮播图高度(PC)', '单位像素', NULL, '9'),
('15', 'basic', 'banner_height_mobile', '300', 'number', '轮播图高度(移动)', '单位像素', NULL, '10'),
('16', 'home', 'home_about_content', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。经过多年发展，已成为行业内具有影响力的企业之一。', 'textarea', '关于我们简介', '首页关于我们区块的介绍文字', NULL, '1'),
('17', 'home', 'home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 'image', '关于我们图片', '首页关于我们区块的图片', NULL, '2'),
('18', 'home', 'home_about_tag_title', '专业服务', 'text', '角标标题', '图片左下角标签标题', NULL, '3'),
('19', 'home', 'home_about_tag_desc', '品质 · 创新 · 共赢', 'text', '角标描述', '图片左下角标签描述', NULL, '4'),
('20', 'home', 'home_stat_1_num', '10+', 'text', '统计数字1', '', NULL, '5'),
('21', 'home', 'home_stat_1_text', '年行业经验', 'text', '统计文字1', '', NULL, '6'),
('22', 'home', 'home_stat_2_num', '1000+', 'text', '统计数字2', '', NULL, '7'),
('23', 'home', 'home_stat_2_text', '服务客户', 'text', '统计文字2', '', NULL, '8'),
('24', 'home', 'home_stat_3_num', '50+', 'text', '统计数字3', '', NULL, '9'),
('25', 'home', 'home_stat_3_text', '专业团队', 'text', '统计文字3', '', NULL, '10'),
('26', 'home', 'home_stat_4_num', '100%', 'text', '统计数字4', '', NULL, '11'),
('27', 'home', 'home_stat_4_text', '客户满意', 'text', '统计文字4', '', NULL, '12'),
('28', 'home', 'home_advantage_desc', '专业团队，优质服务，值得信赖', 'text', '优势区块描述', '', NULL, '13'),
('29', 'home', 'home_adv_1_title', '品质保证', 'text', '优势1标题', '', NULL, '14'),
('30', 'home', 'home_adv_1_desc', '严格把控产品质量，确保每一件产品都符合标准', 'text', '优势1描述', '', NULL, '15'),
('31', 'home', 'home_adv_2_title', '技术领先', 'text', '优势2标题', '', NULL, '16'),
('32', 'home', 'home_adv_2_desc', '持续研发创新，保持技术的领先优势', 'text', '优势2描述', '', NULL, '17'),
('33', 'home', 'home_adv_3_title', '专业服务', 'text', '优势3标题', '', NULL, '18'),
('34', 'home', 'home_adv_3_desc', '专业团队7x24小时技术支持服务', 'text', '优势3描述', '', NULL, '19'),
('35', 'home', 'home_adv_4_title', '合作共赢', 'text', '优势4标题', '', NULL, '20'),
('36', 'home', 'home_adv_4_desc', '与客户建立长期合作关系，实现互利共赢', 'text', '优势4描述', '', NULL, '21'),
('37', 'home', 'home_cta_title', '准备好开始合作了吗？', 'text', 'CTA标题', '行动号召区块标题', NULL, '22'),
('38', 'home', 'home_cta_desc', '联系我们，获取专业的解决方案', 'text', 'CTA描述', '行动号召区块描述', NULL, '23'),
('39', 'email', 'smtp_host', '', 'text', 'SMTP服务器', '如：smtp.qq.com', NULL, '1'),
('40', 'email', 'smtp_port', '465', 'text', 'SMTP端口', 'SSL常用465，TLS常用587', NULL, '2'),
('41', 'email', 'smtp_secure', 'ssl', 'text', '加密方式', 'ssl/tls/空', NULL, '3'),
('42', 'email', 'smtp_user', '', 'text', 'SMTP用户名', '通常是完整邮箱地址', NULL, '4'),
('43', 'email', 'smtp_pass', '', 'text', 'SMTP密码', 'QQ邮箱需使用授权码', NULL, '5'),
('44', 'email', 'mail_from', '', 'text', '发件人邮箱', '留空则使用SMTP用户名', NULL, '6'),
('45', 'email', 'mail_from_name', '', 'text', '发件人名称', '留空使用站点名称', NULL, '7'),
('46', 'email', 'mail_admin', '', 'text', '管理员邮箱', '接收表单提交通知', NULL, '8'),
('47', 'email', 'mail_notify_form', '0', 'text', '表单提交通知', '1开启/0关闭', NULL, '9'),
('48', 'basic', 'product_layout', 'sidebar', 'select', '产品列表版式', '', '{"sidebar":"侧栏模式","top":"顶栏模式"}', '11'),
('53', 'basic', 'show_price', '0', 'select', '显示产品价格', '前台是否显示产品价格', '{"0":"不显示","1":"显示"}', '12'),
('49', 'home', 'home_show_links', '1', 'select', '显示合作伙伴', '是否在页脚显示合作伙伴', NULL, '24'),
('52', 'contact', 'contact_cards', '[{"icon":"phone","label":"联系电话","value":"400-888-8888"},{"icon":"email","label":"电子邮箱","value":"contact@example.com"},{"icon":"location","label":"公司地址","value":"上海市浦东新区XX路XX号"}]', 'contact_cards', '联系信息卡片', '联系我们页面顶部展示的信息卡片，最多4个', NULL, '0'),
('50', 'contact', 'contact_form_title', '在线留言', 'text', '表单标题', '', NULL, '10'),
('54', 'contact', 'contact_form_desc', '', 'textarea', '表单描述', '显示在标题下方的说明文字', NULL, '11'),
('55', 'contact', 'contact_form_fields', '[{"key":"name","label":"您的姓名","type":"text","required":true,"enabled":true},{"key":"phone","label":"联系电话","type":"tel","required":true,"enabled":true},{"key":"email","label":"电子邮箱","type":"email","required":false,"enabled":true},{"key":"company","label":"公司名称","type":"text","required":false,"enabled":true},{"key":"content","label":"留言内容","type":"textarea","required":true,"enabled":true}]', 'contact_form_fields', '表单字段', '设置表单显示的字段、标签和是否必填', NULL, '12'),
('56', 'contact', 'contact_form_success', '提交成功，我们会尽快与您联系！', 'text', '提交成功提示', '表单提交成功后显示的文字', NULL, '13'),
('57', 'header', 'topbar_enabled', '0', 'select', '显示顶部通栏', 'Logo上方的通栏区域', '{"0":"隐藏","1":"显示"}', '0'),
('58', 'header', 'topbar_bg_color', '#f3f4f6', 'color', '通栏背景色', '顶部通栏背景颜色', NULL, '1'),
('59', 'header', 'topbar_left', '', 'code', '通栏左侧内容', '支持HTML代码，如电话、公告等', NULL, '2'),
('60', 'header', 'header_nav_layout', 'right', 'select', '导航布局', 'Logo右侧或Logo下方通栏', '{"right":"Logo右侧","below":"Logo下方通栏"}', '10'),
('61', 'header', 'header_sticky', '0', 'select', '固定顶部', '导航栏是否固定在页面顶部', '{"1":"是","0":"否"}', '11'),
('62', 'header', 'header_bg_color', '#ffffff', 'color', '背景颜色', '十六进制颜色值', NULL, '12'),
('63', 'header', 'header_text_color', '#4b5563', 'color', '文字颜色', '十六进制颜色值', NULL, '13'),
('64', 'footer', 'footer_columns', '[{"title":"关于我们","content":"{{site_description}}","col_span":2},{"title":"联系方式","content":"{{contact_info}}","col_span":1},{"title":"关注我们","content":"{{qrcode}}","col_span":1}]', 'footer_columns', '页脚栏目', '自定义页脚各列内容，最多4列', NULL, '1'),
('65', 'footer', 'footer_bg_color', '#1f2937', 'color', '背景颜色', '十六进制颜色值', NULL, '2'),
('66', 'footer', 'footer_bg_image', '', 'image', '背景图片', '设置后覆盖背景颜色', NULL, '3'),
('67', 'footer', 'footer_text_color', '#9ca3af', 'color', '文字颜色', '十六进制颜色值', NULL, '4'),
('93', 'footer', 'footer_nav', '[{\"title\":\"\",\"links\":[{\"name\":\"隐私政策\",\"url\":\"/privacy.html\"},{\"name\":\"服务条款\",\"url\":\"/terms.html\"}]}]', 'footer_nav', '页脚导航', '版权栏上方的导航链接分组', NULL, '5'),
('68', 'basic', 'site_url', '', 'text', '站点域名', '如 https://www.example.com（不含末尾斜杠）', NULL, '0'),
('69', 'code', 'custom_head_code', '', 'code', 'Head 代码', '插入到 </head> 前的代码，如网站验证、SEO meta 标签等', NULL, '1'),
('70', 'code', 'custom_body_code', '', 'code', 'Body 代码', '插入到 </body> 前的代码，如统计代码、在线客服等', NULL, '2'),
('71', 'basic', 'site_favicon', '/favicon.ico', 'image', '站点图标', '浏览器标签页图标，支持 .ico/.png 格式', NULL, '5'),
('72', 'home', 'home_show_banner', '1', 'select', '显示轮播图', '首页Banner轮播图区块', NULL, '30'),
('73', 'home', 'home_show_about', '1', 'select', '显示关于我们', '首页关于我们简介区块', NULL, '31'),
('74', 'home', 'home_show_stats', '1', 'select', '显示数据统计', '首页数据统计横栏', NULL, '32'),
('75', 'home', 'home_show_channels', '1', 'select', '显示栏目区块', '产品中心、新闻资讯等首页展示栏目', NULL, '33'),
('76', 'home', 'home_show_advantage', '1', 'select', '显示优势展示', '首页我们的优势区块', NULL, '34'),
('77', 'home', 'home_show_cta', '1', 'select', '显示行动号召', '首页底部CTA行动号召区块', NULL, '35'),
('78', 'home', 'home_blocks_config', '[{"type":"banner","enabled":true},{"type":"about","enabled":true},{"type":"stats","enabled":true},{"type":"channels","enabled":true},{"type":"testimonials","enabled":true},{"type":"advantage","enabled":true},{"type":"cta","enabled":true}]', 'home_blocks', '首页区块配置', '首页区块顺序与显示配置', NULL, '40'),
('79', 'home', 'home_testimonials', '[{"avatar":"","name":"张先生","company":"某科技有限公司","content":"非常专业的服务团队，合作非常愉快！产品质量令人满意。"},{"avatar":"","name":"李女士","company":"某贸易公司","content":"产品质量优秀，售后服务及时，值得信赖的合作伙伴。"},{"avatar":"","name":"王总","company":"某集团公司","content":"多年合作，一直保持高品质的服务水准，强烈推荐！"}]', 'home_testimonials', '客户评价', '首页客户评价区块数据', NULL, '26'),
('80', 'home', 'home_testimonials_title', '客户评价', 'text', '评价区标题', '客户评价区块的标题', NULL, '27'),
('81', 'home', 'home_testimonials_desc', '听听合作伙伴怎么说', 'text', '评价区描述', '客户评价区块的副标题', NULL, '28'),
('82', 'home', 'home_stat_bg', '', 'image', '统计区背景图', '数据统计横栏的背景图片', NULL, '12'),
('83', 'home', 'home_about_layout', 'text_left', 'select', '关于我们布局', '左文右图或左图右文', '{"text_left":"左文右图","image_left":"左图右文"}', '6'),
('84', 'home', 'home_adv_1_icon', 'check-circle', 'icon', '优势1图标', '', NULL, '14'),
('85', 'home', 'home_adv_2_icon', 'academic-cap', 'icon', '优势2图标', '', NULL, '16'),
('86', 'home', 'home_adv_3_icon', 'briefcase', 'icon', '优势3图标', '', NULL, '18'),
('87', 'home', 'home_adv_4_icon', 'users', 'icon', '优势4图标', '', NULL, '20'),
('88', 'header', 'show_member_entry', '0', 'select', '显示会员入口', '导航栏显示会员登录/注册入口', '{"0":"隐藏","1":"显示"}', '3'),
('89', 'member', 'allow_member_register', '0', 'switch', '允许会员注册', '是否允许前台会员注册', NULL, '1'),
('90', 'member', 'download_require_login', '0', 'switch', '下载需要登录', '下载文件是否需要会员登录', NULL, '2'),
('91', 'home', 'home_links_title', '合作伙伴', 'text', '链接区块标题', '页脚合作伙伴区块的标题', NULL, '25');

INSERT INTO `yikai_channels` (`id`, `parent_id`, `name`, `slug`, `type`, `album_id`, `icon`, `image`, `description`, `content`, `link_url`, `link_target`, `redirect_type`, `redirect_url`, `seo_title`, `seo_keywords`, `seo_description`, `is_nav`, `is_home`, `status`, `is_system`, `sort_order`, `created_at`, `updated_at`) VALUES
('1', '0', '关于我们', 'about', 'page', '0', '', '', '了解我们的企业文化与发展历程', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1770899116', '0'),
('2', '1', '公司简介', 'company', 'page', '0', '', '', '公司基本情况介绍', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1770899116', '0'),
('3', '1', '企业文化', 'culture', 'page', '0', '', '', '企业核心价值观与文化理念', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1770899116', '0'),
('4', '1', '发展历程', 'history', 'page', '0', '', '', '企业发展的重要里程碑', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '3', '1770899116', '0'),
('6', '0', '产品中心', 'product', 'product', '0', '', '', '我们的产品与服务', NULL, '', '_self', 'auto', '', '', '', '', '1', '1', '1', '1', '2', '1770899116', '0'),
('9', '0', '解决方案', 'solution', 'case', '0', '', '', '行业解决方案与成功案例', '', '', '_self', 'auto', '', '', '', '', '1', '1', '1', '1', '3', '1770899116', '0'),
('10', '9', '行业方案', 'industry', 'case', '0', '', '', '针对不同行业的解决方案', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1770899116', '0'),
('11', '9', '成功案例', 'cases', 'case', '0', '', '', '客户成功案例展示', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1770899116', '0'),
('12', '0', '新闻资讯', 'news', 'list', '0', '', '', '最新动态与行业资讯', NULL, '', '_self', 'auto', '', '', '', '', '1', '1', '1', '1', '4', '1770899116', '0'),
('13', '12', '公司新闻', 'company-news', 'list', '0', '', '', '公司最新动态', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1770899116', '0'),
('14', '12', '行业动态', 'industry-news', 'list', '0', '', '', '行业最新资讯', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1770899116', '0'),
('15', '0', '服务支持', 'service', 'page', '0', '', '', '专业的服务与技术支持', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '5', '1770899116', '0'),
('16', '15', '服务流程', 'process', 'page', '0', '', '', '标准化服务流程', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1770899116', '0'),
('17', '15', '常见问题', 'faq', 'list', '0', '', '', '常见问题解答', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1770899116', '0'),
('18', '15', '下载中心', 'download', 'download', '0', '', '', '资料与软件下载', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '3', '1770899116', '0'),
('19', '0', '人才招聘', 'job', 'job', '0', '', '', '加入我们，共创未来', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '6', '1770899116', '0'),
('20', '0', '联系我们', 'contact', 'page', '0', '', '', '联系方式与在线留言', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '7', '1770899116', '0'),
('23', '1', '荣誉资质', 'honor', 'album', '7', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '4', '1770899116', '0'),
('28', '1', '组织架构', 'organization', 'page', '0', '', '', '公司组织架构', NULL, '', '_self', 'none', '', '', '', '', '1', '0', '1', '1', '5', '1770899116', '0'),
('29', '0', '隐私政策', 'privacy', 'page', '0', '', '', '网站隐私政策', NULL, '', '_self', 'none', '', '', '', '', '0', '0', '1', '1', '98', '1770899116', '0'),
('30', '0', '服务条款', 'terms', 'page', '0', '', '', '网站服务条款', NULL, '', '_self', 'none', '', '', '', '', '0', '0', '1', '1', '99', '1770899116', '0');

INSERT INTO `yikai_article_categories` (`id`, `parent_id`, `name`, `slug`, `image`, `description`, `seo_title`, `seo_keywords`, `seo_description`, `status`, `sort_order`, `created_at`) VALUES
('1', '4', '公司新闻', 'company-news', '', '公司最新动态和重要公告', '', '', '', '1', '1', '1770899116'),
('2', '4', '行业动态', 'industry-news', '', '行业最新资讯和趋势分析', '', '', '', '1', '2', '1770899116'),
('3', '0', '技术分享', 'tech-share', '', '技术文章和经验分享', '', '', '', '1', '3', '1770899116'),
('4', '0', '新闻资讯', 'news', '', '新闻资讯栏目', '', '', '', '1', '1', '1770899116');

INSERT INTO `yikai_articles` (`id`, `category_id`, `title`, `subtitle`, `slug`, `cover`, `summary`, `content`, `author`, `source`, `tags`, `is_top`, `is_recommend`, `is_hot`, `views`, `likes`, `status`, `publish_time`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', '1', '公司荣获"年度最佳科技创新奖"', '', '', '', '在刚刚结束的行业年度评选中，我公司凭借出色的技术创新能力荣获殊荣。', '<p>在日前举办的2024年度行业颁奖典礼上，我公司凭借在技术创新领域的突出表现，荣获"年度最佳科技创新奖"。</p><p>公司CEO表示："这个奖项是对全体员工努力的认可，我们将继续保持创新精神，为客户创造更大价值。"</p>', '管理员', '', '', '1', '1', '0', '7', '0', '1', '1770899116', '1770899116', '1770899116', '1'),
('2', '1', '公司与战略合作伙伴签署合作协议', '', 'partnership-agreement', '', '公司与多家行业领先企业达成战略合作，共同推进行业发展。', '<p>近日，公司与多家行业领先企业签署战略合作协议，将在技术研发、市场拓展等领域开展深度合作。</p>', '管理员', '', '', '0', '1', '0', '0', '0', '1', '1770899116', '1770899116', '1770899116', '1'),
('3', '2', '数字化转型趋势报告发布', '', 'digital-transformation-report', '', '最新行业研究报告显示，企业数字化转型已成为必然趋势。', '<p>近日，某权威研究机构发布了《2024年企业数字化转型趋势报告》。</p><p>报告指出，超过80%的企业已将数字化转型列入战略规划。</p>', '管理员', '', '', '0', '0', '0', '2', '0', '1', '1770899116', '1770899116', '1770899116', '1'),
('4', '3', 'PHP 8.0 新特性详解', '', 'php8-new-features', '', '详细介绍PHP 8.0版本带来的新特性和性能优化。', '<p>PHP 8.0 带来了众多新特性，包括JIT编译器、命名参数、联合类型等。</p><h3>主要新特性</h3><ul><li>JIT 编译器</li><li>命名参数</li><li>联合类型</li></ul>', '技术部', '', '', '0', '0', '0', '1', '0', '1', '1770899116', '1770899116', '1770899116', '1');

INSERT INTO `yikai_product_categories` (`id`, `parent_id`, `name`, `slug`, `image`, `description`, `seo_title`, `seo_keywords`, `seo_description`, `status`, `is_nav`, `sort_order`, `created_at`) VALUES
('1', '0', '智能设备', 'smart-device', '', '智能硬件设备产品系列', '', '', '', '1', '1', '1', '1770899116'),
('2', '0', '软件服务', 'software', '', '企业软件与云服务产品', '', '', '', '1', '1', '2', '1770899116'),
('3', '1', '传感器模块', 'sensor-module', '', NULL, '', '', '', '1', '1', '1', '1770899116'),
('4', '1', '控制终端', 'control-terminal', '', NULL, '', '', '', '1', '1', '2', '1770899116');

INSERT INTO `yikai_products` (`id`, `category_id`, `title`, `subtitle`, `slug`, `cover`, `images`, `summary`, `content`, `price`, `market_price`, `model`, `specs`, `tags`, `is_top`, `is_recommend`, `is_hot`, `is_new`, `views`, `status`, `sort_order`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', '1', '智能物联网网关', '工业级高性能网关设备', 'iot-gateway', 'https://picsum.photos/800/600?random=10', 'https://picsum.photos/800/600?random=20\nhttps://picsum.photos/800/600?random=21\nhttps://picsum.photos/800/600?random=22\nhttps://picsum.photos/800/600?random=23', '支持多协议接入，具备边缘计算能力', NULL, '2999.00', '3599.00', 'IOT-GW-100', NULL, '', '0', '1', '0', '1', '11', '1', '0', '1770899116', '1770899116', '1'),
('2', '2', '企业管理云平台', '一站式企业数字化解决方案', 'cloud-platform', 'https://picsum.photos/800/600?random=11', NULL, '集成ERP、CRM、OA等功能', NULL, '0.00', '0.00', 'EMS-CLOUD-V3', NULL, '', '0', '1', '0', '1', '7', '1', '0', '1770899116', '1770899116', '1'),
('3', '3', '温湿度传感器 TH-200', '高精度工业级温湿度传感器', 'th200-sensor', 'https://picsum.photos/800/600?random=12', 'https://picsum.photos/800/600?random=40\nhttps://picsum.photos/800/600?random=41', '采用瑞士进口芯片，精度±0.1°C，适用于工业环境监测、仓储管理、智慧农业等场景。', NULL, '0.00', '0.00', 'TH-200', NULL, '', '0', '0', '0', '1', '4', '1', '0', '1770899116', '1770899116', '1'),
('4', '3', '光照传感器 LS-100', '宽范围光照强度传感器', 'ls100-sensor', 'https://picsum.photos/800/600?random=13', NULL, '检测范围0-200000Lux，支持RS485/Modbus通信，广泛应用于智慧农业、气象监测。', NULL, '0.00', '0.00', 'LS-100', NULL, '', '0', '0', '1', '0', '4', '1', '0', '1770899116', '1770899116', '1'),
('5', '4', '工业边缘控制器 EC-500', '高性能边缘计算控制终端', 'ec500-controller', 'https://picsum.photos/800/600?random=14', 'https://picsum.photos/800/600?random=30\nhttps://picsum.photos/800/600?random=31\nhttps://picsum.photos/800/600?random=32', '搭载ARM Cortex-A72处理器，支持多种工业协议，可本地化运行AI推理模型。', NULL, '0.00', '0.00', 'EC-500', NULL, '', '0', '1', '1', '1', '0', '1', '0', '1770899116', '1770899116', '1'),
('6', '4', '智能网关控制器 GC-300', '多协议融合网关控制器', 'gc300-gateway', 'https://picsum.photos/800/600?random=15', NULL, '同时支持Wi-Fi/Zigbee/LoRa/4G通信，内置边缘计算能力，一站式设备管理。', NULL, '0.00', '0.00', 'GC-300', NULL, '', '0', '1', '0', '0', '0', '1', '0', '1770899116', '1770899116', '1');

INSERT INTO `yikai_contents` (`id`, `channel_id`, `type`, `title`, `subtitle`, `slug`, `cover`, `images`, `summary`, `content`, `author`, `source`, `tags`, `attachment`, `download_count`, `price`, `specs`, `location`, `salary`, `requirements`, `is_top`, `is_recommend`, `is_hot`, `views`, `likes`, `seo_title`, `seo_keywords`, `seo_description`, `status`, `publish_time`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', '2', 'article', '公司简介', '', '', '', NULL, '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。', '<h2>企业概况</h2>\n<p>公司成立于2010年，总部位于上海，是一家集研发、生产、销售、服务于一体的高新技术企业。经过多年发展，已成为行业内具有影响力的企业之一。</p>\n\n<h2>核心优势</h2>\n<ul>\n<li><strong>技术领先</strong>：拥有多项核心专利技术</li>\n<li><strong>品质保证</strong>：通过ISO9001质量管理体系认证</li>\n<li><strong>服务专业</strong>：7x24小时技术支持</li>\n<li><strong>经验丰富</strong>：服务超过1000家企业客户</li>\n</ul>\n\n<h2>发展愿景</h2>\n<p>以技术创新为驱动，以客户需求为导向，致力于成为行业领先的解决方案提供商。</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '1', '1', '0', '100', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('2', '3', 'article', '企业文化', '', '', '', NULL, '创新、务实、共赢是我们的核心价值观。', '<h2>使命</h2>\n<p>用科技创造价值，为客户提供卓越的产品与服务。</p>\n\n<h2>愿景</h2>\n<p>成为行业最受尊敬的企业，引领行业发展。</p>\n\n<h2>核心价值观</h2>\n<ul>\n<li><strong>创新</strong>：持续创新，追求卓越</li>\n<li><strong>务实</strong>：脚踏实地，精益求精</li>\n<li><strong>共赢</strong>：合作共赢，共同发展</li>\n<li><strong>诚信</strong>：诚实守信，言行一致</li>\n</ul>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '0', '0', '0', '50', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('5', '11', 'case', '某大型制造企业数字化转型项目', '', '', '/images/case-demo.jpg', NULL, '帮助客户实现生产效率提升30%', '<h2>项目背景</h2>\n<p>客户是一家大型制造企业，面临生产效率低、管理成本高等问题，急需进行数字化转型。</p>\n\n<h2>解决方案</h2>\n<p>我们为客户量身定制了一套智能制造解决方案，包括：</p>\n<ul>\n<li>生产计划智能排程</li>\n<li>设备状态实时监控</li>\n<li>质量追溯系统</li>\n<li>供应链协同平台</li>\n</ul>\n\n<h2>实施效果</h2>\n<ul>\n<li>生产效率提升 30%</li>\n<li>运营成本降低 20%</li>\n<li>产品良率提高 15%</li>\n</ul>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '1', '1', '0', '201', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('6', '13', 'article', '公司荣获"年度最佳科技创新奖"', '', '', '/uploads/images/news-demo.jpg', NULL, '在刚刚结束的行业年度评选中，我公司凭借出色的技术创新能力荣获殊荣。', '<p>在日前举办的2024年度行业颁奖典礼上，我公司凭借在技术创新领域的突出表现，荣获"年度最佳科技创新奖"。</p>\n\n<p>此次获奖是对我们多年来坚持技术创新的肯定。公司始终将技术研发作为核心竞争力，每年投入大量资源进行产品研发和技术升级。</p>\n\n<p>公司CEO表示："这个奖项是对全体员工努力的认可，我们将继续保持创新精神，为客户创造更大价值。"</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '1', '1', '1', '810', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('7', '14', 'article', '数字化转型趋势报告发布', '', '', '/uploads/images/news-demo.jpg', NULL, '最新行业研究报告显示，企业数字化转型已成为必然趋势。', '<p>近日，某权威研究机构发布了《2024年企业数字化转型趋势报告》，报告指出：</p>\n\n<ul>\n<li>超过80%的企业已启动数字化转型</li>\n<li>云计算、大数据、AI成为转型三大核心技术</li>\n<li>预计到2025年，数字化投入将增长50%</li>\n</ul>\n\n<p>报告建议企业应尽早规划数字化战略，选择合适的技术合作伙伴，稳步推进转型进程。</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '0', '0', '0', '304', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('8', '16', 'article', '服务流程', '', '', '', NULL, '标准化的服务流程，确保服务质量', '<h2>服务流程</h2>\n\n<h3>1. 需求沟通</h3>\n<p>深入了解客户需求，进行详细的需求分析和评估。</p>\n\n<h3>2. 方案设计</h3>\n<p>根据需求定制专属解决方案，提供详细的实施计划。</p>\n\n<h3>3. 项目实施</h3>\n<p>专业团队负责项目部署和实施，确保按时交付。</p>\n\n<h3>4. 培训交付</h3>\n<p>提供系统培训和使用指导，确保顺利上线。</p>\n\n<h3>5. 售后服务</h3>\n<p>7x24小时技术支持，定期回访和系统优化。</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '1', '0', '0', '150', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('9', '17', 'article', '如何开始使用我们的产品？', '', '', '', NULL, '新用户快速入门指南', '<h2>快速入门步骤</h2>\n\n<ol>\n<li><strong>注册账号</strong>：访问官网，点击注册按钮完成账号注册</li>\n<li><strong>选择套餐</strong>：根据需求选择合适的产品套餐</li>\n<li><strong>系统配置</strong>：按照向导完成基础配置</li>\n<li><strong>开始使用</strong>：登录系统即可开始使用</li>\n</ol>\n\n<p>如有任何问题，请联系我们的客服团队。</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '0', '0', '0', '203', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('13', '20', 'article', '联系我们', '', '', '', NULL, NULL, '', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '0', '0', '0', '0', '0', '', '', '', '1', '0', '1770899116', '1770899116', '0'),
('14', '29', 'article', '隐私政策', '', '', '', NULL, '本隐私政策说明了我们如何收集、使用和保护您的个人信息。', '<h2>隐私政策</h2>\n<p>本隐私政策适用于本网站（以下简称"我们"）提供的所有服务。我们深知个人信息对您的重要性，并会尽全力保护您的个人信息安全。请您在使用我们的服务前，仔细阅读本隐私政策。</p>\n\n<h3>一、信息收集</h3>\n<p>我们可能会收集以下类型的信息：</p>\n<ul>\n<li><strong>个人身份信息</strong>：如姓名、电子邮件地址、电话号码等，仅在您主动提交表单或注册时收集。</li>\n<li><strong>浏览信息</strong>：如IP地址、浏览器类型、访问时间、浏览页面等，通过日志自动记录。</li>\n<li><strong>Cookie信息</strong>：用于改善用户体验和网站功能。</li>\n</ul>\n\n<h3>二、信息使用</h3>\n<p>我们收集的信息将用于以下目的：</p>\n<ul>\n<li>提供、维护和改进我们的服务</li>\n<li>处理您的咨询和请求</li>\n<li>发送服务相关的通知</li>\n<li>防止欺诈和滥用行为</li>\n</ul>\n\n<h3>三、信息保护</h3>\n<p>我们采取合理的技术和管理措施保护您的个人信息安全，防止未经授权的访问、披露、修改或销毁。</p>\n\n<h3>四、信息共享</h3>\n<p>我们不会向第三方出售、出租或以其他方式分享您的个人信息，除非：</p>\n<ul>\n<li>获得您的明确同意</li>\n<li>法律法规要求</li>\n<li>保护我们的合法权益</li>\n</ul>\n\n<h3>五、Cookie使用</h3>\n<p>本网站使用Cookie来改善您的浏览体验。您可以通过浏览器设置管理Cookie偏好。禁用Cookie可能会影响网站部分功能的正常使用。</p>\n\n<h3>六、政策更新</h3>\n<p>我们可能会不时更新本隐私政策。更新后的政策将在本页面发布，建议您定期查阅。</p>\n\n<h3>七、联系我们</h3>\n<p>如果您对本隐私政策有任何疑问，请通过网站联系方式与我们取得联系。</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '0', '0', '0', '0', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1'),
('15', '30', 'article', '服务条款', '', '', '', NULL, '使用本网站前请仔细阅读以下服务条款。', '<h2>服务条款</h2>\n<p>欢迎访问本网站。请在使用本网站服务之前，仔细阅读以下条款。使用本网站即表示您同意遵守以下条款和条件。</p>\n\n<h3>一、服务说明</h3>\n<p>本网站提供的信息和服务仅供参考。我们保留随时修改、暂停或终止服务的权利，恕不另行通知。</p>\n\n<h3>二、用户行为规范</h3>\n<p>在使用本网站时，您同意：</p>\n<ul>\n<li>不得利用本网站从事违法活动</li>\n<li>不得上传或传播含有病毒、恶意代码的内容</li>\n<li>不得侵犯他人的知识产权或其他合法权益</li>\n<li>不得干扰或破坏网站的正常运行</li>\n<li>遵守中华人民共和国相关法律法规</li>\n</ul>\n\n<h3>三、知识产权</h3>\n<p>本网站的所有内容，包括但不限于文字、图片、音频、视频、软件、程序、版面设计等，均受著作权法和其他知识产权法律法规保护。未经我们书面许可，任何人不得复制、转载、修改或用于商业用途。</p>\n\n<h3>四、免责声明</h3>\n<ul>\n<li>本网站内容仅供一般性参考，不构成任何建议或承诺。</li>\n<li>我们不保证网站内容的准确性、完整性和及时性。</li>\n<li>对于因使用本网站而产生的任何直接或间接损失，我们不承担责任。</li>\n<li>本网站可能包含第三方网站的链接，我们对这些网站的内容不承担任何责任。</li>\n</ul>\n\n<h3>五、账号管理</h3>\n<p>如果您在本网站注册了账号，您有责任妥善保管您的账号信息和密码。因账号信息泄露导致的任何损失由您自行承担。</p>\n\n<h3>六、隐私保护</h3>\n<p>我们重视您的隐私保护，具体请参阅我们的<a href=\"/privacy.html\">隐私政策</a>。</p>\n\n<h3>七、条款修改</h3>\n<p>我们保留随时修改本服务条款的权利。修改后的条款将在本页面发布，继续使用本网站即表示您接受修改后的条款。</p>\n\n<h3>八、适用法律</h3>\n<p>本服务条款受中华人民共和国法律管辖。因本条款引起的任何争议，双方应友好协商解决。</p>\n\n<h3>九、联系方式</h3>\n<p>如果您对本服务条款有任何疑问，请通过网站联系方式与我们取得联系。</p>', '', '', '', '', '0', '0.00', NULL, '', '', NULL, '0', '0', '0', '0', '0', '', '', '', '1', '1770899116', '1770899116', '0', '1');

INSERT INTO `yikai_banners` (`id`, `position`, `title`, `subtitle`, `btn1_text`, `btn1_url`, `btn2_text`, `btn2_url`, `image`, `image_mobile`, `link_url`, `link_target`, `start_time`, `end_time`, `status`, `sort_order`, `created_at`) VALUES
('1', 'home', '数字化转型解决方案', '助力企业实现智能化升级', '关于我们', '/about.html', '下载中心', '/download.html', '/uploads/images/202602/20260214234920_22c6c8dc.jpg', '', '/about.html', '_self', '0', '0', '1', '1', '1770899116'),
('2', 'home', '专业的技术服务团队', '7x24小时为您保驾护航', '查看详情', '/about.html', '', '', 'https://picsum.photos/1920/600?random=2', '', '/about.html', '_self', '0', '0', '1', '2', '1770899116'),
('3', 'home', '创新引领未来', '持续创新，追求卓越', '', '', '', '', 'https://picsum.photos/1920/600?random=3', '', '/about.html', '_self', '0', '0', '1', '3', '1770899116');

INSERT INTO `yikai_links` (`id`, `name`, `url`, `logo`, `description`, `status`, `sort_order`, `created_at`) VALUES
('1', '易开网-域名注册', 'https://www.yikai.cn', '', '百度搜索引擎', '1', '1', '1770899116');

INSERT INTO `yikai_albums` (`id`, `category_id`, `name`, `slug`, `cover`, `description`, `photo_count`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
('7', '0', '荣誉资质', 'honor', '/images/cert-1.jpg', '企业荣誉证书与资质认证', '1', '100', '1', '1770899116', '0'),
('8', '0', '团队风采', 'team', '', '团队活动与员工风采展示', '0', '90', '1', '1770899116', '0'),
('9', '0', '企业环境', 'environment', '', '公司办公环境与生产车间', '0', '80', '1', '1770899116', '0');

INSERT INTO `yikai_album_photos` (`album_id`, `title`, `image`, `thumb`, `description`, `sort_order`, `status`, `created_at`) VALUES
('7', '授权证书', '/images/cert-1.jpg', '/images/cert-1.jpg', '企业授权认证证书', '1', '1', '1770899116');

INSERT INTO `yikai_download_categories` (`id`, `name`, `description`, `sort_order`, `status`, `created_at`) VALUES
('1', '产品手册', '产品使用手册和说明文档', '1', '1', '1770899116'),
('2', '软件下载', '软件安装包和工具', '2', '1', '1770899116'),
('3', '技术文档', '技术规范和开发文档', '3', '1', '1770899116');

INSERT INTO `yikai_downloads` (`id`, `category_id`, `title`, `description`, `cover`, `file_url`, `file_name`, `file_size`, `file_ext`, `download_count`, `is_external`, `sort_order`, `status`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', '1', '产品使用手册 V2.0', '最新版产品使用说明书，包含安装、配置和常见问题解答。', '', '', '', '0', 'pdf', '128', '0', '100', '1', '1770899116', '0', '0'),
('2', '2', '客户端软件 V3.5.1', '适用于Windows系统的客户端软件安装包。', '', '', '', '0', 'zip', '256', '0', '90', '1', '1770899116', '0', '0'),
('3', '3', 'API接口文档', '完整的API接口说明文档，供开发者参考。', '', '', '', '0', 'pdf', '89', '0', '80', '1', '1770899116', '0', '0');

INSERT INTO `yikai_jobs` (`id`, `title`, `cover`, `summary`, `content`, `location`, `salary`, `job_type`, `education`, `experience`, `headcount`, `requirements`, `views`, `is_top`, `sort_order`, `status`, `publish_time`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', 'PHP高级开发工程师', '', '', '<p>负责公司核心产品的后端架构设计与开发。</p>', '上海', '15k-25k', '全职', '本科', '3-5年', '2人', '1. 精通PHP语言，熟练使用Laravel/ThinkPHP等主流框架\n2. 熟悉MySQL数据库设计与优化\n3. 有大型项目开发经验者优先', '0', '1', '100', '1', '1770899116', '1770899116', '0', '1'),
('2', '前端开发工程师', '', '', '<p>负责公司产品的前端页面开发与优化。</p>', '上海', '10k-18k', '全职', '本科', '1-3年', '3人', '1. 精通HTML5/CSS3/JavaScript\n2. 熟练使用Vue/React等前端框架\n3. 有良好的代码规范和团队协作能力', '0', '0', '90', '1', '1770899116', '1770899116', '0', '1');

INSERT INTO `yikai_timelines` (`id`, `year`, `month`, `day`, `title`, `content`, `image`, `icon`, `color`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
('1', '2024', '6', '0', '品牌升级', '完成品牌全面升级，推出全新视觉形象系统，开启发展新篇章。', '', 'rocket', 'blue', '100', '1', '1770899116', '0'),
('2', '2024', '1', '0', '荣获殊荣', '荣获行业"最具创新力企业"称号，产品获得多项专利认证。', '', 'award', 'yellow', '95', '1', '1770899116', '0'),
('3', '2023', '8', '0', '战略合作', '与多家知名企业达成战略合作，业务版图进一步扩大。', '', 'handshake', 'green', '90', '1', '1770899116', '0'),
('4', '2023', '3', '0', '新品发布', '成功发布新一代核心产品，技术领先行业水平。', '', 'box', 'purple', '85', '1', '1770899116', '0'),
('5', '2022', '10', '0', '团队扩展', '团队规模突破100人，建立完善的研发和服务体系。', '', 'users', 'cyan', '80', '1', '1770899116', '0'),
('6', '2022', '5', '0', '获得融资', '完成A轮融资，获得知名投资机构数千万投资。', '', 'trending-up', 'red', '75', '1', '1770899116', '0'),
('7', '2021', '0', '0', '业务拓展', '业务范围扩展至全国主要城市，服务客户超过500家。', '', 'map', 'indigo', '70', '1', '1770899116', '0'),
('8', '2020', '0', '0', '公司成立', '公司正式成立，开始为客户提供专业服务。', '', 'flag', 'primary', '60', '1', '1770899116', '0');

INSERT INTO `yikai_plugins` (`id`, `slug`, `status`, `installed_at`, `activated_at`) VALUES
('1', 'search-replace', '1', '1770899116', '1770899116'),
('3', 'db-backup', '1', '1770899116', '1770899116'),
('4', 'back-to-top', '1', '1770899116', '1770899116');
