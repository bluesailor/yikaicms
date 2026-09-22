<?php
/**
 * 商城数据表（M0-1，立项报告 §四的数据模型）。
 *
 * 建表走 seo 插件确立的「运行时懒建表」先例（plugins/seo/links.php）：幂等、
 * MySQL/SQLite 双写法。表结构演进走 settings 里的 shop_schema_version 版本号
 * （G2：插件自带升级，不动核心 Migrator）。
 *
 * 存储约定：金额 decimal(10,2)（与全站一致，MySQL 侧精确十进制）；时间 int 时间戳；
 * 快照/回调原文 mediumtext；字符集 utf8mb4 + utf8mb4_general_ci（MySQL 5.7 底线）。
 */

declare(strict_types=1);

/** 当前商城表结构版本（加列/加表时 +1 并在 shopSchemaSteps() 补步骤）。 */
function shopSchemaVersion(): int
{
    return 2;
}

/**
 * 增量结构步骤（G2：插件自带迁移，不动核心 Migrator）。
 * 每步 = [目标版本, 描述, 执行函数]；执行前由 shopEnsureSchema 判断当前版本，
 * 只跑 > current 的步骤；步骤本身必须幂等（列/表存在即跳过）。
 * 已经跑过的历史步骤不得改写——增量追加。
 *
 * @return list<array{0:int,1:string,2:callable():void}>
 */
function shopSchemaSteps(): array
{
    return [
        [1, '初始七表', static function (): void {
            shopEnsureTables();
        }],
        // v2（M2-b）：物流单号与快递公司——发货时商家填写，买家订单页展示
        [2, '订单表增加物流单号', static function (): void {
            shopEnsureTables();   // 幂等，先保证基础表在
            $orders = DB_PREFIX . 'shop_orders';
            if (db()->isSqlite()) {
                $columns = array_map(
                    static fn(array $row): string => (string) $row['name'],
                    db()->fetchAll("PRAGMA table_info(\"{$orders}\")") ?: []
                );
                if (!in_array('tracking_company', $columns, true)) {
                    db()->execute("ALTER TABLE \"{$orders}\" ADD COLUMN \"tracking_company\" TEXT NOT NULL DEFAULT ''");
                }
                if (!in_array('tracking_no', $columns, true)) {
                    db()->execute("ALTER TABLE \"{$orders}\" ADD COLUMN \"tracking_no\" TEXT NOT NULL DEFAULT ''");
                }
                return;
            }
            // MySQL 无 IF NOT EXISTS 加列：SHOW COLUMNS 逐列判断，避免重复执行报错
            $columns = array_map(
                static fn(array $row): string => (string) ($row['Field'] ?? ''),
                db()->fetchAll("SHOW COLUMNS FROM `{$orders}`") ?: []
            );
            if (!in_array('tracking_company', $columns, true)) {
                db()->execute("ALTER TABLE `{$orders}` ADD COLUMN `tracking_company` varchar(50) NOT NULL DEFAULT '' COMMENT '快递公司'");
            }
            if (!in_array('tracking_no', $columns, true)) {
                db()->execute("ALTER TABLE `{$orders}` ADD COLUMN `tracking_no` varchar(64) NOT NULL DEFAULT '' COMMENT '物流单号'");
            }
        }],
    ];
}

/**
 * 表结构定义（纯函数，供单测核对形状）。
 *
 * `{p}` 为表前缀占位符，ensure 时替换为 DB_PREFIX。SQLite 侧类型映射：
 * varchar/decimal→TEXT（decimal 以两位小数字符串落库，读取经 shopMoneyToCents），
 * int/tinyint→INTEGER；索引单独建（SQLite 不支持内联 KEY）。
 *
 * @return array<string, array{mysql: string, sqlite: string, sqlite_indexes?: list<string>}>
 */
