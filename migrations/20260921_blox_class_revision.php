<?php
/**
 * blox_global_classes 加 revision 字段（外审 P1-4）。
 *
 * 旧乐观锁用秒级 modified 时间戳做比较：同一秒内的两次并发保存分不出先后，
 * 后写覆盖先写且无人察觉。revision 每次写入 +1，配合
 * UPDATE ... WHERE revision = 读取值 的原子比较，旧版本二次写必然 409 冲突。
 */

declare(strict_types=1);

return [
    'id' => '20260921_blox_class_revision',
    'title' => 'Blox 全局类写入版本号',
    'desc' => '为 yikai_blox_global_classes 表新增 revision 字段（int，默认 0）：每次写入 +1，替代秒级 modified 时间戳做乐观并发校验，同一秒内的并发保存不再互相覆盖。',
    'title_en' => 'Blox global class write revision',
    'title_ja' => 'Blox グローバルクラスの書き込みリビジョン',
    'desc_en' => 'Adds a revision column (int, default 0) to the global classes table: each write increments it and optimistic concurrency compares it atomically, so concurrent saves within the same second no longer overwrite each other.',
    'desc_ja' => 'グローバルクラステーブルに revision 列（int、デフォルト 0）を追加します。書き込みごとに +1 され、楽観的並行制御が原子的に比較するため、同一秒内の同時保存が上書きし合うことはなくなります。',
    'check' => static fn (): bool => _columnExists('blox_global_classes', 'revision'),
    'sqls' => [
        "ALTER TABLE `" . DB_PREFIX . "blox_global_classes` ADD COLUMN `revision` int(11) unsigned NOT NULL DEFAULT 0 COMMENT '写入版本号：每次写 +1，乐观并发校验' AFTER `modified`",
    ],
];
