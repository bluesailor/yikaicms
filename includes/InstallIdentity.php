<?php
/**
 * YikaiCMS —— 站点编号（2.0.3）。
 *
 * 站点回访 update 服务器时带上一个随机、稳定的编号，服务器按它建档、按它下发升级指令。
 *
 * 为什么不能只靠域名：同一域名下的子目录站（demo.yikaicms.com/yikai-* 这类演示站、
 * 客户在一个域名下挂多个语言/品牌站）在服务器上会被合并成一条记录，升级指令也按域名
 * 绑定——只能「一个域名下全升」，无法逐站下发、逐站看结果，更做不了先升一两个试水。
 *
 * 复制出来的站（整目录拷贝或整库搬迁来建新站）会带着同一个编号。所以编号与安装目录
 * 的指纹一起保存：目录对不上就重新生成，拷贝站自然拿到自己的编号；站点本身不挪目录，
 * 编号就永远不变。只含随机数与目录的哈希，不含任何可识别信息。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class InstallIdentity
{
    /** 设置键（JSON：{"id": 32 位十六进制, "root": 安装目录指纹}）。 */
    private const KEY = 'install_identity';

    private static ?string $memo = null;

    /** 本站编号；设置表写不进去时返回空串（宁可不带，也不要每次回访换一个）。 */
    public static function id(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $root = self::rootFingerprint();
        $saved = json_decode((string) settingModel()->get(self::KEY, ''), true);
        if (is_array($saved)
            && is_string($saved['id'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/', $saved['id']) === 1
            && ($saved['root'] ?? null) === $root) {
            return self::$memo = $saved['id'];
        }
        $id = bin2hex(random_bytes(16));
        try {
            settingModel()->set(self::KEY, (string) json_encode(['id' => $id, 'root' => $root]), 'system');
            settingModel()->clearCache();
            $check = json_decode((string) settingModel()->get(self::KEY, ''), true);
            if (!is_array($check) || ($check['id'] ?? null) !== $id) {
                return self::$memo = '';
            }
        } catch (\Throwable) {
            return self::$memo = '';
        }
        return self::$memo = $id;
    }

    /**
     * 回访 check.php 时统一携带的站点标识。四处回访（自动升级、后台检查更新、
     * 在线升级、站点健康）都走这里，服务器上才不会一处按编号、一处按域名各建一条。
     *
     * `build` / `integrity` 来自包内的出厂证明（config/provenance.php）：站点上报的版本号
     * 只是 config/version.php 里的一个常量，改一行就能冒充任意版本（2026-09-30 在服务器
     * 站点清单里见到过不存在的 1.29.2）。构建编号与核心文件指纹校验结果让服务器能分辨
     * 「官方包原样」「改过核心文件」「版本号与出厂证明对不上」。只报结论，不报文件内容与路径。
     *
     * @return array{domain: string, install_id: string, base: string, build: string, integrity: string}
     */
    public static function reportParams(): array
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $siteUrl = function_exists('config') ? (string) config('site_url', '') : '';
        return [
            'domain' => $host !== '' ? $host : $siteUrl,
            'install_id' => self::id(),
            'base' => self::basePath($host, $siteUrl),
        ] + self::buildParams();
    }

    /** @return array{build: string, integrity: string} */
    private static function buildParams(): array
    {
        try {
            require_once __DIR__ . '/ProductIdentity.php';
            $info = YikaiProductIdentity::buildInfo(ROOT_PATH);
            return [
                'build' => mb_substr((string) $info['build_id'], 0, 80),
                'integrity' => (string) $info['integrity'],
            ];
        } catch (\Throwable) {
            return ['build' => '', 'integrity' => 'invalid'];
        }
    }

    /**
     * 子目录挂载点（根目录站为空串），供服务器区分同域名下的站点。
     * 计划任务从命令行跑时拿不到请求路径，改从站点地址里取。
     */
    private static function basePath(string $host, string $siteUrl): string
    {
        require_once __DIR__ . '/BasePath.php';
        $base = BasePath::get();
        if ($base === '' && $host === '' && $siteUrl !== '') {
            $path = parse_url($siteUrl, PHP_URL_PATH);
            $base = is_string($path) ? rtrim($path, '/') : '';
        }
        return preg_match('#^(/[A-Za-z0-9._~%-]+)*$#', $base) === 1 ? mb_substr($base, 0, 200) : '';
    }

    private static function rootFingerprint(): string
    {
        $root = realpath(ROOT_PATH);
        $root = str_replace('\\', '/', $root === false ? ROOT_PATH : $root);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);   // Windows 盘符与目录大小写写法不固定
        }
        return substr(hash('sha256', rtrim($root, '/')), 0, 16);
    }

    /** 仅测试用：清掉进程内缓存。 */
    public static function resetForTests(): void
    {
        self::$memo = null;
    }
}
