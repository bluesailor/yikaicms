<?php
/**
 * YikaiCMS - 升级检测（后台页面）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 检测表中是否存在指定字段（兼容 MySQL 和 SQLite）
function _columnExists(string $table, string $column): bool
{
    $tableName = DB_PREFIX . $table;
    if (db()->isSqlite()) {
        $cols = db()->fetchAll("PRAGMA table_info('{$tableName}')");
        foreach ($cols as $col) {
            if ($col['name'] === $column) return true;
        }
        return false;
    }
    $cols = db()->fetchAll("SHOW COLUMNS FROM `{$tableName}` LIKE '{$column}'");
    return !empty($cols);
}

// 将 MySQL DDL 转为 SQLite 兼容语法
function _sqlToSqlite(string $sql): ?string
{
    // 跳过 ADD KEY / ADD INDEX
    if (preg_match('/ALTER\s+TABLE\s+.*\s+ADD\s+(KEY|INDEX)\s+/i', $sql)) {
        return null;
    }
    $sql = preg_replace('/\)\s*ENGINE=.*$/i', ')', $sql);
    $sql = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $sql);
    $sql = preg_replace('/\bUNSIGNED\b/i', '', $sql);
    $sql = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $sql);
    $sql = preg_replace('/\bint\(\d+\)/i', 'INTEGER', $sql);
    $sql = preg_replace('/\bINTEGER\s+NOT\s+NULL\s+AUTOINCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\s+AFTER\s+`[^`]+`/i', '', $sql);
    $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
    if (stripos($sql, 'AUTOINCREMENT') !== false) {
        $sql = preg_replace('/,\s*PRIMARY\s+KEY\s*\(`id`\)/i', '', $sql);
    }
    $sql = preg_replace('/,\s*KEY\s+`[^`]+`\s*\([^)]+\)/i', '', $sql);
    return $sql;
}

// 升级项定义：每项包含 id、描述、检测方法、执行SQL
$upgrades = [

    [
        'id'    => '20260220_banner_groups',
        'title' => '轮播图分组管理',
        'desc'  => '新增轮播图分组表(yikai_banner_groups)，支持动态管理轮播图分组并通过短码 [banner-slug] 在任意页面嵌入轮播图。',
        'check' => function () {
            return db()->tableExists('banner_groups');
        },
        'sqls' => [
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "banner_groups` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮播图分组'",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('首页', 'home', 650, 300, 5000, 0, 1, UNIX_TIMESTAMP())",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('关于我们', 'about', 500, 250, 5000, 1, 1, UNIX_TIMESTAMP())",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('产品中心', 'product', 500, 250, 5000, 2, 1, UNIX_TIMESTAMP())",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('案例展示', 'case', 500, 250, 5000, 3, 1, UNIX_TIMESTAMP())",
        ],
    ],

    [
        'id'    => '20260326_merge_articles',
        'title' => '合并文章系统到统一内容表',
        'desc'  => '将 yikai_articles + yikai_article_categories 的数据迁移到 yikai_contents + yikai_channels，消除数据冗余。迁移后文章通过栏目系统统一管理。',
        'check' => function () {
            // 如果 articles 表不存在或已无数据，则认为已迁移
            if (!db()->tableExists('articles')) return true;
            $count = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "articles");
            return $count === 0;
        },
        'sqls' => [], // 使用 PHP 逻辑迁移，见下方 run handler
        'php' => function () {
            $prefix = DB_PREFIX;

            // 1. 获取 news 栏目 ID
            $newsChannel = db()->fetchOne("SELECT id FROM {$prefix}channels WHERE slug = 'news' AND parent_id = 0 LIMIT 1");
            if (!$newsChannel) {
                throw new \Exception('找不到 slug=news 的栏目，请先创建新闻资讯栏目');
            }
            $newsChannelId = (int)$newsChannel['id'];

            // 2. 获取所有 article_categories
            $categories = db()->fetchAll("SELECT * FROM {$prefix}article_categories ORDER BY parent_id ASC, sort_order ASC, id ASC");
            if (empty($categories)) return '没有文章分类需要迁移';

            // 3. 创建分类 -> 栏目的 ID 映射
            $catIdToChannelId = [];

            // 先处理顶级分类（parent_id = 0 或 parent 是 'news'）
            foreach ($categories as $cat) {
                $parentChannelId = $newsChannelId;

                // 如果分类有父级且父级已映射，使用映射后的栏目 ID
                if ($cat['parent_id'] > 0 && isset($catIdToChannelId[$cat['parent_id']])) {
                    $parentChannelId = $catIdToChannelId[$cat['parent_id']];
                }

                // 跳过 slug='news' 的顶级分类（它就是 news 栏目本身）
                if ($cat['slug'] === 'news' && $cat['parent_id'] == 0) {
                    $catIdToChannelId[$cat['id']] = $newsChannelId;
                    continue;
                }

                // 检查是否已存在同 slug 的子栏目
                $existing = db()->fetchOne(
                    "SELECT id FROM {$prefix}channels WHERE slug = ? AND parent_id = ?",
                    [$cat['slug'], $parentChannelId]
                );

                if ($existing) {
                    $catIdToChannelId[$cat['id']] = (int)$existing['id'];
                } else {
                    // 创建新栏目
                    $channelId = db()->insert("channels", [
                        'parent_id'       => $parentChannelId,
                        'name'            => $cat['name'],
                        'slug'            => $cat['slug'],
                        'type'            => 'list',
                        'image'           => $cat['image'] ?? '',
                        'description'     => $cat['description'] ?? '',
                        'seo_title'       => $cat['seo_title'] ?? '',
                        'seo_keywords'    => $cat['seo_keywords'] ?? '',
                        'seo_description' => $cat['seo_description'] ?? '',
                        'is_nav'          => 1,
                        'is_home'         => 0,
                        'is_system'       => 0,
                        'status'          => $cat['status'] ?? 1,
                        'sort_order'      => $cat['sort_order'] ?? 0,
                        'created_at'      => $cat['created_at'] ?? time(),
                    ]);
                    $catIdToChannelId[$cat['id']] = (int)$channelId;
                }
            }

            // 4. 迁移文章数据到 contents 表
            $articles = db()->fetchAll("SELECT * FROM {$prefix}articles ORDER BY id ASC");
            $migrated = 0;

            foreach ($articles as $art) {
                $channelId = $catIdToChannelId[$art['category_id']] ?? $newsChannelId;

                // 检查是否已迁移（按 slug 或 title+publish_time 去重）
                $exists = false;
                if (!empty($art['slug'])) {
                    $exists = db()->fetchOne(
                        "SELECT id FROM {$prefix}contents WHERE slug = ? AND channel_id = ?",
                        [$art['slug'], $channelId]
                    );
                }
                if ($exists) continue;

                db()->insert("contents", [
                    'channel_id'   => $channelId,
                    'type'         => 'article',
                    'title'        => $art['title'],
                    'subtitle'     => $art['subtitle'] ?? '',
                    'slug'         => $art['slug'] ?? '',
                    'cover'        => $art['cover'] ?? '',
                    'summary'      => $art['summary'] ?? '',
                    'content'      => $art['content'] ?? '',
                    'author'       => $art['author'] ?? '',
                    'source'       => $art['source'] ?? '',
                    'tags'         => $art['tags'] ?? '',
                    'is_top'       => $art['is_top'] ?? 0,
                    'is_recommend' => $art['is_recommend'] ?? 0,
                    'is_hot'       => $art['is_hot'] ?? 0,
                    'views'        => $art['views'] ?? 0,
                    'likes'        => $art['likes'] ?? 0,
                    'status'       => $art['status'] ?? 1,
                    'publish_time' => $art['publish_time'] ?? time(),
                    'created_at'   => $art['created_at'] ?? time(),
                    'updated_at'   => $art['updated_at'] ?? time(),
                    'admin_id'     => $art['admin_id'] ?? 0,
                ]);
                $migrated++;
            }

            // 5. 清空旧表数据（保留表结构以防回退）
            db()->execute("DELETE FROM {$prefix}articles");

            return "迁移完成：" . count($catIdToChannelId) . " 个分类，{$migrated} 篇文章";
        },
    ],

    [
        'id'    => '20260329_language_settings',
        'title' => '多语言支持',
        'desc'  => '新增前台语言和后台语言设置项，支持中文和日本語切换。',
        'check' => function () {
            return (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE `key` = 'site_lang'"
            ) > 0;
        },
        'sqls' => [
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
                ('basic', 'site_lang', 'zh-CN', 'select', '前台语言', '前台页面显示语言', '{\"zh-CN\":\"中文\",\"ja\":\"日本語\"}', 13),
                ('basic', 'admin_lang', 'zh-CN', 'select', '后台语言', '管理后台显示语言', '{\"zh-CN\":\"中文\",\"ja\":\"日本語\"}', 14)",
        ],
    ],

    [
        'id'    => '20260329_translate_settings',
        'title' => '翻译API配置',
        'desc'  => '新增翻译API设置项（DeepL/Google Translate），支持语言包自动翻译。',
        'check' => function () {
            return (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE `key` = 'translate_api'"
            ) > 0;
        },
        'sqls' => [
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
                ('translate', 'translate_api', 'deepl', 'select', '翻译API', '选择翻译服务提供商', '{\"deepl\":\"DeepL\",\"google\":\"Google Translate\"}', 1),
                ('translate', 'translate_api_key', '', 'text', 'API Key', 'DeepL: 注册 https://www.deepl.com/pro-api 获取免费Key', NULL, 2)",
        ],
    ],

    [
        'id'    => '20260329_cms_version_in_db',
        'title' => '版本号写入数据库',
        'desc'  => '将 CMS 版本号存入 settings 表，升级后自动更新，便于版本检测和管理。',
        'check' => function () {
            return (int)db()->fetchColumn(
                "SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE `key` = 'cms_version'"
            ) > 0;
        },
        'sqls' => [
            "INSERT IGNORE INTO `" . DB_PREFIX . "settings` (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES
                ('system', 'cms_version', '" . (defined('CMS_VERSION') ? CMS_VERSION : '1.3.0') . "', 'text', 'CMS版本号', '系统自动维护，请勿手动修改', NULL, 0)",
        ],
    ],

    [
        'id'    => '20260507_deepseek_v4_models',
        'title' => 'DeepSeek API v4 模型升级',
        'desc'  => 'DeepSeek 新版 v4 模型 (deepseek-v4-flash / deepseek-v4-pro) 替代旧的 deepseek-chat / deepseek-reasoner。升级后默认使用 v4-flash (1元/M tokens 输入,2元/M tokens 输出)。',
        'check' => function () {
            // 已升级标志: ai_model 不等于旧 deepseek-chat / deepseek-reasoner (或者非 deepseek 用户视为已升级)
            $provider = (string)db()->fetchColumn("SELECT value FROM " . DB_PREFIX . "settings WHERE `key`='ai_provider'");
            if ($provider !== 'deepseek') return true;
            $model = (string)db()->fetchColumn("SELECT value FROM " . DB_PREFIX . "settings WHERE `key`='ai_model'");
            return !in_array($model, ['deepseek-chat', 'deepseek-reasoner', ''], true);
        },
        'php' => function () {
            $provider = (string)db()->fetchColumn("SELECT value FROM " . DB_PREFIX . "settings WHERE `key`='ai_provider'");
            if ($provider !== 'deepseek') {
                return 'DeepSeek 未启用,跳过模型升级';
            }
            $model = (string)db()->fetchColumn("SELECT value FROM " . DB_PREFIX . "settings WHERE `key`='ai_model'");
            $newModel = ($model === 'deepseek-reasoner') ? 'deepseek-v4-pro' : 'deepseek-v4-flash';
            db()->execute("UPDATE " . DB_PREFIX . "settings SET value=? WHERE `key`='ai_model'", [$newModel]);
            return "DeepSeek 模型已升级: $model → $newModel";
        },
    ],

    [
        'id'    => '20260330_brands_table',
        'title' => '品牌管理',
        'desc'  => '新增品牌管理表(yikai_brands)，支持品牌Logo、产地、介绍等，产品可关联品牌。',
        'check' => function () {
            return db()->tableExists('brands');
        },
        'sqls' => [
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "brands` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL COMMENT '品牌名',
                `slug` varchar(100) NOT NULL DEFAULT '',
                `logo` varchar(255) NOT NULL DEFAULT '' COMMENT '品牌Logo',
                `country` varchar(50) NOT NULL DEFAULT '' COMMENT '国家/产地',
                `description` text COMMENT '品牌介绍',
                `url` varchar(255) NOT NULL DEFAULT '' COMMENT '官网',
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品牌管理'",
        ],
    ],

    [
        'id'    => '20260330_product_tags',
        'title' => '产品标签系统',
        'desc'  => '新增产品标签表和标签关联表，支持按材质、用途等多维度分组标签筛选。',
        'check' => function () {
            return db()->tableExists('product_tags');
        },
        'sqls' => [
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "product_tags` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `group_name` varchar(50) NOT NULL COMMENT '标签组',
                `name` varchar(100) NOT NULL COMMENT '标签名',
                `slug` varchar(100) NOT NULL DEFAULT '',
                `sort_order` int(11) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                KEY `idx_group` (`group_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品标签'",
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "product_tag_map` (
                `product_id` int(11) UNSIGNED NOT NULL,
                `tag_id` int(11) UNSIGNED NOT NULL,
                PRIMARY KEY (`product_id`, `tag_id`),
                KEY `idx_tag` (`tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产品标签关联'",
        ],
    ],

    [
        'id'    => '20260330_product_brand_id',
        'title' => '产品表增加品牌字段',
        'desc'  => '产品表新增 brand_id 字段，关联品牌管理。',
        'check' => function () {
            return _columnExists('products', 'brand_id');
        },
        'sqls' => [
            "ALTER TABLE `" . DB_PREFIX . "products` ADD COLUMN `brand_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '品牌ID' AFTER `category_id`",
        ],
    ],

    // --- 询盘系统 ---

    [
        'id'    => '20260331_inquiry_fields',
        'title' => '表单表增加询盘字段',
        'desc'  => '表单表新增 product_id、product_title、source 字段，支持产品询盘关联。',
        'check' => function () {
            return _columnExists('forms', 'product_id');
        },
        'sqls' => [
            "ALTER TABLE `" . DB_PREFIX . "forms` ADD COLUMN `product_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联产品ID' AFTER `type`",
            "ALTER TABLE `" . DB_PREFIX . "forms` ADD COLUMN `product_title` varchar(255) NOT NULL DEFAULT '' COMMENT '产品名称快照' AFTER `product_id`",
            "ALTER TABLE `" . DB_PREFIX . "forms` ADD COLUMN `source` varchar(30) NOT NULL DEFAULT 'contact' COMMENT '来源: contact/product/custom' AFTER `product_title`",
            "ALTER TABLE `" . DB_PREFIX . "forms` ADD KEY `idx_product` (`product_id`)",
            "ALTER TABLE `" . DB_PREFIX . "forms` ADD KEY `idx_source` (`source`)",
        ],
    ],

    [
        'id'    => '20260331_inquiry_form_template',
        'title' => '创建产品询盘表单模板',
        'desc'  => '自动创建 product-inquiry 表单模板，用于产品详情页内联询盘。',
        'check' => function () {
            $row = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "form_templates WHERE slug = 'product-inquiry'");
            return !empty($row);
        },
        'sqls' => [],
        'php'  => function () {
            db()->execute(
                "INSERT INTO " . DB_PREFIX . "form_templates (name, slug, fields, success_message, status, created_at) VALUES (?, ?, ?, ?, 1, ?)",
                [
                    '产品询盘',
                    'product-inquiry',
                    "[text* name \"您的姓名\"]\n[tel* phone \"联系电话\"]\n[email email \"邮箱地址\"]\n[text company \"公司名称\"]\n[textarea* content \"请描述您的需求\"]",
                    '询盘已提交，我们将尽快与您联系！',
                    time(),
                ]
            );
            return '产品询盘模板创建成功';
        },
    ],

    [
        'id'    => '20260401_mail_templates',
        'title' => '初始化邮件通知模板',
        'desc'  => '写入会员注册、找回密码、重置密码、询盘通知 4 套邮件模板默认内容。',
        'check' => function () {
            $row = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "settings WHERE `key` = 'mail_tpl_register_subject'");
            return !empty($row);
        },
        'sqls' => [],
        'php'  => function () {
            $templates = [
                'mail_tpl_register_subject' => '欢迎注册 — {{site_name}}',
                'mail_tpl_register_body'    => "{{username}}，您好！\n\n欢迎注册 {{site_name}}！您的帐号已创建成功。\n\n请登录会员中心管理您的帐号：\n{{site_url}}/member/\n\n如有任何问题，请随时联系我们。\n\n{{site_name}}\n{{date}}",
                'mail_tpl_forgot_subject'   => '密码找回 — {{site_name}}',
                'mail_tpl_forgot_body'      => "{{username}}，您好！\n\n您正在进行密码找回操作，请点击以下链接重置密码：\n{{reset_link}}\n\n链接有效期为 30 分钟，如非本人操作请忽略此邮件。\n\n{{site_name}}\n{{date}}",
                'mail_tpl_reset_subject'    => '密码已重置 — {{site_name}}',
                'mail_tpl_reset_body'       => "{{username}}，您好！\n\n您的密码已成功重置。如非本人操作，请立即联系我们修改密码。\n\n{{site_name}}\n{{date}}",
                'mail_tpl_inquiry_subject'  => '新询盘通知：{{product_title}} — {{site_name}}',
                'mail_tpl_inquiry_body'     => "您收到一条新的产品询盘：\n\n产品：{{product_title}}\n姓名：{{name}}\n电话：{{phone}}\n邮箱：{{email}}\n公司：{{company}}\n内容：{{content}}\n\n时间：{{date}}\nIP：{{ip}}\n\n后台查看：{{site_url}}/admin/form.php",
            ];
            $count = 0;
            foreach ($templates as $key => $value) {
                $exists = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "settings WHERE `key` = ?", [$key]);
                if (!$exists) {
                    db()->execute(
                        "INSERT INTO " . DB_PREFIX . "settings (`group`, `key`, `value`, `type`, `name`, `sort_order`) VALUES (?, ?, ?, 'textarea', ?, ?)",
                        ['email', $key, $value, $key, 20 + $count]
                    );
                    $count++;
                }
            }
            return "已插入 {$count} 个邮件模板";
        },
    ],

    [
        'id'    => '20260404_ai_logs',
        'title' => 'AI 调用日志表',
        'desc'  => '新增 AI 调用日志表，记录每次 AI 请求的供应商、模型、Token 用量和状态。',
        'check' => function () {
            return db()->tableExists('ai_logs');
        },
        'sqls' => [
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "ai_logs` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `provider` varchar(30) NOT NULL,
                `model` varchar(50) NOT NULL DEFAULT '',
                `action` varchar(50) NOT NULL DEFAULT '',
                `prompt_tokens` int(11) NOT NULL DEFAULT 0,
                `completion_tokens` int(11) NOT NULL DEFAULT 0,
                `total_tokens` int(11) NOT NULL DEFAULT 0,
                `success` tinyint(1) NOT NULL DEFAULT 1,
                `error_msg` varchar(500) NOT NULL DEFAULT '',
                `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_provider` (`provider`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI调用日志'",
        ],
    ],


    [
        'id'    => '20260511_translation_group_ext',
        'title' => 'timelines / links 加翻译组字段',
        'desc'  => '为 yikai_timelines 和 yikai_links 表新增 translation_group_id 字段，让大事记和合作伙伴也能用统一的多语言翻译流（与 channels/contents/products 一致）。源行的 translation_group_id 顺手回填为自己的 id。',
        'check' => function () {
            return _columnExists('timelines', 'translation_group_id') && _columnExists('links', 'translation_group_id');
        },
        'sqls' => [
            "ALTER TABLE `" . DB_PREFIX . "timelines` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID（同一概念跨语言的多个行共享同一值）' AFTER `lang`",
            "ALTER TABLE `" . DB_PREFIX . "timelines` ADD INDEX `idx_tl_trans` (`translation_group_id`)",
            "UPDATE `" . DB_PREFIX . "timelines` SET `translation_group_id` = `id` WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0",

            "ALTER TABLE `" . DB_PREFIX . "links` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID（同一概念跨语言的多个行共享同一值）' AFTER `lang`",
            "ALTER TABLE `" . DB_PREFIX . "links` ADD INDEX `idx_lk_trans` (`translation_group_id`)",
            "UPDATE `" . DB_PREFIX . "links` SET `translation_group_id` = `id` WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0",
        ],
    ],

    [
        'id'    => '20260511_banners_translation_group',
        'title' => 'banners 加翻译组字段',
        'desc'  => '为 yikai_banners 表新增 translation_group_id 字段，使轮播图也能跟其它实体一样用同源 group 串起多语言版本；源行的 translation_group_id 回填为自身 id。',
        'check' => function () {
            return _columnExists('banners', 'translation_group_id');
        },
        'sqls' => [
            "ALTER TABLE `" . DB_PREFIX . "banners` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`",
            "ALTER TABLE `" . DB_PREFIX . "banners` ADD INDEX `idx_bn_trans` (`translation_group_id`)",
            "UPDATE `" . DB_PREFIX . "banners` SET `translation_group_id` = `id` WHERE `lang` = 'zh-CN' AND `translation_group_id` = 0",
        ],
    ],

    [
        'id'    => '20260511_home_footer_translations',
        'title' => '首页/页脚/联系方式 多语言种子',
        'desc'  => '为后台「首页设置」「SEO 设置」「页脚」启用 EN/JA 视图后预填英文/日文种子文案，避免首次访问翻译 tab 时出现空字段。'
                   . '包含 contact_phone/email/address、nav_home_text、关于我们、stats、testimonials、advantage、cta、partners、footer_columns/nav/copyright、site_description 等共约 60 个 per-lang 键。'
                   . '幂等：检测到 home_about_content_en 已存在则跳过。',
        'check' => function () {
            $row = db()->fetchOne("SELECT 1 FROM " . DB_PREFIX . "settings WHERE `key` = 'home_about_content_en' LIMIT 1");
            return !empty($row);
        },
        'sqls' => [
            // contact
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('contact_phone_en',   '+86-400-888-8888',                              'contact', 'text',     'Contact phone (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('contact_phone_ja',   '+86-400-888-8888',                              'contact', 'text',     'Contact phone (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('contact_email_en',   'contact@example.com',                           'contact', 'text',     'Contact email (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('contact_email_ja',   'contact@example.com',                           'contact', 'text',     'Contact email (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('contact_address_en', 'XX Road, Pudong New Area, Shanghai, China',     'contact', 'textarea', 'Contact address (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('contact_address_ja', '中国 上海市浦東新区XX路XX号',                       'contact', 'textarea', 'Contact address (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // nav home
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('nav_home_text_en', 'Home',  'home', 'text', 'Nav Home text (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('nav_home_text_ja', 'ホーム', 'home', 'text', 'Nav Home text (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // about block
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_about_content_en',   'We are a technology company focused on enterprise digital transformation, delivering high-quality products and services to our customers.', 'home', 'textarea', 'About content (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_about_content_ja',   '当社は企業のデジタルトランスフォーメーションに特化したテクノロジー企業として、お客様に高品質な製品とサービスを提供しています。', 'home', 'textarea', 'About content (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_about_tag_title_en', 'Professional Service',           'home', 'text', 'About tag title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_about_tag_title_ja', 'プロフェッショナルサービス',       'home', 'text', 'About tag title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_about_tag_desc_en',  'Quality · Innovation · Win-Win', 'home', 'text', 'About tag desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_about_tag_desc_ja',  '品質 · イノベーション · 共創',     'home', 'text', 'About tag desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // stats text
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_1_text_en', 'Years in Industry',     'home', 'text', 'Stat 1 (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_1_text_ja', '業界経験年数',           'home', 'text', 'Stat 1 (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_2_text_en', 'Customers Served',      'home', 'text', 'Stat 2 (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_2_text_ja', '取引実績数',             'home', 'text', 'Stat 2 (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_3_text_en', 'Professional Team',     'home', 'text', 'Stat 3 (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_3_text_ja', 'プロフェッショナルチーム', 'home', 'text', 'Stat 3 (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_4_text_en', 'Customer Satisfaction', 'home', 'text', 'Stat 4 (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_stat_4_text_ja', '顧客満足度',             'home', 'text', 'Stat 4 (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // testimonials
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_testimonials_title_en', 'Testimonials',          'home', 'text', 'Testimonials title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_testimonials_title_ja', 'お客様の声',             'home', 'text', 'Testimonials title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_testimonials_desc_en',  'What our partners say', 'home', 'text', 'Testimonials desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_testimonials_desc_ja',  'パートナーからの声',     'home', 'text', 'Testimonials desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_testimonials_en', '[{\"avatar\":\"\",\"name\":\"Mr. Zhang\",\"company\":\"Tech Co., Ltd.\",\"content\":\"A very professional team — a pleasure to work with.\"},{\"avatar\":\"\",\"name\":\"Ms. Li\",\"company\":\"Trading Corp.\",\"content\":\"Quality products and excellent service.\"}]', 'home', 'textarea', 'Testimonials JSON (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_testimonials_ja', '[{\"avatar\":\"\",\"name\":\"張様\",\"company\":\"テクノロジー会社\",\"content\":\"非常にプロフェッショナルなチームで、ご一緒できて大変光栄でした。\"},{\"avatar\":\"\",\"name\":\"李様\",\"company\":\"商社\",\"content\":\"高品質な製品と優れたサービス。\"}]', 'home', 'textarea', 'Testimonials JSON (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // advantage
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_advantage_desc_en', 'Professional team, quality service, trusted partner', 'home', 'text', 'Advantage desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_advantage_desc_ja', 'プロフェッショナルチーム・優れたサービス・信頼のパートナー', 'home', 'text', 'Advantage desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_1_title_en', 'Quality Assured',                                              'home', 'text', 'Adv 1 title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_1_title_ja', '品質保証',                                                     'home', 'text', 'Adv 1 title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_1_desc_en',  'Strict quality control ensures every product meets standards', 'home', 'text', 'Adv 1 desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_1_desc_ja',  '厳格な品質管理で、すべての製品が基準を満たすことを保証',           'home', 'text', 'Adv 1 desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_2_title_en', 'Tech Leadership',                                              'home', 'text', 'Adv 2 title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_2_title_ja', '技術リーダーシップ',                                            'home', 'text', 'Adv 2 title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_2_desc_en',  'Continuous R&D investment keeps us ahead of the curve',        'home', 'text', 'Adv 2 desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_2_desc_ja',  '継続的な研究開発で、技術の最前線をリードします',                   'home', 'text', 'Adv 2 desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_3_title_en', 'Professional Service',                                         'home', 'text', 'Adv 3 title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_3_title_ja', 'プロフェッショナルサービス',                                     'home', 'text', 'Adv 3 title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_3_desc_en',  'Expert team provides 24/7 technical support',                  'home', 'text', 'Adv 3 desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_3_desc_ja',  '専門チームが24時間365日テクニカルサポートを提供',                  'home', 'text', 'Adv 3 desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_4_title_en', 'Win-Win Partnership',                                          'home', 'text', 'Adv 4 title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_4_title_ja', '共創パートナーシップ',                                          'home', 'text', 'Adv 4 title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_4_desc_en',  'Long-term cooperation that creates mutual value',              'home', 'text', 'Adv 4 desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_adv_4_desc_ja',  '長期的なパートナーシップで、共に成長します',                       'home', 'text', 'Adv 4 desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // CTA
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_cta_title_en', 'Ready to get started?',                  'home', 'text', 'CTA title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_cta_title_ja', 'お取引を始めませんか？',                  'home', 'text', 'CTA title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_cta_desc_en',  'Contact us for a professional solution', 'home', 'text', 'CTA desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_cta_desc_ja',  'お問い合わせいただければ、最適なソリューションをご提案いたします', 'home', 'text', 'CTA desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // partners
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_links_title_en', 'Partners',  'home', 'text', 'Partners title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('home_links_title_ja', 'パートナー', 'home', 'text', 'Partners title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

            // footer + site_description
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('site_description_en', 'A professional CMS for enterprises, supporting multilingual content, SEO optimization, and responsive design.', 'basic', 'textarea', 'Site description (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('site_description_ja', '企業のデジタルトランスフォーメーションを支えるプロフェッショナル CMS。多言語対応、SEO 最適化、レスポンシブデザイン。', 'basic', 'textarea', 'Site description (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('footer_copyright_text_en', '© {year} {site_name}. All Rights Reserved.', 'footer', 'text', 'Footer copyright (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('footer_copyright_text_ja', '© {year} {site_name}. All Rights Reserved.', 'footer', 'text', 'Footer copyright (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('footer_columns_en', '[{\"title\":\"About Us\",\"content\":\"{{site_description}}\",\"col_span\":2},{\"title\":\"Contact\",\"content\":\"{{contact_info}}\",\"col_span\":1},{\"title\":\"Follow Us\",\"content\":\"{{qrcode}}{{social_icons}}\",\"col_span\":1}]', 'footer', 'textarea', 'Footer columns (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('footer_columns_ja', '[{\"title\":\"会社案内\",\"content\":\"{{site_description}}\",\"col_span\":2},{\"title\":\"お問合せ\",\"content\":\"{{contact_info}}\",\"col_span\":1},{\"title\":\"フォロー\",\"content\":\"{{qrcode}}{{social_icons}}\",\"col_span\":1}]', 'footer', 'textarea', 'Footer columns (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('footer_nav_en', '[{\"title\":\"\",\"links\":[{\"name\":\"Privacy Policy\",\"url\":\"/privacy.html\"},{\"name\":\"Terms of Service\",\"url\":\"/terms.html\"}]}]', 'footer', 'textarea', 'Footer nav (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`, `value`, `group`, `type`, `name`) VALUES ('footer_nav_ja', '[{\"title\":\"\",\"links\":[{\"name\":\"プライバシーポリシー\",\"url\":\"/privacy.html\"},{\"name\":\"利用規約\",\"url\":\"/terms.html\"}]}]', 'footer', 'textarea', 'Footer nav (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        ],
    ],

    [
        'id'    => '20260511_form_template_i18n',
        'title' => '表单模板：加 EN/JA 列',
        'desc'  => '为 yikai_form_templates 加 name_en/name_ja、fields_en/fields_ja、success_message_en/success_message_ja 共 6 个 per-lang 列，让表单模板的显示名称、字段模板、成功提示可以按语言独立存翻译。slug/status 保持全局共享。'
                   . '幂等：检测 fields_en 列已存在则跳过。',
        'check' => function () {
            return _columnExists('form_templates', 'fields_en');
        },
        'sqls' => [
            "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `name_en` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'EN 名称' AFTER `name`",
            "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `name_ja` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'JA 名称' AFTER `name_en`",
            "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `fields_en` TEXT NULL COMMENT 'EN 字段模板' AFTER `fields`",
            "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `fields_ja` TEXT NULL COMMENT 'JA 字段模板' AFTER `fields_en`",
            "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `success_message_en` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'EN 成功提示' AFTER `success_message`",
            "ALTER TABLE `" . DB_PREFIX . "form_templates` ADD COLUMN `success_message_ja` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'JA 成功提示' AFTER `success_message_en`",
        ],
    ],

    [
        'id'    => '20260511_contact_form_translations',
        'title' => '联系表单/卡片：EN/JA 翻译种子',
        'desc'  => '为联系页表单（标题/描述/成功提示/字段标签）和联系卡片（icon+label+value）的 EN/JA 视图预填种子翻译值。'
                   . '幂等：检测到 contact_form_title_en 已存在则跳过。',
        'check' => function () {
            $row = db()->fetchOne("SELECT 1 FROM " . DB_PREFIX . "settings WHERE `key` = 'contact_form_title_en' LIMIT 1");
            return !empty($row);
        },
        'sqls' => [
            // 表单标题
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_title_en', 'Online Inquiry', 'contact', 'text', 'Form title (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_title_ja', 'お問い合わせ', 'contact', 'text', 'Form title (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            // 表单描述
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_desc_en', 'Leave us a message and we''ll get back to you shortly.', 'contact', 'textarea', 'Form desc (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_desc_ja', 'お気軽にメッセージをお寄せください。担当者よりご連絡いたします。', 'contact', 'textarea', 'Form desc (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            // 提交成功提示
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_success_en', 'Thank you! Your message has been received. We''ll contact you soon.', 'contact', 'text', 'Form success (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_success_ja', 'ありがとうございます。お問い合わせを受け付けました。担当者よりご連絡いたします。', 'contact', 'text', 'Form success (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            // 表单字段（标签翻译；key/type/required/enabled 跟源保持一致）
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_fields_en', '[{\"key\":\"name\",\"label\":\"Your Name\",\"type\":\"text\",\"required\":true,\"enabled\":true},{\"key\":\"phone\",\"label\":\"Phone\",\"type\":\"tel\",\"required\":true,\"enabled\":true},{\"key\":\"email\",\"label\":\"Email\",\"type\":\"email\",\"required\":false,\"enabled\":true},{\"key\":\"company\",\"label\":\"Company\",\"type\":\"text\",\"required\":false,\"enabled\":true},{\"key\":\"content\",\"label\":\"Message\",\"type\":\"textarea\",\"required\":true,\"enabled\":true}]', 'contact', 'textarea', 'Form fields (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_form_fields_ja', '[{\"key\":\"name\",\"label\":\"お名前\",\"type\":\"text\",\"required\":true,\"enabled\":true},{\"key\":\"phone\",\"label\":\"電話番号\",\"type\":\"tel\",\"required\":true,\"enabled\":true},{\"key\":\"email\",\"label\":\"メールアドレス\",\"type\":\"email\",\"required\":false,\"enabled\":true},{\"key\":\"company\",\"label\":\"会社名\",\"type\":\"text\",\"required\":false,\"enabled\":true},{\"key\":\"content\",\"label\":\"お問い合わせ内容\",\"type\":\"textarea\",\"required\":true,\"enabled\":true}]', 'contact', 'textarea', 'Form fields (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            // 联系卡片
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_cards_en', '[{\"icon\":\"phone\",\"label\":\"Phone\",\"value\":\"+86-400-888-8888\"},{\"icon\":\"email\",\"label\":\"Email\",\"value\":\"contact@example.com\"},{\"icon\":\"location\",\"label\":\"Address\",\"value\":\"XX Road, Pudong New Area, Shanghai, China\"}]', 'contact', 'contact_cards', 'Contact cards (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
            "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('contact_cards_ja', '[{\"icon\":\"phone\",\"label\":\"電話\",\"value\":\"+86-400-888-8888\"},{\"icon\":\"email\",\"label\":\"メール\",\"value\":\"contact@example.com\"},{\"icon\":\"location\",\"label\":\"住所\",\"value\":\"中国 上海市浦東新区XX路XX号\"}]', 'contact', 'contact_cards', 'Contact cards (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        ],
    ],

    [
        'id'    => '20260511_solution_sample',
        'title' => '解决方案：示例条目 + EN/JA 翻译',
        'desc'  => '解决方案栏目空空如也，加一条示例案例（zh-CN/EN/JA 三语种 + 同一翻译组），让前台 /solution.html / /en/solution-en.html / /ja/solution-ja.html 都有内容可看。'
                   . '幂等：检测到 slug=smart-factory-solution 已存在则跳过。',
        'check' => function () {
            $row = db()->fetchOne("SELECT 1 FROM " . DB_PREFIX . "contents WHERE slug = 'smart-factory-solution' LIMIT 1");
            return !empty($row);
        },
        'sqls' => [],
        'php'  => function (): string {
            // 取三语种解决方案栏目 id
            $rows = db()->fetchAll("SELECT id, lang FROM " . DB_PREFIX . "channels WHERE slug IN ('solution','solution-en','solution-ja')");
            $chId = [];
            foreach ($rows as $r) $chId[$r['lang']] = (int) $r['id'];
            if (empty($chId['zh-CN'])) return '未找到 zh-CN 解决方案栏目，跳过';

            $now = time();
            $zhContent = "<h2>项目背景</h2>\n<p>某大型制造企业生产管理系统老旧，无法实时掌握车间设备状态与生产进度，质量数据靠纸质单据回收，月度对账周期长达 7 天。</p>\n<h2>解决方案</h2>\n<ul>\n  <li><strong>设备联网层：</strong>通过 IoT 网关接入 200+ 台数控机床，采集 OEE / 主轴负载 / 报警代码等关键指标。</li>\n  <li><strong>MES 调度层：</strong>工单实时下发到工位终端，扫码报工自动汇总产量与不良率。</li>\n  <li><strong>BI 可视化：</strong>车间看板 + 移动端报表，管理层随时掌握产能与库存。</li>\n</ul>\n<h2>实施成效</h2>\n<p>上线 6 个月后，设备综合效率（OEE）提升 18%，工单周期缩短 30%，质量追溯从天级缩短到秒级。</p>";
            $enContent = "<h2>Background</h2>\n<p>A large manufacturer's legacy production-management system couldn't track shop-floor equipment status or production progress in real time. Quality data was collected on paper forms and the monthly reconciliation cycle stretched to 7 days.</p>\n<h2>Solution</h2>\n<ul>\n  <li><strong>Device-connectivity layer:</strong> IoT gateways onboarded 200+ CNC machines, capturing OEE, spindle load, and alarm codes.</li>\n  <li><strong>MES scheduling:</strong> Work orders dispatched to station terminals; scan-to-report auto-aggregates output and defect rates.</li>\n  <li><strong>BI dashboards:</strong> Shop-floor displays and mobile reports keep management on top of capacity and inventory.</li>\n</ul>\n<h2>Results</h2>\n<p>Six months after go-live, OEE rose by 18%, work-order cycle time dropped 30%, and quality traceability shortened from days to seconds.</p>";
            $jaContent = "<h2>プロジェクト背景</h2>\n<p>ある大手製造業のお客様は、旧式の生産管理システムでは現場設備の稼働状況や生産進捗をリアルタイムで把握できず、品質データも紙の伝票で回収していました。月次照合には7日かかっていました。</p>\n<h2>ソリューション</h2>\n<ul>\n  <li><strong>設備接続層：</strong>IoTゲートウェイ経由でNC工作機械200台以上を接続し、OEE・主軸負荷・アラームコードなどの主要指標を収集。</li>\n  <li><strong>MESスケジューリング：</strong>作業指示書を作業ステーション端末にリアルタイム配信し、スキャン報告で生産数と不良率を自動集計。</li>\n  <li><strong>BIビジュアライゼーション：</strong>現場ダッシュボードとモバイル帳票で、経営陣がいつでも能力と在庫を把握。</li>\n</ul>\n<h2>導入効果</h2>\n<p>稼働から6ヶ月後、OEEは18%向上、作業指示サイクルは30%短縮、品質トレーサビリティは日単位から秒単位に短縮されました。</p>";

            // 1. 插入 zh 源行
            $zhId = (int) db()->insert('contents', [
                'lang'                 => 'zh-CN',
                'translation_group_id' => 0,
                'channel_id'           => $chId['zh-CN'],
                'type'                 => 'case',
                'title'                => '某制造业智能工厂解决方案',
                'subtitle'             => '从单点信息化到工厂级数字化转型',
                'slug'                 => 'smart-factory-solution',
                'cover'                => 'https://picsum.photos/seed/smart-factory/1200/600',
                'summary'              => '通过 IoT 设备联网、MES 工单调度与 BI 可视化，帮助某大型制造企业实现 OEE 提升 18%、工单周期缩短 30%。',
                'content'              => $zhContent,
                'content_type'         => 'html',
                'tags'                 => 'IoT,MES,智能制造',
                'status'               => 1,
                'is_top'               => 0,
                'is_recommend'         => 1,
                'sort_order'           => 0,
                'views'                => 0,
                'publish_time'         => $now,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            db()->execute("UPDATE " . DB_PREFIX . "contents SET translation_group_id = ? WHERE id = ?", [$zhId, $zhId]);

            // 2. 插入 en 翻译
            if (!empty($chId['en'])) {
                db()->insert('contents', [
                    'lang'                 => 'en',
                    'translation_group_id' => $zhId,
                    'channel_id'           => $chId['en'],
                    'type'                 => 'case',
                    'title'                => 'Smart Factory Solution for a Manufacturer',
                    'subtitle'             => 'From point IT to factory-wide digital transformation',
                    'slug'                 => 'smart-factory-solution-en',
                    'cover'                => 'https://picsum.photos/seed/smart-factory/1200/600',
                    'summary'              => 'By connecting IoT devices, MES dispatch, and BI dashboards, we helped a large manufacturer lift OEE by 18% and shorten work-order cycles by 30%.',
                    'content'              => $enContent,
                    'content_type'         => 'html',
                    'tags'                 => 'IoT,MES,Smart Manufacturing',
                    'status'               => 1,
                    'is_top'               => 0,
                    'is_recommend'         => 1,
                    'sort_order'           => 0,
                    'views'                => 0,
                    'publish_time'         => $now,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }

            // 3. 插入 ja 翻译
            if (!empty($chId['ja'])) {
                db()->insert('contents', [
                    'lang'                 => 'ja',
                    'translation_group_id' => $zhId,
                    'channel_id'           => $chId['ja'],
                    'type'                 => 'case',
                    'title'                => 'ある製造業のスマートファクトリー導入事例',
                    'subtitle'             => '部分的なIT化から、工場全体のデジタルトランスフォーメーションへ',
                    'slug'                 => 'smart-factory-solution-ja',
                    'cover'                => 'https://picsum.photos/seed/smart-factory/1200/600',
                    'summary'              => 'IoT機器接続、MES作業指示、BIダッシュボードを組み合わせ、大手製造業のお客様のOEEを18%向上させ、作業サイクルを30%短縮しました。',
                    'content'              => $jaContent,
                    'content_type'         => 'html',
                    'tags'                 => 'IoT,MES,スマート製造',
                    'status'               => 1,
                    'is_top'               => 0,
                    'is_recommend'         => 1,
                    'sort_order'           => 0,
                    'views'                => 0,
                    'publish_time'         => $now,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }

            return '解决方案示例已添加（zh/en/ja 三语种，翻译组 ' . $zhId . '）';
        },
    ],

    [
        'id'    => '20260511_industry_sample',
        'title' => '行业方案：示例条目 + EN/JA 翻译',
        'desc'  => '"行业方案"栏目空，添加一条零售连锁数字化的示例案例（zh-CN/EN/JA 三语种 + 同一翻译组），让前台 /industry.html / /en/industry-en.html / /ja/industry-ja.html 都有内容可看。'
                   . '幂等：检测到 slug=retail-chain-digitalization 已存在则跳过。',
        'check' => function () {
            $row = db()->fetchOne("SELECT 1 FROM " . DB_PREFIX . "contents WHERE slug = 'retail-chain-digitalization' LIMIT 1");
            return !empty($row);
        },
        'sqls' => [],
        'php'  => function (): string {
            $rows = db()->fetchAll("SELECT id, lang FROM " . DB_PREFIX . "channels WHERE slug IN ('industry','industry-en','industry-ja')");
            $chId = [];
            foreach ($rows as $r) $chId[$r['lang']] = (int) $r['id'];
            if (empty($chId['zh-CN'])) return '未找到 zh-CN 行业方案栏目，跳过';

            $now = time();

            $zhContent = "<h2>行业背景</h2>\n<p>某全国连锁零售品牌旗下 300+ 门店，各门店 POS、会员、库存系统割裂运行，总部无法获取实时销售与库存数据，促销活动响应慢，库存周转率长期低于行业平均。</p>\n<h2>解决方案</h2>\n<ul>\n  <li><strong>统一中台：</strong>POS / 会员 / 库存 / 优惠券 整合到一个数据中台，门店与总部数据实时同步。</li>\n  <li><strong>智能补货：</strong>基于历史销量 + 季节因子 + 天气因子的预测模型，自动生成门店补货建议。</li>\n  <li><strong>会员全渠道：</strong>线上小程序与线下门店共享会员积分体系，全域营销可触达。</li>\n</ul>\n<h2>实施成效</h2>\n<p>上线一年内：门店日均销售提升 12%，缺货率从 8.2% 降至 2.1%，库存周转率提升 25%，会员复购率提升 18%。</p>";
            $enContent = "<h2>Industry Context</h2>\n<p>A nationwide retail chain with 300+ stores ran POS, membership, and inventory systems that didn't talk to each other. HQ had no real-time view of sales or stock; promotions launched slowly and inventory turnover trailed the industry average.</p>\n<h2>Solution</h2>\n<ul>\n  <li><strong>Unified data platform:</strong> POS / membership / inventory / coupons consolidated into one platform; stores and HQ stay in sync.</li>\n  <li><strong>Smart replenishment:</strong> Forecasting model combines historical sales, seasonality, and weather to auto-generate per-store replenishment recommendations.</li>\n  <li><strong>Omnichannel membership:</strong> Online mini-program and offline stores share a single loyalty system, enabling unified marketing reach.</li>\n</ul>\n<h2>Results</h2>\n<p>One year after launch: daily store sales up 12%, out-of-stock rate down from 8.2% to 2.1%, inventory turnover up 25%, member repurchase rate up 18%.</p>";
            $jaContent = "<h2>業界背景</h2>\n<p>全国に300店舗以上を展開する小売チェーンでは、各店舗のPOS・会員・在庫システムが分断されており、本部はリアルタイムの売上・在庫データを取得できず、販促活動の対応も遅れ、在庫回転率は業界平均を下回っていました。</p>\n<h2>ソリューション</h2>\n<ul>\n  <li><strong>統合データ基盤：</strong>POS／会員／在庫／クーポンを一つのデータ基盤に統合し、店舗と本部のデータをリアルタイム同期。</li>\n  <li><strong>スマート補充：</strong>過去の販売実績＋季節要因＋天候要因を組み合わせた予測モデルで、店舗別の補充推奨を自動生成。</li>\n  <li><strong>会員のオムニチャネル化：</strong>オンラインミニプログラムと実店舗で会員ポイントを共有し、全チャネルでマーケティングが可能に。</li>\n</ul>\n<h2>導入効果</h2>\n<p>稼働1年後：店舗の日次売上12%向上、欠品率8.2%から2.1%へ低減、在庫回転率25%向上、会員リピート率18%向上。</p>";

            // 1. 插入 zh 源行
            $zhId = (int) db()->insert('contents', [
                'lang'                 => 'zh-CN',
                'translation_group_id' => 0,
                'channel_id'           => $chId['zh-CN'],
                'type'                 => 'case',
                'title'                => '零售连锁数字化升级方案',
                'subtitle'             => '300+门店的统一中台、智能补货与会员全渠道',
                'slug'                 => 'retail-chain-digitalization',
                'cover'                => 'https://picsum.photos/seed/retail-chain/1200/600',
                'summary'              => '为全国连锁零售品牌打通 POS / 会员 / 库存 / 优惠券四大系统，借助智能补货模型，店均销售提升 12%，缺货率从 8.2% 降至 2.1%。',
                'content'              => $zhContent,
                'content_type'         => 'html',
                'tags'                 => '零售,中台,智能补货,会员',
                'status'               => 1,
                'is_top'               => 0,
                'is_recommend'         => 1,
                'sort_order'           => 0,
                'views'                => 0,
                'publish_time'         => $now,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            db()->execute("UPDATE " . DB_PREFIX . "contents SET translation_group_id = ? WHERE id = ?", [$zhId, $zhId]);

            // 2. en
            if (!empty($chId['en'])) {
                db()->insert('contents', [
                    'lang'                 => 'en',
                    'translation_group_id' => $zhId,
                    'channel_id'           => $chId['en'],
                    'type'                 => 'case',
                    'title'                => 'Retail Chain Digital Transformation',
                    'subtitle'             => 'Unified platform, smart replenishment, and omnichannel membership for 300+ stores',
                    'slug'                 => 'retail-chain-digitalization-en',
                    'cover'                => 'https://picsum.photos/seed/retail-chain/1200/600',
                    'summary'              => 'Unified POS, membership, inventory, and coupons for a national retail chain. With a smart replenishment model, daily store sales rose 12% and the out-of-stock rate fell from 8.2% to 2.1%.',
                    'content'              => $enContent,
                    'content_type'         => 'html',
                    'tags'                 => 'Retail,Platform,Replenishment,Membership',
                    'status'               => 1,
                    'is_top'               => 0,
                    'is_recommend'         => 1,
                    'sort_order'           => 0,
                    'views'                => 0,
                    'publish_time'         => $now,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }

            // 3. ja
            if (!empty($chId['ja'])) {
                db()->insert('contents', [
                    'lang'                 => 'ja',
                    'translation_group_id' => $zhId,
                    'channel_id'           => $chId['ja'],
                    'type'                 => 'case',
                    'title'                => '小売チェーンのデジタル変革ソリューション',
                    'subtitle'             => '300店舗以上のための統合基盤・スマート補充・会員オムニチャネル',
                    'slug'                 => 'retail-chain-digitalization-ja',
                    'cover'                => 'https://picsum.photos/seed/retail-chain/1200/600',
                    'summary'              => '全国小売チェーン向けに POS／会員／在庫／クーポンを統合。スマート補充モデルにより、店舗日次売上を12%向上、欠品率を8.2%から2.1%に低減しました。',
                    'content'              => $jaContent,
                    'content_type'         => 'html',
                    'tags'                 => '小売,プラットフォーム,補充,会員',
                    'status'               => 1,
                    'is_top'               => 0,
                    'is_recommend'         => 1,
                    'sort_order'           => 0,
                    'views'                => 0,
                    'publish_time'         => $now,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }

            return '行业方案示例已添加（zh/en/ja 三语种，翻译组 ' . $zhId . '）';
        },
    ],

    [
        'id'    => '20260511_form_template_seed_translations',
        'title' => '表单模板：EN/JA 翻译种子（contact / product-inquiry）',
        'desc'  => '为 yikai_form_templates 现有的两条记录（contact、product-inquiry）填充 name_en/ja、fields_en/ja、success_message_en/ja 列，让前台 /en/contact.html、/ja/contact.html 的表单按当前语言显示字段标签和成功提示。'
                   . '幂等：检测到 contact 行的 fields_en 已非空则跳过。前置依赖：「表单模板：加 EN/JA 列」迁移已执行。',
        'check' => function () {
            $v = (string) db()->fetchColumn("SELECT fields_en FROM " . DB_PREFIX . "form_templates WHERE slug = 'contact' LIMIT 1");
            return $v !== '';
        },
        'sqls' => [
            // contact 表单
            "UPDATE `" . DB_PREFIX . "form_templates` SET
                `name_en` = 'Contact Form',
                `name_ja` = 'お問い合わせフォーム',
                `fields_en` = '[{\"key\":\"name\",\"label\":\"Your Name\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"Phone\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"Email\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"Company\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"Message\",\"type\":\"textarea\",\"required\":true}]',
                `fields_ja` = '[{\"key\":\"name\",\"label\":\"お名前\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"電話番号\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"メールアドレス\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"会社名\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"お問い合わせ内容\",\"type\":\"textarea\",\"required\":true}]',
                `success_message_en` = 'Thank you! Your message has been received. We will contact you shortly.',
                `success_message_ja` = 'ありがとうございます。お問い合わせを受け付けました。担当者よりご連絡いたします。'
             WHERE `slug` = 'contact'",

            // product-inquiry 产品询盘
            "UPDATE `" . DB_PREFIX . "form_templates` SET
                `name_en` = 'Product Inquiry',
                `name_ja` = '製品お問い合わせ',
                `fields_en` = '[{\"key\":\"name\",\"label\":\"Your Name\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"Phone\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"Email\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"Company\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"Inquiry Details\",\"type\":\"textarea\",\"required\":true}]',
                `fields_ja` = '[{\"key\":\"name\",\"label\":\"お名前\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"電話番号\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"メールアドレス\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"会社名\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"お問い合わせ内容\",\"type\":\"textarea\",\"required\":true}]',
                `success_message_en` = 'Thank you for your inquiry. We will contact you shortly.',
                `success_message_ja` = 'お問い合わせいただきありがとうございます。担当者よりご連絡いたします。'
             WHERE `slug` = 'product-inquiry'",
        ],
    ],

    // --- 未来升级项追加到这里 ---

];

// AJAX: 在线升级检测（服务端代理，避免 CORS）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_update') {
    header('Content-Type: application/json; charset=utf-8');
    $currentVersion = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
    $updateServerUrl = 'https://update.yikaicms.com';
    $apiUrl = $updateServerUrl . '/api/update/check.php?version=' . urlencode($currentVersion);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($apiUrl, false, $context);

    if ($response === false) {
        // 尝试 curl 作为备选
        if (function_exists('curl_init')) {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false || $httpCode >= 400) {
                echo json_encode(['code' => 1, 'msg' => '无法连接更新服务器' . ($error ? ': ' . $error : ' (HTTP ' . $httpCode . ')')], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            echo json_encode(['code' => 1, 'msg' => '无法连接更新服务器，请检查网络或服务器 PHP 配置'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $data = json_decode($response, true);
    if ($data === null) {
        echo json_encode(['code' => 1, 'msg' => '更新服务器返回数据格式错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo $response;
    exit;
}

// AJAX 执行升级
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    // 抑制响应被任何 warning/notice 污染 (会破坏 JSON 解析)
    ob_start();

    $runIds = (array)$_POST['run'];
    $results = [];
    foreach ($upgrades as $up) {
        if (!in_array($up['id'], $runIds)) continue;
        try {
            // check() 也可能抛错(连不上 DB / 表不存在 / 权限等),包进 try/catch
            $alreadyDone = (bool)$up['check']();
        } catch (\Throwable $e) {
            $results[$up['id']] = ['status' => 'error', 'message' => 'check failed: ' . $e->getMessage()];
            continue;
        }
        if ($alreadyDone) {
            $results[$up['id']] = ['status' => 'skipped', 'message' => '已是最新，无需升级'];
            continue;
        }
        try {
            // 支持 PHP 回调迁移
            if (!empty($up['php']) && is_callable($up['php'])) {
                $msg = ($up['php'])();
                $results[$up['id']] = ['status' => 'success', 'message' => $msg ?: __('upgrade_success')];
            } else {
                foreach ($up['sqls'] as $sql) {
                    if (db()->isSqlite()) {
                        $sql = _sqlToSqlite($sql);
                        if ($sql === null) continue;
                    }
                    db()->execute($sql);
                }
                $results[$up['id']] = ['status' => 'success', 'message' => __('upgrade_success')];
            }
            // adminLog 失败不影响升级响应
            try { adminLog('upgrade', 'execute', '执行升级: ' . $up['title']); } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            $results[$up['id']] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    // 升级完成后，自动更新数据库中的版本号
    $currentVersion = defined('CMS_VERSION') ? CMS_VERSION : '1.3.0';
    try {
        $exists = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE `key` = 'cms_version'");
        if ($exists) {
            db()->execute("UPDATE " . DB_PREFIX . "settings SET `value` = ? WHERE `key` = 'cms_version'", [$currentVersion]);
        }
    } catch (\Throwable $e) {}

    // 丢弃任何意外输出 (warnings/notices/BOM/echo 等)
    ob_end_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'data' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检测各项状态
foreach ($upgrades as &$up) {
    $up['status'] = $up['check']() ? 'done' : 'pending';
}
unset($up);

// 分离：待升级 / 已完成
$pendingUpgrades = [];
$doneUpgrades = [];
foreach ($upgrades as $up) {
    if ($up['status'] === 'pending') {
        $pendingUpgrades[] = $up;
    } else {
        $doneUpgrades[] = $up;
    }
}

$tab = $_GET['tab'] ?? 'check';
$pageTitle = '升级管理';
$currentMenu = $tab === 'online' ? 'online_upgrade' : 'upgrade';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/upgrade.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'check' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            升级检测
            <?php if (!empty($pendingUpgrades)): ?>
            <span class="ml-1.5 inline-block w-5 h-5 leading-5 text-center rounded-full bg-red-500 text-white text-xs"><?php echo count($pendingUpgrades); ?></span>
            <?php endif; ?>
        </a>
        <a href="/admin/upgrade.php?tab=history" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'history' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('upgrade_history'); ?></a>
        <a href="/admin/upgrade.php?tab=online" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'online' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('upgrade_online'); ?></a>
    </div>
</div>

<?php if ($tab === 'check'): ?>
<div class="max-w-3xl">
    <?php if (empty($pendingUpgrades)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <svg class="w-16 h-16 mx-auto text-green-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <p class="text-green-600 font-medium text-lg mb-2"><?php echo __('upgrade_up_to_date'); ?></p>
        <p class="text-gray-400 text-sm"><?php echo __('upgrade_all_done'); ?></p>
    </div>
    <?php else: ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800 mb-6">
        检测到 <?php echo count($pendingUpgrades); ?> 项待升级，升级前请确保已备份数据库。
    </div>

    <div id="upgradeList" class="space-y-4">
    <?php foreach ($pendingUpgrades as $up): ?>
    <div class="bg-white rounded-lg shadow" data-id="<?php echo $up['id']; ?>">
        <div class="px-5 py-4 border-b flex items-center gap-3">
            <input type="checkbox" class="upgrade-check w-4 h-4" value="<?php echo $up['id']; ?>" checked>
            <span class="font-semibold flex-1"><?php echo htmlspecialchars($up['title']); ?></span>
            <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
            <span class="upgrade-badge inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">待升级</span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-500">
            <?php echo htmlspecialchars($up['desc']); ?>
            <div class="upgrade-msg mt-2 hidden"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="mt-6">
        <button id="btnUpgrade" onclick="runUpgrade()" class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            执行升级
        </button>
    </div>
    <?php endif; ?>
</div>

<script>
async function runUpgrade() {
    var checks = document.querySelectorAll('.upgrade-check:checked');
    if (!checks.length) { showMessage('请选择要升级的项目', 'error'); return; }

    var ids = [];
    checks.forEach(function(c) { ids.push(c.value); });

    var btn = document.getElementById('btnUpgrade');
    btn.disabled = true;
    btn.textContent = '<?php echo __('upgrade_running'); ?>';

    var formData = new FormData();
    ids.forEach(function(id) { formData.append('run[]', id); });

    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);

        if (data.code === 0) {
            var allSuccess = true;
            for (var id in data.data) {
                var item = data.data[id];
                var card = document.querySelector('[data-id="' + id + '"]');
                if (!card) continue;

                var badge = card.querySelector('.upgrade-badge');
                var msg = card.querySelector('.upgrade-msg');
                var check = card.querySelector('.upgrade-check');

                if (check) check.remove();

                if (badge) {
                    if (item.status === 'success') {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700';
                        badge.textContent = '<?php echo __('upgrade_success'); ?>';
                    } else if (item.status === 'error') {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700';
                        badge.textContent = '<?php echo __('upgrade_failed'); ?>';
                        allSuccess = false;
                    } else {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500';
                        badge.textContent = '<?php echo __('upgrade_skipped'); ?>';
                    }
                }

                if (msg && item.status === 'error') {
                    msg.className = 'upgrade-msg mt-2 text-red-600 text-xs';
                    msg.textContent = item.message;
                } else if (msg && item.status === 'success') {
                    msg.className = 'upgrade-msg mt-2 text-green-600 text-xs';
                    msg.textContent = item.message;
                }
            }
            showMessage('升级完成');
            if (allSuccess) {
                setTimeout(function() { location.reload(); }, 1500);
            }
        } else {
            showMessage(data.msg || '<?php echo __('upgrade_failed'); ?>', 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }

    btn.disabled = false;
    btn.textContent = '<?php echo __('upgrade_execute'); ?>';
}
</script>
<?php endif; ?>

<?php if ($tab === 'history'): ?>
<div class="max-w-3xl">
    <?php if (empty($doneUpgrades)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <p class="text-gray-400"><?php echo __('upgrade_no_history'); ?></p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
    <?php foreach (array_reverse($doneUpgrades) as $up): ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-5 py-4 border-b flex items-center gap-3">
            <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-semibold flex-1"><?php echo htmlspecialchars($up['title']); ?></span>
            <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
            <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700"><?php echo __('upgrade_completed'); ?></span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-500">
            <?php echo htmlspecialchars($up['desc']); ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'online'): ?>
<?php
// 在线升级配置
$updateServerUrl = 'https://update.yikaicms.com';
$updateCheckApi  = $updateServerUrl . '/api/update/check';
$currentVersion  = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
?>
<div class="max-w-2xl">
    <!-- 当前版本信息 -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo __('upgrade_online'); ?></h2>
            <span class="text-sm text-gray-400">更新服务器：<?php echo e($updateServerUrl); ?></span>
        </div>
        <div class="px-6 py-5">
            <div class="flex items-center gap-4 mb-4">
                <div class="flex-shrink-0 w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-gray-800 font-medium">YikaiCMS</p>
                    <p class="text-sm text-gray-500">当前版本：<span class="font-mono font-medium text-primary">v<?php echo e($currentVersion); ?></span></p>
                </div>
            </div>

            <div id="updateResult" class="hidden"></div>

            <button id="btnCheckUpdate" onclick="checkUpdate()" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded transition inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                检测更新
            </button>
        </div>
    </div>

    <!-- 升级说明 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h3 class="font-medium text-gray-700">升级说明</h3>
        </div>
        <div class="px-6 py-4 text-sm text-gray-500 space-y-2">
            <p>1. 升级前请务必<strong class="text-gray-700">备份数据库和网站文件</strong>。</p>
            <p>2. 系统会自动从 <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs"><?php echo e($updateServerUrl); ?></code> 检测是否有新版本。</p>
            <p>3. 检测到新版本后，请按照提示下载更新包并按步骤完成升级。</p>
            <p>4. 升级完成后，建议访问 <a href="/admin/upgrade.php" class="text-primary hover:underline"><?php echo __('upgrade_check'); ?></a> 页面执行数据库升级。</p>
        </div>
    </div>
</div>

<script>
var currentVersion = <?php echo json_encode($currentVersion); ?>;

async function checkUpdate() {
    var btn = document.getElementById('btnCheckUpdate');
    var result = document.getElementById('updateResult');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> 检测中...';

    try {
        var formData = new FormData();
        formData.append('action', 'check_update');
        var response = await fetch('', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) {
            throw new Error('服务器响应异常 (HTTP ' + response.status + ')');
        }

        var data = await safeJson(response);
        result.classList.remove('hidden');

        if (data.code === 0 && data.data && data.data.has_update) {
            var d = data.data;
            result.innerHTML = '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">'
                + '<div class="flex items-start gap-3">'
                + '<svg class="w-5 h-5 text-blue-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                + '<div class="flex-1">'
                + '<p class="font-medium text-blue-800 mb-1">发现新版本 <span class="font-mono">v' + escapeHtml(d.latest_version) + '</span></p>'
                + (d.release_date ? '<p class="text-sm text-blue-600 mb-2">发布日期：' + escapeHtml(d.release_date) + '</p>' : '')
                + (d.changelog ? '<div class="text-sm text-blue-700 mb-3 whitespace-pre-line">' + escapeHtml(d.changelog) + '</div>' : '')
                + (d.download_url ? '<a href="' + escapeHtml(d.download_url) + '" target="_blank" class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> 下载更新包</a>' : '')
                + '</div></div></div>';
        } else if (data.code === 0) {
            result.innerHTML = '<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
                + '<svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                + '<p class="text-green-700">当前已是最新版本 <span class="font-mono font-medium">v' + escapeHtml(currentVersion) + '</span></p>'
                + '</div>';
        } else {
            throw new Error(data.msg || '检测失败');
        }
    } catch (err) {
        result.classList.remove('hidden');
        result.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
            + '<svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            + '<div><p class="text-red-700 font-medium">检测失败</p><p class="text-red-600 text-sm mt-0.5">' + escapeHtml(err.message) + '</p></div>'
            + '</div>';
    }

    btn.disabled = false;
    btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg> 重新检测';
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
