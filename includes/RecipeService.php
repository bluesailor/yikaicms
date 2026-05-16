<?php
/**
 * Yikai CMS - 配方服务（Recipe System）
 *
 * 灵感来自 Drupal CMS 2.x 的 Recipe 概念：
 *   一个配方 = 一份 JSON 清单，声明应创建/更新的 channels + extfields + contents + settings。
 *   admin 一键应用即可拉起整套业务骨架（博客站 / 商业站 / FAQ 站等）。
 *
 * 幂等性：
 *   - channels 按 slug 唯一索引：已存在则跳过（默认）或更新（recipe.update_existing=true）
 *   - extfields 按 (owner_type, field_key) 唯一索引：upsert
 *   - contents 按 (channel + slug) 唯一：已存在则跳过（避免重复种子内容）
 *   - settings 用 settingModel()->set() upsert
 *
 * 用法：
 *   $service = new RecipeService();
 *   $list   = $service->list();              // 扫描 /recipes/ 列出所有
 *   $recipe = $service->load('blog-basic');  // 读单个 manifest
 *   $report = $service->apply('blog-basic'); // 执行
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

class RecipeService
{
    private string $recipesDir;

    public function __construct(?string $recipesDir = null)
    {
        $this->recipesDir = $recipesDir ?? (ROOT_PATH . '/recipes');
    }

    /**
     * 扫描 /recipes/ 列出所有可用配方（按 slug 索引）。
     * @return array<string, array>
     */
    public function list(): array
    {
        $out = [];
        if (!is_dir($this->recipesDir)) return $out;
        foreach (scandir($this->recipesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') continue;
            $dir = $this->recipesDir . '/' . $entry;
            if (!is_dir($dir)) continue;
            $manifest = $this->load($entry);
            if ($manifest !== null) $out[$entry] = $manifest;
        }
        return $out;
    }

    /**
     * 读取并解析单个配方 manifest，返回 null 表示不存在或解析失败。
     */
    public function load(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug)) return null;
        $file = $this->recipesDir . '/' . $slug . '/recipe.json';
        if (!file_exists($file)) return null;
        $raw = file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        $data['slug'] = $slug;
        // 默认值
        $data['channels']   = $data['channels']   ?? [];
        $data['extfields']  = $data['extfields']  ?? [];
        $data['contents']   = $data['contents']   ?? [];
        $data['settings']   = $data['settings']   ?? [];
        $data['lang']       = $data['lang']       ?? (defined('SITE_LANG') ? SITE_LANG : 'zh-CN');
        return $data;
    }

    /**
     * 应用配方。返回执行报告。
     */
    public function apply(string $slug, array $options = []): array
    {
        $recipe = $this->load($slug);
        if ($recipe === null) {
            throw new \RuntimeException("配方不存在或 manifest 无效: {$slug}");
        }

        $updateExisting = !empty($options['update_existing']) || !empty($recipe['update_existing']);
        $lang = (string)$recipe['lang'];
        $now  = time();

        $report = [
            'recipe'             => $recipe['name'] ?? $slug,
            'channels_created'   => 0,
            'channels_updated'   => 0,
            'channels_skipped'   => 0,
            'extfields_created'  => 0,
            'extfields_updated'  => 0,
            'contents_created'   => 0,
            'contents_skipped'   => 0,
            'settings_set'       => 0,
            'errors'             => [],
        ];

        db()->beginTransaction();
        try {
            // ── channels ──────────────────────────────────────
            // 先建顶级（parent_slug 为空），再按 parent_slug 解析建子级。
            // 为支持任意层级，做两遍：第一遍占位（拿到 id 映射），第二遍写 parent_id。
            $slugToId = [];

            foreach ($recipe['channels'] as $c) {
                $cSlug = (string)($c['slug'] ?? '');
                if ($cSlug === '') {
                    $report['errors'][] = '跳过无 slug 的 channel';
                    continue;
                }
                $existing = channelModel()->findBySlug($cSlug);
                if ($existing) {
                    $slugToId[$cSlug] = (int)$existing['id'];
                    if ($updateExisting) {
                        $data = $this->buildChannelData($c, $lang, $now, false);
                        db()->execute(
                            "UPDATE " . DB_PREFIX . "channels SET name = ?, seo_title = ?, seo_description = ?, seo_keywords = ?, is_nav = ?, sort_order = ?, updated_at = ? WHERE id = ?",
                            [$data['name'], $data['seo_title'], $data['seo_description'], $data['seo_keywords'], $data['is_nav'], $data['sort_order'], $now, (int)$existing['id']]
                        );
                        $report['channels_updated']++;
                    } else {
                        $report['channels_skipped']++;
                    }
                    continue;
                }
                $data = $this->buildChannelData($c, $lang, $now, true);
                $newId = (int)channelModel()->create($data);
                $slugToId[$cSlug] = $newId;
                $report['channels_created']++;
            }

            // 第二遍：写 parent_id
            foreach ($recipe['channels'] as $c) {
                $cSlug = (string)($c['slug'] ?? '');
                $parentSlug = (string)($c['parent_slug'] ?? '');
                if ($cSlug === '' || $parentSlug === '') continue;
                if (!isset($slugToId[$cSlug]) || !isset($slugToId[$parentSlug])) continue;
                db()->execute(
                    "UPDATE " . DB_PREFIX . "channels SET parent_id = ? WHERE id = ?",
                    [$slugToId[$parentSlug], $slugToId[$cSlug]]
                );
            }

            // ── extfields ─────────────────────────────────────
            foreach ($recipe['extfields'] as $f) {
                $ownerType = (string)($f['owner_type'] ?? '');
                $fieldKey  = (string)($f['field_key'] ?? '');
                if ($ownerType === '' || $fieldKey === '') {
                    $report['errors'][] = '跳过无 owner_type / field_key 的 extfield';
                    continue;
                }
                $existing = db()->fetchOne(
                    "SELECT id FROM " . DB_PREFIX . "extfields WHERE owner_type = ? AND field_key = ?",
                    [$ownerType, $fieldKey]
                );
                $data = [
                    'owner_type'  => $ownerType,
                    'field_key'   => $fieldKey,
                    'field_name'  => (string)($f['field_name'] ?? $fieldKey),
                    'field_type'  => (string)($f['field_type'] ?? 'TEXT'),
                    'options'     => isset($f['options']) ? (is_string($f['options']) ? $f['options'] : json_encode($f['options'], JSON_UNESCAPED_UNICODE)) : null,
                    'placeholder' => (string)($f['placeholder'] ?? ''),
                    'help_text'   => (string)($f['help_text'] ?? ''),
                    'is_required' => (int)($f['is_required'] ?? 0),
                    'sort_order'  => (int)($f['sort_order'] ?? 0),
                    'status'      => (int)($f['status'] ?? 1),
                ];
                if ($existing) {
                    db()->execute(
                        "UPDATE " . DB_PREFIX . "extfields SET field_name = ?, field_type = ?, options = ?, placeholder = ?, help_text = ?, is_required = ?, sort_order = ?, status = ? WHERE id = ?",
                        [$data['field_name'], $data['field_type'], $data['options'], $data['placeholder'], $data['help_text'], $data['is_required'], $data['sort_order'], $data['status'], (int)$existing['id']]
                    );
                    $report['extfields_updated']++;
                } else {
                    $data['created_at'] = $now;
                    extFieldModel()->create($data);
                    $report['extfields_created']++;
                }
            }

            // ── contents ──────────────────────────────────────
            foreach ($recipe['contents'] as $ct) {
                $channelSlug = (string)($ct['channel_slug'] ?? '');
                $title       = (string)($ct['title'] ?? '');
                $cSlug       = (string)($ct['slug'] ?? '');
                if ($channelSlug === '' || $title === '') {
                    $report['errors'][] = '跳过无 channel_slug / title 的 content';
                    continue;
                }
                $channelId = $slugToId[$channelSlug] ?? null;
                if ($channelId === null) {
                    $existing = channelModel()->findBySlug($channelSlug);
                    if ($existing) $channelId = (int)$existing['id'];
                }
                if ($channelId === null) {
                    $report['errors'][] = "content '{$title}' 引用的 channel '{$channelSlug}' 不存在";
                    continue;
                }
                // 重复检查：同 channel 下 slug 已存在则跳过
                if ($cSlug !== '') {
                    $dup = db()->fetchOne(
                        "SELECT id FROM " . DB_PREFIX . "contents WHERE channel_id = ? AND slug = ? AND lang = ?",
                        [$channelId, $cSlug, $lang]
                    );
                    if ($dup) { $report['contents_skipped']++; continue; }
                }
                $data = [
                    'lang'         => $lang,
                    'channel_id'   => $channelId,
                    'type'         => (string)($ct['type'] ?? 'article'),
                    'title'        => $title,
                    'subtitle'     => (string)($ct['subtitle'] ?? ''),
                    'slug'         => $cSlug,
                    'cover'        => (string)($ct['cover'] ?? ''),
                    'summary'      => (string)($ct['summary'] ?? ''),
                    'content'      => (string)($ct['content'] ?? ''),
                    'content_type' => (string)($ct['content_type'] ?? 'html'),
                    'author'       => (string)($ct['author'] ?? ''),
                    'source'       => (string)($ct['source'] ?? ''),
                    'tags'         => (string)($ct['tags'] ?? ''),
                    'status'       => (int)($ct['status'] ?? 1),
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];
                contentModel()->create($data);
                $report['contents_created']++;
            }

            // ── settings ──────────────────────────────────────
            foreach ($recipe['settings'] as $k => $v) {
                settingModel()->set((string)$k, (string)$v);
                $report['settings_set']++;
            }

            db()->commit();
        } catch (\Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return $report;
    }

    private function buildChannelData(array $c, string $lang, int $now, bool $forInsert): array
    {
        $data = [
            'lang'            => $lang,
            'parent_id'       => 0, // 第二遍再写
            'name'            => (string)($c['name'] ?? $c['slug']),
            'slug'            => (string)$c['slug'],
            'type'            => (string)($c['type'] ?? 'list'),
            'icon'            => (string)($c['icon'] ?? ''),
            'description'     => (string)($c['description'] ?? ''),
            'content'         => (string)($c['content'] ?? ''),
            'seo_title'       => (string)($c['seo_title'] ?? ''),
            'seo_keywords'    => (string)($c['seo_keywords'] ?? ''),
            'seo_description' => (string)($c['seo_description'] ?? ''),
            'is_nav'          => (int)($c['is_nav'] ?? 1),
            'is_home'         => (int)($c['is_home'] ?? 0),
            'status'          => (int)($c['status'] ?? 1),
            'sort_order'      => (int)($c['sort_order'] ?? 0),
        ];
        if ($forInsert) {
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
        }
        return $data;
    }

    /**
     * 把当前站点状态序列化成配方 manifest（供导出）。
     */
    public function exportCurrent(array $options = []): array
    {
        $lang = (string)($options['lang'] ?? (defined('SITE_LANG') ? SITE_LANG : 'zh-CN'));
        $includeContents = !empty($options['include_contents']);

        // 设置：只导出可移植的 KV（排除敏感如 api keys、随机密钥）
        $excludeKeys = $options['exclude_setting_keys'] ?? [
            'ai_api_key', 'mail_password', 'smtp_password', 'encrypt_key',
        ];
        $allSettings = settingModel()->getAll();
        $settings = [];
        foreach ($allSettings as $k => $v) {
            if (in_array($k, $excludeKeys, true)) continue;
            $settings[$k] = (string)$v;
        }

        // channels（默认语言）
        $channelRows = db()->fetchAll(
            "SELECT * FROM " . DB_PREFIX . "channels WHERE lang = ? ORDER BY sort_order ASC, id ASC",
            [$lang]
        );
        $idToSlug = [];
        foreach ($channelRows as $r) $idToSlug[(int)$r['id']] = $r['slug'];

        $channels = [];
        foreach ($channelRows as $r) {
            $channels[] = [
                'slug'            => $r['slug'],
                'name'            => $r['name'],
                'type'            => $r['type'],
                'parent_slug'     => $idToSlug[(int)$r['parent_id']] ?? '',
                'icon'            => $r['icon'],
                'description'     => $r['description'],
                'content'         => $r['content'],
                'seo_title'       => $r['seo_title'],
                'seo_keywords'    => $r['seo_keywords'],
                'seo_description' => $r['seo_description'],
                'is_nav'          => (int)$r['is_nav'],
                'is_home'         => (int)$r['is_home'],
                'sort_order'      => (int)$r['sort_order'],
                'status'          => (int)$r['status'],
            ];
        }

        // extfields
        $extRows = db()->fetchAll(
            "SELECT * FROM " . DB_PREFIX . "extfields ORDER BY owner_type, sort_order ASC, id ASC"
        );
        $extfields = [];
        foreach ($extRows as $r) {
            $extfields[] = [
                'owner_type'  => $r['owner_type'],
                'field_key'   => $r['field_key'],
                'field_name'  => $r['field_name'],
                'field_type'  => $r['field_type'],
                'options'     => $r['options'] ? (json_decode($r['options'], true) ?? $r['options']) : null,
                'placeholder' => $r['placeholder'],
                'help_text'   => $r['help_text'],
                'is_required' => (int)$r['is_required'],
                'sort_order'  => (int)$r['sort_order'],
                'status'      => (int)$r['status'],
            ];
        }

        $manifest = [
            'name'        => (string)($options['name'] ?? '导出于 ' . date('Y-m-d H:i')),
            'slug'        => (string)($options['slug'] ?? 'exported-' . date('Ymd-His')),
            'version'     => '1.0.0',
            'description' => (string)($options['description'] ?? '由 ' . SITE_NAME . ' 自动导出的站点配置'),
            'author'      => (string)($options['author'] ?? 'Yikai CMS'),
            'requires_cms' => '1.7.0',
            'lang'        => $lang,
            'channels'    => $channels,
            'extfields'   => $extfields,
            'contents'    => [],
            'settings'    => $settings,
        ];

        if ($includeContents) {
            $contentRows = db()->fetchAll(
                "SELECT * FROM " . DB_PREFIX . "contents WHERE lang = ? AND status = 1 ORDER BY id ASC LIMIT 500",
                [$lang]
            );
            $contents = [];
            foreach ($contentRows as $r) {
                $contents[] = [
                    'channel_slug' => $idToSlug[(int)$r['channel_id']] ?? '',
                    'title'        => $r['title'],
                    'subtitle'     => $r['subtitle'],
                    'slug'         => $r['slug'],
                    'type'         => $r['type'],
                    'cover'        => $r['cover'],
                    'summary'      => $r['summary'],
                    'content'      => $r['content'],
                    'content_type' => $r['content_type'],
                    'author'       => $r['author'],
                    'source'       => $r['source'],
                    'tags'         => $r['tags'],
                    'status'       => (int)$r['status'],
                ];
            }
            $manifest['contents'] = $contents;
        }

        return $manifest;
    }
}
