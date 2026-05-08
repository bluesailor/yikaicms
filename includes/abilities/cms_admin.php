<?php
/**
 * Yikai CMS - 后台管理类 abilities
 *
 * 设置读写、栏目浏览、内容标志位、后台导航——
 * 让 AI 能完成"修改 LOGO / 改邮箱 / 找页面 / 把这篇置顶"等管理任务。
 */

declare(strict_types=1);

if (!class_exists('Abilities')) return;

// ─────────────────────────────────────────────────────────
// 9) 后台页面导航（高频）：自然语言 → URL
// ─────────────────────────────────────────────────────────
register_ability('cms_navigate_admin', [
    'label'        => '查找后台页面',
    'description'  => '根据自然语言描述返回对应的后台 URL 列表，便于用户快速跳转。例如"修改邮箱""上传 LOGO""管理产品"。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'query' => ['type' => 'string', 'description' => '想找的功能描述'],
        ],
        'required' => ['query'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        require_once dirname(__DIR__) . '/admin_pages_catalog.php';
        $hits = adminPagesSearch((string)$input['query'], 5);
        return array_map(fn($p) => ['url' => $p['url'], 'title' => $p['title']], $hits);
    },
]);

// ─────────────────────────────────────────────────────────
// 10) 列出常用设置项
// ─────────────────────────────────────────────────────────
register_ability('cms_list_common_settings', [
    'label'        => '列出常用设置项',
    'description'  => '返回常见站点设置 key 的列表与说明，便于 AI 知道有哪些可读写的设置。',
    'input_schema' => ['type' => 'object', 'properties' => []],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (): array {
        return [
            ['key' => 'site_name',         'desc' => '网站名称'],
            ['key' => 'site_keywords',     'desc' => 'SEO 关键词，逗号分隔'],
            ['key' => 'site_description',  'desc' => 'SEO 描述'],
            ['key' => 'site_logo',         'desc' => '前台 LOGO 图片 URL'],
            ['key' => 'site_favicon',      'desc' => '网站图标 URL'],
            ['key' => 'site_url',          'desc' => '站点完整 URL（含 https://）'],
            ['key' => 'site_icp',          'desc' => '备案号'],
            ['key' => 'admin_title',       'desc' => '后台标题'],
            ['key' => 'admin_logo',        'desc' => '后台 LOGO URL'],
            ['key' => 'admin_copyright',   'desc' => '后台版权信息'],
            ['key' => 'contact_phone',     'desc' => '联系电话'],
            ['key' => 'contact_email',     'desc' => '联系邮箱'],
            ['key' => 'contact_address',   'desc' => '公司地址'],
            ['key' => 'contact_qrcode',    'desc' => '微信二维码 URL'],
            ['key' => 'primary_color',     'desc' => '主色调 hex'],
            ['key' => 'secondary_color',   'desc' => '辅助色 hex'],
            ['key' => 'footer_copyright',  'desc' => '页脚版权'],
            ['key' => 'mail_from',         'desc' => '发件人邮箱'],
            ['key' => 'mail_admin',        'desc' => '管理员通知邮箱'],
            ['key' => 'show_lang_switcher','desc' => '是否显示语言切换器（0/1）'],
        ];
    },
]);

// ─────────────────────────────────────────────────────────
// 11) 读取设置
// ─────────────────────────────────────────────────────────
register_ability('cms_get_setting', [
    'label'        => '读取站点设置',
    'description'  => '通过 key 读取一项站点设置当前值。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'key' => ['type' => 'string', 'description' => '设置键名，如 site_name / contact_email'],
        ],
        'required' => ['key'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $key = trim((string)$input['key']);
        // 敏感项：禁止读取
        if (in_array($key, ['ai_api_key', 'smtp_pass', 'license_key'], true) || str_starts_with($key, '_')) {
            throw new \RuntimeException("Setting key '{$key}' is restricted");
        }
        $value = config($key, null);
        return ['key' => $key, 'value' => $value, 'exists' => $value !== null];
    },
]);

