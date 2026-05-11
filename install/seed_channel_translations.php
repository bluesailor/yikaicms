<?php
/**
 * 安装时把默认 zh-CN 频道 / 内容镜像到 en / ja。
 *
 * 调用约定：
 *   require_once INSTALL_PATH . '/seed_channel_translations.php';
 *   seedChannelTranslations($pdo, $prefix);
 *
 * 数据来源（优先级从高到低）：
 *   1. install/seed_data_en.json / seed_data_ja.json
 *      —— 由 tools/export_sister_translations.php 从兄弟项目（enkaicms / ikaicms）
 *      运行 DB 导出。包含完整字段（name + description + content + seo_*），
 *      质量是真人录入或人工翻译的水平。
 *   2. 内置硬编码 $FALLBACK 表
 *      —— 仅 name + description 短翻译，给 JSON 里 slug 没命中的频道兜底。
 *
 * 工作流：
 *   - 第一遍：插入 channels 翻译行
 *     · 优先用 JSON 整行字段；没命中再用 FALLBACK
 *     · slug 加后缀 '-en' / '-ja' 避开 uk_slug 全表唯一约束
 *     · translation_group_id 指向 zh-CN 源行的 id
 *   - 第二遍：回填 channels 的 parent_id（同语言内）
 *   - 第三遍：插入 contents 翻译行（仅 JSON 命中的 slug）
 *     · channel_id 重映射到对应语言的频道行
 *     · translation_group_id 指向 zh-CN 源 content 的 id
 *
 * Slug 后缀策略：schema 里 UNIQUE KEY uk_slug (slug) 是全表唯一，所以镜像行
 * 不能跟源同 slug；URL 路由仍能走 findBySlugLang() 通过 translation_group_id
 * 跳转到对应语言版本，slug 后缀只在数据库内可见。
 */

declare(strict_types=1);

