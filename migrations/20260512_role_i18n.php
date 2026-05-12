<?php
/**
 * 角色表多语言支持
 *
 * 给 yikai_roles 加 name_en / name_ja / description_en / description_ja 列；
 * 填默认 3 个角色（超级管理员/编辑/运营）的 EN/JA 翻译。
 *
 * permissions 字段是 ability key（"content" / "media" / "*"），不翻译。
 */

declare(strict_types=1);

return [
    'id'    => '20260512_role_i18n',
    'title' => '角色：name/description 加 EN/JA 列',
    'desc'  => '给 yikai_roles 加 name_en/ja 和 description_en/ja 共 4 个 per-lang 列，'
               . '并填默认 3 个内置角色（超级管理员/编辑/运营）的 EN/JA 翻译。'
               . '幂等：检测 name_en 列已存在则跳过 ALTER；UPDATE 用 WHERE 子句，重跑也安全。',
    'check' => function (): bool {
        return function_exists('_columnExists') && _columnExists('roles', 'name_en');
    },
    'sqls' => [],
    'php' => function (): string {
        $isSqlite = db()->isSqlite();
        $T = DB_PREFIX . 'roles';

        if (!_columnExists('roles', 'name_en')) {
            if ($isSqlite) {
                db()->execute("ALTER TABLE $T ADD COLUMN name_en VARCHAR(50) NOT NULL DEFAULT ''");
                db()->execute("ALTER TABLE $T ADD COLUMN name_ja VARCHAR(50) NOT NULL DEFAULT ''");
                db()->execute("ALTER TABLE $T ADD COLUMN description_en VARCHAR(255) NOT NULL DEFAULT ''");
                db()->execute("ALTER TABLE $T ADD COLUMN description_ja VARCHAR(255) NOT NULL DEFAULT ''");
            } else {
                db()->execute("ALTER TABLE `$T` ADD COLUMN `name_en` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'EN 角色名' AFTER `name`");
                db()->execute("ALTER TABLE `$T` ADD COLUMN `name_ja` VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'JA 角色名' AFTER `name_en`");
                db()->execute("ALTER TABLE `$T` ADD COLUMN `description_en` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'EN 角色描述' AFTER `description`");
                db()->execute("ALTER TABLE `$T` ADD COLUMN `description_ja` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'JA 角色描述' AFTER `description_en`");
            }
        }

        // 默认内置角色翻译（按 zh-CN 名匹配，老库改过名称的不动）
        $translations = [
            '超级管理员' => [
                'en' => ['name' => 'Super Admin',    'desc' => 'Full access to all features'],
                'ja' => ['name' => 'スーパー管理者', 'desc' => 'すべての機能にアクセス可能'],
            ],
            '编辑' => [
                'en' => ['name' => 'Editor',     'desc' => 'Content editing permissions'],
                'ja' => ['name' => '編集者',     'desc' => 'コンテンツ編集権限'],
            ],
            '运营' => [
                'en' => ['name' => 'Operations', 'desc' => 'Operations management permissions'],
                'ja' => ['name' => '運営',       'desc' => '運営管理権限'],
            ],
        ];

        $updated = 0;
        foreach ($translations as $zhName => $langs) {
            $row = db()->fetchOne("SELECT id FROM $T WHERE name = ? LIMIT 1", [$zhName]);
            if (!$row) continue;
            db()->execute(
                "UPDATE $T SET name_en = ?, name_ja = ?, description_en = ?, description_ja = ? WHERE id = ?",
                [
                    $langs['en']['name'], $langs['ja']['name'],
                    $langs['en']['desc'], $langs['ja']['desc'],
                    (int)$row['id'],
                ]
            );
            $updated++;
        }

        return "角色 i18n：4 列已加，$updated 个默认角色已填 EN/JA 翻译";
    },
];
