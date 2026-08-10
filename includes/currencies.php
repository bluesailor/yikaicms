<?php
/**
 * 常用货币表：符号 + 小数位。
 *
 * 为什么不导 ISO 3166 国家库：国家 ≠ 货币。249 个国家里大量共用同一种货币
 * （欧元区 20 国、非洲法郎区 14 国），而我们要的只是「符号」和「小数位」两个属性，
 * 那是货币的属性。导国家库既冗余，又要用户先在国家里找货币，反而绕。
 *
 * 表里只收**外贸站真会用到的**十来种。其余情况一律走「自定义」——
 * 符号输入框始终可编辑，客户想写 US$ / RMB / 元 都随意。
 *
 * decimals 是这张表真正的价值所在：
 *   日元 / 韩元 / 越南盾是 0 位，客户十有八九不知道，填成 2 位就出现
 *   「¥1,234.00」这种一眼假的价格；
 *   科威特第纳尔等海湾货币是 3 位。
 * 选中货币时自动带出小数位，比让人自己查靠谱。
 *
 * 注意：本表**不管符号位置与千分位写法**。formatPrice() 目前一律「符号在前 +
 * 英美式千分位」（$1,234.50）。德法写成 1.234,50 €，与此不符 —— 那是另一个维度的
 * 本地化，要动 formatPrice() 的格式化逻辑，见 yikaicms-docs 里的待办，别在这张表里凑合。
 */

declare(strict_types=1);

/**
 * @return array<int, array{code:string, symbol:string, decimals:int, name_key:string}>
 */
function commonCurrencies(): array
{
    return [
        ['code' => 'CNY', 'symbol' => '¥',  'decimals' => 2, 'name_key' => 'cur_cny'],
        ['code' => 'USD', 'symbol' => '$',  'decimals' => 2, 'name_key' => 'cur_usd'],
        ['code' => 'JPY', 'symbol' => '¥',  'decimals' => 0, 'name_key' => 'cur_jpy'],
        ['code' => 'EUR', 'symbol' => '€',  'decimals' => 2, 'name_key' => 'cur_eur'],
        ['code' => 'GBP', 'symbol' => '£',  'decimals' => 2, 'name_key' => 'cur_gbp'],
        ['code' => 'HKD', 'symbol' => 'HK$', 'decimals' => 2, 'name_key' => 'cur_hkd'],
        ['code' => 'TWD', 'symbol' => 'NT$', 'decimals' => 2, 'name_key' => 'cur_twd'],
        ['code' => 'KRW', 'symbol' => '₩',  'decimals' => 0, 'name_key' => 'cur_krw'],
        ['code' => 'SGD', 'symbol' => 'S$', 'decimals' => 2, 'name_key' => 'cur_sgd'],
        ['code' => 'AUD', 'symbol' => 'A$', 'decimals' => 2, 'name_key' => 'cur_aud'],
        ['code' => 'CAD', 'symbol' => 'C$', 'decimals' => 2, 'name_key' => 'cur_cad'],
        ['code' => 'RUB', 'symbol' => '₽',  'decimals' => 2, 'name_key' => 'cur_rub'],
        ['code' => 'INR', 'symbol' => '₹',  'decimals' => 2, 'name_key' => 'cur_inr'],
        ['code' => 'PHP', 'symbol' => '₱',  'decimals' => 2, 'name_key' => 'cur_php'],
        ['code' => 'VND', 'symbol' => '₫',  'decimals' => 0, 'name_key' => 'cur_vnd'],
        ['code' => 'THB', 'symbol' => '฿',  'decimals' => 2, 'name_key' => 'cur_thb'],
        ['code' => 'MYR', 'symbol' => 'RM', 'decimals' => 2, 'name_key' => 'cur_myr'],
        ['code' => 'IDR', 'symbol' => 'Rp', 'decimals' => 0, 'name_key' => 'cur_idr'],
        ['code' => 'BRL', 'symbol' => 'R$', 'decimals' => 2, 'name_key' => 'cur_brl'],
        ['code' => 'AED', 'symbol' => 'AED', 'decimals' => 2, 'name_key' => 'cur_aed'],
        ['code' => 'SAR', 'symbol' => 'SAR', 'decimals' => 2, 'name_key' => 'cur_sar'],
        ['code' => 'TRY', 'symbol' => '₺',  'decimals' => 2, 'name_key' => 'cur_try'],
        ['code' => 'MXN', 'symbol' => 'MX$', 'decimals' => 2, 'name_key' => 'cur_mxn'],
        ['code' => 'ZAR', 'symbol' => 'R',  'decimals' => 2, 'name_key' => 'cur_zar'],
    ];
}
