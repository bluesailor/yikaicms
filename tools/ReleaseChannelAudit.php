<?php

/**
 * 发布渠道核对（R1）。
 *
 * 存在的理由：`release-precheck.sh --post-release` 只验「标签是否合入 main」就 exit 0，
 * 发布之后没有任何工具看过官网、本地归档和模板市场。于是「安装包发过了」被记成
 * 「发布全部完成」。这个类把每条渠道单独判定，并把「未执行」和「已验证」严格区分开。
 *
 * 三条设计约束，都是被真实事故逼出来的：
 *
 * 1. **未执行 ≠ 通过。** 候选阶段允许线上还没更新（记 skipped）；发布后阶段任何一条
 *    必需渠道不是 verified，整体就不算完成。跳过不能冒充成功。
 * 2. **判据是「最新条目」，不是「出现过」。** 更新日志天然累积所有历史版本，
 *    `grep v1.19.8` 一旦通过就永远通过。这里要求目标版本是文档顺序里的第一条。
 * 3. **记录观测时刻与文件 mtime。** 官网目录是活动目录，会在核对期间被人编辑；
 *    不记这两个字段，两个人跑同一条命令得到不同结论还都以为对方错了。
 *
 * 网络检查走注入的 fetcher：CI 用 fixture，本地用 curl。没有 fetcher 时线上子项记
 * 未执行——于是发布后模式会如实失败，而不是悄悄放行。
 */

declare(strict_types=1);

final class ReleaseChannelAudit
{
    public const VERIFIED = 'verified';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public const MODE_CANDIDATE = 'candidate';
    public const MODE_POST_RELEASE = 'post-release';

    private const VERSION_TOKEN = '/v(\d+\.\d+\.\d+(?:\.\d+)?)/';

    /**
     * @param array<string,mixed> $config
     * @param null|callable(string):array{status:int,type:string,bytes:int,error:string} $fetcher
     * @return array<string,mixed>
     */
    public static function run(
        array $config,
        string $version,
        string $mode,
        string $workspace,
        ?callable $fetcher = null
    ): array {
        self::assertVersion($version);
        if (!in_array($mode, [self::MODE_CANDIDATE, self::MODE_POST_RELEASE], true)) {
            throw new InvalidArgumentException("Unknown audit mode: {$mode}");
        }

        $observedAt = gmdate('c');
        $channels = [
            'website' => self::website($config, $version, $workspace),
            'archive' => self::archive($config, $version, $workspace),
            'update_server' => self::updateServer($config, $version, $workspace),
            'market' => self::market($config, $version, $workspace, $fetcher),
            'github' => self::github($config, $version, $fetcher),
        ];

        // 候选阶段：线上渠道允许尚未同步，降级为「未执行」而不是失败。
        // 但本地归档任何时候都必须成立——包都没打出来就没什么好核对的。
        $optional = is_array($config['candidate_optional'] ?? null) ? $config['candidate_optional'] : [];
        if ($mode === self::MODE_CANDIDATE) {
            foreach ($optional as $name) {
                if (isset($channels[$name])
                    && $channels[$name]['status'] === self::FAILED
                    && !self::hasInvariantFailure($channels[$name])
                ) {
                    $channels[$name]['status'] = self::SKIPPED;
                    $channels[$name]['note'] = '候选阶段：线上允许尚未同步，本项不计失败';
                }
            }
        }

        $ok = true;
        foreach ($channels as $result) {
            if ($result['status'] === self::FAILED) {
                $ok = false;
            }
            // 发布后阶段，「未执行」同样不算完成——不能用跳过代替成功。
            if ($mode === self::MODE_POST_RELEASE && $result['status'] === self::SKIPPED) {
                $ok = false;
            }
        }

        return [
            'schema' => 1,
            'version' => $version,
            'mode' => $mode,
            'observed_at' => $observedAt,
            'workspace' => self::normalize($workspace),
            'channels' => $channels,
            'ok' => $ok,
        ];
    }

