-- Yikai CMS 数据库备份
-- 时间: 2026-04-20 12:01:19
-- 数据库: ikaicms

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `yikai_admin_logs`;
CREATE TABLE `yikai_admin_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0',
  `admin_name` varchar(50) NOT NULL DEFAULT '',
  `module` varchar(50) NOT NULL DEFAULT '' COMMENT '模块',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '动作',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '描述',
  `url` varchar(255) NOT NULL DEFAULT '',
  `method` varchar(10) NOT NULL DEFAULT '',
  `request_data` text,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='操作日志';

INSERT INTO `yikai_admin_logs` (`id`, `admin_id`, `admin_name`, `module`, `action`, `description`, `url`, `method`, `request_data`, `ip`, `user_agent`, `created_at`) VALUES
('1', '1', 'admin', 'auth', 'logout', '退出登录', '/admin/logout.php', 'GET', '[]', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653011'),
('2', '0', 'admin', 'auth', 'login_fail', '登录失败：用户名或密码错误', '/admin/login.php', 'POST', '{\"username\":\"admin\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653022'),
('3', '1', 'admin', 'auth', 'login', '登录成功', '/admin/login.php', 'POST', '{\"_token\":\"baabf2175f0b6d0d4f8313fc45f5e48a4ebec692e5cd74174c7c3e664159f928\",\"username\":\"admin\",\"password\":\"password\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653028'),
('4', '1', 'admin', 'setting', 'update', '更新站点设置', '/admin/setting.php', 'POST', '{\"settings\":{\"site_url\":\"\",\"site_name\":\"Yikai CMS\",\"site_keywords\":\"企业官网,CMS,内容管理\",\"site_description\":\"专业的企业内容管理系统，助力企业数字化转型\",\"site_logo\":\"\",\"site_favicon\":\"\\/favicon.ico\",\"primary_color\":\"#3B82F6\",\"secondary_color\":\"#1D4ED8\",\"site_icp\":\"\",\"site_police\":\"\",\"html_cache_enabled\":\"1\",\"html_cache_ttl\":\"300\",\"admin_title\":\"Yikai CMS\",\"admin_logo\":\"\",\"admin_copyright\":\"\"},\"_token\":\"baabf2175f0b6d0d4f8313fc45f5e48a4ebec692e5cd74174c7c3e664159f928\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653295'),
('5', '1', 'admin', 'database', 'backup', '备份: 30个表, 62KB', '/admin/database.php', 'POST', '{\"_token\":\"baabf2175f0b6d0d4f8313fc45f5e48a4ebec692e5cd74174c7c3e664159f928\",\"action\":\"backup\",\"structure\":\"1\",\"data\":\"1\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653461'),
('6', '1', 'admin', 'database', 'delete_backup', '删除备份: backup_20260420_105101.sql', '/admin/database.php', 'POST', '{\"action\":\"delete_backup\",\"file\":\"backup_20260420_105101.sql\",\"_token\":\"baabf2175f0b6d0d4f8313fc45f5e48a4ebec692e5cd74174c7c3e664159f928\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653493'),
('7', '1', 'admin', 'profile', 'change_password', '修改密码', '/admin/profile.php', 'POST', '{\"_token\":\"baabf2175f0b6d0d4f8313fc45f5e48a4ebec692e5cd74174c7c3e664159f928\",\"action\":\"change_password\",\"old_password\":\"password\",\"new_password\":\"admin123\",\"confirm_password\":\"admin123\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776653513'),
('8', '1', 'admin', 'setting', 'update', '更新站点设置', '/admin/setting.php', 'POST', '{\"settings\":{\"site_url\":\"\",\"timeline_sort\":\"asc\",\"site_name\":\"Yikai CMS\",\"site_keywords\":\"企业官网,CMS,内容管理\",\"site_description\":\"专业的企业内容管理系统，助力企业数字化转型\",\"site_logo\":\"\",\"site_favicon\":\"\\/favicon.ico\",\"primary_color\":\"#3B82F6\",\"secondary_color\":\"#1D4ED8\",\"site_icp\":\"\",\"site_police\":\"\",\"html_cache_enabled\":\"1\",\"html_cache_ttl\":\"300\",\"admin_title\":\"后台管理\",\"admin_logo\":\"\",\"admin_copyright\":\"\"},\"_token\":\"baabf2175f0b6d0d4f8313fc45f5e48a4ebec692e5cd74174c7c3e664159f928\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '1776657365');

DROP TABLE IF EXISTS `yikai_ai_logs`;
CREATE TABLE `yikai_ai_logs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(30) NOT NULL,
  `model` varchar(50) NOT NULL DEFAULT '',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '操作类型',
  `prompt_tokens` int(11) NOT NULL DEFAULT '0',
  `completion_tokens` int(11) NOT NULL DEFAULT '0',
  `total_tokens` int(11) NOT NULL DEFAULT '0',
  `success` tinyint(1) NOT NULL DEFAULT '1',
  `error_msg` varchar(500) NOT NULL DEFAULT '',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider` (`provider`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='AI调用日志';

DROP TABLE IF EXISTS `yikai_album_photos`;
CREATE TABLE `yikai_album_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `album_id` int(10) unsigned NOT NULL COMMENT '所属相册',
  `title` varchar(255) DEFAULT '' COMMENT '图片标题',
  `image` varchar(500) NOT NULL COMMENT '图片地址',
  `thumb` varchar(500) DEFAULT '' COMMENT '缩略图',
  `description` varchar(500) DEFAULT '' COMMENT '图片描述',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序(越大越靠前)',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态:1显示,0隐藏',
  `created_at` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_album` (`album_id`),
  KEY `idx_sort` (`sort_order` DESC,`id` DESC)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='相册图片';

INSERT INTO `yikai_album_photos` (`id`, `album_id`, `title`, `image`, `thumb`, `description`, `sort_order`, `status`, `created_at`) VALUES
('1', '1', '高新技术企业证书', 'https://picsum.photos/600/400?random=401', '', '', '1', '1', '1776654388'),
('2', '1', 'ISO9001质量管理体系认证', 'https://picsum.photos/600/400?random=402', '', '', '2', '1', '1776654388'),
('3', '1', '软件企业认定证书', 'https://picsum.photos/600/400?random=403', '', '', '3', '1', '1776654388'),
('4', '1', '年度最佳科技创新奖', 'https://picsum.photos/600/400?random=404', '', '', '4', '1', '1776654388'),
('5', '1', '优秀供应商荣誉证书', 'https://picsum.photos/600/400?random=405', '', '', '5', '1', '1776654388'),
('6', '1', '行业十佳品牌奖', 'https://picsum.photos/600/400?random=406', '', '', '6', '1', '1776654388');

DROP TABLE IF EXISTS `yikai_albums`;
CREATE TABLE `yikai_albums` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned DEFAULT '0' COMMENT '分类ID',
  `name` varchar(100) NOT NULL COMMENT '相册名称',
  `slug` varchar(100) DEFAULT '' COMMENT 'URL别名',
  `cover` varchar(500) DEFAULT '' COMMENT '封面图',
  `description` text COMMENT '相册描述',
  `photo_count` int(10) unsigned DEFAULT '0' COMMENT '图片数量',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序(越大越靠前)',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态:1显示,0隐藏',
  `created_at` int(10) unsigned DEFAULT '0',
  `updated_at` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order` DESC,`id` DESC)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='相册';

