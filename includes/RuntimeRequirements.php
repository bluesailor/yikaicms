<?php
/**
 * Yikai CMS - 运行环境要求（安装器 / 健康检查 / 兼容层 / README 的唯一来源）
 *
 * 为什么要收成一份：v1.19.3 之前同一个问题有五个互相矛盾的答案——
 *
 *   README        PHP >= 8.0
 *   composer.json PHP >= 8.0
 *   安装器         PHP >= 8.0，必需 pdo/json/mbstring/fileinfo/dom，openssl 算「可选」
 *   Compatibility 必需 curl/mbstring/openssl/json/pdo（不查 fileinfo/dom）
 *   SiteHealth    PHP >= 8.2 判 CRITICAL，必需项里还多一个 simplexml
 *
 * 后果是实打实的：装得上 8.0 的站，打开站点健康检查会看到一条红色 CRITICAL；
 * 而 simplexml 核心根本没用到（只有 product-import 插件用），缺了也报红。
 *
 * 这里的分级口径：
 *   PHP_MINIMUM      硬地板。低于它安装器直接拦——功能真的跑不起来。
 *   PHP_RECOMMENDED  建议线。介于两者之间安装照常，健康检查给「建议」而非「严重」。
 *   REQUIRED         缺了核心功能直接坏（正文净化、上传 MIME 检测、数据库）。
 *   RECOMMENDED      缺了只是降级（装不了在线升级、生不成缩略图、验不了授权）。
 *
 * 加新扩展要求时，请连同「缺了会怎样」一起写进下面的注释——
 * 分级的依据是后果，不是习惯。
 */
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition 直连访问本文件时的守卫；Psalm 按包含顺序已认定 ROOT_PATH 有定义。 */
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class RuntimeRequirements
{
    /** 硬地板：低于此版本安装器拒绝安装 */
    public const PHP_MINIMUM = '8.0.0';

    /** 建议线：低于此版本仍可运行，健康检查给出建议 */
    public const PHP_RECOMMENDED = '8.2.0';

    /** 缺了核心功能就坏 */
    private const REQUIRED = [
        'pdo'      => '数据库访问，缺了整站跑不起来',
        'json'     => '配置、Blox 文档、API 全部走 JSON',
        'mbstring' => '中日文截断与大小写，缺了正文会截出乱码',
        'fileinfo' => '上传 MIME 检测，是上传安全基线（v1.18.6 起必需）',
        'dom'      => '富文本净化 HtmlPolicy 用 DOMDocument 做白名单，缺了正文降级成转义纯文本',
    ];

    /** 缺了只是降级，不拦安装 */
    private const RECOMMENDED = [
        'curl'      => '在线升级与 AI 服务；缺了退回 file_get_contents，部分主机会失败',
        'openssl'   => '授权校验与加密；缺了商业授权验不了',
        'gd'        => '缩略图与图片压缩；缺了上传仍可用但不生成缩略图',
        'zip'       => '在线升级解包与远程模板导入；缺了只能手工传包升级',
        'simplexml' => 'product-import 插件读 xlsx 用；核心不依赖，未装该插件可忽略',
    ];

    /** 数据库驱动：至少要有一个 */
    private const DATABASE = ['pdo_mysql', 'pdo_sqlite'];

    /** @return array<string,string> 扩展名 => 缺了会怎样 */
    public static function required(): array
    {
        return self::REQUIRED;
    }

    /** @return array<string,string> */
    public static function recommended(): array
    {
        return self::RECOMMENDED;
    }

    /** @return list<string> */
    public static function requiredNames(): array
    {
        return array_keys(self::required());
    }

    /** @return list<string> */
    public static function recommendedNames(): array
    {
        return array_keys(self::recommended());
    }

    /** @return list<string> */
    public static function databaseNames(): array
    {
        return self::DATABASE;
    }

    public static function phpMeetsMinimum(?string $version = null): bool
    {
        return version_compare($version ?? PHP_VERSION, self::PHP_MINIMUM, '>=');
    }

    public static function phpMeetsRecommended(?string $version = null): bool
    {
        return version_compare($version ?? PHP_VERSION, self::PHP_RECOMMENDED, '>=');
    }

    /**
     * 供 README / 安装界面显示，如 "8.0+"。
     *
     * 不要用 rtrim(self::PHP_MINIMUM, '.0')：rtrim 第二参是**字符集合**不是后缀，
     * rtrim('8.0.0', '.0') 会一路剥到只剩 '8'，安装器于是显示 "PHP 8+"。
     * 取前两段最直白，也不会随补丁号变化。
     */
    public static function phpMinimumLabel(): string
    {
        $parts = explode('.', self::PHP_MINIMUM);
        return implode('.', array_slice($parts, 0, 2)) . '+';
    }

    /** @return list<string> 缺失的必需扩展 */
    public static function missingRequired(): array
    {
        return array_values(array_filter(
            self::requiredNames(),
            static fn (string $ext): bool => !extension_loaded($ext)
        ));
    }

    /** @return list<string> 缺失的建议扩展 */
    public static function missingRecommended(): array
    {
        return array_values(array_filter(
            self::recommendedNames(),
            static fn (string $ext): bool => !extension_loaded($ext)
        ));
    }

    /** 至少有一个数据库驱动 */
    public static function hasDatabaseDriver(): bool
    {
        foreach (self::DATABASE as $ext) {
            if (extension_loaded($ext)) {
                return true;
            }
        }
        return false;
    }
}