function seedChannelTranslations(PDO $pdo, string $prefix): void
{
    $external = seed_load_external();
    $fallback = seed_fallback_map();

    // 规范化：源行的 translation_group_id 也指向自己的 id（不指别处的话默认为 0）。
    // 镜像行的 translation_group_id 一律指向源 id；这样同一翻译组的所有行
    // 共享同一个 group_id，model / widget 查询逻辑可以统一。
    // 顺便把同样数据模型的 timelines / links 表也一起回填（容错：表/字段不存在时静默跳过）。
    $pdo->exec("UPDATE {$prefix}channels SET translation_group_id = id WHERE lang = 'zh-CN' AND translation_group_id = 0");
    try { $pdo->exec("UPDATE {$prefix}timelines SET translation_group_id = id WHERE lang = 'zh-CN' AND translation_group_id = 0"); } catch (Throwable $_) {}
    try { $pdo->exec("UPDATE {$prefix}links     SET translation_group_id = id WHERE lang = 'zh-CN' AND translation_group_id = 0"); } catch (Throwable $_) {}
    try { $pdo->exec("UPDATE {$prefix}banners   SET translation_group_id = id WHERE lang = 'zh-CN' AND translation_group_id = 0"); } catch (Throwable $_) {}

    $srcChannels = $pdo->query("SELECT * FROM {$prefix}channels WHERE lang = 'zh-CN' ORDER BY id ASC")
                       ->fetchAll(PDO::FETCH_ASSOC);
    if (!$srcChannels) return;

    // 幂等：已存在的 (translation_group_id, lang) 组合直接跳过，保留用户已有翻译。
    // 兼容两种来源：seed 自己写的（translation_group_id = src.id）和
    // setting_lang.php 批量翻译写的（同样使用 src.id 作为 group）。
    $existingChan = [];
    foreach ($pdo->query("SELECT translation_group_id, lang FROM {$prefix}channels WHERE lang IN ('en','ja')")
                  ->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existingChan[(int) $r['translation_group_id']][$r['lang']] = true;
    }

    // ── 第一遍：插入 channels 翻译行 ──
    $insertChannel = $pdo->prepare(
        "INSERT INTO {$prefix}channels
         (lang, translation_group_id, parent_id, name, slug, type, album_id, icon, image,
          description, content, link_url, link_target, redirect_type, redirect_url,
          seo_title, seo_keywords, seo_description, is_nav, is_home, status, is_system,
          sort_order, created_at, updated_at)
         VALUES
         (?, ?, ?, ?, ?, ?, ?, ?, ?,
          ?, ?, ?, ?, ?, ?,
          ?, ?, ?, ?, ?, ?, ?,
          ?, ?, ?)"
    );

    // src zh-CN id => translated id（每语言一份，给第二遍回填 parent + 第三遍 contents 用）
    $channelIdMap = ['en' => [], 'ja' => []];

    foreach (['en', 'ja'] as $lang) {
        $extBySlug = [];
        foreach (($external[$lang]['channels'] ?? []) as $row) {
            if (!empty($row['slug'])) $extBySlug[$row['slug']] = $row;
        }

        foreach ($srcChannels as $src) {
            $slug = (string) $src['slug'];

            // 幂等：该源行已有该语言翻译，跳过
            if (!empty($existingChan[(int) $src['id']][$lang])) continue;

            $row  = $extBySlug[$slug] ?? null;
            $stub = $fallback[$slug][$lang] ?? null;

            if (!$row && !$stub) continue;  // 该 slug 既没外部数据也没兜底

            // 字段优先级：JSON > FALLBACK > 源行
            $name        = $row['name']            ?? $stub['name']        ?? $src['name'];
            $description = $row['description']     ?? $stub['description'] ?? '';
            $content     = $row['content']         ?? null;
            $seoTitle    = $row['seo_title']       ?? $name;
            $seoKeywords = $row['seo_keywords']    ?? '';
            $seoDesc     = $row['seo_description'] ?? $description;
            $image       = $row['image']           ?? (string)($src['image'] ?? '');
            $icon        = $row['icon']            ?? (string)($src['icon'] ?? '');

            $insertChannel->execute([
                $lang,
                (int) $src['id'],                // translation_group_id 指向源
                0,                                // parent_id 第二遍回填
                $name,
                $slug . '-' . $lang,             // about-en / about-ja
                $src['type'],
                (int) ($src['album_id'] ?? 0),
                $icon,
                $image,
                $description,
                $content,
                (string) ($src['link_url']     ?? ''),
                (string) ($src['link_target']  ?? '_self'),
                (string) ($src['redirect_type']?? 'auto'),
                (string) ($src['redirect_url'] ?? ''),
                $seoTitle,
                $seoKeywords,
                $seoDesc,
                (int) ($src['is_nav']    ?? 1),
                (int) ($src['is_home']   ?? 0),
                (int) ($src['status']    ?? 1),
                (int) ($src['is_system'] ?? 1),
                (int) ($src['sort_order'] ?? 0),
                time(),
                time(),
            ]);
            $channelIdMap[$lang][(int) $src['id']] = (int) $pdo->lastInsertId();
        }
    }

    // ── 第二遍：回填 parent_id（同语言内） ──
    $updateParent = $pdo->prepare("UPDATE {$prefix}channels SET parent_id = ? WHERE id = ?");
    foreach (['en', 'ja'] as $lang) {
        foreach ($srcChannels as $src) {
            $srcParentId = (int) $src['parent_id'];
            if ($srcParentId === 0) continue;
            $myNewId   = $channelIdMap[$lang][(int) $src['id']] ?? null;
            $newParent = $channelIdMap[$lang][$srcParentId]     ?? null;
            if ($myNewId && $newParent) {
                $updateParent->execute([$newParent, $myNewId]);
            }
        }
    }

    // ── 第三遍：镜像 contents（仅 JSON 命中部分） ──
    seed_mirror_contents($pdo, $prefix, $external, $channelIdMap);
}

/**
 * 加载从兄弟项目导出的 JSON 数据。
 *
 * @return array{en: array, ja: array}
 */
function seed_load_external(): array
{
    $base = __DIR__;
    $out = ['en' => ['channels' => [], 'contents' => []],
            'ja' => ['channels' => [], 'contents' => []]];
    foreach (['en', 'ja'] as $lang) {
        $f = "{$base}/seed_data_{$lang}.json";
        if (!is_file($f)) continue;
        $data = json_decode((string) file_get_contents($f), true);
        if (!is_array($data)) continue;
        if (isset($data['channels']) && is_array($data['channels'])) $out[$lang]['channels'] = $data['channels'];
        if (isset($data['contents']) && is_array($data['contents'])) $out[$lang]['contents'] = $data['contents'];
    }
    return $out;
}

