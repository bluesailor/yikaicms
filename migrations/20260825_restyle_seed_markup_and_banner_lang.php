<?php

declare(strict_types=1);

/**
 * 两个种子缺陷的存量修复（v1.18.8）：
 *
 * 1. 出厂富文本的内联样式在前台永远不生效——sanitizeHtml（HtmlPolicy::richText）
 *    渲染时剥掉 style 属性，价格方案的去圆点列表/居中/大号价格/推荐徽章全部散架。
 *    把已知的出厂样式串替换成 app.css 里的对应类名（class 属性会被净化器保留）。
 *    只替换字节级完全一致的出厂串，用户自己写的样式不受影响（反正也渲染不出来）。
 *
 * 2. 非中文站首页 Blox 文档里烤死了三条中文轮播（items_mode=custom）——渲染层的
 *    本地化兜底被 siteLang() !== defaultLang 闸挡住，英文/日文安装站首页直接显示
 *    中文。当自定义轮播仍是未经修改的出厂内容、且 banners 表有当前语言的可用行时，
 *    切回 items_mode=inherit 走 banners 表（表内 zh/en/ja 三语行齐全）。
 */
return [
    'id' => '20260825_restyle_seed_markup_and_banner_lang',
    'title' => '修复出厂内容样式丢失与非中文站首页轮播中文残留',
    'desc' => '出厂富文本的内联样式改为类名（净化器会剥掉 style）；非中文站未修改过的出厂轮播改回读多语言轮播表。',
    'title_en' => 'Fix factory content styling loss and Chinese banner leak on non-Chinese sites',
    'title_ja' => '初期コンテンツのスタイル欠落と非中国語サイトの中国語バナー残留を修正',
    'desc_en' => 'Factory rich text switches from inline styles (stripped by the sanitizer) to CSS classes; untouched factory banner slides on non-Chinese sites switch back to the multilingual banner table.',
    'desc_ja' => '初期リッチテキストのインラインスタイル（サニタイザで除去される）をクラスに置換し、未編集の初期バナーは多言語バナーテーブル参照に戻します。',
    'check' => static function (): bool {
        if (!db()->tableExists('settings')) {
            return true;
        }
        return db()->fetchOne(
            'SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ? AND `value` = ? LIMIT 1',
            ['migration_20260825_seed_restyle', '1']
        ) !== null;
    },
    'sqls' => [],
    'php' => static function (): string {
        // ── 1. 出厂样式串 → 类名（顺序敏感：长串在前，避免子串误替换）──
        $stylePairs = [
            'style="display:inline-block;background:#4f46e5;color:#fff;font-size:12px;font-weight:600;padding:3px 14px;border-radius:9999px"' => 'class="yk-badge"',
            'style="list-style:none;padding:0;margin:0;line-height:2.2;color:#555"' => 'class="yk-plan-list"',
            'style="font-size:2rem;font-weight:700;color:#4f46e5"' => 'class="yk-price yk-price--hot"',
            'style="text-align:center;margin-bottom:6px"' => 'class="yk-badge-row"',
            'style="font-size:2rem;font-weight:700"' => 'class="yk-price"',
            'style="text-align:center"' => 'class="yk-center"',
            'style="color:#888"' => 'class="yk-price-note"',
            // 随包整页模板（1.18.8 同批发布，存量站通常没有，一并覆盖以防手工导入过）
            'style="margin:0;text-align:center;letter-spacing:.18em;text-transform:uppercase;font-size:.75rem;color:#6b7280"' => 'class="yk-eyebrow yk-center"',
            'style="margin:0;letter-spacing:.18em;text-transform:uppercase;font-size:.75rem;color:#6b7280"' => 'class="yk-eyebrow"',
            'style="margin:0;font-size:1.05rem;line-height:1.9;color:#4b5563;max-width:46rem"' => 'class="yk-lead"',
            'style="margin:0 auto;text-align:center;max-width:38rem;line-height:1.9;color:#4b5563"' => 'class="yk-copy yk-copy--center"',
            'style="line-height:1.9;color:#4b5563"' => 'class="yk-copy"',
            'style="text-align:center;color:#6b7280;margin:0 auto;max-width:40rem"' => 'class="yk-hint-center"',
            'style="margin:0 0 .5rem;color:#6b7280;font-size:.95rem"' => 'class="yk-note"',
            'style="display:flex;justify-content:space-between;gap:1rem;margin:0;color:#686866;font-size:.75rem;text-transform:uppercase"' => 'class="yk-404-meta-row"',
            'style="margin:0;color:#777773;font-size:.75rem;text-transform:uppercase"' => 'class="yk-404-label"',
            'style="margin:0;text-align:right;color:#686866"' => 'class="yk-404-value"',
            'style="max-width:34rem;margin:2rem auto .75rem;text-align:center;color:#666663"' => 'class="yk-404-desc"',
        ];

        /** JSON 文档字符串字段递归替换；返回 [新文档, 是否有改动] */
        $restyleJson = static function (string $json) use ($stylePairs): array {
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return [$json, false];
            }
            $changed = false;
            $walk = static function (&$node) use (&$walk, &$changed, $stylePairs): void {
                if (is_array($node)) {
                    foreach ($node as &$value) {
                        $walk($value);
                    }
                    unset($value);
                } elseif (is_string($node) && str_contains($node, 'style="')) {
                    $replaced = strtr($node, $stylePairs);
                    if ($replaced !== $node) {
                        $node = $replaced;
                        $changed = true;
                    }
                }
            };
            $walk($decoded);
            if (!$changed) {
                return [$json, false];
            }
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? [$json, false] : [$encoded, true];
        };

        $restyled = 0;

        // 首页自定义版块（home_custom_N 及其语言变体）
        $rows = db()->fetchAll(
            'SELECT id, `value` FROM ' . DB_PREFIX . "settings WHERE `key` LIKE 'home_custom_%'"
        );
        foreach ($rows as $row) {
            [$next, $changed] = $restyleJson((string) ($row['value'] ?? ''));
            if ($changed) {
                db()->update('settings', ['value' => $next], 'id = ?', [(int) $row['id']]);
                $restyled++;
            }
        }

        // 首页 Blox 文档 + 用户把价格方案预设插进过的页面/模板
        $docTargets = [
            ['settings', 'id', ['value'], "`key` IN ('home_blox_data','home_blox_published')"],
            ['blox_page_drafts', 'id', ['draft_data', 'published_data'], '1=1'],
            ['blox_templates', 'id', ['draft_data', 'published_data'], '1=1'],
        ];
        foreach ($docTargets as [$table, $pk, $columns, $where]) {
            if (!db()->tableExists($table)) {
                continue;
            }
            $cols = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
            $rows = db()->fetchAll("SELECT `{$pk}`, {$cols} FROM " . DB_PREFIX . "{$table} WHERE {$where}");
            foreach ($rows as $row) {
                $updates = [];
                foreach ($columns as $column) {
                    $raw = (string) ($row[$column] ?? '');
                    if ($raw === '' || !str_contains($raw, 'style="')) {
                        continue;
                    }
                    [$next, $changed] = $restyleJson($raw);
                    if ($changed) {
                        $updates[$column] = $next;
                    }
                }
                if ($updates) {
                    db()->update($table, $updates, "{$pk} = ?", [(int) $row[$pk]]);
                    $restyled++;
                }
            }
        }

        // ── 2. 非中文站：未修改过的出厂中文轮播 → items_mode=inherit ──
        $bannerFixed = 0;
        $siteLang = (string) config('site_lang', 'zh-CN');
        if ($siteLang !== 'zh-CN' && db()->tableExists('banners')) {
            $factory = [
                '数字化转型解决方案' => '助力企业实现智能化升级',
                '专业的技术服务团队' => '7x24小时为您保驾护航',
                '创新引领未来' => '持续创新，追求卓越',
            ];
            $hasLangBanners = db()->fetchOne(
                'SELECT id FROM ' . DB_PREFIX . 'banners WHERE position = ? AND lang = ? AND status = 1 LIMIT 1',
                ['home', $siteLang]
            ) !== null;
            if ($hasLangBanners) {
                foreach (['home_blox_data', 'home_blox_published'] as $key) {
                    $raw = (string) config($key, '');
                    if ($raw === '') {
                        continue;
                    }
                    $doc = json_decode($raw, true);
                    if (!is_array($doc) || !isset($doc['sections']) || !is_array($doc['sections'])) {
                        continue;
                    }
                    $changed = false;
                    // 注意：不能 foreach (expr ?? [] as &$x)——引用挂在临时拷贝上，
                    // 改动不会落回 $doc（首版就栽在这里）。按索引直写。
                    foreach ($doc['sections'] as $si => $section) {
                        foreach (($section['columns'] ?? []) as $ci => $column) {
                            foreach (($column['elements'] ?? []) as $ei => $element) {
                                $data = $element['data'] ?? null;
                                if (!is_array($data)
                                    || ($element['type'] ?? '') !== 'home-block'
                                    || ($data['block_type'] ?? '') !== 'banner'
                                    || ($data['items_mode'] ?? '') !== 'custom') {
                                    continue;
                                }
                                $children = is_array($data['children'] ?? null) ? $data['children'] : [];
                                $allFactory = $children !== [];
                                foreach ($children as $child) {
                                    $title = (string) ($child['data']['title'] ?? '');
                                    $subtitle = (string) ($child['data']['subtitle'] ?? '');
                                    if (!isset($factory[$title]) || $factory[$title] !== $subtitle) {
                                        $allFactory = false;
                                        break;
                                    }
                                }
                                if ($allFactory) {
                                    $doc['sections'][$si]['columns'][$ci]['elements'][$ei]['data']['items_mode'] = 'inherit';
                                    $changed = true;
                                }
                            }
                        }
                    }
                    if ($changed) {
                        $encoded = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if ($encoded !== false) {
                            settingModel()->set($key, $encoded, 'home');
                            $bannerFixed++;
                        }
                    }
                }
            }
        }

        if (class_exists('HtmlCache')) {
            HtmlCache::invalidate();
        }
        settingModel()->set('migration_20260825_seed_restyle', '1', 'system');
        return "样式改类名 {$restyled} 处；轮播回归多语言表 {$bannerFixed} 份文档";
    },
];