    // ── 官网：三语首页 + 三语更新日志，六个页面 ──────────────────────

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function website(array $config, string $version, string $workspace): array
    {
        $cfg = self::section($config, 'website');
        $checks = [];
        $dir = self::resolveDir($cfg, $workspace);
        if ($dir === null) {
            return self::channel('官网', self::FAILED, [self::check(
                '官网目录',
                false,
                '找不到官网目录；请设置 ' . (string) ($cfg['dir_env'] ?? '') . ' 指向实际路径'
                    . '（默认相对 workspace: ' . (string) ($cfg['dir_default'] ?? '') . '）',
                null,
                true
            )]);
        }
        $checks[] = self::check('官网目录', true, $dir);

        $downloadUrl = str_replace('{version}', $version, (string) ($cfg['download_url'] ?? ''));

        foreach (self::pages($cfg, 'home_pages') as $lang => $relative) {
            $path = $dir . '/' . $relative;
            $html = self::readFile($path);
            if ($html === null) {
                $checks[] = self::check("首页 {$lang}", false, "页面缺失: {$path}");
                continue;
            }
            $evidence = self::evidence($path);

            // 判据是「页面提到的最高版本 == 目标版本」，不是「只能出现一个版本号」。
            // 首页有合法的历史引用（例如「自 v1.16.0 起采用软件许可协议」），
            // 一刀切会造假红；而假红会训练人忽略这道闸，和漏检一样有害。
            //
            // 最高版本这条判据同时守住两个方向：
            //   站点停在旧版 → 最高版本低于目标 → 失败（正是要抓的「官网没同步」）
            //   站点宣传了未发布的版本 → 最高版本高于目标 → 失败
            $found = self::versionTokens($html);
            $highest = self::highestVersion($found);
            if ($highest === null) {
                $checks[] = self::check("首页 {$lang} 版本号", false, "未发现任何版本号（期望 {$version}）", $evidence);
            } elseif ($highest !== $version) {
                $checks[] = self::check(
                    "首页 {$lang} 版本号",
                    false,
                    "页面提到的最高版本是 {$highest}，期望 {$version}"
                        . (version_compare($highest, $version, '<') ? '（官网落后于本版）' : '（官网宣传了未发布的版本）'),
                    $evidence
                );
            } else {
                $others = array_values(array_filter(array_unique($found), static fn (string $v): bool => $v !== $version));
                $note = $others === [] ? '' : '；另有历史引用 ' . implode(', ', $others);
                $checks[] = self::check("首页 {$lang} 版本号", true, "最高版本 {$version}{$note}", $evidence);
            }

            if ($downloadUrl !== '' && str_contains($html, $downloadUrl)) {
                $checks[] = self::check("首页 {$lang} 下载入口", true, $downloadUrl, $evidence);
            } else {
                $checks[] = self::check(
                    "首页 {$lang} 下载入口",
                    false,
                    "未找到指向本版完整包的下载链接: {$downloadUrl}",
                    $evidence
                );
            }
        }

        foreach (self::pages($cfg, 'changelog_pages') as $lang => $relative) {
            $path = $dir . '/' . $relative;
            $html = self::readFile($path);
            if ($html === null) {
                $checks[] = self::check("更新日志 {$lang}", false, "页面缺失: {$path}");
                continue;
            }
            $evidence = self::evidence($path);
            $found = self::versionTokens($html);
            // 关键：取文档顺序的第一个版本号。日志累积所有历史版本，
            // 「出现过目标版本」证明不了「目标版本是最新条目」。
            $newest = $found[0] ?? '';
            $highest = self::highestVersion($found);
            if ($highest !== null && $highest !== $version && version_compare($highest, $version, '>')) {
                // 日志里出现比本版更新的条目：要么发早了，要么版本号写错了。
                $checks[] = self::check(
                    "更新日志 {$lang} 最新条目",
                    false,
                    "出现了比本版更新的条目 {$highest}（本版 {$version}）",
                    $evidence
                );
            } elseif ($newest === $version) {
                $checks[] = self::check("更新日志 {$lang} 最新条目", true, $version, $evidence);
            } else {
                $checks[] = self::check(
                    "更新日志 {$lang} 最新条目",
                    false,
                    $newest === ''
                        ? "页面里没有任何版本条目（期望最新条目为 {$version}）"
                        : "最新条目是 {$newest}，期望 {$version}"
                        . (in_array($version, $found, true) ? '（目标版本只出现在历史条目里，不算已同步）' : ''),
                    $evidence
                );
            }
        }

        return self::channel('官网', self::statusOf($checks), $checks, ['expected_version' => $version]);
    }

