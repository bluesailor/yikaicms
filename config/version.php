<?php
/**
 * YikaiCMS 版本号 —— 单一可信来源（独立于 config.php）。
 *
 * 为什么单独成文件：config.php 含库密码 / DEMO_MODE，是「部署/升级排除项」，
 * 升级时不会被覆盖；版本号放这里 → 随发布包覆盖 → 在线升级解压新包即自动升版本号，
 * 无需改各站 config.php。请勿手动修改（由发布 / 在线升级流程维护）。
 */

if (!defined('CMS_VERSION')) {
    define('CMS_VERSION', '1.19.7');
}
