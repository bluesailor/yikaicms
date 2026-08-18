<?php

declare(strict_types=1);

return [
    'id' => '20260818_localize_demo_job_fields',
    'title' => '补齐演示职位结构化字段翻译',
    'desc' => '修复英文、日文演示职位中残留的中文学历、经验、摘要和任职要求。',
    'check' => static function (): bool {
        if (!db()->tableExists('jobs')) {
            return true;
        }
        return (int) db()->fetchColumn(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'jobs'
            . ' WHERE lang IN (?, ?) AND title IN (?, ?, ?, ?)'
            . ' AND (education = ? OR summary LIKE ? OR requirements LIKE ?)',
            ['en', 'ja', 'Senior PHP Engineer', 'Frontend Engineer', 'PHP シニアエンジニア', 'フロントエンドエンジニア', '本科', '%负责公司%', '%熟悉%']
        ) === 0;
    },
    'sqls' => [],
    'php' => static function (): string {
        $repairs = [
            'en|Senior PHP Engineer' => [
                'summary' => ['负责公司核心产品的后端开发', 'Responsible for backend development of our core products.'],
                'education' => ['本科', 'Bachelor degree'],
                'experience' => ['3年以上', '3+ years'],
                'requirements' => ["熟悉PHP 8.0+\n熟悉MySQL\n有CMS开发经验优先", "Proficient in PHP 8.0+\nProficient in MySQL\nCMS development experience preferred"],
            ],
            'en|Frontend Engineer' => [
                'summary' => ['负责公司产品的前端界面开发', 'Responsible for frontend interface development of our products.'],
                'education' => ['本科', 'Bachelor degree'],
                'experience' => ['2年以上', '2+ years'],
                'requirements' => ["熟悉Vue/React\n熟悉Tailwind CSS\n注重代码质量", "Proficient in Vue / React\nProficient in Tailwind CSS\nStrong focus on code quality"],
            ],
            'ja|PHP シニアエンジニア' => [
                'summary' => ['负责公司核心产品的后端开发', '当社の主力製品のバックエンド開発を担当します。'],
                'education' => ['本科', '大卒'],
                'requirements' => ["熟悉PHP 8.0+\n熟悉MySQL\n有CMS开发经验优先", "PHP 8.0+ に精通\nMySQL に精通\nCMS 開発経験者優遇"],
            ],
            'ja|フロントエンドエンジニア' => [
                'summary' => ['负责公司产品的前端界面开发', '当社製品のフロントエンド開発を担当します。'],
                'education' => ['本科', '大卒'],
                'requirements' => ["熟悉Vue/React\n熟悉Tailwind CSS\n注重代码质量", "Vue / React に精通\nTailwind CSS に精通\nコード品質を重視"],
            ],
        ];
        $rows = db()->fetchAll(
            'SELECT * FROM ' . DB_PREFIX . 'jobs WHERE lang IN (?, ?)',
            ['en', 'ja']
        );
        $updated = 0;
        foreach ($rows as $row) {
            $repair = $repairs[(string) $row['lang'] . '|' . (string) $row['title']] ?? null;
            if (!is_array($repair)) {
                continue;
            }
            $changes = [];
            foreach ($repair as $field => [$oldValue, $newValue]) {
                if ((string) ($row[$field] ?? '') === $oldValue) {
                    $changes[$field] = $newValue;
                }
            }
            if ($changes !== []) {
                db()->update('jobs', $changes, 'id = ?', [(int) $row['id']]);
                $updated++;
            }
        }
        return "已修复 {$updated} 条演示职位翻译";
    },
];
