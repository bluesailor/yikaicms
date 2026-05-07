<?php
/**
 * Yikai CMS - 核心 abilities 注册
 *
 * 这些 abilities 让 AI 能通过 function-calling 操作 CMS。
 * 由 init.php 在 Abilities.php 加载后引入。
 *
 * 增加新能力：用 register_ability() 即可。建议把站点专用的能力放到 plugin 中。
 */

declare(strict_types=1);

if (!class_exists('Abilities')) {
    return; // 防御：被错误时序加载时安静退出
}

// ─────────────────────────────────────────────────────────
// 1) 搜索内容
// ─────────────────────────────────────────────────────────
register_ability('cms_search_content', [
    'label'        => '搜索内容',
    'description'  => '按关键词搜索已发布的文章 / 内容；返回 id、title、summary、channel 列表，最多 limit 条。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'keyword' => ['type' => 'string', 'description' => '搜索关键词，匹配标题或摘要'],
            'limit'   => ['type' => 'integer', 'description' => '返回条数，默认 10，最大 50'],
        ],
        'required' => ['keyword'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $kw    = trim((string)($input['keyword'] ?? ''));
        $limit = max(1, min(50, (int)($input['limit'] ?? 10)));
        if ($kw === '') return [];
        $rows = contentModel()->getList(0, $limit, 0, ['keyword' => $kw, '_skip_lang' => true]);
        return array_map(fn($r) => [
            'id'      => (int)$r['id'],
            'title'   => (string)$r['title'],
            'summary' => mb_substr((string)($r['summary'] ?? ''), 0, 120),
            'channel' => (string)($r['channel_name'] ?? ''),
            'status'  => (int)$r['status'],
        ], $rows);
    },
]);

// ─────────────────────────────────────────────────────────
// 2) 列出草稿
// ─────────────────────────────────────────────────────────
register_ability('cms_list_drafts', [
    'label'        => '列出草稿',
    'description'  => '列出当前所有未发布（status=0）的草稿内容，便于后续发布或编辑。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'limit' => ['type' => 'integer', 'description' => '最大返回数，默认 20'],
        ],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $limit = max(1, min(100, (int)($input['limit'] ?? 20)));
        $sql = 'SELECT id, title, channel_id, created_at FROM ' . DB_PREFIX . 'contents WHERE status = 0 ORDER BY id DESC LIMIT ?';
        $rows = db()->fetchAll($sql, [$limit]);
        return array_map(fn($r) => [
            'id'         => (int)$r['id'],
            'title'      => (string)$r['title'],
            'channel_id' => (int)$r['channel_id'],
            'created_at' => date('Y-m-d H:i', (int)$r['created_at']),
        ], $rows);
    },
]);

// ─────────────────────────────────────────────────────────
// 3) 获取单条内容（含正文）
// ─────────────────────────────────────────────────────────
register_ability('cms_get_content', [
    'label'        => '读取内容详情',
    'description'  => '通过 id 读取一条内容的完整信息（含正文），用于阅读理解 / 二次编辑。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required'   => ['id'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $id   = (int)$input['id'];
        $row  = contentModel()->getDetail($id);
        if (!$row) throw new \RuntimeException("Content #{$id} not found");
        return [
            'id'      => (int)$row['id'],
            'title'   => (string)$row['title'],
            'summary' => (string)($row['summary'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
            'tags'    => (string)($row['tags'] ?? ''),
            'status'  => (int)$row['status'],
        ];
    },
]);

// ─────────────────────────────────────────────────────────
// 4) 发布草稿
// ─────────────────────────────────────────────────────────
register_ability('cms_publish_content', [
    'label'        => '发布内容',
    'description'  => '把指定 id 的内容设为已发布（status=1）。用于把 AI 创建的草稿一键上线。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required'   => ['id'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $id = (int)$input['id'];
        $row = db()->fetchOne('SELECT id, title, status FROM ' . DB_PREFIX . 'contents WHERE id = ?', [$id]);
        if (!$row) throw new \RuntimeException("Content #{$id} not found");
        if ((int)$row['status'] === 1) {
            return ['id' => $id, 'title' => $row['title'], 'already_published' => true];
        }
        $publishTime = time();
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'contents SET status = 1, publish_time = ?, updated_at = ? WHERE id = ?',
            [$publishTime, $publishTime, $id]
        );
        return ['id' => $id, 'title' => $row['title'], 'published_at' => date('Y-m-d H:i:s', $publishTime)];
    },
]);

// ─────────────────────────────────────────────────────────
// 5) 创建草稿（AI 直接落库）
// ─────────────────────────────────────────────────────────
register_ability('cms_create_article_draft', [
    'label'        => '创建文章草稿',
    'description'  => '创建一篇新文章草稿（status=0），返回新建 id。channel_id 必填；type 默认 article。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'title'      => ['type' => 'string', 'description' => '文章标题'],
            'channel_id' => ['type' => 'integer', 'description' => '所属栏目 id'],
            'content'    => ['type' => 'string', 'description' => '正文（HTML 允许）'],
            'summary'    => ['type' => 'string', 'description' => '摘要，可选'],
            'tags'       => ['type' => 'string', 'description' => '逗号分隔标签，可选'],
            'type'       => ['type' => 'string', 'enum' => ['article', 'product', 'case', 'download', 'job']],
        ],
        'required' => ['title', 'channel_id', 'content'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $now = time();
        $row = [
            'lang'       => function_exists('siteLang') ? siteLang() : 'zh-CN',
            'channel_id' => (int)$input['channel_id'],
            'type'       => $input['type'] ?? 'article',
            'title'      => mb_substr((string)$input['title'], 0, 255),
            'summary'    => (string)($input['summary'] ?? ''),
            'content'    => (string)$input['content'],
            'tags'       => (string)($input['tags'] ?? ''),
            'status'     => 0, // 草稿
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $row['slug'] = function_exists('generateSlug') ? generateSlug($row['title']) : '';
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . DB_PREFIX . 'contents (' . implode(',', $cols) . ') VALUES (' .
               implode(',', array_fill(0, count($cols), '?')) . ')';
        db()->execute($sql, array_values($row));
        $newId = (int)db()->lastInsertId();
        return ['id' => $newId, 'title' => $row['title'], 'status' => 'draft'];
    },
]);

// ─────────────────────────────────────────────────────────
// 6) AI 生成 SEO 摘要并写回
// ─────────────────────────────────────────────────────────
register_ability('cms_generate_seo_summary', [
    'label'        => '生成 SEO 摘要',
    'description'  => '读取指定文章正文，让 AI 生成 80-120 字的 SEO 摘要并保存到 summary 字段。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['id' => ['type' => 'integer']],
        'required'   => ['id'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $id = (int)$input['id'];
        $row = contentModel()->getDetail($id);
        if (!$row) throw new \RuntimeException("Content #{$id} not found");

        $plain = trim(strip_tags((string)$row['content']));
        if ($plain === '') throw new \RuntimeException('Empty content');
        $excerpt = mb_substr($plain, 0, 1500);

        $sys = '你是 SEO 编辑。根据正文写一段 80-120 字的中文摘要，突出关键词，文末不留省略号，仅输出摘要本身。';
        $r = aiService()->chat("标题：{$row['title']}\n\n正文片段：\n{$excerpt}", $sys, 0.4);
        if (!$r['success']) throw new \RuntimeException($r['error'] ?: 'AI failed');

        $summary = mb_substr(trim($r['content']), 0, 500);
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'contents SET summary = ?, updated_at = ? WHERE id = ?',
            [$summary, time(), $id]
        );
        return ['id' => $id, 'summary' => $summary];
    },
]);

