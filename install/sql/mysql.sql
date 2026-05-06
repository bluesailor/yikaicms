-- -----------------------------------------------------------
-- Yikai CMS v1.4.2 - MySQL Install Script
-- -----------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `yikai_admin_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_ai_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI调用日志';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_album_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册图片';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_albums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='相册';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_article_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章分类';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_articles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文章表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_banner_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮播图分组';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_banners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮播图';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品牌管理';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='栏目表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_contents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_download_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `yikai_download_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '分类名称',
  `description` varchar(255) DEFAULT '' COMMENT '分类描述',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) DEFAULT '1' COMMENT '状态',
  `created_at` int(10) unsigned DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='下载分类';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_downloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='下载管理';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_extfields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='扩展字段定义表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_form_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表单模板';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_forms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表单数据';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='招聘管理';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='友情链接';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='媒体库';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台会员表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_metas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='通用元数据键值表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_plugins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `yikai_plugins` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL COMMENT '插件标识',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0禁用 1启用',
  `installed_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '安装时间',
  `activated_at` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '启用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_product_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品分类';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_product_tag_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `yikai_product_tag_map` (
  `product_id` int(11) unsigned NOT NULL,
  `tag_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`product_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品标签关联';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_product_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品标签';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `yikai_roles` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '角色名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '角色描述',
  `permissions` text COMMENT '权限JSON',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='配置表';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_timelines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='发展历程时间线';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `yikai_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------
-- Default Roles
-- -----------------------------------------------------------
INSERT INTO `yikai_roles` (`id`, `name`, `description`, `permissions`, `status`, `created_at`) VALUES
(1, '超级管理员', '拥有全部权限', '["*"]', 1, UNIX_TIMESTAMP()),
(2, '编辑', '内容编辑权限', '["content","media"]', 1, UNIX_TIMESTAMP()),
(3, '运营', '运营管理权限', '["content","media","form","banner","link"]', 1, UNIX_TIMESTAMP());