/**
 * 镜像 contents（页面正文）—— 仅在 JSON 里 slug 命中时写入。
 * 没有 JSON 数据的 slug 直接跳过（不做硬编码兜底，因为 content 是大段 HTML）。
 */
function seed_mirror_contents(PDO $pdo, string $prefix, array $external, array $channelIdMap): void
{
    // 同步规范化 contents 表的源行 translation_group_id
    $pdo->exec("UPDATE {$prefix}contents SET translation_group_id = id WHERE lang = 'zh-CN' AND translation_group_id = 0");

    $srcContents = $pdo->query("SELECT * FROM {$prefix}contents WHERE lang = 'zh-CN' ORDER BY id ASC")
                       ->fetchAll(PDO::FETCH_ASSOC);
    if (!$srcContents) return;

    // 幂等：跳过已有翻译
    $existingCnt = [];
    foreach ($pdo->query("SELECT translation_group_id, lang FROM {$prefix}contents WHERE lang IN ('en','ja')")
                  ->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existingCnt[(int) $r['translation_group_id']][$r['lang']] = true;
    }

    // 探测目标表的列结构，避免兄弟项目 schema 微差导致 INSERT 失败
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $rows = $pdo->query("PRAGMA table_info({$prefix}contents)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_map(fn($r) => $r['name'] ?? '', $rows);
    } else {
        $rows = $pdo->query("DESCRIBE {$prefix}contents")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_map(fn($r) => $r['Field'] ?? '', $rows);
    }
    $cols = array_values(array_filter($cols, fn($c) => $c !== '' && $c !== 'id'));

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $colList      = '`' . implode('`,`', $cols) . '`';
    $insertContent = $pdo->prepare("INSERT INTO {$prefix}contents ({$colList}) VALUES ({$placeholders})");

    foreach (['en', 'ja'] as $lang) {
        $extBySlug = [];
        foreach (($external[$lang]['contents'] ?? []) as $row) {
            if (!empty($row['slug'])) $extBySlug[$row['slug']] = $row;
        }

        foreach ($srcContents as $src) {
            $slug = (string) $src['slug'];
            if ($slug === '') continue;
            // 幂等：源行已有该语言翻译就跳
            if (!empty($existingCnt[(int) $src['id']][$lang])) continue;
            $ext = $extBySlug[$slug] ?? null;
            if (!$ext) continue;  // ja 多数 slug 没数据，跳过

            // 把 ext 行按目标列顺序拼数据；找不到的列用源行兜底
            $values = [];
            foreach ($cols as $c) {
                if ($c === 'lang') {
                    $values[] = $lang;
                } elseif ($c === 'translation_group_id') {
                    $values[] = (int) $src['id'];
                } elseif ($c === 'channel_id') {
                    // 重映射到目标语言对应的 channel
                    $values[] = $channelIdMap[$lang][(int) $src['channel_id']] ?? (int) $src['channel_id'];
                } elseif ($c === 'created_at' || $c === 'updated_at') {
                    $values[] = time();
                } elseif (array_key_exists($c, $ext)) {
                    $values[] = $ext[$c];
                } elseif (array_key_exists($c, $src)) {
                    $values[] = $src[$c];
                } else {
                    $values[] = null;
                }
            }
            $insertContent->execute($values);
        }
    }
}

/**
 * 硬编码兜底翻译表 —— 当 JSON 里某 slug 没命中时使用。
 * 仅 name + description 短文本，不含正文 content。
 */
