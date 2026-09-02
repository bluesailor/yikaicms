<?php
/**
 * YikaiCMS 产品身份唯一来源。
 *
 * Product ID 使用反向域名格式，既稳定又不会与客户站点品牌混淆。
 * fingerprint_files 仅包含 YikaiCMS 自有核心代码，不把第三方组件纳入权属指纹。
 */

declare(strict_types=1);

return [
    'vendor_id' => 'cn.yikai',
    'product_id' => 'cn.yikai.yikaicms',
    'product_name' => 'YikaiCMS',
    'vendor_name' => 'Yikai',
    'copyright_holder' => 'Yikai',
    'vendor_url' => 'https://yikai.cn',
    'product_url' => 'https://www.yikaicms.com',
    'license_id' => 'YIKAI-CMS-LICENSE-1.0',
    'fingerprint_files' => [
        'config/product.php',
        'config/version.php',
        'includes/init.php',
        'includes/functions.php',
        'includes/FooterNavigation.php',
        'includes/ProductIdentity.php',
        'includes/security.php',
        'includes/License.php',
        'includes/builder/BloxDocumentPipeline.php',
        'includes/builder/BloxTemplateImporter.php',
    ],
];