INSERT INTO `yikai_albums` (`id`, `category_id`, `name`, `slug`, `cover`, `description`, `photo_count`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
('1', '0', '荣誉资质', 'honor', 'https://picsum.photos/400/300?random=401', '公司获得的各项荣誉与资质证书', '6', '1', '1', '1776654388', '1776654388');

DROP TABLE IF EXISTS `yikai_article_categories`;
CREATE TABLE `yikai_article_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '父分类ID',
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '分类图片',
  `description` text COMMENT '分类描述',
  `seo_title` varchar(255) NOT NULL DEFAULT '',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '',
  `seo_description` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文章分类';

DROP TABLE IF EXISTS `yikai_articles`;
CREATE TABLE `yikai_articles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '分类ID',
  `title` varchar(255) NOT NULL COMMENT '标题',
  `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
  `slug` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `summary` text COMMENT '摘要',
  `content` longtext COMMENT '内容',
  `author` varchar(50) NOT NULL DEFAULT '' COMMENT '作者',
  `source` varchar(100) NOT NULL DEFAULT '' COMMENT '来源',
  `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签',
  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '置顶',
  `is_recommend` tinyint(1) NOT NULL DEFAULT '0' COMMENT '推荐',
  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '热门',
  `views` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '浏览量',
  `likes` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '点赞数',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0草稿 1发布',
  `publish_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '发布时间',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_publish` (`publish_time`),
  KEY `idx_top` (`is_top`),
  KEY `idx_recommend` (`is_recommend`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='文章表';

DROP TABLE IF EXISTS `yikai_banner_groups`;
CREATE TABLE `yikai_banner_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '分组名称',
  `slug` varchar(50) NOT NULL COMMENT '标识（= banners.position）',
  `height_pc` smallint(5) unsigned NOT NULL DEFAULT '500',
  `height_mobile` smallint(5) unsigned NOT NULL DEFAULT '250',
  `autoplay_delay` int(10) unsigned NOT NULL DEFAULT '5000',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='轮播图分组';

INSERT INTO `yikai_banner_groups` (`id`, `name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES
('1', '首页', 'home', '650', '300', '5000', '0', '1', '1776652898'),
('2', '关于我们', 'about', '500', '250', '5000', '1', '1', '1776652898'),
('3', '产品中心', 'product', '500', '250', '5000', '2', '1', '1776652898'),
('4', '案例展示', 'case', '500', '250', '5000', '3', '1', '1776652898');

DROP TABLE IF EXISTS `yikai_banners`;
CREATE TABLE `yikai_banners` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `position` varchar(50) NOT NULL DEFAULT 'home' COMMENT '位置',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
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
  `start_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '开始时间',
  `end_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '结束时间',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_position` (`position`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_banner_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='轮播图';

INSERT INTO `yikai_banners` (`id`, `position`, `lang`, `title`, `subtitle`, `btn1_text`, `btn1_url`, `btn2_text`, `btn2_url`, `image`, `image_mobile`, `link_url`, `link_target`, `start_time`, `end_time`, `status`, `sort_order`, `created_at`) VALUES
('1', 'home', 'zh-CN', '数字化转型解决方案', '助力企业实现智能化升级', '了解更多', '/about.html', '', '', 'https://picsum.photos/1920/600?random=1', '', '', '_self', '0', '0', '1', '1', '1776652898'),
('2', 'home', 'zh-CN', '专业的技术服务团队', '7x24小时为您保驾护航', '', '', '', '', 'https://picsum.photos/1920/600?random=2', '', '', '_self', '0', '0', '1', '2', '1776652898'),
('3', 'home', 'zh-CN', '创新引领未来', '持续创新，追求卓越', '', '', '', '', 'https://picsum.photos/1920/600?random=3', '', '', '_self', '0', '0', '1', '3', '1776652898');

DROP TABLE IF EXISTS `yikai_brands`;
CREATE TABLE `yikai_brands` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '品牌名',
  `slug` varchar(100) NOT NULL DEFAULT '',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `logo` varchar(255) NOT NULL DEFAULT '' COMMENT '品牌Logo',
  `country` varchar(50) NOT NULL DEFAULT '' COMMENT '国家/产地',
  `description` text COMMENT '品牌介绍',
  `url` varchar(255) NOT NULL DEFAULT '' COMMENT '官网',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='品牌管理';

DROP TABLE IF EXISTS `yikai_channels`;
CREATE TABLE `yikai_channels` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(5) NOT NULL DEFAULT 'ja',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `parent_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '父栏目ID',
  `name` varchar(100) NOT NULL COMMENT '栏目名称',
  `slug` varchar(100) NOT NULL COMMENT 'URL别名',
  `type` varchar(20) NOT NULL DEFAULT 'list' COMMENT '类型：list/page/link/product/case/download/job',
  `album_id` int(10) unsigned DEFAULT '0' COMMENT '关联相册ID',
  `icon` varchar(100) NOT NULL DEFAULT '' COMMENT '图标',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '栏目图片',
  `description` text COMMENT '栏目描述',
  `content` longtext COMMENT '单页内容',
  `link_url` varchar(255) NOT NULL DEFAULT '' COMMENT '外链地址',
  `link_target` varchar(20) NOT NULL DEFAULT '_self' COMMENT '打开方式',
  `redirect_type` varchar(10) NOT NULL DEFAULT 'auto',
  `redirect_url` varchar(255) NOT NULL DEFAULT '',
  `seo_title` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO标题',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
  `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO描述',
  `is_nav` tinyint(1) NOT NULL DEFAULT '1' COMMENT '显示在导航',
  `is_home` tinyint(1) NOT NULL DEFAULT '0' COMMENT '显示在首页',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '系统预设：1是 0否',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='栏目表';

INSERT INTO `yikai_channels` (`id`, `lang`, `translation_group_id`, `parent_id`, `name`, `slug`, `type`, `album_id`, `icon`, `image`, `description`, `content`, `link_url`, `link_target`, `redirect_type`, `redirect_url`, `seo_title`, `seo_keywords`, `seo_description`, `is_nav`, `is_home`, `status`, `is_system`, `sort_order`, `created_at`, `updated_at`) VALUES
('1', 'zh-CN', '0', '0', '关于我们', 'about', 'page', '0', '', '', '了解我们的企业文化与发展历程', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1776652898', '0'),
('2', 'zh-CN', '0', '1', '公司简介', 'company', 'page', '0', '', '', '公司基本情况介绍', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1776652898', '0'),
('3', 'zh-CN', '0', '1', '企业文化', 'culture', 'page', '0', '', '', '企业核心价值观', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1776652898', '0'),
('4', 'zh-CN', '0', '1', '发展历程', 'history', 'page', '0', '', '', '企业发展里程碑', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '3', '1776652898', '0'),
('5', 'zh-CN', '0', '0', '产品中心', 'product', 'product', '0', '', '', '我们的产品与服务', NULL, '', '_self', 'auto', '', '', '', '', '1', '1', '1', '1', '2', '1776652898', '0'),
('6', 'zh-CN', '0', '0', '成功案例', 'cases', 'case', '0', '', '', '客户成功案例展示', NULL, '', '_self', 'auto', '', '', '', '', '1', '1', '1', '1', '3', '1776652898', '0'),
('7', 'zh-CN', '0', '0', '新闻资讯', 'news', 'list', '0', '', '', '最新动态与行业资讯', NULL, '', '_self', 'auto', '', '', '', '', '1', '1', '1', '1', '4', '1776652898', '0'),
('8', 'zh-CN', '0', '7', '公司新闻', 'company-news', 'list', '0', '', '', '公司最新动态', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1776652898', '0'),
('9', 'zh-CN', '0', '7', '行业动态', 'industry-news', 'list', '0', '', '', '行业最新资讯', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1776652898', '0'),
('10', 'zh-CN', '0', '0', '服务支持', 'service', 'page', '0', '', '', '专业的服务与技术支持', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '5', '1776652898', '0'),
('11', 'zh-CN', '0', '10', '服务流程', 'process', 'page', '0', '', '', '标准化服务流程', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1776652898', '0'),
('12', 'zh-CN', '0', '10', '常见问题', 'faq', 'list', '0', '', '', '常见问题解答', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '2', '1776652898', '0'),
('13', 'zh-CN', '0', '0', '下载中心', 'download', 'download', '0', '', '', '资料与软件下载', NULL, '', '_self', 'none', '', '', '', '', '1', '0', '1', '1', '3', '1776652898', '0'),
('14', 'zh-CN', '0', '0', '人才招聘', 'job', 'job', '0', '', '', '加入我们', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '6', '1776652898', '0'),
('15', 'zh-CN', '0', '0', '联系我们', 'contact', 'page', '0', '', '', '联系方式与在线留言', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '7', '1776652898', '0'),
('16', 'zh-CN', '0', '0', '隐私政策', 'privacy', 'page', '0', '', '', '', NULL, '', '_self', 'auto', '', '', '', '', '0', '0', '1', '1', '98', '1776652898', '0'),
('17', 'zh-CN', '0', '0', '服务条款', 'terms', 'page', '0', '', '', '', NULL, '', '_self', 'auto', '', '', '', '', '0', '0', '1', '1', '99', '1776652898', '0'),
('18', 'zh-CN', '0', '1', '荣誉资质', 'honor', 'album', '1', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '4', '1776654080', '0'),
('19', 'zh-CN', '0', '1', '组织架构', 'organization', 'page', '0', '', '', NULL, NULL, '', '_self', 'none', '', '', '', '', '1', '0', '1', '1', '5', '1776654080', '0'),
('20', 'zh-CN', '0', '0', '解决方案', 'solution', 'case', '0', '', '', '行业解决方案', NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '3', '1776654080', '0'),
('21', 'zh-CN', '0', '20', '行业方案', 'industry', 'case', '0', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '1', '1', '1776654080', '0'),
('22', 'zh-CN', '0', '7', '技术分享', 'tech-share', 'list', '0', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '0', '3', '1776654080', '0'),
('23', 'zh-CN', '0', '13', '软件下载', 'software-download', 'download', '0', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '0', '1', '1776654080', '0'),
('24', 'zh-CN', '0', '13', '文档资料', 'document-download', 'download', '0', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '0', '2', '1776654080', '0'),
('25', 'zh-CN', '0', '13', '驱动程序', 'driver-download', 'download', '0', '', '', NULL, NULL, '', '_self', 'auto', '', '', '', '', '1', '0', '1', '0', '3', '1776654080', '0');

DROP TABLE IF EXISTS `yikai_contents`;
CREATE TABLE `yikai_contents` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(5) NOT NULL DEFAULT 'ja',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `channel_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '栏目ID',
  `type` varchar(20) NOT NULL DEFAULT 'article' COMMENT '类型：article/product/case/download/job',
  `title` varchar(255) NOT NULL COMMENT '标题',
  `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
  `slug` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `images` text COMMENT '图片组JSON',
  `summary` text COMMENT '摘要',
  `content` longtext COMMENT '内容',
  `content_type` varchar(10) NOT NULL DEFAULT 'html' COMMENT '内容类型：html/blocks',
  `blocks_data` longtext COMMENT '排版模式JSON数据',
  `author` varchar(50) NOT NULL DEFAULT '' COMMENT '作者',
  `source` varchar(100) NOT NULL DEFAULT '' COMMENT '来源',
  `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签',
  `attachment` varchar(255) NOT NULL DEFAULT '' COMMENT '附件',
  `download_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '下载次数',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价格',
  `specs` text COMMENT '规格JSON',
  `location` varchar(100) NOT NULL DEFAULT '' COMMENT '工作地点',
  `salary` varchar(50) NOT NULL DEFAULT '' COMMENT '薪资范围',
  `requirements` text COMMENT '任职要求',
  `headcount` varchar(20) NOT NULL DEFAULT '',
  `job_type` varchar(20) NOT NULL DEFAULT '',
  `education` varchar(50) NOT NULL DEFAULT '',
  `experience` varchar(50) NOT NULL DEFAULT '',
  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '置顶',
  `is_recommend` tinyint(1) NOT NULL DEFAULT '0' COMMENT '推荐',
  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '热门',
  `views` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '浏览量',
  `likes` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '点赞数',
  `seo_title` varchar(255) NOT NULL DEFAULT '',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '',
  `seo_description` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0草稿 1发布 2归档',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序权重',
  `publish_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '发布时间',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建人',
  `client_name` varchar(100) NOT NULL DEFAULT '' COMMENT '案例：客户名称',
  `industry` varchar(100) NOT NULL DEFAULT '' COMMENT '案例：所属行业',
  `duration` varchar(100) NOT NULL DEFAULT '' COMMENT '案例：项目周期',
  `result_metric` varchar(255) NOT NULL DEFAULT '' COMMENT '案例：核心成果',
  PRIMARY KEY (`id`),
  KEY `idx_channel` (`channel_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_publish` (`publish_time`),
  KEY `idx_top` (`is_top`),
  KEY `idx_recommend` (`is_recommend`),
  KEY `idx_hot` (`is_hot`),
  KEY `idx_lang_status` (`lang`,`status`),
  KEY `idx_trans_group` (`translation_group_id`),
  KEY `idx_sort` (`sort_order`),
  FULLTEXT KEY `ft_search` (`title`,`summary`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='内容表';

INSERT INTO `yikai_contents` (`id`, `lang`, `translation_group_id`, `channel_id`, `type`, `title`, `subtitle`, `slug`, `cover`, `images`, `summary`, `content`, `content_type`, `blocks_data`, `author`, `source`, `tags`, `attachment`, `download_count`, `price`, `specs`, `location`, `salary`, `requirements`, `headcount`, `job_type`, `education`, `experience`, `is_top`, `is_recommend`, `is_hot`, `views`, `likes`, `seo_title`, `seo_keywords`, `seo_description`, `status`, `sort_order`, `publish_time`, `created_at`, `updated_at`, `admin_id`, `client_name`, `industry`, `duration`, `result_metric`) VALUES
('1', 'zh-CN', '0', '8', 'article', '公司荣获年度最佳科技创新奖', '', '', 'https://picsum.photos/800/500?random=201', NULL, '在2024年科技创新大会上，我公司凭借技术实力获此殊荣。', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '1', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('2', 'zh-CN', '0', '8', 'article', '公司与战略合作伙伴签署合作协议', '', '', 'https://picsum.photos/800/500?random=202', NULL, '双方将在智能制造领域展开深度合作。', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('3', 'zh-CN', '0', '9', 'article', '数字化转型趋势报告发布', '', '', 'https://picsum.photos/800/500?random=203', NULL, '报告分析了企业数字化转型的最新趋势和最佳实践。', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('4', 'zh-CN', '0', '9', 'article', 'PHP 8.0 新特性详解', '', '', 'https://picsum.photos/800/500?random=204', NULL, '深入解析PHP 8.0带来的性能提升和新语法特性。', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('5', 'zh-CN', '0', '6', 'case', '某大型制造企业数字化转型项目', '', '', 'https://picsum.photos/800/500?random=301', NULL, '帮助客户实现生产效率提升30%', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '2', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('6', 'zh-CN', '0', '2', 'article', '公司简介', '', '', '', NULL, NULL, '<h2>关于我们</h2>
<p>我们是一家专注于企业数字化转型的科技公司，成立于2010年，总部位于上海。经过十余年的发展，已成为行业内具有影响力的企业之一。</p>
<p>公司拥有一支经验丰富的技术团队，核心成员来自国内外知名企业，在物联网、云计算、人工智能等领域拥有深厚的技术积累。</p>
<h3>我们的使命</h3>
<p>以技术创新驱动企业数字化升级，帮助客户实现智能化运营，提升核心竞争力。</p>
<h3>我们的愿景</h3>
<p>成为企业数字化转型领域最值得信赖的技术合作伙伴。</p>
<h3>核心优势</h3>
<ul>
<li>10+ 年行业深耕经验</li>
<li>1000+ 企业客户信赖</li>
<li>50+ 人专业研发团队</li>
<li>7×24 小时技术支持</li>
</ul>', 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('7', 'zh-CN', '0', '3', 'article', '企业文化', '', '', '', NULL, NULL, '<h2>企业文化</h2>
<h3>核心价值观</h3>
<p><strong>以人为本</strong> — 尊重每一位员工，激发团队潜能，共同成长。</p>
<p><strong>创新驱动</strong> — 持续技术创新，保持行业领先优势。</p>
<p><strong>追求卓越</strong> — 精益求精，以最高标准要求每一个产品和服务。</p>
<p><strong>合作共赢</strong> — 与客户建立长期合作关系，实现互利共赢。</p>
<h3>企业精神</h3>
<p>诚信、专业、高效、创新</p>
<h3>工作理念</h3>
<p>以客户需求为导向，以技术创新为驱动，以团队协作为基础，持续为客户创造价值。</p>', 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('8', 'zh-CN', '0', '15', 'article', '联系我们', '', '', '', NULL, NULL, '<p>欢迎通过以下方式联系我们。</p>', 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('9', 'zh-CN', '0', '16', 'article', '隐私政策', '', '', '', NULL, NULL, '<h2>隐私政策</h2><p>我们重视您的隐私保护。</p>', 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('10', 'zh-CN', '0', '17', 'article', '服务条款', '', '', '', NULL, NULL, '<h2>服务条款</h2><p>欢迎使用我们的网站和服务。</p>', 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776652898', '1776652898', '1776652898', '1', '', '', '', ''),
('11', 'zh-CN', '0', '4', 'article', '发展历程', '', '', '', NULL, NULL, '<h2>发展历程</h2>
<p><strong>2024年</strong> — 发布新一代智能物联网平台，服务客户突破1000家。</p>
<p><strong>2022年</strong> — 获得国家高新技术企业认定，完成B轮融资。</p>
<p><strong>2020年</strong> — 推出企业管理云平台，实现SaaS化服务。</p>
<p><strong>2018年</strong> — 成立研发中心，团队扩展至50人。</p>
<p><strong>2015年</strong> — 产品线扩展至传感器、控制器等硬件领域。</p>
<p><strong>2012年</strong> — 首个物联网项目落地，服务首批企业客户。</p>
<p><strong>2010年</strong> — 公司成立，专注于企业信息化解决方案。</p>', 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776653934', '1776653934', '1776653934', '1', '', '', '', ''),
('12', 'zh-CN', '0', '8', 'article', '公司与战略合作伙伴签署合作协议', '', '', 'https://picsum.photos/800/500?random=205', NULL, '双方将在智能制造领域展开深度合作。', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '1', '0', '0', '0', '', '', '', '1', '0', '1776567680', '1776654080', '1776654080', '1', '', '', '', ''),
('13', 'zh-CN', '0', '8', 'article', '公司参加2024国际物联网博览会', '', '', 'https://picsum.photos/800/500?random=206', NULL, '展示最新智能物联网解决方案。', NULL, 'html', NULL, '', '', '', '', '0', '0.00', NULL, '', '', NULL, '', '', '', '', '0', '0', '0', '0', '0', '', '', '', '1', '0', '1776481280', '1776654080', '1776654080', '1', '', '', '', '');

DROP TABLE IF EXISTS `yikai_download_categories`;
CREATE TABLE `yikai_download_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `description` varchar(255) DEFAULT '' COMMENT '分类描述',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态',
  `created_at` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='下载分类';

DROP TABLE IF EXISTS `yikai_downloads`;
CREATE TABLE `yikai_downloads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned DEFAULT '0' COMMENT '分类ID',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `title` varchar(255) NOT NULL COMMENT '文件名称',
  `description` text COMMENT '文件描述',
  `cover` varchar(500) DEFAULT '' COMMENT '封面图',
  `file_url` varchar(500) DEFAULT '' COMMENT '文件地址(上传或外链)',
  `file_name` varchar(255) DEFAULT '' COMMENT '原始文件名',
  `file_size` bigint(20) unsigned DEFAULT '0' COMMENT '文件大小(字节)',
  `file_ext` varchar(20) DEFAULT '' COMMENT '文件扩展名',
  `download_count` int(10) unsigned DEFAULT '0' COMMENT '下载次数',
  `is_external` tinyint(1) DEFAULT '0' COMMENT '是否外链:0本地,1外链',
  `require_login` tinyint(1) NOT NULL DEFAULT '0' COMMENT '下载条件：0游客 1需登录',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序(越大越靠前)',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态:1显示,0隐藏',
  `created_at` int(10) unsigned DEFAULT '0',
  `updated_at` int(10) unsigned DEFAULT '0',
  `admin_id` int(10) unsigned DEFAULT '0' COMMENT '创建人',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order` DESC,`id` DESC),
  KEY `idx_dl_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='下载管理';

INSERT INTO `yikai_downloads` (`id`, `category_id`, `lang`, `translation_group_id`, `title`, `description`, `cover`, `file_url`, `file_name`, `file_size`, `file_ext`, `download_count`, `is_external`, `require_login`, `sort_order`, `status`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', '0', 'zh-CN', '0', '产品使用手册 V2.0', '最新版产品使用说明书', '', '', '', '0', 'pdf', '0', '0', '0', '0', '1', '1776652898', '1776652898', '0'),
('2', '0', 'zh-CN', '0', '客户端软件 V3.5.1', '适用于Windows系统的客户端软件', '', '', '', '0', 'exe', '0', '0', '0', '0', '1', '1776652898', '1776652898', '0'),
('3', '0', 'zh-CN', '0', 'API接口文档', '完整的API接口说明文档', '', '', '', '0', 'pdf', '0', '0', '0', '0', '1', '1776652898', '1776652898', '0');

DROP TABLE IF EXISTS `yikai_extfields`;
CREATE TABLE `yikai_extfields` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(30) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `field_name` varchar(100) NOT NULL,
  `field_type` varchar(20) NOT NULL DEFAULT 'text',
  `options` text,
  `placeholder` varchar(255) NOT NULL DEFAULT '',
  `help_text` varchar(255) NOT NULL DEFAULT '',
  `is_required` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_key` (`owner_type`,`field_key`),
  KEY `idx_owner` (`owner_type`,`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='扩展字段定义表';

DROP TABLE IF EXISTS `yikai_form_templates`;
CREATE TABLE `yikai_form_templates` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '表单名称',
  `slug` varchar(50) NOT NULL COMMENT '短码标识',
  `fields` text COMMENT '字段配置JSON',
  `success_message` varchar(255) NOT NULL DEFAULT '提交成功，感谢您的反馈！' COMMENT '成功提示',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='表单模板';

INSERT INTO `yikai_form_templates` (`id`, `name`, `slug`, `fields`, `success_message`, `status`, `created_at`) VALUES
('1', '产品询盘', 'product-inquiry', '[{\"key\":\"name\",\"label\":\"您的姓名\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"联系电话\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"电子邮箱\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"公司名称\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"咨询内容\",\"type\":\"textarea\",\"required\":true}]', '提交成功，我们会尽快与您联系！', '1', '1776653097'),
('2', '联系表单', 'contact', '[{\"key\":\"name\",\"label\":\"您的姓名\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"联系电话\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"电子邮箱\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"公司名称\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"留言内容\",\"type\":\"textarea\",\"required\":true}]', '提交成功，我们会尽快与您联系！', '1', '1776656141');

DROP TABLE IF EXISTS `yikai_forms`;
CREATE TABLE `yikai_forms` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL DEFAULT 'contact' COMMENT '类型：contact/apply/custom',
  `product_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '关联产品ID',
  `product_title` varchar(255) NOT NULL DEFAULT '' COMMENT '产品名称快照',
  `source` varchar(30) NOT NULL DEFAULT 'contact' COMMENT '来源: contact/product/custom',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '姓名',
  `phone` varchar(20) NOT NULL DEFAULT '' COMMENT '电话',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `company` varchar(100) NOT NULL DEFAULT '' COMMENT '公司',
  `content` text COMMENT '内容',
  `extra` text COMMENT '额外字段JSON',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0待处理 1已处理 2无效',
  `follow_admin` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '跟进人',
  `follow_note` text COMMENT '跟进备注',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_product` (`product_id`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='表单数据';

DROP TABLE IF EXISTS `yikai_jobs`;
CREATE TABLE `yikai_jobs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT '职位名称',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
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
  `views` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '浏览量',
  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：1招聘中 0已关闭',
  `publish_time` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建人',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_top` (`is_top` DESC,`sort_order` DESC,`id` DESC),
  KEY `idx_job_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='招聘管理';

INSERT INTO `yikai_jobs` (`id`, `title`, `lang`, `translation_group_id`, `cover`, `summary`, `content`, `location`, `salary`, `job_type`, `education`, `experience`, `headcount`, `requirements`, `views`, `is_top`, `sort_order`, `status`, `publish_time`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', 'PHP高级工程师', 'zh-CN', '0', '', '负责公司核心产品的后端开发', NULL, '上海（可远程）', '25-40K', '全职', '本科', '3年以上', '2', '熟悉PHP 8.0+
熟悉MySQL
有CMS开发经验优先', '1', '0', '0', '1', '1776652898', '1776652898', '1776652898', '0'),
('2', '前端开发工程师', 'zh-CN', '0', '', '负责公司产品的前端界面开发', NULL, '上海（可远程）', '20-35K', '全职', '本科', '2年以上', '1', '熟悉Vue/React
熟悉Tailwind CSS
注重代码质量', '2', '0', '0', '1', '1776652898', '1776652898', '1776652898', '0');

DROP TABLE IF EXISTS `yikai_links`;
CREATE TABLE `yikai_links` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `name` varchar(100) NOT NULL COMMENT '名称',
  `url` varchar(255) NOT NULL COMMENT '链接',
  `logo` varchar(255) NOT NULL DEFAULT '' COMMENT 'Logo',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '描述',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_lk_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='友情链接';

INSERT INTO `yikai_links` (`id`, `lang`, `name`, `url`, `logo`, `description`, `status`, `sort_order`, `created_at`) VALUES
('1', 'zh-CN', '百度', 'https://www.baidu.com', '', '', '1', '1', '1776652898'),
('2', 'zh-CN', '阿里云', 'https://www.aliyun.com', '', '', '1', '2', '1776652898'),
('3', 'zh-CN', '腾讯云', 'https://cloud.tencent.com', '', '', '1', '3', '1776652898');

DROP TABLE IF EXISTS `yikai_media`;
CREATE TABLE `yikai_media` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL COMMENT '文件名',
  `path` varchar(255) NOT NULL COMMENT '存储路径',
  `url` varchar(255) NOT NULL COMMENT '访问URL',
  `type` varchar(20) NOT NULL DEFAULT 'image' COMMENT '类型：image/file/video',
  `ext` varchar(20) NOT NULL DEFAULT '' COMMENT '扩展名',
  `mime` varchar(100) NOT NULL DEFAULT '' COMMENT 'MIME类型',
  `size` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '文件大小',
  `width` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '图片宽度',
  `height` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '图片高度',
  `md5` varchar(32) NOT NULL DEFAULT '' COMMENT 'MD5',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_md5` (`md5`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='媒体库';

DROP TABLE IF EXISTS `yikai_members`;
CREATE TABLE `yikai_members` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL DEFAULT '',
  `nickname` varchar(50) NOT NULL DEFAULT '',
  `avatar` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_time` int(11) unsigned NOT NULL DEFAULT '0',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '',
  `login_count` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='前台会员表';

DROP TABLE IF EXISTS `yikai_metas`;
CREATE TABLE `yikai_metas` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(30) NOT NULL COMMENT '归属类型',
  `owner_id` int(11) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(100) NOT NULL,
  `meta_value` longtext,
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_owner_key` (`owner_type`,`owner_id`,`meta_key`),
  KEY `idx_owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='通用元数据键值表';

DROP TABLE IF EXISTS `yikai_plugins`;
CREATE TABLE `yikai_plugins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL COMMENT '插件标识',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0禁用 1启用',
  `installed_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '安装时间',
  `activated_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '启用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='插件表';

DROP TABLE IF EXISTS `yikai_product_categories`;
CREATE TABLE `yikai_product_categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '父分类ID',
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `image` varchar(255) NOT NULL DEFAULT '' COMMENT '分类图片',
  `description` text COMMENT '分类描述',
  `seo_title` varchar(255) NOT NULL DEFAULT '',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '',
  `seo_description` varchar(500) NOT NULL DEFAULT '',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `is_nav` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否在导航显示',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_pc_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='产品分类';

INSERT INTO `yikai_product_categories` (`id`, `parent_id`, `name`, `slug`, `lang`, `translation_group_id`, `image`, `description`, `seo_title`, `seo_keywords`, `seo_description`, `status`, `is_nav`, `sort_order`, `created_at`) VALUES
('1', '0', '智能设备', 'smart-device', 'zh-CN', '0', '', NULL, '', '', '', '1', '1', '1', '1776652898'),
('2', '0', '软件服务', 'software', 'zh-CN', '0', '', NULL, '', '', '', '1', '1', '2', '1776652898'),
('3', '1', '传感器模块', 'sensor-module', 'zh-CN', '0', '', NULL, '', '', '', '1', '1', '1', '1776652898'),
('4', '1', '控制终端', 'control-terminal', 'zh-CN', '0', '', NULL, '', '', '', '1', '1', '2', '1776652898');

DROP TABLE IF EXISTS `yikai_product_tag_map`;
CREATE TABLE `yikai_product_tag_map` (
  `product_id` int(11) unsigned NOT NULL,
  `tag_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`product_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='产品标签关联';

DROP TABLE IF EXISTS `yikai_product_tags`;
CREATE TABLE `yikai_product_tags` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(50) NOT NULL COMMENT '标签组',
  `name` varchar(100) NOT NULL COMMENT '标签名',
  `slug` varchar(100) NOT NULL DEFAULT '',
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='产品标签';

DROP TABLE IF EXISTS `yikai_products`;
CREATE TABLE `yikai_products` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(5) NOT NULL DEFAULT 'ja',
  `translation_group_id` int(11) unsigned NOT NULL DEFAULT '0',
  `category_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '分类ID',
  `brand_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '品牌ID',
  `title` varchar(255) NOT NULL COMMENT '产品名称',
  `subtitle` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题',
  `slug` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL别名',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `images` text COMMENT '产品图片JSON',
  `summary` text COMMENT '简介',
  `content` longtext COMMENT '详情',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '价格',
  `market_price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '市场价',
  `model` varchar(100) NOT NULL DEFAULT '' COMMENT '型号',
  `specs` text COMMENT '规格参数JSON',
  `tags` varchar(255) NOT NULL DEFAULT '' COMMENT '标签',
  `is_top` tinyint(1) NOT NULL DEFAULT '0' COMMENT '置顶',
  `is_recommend` tinyint(1) NOT NULL DEFAULT '0' COMMENT '推荐',
  `is_hot` tinyint(1) NOT NULL DEFAULT '0' COMMENT '热门',
  `is_new` tinyint(1) NOT NULL DEFAULT '0' COMMENT '新品',
  `views` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '浏览量',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0下架 1上架',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  `admin_id` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_top` (`is_top`),
  KEY `idx_recommend` (`is_recommend`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_lang_status` (`lang`,`status`),
  KEY `idx_trans_group` (`translation_group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='产品表';

INSERT INTO `yikai_products` (`id`, `lang`, `translation_group_id`, `category_id`, `brand_id`, `title`, `subtitle`, `slug`, `cover`, `images`, `summary`, `content`, `price`, `market_price`, `model`, `specs`, `tags`, `is_top`, `is_recommend`, `is_hot`, `is_new`, `views`, `status`, `sort_order`, `created_at`, `updated_at`, `admin_id`) VALUES
('1', 'zh-CN', '0', '1', '0', '智能物联网网关', '', '', 'https://picsum.photos/600/600?random=101', NULL, '多协议兼容，边缘计算能力', '<h2>产品概述</h2>
<p>智能物联网网关是一款高性能的边缘计算网关设备，支持多种通信协议（MQTT、HTTP、Modbus、OPC UA），可实现设备数据的实时采集、边缘计算和云端同步。</p>
<h3>核心特点</h3>
<ul>
<li>支持 Wi-Fi/4G/以太网多种联网方式</li>
<li>内置边缘计算引擎，支持本地数据处理</li>
<li>兼容 100+ 种工业协议</li>
<li>工业级设计，-40°C ~ 85°C 宽温工作</li>
</ul>
<h3>应用场景</h3>
<p>智能工厂、智慧农业、智慧城市、能源管理等领域。</p>', '0.00', '0.00', 'IoT-GW-100', NULL, '', '0', '0', '0', '0', '1', '1', '1', '1776652898', '1776652898', '0'),
('2', 'zh-CN', '0', '2', '0', '企业管理云平台', '', '', 'https://picsum.photos/600/600?random=102', NULL, '集成ERP/CRM/OA功能', '<h2>产品概述</h2>
<p>企业管理云平台是一站式企业数字化管理解决方案，集成 ERP、CRM、OA 三大核心模块，帮助企业实现业务流程数字化。</p>
<h3>功能模块</h3>
<ul>
<li><strong>ERP</strong> — 采购、库存、生产、财务一体化管理</li>
<li><strong>CRM</strong> — 客户管理、销售漏斗、业绩分析</li>
<li><strong>OA</strong> — 审批流程、日程管理、即时通讯</li>
</ul>
<h3>技术优势</h3>
<p>基于微服务架构，支持私有化部署和 SaaS 模式，数据安全有保障。</p>', '0.00', '0.00', 'Cloud-ERP', NULL, '', '0', '0', '0', '0', '0', '1', '2', '1776652898', '1776652898', '0'),
('3', 'zh-CN', '0', '3', '0', '温湿度传感器 TH-200', '', '', 'https://picsum.photos/600/600?random=103', NULL, '瑞士芯片，精度±0.1°C', '<h2>产品概述</h2>
<p>TH-200 温湿度传感器采用瑞士进口高精度芯片，精度达 ±0.1°C / ±1.5%RH，适用于工业环境监测、仓储管理、智慧农业等场景。</p>
<h3>技术参数</h3>
<ul>
<li>温度范围：-40°C ~ 125°C</li>
<li>湿度范围：0 ~ 100%RH</li>
<li>通信接口：RS485 / Modbus RTU</li>
<li>供电方式：DC 12-24V</li>
<li>防护等级：IP65</li>
</ul>', '0.00', '0.00', 'TH-200', NULL, '', '0', '0', '0', '0', '0', '1', '3', '1776652898', '1776652898', '0'),
('4', 'zh-CN', '0', '3', '0', '光照传感器 LS-100', '', '', 'https://picsum.photos/600/600?random=104', NULL, '检测范围0-200000Lux', '<h2>产品概述</h2>
<p>LS-100 光照传感器检测范围 0-200,000 Lux，采用高灵敏度光电二极管，响应速度快，线性度好。</p>
<h3>技术参数</h3>
<ul>
<li>测量范围：0 ~ 200,000 Lux</li>
<li>精度：±3%</li>
<li>通信接口：RS485 / Modbus</li>
<li>供电方式：DC 5-24V</li>
</ul>
<h3>应用场景</h3>
<p>智慧农业、气象观测、智能照明控制。</p>', '0.00', '0.00', 'LS-100', NULL, '', '0', '0', '0', '0', '0', '1', '4', '1776652898', '1776652898', '0'),
('5', 'zh-CN', '0', '4', '0', '工业边缘控制器 EC-500', '', '', 'https://picsum.photos/600/600?random=105', NULL, 'ARM Cortex-A72，支持AI推理', '<h2>产品概述</h2>
<p>EC-500 工业边缘控制器搭载 ARM Cortex-A72 处理器，支持多种工业协议和 AI 模型本地推理，是智能工厂的核心控制单元。</p>
<h3>核心特点</h3>
<ul>
<li>四核 ARM Cortex-A72，主频 1.8GHz</li>
<li>4GB RAM / 32GB eMMC 存储</li>
<li>支持 TensorFlow Lite / ONNX 推理</li>
<li>丰富 I/O 接口：4×RS485、2×CAN、4×DI、4×DO</li>
</ul>', '0.00', '0.00', 'EC-500', NULL, '', '0', '0', '0', '0', '1', '1', '5', '1776652898', '1776652898', '0'),
('6', 'zh-CN', '0', '4', '0', '智能网关控制器 GC-300', '', '', 'https://picsum.photos/600/600?random=106', NULL, 'Wi-Fi/Zigbee/LoRa/4G多协议', '<h2>产品概述</h2>
<p>GC-300 智能网关控制器支持 Wi-Fi/Zigbee/LoRa/4G 四种无线协议同时工作，内置边缘计算模块，实现设备统一管理。</p>
<h3>核心特点</h3>
<ul>
<li>四协议同时在线，最大接入 500 个终端</li>
<li>内置边缘计算引擎</li>
<li>支持 OTA 远程升级</li>
<li>Web 管理界面，零代码配置</li>
</ul>
<h3>应用场景</h3>
<p>智慧楼宇、智能家居、工业物联网。</p>', '0.00', '0.00', 'GC-300', NULL, '', '0', '0', '0', '0', '0', '1', '6', '1776652898', '1776652898', '0');

DROP TABLE IF EXISTS `yikai_roles`;
CREATE TABLE `yikai_roles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '角色描述',
  `permissions` text COMMENT '权限JSON',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='角色表';

INSERT INTO `yikai_roles` (`id`, `name`, `description`, `permissions`, `status`, `created_at`) VALUES
('1', '超级管理员', '拥有全部权限', '[\"*\"]', '1', '1776652898'),
('2', '编辑', '内容编辑权限', '[\"content\",\"media\"]', '1', '1776652898'),
('3', '运营', '运营管理权限', '[\"content\",\"media\",\"form\",\"banner\",\"link\"]', '1', '1776652898');

DROP TABLE IF EXISTS `yikai_settings`;
CREATE TABLE `yikai_settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `group` varchar(50) NOT NULL DEFAULT 'basic' COMMENT '分组',
  `key` varchar(100) NOT NULL COMMENT '键名',
  `value` text COMMENT '值',
  `type` varchar(20) NOT NULL DEFAULT 'text' COMMENT '类型：text/textarea/number/select/image/editor',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '显示名称',
  `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '提示',
  `options` text COMMENT '选项JSON',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='配置表';

INSERT INTO `yikai_settings` (`id`, `group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
('1', 'basic', 'site_url', '', 'text', '站点域名', '如 https://www.example.com', NULL, '0'),
('2', 'basic', 'site_name', 'Yikai CMS', 'text', '站点名称', '', NULL, '1'),
('3', 'basic', 'site_keywords', '企业官网,CMS,内容管理', 'textarea', 'SEO关键词', '多个关键词用逗号分隔', NULL, '2'),
('4', 'basic', 'site_description', '专业的企业内容管理系统，助力企业数字化转型', 'textarea', 'SEO描述', '', NULL, '3'),
('5', 'basic', 'site_logo', '/images/logo.png', 'image', '站点Logo', '', NULL, '4'),
('6', 'basic', 'site_favicon', '/favicon.ico', 'image', '站点图标', '', NULL, '5'),
('7', 'basic', 'primary_color', '#3B82F6', 'color', '主题色', '', NULL, '6'),
('8', 'basic', 'secondary_color', '#1D4ED8', 'color', '辅助色', '', NULL, '7'),
('9', 'basic', 'site_icp', '', 'text', 'ICP备案号', '', NULL, '10'),
('10', 'basic', 'site_police', '', 'text', '公安备案号', '', NULL, '11'),
('11', 'basic', 'admin_title', '后台管理', 'text', '后台名称', '后台左上角显示的名称', NULL, '20'),
('12', 'basic', 'admin_logo', '', 'image', '后台Logo', '留空显示文字', NULL, '21'),
('13', 'basic', 'admin_copyright', '', 'text', '后台版权', '留空不显示', NULL, '22'),
('14', 'header', 'topbar_enabled', '0', 'select', '顶部通栏', '', '{\"0\":\"隐藏\",\"1\":\"显示\"}', '0'),
('15', 'header', 'topbar_bg_color', '#f3f4f6', 'color', '通栏背景色', '', NULL, '1'),
('16', 'header', 'topbar_left', '', 'code', '通栏左侧内容', '', NULL, '2'),
('17', 'header', 'show_member_entry', '0', 'select', '会员入口', '', '{\"0\":\"隐藏\",\"1\":\"显示\"}', '3'),
('18', 'header', 'header_nav_layout', 'right', 'select', '导航布局', '', '{\"right\":\"Logo右侧\",\"below\":\"Logo下方通栏\"}', '10'),
('19', 'header', 'header_sticky', '0', 'select', '固定顶部', '', '{\"1\":\"是\",\"0\":\"否\"}', '11'),
('20', 'header', 'header_bg_color', '#ffffff', 'color', '背景颜色', '', NULL, '12'),
('21', 'header', 'header_text_color', '#4b5563', 'color', '文字颜色', '', NULL, '13'),
('22', 'footer', 'footer_columns', '[{\"title\":\"关于我们\",\"content\":\"{{site_description}}\",\"col_span\":2},{\"title\":\"联系方式\",\"content\":\"{{contact_info}}\",\"col_span\":1},{\"title\":\"关注我们\",\"content\":\"{{qrcode}}\",\"col_span\":1}]', 'footer_columns', '页脚栏目', '', NULL, '1'),
('23', 'footer', 'footer_bg_color', '#1f2937', 'color', '背景颜色', '', NULL, '2'),
('24', 'footer', 'footer_bg_image', '', 'image', '背景图片', '', NULL, '3'),
('25', 'footer', 'footer_text_color', '#9ca3af', 'color', '文字颜色', '', NULL, '4'),
('26', 'footer', 'footer_nav', '[{\"title\":\"\",\"links\":[{\"name\":\"隐私政策\",\"url\":\"/privacy.html\"},{\"name\":\"服务条款\",\"url\":\"/terms.html\"}]}]', 'footer_nav', '页脚导航', '', NULL, '5'),
('27', 'footer', 'footer_copyright_text', '© {year} {site_name} 版权所有.', 'text', '版权文字', '{year}=年份 {site_name}=站点名', NULL, '6'),
('28', 'code', 'custom_head_code', '', 'code', 'Head代码', '', NULL, '1'),
('29', 'code', 'custom_body_code', '', 'code', 'Body代码', '', NULL, '2'),
('30', 'contact', 'contact_cards', '[{\"icon\":\"phone\",\"label\":\"联系电话\",\"value\":\"400-888-8888\"},{\"icon\":\"email\",\"label\":\"电子邮箱\",\"value\":\"contact@example.com\"},{\"icon\":\"location\",\"label\":\"公司地址\",\"value\":\"上海市浦东新区XX路XX号\"}]', 'contact_cards', '联系卡片', '', NULL, '0'),
('31', 'contact', 'contact_phone', '400-888-8888', 'text', '联系电话', '', NULL, '1'),
('32', 'contact', 'contact_email', 'contact@example.com', 'text', '联系邮箱', '', NULL, '2'),
('33', 'contact', 'contact_address', '上海市浦东新区XX路XX号', 'textarea', '联系地址', '', NULL, '3'),
('34', 'contact', 'contact_qrcode', '', 'image', '二维码', '', NULL, '4'),
('35', 'contact', 'contact_map', '', 'image', '地图图片', '', NULL, '5'),
('36', 'contact', 'contact_form_title', '在线留言', 'text', '表单标题', '', NULL, '10'),
('37', 'contact', 'contact_form_desc', '', 'textarea', '表单描述', '', NULL, '11'),
('38', 'contact', 'contact_form_fields', '[{\"key\":\"name\",\"label\":\"您的姓名\",\"type\":\"text\",\"required\":true,\"enabled\":true},{\"key\":\"phone\",\"label\":\"联系电话\",\"type\":\"tel\",\"required\":true,\"enabled\":true},{\"key\":\"email\",\"label\":\"电子邮箱\",\"type\":\"email\",\"required\":false,\"enabled\":true},{\"key\":\"company\",\"label\":\"公司名称\",\"type\":\"text\",\"required\":false,\"enabled\":true},{\"key\":\"content\",\"label\":\"留言内容\",\"type\":\"textarea\",\"required\":true,\"enabled\":true}]', 'contact_form_fields', '表单字段', '', NULL, '12'),
('39', 'contact', 'contact_form_success', '提交成功，我们会尽快与您联系！', 'text', '提交成功提示', '', NULL, '13'),
('40', 'home', 'home_about_content', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。', 'textarea', '关于我们简介', '', NULL, '1'),
('41', 'home', 'home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 'image', '关于我们图片', '', NULL, '2'),
('42', 'home', 'home_about_tag_title', '专业服务', 'text', '角标标题', '', NULL, '3'),
('43', 'home', 'home_about_tag_desc', '品质 · 创新 · 共赢', 'text', '角标描述', '', NULL, '4'),
('44', 'home', 'home_about_layout', 'text_left', 'select', '关于我们布局', '', '{\"text_left\":\"左文右图\",\"image_left\":\"左图右文\"}', '6'),
('45', 'home', 'home_stat_1_num', '10+', 'text', '统计数字1', '', NULL, '5'),
('46', 'home', 'home_stat_1_text', '年行业经验', 'text', '统计文字1', '', NULL, '6'),
('47', 'home', 'home_stat_2_num', '1000+', 'text', '统计数字2', '', NULL, '7'),
('48', 'home', 'home_stat_2_text', '服务客户', 'text', '统计文字2', '', NULL, '8'),
('49', 'home', 'home_stat_3_num', '50+', 'text', '统计数字3', '', NULL, '9'),
('50', 'home', 'home_stat_3_text', '专业团队', 'text', '统计文字3', '', NULL, '10'),
('51', 'home', 'home_stat_4_num', '100%', 'text', '统计数字4', '', NULL, '11'),
('52', 'home', 'home_stat_4_text', '客户满意', 'text', '统计文字4', '', NULL, '12'),
('53', 'home', 'home_stat_bg', '', 'image', '统计区背景图', '', NULL, '12'),
('54', 'home', 'home_advantage_desc', '专业团队，优质服务，值得信赖', 'text', '优势区块描述', '', NULL, '13'),
('55', 'home', 'home_adv_1_icon', 'check-circle', 'icon', '优势1图标', '', NULL, '14'),
('56', 'home', 'home_adv_1_title', '品质保证', 'text', '优势1标题', '', NULL, '14'),
('57', 'home', 'home_adv_1_desc', '严格把控产品质量，确保每一件产品都符合标准', 'text', '优势1描述', '', NULL, '15'),
('58', 'home', 'home_adv_2_icon', 'academic-cap', 'icon', '优势2图标', '', NULL, '16'),
('59', 'home', 'home_adv_2_title', '技术领先', 'text', '优势2标题', '', NULL, '16'),
('60', 'home', 'home_adv_2_desc', '持续研发创新，保持技术的领先优势', 'text', '优势2描述', '', NULL, '17'),
('61', 'home', 'home_adv_3_icon', 'briefcase', 'icon', '优势3图标', '', NULL, '18'),
('62', 'home', 'home_adv_3_title', '专业服务', 'text', '优势3标题', '', NULL, '18'),
('63', 'home', 'home_adv_3_desc', '专业团队7x24小时技术支持服务', 'text', '优势3描述', '', NULL, '19'),
('64', 'home', 'home_adv_4_icon', 'users', 'icon', '优势4图标', '', NULL, '20'),
('65', 'home', 'home_adv_4_title', '合作共赢', 'text', '优势4标题', '', NULL, '20'),
('66', 'home', 'home_adv_4_desc', '与客户建立长期合作关系，实现互利共赢', 'text', '优势4描述', '', NULL, '21'),
('67', 'home', 'home_cta_title', '准备好开始合作了吗？', 'text', 'CTA标题', '', NULL, '22'),
('68', 'home', 'home_cta_desc', '联系我们，获取专业的解决方案', 'text', 'CTA描述', '', NULL, '23'),
('69', 'home', 'home_show_links', '1', 'select', '显示合作伙伴', '', NULL, '24'),
('70', 'home', 'home_links_title', '合作伙伴', 'text', '链接区块标题', '', NULL, '25'),
('71', 'home', 'home_testimonials', '[{\"avatar\":\"\",\"name\":\"张先生\",\"company\":\"某科技有限公司\",\"content\":\"非常专业的服务团队，合作非常愉快！\"},{\"avatar\":\"\",\"name\":\"李女士\",\"company\":\"某贸易公司\",\"content\":\"产品质量优秀，售后服务及时，值得信赖。\"},{\"avatar\":\"\",\"name\":\"王总\",\"company\":\"某集团公司\",\"content\":\"多年合作，一直保持高品质的服务水准！\"}]', 'home_testimonials', '客户评价', '', NULL, '26'),
('72', 'home', 'home_testimonials_title', '客户评价', 'text', '评价区标题', '', NULL, '27'),
('73', 'home', 'home_testimonials_desc', '听听合作伙伴怎么说', 'text', '评价区描述', '', NULL, '28'),
('74', 'home', 'home_show_banner', '1', 'select', '显示轮播图', '', NULL, '30'),
('75', 'home', 'home_show_about', '1', 'select', '显示关于我们', '', NULL, '31'),
('76', 'home', 'home_show_stats', '1', 'select', '显示数据统计', '', NULL, '32'),
('77', 'home', 'home_show_channels', '1', 'select', '显示栏目区块', '', NULL, '33'),
('78', 'home', 'home_show_advantage', '1', 'select', '显示优势展示', '', NULL, '34'),
('79', 'home', 'home_show_cta', '1', 'select', '显示行动号召', '', NULL, '35'),
('80', 'home', 'home_blocks_config', '[{\"type\":\"banner\",\"enabled\":true},{\"type\":\"about\",\"enabled\":true},{\"type\":\"stats\",\"enabled\":true},{\"type\":\"channels\",\"enabled\":true},{\"type\":\"testimonials\",\"enabled\":true},{\"type\":\"advantage\",\"enabled\":true},{\"type\":\"cta\",\"enabled\":true}]', 'home_blocks', '首页区块配置', '', NULL, '40'),
('81', 'email', 'smtp_host', '', 'text', 'SMTP服务器', '', NULL, '1'),
('82', 'email', 'smtp_port', '465', 'text', 'SMTP端口', '', NULL, '2'),
('83', 'email', 'smtp_secure', 'ssl', 'text', '加密方式', '', NULL, '3'),
('84', 'email', 'smtp_user', '', 'text', 'SMTP用户名', '', NULL, '4'),
('85', 'email', 'smtp_pass', '', 'text', 'SMTP密码', '', NULL, '5'),
('86', 'email', 'mail_from', '', 'text', '发件人邮箱', '', NULL, '6'),
('87', 'email', 'mail_from_name', '', 'text', '发件人名称', '', NULL, '7'),
('88', 'email', 'mail_admin', '', 'text', '管理员邮箱', '', NULL, '8'),
('89', 'email', 'mail_notify_form', '0', 'text', '表单提交通知', '', NULL, '9'),
('90', 'email', 'mail_tpl_register_subject', '欢迎注册 — {{site_name}}', 'text', '注册邮件标题', '', NULL, '20'),
('91', 'email', 'mail_tpl_register_body', '{{username}}，您好！
欢迎注册 {{site_name}}！
{{site_url}}/member/
{{site_name}} {{date}}', 'textarea', '注册邮件内容', '', NULL, '21'),
('92', 'email', 'mail_tpl_forgot_subject', '密码找回 — {{site_name}}', 'text', '找回密码标题', '', NULL, '22'),
('93', 'email', 'mail_tpl_forgot_body', '{{username}}，您好！
请点击以下链接重置密码：
{{reset_link}}
链接30分钟有效。
{{site_name}} {{date}}', 'textarea', '找回密码内容', '', NULL, '23'),
('94', 'email', 'mail_tpl_reset_subject', '密码已重置 — {{site_name}}', 'text', '密码重置标题', '', NULL, '24'),
('95', 'email', 'mail_tpl_reset_body', '{{username}}，您好！
您的密码已成功重置。
{{site_name}} {{date}}', 'textarea', '密码重置内容', '', NULL, '25'),
('96', 'email', 'mail_tpl_inquiry_subject', '新询盘：{{product_title}} — {{site_name}}', 'text', '询盘通知标题', '', NULL, '26'),
('97', 'email', 'mail_tpl_inquiry_body', '产品：{{product_title}}
姓名：{{name}}
电话：{{phone}}
邮箱：{{email}}
公司：{{company}}
内容：{{content}}
时间：{{date}}
IP：{{ip}}', 'textarea', '询盘通知内容', '', NULL, '27'),
('98', 'product', 'product_layout', 'sidebar', 'select', '产品列表版式', '', '{\"sidebar\":\"侧栏模式\",\"top\":\"顶栏模式\"}', '1'),
('99', 'product', 'show_price', '0', 'select', '显示产品价格', '', '{\"0\":\"隐藏\",\"1\":\"显示\"}', '2'),
('100', 'product', 'product_sort_options', '[\"default\",\"newest\",\"views\"]', 'text', '可用排序选项', '', NULL, '3');

INSERT INTO `yikai_settings` (`id`, `group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
('101', 'product', 'product_default_sort', 'default', 'select', '产品默认排序', '', '{\"default\":\"默认\",\"newest\":\"最新\",\"views\":\"浏览量\"}', '4'),
('102', 'banner', 'banner_height_pc', '650', 'number', '轮播图高度(PC)', '', NULL, '1'),
('103', 'banner', 'banner_height_mobile', '300', 'number', '轮播图高度(移动)', '', NULL, '2'),
('104', 'member', 'allow_member_register', '0', 'switch', '允许会员注册', '', NULL, '1'),
('105', 'member', 'download_require_login', '0', 'switch', '下载需要登录', '', NULL, '2'),
('106', 'social', 'social_links', '[]', 'social_links', '社交媒体链接', '', NULL, '1'),
('107', 'system', 'current_theme', 'default', 'text', '当前主题', '', NULL, '0'),
('108', 'system', 'cms_version', '1.4.2', 'text', 'CMS版本号', '', NULL, '1'),
('109', 'system', 'site_lang', 'zh-CN', 'text', '站点语言', '', NULL, '2'),
('110', 'system', 'admin_lang', 'zh-CN', 'text', '后台语言', '', NULL, '3'),
('111', 'basic', 'html_cache_enabled', '1', 'select', 'HTML缓存', '', '{\"0\":\"关闭\",\"1\":\"开启\"}', '15'),
('112', 'basic', 'html_cache_ttl', '300', 'number', '缓存有效期', '秒', NULL, '16'),
('113', 'basic', 'timeline_sort', 'asc', 'text', 'timeline_sort', '', NULL, '0');

DROP TABLE IF EXISTS `yikai_timelines`;
CREATE TABLE `yikai_timelines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
  `year` smallint(5) unsigned NOT NULL COMMENT '年份',
  `month` tinyint(3) unsigned DEFAULT '0' COMMENT '月份(0表示仅显示年)',
  `day` tinyint(3) unsigned DEFAULT '0' COMMENT '日期(0表示不显示)',
  `title` varchar(200) NOT NULL COMMENT '标题',
  `content` text COMMENT '内容描述',
  `image` varchar(500) DEFAULT '' COMMENT '配图',
  `icon` varchar(50) DEFAULT '' COMMENT '图标(可选)',
  `color` varchar(20) DEFAULT '' COMMENT '颜色标记',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序(越大越靠前)',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态:1显示,0隐藏',
  `created_at` int(10) unsigned DEFAULT '0',
  `updated_at` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_year` (`year`),
  KEY `idx_status` (`status`),
  KEY `idx_sort` (`sort_order` DESC,`year` DESC,`month` DESC),
  KEY `idx_tl_lang` (`lang`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='发展历程时间线';

INSERT INTO `yikai_timelines` (`id`, `lang`, `year`, `month`, `day`, `title`, `content`, `image`, `icon`, `color`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
('1', 'zh-CN', '2024', '1', '0', '智能物联网平台发布', '发布新一代智能物联网平台，集成AI边缘计算能力，服务客户突破1000家。', '', 'rocket', '#3B82F6', '1', '1', '1776654208', '1776654208'),
('2', 'zh-CN', '2022', '6', '0', '国家高新技术企业认定', '通过国家高新技术企业认定，完成B轮融资，估值突破5亿。', '', 'star', '#10B981', '2', '1', '1776654208', '1776654208'),
('3', 'zh-CN', '2020', '3', '0', '企业管理云平台上线', '推出企业管理云平台，实现ERP/CRM/OA一体化SaaS服务。', '', 'cloud', '#8B5CF6', '3', '1', '1776654208', '1776654208'),
('4', 'zh-CN', '2018', '9', '0', '研发中心成立', '成立独立研发中心，团队规模扩展至50人，获得多项技术专利。', '', 'office', '#F59E0B', '4', '1', '1776654208', '1776654208'),
('5', 'zh-CN', '2015', '1', '0', '产品线扩展', '产品线从软件扩展至传感器、控制器等硬件领域，形成软硬一体解决方案。', '', 'chip', '#EF4444', '5', '1', '1776654208', '1776654208'),
('6', 'zh-CN', '2012', '6', '0', '首个物联网项目', '首个物联网项目成功落地，服务首批企业客户，营收突破500万。', '', 'flag', '#06B6D4', '6', '1', '1776654208', '1776654208'),
('7', 'zh-CN', '2010', '3', '0', '公司成立', '公司在上海正式注册成立，专注于企业信息化解决方案，初始团队5人。', '', 'home', '#6366F1', '7', '1', '1776654208', '1776654208');

DROP TABLE IF EXISTS `yikai_users`;
CREATE TABLE `yikai_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `email` varchar(100) NOT NULL DEFAULT '' COMMENT '邮箱',
  `avatar` varchar(255) NOT NULL DEFAULT '' COMMENT '头像',
  `role_id` int(11) unsigned NOT NULL DEFAULT '1' COMMENT '角色ID',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `last_login_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `last_login_ip` varchar(45) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `login_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '登录次数',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`),
  KEY `idx_role` (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户表';

INSERT INTO `yikai_users` (`id`, `username`, `password`, `nickname`, `email`, `avatar`, `role_id`, `status`, `last_login_time`, `last_login_ip`, `login_count`, `created_at`, `updated_at`) VALUES
('1', 'admin', '$2y$10$jDd66AGgS7QxomAbMpDaxON63Fjft/fMOSoCKpT076go2FSGQvDZq', 'admin', 'admin@example.com', '', '1', '1', '1776653028', '127.0.0.1', '1', '1776652898', '1776653513');

SET FOREIGN_KEY_CHECKS = 1;
