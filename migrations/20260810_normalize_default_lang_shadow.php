<?php
/**
 * 归位「默认语言后缀行」——修「后台改了设置，前台不生效」（kksky.ph 实病）。
 *
 * ── 病因 ──
 * settings 的写读不对称：后台保存写 base 行，前台 configLang 读取**后缀优先**
 * （对未归位老站的兼容）。若站点默认语言（如 en）残留 <key>_en 后缀行，
 * 前台永远读后缀：后台怎么改都白改。残留的两个来源：
 *   1. **安装器**：装英文站只 UPDATE site_lang，从不归位种子行（install/index.php）——
 *      每个非中文新装站出厂自带此病；
 *   2. 1.17.1/1.17.2 上「切默认语言」的归位因 ESCAPE 转义 bug 直接 500，从没成功跑过。
 *
 * ── 三规则（在 fhzn2 44 行实测验证，前台逐字节不变、病根治）──
 * 对每个 <key>_<默认语言> 行，对照同名 base 行：
 *   R1 base 不存在或为空          → 后缀值提升为 base（显示不变）
 *   R2 base 含 CJK 而后缀不含     → base 是没动过的中文种子，后缀提升覆盖（显示不变）
 *   R3 其余（base 是站长的编辑）  → 保留 base（站长的修改终于生效）
 * 处理完删除后缀行，恢复「默认语言内容只在 base」的不变量。
 *
 * R2 的 CJK 启发只在**默认语言不含汉字书写系统**时有效——zh-CN / zh-TW / ja
 * 默认的站点本迁移不动（它们也极少出现此病：中文默认站的写读路径是自洽的）。
 *
 * 执行前把受影响行原值备份到 storage/lang-shadow-backup-<时间>.json。
 */

declare(strict_types=1);

return [
    'id' => '20260810_normalize_default_lang_shadow',
    'title' => '归位默认语言的设置后缀行',
    'title_en' => 'Normalize settings rows for the default language',
    'title_ja' => '既定言語の設定サフィックス行を正規化',
    'desc' => '非中文默认语言的站点上，安装种子或历史遗留的 <key>_<默认语言> 后缀行会遮蔽'
        . '后台的修改（改了设置前台不生效）。本迁移把它们归位到主行：站长已编辑的内容保留并'
        . '开始生效，未编辑过的保持当前显示不变。执行前自动备份到 storage/。',
    'desc_en' => 'On sites whose default language is not Chinese, leftover <key>_<lang> suffix rows '
        . '(from install seeds or older versions) shadow anything you change in the admin — edits '
        . 'never show on the front end. This migration folds them into the main rows: your edits are '
        . 'kept and finally take effect; untouched settings keep displaying exactly as before. '
        . 'A backup is written to storage/ first.',
    'desc_ja' => '既定言語が中国語以外のサイトでは、インストール時のシードや旧バージョンが残した '
        . '<key>_<言語> サフィックス行が管理画面での変更を覆い隠します（編集がフロントに反映されない）。'
        . '本マイグレーションはこれらを主行に統合します。編集済みの内容は保持されて反映されるようになり、'
        . '未編集の設定は表示がそのまま維持されます。実行前に storage/ へバックアップします。',
    'check' => static function (): bool {
        try {
            $row = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . "settings WHERE `key` = 'site_lang'");
            $default = trim((string) ($row['value'] ?? ''));
            // 中文/日文默认站：CJK 启发无效，也基本不生此病 —— 视为无事可做
            if ($default === '' || in_array($default, ['zh-CN', 'zh-TW', 'ja'], true)) {
                return true;
            }
            $like = '%' . str_replace(['!', '_', '%'], ['!!', '!_', '!%'], '_' . $default);
            $hit = db()->fetchOne(
                'SELECT id FROM ' . DB_PREFIX . "settings WHERE `key` LIKE ? ESCAPE '!'",
                [$like]
            );
            return $hit === null;          // 没有默认语言的后缀行 = 已归位
        } catch (Throwable) {
            return true;                   // 探测失败不阻塞升级链
        }
    },
    'sqls' => [],
    'php' => static function (): string {
        $T = DB_PREFIX . 'settings';
        $row = db()->fetchOne("SELECT value FROM {$T} WHERE `key` = 'site_lang'");
        $default = trim((string) ($row['value'] ?? ''));
        if ($default === '' || in_array($default, ['zh-CN', 'zh-TW', 'ja'], true)) {
            return '默认语言为中文/日文，无需归位';
        }

        $suffix = '_' . $default;
        $cjk = static fn(?string $s): bool => (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', (string) $s);
        $like = '%' . str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $suffix);
        $suffixRows = db()->fetchAll("SELECT `key`, value FROM {$T} WHERE `key` LIKE ? ESCAPE '!'", [$like]);
        if ($suffixRows === []) {
            return '未发现默认语言的后缀行，无需归位';
        }

        $plan = [];
        foreach ($suffixRows as $r) {
            $sKey = (string) $r['key'];
            $baseKey = substr($sKey, 0, -strlen($suffix));
            if ($baseKey === '') {
                continue;
            }
            $b = db()->fetchOne("SELECT value FROM {$T} WHERE `key` = ?", [$baseKey]);
            $bVal = $b === null ? null : (string) $b['value'];
            $sVal = (string) $r['value'];

            if ($bVal === null || trim($bVal) === '') {
                $rule = 1;
            } elseif ($cjk($bVal) && !$cjk($sVal)) {
                $rule = 2;
            } else {
                $rule = 3;
            }
            $plan[] = ['rule' => $rule, 'base' => $baseKey, 'suffixKey' => $sKey,
                       'baseVal' => $bVal, 'suffixVal' => $sVal];
        }

        // 备份（尽力而为：storage 不可写也不阻塞归位——所有决策都记在返回信息里）
        $bak = ROOT_PATH . '/storage/lang-shadow-backup-' . date('Ymd-His') . '.json';
        @file_put_contents($bak, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $c = [1 => 0, 2 => 0, 3 => 0];
        foreach ($plan as $p) {
            if ($p['rule'] === 1 && $p['baseVal'] === null) {
                // base 行不存在：后缀行改名成 base 行，保留 group/type 元数据
                db()->execute("UPDATE {$T} SET `key` = ? WHERE `key` = ?", [$p['base'], $p['suffixKey']]);
                $c[1]++;
                continue;
            }
            if ($p['rule'] === 1 || $p['rule'] === 2) {
                db()->execute("UPDATE {$T} SET value = ? WHERE `key` = ?", [$p['suffixVal'], $p['base']]);
            }
            db()->execute("DELETE FROM {$T} WHERE `key` = ?", [$p['suffixKey']]);
            $c[$p['rule']]++;
        }

        // 页面缓存里可能还躺着旧渲染
        foreach ((array) glob(ROOT_PATH . '/storage/cache/html/*') as $f) {
            @unlink($f);
        }

        return sprintf(
            '已归位 %d 个设置键（提升 %d、覆盖中文种子 %d、保留站长编辑 %d），备份见 storage/%s',
            $c[1] + $c[2] + $c[3], $c[1], $c[2], $c[3], basename($bak)
        );
    },
];