    // ── 本地归档：完整包、校验、构建证据、已发布的增量包 ──────────────

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function archive(array $config, string $version, string $workspace): array
    {
        $cfg = self::section($config, 'archive');
        $dir = self::resolveDir($cfg, $workspace);
        if ($dir === null) {
            return self::channel('本地归档', self::FAILED, [self::check(
                '归档目录',
                false,
                '找不到归档目录；请设置 ' . (string) ($cfg['dir_env'] ?? '')
            )]);
        }
        $checks = [self::check('归档目录', true, $dir)];

        $package = $dir . '/' . str_replace('{version}', $version, (string) $cfg['package']);
        $actualHash = is_file($package) ? hash_file('sha256', $package) : false;
        if (!is_string($actualHash)) {
            $checks[] = self::check('正式完整包', false, "缺失: {$package}");
            return self::channel('本地归档', self::FAILED, $checks, ['expected_version' => $version]);
        }
        $checks[] = self::check('正式完整包', true, basename($package) . ' sha256=' . $actualHash, self::evidence($package));

        $checksumPath = $dir . '/' . str_replace('{version}', $version, (string) $cfg['checksum']);
        $recorded = self::hashFromChecksumFile($checksumPath);
        if ($recorded === null) {
            $checks[] = self::check('校验文件', false, "缺失或无法解析: {$checksumPath}");
        } elseif (!hash_equals($recorded, $actualHash)) {
            $checks[] = self::check('校验文件', false, "记录 {$recorded} 与实际 {$actualHash} 不符", self::evidence($checksumPath));
        } else {
            $checks[] = self::check('校验文件', true, $recorded, self::evidence($checksumPath));
        }

        $evidencePath = $dir . '/' . str_replace('{version}', $version, (string) $cfg['evidence']);
        $evidenceJson = self::readJson($evidencePath);
        if ($evidenceJson === null) {
            $checks[] = self::check('构建证据', false, "缺失或非法 JSON: {$evidencePath}");
        } else {
            $problems = [];
            if ((string) ($evidenceJson['version'] ?? '') !== $version) {
                $problems[] = 'version=' . (string) ($evidenceJson['version'] ?? '(缺)');
            }
            if (!hash_equals((string) ($evidenceJson['artifact_sha256'] ?? ''), $actualHash)) {
                $problems[] = 'artifact_sha256 与实际包不符';
            }
            if (preg_match('/^[a-f0-9]{40}$/D', (string) ($evidenceJson['source_commit'] ?? '')) !== 1) {
                $problems[] = 'source_commit 不是 40 位提交号';
            }
            $checks[] = $problems === []
                ? self::check('构建证据', true, 'commit ' . substr((string) $evidenceJson['source_commit'], 0, 12), self::evidence($evidencePath))
                : self::check('构建证据', false, implode('；', $problems), self::evidence($evidencePath));
        }

        // 增量包：以本版的 delta 清单为准。没有清单 = 本版不发增量，属正常。
        $manifestPath = $dir . '/' . str_replace('{version}', $version, (string) $cfg['delta_manifest']);
        $deltas = self::deltaManifest($manifestPath);
        if ($deltas === null) {
            $checks[] = self::check('增量包清单', true, '本版无 delta 清单，按不发增量处理');
        } else {
            $missing = [];
            $mismatch = [];
            foreach ($deltas as $delta) {
                $name = (string) ($delta['package'] ?? '');
                $path = $dir . '/' . $name;
                if ($name === '' || !is_file($path)) {
                    $missing[] = $name !== '' ? $name : '(无包名)';
                    continue;
                }
                $expected = preg_replace('/^sha256:/', '', (string) ($delta['hash'] ?? '')) ?? '';
                $actual = hash_file('sha256', $path);
                if ($expected !== '' && is_string($actual) && !hash_equals($expected, $actual)) {
                    $mismatch[] = $name;
                }
            }
            if ($missing === [] && $mismatch === []) {
                $checks[] = self::check('增量包归档', true, count($deltas) . ' 个 delta 齐全且哈希一致', self::evidence($manifestPath));
            } else {
                $detail = [];
                if ($missing !== []) {
                    $detail[] = '缺失: ' . implode(', ', $missing);
                }
                if ($mismatch !== []) {
                    $detail[] = '哈希不符: ' . implode(', ', $mismatch);
                }
                $checks[] = self::check('增量包归档', false, implode('；', $detail), self::evidence($manifestPath));
            }
        }

        return self::channel('本地归档', self::statusOf($checks), $checks, ['expected_version' => $version]);
    }