// ─────────────────────────────────────────────────────────
// 12) 修改设置（仅超管，受白名单前缀保护）
// ─────────────────────────────────────────────────────────
register_ability('cms_update_setting', [
    'label'        => '修改站点设置',
    'description'  => '更新一项站点设置（如修改 LOGO URL / 联系电话 / 邮箱 / 网站标题）。仅超管可调用，敏感字段已屏蔽。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'key'   => ['type' => 'string', 'description' => '设置键名'],
            'value' => ['type' => 'string', 'description' => '新值（任意字符串）'],
        ],
        'required' => ['key', 'value'],
    ],
    'permission'   => fn() => function_exists('isSuperAdmin') && isSuperAdmin(),
    'execute'      => function (array $input): array {
        $key = trim((string)$input['key']);
        // 黑名单
        $blocked = ['ai_api_key', 'smtp_pass', 'license_key', 'encrypt_key'];
        if (in_array($key, $blocked, true) || str_starts_with($key, '_')) {
            throw new \RuntimeException("Setting key '{$key}' is restricted");
        }
        // 白名单前缀（防止意外改到不该改的）
        $allowedPrefixes = ['site_', 'admin_', 'contact_', 'social_', 'footer_', 'mail_', 'banner_', 'primary_', 'secondary_', 'show_', 'product_', 'download_', 'allow_', 'html_cache_', 'cache_'];
        $prefixOk = false;
        foreach ($allowedPrefixes as $p) {
            if (str_starts_with($key, $p)) { $prefixOk = true; break; }
        }
        if (!$prefixOk) {
            throw new \RuntimeException("Setting key '{$key}' is not in the AI-modifiable allowlist");
        }
        $oldValue = config($key, null);
        settingModel()->set($key, $input['value']);
        if (function_exists('adminLog')) {
            adminLog('setting', 'ai_update', "AI 修改 {$key}");
        }
        return ['key' => $key, 'old_value' => $oldValue, 'new_value' => $input['value']];
    },
]);

// ─────────────────────────────────────────────────────────
// 13) 列出栏目
// ─────────────────────────────────────────────────────────
register_ability('cms_list_channels', [
    'label'        => '列出栏目',
    'description'  => '列出所有栏目（带层级），用于 AI 创建文章/产品时选择 channel_id。',
    'input_schema' => ['type' => 'object', 'properties' => []],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (): array {
        $rows = db()->fetchAll(
            'SELECT id, parent_id, name, slug, type FROM ' . DB_PREFIX . 'channels ORDER BY parent_id, sort_order, id'
        );
        return array_map(fn($r) => [
            'id'        => (int)$r['id'],
            'parent_id' => (int)$r['parent_id'],
            'name'      => (string)$r['name'],
            'slug'      => (string)$r['slug'],
            'type'      => (string)$r['type'],
        ], $rows);
    },
]);

// ─────────────────────────────────────────────────────────
// 14) 切换内容标志位（置顶 / 推荐 / 热门 / 新品）
// ─────────────────────────────────────────────────────────
register_ability('cms_set_content_flags', [
    'label'        => '切换内容标志',
    'description'  => '为指定内容设置标志位：is_top（置顶）/ is_recommend（推荐）/ is_hot（热门）/ is_new（新品）。每个标志为 0/1。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'           => ['type' => 'integer'],
            'is_top'       => ['type' => 'integer'],
            'is_recommend' => ['type' => 'integer'],
            'is_hot'       => ['type' => 'integer'],
            'is_new'       => ['type' => 'integer'],
        ],
        'required' => ['id'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $id = (int)$input['id'];
        $row = db()->fetchOne('SELECT id, title FROM ' . DB_PREFIX . 'contents WHERE id = ?', [$id]);
        if (!$row) throw new \RuntimeException("Content #{$id} not found");

        $sets = [];
        $params = [];
        foreach (['is_top', 'is_recommend', 'is_hot', 'is_new'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $sets[] = "{$flag} = ?";
                $params[] = (int)$input[$flag] ? 1 : 0;
            }
        }
        if ($sets === []) {
            return ['id' => $id, 'message' => 'no flag specified'];
        }
        $params[] = time();
        $params[] = $id;
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'contents SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ?',
            $params
        );
        return ['id' => $id, 'title' => $row['title'], 'updated_flags' => array_keys(array_intersect_key($input, array_flip(['is_top', 'is_recommend', 'is_hot', 'is_new'])))];
    },
]);