function shopTableSchemas(): array
{
    return [
        // 产品销售配置（1:1 关联 products，不动产品表本身——「启用销售」是叠加而非改写）
        'shop_products' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_products` (
                `product_id` int(11) UNSIGNED NOT NULL,
                `sku` varchar(64) NOT NULL DEFAULT '',
                `price` decimal(10,2) DEFAULT NULL COMMENT '为空=沿用 products.price',
                `stock` int(11) NOT NULL DEFAULT 0,
                `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=下架 1=上架',
                `specs_json` mediumtext NULL COMMENT '有限 SKU 规格与独立售价库存',
                `sales` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` int(11) NOT NULL DEFAULT 0,
                `updated_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`product_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_products\" (
                \"product_id\" INTEGER PRIMARY KEY,
                \"sku\" TEXT NOT NULL DEFAULT '',
                \"price\" TEXT NULL,
                \"stock\" INTEGER NOT NULL DEFAULT 0,
                \"status\" INTEGER NOT NULL DEFAULT 0,
                \"specs_json\" TEXT NULL,
                \"sales\" INTEGER NOT NULL DEFAULT 0,
                \"created_at\" INTEGER NOT NULL DEFAULT 0,
                \"updated_at\" INTEGER NOT NULL DEFAULT 0
            )",
            'sqlite_indexes' => [
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_products_status\" ON \"{p}shop_products\" (\"status\")",
            ],
        ],

        // 订单主表：状态/金额/快照分离（立项红线：不用一个 status 包办支付与退款）
        'shop_orders' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_orders` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` varchar(32) NOT NULL COMMENT '对外单号=支付 out_trade_no',
                `member_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=游客下单',
                `lang` varchar(10) NOT NULL DEFAULT '' COMMENT '下单语言（快照）',
                `status` varchar(20) NOT NULL DEFAULT 'pending_payment',
                `currency` char(3) NOT NULL DEFAULT 'CNY',
                `amount_goods` decimal(10,2) NOT NULL DEFAULT 0.00,
                `amount_shipping` decimal(10,2) NOT NULL DEFAULT 0.00,
                `amount_total` decimal(10,2) NOT NULL DEFAULT 0.00,
                `contact_json` mediumtext NULL COMMENT '联系人快照',
                `address_json` mediumtext NULL COMMENT '收货地址快照',
                `shipping_method` varchar(32) NOT NULL DEFAULT '',
                `shipping_note` varchar(255) NOT NULL DEFAULT '',
                `remark` varchar(500) NOT NULL DEFAULT '',
                `expire_at` int(11) NOT NULL DEFAULT 0 COMMENT '待付款截止（超时关单释放库存）',
                `paid_at` int(11) NOT NULL DEFAULT 0,
                `shipped_at` int(11) NOT NULL DEFAULT 0,
                `completed_at` int(11) NOT NULL DEFAULT 0,
                `closed_at` int(11) NOT NULL DEFAULT 0,
                `created_at` int(11) NOT NULL DEFAULT 0,
                `updated_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_status` (`status`),
                KEY `idx_member` (`member_id`),
                KEY `idx_expire` (`status`, `expire_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_orders\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"order_no\" TEXT NOT NULL,
                \"member_id\" INTEGER NOT NULL DEFAULT 0,
                \"lang\" TEXT NOT NULL DEFAULT '',
                \"status\" TEXT NOT NULL DEFAULT 'pending_payment',
                \"currency\" TEXT NOT NULL DEFAULT 'CNY',
                \"amount_goods\" TEXT NOT NULL DEFAULT '0.00',
                \"amount_shipping\" TEXT NOT NULL DEFAULT '0.00',
                \"amount_total\" TEXT NOT NULL DEFAULT '0.00',
                \"contact_json\" TEXT NULL,
                \"address_json\" TEXT NULL,
                \"shipping_method\" TEXT NOT NULL DEFAULT '',
                \"shipping_note\" TEXT NOT NULL DEFAULT '',
                \"remark\" TEXT NOT NULL DEFAULT '',
                \"expire_at\" INTEGER NOT NULL DEFAULT 0,
                \"paid_at\" INTEGER NOT NULL DEFAULT 0,
                \"shipped_at\" INTEGER NOT NULL DEFAULT 0,
                \"completed_at\" INTEGER NOT NULL DEFAULT 0,
                \"closed_at\" INTEGER NOT NULL DEFAULT 0,
                \"created_at\" INTEGER NOT NULL DEFAULT 0,
                \"updated_at\" INTEGER NOT NULL DEFAULT 0
            )",
            'sqlite_indexes' => [
                "CREATE UNIQUE INDEX IF NOT EXISTS \"uk_{p}shop_orders_no\" ON \"{p}shop_orders\" (\"order_no\")",
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_orders_status\" ON \"{p}shop_orders\" (\"status\")",
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_orders_member\" ON \"{p}shop_orders\" (\"member_id\")",
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_orders_expire\" ON \"{p}shop_orders\" (\"status\", \"expire_at\")",
            ],
        ],

        // 订单明细：商品快照在此（改商品不影响历史订单——立项红线）
        'shop_order_items' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_order_items` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` int(11) UNSIGNED NOT NULL,
                `product_id` int(11) UNSIGNED NOT NULL,
                `snapshot_json` mediumtext NOT NULL COMMENT '名称/图/单价/规格快照',
                `qty` int(11) UNSIGNED NOT NULL DEFAULT 1,
                `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
                `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
                `created_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_order_items\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"order_id\" INTEGER NOT NULL,
                \"product_id\" INTEGER NOT NULL,
                \"snapshot_json\" TEXT NOT NULL,
                \"qty\" INTEGER NOT NULL DEFAULT 1,
                \"unit_price\" TEXT NOT NULL DEFAULT '0.00',
                \"subtotal\" TEXT NOT NULL DEFAULT '0.00',
                \"created_at\" INTEGER NOT NULL DEFAULT 0
            )",
            'sqlite_indexes' => [
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_order_items_order\" ON \"{p}shop_order_items\" (\"order_id\")",
            ],
        ],

        // 支付流水：与订单状态分离；渠道单号唯一约束是幂等的第一道闸
        'shop_payments' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_payments` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` int(11) UNSIGNED NOT NULL,
                `gateway` varchar(20) NOT NULL DEFAULT 'offline',
                `status` varchar(20) NOT NULL DEFAULT 'created',
                `gateway_trade_no` varchar(64) DEFAULT NULL,
                `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `raw_notify` mediumtext NULL COMMENT '回调原文（排障对账用）',
                `created_at` int(11) NOT NULL DEFAULT 0,
                `paid_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_gateway_trade` (`gateway`, `gateway_trade_no`),
                KEY `idx_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_payments\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"order_id\" INTEGER NOT NULL,
                \"gateway\" TEXT NOT NULL DEFAULT 'offline',
                \"status\" TEXT NOT NULL DEFAULT 'created',
                \"gateway_trade_no\" TEXT NULL,
                \"amount\" TEXT NOT NULL DEFAULT '0.00',
                \"raw_notify\" TEXT NULL,
                \"created_at\" INTEGER NOT NULL DEFAULT 0,
                \"paid_at\" INTEGER NOT NULL DEFAULT 0
            )",
            'sqlite_indexes' => [
                "CREATE UNIQUE INDEX IF NOT EXISTS \"uk_{p}shop_payments_trade\" ON \"{p}shop_payments\" (\"gateway\", \"gateway_trade_no\")",
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_payments_order\" ON \"{p}shop_payments\" (\"order_id\")",
            ],
        ],

        // 回调记录：notify_hash 唯一约束是幂等的第二道闸（重复通知直接拒收）
        'shop_payment_notifications' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_payment_notifications` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `gateway` varchar(20) NOT NULL,
                `notify_hash` char(64) NOT NULL,
                `received_at` int(11) NOT NULL DEFAULT 0,
                `processed` tinyint(1) NOT NULL DEFAULT 0,
                `process_note` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_notify_hash` (`notify_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_payment_notifications\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"gateway\" TEXT NOT NULL,
                \"notify_hash\" TEXT NOT NULL,
                \"received_at\" INTEGER NOT NULL DEFAULT 0,
                \"processed\" INTEGER NOT NULL DEFAULT 0,
                \"process_note\" TEXT NOT NULL DEFAULT ''
            )",
            'sqlite_indexes' => [
                "CREATE UNIQUE INDEX IF NOT EXISTS \"uk_{p}shop_pay_notify_hash\" ON \"{p}shop_payment_notifications\" (\"notify_hash\")",
            ],
        ],

        // 退款（人工流程）：状态独立于订单与支付
        'shop_refunds' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_refunds` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` int(11) UNSIGNED NOT NULL,
                `payment_id` int(11) UNSIGNED NOT NULL,
                `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
                `status` varchar(20) NOT NULL DEFAULT 'requested',
                `reason` varchar(500) NOT NULL DEFAULT '',
                `admin_note` varchar(500) NOT NULL DEFAULT '',
                `admin_id` int(11) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` int(11) NOT NULL DEFAULT 0,
                `handled_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_refunds\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"order_id\" INTEGER NOT NULL,
                \"payment_id\" INTEGER NOT NULL,
                \"amount\" TEXT NOT NULL DEFAULT '0.00',
                \"status\" TEXT NOT NULL DEFAULT 'requested',
                \"reason\" TEXT NOT NULL DEFAULT '',
                \"admin_note\" TEXT NOT NULL DEFAULT '',
                \"admin_id\" INTEGER NOT NULL DEFAULT 0,
                \"created_at\" INTEGER NOT NULL DEFAULT 0,
                \"handled_at\" INTEGER NOT NULL DEFAULT 0
            )",
            'sqlite_indexes' => [
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_refunds_order\" ON \"{p}shop_refunds\" (\"order_id\")",
            ],
        ],

        // 会员收货地址（游客下单不落此表，直接进订单快照）
        'shop_member_addresses' => [
            'mysql' => "CREATE TABLE IF NOT EXISTS `{p}shop_member_addresses` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `member_id` int(11) UNSIGNED NOT NULL,
                `contact_json` mediumtext NOT NULL,
                `address_json` mediumtext NOT NULL,
                `is_default` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` int(11) NOT NULL DEFAULT 0,
                `updated_at` int(11) NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `idx_member` (`member_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
            'sqlite' => "CREATE TABLE IF NOT EXISTS \"{p}shop_member_addresses\" (
                \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,
                \"member_id\" INTEGER NOT NULL,
                \"contact_json\" TEXT NOT NULL,
                \"address_json\" TEXT NOT NULL,
                \"is_default\" INTEGER NOT NULL DEFAULT 0,
                \"created_at\" INTEGER NOT NULL DEFAULT 0,
                \"updated_at\" INTEGER NOT NULL DEFAULT 0
            )",
            'sqlite_indexes' => [
                "CREATE INDEX IF NOT EXISTS \"idx_{p}shop_member_addr_member\" ON \"{p}shop_member_addresses\" (\"member_id\")",
            ],
        ],
    ];
}