    // ── 升级服务器：复用既有 ReleaseUploadGuard，不另造判定 ────────────

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function updateServer(array $config, string $version, string $workspace): array
    {
        $cfg = self::section($config, 'update_server');
        $updateRoot = self::resolveDir($cfg, $workspace);
        if ($updateRoot === null) {
            return self::channel('升级服务器', self::FAILED, [self::check(
                '升级服务器目录',
                false,
                '找不到升级服务器目录；请设置 ' . (string) ($cfg['dir_env'] ?? '')
            )]);
        }
        $archiveDir = self::resolveDir(self::section($config, 'archive'), $workspace);
        if ($archiveDir === null) {
            return self::channel('升级服务器', self::FAILED, [self::check('归档目录', false, '归档目录不可用，无法比对')]);
        }

        require_once __DIR__ . '/ReleaseUploadGuard.php';
        try {
            $plan = ReleaseUploadGuard::inspect($updateRoot, $archiveDir, $version);
        } catch (Throwable $e) {
            return self::channel('升级服务器', self::FAILED, [
                self::check('目录', true, $updateRoot),
                self::check('ReleaseUploadGuard', false, $e->getMessage()),
            ], ['expected_version' => $version]);
        }

        return self::channel('升级服务器', self::VERIFIED, [
            self::check('目录', true, $updateRoot),
            self::check(
                'ReleaseUploadGuard',
                true,
                'channel=' . (string) $plan['channel'] . '，待上传 ' . count($plan['packages']) . ' 个包',
                self::evidence($updateRoot . '/' . (string) $cfg['catalog'])
            ),
        ], ['expected_version' => $version]);
    }

    // ── 模板市场：按批准上架清单核验，不从源码目录推导 ────────────────

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function market(array $config, string $version, string $workspace, ?callable $fetcher): array
    {
        $cfg = self::section($config, 'market');
        $updateRoot = self::resolveDir(self::section($config, 'update_server'), $workspace);
        if ($updateRoot === null) {
            return self::channel('模板市场', self::FAILED, [self::check('升级服务器目录', false, '不可用，无法读取主题注册表')]);
        }
        $registryPath = $updateRoot . '/' . (string) $cfg['registry'];
        $registry = self::readJson($registryPath);
        if ($registry === null || !is_array($registry['themes'] ?? null)) {
            return self::channel('模板市场', self::FAILED, [
                self::check('主题注册表', false, "缺失或结构非法: {$registryPath}", null, true),
            ]);
        }

        $listed = [];
        foreach ($registry['themes'] as $theme) {
            if (is_array($theme) && isset($theme['slug'])) {
                $listed[(string) $theme['slug']] = $theme;
            }
        }
        $checks = [self::check('主题注册表', true, basename($registryPath) . '：' . count($listed) . ' 个已上架', self::evidence($registryPath))];

        $approved = array_map('strval', (array) ($cfg['approved'] ?? []));
        $delisted = array_map('strval', (array) ($cfg['delisted'] ?? []));

        foreach ($approved as $slug) {
            $theme = $listed[$slug] ?? null;
            if (!is_array($theme)) {
                $checks[] = self::check("上架 {$slug}", false, '批准上架但注册表里没有');
                continue;
            }
            $problems = [];
            foreach (['version', 'package', 'hash', 'sig', 'requires_cms'] as $field) {
                if (trim((string) ($theme[$field] ?? '')) === '') {
                    $problems[] = "缺 {$field}";
                }
            }
            $packageName = (string) ($theme['package'] ?? '');
            $localPackage = $updateRoot . '/packages/themes/' . $packageName;
            $expected = preg_replace('/^sha256:/', '', (string) ($theme['hash'] ?? '')) ?? '';
            if ($packageName !== '' && is_file($localPackage)) {
                $actual = hash_file('sha256', $localPackage);
                if (is_string($actual) && $expected !== '' && !hash_equals($expected, $actual)) {
                    $problems[] = '包 SHA256 与注册表不符';
                }
            } elseif ($packageName !== '') {
                $problems[] = "本地包缺失: {$localPackage}";
            }
            $checks[] = $problems === []
                ? self::check("上架 {$slug}", true, 'v' . (string) $theme['version'] . '，' . $packageName . '，签名在场', self::evidence($localPackage))
                : self::check("上架 {$slug}", false, implode('；', $problems));
        }

        // 下架决定同样要守住：出现即失败，否则「下架」会被下一次打包悄悄撤销。
        foreach ($delisted as $slug) {
            $checks[] = isset($listed[$slug])
                ? self::check("下架 {$slug}", false, '已决定下架，但仍出现在注册表里', null, true)
                : self::check("下架 {$slug}", true, '确认不在注册表');
        }

        $unexpected = array_diff(array_keys($listed), $approved);
        $checks[] = $unexpected === []
            ? self::check('无计划外上架', true, '注册表与批准清单一致')
            : self::check('无计划外上架', false, '注册表出现未批准的主题: ' . implode(', ', $unexpected), null, true);

        // 线上包可达性：没有 fetcher 就记未执行，不冒充通过。
        if ($fetcher === null) {
            $checks[] = self::check('线上主题包', null, '未提供 fetcher，本项未执行');
        } else {
            foreach ($approved as $slug) {
                $theme = $listed[$slug] ?? null;
                if (!is_array($theme)) {
                    continue;
                }
                $url = str_replace('{package}', (string) ($theme['package'] ?? ''), (string) $cfg['package_url']);
                $checks[] = self::remoteCheck("线上主题包 {$slug}", $url, $fetcher, 'application/zip');
            }
        }

        return self::channel('模板市场', self::statusOf($checks), $checks, ['approved' => $approved]);
    }

