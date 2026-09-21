<?php
/**
 * 商城插件语言包（zh-CN 兜底，en/ja 覆盖同名键）。
 * 后台与前台共用：loadActivePlugins() 在两侧都会先于插件代码加载本文件。
 */

declare(strict_types=1);

return [
    'shop_menu' => '商城',
    'shop_menu_sales' => '商品销售',
    'shop_nav_sales' => '销售设置',
    'shop_nav_orders' => '订单管理',
    'shop_sales_title' => '商品销售设置',
    'shop_sales_desc' => '给产品「启用销售」即叠加卖货能力；未启用的产品仍是普通企业产品展示。停用插件不影响产品页。',
    'shop_col_product' => '产品',
    'shop_col_price_display' => '产品价',
    'shop_col_sale_price' => '售价（留空=用产品价）',
    'shop_col_sku' => 'SKU',
    'shop_col_stock' => '库存',
    'shop_col_status' => '上架',
    'shop_col_sales' => '已售',
    'shop_col_action' => '操作',
    'shop_btn_save' => '保存',
    'shop_btn_search' => '搜索',
    'shop_placeholder_search' => '产品名称 / 型号关键词',
    'shop_filter_all' => '全部',
    'shop_filter_on' => '在售',
    'shop_filter_off' => '未启用',
    'shop_status_on' => '在售',
    'shop_status_off' => '未启用',
    'shop_saved' => '已保存',
    'shop_err_product' => '产品不存在',
    'shop_err_sku_len' => 'SKU 不能超过 64 个字符',
    'shop_err_status' => '上架状态不合法',
    'shop_lang_tag' => '语言',
    'shop_stock_infinite_hint' => '库存为 0 且未上架时前台不显示购买入口',
    'shop_order_status_pending_payment' => '待付款',
    'shop_order_status_paid' => '已付款',
    'shop_order_status_awaiting_ship' => '待发货',
    'shop_order_status_shipped' => '已发货',
    'shop_order_status_completed' => '已完成',
    'shop_order_status_closed' => '已关闭',
    'shop_err_schema' => '商城数据初始化失败，请检查数据库权限后重试（详情见系统日志）',
    'shop_shared_group' => '多语言共享',
    'shop_err_price' => '售价格式不正确（须为正数，最多两位小数，不超过 99999999.99；留空=用产品价）',
    'shop_err_stock' => '库存必须是不小于 0 的整数（上限 9999999999）',
];