/** 商城表名（不含前缀的短名清单）。@return list<string> */
function shopTableNames(): array
{
    return array_keys(shopTableSchemas());
}

/**
 * 幂等建表（MySQL/SQLite 各走各的方言）。失败抛异常——商城宁可显式报错
 * 也不在缺表状态下继续跑（订单写一半发现表不存在比装不上更糟）。
 */
function shopEnsureTables(): void
{
    $prefix = DB_PREFIX;
    $sqlite = db()->isSqlite();
    foreach (shopTableSchemas() as $schema) {
        db()->execute(str_replace('{p}', $prefix, $schema[$sqlite ? 'sqlite' : 'mysql']));
        if ($sqlite) {
            foreach ($schema['sqlite_indexes'] ?? [] as $indexSql) {
                db()->execute(str_replace('{p}', $prefix, $indexSql));
            }
        }
    }
}

/**
 * 结构版本推进（G2：插件自带迁移，不动核心 Migrator）。
 * 版本号存 settings.shop_schema_version；按 shopSchemaSteps() 增量执行
 * 「高于当前版本」的步骤，全部完成后一次性写新版本号。步骤失败抛异常，
 * 版本号不推进——下次请求重试；步骤自身幂等，重试安全。
 */
function shopEnsureSchema(): void
{
    try {
        $current = (int) settingModel()->get('shop_schema_version', '0');
    } catch (\Throwable $e) {
        $current = 0;   // settings 表尚不可用（极端安装期）：按未初始化处理
    }
    if ($current >= shopSchemaVersion()) {
        return;
    }
    foreach (shopSchemaSteps() as [$version, $description, $apply]) {
        if ($version <= $current) {
            continue;
        }
        $apply();
        error_log("[shop] schema v{$version} applied: {$description}");
    }
    settingModel()->set('shop_schema_version', (string) shopSchemaVersion());
}