// ─────────────────────────────────────────────────────────
// 7) AI 生成标签并写回
// ─────────────────────────────────────────────────────────
register_ability('cms_auto_tag_content', [
    'label'        => '自动生成标签',
    'description'  => '读取文章标题与摘要，让 AI 输出 3-6 个标签（逗号分隔），写入 tags 字段。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'id'    => ['type' => 'integer'],
            'count' => ['type' => 'integer', 'description' => '标签个数，默认 5'],
        ],
        'required' => ['id'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): array {
        $id    = (int)$input['id'];
        $count = max(3, min(8, (int)($input['count'] ?? 5)));
        $row = contentModel()->getDetail($id);
        if (!$row) throw new \RuntimeException("Content #{$id} not found");

        $base = trim($row['title'] . "\n" . ($row['summary'] ?? ''));
        if ($base === '') $base = mb_substr(strip_tags((string)$row['content']), 0, 500);

        $sys = "提取关键标签。规则：输出严格 {$count} 个标签，逗号分隔，每个 ≤ 6 字，不带前缀，不要序号、不要解释。";
        $r = aiService()->chat($base, $sys, 0.3);
        if (!$r['success']) throw new \RuntimeException($r['error'] ?: 'AI failed');

        // 规整：去 # / 序号 / 引号
        $raw = preg_replace('/[#\d\.\)\(\[\]"\'\s]+/u', '', $r['content']);
        $tags = array_values(array_filter(array_map('trim', explode(',', str_replace(['，', '、', ';'], ',', (string)$r['content'])))));
        $tags = array_slice($tags, 0, $count);
        $tagStr = mb_substr(implode(',', $tags), 0, 255);

        db()->execute(
            'UPDATE ' . DB_PREFIX . 'contents SET tags = ?, updated_at = ? WHERE id = ?',
            [$tagStr, time(), $id]
        );
        return ['id' => $id, 'tags' => $tagStr];
    },
]);

// ─────────────────────────────────────────────────────────
// 8) 翻译文本
// ─────────────────────────────────────────────────────────
register_ability('cms_translate_text', [
    'label'        => '翻译文本',
    'description'  => '把一段文本翻译成目标语言（zh-CN/en/ja），用于多语版本生成。',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'text'        => ['type' => 'string'],
            'target_lang' => ['type' => 'string', 'enum' => ['zh-CN', 'en', 'ja']],
        ],
        'required' => ['text', 'target_lang'],
    ],
    'permission'   => fn() => !empty($_SESSION['admin_id']),
    'execute'      => function (array $input): string {
        $langName = ['zh-CN' => '简体中文', 'en' => 'English', 'ja' => '日本語'][$input['target_lang']];
        $sysPrompt = "你是专业翻译。把用户输入的文本翻译为{$langName}，仅输出译文，不要任何解释。";
        $r = aiService()->chat($input['text'], $sysPrompt, 0.3);
        if (!$r['success']) throw new \RuntimeException($r['error'] ?: 'Translation failed');
        return $r['content'];
    },
]);