-- -----------------------------------------------------------
-- Default Banner Groups
-- -----------------------------------------------------------
INSERT INTO `yikai_banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES
('首页', 'home', 650, 300, 5000, 0, 1, UNIX_TIMESTAMP()),
('关于我们', 'about', 500, 250, 5000, 1, 1, UNIX_TIMESTAMP()),
('产品中心', 'product', 500, 250, 5000, 2, 1, UNIX_TIMESTAMP()),
('案例展示', 'case', 500, 250, 5000, 3, 1, UNIX_TIMESTAMP());

-- -----------------------------------------------------------
-- Default Settings
-- -----------------------------------------------------------
INSERT INTO `yikai_settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
-- basic
('basic', 'site_url', '', 'text', '站点域名', '如 https://www.example.com', NULL, 0),
('basic', 'site_name', 'Yikai CMS', 'text', '站点名称', '', NULL, 1),
('basic', 'site_keywords', '企业官网,CMS,内容管理', 'textarea', 'SEO关键词', '多个关键词用逗号分隔', NULL, 2),
('basic', 'site_description', '专业的企业内容管理系统，助力企业数字化转型', 'textarea', 'SEO描述', '', NULL, 3),
('basic', 'site_logo', '/images/logo.png', 'image', '站点Logo', '', NULL, 4),
('basic', 'site_favicon', '/favicon.ico', 'image', '站点图标', '', NULL, 5),
('basic', 'primary_color', '#3B82F6', 'color', '主题色', '', NULL, 6),
('basic', 'secondary_color', '#1D4ED8', 'color', '辅助色', '', NULL, 7),
('basic', 'site_icp', '', 'text', 'ICP备案号', '', NULL, 10),
('basic', 'site_police', '', 'text', '公安备案号', '', NULL, 11),
('basic', 'admin_title', '后台管理', 'text', '后台名称', '后台左上角显示的名称', NULL, 20),
('basic', 'admin_logo', '/images/logo.png', 'image', '后台Logo', '留空显示文字', NULL, 21),
('basic', 'admin_copyright', '', 'text', '后台版权', '留空不显示', NULL, 22),
-- header
('header', 'topbar_enabled', '0', 'select', '顶部通栏', '', '{"0":"隐藏","1":"显示"}', 0),
('header', 'topbar_bg_color', '#f3f4f6', 'color', '通栏背景色', '', NULL, 1),
('header', 'topbar_left', '', 'code', '通栏左侧内容', '', NULL, 2),
('header', 'show_member_entry', '0', 'select', '会员入口', '', '{"0":"隐藏","1":"显示"}', 3),
('header', 'header_nav_layout', 'right', 'select', '导航布局', '', '{"right":"Logo右侧","below":"Logo下方通栏"}', 10),
('header', 'header_sticky', '0', 'select', '固定顶部', '', '{"1":"是","0":"否"}', 11),
('header', 'header_bg_color', '#ffffff', 'color', '背景颜色', '', NULL, 12),
('header', 'header_text_color', '#4b5563', 'color', '文字颜色', '', NULL, 13),
-- footer
('footer', 'footer_columns', '[{"title":"关于我们","content":"{{site_description}}","col_span":2},{"title":"联系方式","content":"{{contact_info}}","col_span":1},{"title":"关注我们","content":"{{qrcode}}","col_span":1}]', 'footer_columns', '页脚栏目', '', NULL, 1),
('footer', 'footer_bg_color', '#1f2937', 'color', '背景颜色', '', NULL, 2),
('footer', 'footer_bg_image', '', 'image', '背景图片', '', NULL, 3),
('footer', 'footer_text_color', '#9ca3af', 'color', '文字颜色', '', NULL, 4),
('footer', 'footer_nav', '[{"title":"","links":[{"name":"隐私政策","url":"/privacy.html"},{"name":"服务条款","url":"/terms.html"}]}]', 'footer_nav', '页脚导航', '', NULL, 5),
('footer', 'footer_copyright_text', '© {year} {site_name} 版权所有.', 'text', '版权文字', '{year}=年份 {site_name}=站点名', NULL, 6),
-- code
('code', 'custom_head_code', '', 'code', 'Head代码', '', NULL, 1),
('code', 'custom_body_code', '', 'code', 'Body代码', '', NULL, 2),
-- contact
('contact', 'contact_cards', '[{"icon":"phone","label":"联系电话","value":"400-888-8888"},{"icon":"email","label":"电子邮箱","value":"contact@example.com"},{"icon":"location","label":"公司地址","value":"上海市浦东新区XX路XX号"}]', 'contact_cards', '联系卡片', '', NULL, 0),
('contact', 'contact_phone', '400-888-8888', 'text', '联系电话', '', NULL, 1),
('contact', 'contact_email', 'contact@example.com', 'text', '联系邮箱', '', NULL, 2),
('contact', 'contact_address', '上海市浦东新区XX路XX号', 'textarea', '联系地址', '', NULL, 3),
('contact', 'contact_qrcode', '', 'image', '二维码', '', NULL, 4),
('contact', 'contact_map', '', 'image', '地图图片', '', NULL, 5),
('contact', 'contact_form_title', '在线留言', 'text', '表单标题', '', NULL, 10),
('contact', 'contact_form_desc', '', 'textarea', '表单描述', '', NULL, 11),
('contact', 'contact_form_fields', '[{"key":"name","label":"您的姓名","type":"text","required":true,"enabled":true},{"key":"phone","label":"联系电话","type":"tel","required":true,"enabled":true},{"key":"email","label":"电子邮箱","type":"email","required":false,"enabled":true},{"key":"company","label":"公司名称","type":"text","required":false,"enabled":true},{"key":"content","label":"留言内容","type":"textarea","required":true,"enabled":true}]', 'contact_form_fields', '表单字段', '', NULL, 12),
('contact', 'contact_form_success', '提交成功，我们会尽快与您联系！', 'text', '提交成功提示', '', NULL, 13),
-- home
('home', 'home_about_content', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。', 'textarea', '关于我们简介', '', NULL, 1),
('home', 'home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80', 'image', '关于我们图片', '', NULL, 2),
('home', 'home_about_tag_title', '专业服务', 'text', '角标标题', '', NULL, 3),
('home', 'home_about_tag_desc', '品质 · 创新 · 共赢', 'text', '角标描述', '', NULL, 4),
('home', 'home_about_layout', 'text_left', 'select', '关于我们布局', '', '{"text_left":"左文右图","image_left":"左图右文"}', 6),
('home', 'home_stat_1_num', '10+', 'text', '统计数字1', '', NULL, 5),
('home', 'home_stat_1_text', '年行业经验', 'text', '统计文字1', '', NULL, 6),
('home', 'home_stat_2_num', '1000+', 'text', '统计数字2', '', NULL, 7),
('home', 'home_stat_2_text', '服务客户', 'text', '统计文字2', '', NULL, 8),
('home', 'home_stat_3_num', '50+', 'text', '统计数字3', '', NULL, 9),
('home', 'home_stat_3_text', '专业团队', 'text', '统计文字3', '', NULL, 10),
('home', 'home_stat_4_num', '100%', 'text', '统计数字4', '', NULL, 11),
('home', 'home_stat_4_text', '客户满意', 'text', '统计文字4', '', NULL, 12),
('home', 'home_stat_bg', '', 'image', '统计区背景图', '', NULL, 12),
('home', 'home_advantage_desc', '专业团队，优质服务，值得信赖', 'text', '优势区块描述', '', NULL, 13),
('home', 'home_adv_1_icon', 'check-circle', 'icon', '优势1图标', '', NULL, 14),
('home', 'home_adv_1_title', '品质保证', 'text', '优势1标题', '', NULL, 14),
('home', 'home_adv_1_desc', '严格把控产品质量，确保每一件产品都符合标准', 'text', '优势1描述', '', NULL, 15),
('home', 'home_adv_2_icon', 'academic-cap', 'icon', '优势2图标', '', NULL, 16),
('home', 'home_adv_2_title', '技术领先', 'text', '优势2标题', '', NULL, 16),
('home', 'home_adv_2_desc', '持续研发创新，保持技术的领先优势', 'text', '优势2描述', '', NULL, 17),
('home', 'home_adv_3_icon', 'briefcase', 'icon', '优势3图标', '', NULL, 18),
('home', 'home_adv_3_title', '专业服务', 'text', '优势3标题', '', NULL, 18),
('home', 'home_adv_3_desc', '专业团队7x24小时技术支持服务', 'text', '优势3描述', '', NULL, 19),
('home', 'home_adv_4_icon', 'users', 'icon', '优势4图标', '', NULL, 20),
('home', 'home_adv_4_title', '合作共赢', 'text', '优势4标题', '', NULL, 20),
('home', 'home_adv_4_desc', '与客户建立长期合作关系，实现互利共赢', 'text', '优势4描述', '', NULL, 21),
('home', 'home_cta_title', '准备好开始合作了吗？', 'text', 'CTA标题', '', NULL, 22),
('home', 'home_cta_desc', '联系我们，获取专业的解决方案', 'text', 'CTA描述', '', NULL, 23),
('home', 'home_show_links', '0', 'select', '显示合作伙伴', '', NULL, 24),
('home', 'home_links_title', '合作伙伴', 'text', '链接区块标题', '', NULL, 25),
('home', 'home_testimonials', '[{"avatar":"","name":"张先生","company":"某科技有限公司","content":"非常专业的服务团队，合作非常愉快！"},{"avatar":"","name":"李女士","company":"某贸易公司","content":"产品质量优秀，售后服务及时，值得信赖。"},{"avatar":"","name":"王总","company":"某集团公司","content":"多年合作，一直保持高品质的服务水准！"}]', 'home_testimonials', '客户评价', '', NULL, 26),
('home', 'home_testimonials_title', '客户评价', 'text', '评价区标题', '', NULL, 27),
('home', 'home_testimonials_desc', '听听合作伙伴怎么说', 'text', '评价区描述', '', NULL, 28),
('home', 'home_show_banner', '1', 'select', '显示轮播图', '', NULL, 30),
('home', 'home_show_about', '1', 'select', '显示关于我们', '', NULL, 31),
('home', 'home_show_stats', '1', 'select', '显示数据统计', '', NULL, 32),
('home', 'home_show_channels', '1', 'select', '显示栏目区块', '', NULL, 33),
('home', 'home_show_advantage', '1', 'select', '显示优势展示', '', NULL, 34),
('home', 'home_show_cta', '1', 'select', '显示行动号召', '', NULL, 35),
('home', 'home_blocks_config', '[{"type":"banner","enabled":true},{"type":"about","enabled":true},{"type":"stats","enabled":true},{"type":"channels","enabled":true},{"type":"testimonials","enabled":true},{"type":"advantage","enabled":true},{"type":"cta","enabled":true}]', 'home_blocks', '首页区块配置', '', NULL, 40),
-- email
('email', 'smtp_host', '', 'text', 'SMTP服务器', '', NULL, 1),
('email', 'smtp_port', '465', 'text', 'SMTP端口', '', NULL, 2),
('email', 'smtp_secure', 'ssl', 'text', '加密方式', '', NULL, 3),
('email', 'smtp_user', '', 'text', 'SMTP用户名', '', NULL, 4),
('email', 'smtp_pass', '', 'text', 'SMTP密码', '', NULL, 5),
('email', 'mail_from', '', 'text', '发件人邮箱', '', NULL, 6),
('email', 'mail_from_name', '', 'text', '发件人名称', '', NULL, 7),
('email', 'mail_admin', '', 'text', '管理员邮箱', '', NULL, 8),
('email', 'mail_notify_form', '0', 'text', '表单提交通知', '', NULL, 9),
('email', 'mail_tpl_register_subject', '欢迎注册 — {{site_name}}', 'text', '注册邮件标题', '', NULL, 20),
('email', 'mail_tpl_register_body', '{{username}}，您好！\n欢迎注册 {{site_name}}！\n{{site_url}}/member/\n{{site_name}} {{date}}', 'textarea', '注册邮件内容', '', NULL, 21),
('email', 'mail_tpl_forgot_subject', '密码找回 — {{site_name}}', 'text', '找回密码标题', '', NULL, 22),
('email', 'mail_tpl_forgot_body', '{{username}}，您好！\n请点击以下链接重置密码：\n{{reset_link}}\n链接30分钟有效。\n{{site_name}} {{date}}', 'textarea', '找回密码内容', '', NULL, 23),
('email', 'mail_tpl_reset_subject', '密码已重置 — {{site_name}}', 'text', '密码重置标题', '', NULL, 24),
('email', 'mail_tpl_reset_body', '{{username}}，您好！\n您的密码已成功重置。\n{{site_name}} {{date}}', 'textarea', '密码重置内容', '', NULL, 25),
('email', 'mail_tpl_inquiry_subject', '新询盘：{{product_title}} — {{site_name}}', 'text', '询盘通知标题', '', NULL, 26),
('email', 'mail_tpl_inquiry_body', '产品：{{product_title}}\n姓名：{{name}}\n电话：{{phone}}\n邮箱：{{email}}\n公司：{{company}}\n内容：{{content}}\n时间：{{date}}\nIP：{{ip}}', 'textarea', '询盘通知内容', '', NULL, 27),
-- product
('product', 'product_layout', 'sidebar', 'select', '产品列表版式', '', '{"sidebar":"侧栏模式","top":"顶栏模式"}', 1),
('product', 'show_price', '0', 'select', '显示产品价格', '', '{"0":"隐藏","1":"显示"}', 2),
('product', 'product_sort_options', '["default","newest","views"]', 'text', '可用排序选项', '', NULL, 3),
('product', 'product_default_sort', 'default', 'select', '产品默认排序', '', '{"default":"默认","newest":"最新","views":"浏览量"}', 4),
-- banner
('banner', 'banner_height_pc', '650', 'number', '轮播图高度(PC)', '', NULL, 1),
('banner', 'banner_height_mobile', '300', 'number', '轮播图高度(移动)', '', NULL, 2),
-- member
('member', 'allow_member_register', '0', 'switch', '允许会员注册', '', NULL, 1),
('member', 'download_require_login', '0', 'switch', '下载需要登录', '', NULL, 2),
-- social
('social', 'social_links', '[]', 'social_links', '社交媒体链接', '', NULL, 1),
-- system
('system', 'current_theme', 'default', 'text', '当前主题', '', NULL, 0),
('system', 'cms_version', '1.4.2', 'text', 'CMS版本号', '', NULL, 1),
('system', 'site_lang', 'zh-CN', 'text', '站点语言', '', NULL, 2),
('system', 'admin_lang', 'zh-CN', 'text', '后台语言', '', NULL, 3),
('system', 'html_cache_enabled', '0', 'select', 'HTML缓存', '', '{"0":"关闭","1":"开启"}', 10),
('system', 'html_cache_ttl', '300', 'number', '缓存有效期', '秒', NULL, 11);

-- -----------------------------------------------------------
-- Default Form Template (Product Inquiry)
-- -----------------------------------------------------------
INSERT INTO `yikai_form_templates` (`name`, `slug`, `fields`, `success_message`, `status`, `created_at`) VALUES
('联系表单', 'contact', '[{"key":"name","label":"您的姓名","type":"text","required":true},{"key":"phone","label":"联系电话","type":"tel","required":true},{"key":"email","label":"电子邮箱","type":"email","required":false},{"key":"company","label":"公司名称","type":"text","required":false},{"key":"content","label":"留言内容","type":"textarea","required":true}]', '提交成功，我们会尽快与您联系！', 1, UNIX_TIMESTAMP()),
('产品询盘', 'product-inquiry', '[{"key":"name","label":"您的姓名","type":"text","required":true},{"key":"phone","label":"联系电话","type":"tel","required":true},{"key":"email","label":"电子邮箱","type":"email","required":false},{"key":"company","label":"公司名称","type":"text","required":false},{"key":"content","label":"咨询内容","type":"textarea","required":true}]', '提交成功，我们会尽快与您联系！', 1, UNIX_TIMESTAMP());

-- -----------------------------------------------------------
-- Default Channels
-- -----------------------------------------------------------
INSERT INTO `yikai_channels` (`lang`, `translation_group_id`, `parent_id`, `name`, `slug`, `type`, `album_id`, `icon`, `image`, `description`, `content`, `link_url`, `link_target`, `redirect_type`, `redirect_url`, `seo_title`, `seo_keywords`, `seo_description`, `is_nav`, `is_home`, `status`, `is_system`, `sort_order`, `created_at`, `updated_at`) VALUES
('zh-CN', 0, 0, '关于我们', 'about', 'page', 0, '', '', '了解我们的企业文化与发展历程', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 1, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 1, '公司简介', 'company', 'page', 0, '', '', '公司基本情况介绍', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 1, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 1, '企业文化', 'culture', 'page', 0, '', '', '企业核心价值观', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 2, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 1, '发展历程', 'history', 'page', 0, '', '', '企业发展里程碑', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 3, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '产品中心', 'product', 'product', 0, '', '', '我们的产品与服务', NULL, '', '_self', 'auto', '', '', '', '', 1, 1, 1, 1, 2, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '成功案例', 'cases', 'case', 0, '', '', '客户成功案例展示', NULL, '', '_self', 'auto', '', '', '', '', 1, 1, 1, 1, 3, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '新闻资讯', 'news', 'list', 0, '', '', '最新动态与行业资讯', NULL, '', '_self', 'auto', '', '', '', '', 1, 1, 1, 1, 4, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 7, '公司新闻', 'company-news', 'list', 0, '', '', '公司最新动态', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 1, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 7, '行业动态', 'industry-news', 'list', 0, '', '', '行业最新资讯', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 2, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '服务支持', 'service', 'page', 0, '', '', '专业的服务与技术支持', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 5, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 10, '服务流程', 'process', 'page', 0, '', '', '标准化服务流程', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 1, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 10, '常见问题', 'faq', 'list', 0, '', '', '常见问题解答', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 2, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '下载中心', 'download', 'download', 0, '', '', '资料与软件下载', NULL, '', '_self', 'none', '', '', '', '', 1, 0, 1, 1, 3, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '人才招聘', 'job', 'job', 0, '', '', '加入我们', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 6, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '联系我们', 'contact', 'page', 0, '', '', '联系方式与在线留言', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 7, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '隐私政策', 'privacy', 'page', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 0, 0, 1, 1, 98, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '服务条款', 'terms', 'page', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 0, 0, 1, 1, 99, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 1, '荣誉资质', 'honor', 'album', 1, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 4, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 1, '组织架构', 'organization', 'page', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 5, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 0, '解决方案', 'solution', 'case', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 3, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 20, '行业方案', 'industry', 'case', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 1, 1, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 7, '技术分享', 'tech-share', 'list', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 0, 3, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 13, '软件下载', 'software-download', 'download', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 0, 1, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 13, '文档资料', 'document-download', 'download', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 0, 2, UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 13, '驱动程序', 'driver-download', 'download', 0, '', '', '', NULL, '', '_self', 'auto', '', '', '', '', 1, 0, 1, 0, 3, UNIX_TIMESTAMP(), 0);

-- -----------------------------------------------------------
-- Default Albums (Demo)
-- -----------------------------------------------------------
-- @demo:start
INSERT INTO `yikai_albums` (`category_id`, `name`, `slug`, `cover`, `description`, `photo_count`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(0, '荣誉资质', 'honor', 'https://picsum.photos/400/300?random=401', '公司获得的各项荣誉与资质证书', 6, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

INSERT INTO `yikai_album_photos` (`album_id`, `title`, `image`, `thumb`, `description`, `sort_order`, `status`, `created_at`) VALUES
(1, '高新技术企业证书', 'https://picsum.photos/600/400?random=401', '', '', 1, 1, UNIX_TIMESTAMP()),
(1, 'ISO9001质量管理体系认证', 'https://picsum.photos/600/400?random=402', '', '', 2, 1, UNIX_TIMESTAMP()),
(1, '软件企业认定证书', 'https://picsum.photos/600/400?random=403', '', '', 3, 1, UNIX_TIMESTAMP()),
(1, '年度最佳科技创新奖', 'https://picsum.photos/600/400?random=404', '', '', 4, 1, UNIX_TIMESTAMP()),
(1, '优秀供应商荣誉证书', 'https://picsum.photos/600/400?random=405', '', '', 5, 1, UNIX_TIMESTAMP()),
(1, '行业十佳品牌奖', 'https://picsum.photos/600/400?random=406', '', '', 6, 1, UNIX_TIMESTAMP());
-- @demo:end

-- -----------------------------------------------------------
-- Default Product Categories
-- -----------------------------------------------------------
INSERT INTO `yikai_product_categories` (`parent_id`, `name`, `slug`, `lang`, `translation_group_id`, `image`, `description`, `seo_title`, `seo_keywords`, `seo_description`, `status`, `is_nav`, `sort_order`, `created_at`) VALUES
(0, '智能设备', 'smart-device', 'zh-CN', 0, '', NULL, '', '', '', 1, 1, 1, UNIX_TIMESTAMP()),
(0, '软件服务', 'software', 'zh-CN', 0, '', NULL, '', '', '', 1, 1, 2, UNIX_TIMESTAMP()),
(1, '传感器模块', 'sensor-module', 'zh-CN', 0, '', NULL, '', '', '', 1, 1, 1, UNIX_TIMESTAMP()),
(1, '控制终端', 'control-terminal', 'zh-CN', 0, '', NULL, '', '', '', 1, 1, 2, UNIX_TIMESTAMP());

-- -----------------------------------------------------------
-- Default Products (Demo)
-- -----------------------------------------------------------
-- @demo:start
INSERT INTO `yikai_products` (`lang`, `translation_group_id`, `category_id`, `brand_id`, `title`, `subtitle`, `slug`, `cover`, `images`, `summary`, `content`, `price`, `market_price`, `model`, `specs`, `tags`, `is_top`, `is_recommend`, `is_hot`, `is_new`, `views`, `status`, `sort_order`, `created_at`, `updated_at`, `admin_id`) VALUES
('zh-CN', 0, 1, 0, '智能物联网网关', '', '', 'https://picsum.photos/600/600?random=101', NULL, '多协议兼容，边缘计算能力', '<h2>产品概述</h2>\n<p>智能物联网网关是一款高性能的边缘计算网关设备，支持多种通信协议（MQTT、HTTP、Modbus、OPC UA），可实现设备数据的实时采集、边缘计算和云端同步。</p>\n<h3>核心特点</h3>\n<ul>\n<li>支持 Wi-Fi/4G/以太网多种联网方式</li>\n<li>内置边缘计算引擎，支持本地数据处理</li>\n<li>兼容 100+ 种工业协议</li>\n<li>工业级设计，-40°C ~ 85°C 宽温工作</li>\n</ul>\n<h3>应用场景</h3>\n<p>智能工厂、智慧农业、智慧城市、能源管理等领域。</p>', 0.00, 0.00, 'IoT-GW-100', NULL, '', 0, 0, 0, 0, 0, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 2, 0, '企业管理云平台', '', '', 'https://picsum.photos/600/600?random=102', NULL, '集成ERP/CRM/OA功能', '<h2>产品概述</h2>\n<p>企业管理云平台是一站式企业数字化管理解决方案，集成 ERP、CRM、OA 三大核心模块，帮助企业实现业务流程数字化。</p>\n<h3>功能模块</h3>\n<ul>\n<li><strong>ERP</strong> — 采购、库存、生产、财务一体化管理</li>\n<li><strong>CRM</strong> — 客户管理、销售漏斗、业绩分析</li>\n<li><strong>OA</strong> — 审批流程、日程管理、即时通讯</li>\n</ul>\n<h3>技术优势</h3>\n<p>基于微服务架构，支持私有化部署和 SaaS 模式，数据安全有保障。</p>', 0.00, 0.00, 'Cloud-ERP', NULL, '', 0, 0, 0, 0, 0, 1, 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 3, 0, '温湿度传感器 TH-200', '', '', 'https://picsum.photos/600/600?random=103', NULL, '瑞士芯片，精度±0.1°C', '<h2>产品概述</h2>\n<p>TH-200 温湿度传感器采用瑞士进口高精度芯片，精度达 ±0.1°C / ±1.5%RH，适用于工业环境监测、仓储管理、智慧农业等场景。</p>\n<h3>技术参数</h3>\n<ul>\n<li>温度范围：-40°C ~ 125°C</li>\n<li>湿度范围：0 ~ 100%RH</li>\n<li>通信接口：RS485 / Modbus RTU</li>\n<li>供电方式：DC 12-24V</li>\n<li>防护等级：IP65</li>\n</ul>', 0.00, 0.00, 'TH-200', NULL, '', 0, 0, 0, 0, 0, 1, 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 3, 0, '光照传感器 LS-100', '', '', 'https://picsum.photos/600/600?random=104', NULL, '检测范围0-200000Lux', '<h2>产品概述</h2>\n<p>LS-100 光照传感器检测范围 0-200,000 Lux，采用高灵敏度光电二极管，响应速度快，线性度好。</p>\n<h3>技术参数</h3>\n<ul>\n<li>测量范围：0 ~ 200,000 Lux</li>\n<li>精度：±3%</li>\n<li>通信接口：RS485 / Modbus</li>\n<li>供电方式：DC 5-24V</li>\n</ul>\n<h3>应用场景</h3>\n<p>智慧农业、气象观测、智能照明控制。</p>', 0.00, 0.00, 'LS-100', NULL, '', 0, 0, 0, 0, 0, 1, 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 4, 0, '工业边缘控制器 EC-500', '', '', 'https://picsum.photos/600/600?random=105', NULL, 'ARM Cortex-A72，支持AI推理', '<h2>产品概述</h2>\n<p>EC-500 工业边缘控制器搭载 ARM Cortex-A72 处理器，支持多种工业协议和 AI 模型本地推理，是智能工厂的核心控制单元。</p>\n<h3>核心特点</h3>\n<ul>\n<li>四核 ARM Cortex-A72，主频 1.8GHz</li>\n<li>4GB RAM / 32GB eMMC 存储</li>\n<li>支持 TensorFlow Lite / ONNX 推理</li>\n<li>丰富 I/O 接口：4×RS485、2×CAN、4×DI、4×DO</li>\n</ul>', 0.00, 0.00, 'EC-500', NULL, '', 0, 0, 0, 0, 0, 1, 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 0, 4, 0, '智能网关控制器 GC-300', '', '', 'https://picsum.photos/600/600?random=106', NULL, 'Wi-Fi/Zigbee/LoRa/4G多协议', '<h2>产品概述</h2>\n<p>GC-300 智能网关控制器支持 Wi-Fi/Zigbee/LoRa/4G 四种无线协议同时工作，内置边缘计算模块，实现设备统一管理。</p>\n<h3>核心特点</h3>\n<ul>\n<li>四协议同时在线，最大接入 500 个终端</li>\n<li>内置边缘计算引擎</li>\n<li>支持 OTA 远程升级</li>\n<li>Web 管理界面，零代码配置</li>\n</ul>\n<h3>应用场景</h3>\n<p>智慧楼宇、智能家居、工业物联网。</p>', 0.00, 0.00, 'GC-300', NULL, '', 0, 0, 0, 0, 0, 1, 6, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0);

-- -----------------------------------------------------------
-- Default Contents (Demo)
-- -----------------------------------------------------------
INSERT INTO `yikai_contents` (`lang`, `channel_id`, `title`, `slug`, `type`, `cover`, `summary`, `content`, `seo_title`, `seo_keywords`, `seo_description`, `views`, `status`, `sort_order`, `created_at`, `updated_at`, `admin_id`) VALUES
('zh-CN', 8, '公司荣获年度最佳科技创新奖', 'tech-innovation-award', 'article', 'https://picsum.photos/800/500?random=201', '在2024年科技创新大会上，我公司凭借技术实力获此殊荣。', '<p>在日前举行的2024年度科技创新大会上，我公司凭借在物联网和边缘计算领域的突出贡献，荣获"年度最佳科技创新奖"。</p>\n<p>此次评选由中国信息通信研究院主办，经过专家委员会的严格评审，从数百家参评企业中脱颖而出。评委会高度评价了我公司在智能物联网网关、边缘计算平台等核心产品上的技术创新成果。</p>\n<h3>获奖亮点</h3>\n<ul>\n<li>自主研发的物联网协议栈，兼容100+种工业协议</li>\n<li>边缘AI推理引擎，支持毫秒级实时决策</li>\n<li>累计申请技术专利32项，其中发明专利12项</li>\n</ul>\n<p>公司CEO表示："这个奖项是对我们团队持续创新的认可，未来我们将继续加大研发投入，为行业数字化转型贡献力量。"</p>', '公司荣获年度最佳科技创新奖', '科技创新,物联网,边缘计算,技术奖项', '我公司在2024年度科技创新大会上荣获年度最佳科技创新奖，表彰在物联网和边缘计算领域的突出贡献。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 8, '公司与战略合作伙伴签署合作协议', 'strategic-partnership', 'article', 'https://picsum.photos/800/500?random=202', '双方将在智能制造领域展开深度合作。', '<p>近日，我公司与国内领先的智能制造解决方案提供商正式签署战略合作协议，双方将在工业物联网、智能制造和数字化工厂等领域展开全方位深度合作。</p>\n<h3>合作内容</h3>\n<ul>\n<li><strong>技术融合</strong>：将我公司的物联网网关与合作伙伴的MES系统深度集成</li>\n<li><strong>市场协同</strong>：共同开拓华东和华南区域的制造业客户</li>\n<li><strong>产品共创</strong>：联合开发面向中小制造企业的轻量化数字工厂方案</li>\n</ul>\n<p>此次合作将充分发挥双方在各自领域的技术优势和市场资源，为制造业客户提供从设备连接到生产管理的一站式数字化解决方案。</p>', '公司与战略合作伙伴签署合作协议', '战略合作,智能制造,工业物联网,数字化工厂', '我公司与智能制造解决方案提供商签署战略合作协议，将在工业物联网和数字化工厂领域展开深度合作。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 9, '数字化转型趋势报告发布', 'digital-transformation-report', 'article', 'https://picsum.photos/800/500?random=203', '报告分析了企业数字化转型的最新趋势和最佳实践。', '<p>我公司研究院正式发布《2024企业数字化转型趋势报告》，报告基于对500+企业的调研数据，深入分析了当前数字化转型的关键趋势和实践路径。</p>\n<h3>核心发现</h3>\n<ol>\n<li><strong>AI驱动成为主流</strong>：78%的受访企业已将AI技术纳入数字化转型规划</li>\n<li><strong>边缘计算崛起</strong>：工业场景中边缘计算部署量同比增长150%</li>\n<li><strong>数据安全受重视</strong>：65%的企业将数据安全列为转型首要考量</li>\n<li><strong>中小企业加速</strong>：轻量化SaaS方案推动中小企业数字化渗透率提升至45%</li>\n</ol>\n<h3>趋势展望</h3>\n<p>报告指出，未来三年，数字孪生、工业元宇宙和绿色智能制造将成为企业数字化转型的三大新方向。</p>\n<p>完整报告可在官网下载中心获取。</p>', '数字化转型趋势报告发布', '数字化转型,趋势报告,AI,边缘计算', '2024企业数字化转型趋势报告发布，分析500+企业调研数据，解读AI驱动和边缘计算等关键趋势。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 9, 'PHP 8.0 新特性详解', 'php80-new-features', 'article', 'https://picsum.photos/800/500?random=204', '深入解析PHP 8.0带来的性能提升和新语法特性。', '<p>PHP 8.0 是 PHP 语言的重大版本更新，带来了众多令人兴奋的新特性和性能改进。本文将深入解析其中最重要的变化。</p>\n<h3>JIT 编译器</h3>\n<p>PHP 8.0 引入了 JIT（即时编译）支持，在计算密集型场景下性能提升可达3倍。虽然对典型 Web 应用提升有限，但在数据处理和科学计算场景表现优异。</p>\n<h3>命名参数</h3>\n<pre><code>htmlspecialchars($string, double_encode: false);</code></pre>\n<p>命名参数使代码更具可读性，不再需要记忆参数顺序。</p>\n<h3>联合类型</h3>\n<pre><code>function foo(int|string $id): void {}</code></pre>\n<p>原生支持联合类型声明，减少对 PHPDoc 注释的依赖。</p>\n<h3>Match 表达式</h3>\n<pre><code>$result = match($status) {\n    1 => \"active\",\n    2 => \"inactive\",\n    default => \"unknown\",\n};</code></pre>\n<p>match 是 switch 的现代替代，支持严格比较和返回值。</p>\n<h3>Null 安全运算符</h3>\n<pre><code>$country = $user?->getAddress()?->country;</code></pre>\n<p>链式调用中优雅处理 null 值，避免冗长的 null 检查。</p>', 'PHP 8.0 新特性详解', 'PHP8,JIT,命名参数,联合类型,技术分享', '深入解析PHP 8.0的JIT编译器、命名参数、联合类型、Match表达式和Null安全运算符等重要新特性。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 6, '某大型制造企业数字化转型项目', 'manufacturing-digital-transformation', 'case', 'https://picsum.photos/800/500?random=301', '帮助客户实现生产效率提升30%', '<h3>项目背景</h3>\n<p>客户为国内大型制造企业，拥有5个生产基地、2000+台设备。面临设备数据孤岛、生产计划依赖人工经验、质量追溯困难等痛点。</p>\n<h3>解决方案</h3>\n<ul>\n<li><strong>设备互联</strong>：部署200+台IoT网关，接入全部生产设备，实现数据实时采集</li>\n<li><strong>数据中台</strong>：搭建统一数据平台，打通ERP、MES、WMS系统数据</li>\n<li><strong>智能排产</strong>：基于AI算法的智能排产系统，优化生产计划</li>\n<li><strong>质量追溯</strong>：全流程二维码追溯体系，精准定位质量问题</li>\n</ul>\n<h3>项目成果</h3>\n<ul>\n<li>生产效率提升 <strong>30%</strong></li>\n<li>设备停机时间减少 <strong>45%</strong></li>\n<li>产品不良率降低 <strong>60%</strong></li>\n<li>库存周转率提升 <strong>25%</strong></li>\n</ul>\n<p>项目实施周期6个月，投入使用后第一年即实现投资回报。</p>', '大型制造企业数字化转型案例', '数字化转型,智能制造,物联网,成功案例', '帮助大型制造企业实现设备互联与智能排产，生产效率提升30%，设备停机减少45%。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 2, '公司简介', 'company', 'article', '', NULL, '<h2>关于我们</h2>\n<p>我们是一家专注于企业数字化转型的科技公司，成立于2010年，总部位于上海。经过十余年的发展，已成为行业内具有影响力的企业之一。</p>\n<p>公司拥有一支经验丰富的技术团队，核心成员来自国内外知名企业，在物联网、云计算、人工智能等领域拥有深厚的技术积累。</p>\n<h3>我们的使命</h3>\n<p>以技术创新驱动企业数字化升级，帮助客户实现智能化运营，提升核心竞争力。</p>\n<h3>我们的愿景</h3>\n<p>成为企业数字化转型领域最值得信赖的技术合作伙伴。</p>\n<h3>核心优势</h3>\n<ul>\n<li>10+ 年行业深耕经验</li>\n<li>1000+ 企业客户信赖</li>\n<li>50+ 人专业研发团队</li>\n<li>7×24 小时技术支持</li>\n</ul>', '公司简介', '企业简介,数字化转型,科技公司', '专注于企业数字化转型的科技公司，成立于2010年，拥有10+年行业经验和50+人专业团队。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 3, '企业文化', 'culture', 'article', '', NULL, '<h2>企业文化</h2>\n<h3>核心价值观</h3>\n<p><strong>以人为本</strong> — 尊重每一位员工，激发团队潜能，共同成长。</p>\n<p><strong>创新驱动</strong> — 持续技术创新，保持行业领先优势。</p>\n<p><strong>追求卓越</strong> — 精益求精，以最高标准要求每一个产品和服务。</p>\n<p><strong>合作共赢</strong> — 与客户建立长期合作关系，实现互利共赢。</p>\n<h3>企业精神</h3>\n<p>诚信、专业、高效、创新</p>\n<h3>工作理念</h3>\n<p>以客户需求为导向，以技术创新为驱动，以团队协作为基础，持续为客户创造价值。</p>', '企业文化', '企业文化,核心价值观,企业精神', '以人为本、创新驱动、追求卓越、合作共赢 — 我们的核心价值观和企业精神。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 15, '联系我们', 'contact', 'article', '', NULL, '<p>欢迎通过以下方式联系我们。</p>', '联系我们', '联系方式,在线留言', '通过电话、邮件或在线表单联系我们，我们将尽快回复。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 16, '隐私政策', 'privacy', 'article', '', NULL, '<h2>隐私政策</h2>\n<p>我们重视您的隐私保护。本隐私政策说明了我们如何收集、使用和保护您的个人信息。</p>\n<h3>信息收集</h3>\n<p>我们在您使用网站服务时，可能收集以下信息：姓名、联系方式、公司名称等您主动提交的信息。</p>\n<h3>信息使用</h3>\n<p>收集的信息仅用于：回复您的咨询、提供客户服务、改善产品和服务质量。</p>\n<h3>信息保护</h3>\n<p>我们采取行业标准的安全措施保护您的个人信息，未经您的同意不会向第三方透露。</p>', '隐私政策', '隐私政策,个人信息保护', '了解我们如何收集、使用和保护您的个人信息。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 17, '服务条款', 'terms', 'article', '', NULL, '<h2>服务条款</h2>\n<p>欢迎使用我们的网站和服务。使用本网站即表示您同意以下条款。</p>\n<h3>服务说明</h3>\n<p>本网站提供企业信息展示和产品咨询服务。我们保留随时修改或中断服务的权利。</p>\n<h3>知识产权</h3>\n<p>本网站所有内容（包括但不限于文字、图片、标志）均受知识产权法保护，未经授权不得复制或传播。</p>\n<h3>免责声明</h3>\n<p>我们尽力确保网站信息准确，但不对信息的完整性和实时性作出保证。</p>', '服务条款', '服务条款,使用协议', '了解使用本网站的服务条款和相关规定。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 4, '发展历程', 'history', 'article', '', NULL, '<h2>发展历程</h2>\n<p><strong>2024年</strong> — 发布新一代智能物联网平台，服务客户突破1000家。</p>\n<p><strong>2022年</strong> — 获得国家高新技术企业认定，完成B轮融资。</p>\n<p><strong>2020年</strong> — 推出企业管理云平台，实现SaaS化服务。</p>\n<p><strong>2018年</strong> — 成立研发中心，团队扩展至50人。</p>\n<p><strong>2015年</strong> — 产品线扩展至传感器、控制器等硬件领域。</p>\n<p><strong>2012年</strong> — 首个物联网项目落地，服务首批企业客户。</p>\n<p><strong>2010年</strong> — 公司成立，专注于企业信息化解决方案。</p>', '发展历程', '发展历程,公司历史,企业大事记', '从2010年创立至今，回顾企业发展的重要里程碑。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 8, '公司参加2024国际物联网博览会', 'iot-expo-2024', 'article', 'https://picsum.photos/800/500?random=206', '展示最新智能物联网解决方案。', '<p>我公司携旗下全系列物联网产品亮相2024国际物联网博览会，全面展示了在智能物联网领域的最新技术成果。</p>\n<h3>展品亮点</h3>\n<ul>\n<li><strong>新一代IoT网关</strong>：支持5G+Wi-Fi 6双模连接，处理性能提升200%</li>\n<li><strong>边缘AI套件</strong>：集成视觉检测和预测性维护功能</li>\n<li><strong>数字孪生平台</strong>：实时3D可视化工厂运行状态</li>\n</ul>\n<p>展会期间接���了来自30多个国家的500余位专业观众，达成多项合作意向。公司展位获评"最佳创新展���奖"。</p>', '公司参加2024国际物联网博览会', '物联网博览会,IoT,5G,边缘AI', '公司携全系列物联网产品亮相2024国际物联网博览会，展示5G网关和边缘AI等最新技术成果。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 19, '组织架构', 'organization', 'article', '', NULL, '<style>\n.org-chart { text-align: center; }\n.org-chart ul { padding-top: 20px; position: relative; display: flex; justify-content: center; list-style: none; margin: 0; padding-left: 0; }\n.org-chart ul::before { content: \"\"; position: absolute; top: 0; left: 50%; width: 0; height: 20px; border-left: 2px solid #cbd5e1; }\n.org-chart li { position: relative; padding: 20px 5px 0; display: flex; flex-direction: column; align-items: center; }\n.org-chart li::before, .org-chart li::after { content: \"\"; position: absolute; top: 0; width: 50%; height: 20px; border-top: 2px solid #cbd5e1; }\n.org-chart li::before { left: 0; border-left: 2px solid #cbd5e1; }\n.org-chart li::after { right: 0; border-right: 2px solid #cbd5e1; }\n.org-chart li:first-child::before { display: none; }\n.org-chart li:last-child::after { display: none; }\n.org-chart li:only-child::before, .org-chart li:only-child::after { display: none; }\n.org-chart li:first-child::after { border-radius: 5px 0 0 0; }\n.org-chart li:last-child::before { border-radius: 0 5px 0 0; }\n.org-chart .org-node { display: inline-block; padding: 10px 20px; border-radius: 8px; text-align: center; min-width: 120px; }\n.org-chart .org-ceo { background: linear-gradient(135deg, #1e40af, #3b82f6); color: #fff; font-size: 16px; font-weight: 700; padding: 14px 28px; }\n.org-chart .org-vp { background: linear-gradient(135deg, #0f766e, #14b8a6); color: #fff; font-weight: 600; }\n.org-chart .org-dept { background: #f1f5f9; border: 1px solid #e2e8f0; color: #334155; font-size: 14px; }\n.org-chart .org-title { display: block; font-size: 11px; opacity: 0.85; margin-top: 2px; font-weight: 400; }\n</style>\n<div class=\"org-chart\"><ul style=\"padding-top:0\"><li style=\"padding-top:0\"><ul style=\"padding-top:0\"><li style=\"padding-top:0\"><div class=\"org-node org-ceo\">张伟<span class=\"org-title\">董事长 / CEO</span></div><ul><li><div class=\"org-node org-vp\">李明<span class=\"org-title\">副总裁 · 技术</span></div><ul><li><div class=\"org-node org-dept\">研发部</div></li><li><div class=\"org-node org-dept\">测试部</div></li><li><div class=\"org-node org-dept\">运维部</div></li></ul></li><li><div class=\"org-node org-vp\">王芳<span class=\"org-title\">副总裁 · 营销</span></div><ul><li><div class=\"org-node org-dept\">市场��</div></li><li><div class=\"org-node org-dept\">��售部</div></li><li><div class=\"org-node org-dept\">��服部</div></li></ul></li><li><div class=\"org-node org-vp\">赵强<span class=\"org-title\">副总裁 · 运营</span></div><ul><li><div class=\"org-node org-dept\">财务部</div></li><li><div class=\"org-node org-dept\">人力资源部</div></li><li><div class=\"org-node org-dept\">行政部</div></li></ul></li></ul></li></ul></li></ul></div>\n<div style=\"margin-top:40px;padding:24px;background:#f8fafc;border-radius:8px;\"><h3 style=\"margin-top:0;\">组织概况</h3><p>公司设有<strong>技术中心</strong>、<strong>营销中心</strong>��<strong>运营中心</strong>三大业务板块，下辖9个职能部门。现有员工50余人，其中技术研发人员占比超过60%。</p><p>我们秉承扁平化管理理念，鼓励跨部门协作，确保信息高效流通和快速决策。</p></div>', '组织架构', '组织架构,公司架构,团队结构', '公司组织架构图，设有技术、营销、运营三大中心及9个职��部门。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 11, '服务流程', 'process', 'article', '', NULL, '<div style=\"max-width:800px;margin:0 auto;\"><p style=\"text-align:center;color:#6b7280;margin-bottom:2em;\">我们以标准化的服务流程，确保每一个项目高效交付、客户满意。</p><div style=\"display:flex;align-items:flex-start;margin-bottom:2em;\"><div style=\"flex-shrink:0;width:60px;height:60px;background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;\">1</div><div style=\"margin-left:20px;flex:1;\"><h3 style=\"margin-top:0;margin-bottom:4px;\">需求沟通</h3><p style=\"color:#6b7280;\">与客户深入交流，了解业务场景和具体需求，形成详细的需求规格说明书。</p></div></div><div style=\"display:flex;align-items:flex-start;margin-bottom:2em;\"><div style=\"flex-shrink:0;width:60px;height:60px;background:linear-gradient(135deg,#10b981,#34d399);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;\">2</div><div style=\"margin-left:20px;flex:1;\"><h3 style=\"margin-top:0;margin-bottom:4px;\">方案设计</h3><p style=\"color:#6b7280;\">制定技术方案和实施计划，包括系统架构设计、硬件选型、网络规划和项目排期。</p></div></div><div style=\"display:flex;align-items:flex-start;margin-bottom:2em;\"><div style=\"flex-shrink:0;width:60px;height:60px;background:linear-gradient(135deg,#8b5cf6,#a78bfa);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;\">3</div><div style=\"margin-left:20px;flex:1;\"><h3 style=\"margin-top:0;margin-bottom:4px;\">开发实施</h3><p style=\"color:#6b7280;\">采用敏捷开发模式，定期汇报进度，关键节点邀请客户参与验收。</p></div></div><div style=\"display:flex;align-items:flex-start;margin-bottom:2em;\"><div style=\"flex-shrink:0;width:60px;height:60px;background:linear-gradient(135deg,#f59e0b,#fbbf24);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;\">4</div><div style=\"margin-left:20px;flex:1;\"><h3 style=\"margin-top:0;margin-bottom:4px;\">测试验收</h3><p style=\"color:#6b7280;\">全面系统测试，确保稳定可靠后交付上线。</p></div></div><div style=\"display:flex;align-items:flex-start;margin-bottom:2em;\"><div style=\"flex-shrink:0;width:60px;height:60px;background:linear-gradient(135deg,#ef4444,#f87171);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;\">5</div><div style=\"margin-left:20px;flex:1;\"><h3 style=\"margin-top:0;margin-bottom:4px;\">培训交付</h3><p style=\"color:#6b7280;\">提供操作培训和技术文档，确保客户能独立运维。</p></div></div><div style=\"display:flex;align-items:flex-start;margin-bottom:2em;\"><div style=\"flex-shrink:0;width:60px;height:60px;background:linear-gradient(135deg,#06b6d4,#22d3ee);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:700;\">6</div><div style=\"margin-left:20px;flex:1;\"><h3 style=\"margin-top:0;margin-bottom:4px;\">售后支持</h3><p style=\"color:#6b7280;\">7×24小时技术支持，定期巡检和系统优化。</p></div></div></div>', '服务流程', '服务流程,项目交付,技术支持', '标准化六步服务流程：需求沟通、方案设��、开发实施、测试验收、培训交付、售后支���。', 0, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 12, '你们的产品支持哪些通信协议？', 'faq-protocols', 'article', '', '我们的物联网网关支持MQTT、HTTP、Modbus、OPC UA等100+种工业协议。', '<p>���们的物联网网关产品支持丰富的通信协议，包括但不限于：</p>\n<ul>\n<li><strong>物联网协议</strong>：MQTT���CoAP、HTTP/HTTPS、WebSocket</li>\n<li><strong>工业协议</strong>：Modbus RTU/TCP、OPC UA、Profinet、EtherCAT</li>\n<li><strong>无线协议</strong>：Wi-Fi、蓝牙、Zigbee、LoRa、NB-IoT、4G/5G</li>\n</ul>', '支持的通信协议', '通信协议,MQTT,Modbus', '我们的物联网网关支持MQTT、Modbus、OPC UA等100+种工业协议。', 0, 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 12, '项目实施周期一般多长？', 'faq-timeline', 'article', '', '根据项目规模不同，实施周期通常在1-6个月。', '<p>项目实施周期取决于规模和复杂度：</p>\n<ul>\n<li><strong>小型项目</strong>（50台以内设备）：1-2个月</li>\n<li><strong>中型项目</strong>（200台以内设备）：2-4个��</li>\n<li><strong>大型项���</strong>（集团级跨区域）：4-6个月</li>\n</ul>', '项目实施周期', '项目周期,实施计划', '项目实施周期根据规模不同通常在1-6个月。', 0, 1, 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 12, '是否支持私有化部署？', 'faq-private-deploy', 'article', '', '支持。我们的所有软件产品均支持私有化部署和SaaS两种模式��', '<p>是的，支持两种部署模式：</p>\n<h4>私有化部署</h4>\n<ul><li>部署在客户自有服务器</li><li>数据完全掌握在客户手中</li><li>支持内网离线运行</li></ul>\n<h4>SaaS 云服务</h4>\n<ul><li>开箱即用，无需运维</li><li>按需付费，���活扩展</li></ul>', '是否支持私有化部署', '私有化部署,SaaS,数据安全', '支持私有化部署和SaaS云服务两种模式。', 0, 1, 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 12, '售后服务包括���些内容？', 'faq-after-sales', 'article', '', '提供7×24小时技术支持、定期巡检、系统升级和远程运维服务。', '<ul>\n<li><strong>技术支持</strong>：7×24小时热线和在线客服</li>\n<li><strong>��障响应</strong>：紧急问题1小时内响应</li>\n<li><strong>定��巡检</strong>：每季度一次系统巡检</li>\n<li><strong>系统升级</strong>：免费一年版本升级</li>\n<li><strong>远程运维</strong>：安全通道远程协助</li>\n</ul>', '售后服务内容', '售后服务,技术支持', '提供7×24小时技术支持、定期巡检、系统升级和远程运维服务。', 0, 1, 4, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('zh-CN', 12, '如何获取产品报价？', 'faq-pricing', 'article', '', '可通过在线咨询、电话或填写询盘表单获取定制化报价方案。', '<ol>\n<li><strong>在线咨询</strong>：网站在线客服</li>\n<li><strong>电话咨询</strong>：拨打 400-888-8888</li>\n<li><strong>询盘表单</strong>：产品页面提交，24小时内回复</li>\n<li><strong>邮件联系</strong>：contact@example.com</li>\n</ol>', '如何获取报价', '产品报价,询盘', '通过在线咨询、电话或询盘��单获取定制化报价方案。', 0, 1, 5, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0);

-- -----------------------------------------------------------
-- Default Jobs (Demo)
-- -----------------------------------------------------------
INSERT INTO `yikai_jobs` (`title`, `lang`, `translation_group_id`, `cover`, `summary`, `content`, `location`, `salary`, `job_type`, `education`, `experience`, `headcount`, `requirements`, `views`, `is_top`, `sort_order`, `status`, `publish_time`, `created_at`, `updated_at`, `admin_id`) VALUES
('PHP高级工程师', 'zh-CN', 0, '', '负责公司核心产品的后端开发', NULL, '上海（可远程）', '25-40K', '全职', '本科', '3年以上', 2, '熟悉PHP 8.0+\n熟悉MySQL\n有CMS开发经验优先', 0, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
('前端开发工程师', 'zh-CN', 0, '', '负责公司产品的前端界面开发', NULL, '上海（可远程）', '20-35K', '全职', '本科', '2年以上', 1, '熟悉Vue/React\n熟悉Tailwind CSS\n注重代码质量', 0, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0);

-- -----------------------------------------------------------
-- Default Downloads (Demo)
-- -----------------------------------------------------------
INSERT INTO `yikai_downloads` (`category_id`, `lang`, `translation_group_id`, `title`, `description`, `cover`, `file_url`, `file_name`, `file_size`, `file_ext`, `download_count`, `is_external`, `require_login`, `sort_order`, `status`, `created_at`, `updated_at`, `admin_id`) VALUES
(0, 'zh-CN', 0, '产品使用手册 V2.0', '最新版产品使用说明书', '', '', '', 0, 'pdf', 0, 0, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
(0, 'zh-CN', 0, '客户端软件 V3.5.1', '适用于Windows系统的客户端软件', '', '', '', 0, 'exe', 0, 0, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0),
(0, 'zh-CN', 0, 'API接口文档', '完整的API接口说明文档', '', '', '', 0, 'pdf', 0, 0, 0, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0);

-- -----------------------------------------------------------
-- Default Timelines (Demo)
-- -----------------------------------------------------------
INSERT INTO `yikai_timelines` (`lang`, `year`, `month`, `day`, `title`, `content`, `image`, `icon`, `color`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
('zh-CN', 2024, 1, 0, '智能物联网平台发布', '发布新一代智能物联网平台，集成AI边缘计算能力，服务客户突破1000家。', '', 'rocket', '#3B82F6', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('zh-CN', 2022, 6, 0, '国家高新技术企业认定', '通过国家高新技术企业认定，完成B轮融资，估值突破5亿。', '', 'star', '#10B981', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('zh-CN', 2020, 3, 0, '企业管理云平台上线', '推出企业管理云平台，实现ERP/CRM/OA一体化SaaS服务。', '', 'cloud', '#8B5CF6', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('zh-CN', 2018, 9, 0, '研发中心成立', '成立独立研发中心，团队规模扩展至50人，获得多项技术专利。', '', 'office', '#F59E0B', 4, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('zh-CN', 2015, 1, 0, '产品线扩展', '产品线从软件扩展至传感器、控制器等硬件领域，形成软硬一体解决方案。', '', 'chip', '#EF4444', 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('zh-CN', 2012, 6, 0, '首个物联网项目', '首个物联网项目成功落地，服务首批企业客户，营收突破500万。', '', 'flag', '#06B6D4', 6, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('zh-CN', 2010, 3, 0, '公司成立', '公司在上海正式注册成立，专注于企业信息化解决方案，初始团队5人。', '', 'home', '#6366F1', 7, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
-- @demo:end

-- -----------------------------------------------------------
-- Default Banners (Demo)
-- -----------------------------------------------------------
-- @demo:start
INSERT INTO `yikai_banners` (`position`, `lang`, `title`, `subtitle`, `btn1_text`, `btn1_url`, `btn2_text`, `btn2_url`, `image`, `image_mobile`, `link_url`, `link_target`, `start_time`, `end_time`, `status`, `sort_order`, `created_at`) VALUES
('home', 'zh-CN', '数字化转型解决方案', '助力企业实现智能化升级', '了解更多', '/about.html', '', '', 'https://picsum.photos/1920/600?random=1', '', '', '_self', 0, 0, 1, 1, UNIX_TIMESTAMP()),
('home', 'zh-CN', '专业的技术服务团队', '7x24小时为您保驾护航', '', '', '', '', 'https://picsum.photos/1920/600?random=2', '', '', '_self', 0, 0, 1, 2, UNIX_TIMESTAMP()),
('home', 'zh-CN', '创新引领未来', '持续创新，追求卓越', '', '', '', '', 'https://picsum.photos/1920/600?random=3', '', '', '_self', 0, 0, 1, 3, UNIX_TIMESTAMP());
-- @demo:end