    // ── GitHub Release ────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function github(array $config, string $version, ?callable $fetcher): array
    {
        $cfg = self::section($config, 'github');
        $url = str_replace('{version}', $version, (string) ($cfg['release_url'] ?? ''));
        if ($fetcher === null) {
            return self::channel('GitHub Release', self::SKIPPED, [
                self::check('Release 页面', null, "未提供 fetcher，本项未执行：{$url}"),
            ], ['expected_version' => $version]);
        }
        $check = self::remoteCheck('Release 页面', $url, $fetcher, 'text/html');
        return self::channel('GitHub Release', self::statusOf([$check]), [$check], ['expected_version' => $version]);
    }

    // ── 通用 ──────────────────────────────────────────────────────────

    /**
     * @param callable(string):array{status:int,type:string,bytes:int,error:string} $fetcher
     * @return array<string,mixed>
     */
    private static function remoteCheck(string $name, string $url, callable $fetcher, string $expectType): array
    {
        // 主机与路径先过一遍：绝不把带凭据的请求发到非目标地址。
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https' || !is_string($host) || $host === '') {
            return self::check($name, false, "拒绝请求非 https 或主机不明的地址: {$url}");
        }

        $result = $fetcher($url);
        $status = (int) ($result['status'] ?? 0);
        $type = (string) ($result['type'] ?? '');
        $bytes = (int) ($result['bytes'] ?? 0);
        $error = trim((string) ($result['error'] ?? ''));