function seed_fallback_map(): array
{
    return [
        'about'              => ['en' => ['name' => 'About Us',          'description' => 'Learn about our company culture and history'],
                                  'ja' => ['name' => '会社案内',          'description' => '会社理念と沿革のご紹介']],
        'company'            => ['en' => ['name' => 'Company Profile',   'description' => 'Company overview'],
                                  'ja' => ['name' => '会社概要',          'description' => '会社の基本情報']],
        'culture'            => ['en' => ['name' => 'Culture',           'description' => 'Our core values'],
                                  'ja' => ['name' => '企業文化',          'description' => '私たちの価値観']],
        'history'            => ['en' => ['name' => 'History',           'description' => 'Milestones in our journey'],
                                  'ja' => ['name' => '沿革',              'description' => '会社の歩み']],
        'product'            => ['en' => ['name' => 'Products',          'description' => 'Our products and services'],
                                  'ja' => ['name' => '製品',              'description' => '当社の製品とサービス']],
        'cases'              => ['en' => ['name' => 'Cases',             'description' => 'Customer success stories'],
                                  'ja' => ['name' => '導入事例',          'description' => 'お客様の成功事例']],
        'news'               => ['en' => ['name' => 'News',              'description' => 'Latest news and industry updates'],
                                  'ja' => ['name' => 'ニュース',          'description' => '最新情報と業界動向']],
        'company-news'       => ['en' => ['name' => 'Company News',      'description' => 'Latest company updates'],
                                  'ja' => ['name' => '会社ニュース',      'description' => '会社の最新情報']],
        'industry-news'      => ['en' => ['name' => 'Industry News',     'description' => 'Industry updates'],
                                  'ja' => ['name' => '業界動向',          'description' => '業界の最新情報']],
        'service'            => ['en' => ['name' => 'Service',           'description' => 'Professional service and technical support'],
                                  'ja' => ['name' => 'サービス',          'description' => 'プロフェッショナルなサポート']],
        'process'            => ['en' => ['name' => 'Service Process',   'description' => 'Our standardized service process'],
                                  'ja' => ['name' => 'サービスフロー',    'description' => '標準化されたサービスフロー']],
        'faq'                => ['en' => ['name' => 'FAQ',               'description' => 'Frequently asked questions'],
                                  'ja' => ['name' => 'よくある質問',      'description' => 'よくお寄せいただく質問']],
        'download'           => ['en' => ['name' => 'Download',          'description' => 'Documents and software downloads'],
                                  'ja' => ['name' => 'ダウンロード',      'description' => '資料とソフトウェアダウンロード']],
        'job'                => ['en' => ['name' => 'Careers',           'description' => 'Join our team'],
                                  'ja' => ['name' => '採用情報',          'description' => '私たちと一緒に働きませんか']],
        'contact'            => ['en' => ['name' => 'Contact',           'description' => 'Get in touch with us'],
                                  'ja' => ['name' => 'お問合せ',          'description' => 'お問合せ・ご相談はこちら']],
        'privacy'            => ['en' => ['name' => 'Privacy Policy',    'description' => ''],
                                  'ja' => ['name' => 'プライバシーポリシー','description' => '']],
        'terms'              => ['en' => ['name' => 'Terms of Service',  'description' => ''],
                                  'ja' => ['name' => '利用規約',          'description' => '']],
        'honor'              => ['en' => ['name' => 'Honors',            'description' => ''],
                                  'ja' => ['name' => '受賞・認証',        'description' => '']],
        'organization'       => ['en' => ['name' => 'Organization',      'description' => ''],
                                  'ja' => ['name' => '組織体制',          'description' => '']],
        'solution'           => ['en' => ['name' => 'Solutions',         'description' => ''],
                                  'ja' => ['name' => 'ソリューション',    'description' => '']],
        'industry'           => ['en' => ['name' => 'Industries',        'description' => ''],
                                  'ja' => ['name' => '業界別ソリューション','description' => '']],
        'tech-share'         => ['en' => ['name' => 'Tech Share',        'description' => ''],
                                  'ja' => ['name' => '技術情報',          'description' => '']],
        'software-download'  => ['en' => ['name' => 'Software',          'description' => ''],
                                  'ja' => ['name' => 'ソフトウェア',      'description' => '']],
        'document-download'  => ['en' => ['name' => 'Documents',         'description' => ''],
                                  'ja' => ['name' => '文書資料',          'description' => '']],
        'driver-download'    => ['en' => ['name' => 'Drivers',           'description' => ''],
                                  'ja' => ['name' => 'ドライバー',        'description' => '']],
    ];
}