        if ($error !== '') {
            // 传输失败是本机/网络问题，必须和「线上资源坏了」区分开，
            // 否则下一个人会照着错误信息去查 CDN。也不许自动归因为 WAF。
            return self::check($name, false, "请求未完成（{$error}）：{$url}");
        }
        if ($status !== 200) {
            return self::check($name, false, "HTTP {$status}（403/超时/缓存旧版本都不算成功）：{$url}");
        }
        if ($expectType !== '' && !str_starts_with($type, $expectType)) {
            return self::check($name, false, "内容类型 {$type}，期望 {$expectType}：{$url}");
        }
        if ($bytes <= 0) {
            return self::check($name, false, "HTTP 200 但响应体为空：{$url}");
        }
        return self::check($name, true, "HTTP 200 {$type} {$bytes} bytes", $url);
    }

    /**
     * 最高版本用 version_compare 比，不能按字符串比——1.19.10 字符串上小于 1.19.8。
     *
     * @param list<string> $versions
     */
    private static function highestVersion(array $versions): ?string
    {
        $highest = null;
        foreach ($versions as $candidate) {
            if ($highest === null || version_compare($candidate, $highest, '>')) {
                $highest = $candidate;
            }
        }
        return $highest;
    }

    /** @return list<string> 文档顺序的版本号（不去重） */
    private static function versionTokens(string $html): array
    {
        if (preg_match_all(self::VERSION_TOKEN, $html, $matches) < 1) {
            return [];
        }
        return array_values(array_map('strval', $matches[1]));
    }

    /** @return array<string,string> */
    private static function pages(array $cfg, string $key): array
    {
        $pages = $cfg[$key] ?? null;
        if (!is_array($pages) || $pages === []) {
            throw new RuntimeException("release-channels.php 的 website.{$key} 未配置");
        }
        $result = [];
        foreach ($pages as $lang => $relative) {
            $result[(string) $lang] = (string) $relative;
        }
        return $result;
    }

    /** @param array<string,mixed> $config */
    private static function section(array $config, string $name): array
    {
        $section = $config[$name] ?? null;
        if (!is_array($section)) {
            throw new RuntimeException("release-channels.php 缺少配置段: {$name}");
        }
        return $section;
    }

    /** @param array<string,mixed> $cfg */
    private static function resolveDir(array $cfg, string $workspace): ?string
    {
        $env = (string) ($cfg['dir_env'] ?? '');
        $configured = $env !== '' ? getenv($env) : false;
        $candidate = is_string($configured) && trim($configured) !== ''
            ? $configured
            : $workspace . '/' . (string) ($cfg['dir_default'] ?? '');
        $real = realpath($candidate);
        return $real !== false && is_dir($real) ? self::normalize($real) : null;
    }

    private static function readFile(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        return is_string($raw) ? $raw : null;
    }

    /** @return array<string,mixed>|null */
    private static function readJson(string $path): ?array
    {
        $raw = self::readFile($path);
        if ($raw === null) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<string,mixed>>|null */
    private static function deltaManifest(string $path): ?array
    {
        $raw = self::readFile($path);
        if ($raw === null) {
            return null;
        }
        // 清单文件是「裸的键值片段」，与 ReleaseUploadGuard 的读法保持一致。
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        try {
            $decoded = json_decode('{' . trim($raw) . '}', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }
        $deltas = is_array($decoded) && is_array($decoded['deltas'] ?? null) ? $decoded['deltas'] : [];
        $result = [];
        foreach ($deltas as $delta) {
            if (is_array($delta)) {
                $result[] = $delta;
            }
        }
        return $result;
    }

    private static function hashFromChecksumFile(string $path): ?string
    {
        $raw = self::readFile($path);
        if ($raw === null) {
            return null;
        }
        return preg_match('/\b([a-f0-9]{64})\b/i', $raw, $m) === 1 ? strtolower($m[1]) : null;
    }

    /** @return array<string,mixed> */
    private static function evidence(string $path): array
    {
        if (!is_file($path)) {
            return ['path' => self::normalize($path)];
        }
        $mtime = filemtime($path);
        return [
            'path' => self::normalize($path),
            // 官网是活动目录：不记 mtime，报告描述的就是一个移动目标。
            'mtime' => is_int($mtime) ? gmdate('c', $mtime) : null,
            'bytes' => filesize($path) ?: 0,
        ];
    }

    /**
     * @param array<string,mixed>|string|null $evidence
     * @param bool $invariant 是否为「任何阶段都必须成立」的不变量。
     *   候选阶段可以豁免「线上还没同步」，但不能豁免不变量——例如已下架的主题
     *   重新出现在注册表里，那在任何阶段都是错的，豁免它等于把决定悄悄撤销。
     */
    private static function check(
        string $name,
        ?bool $ok,
        string $detail,
        array|string|null $evidence = null,
        bool $invariant = false
    ): array {
        return [
            'name' => $name,
            // null = 未执行；true = 通过；false = 失败。三态不折叠成布尔。
            'ok' => $ok,
            'detail' => $detail,
            'evidence' => $evidence,
            'invariant' => $invariant,
        ];
    }

    /**
     * 候选阶段只豁免「随发布进度变化」的失败项；有不变量失败就不许降级。
     *
     * @param array<string,mixed> $channel
     */
    private static function hasInvariantFailure(array $channel): bool
    {
        foreach ($channel['checks'] as $check) {
            if ($check['ok'] === false && ($check['invariant'] ?? false) === true) {
                return true;
            }
        }
        return false;
    }

    /** @param list<array<string,mixed>> $checks */
    private static function statusOf(array $checks): string
    {
        $sawSkipped = false;
        foreach ($checks as $check) {
            if ($check['ok'] === false) {
                return self::FAILED;
            }
            if ($check['ok'] === null) {
                $sawSkipped = true;
            }
        }
        return $sawSkipped ? self::SKIPPED : self::VERIFIED;
    }

    /**
     * @param list<array<string,mixed>> $checks
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private static function channel(string $label, string $status, array $checks, array $meta = []): array
    {
        return ['label' => $label, 'status' => $status, 'checks' => $checks] + $meta;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function assertVersion(string $version): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/D', $version) !== 1) {
            throw new InvalidArgumentException("Invalid release version: {$version}");
        }
    }
}
