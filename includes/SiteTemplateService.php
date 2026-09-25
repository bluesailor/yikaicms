<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTemplateArchive.php';
require_once __DIR__ . '/SiteTemplateLanguages.php';
require_once __DIR__ . '/ThemeInstaller.php';
require_once __DIR__ . '/UploadReferences.php';
require_once __DIR__ . '/PluginMarketInstall.php';
require_once __DIR__ . '/SiteTemplateMarket.php';

/**
 * Local, explicit site transfer. Never restores accounts or server configuration.
 *
 * 默认只给全新安装的站导入。已有内容的站需管理员在预览前显式确认「覆盖现有网站」
 * （界面要求先备份数据库）：内容表整体替换，账号、会员、表单留言保留；
 * 导入前快照照常写入日志，可「恢复导入前」。
 */
final class SiteTemplateService
{
    private string $root;
    private string $store;
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";
    private const PRIVATE_TABLES = ['forms', 'members', 'mail_log', 'content_revisions', 'blox_page_drafts'];
    /**
     * 覆盖现有站时一并清空：它们按 ID 指向被替换的内容表，而导入行保留包内原 ID——
     * 不清就会把旧草稿、旧历史版本挂到毫不相干的新页面上。全新站里这些表本来就是空的。
     */
    private const REPLACE_CLEARS = ['blox_page_drafts', 'content_revisions', 'blox_import_reviews',
        'blox_remote_template_states', 'media_remote_imports'];

    private ?PluginMarketInstall $pluginMarket;

    /** @param null|PluginMarketInstall $pluginMarket 测试注入；默认按需创建官方市场安装器 */
    public function __construct(string $root, ?PluginMarketInstall $pluginMarket = null)
    {
        $this->pluginMarket = $pluginMarket;
        $resolved = realpath($root);
        if ($resolved === false) throw new RuntimeException('st_storage');
        $this->root = str_replace('\\', '/', $resolved);
        $this->store = $this->root . '/storage/site-templates';
    }

    /** Only the installer calls this, before creating installed.lock. No administrator reset endpoint. */
    public function markFreshInstall(): void
    {
        if (file_exists($this->root . '/installed.lock') || $this->readRecord('baseline') !== null) throw new RuntimeException('st_not_fresh');
        $this->writeRecord('baseline', ['fingerprint' => SiteTemplateData::fingerprint(), 'created_at' => time()]);
    }

    public function canApply(): bool
    {
        $baseline = $this->readRecord('baseline');
        return $baseline !== null && hash_equals((string) $baseline['fingerprint'], SiteTemplateData::fingerprint());
    }

    /**
     * 预览后的计划是否仍可执行：新站，或预览时已确认覆盖；两种情况都要求数据自预览起没变。
     *
     * @param array<string,mixed> $plan
     */
    private function planStillApplies(array $plan): bool
    {
        return (!empty($plan['replace_existing']) || $this->canApply())
            && hash_equals((string) ($plan['fingerprint'] ?? ''), SiteTemplateData::fingerprint());
    }

    public function recovery(): ?array
    {
        $record = $this->readRecord('current');
        if ($record === null) return null;
        $pluginsMatch = $this->pluginSnapshotMatches(
            is_array($record['plugin_after'] ?? null) ? $record['plugin_after'] : [],
            is_array($record['plugin_requirements'] ?? null) ? $record['plugin_requirements'] : []
        );
        return ['status' => (string) $record['status'], 'created_at' => (int) $record['created_at'],
            'can_restore' => isset($record['after']) && hash_equals((string) $record['after'], SiteTemplateData::fingerprint())
                && $pluginsMatch && !in_array($record['status'], ['restored', 'aborted'], true)];
    }

    private function supported(): void
    {
        if ((function_exists('configOverrides') && !$this->overridesMatchPortableSettings(configOverrides()))
            || !empty($GLOBALS['yikai_config_runtime_overrides'])) throw new RuntimeException('st_overrides');
        $overrides = $this->root . '/overrides';
        if (is_dir($overrides)) {
            $this->assertContained($overrides);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($overrides, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) if ($file->isFile() && !in_array($file->getFilename(), ['README.md', '.gitkeep', '.htaccess'], true)) throw new RuntimeException('st_overrides');
        }
    }

    /** Read-only export readiness and known-channel link checks. */
    public function exportCheck(): array
    {
        require_once __DIR__ . '/SiteExportChecks.php';
        try {
            $this->supported();
            $data = SiteTemplateData::snapshot(true);
            SiteTemplateData::validate($data);
            $report = SiteExportChecks::inspect($data, channelModel()->all(), (string) config('site_url', ''));
            $plugins = $this->activePlugins();
            $portable = SiteTemplatePluginData::snapshot($plugins);
            $excluded = array_values(array_filter($plugins, static fn(array $plugin): bool => !isset($portable[$plugin['slug']])));
            if ($excluded !== []) {
                $list = implode(', ', array_map(static fn(array $plugin): string => $plugin['slug'] . ' ' . $plugin['version'], $excluded));
                $report['issues'][] = ['code' => 'usability_export_plugins_excluded', 'label' => $list,
                    'detail' => $list, 'url' => '/admin/plugin.php'];
            }
            return ['blocked' => '', 'issues' => $report['issues'], 'scanned' => $report['scanned'], 'limited' => $report['limited']];
        } catch (RuntimeException $error) {
            $code = $error->getMessage();
            return ['blocked' => preg_match('/^st_[a-z_]+$/D', $code) ? $code : 'st_invalid', 'issues' => [], 'scanned' => 0, 'limited' => false];
        }
    }

    /** Writes to a caller-owned temporary file. Only referenced public uploads are bundled. */
    public function export(string $destination): array
    {
        $this->supported();
        $before = SiteTemplateData::fingerprint();
        $theme = (string) config('current_theme', 'default');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $theme)) throw new RuntimeException('st_theme');
        $themeRoot = $this->root . '/themes/' . $theme;
        $this->assertContained($themeRoot);
        if (!is_dir($themeRoot)) throw new RuntimeException('st_theme');
        $files = [];
        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($themeRoot) + 1);
            if (preg_match('~(?:^|/)\.~', $relative)) continue;
            $this->assertContained($path);
            if (!$file->isFile()) throw new RuntimeException('st_unsafe');
            if (!SiteTemplateArchive::themeFileAllowed($relative)) continue;
            $files['theme/' . $relative] = $this->boundedRead($path, $size);
        }
        $data = SiteTemplateData::snapshot(true);
        $plugins = $this->activePlugins();
        $pluginDataBefore = SiteTemplatePluginData::snapshot($plugins);
        $pluginData = $pluginDataBefore;
        // Normalize the author's own origin, not third-party links.
        $origin = rtrim((string) config('site_url', ''), '/');
        if ($origin !== '') {
            $data = UploadReferences::localize($data, $origin);
            $pluginData = UploadReferences::localize($pluginData, $origin);
            foreach ($files as $path => $bytes) if ($this->textFile($path)) $files[$path] = UploadReferences::localize($bytes, $origin);
        }
        $media = $data['tables']['media'];
        $data['tables']['media'] = [];
        $refs = UploadReferences::collect([$data, $pluginData]);
        foreach ($files as $path => $bytes) if ($this->textFile($path)) {
            foreach (UploadReferences::collect($bytes) as $relative => $count) {
                $refs[$relative] = ($refs[$relative] ?? 0) + $count;
            }
        }
        foreach (array_keys($refs) as $relative) {
            if (!SiteTemplateArchive::safePath($relative)) throw new RuntimeException('st_unsafe');
            $source = $this->root . '/uploads/' . $relative;
            $this->assertContained($source);
            if (!is_file($source)) throw new RuntimeException('st_missing_media');
            $files['media/' . $relative] = $this->boundedRead($source, $size);
        }
        foreach ($media as $row) {
            $rowRefs = UploadReferences::collect([$row['url'] ?? '', $row['path'] ?? '']);
            $publicRefs = array_intersect_key($refs, $rowRefs);
            if ($publicRefs) {
                $relative = (string) array_key_first($publicRefs);
                $row['path'] = 'uploads/' . $relative;
                $row['url'] = '/uploads/' . $relative;
                $data['tables']['media'][] = $row;
            }
        }
        SiteTemplateData::validate($data);
        $pluginDataAfter = SiteTemplatePluginData::snapshot($plugins);
        if (!hash_equals($before, SiteTemplateData::fingerprint())
            || !hash_equals(SiteTemplatePluginData::portableFingerprint($pluginDataBefore), SiteTemplatePluginData::portableFingerprint($pluginDataAfter))) {
            throw new RuntimeException('st_stale');
        }
        $pluginPackage = SiteTemplatePluginData::package($pluginData);
        $files += $pluginPackage['files'];
        $manifest = ['format' => 'yikaicms-site-template', 'version' => $pluginData === [] ? 1 : 2, 'cms' => CMS_VERSION,
            'plugins' => $plugins, 'plugin_data' => $pluginPackage['manifest'],
            'schema' => SiteTemplateData::schema(), 'theme' => $theme, 'created_at' => gmdate('c'), 'data' => $data];
        SiteTemplateArchive::write($destination, $manifest, $files);
        SiteTemplateArchive::read($destination);
        return $this->summary($manifest, $files);
    }

    /**
     * @param bool $replaceExisting 已有内容的站：管理员已确认备份并同意覆盖
     * @param array<string,mixed> $origin 来源说明。只有模板市场在官方下载并验签、验包之后才会传 official=true，
     *                                    导入时据此免去「我信任该开发者」确认；name / screenshot 仅用于预览展示。
     */
    public function prepare(string $archive, int $adminId, bool $replaceExisting = false, array $origin = []): array
    {
        $origin = self::origin($origin);
        $this->supported();
        $fresh = $this->canApply();
        if (!$fresh && !$replaceExisting) throw new RuntimeException('st_not_fresh');
        // inspect 只做条目校验与 manifest 解析，不把内容读进内存——32MB 包在这里的
        // 峰值是单个小文件，而不是「整包解压 + base64 副本 + JSON 副本」（旧实现在
        // memory_limit=128M 的主机上会在 apply 中途 fatal）。
        $package = SiteTemplateArchive::inspect($archive);
        $missingPlugins = $this->missingPlugins($package['manifest']['plugins']);
        $pluginRequirements = $this->pluginRequirements($package['manifest']['plugins'], $package['plugin_data']);
        $matchedRequirements = $this->withoutMissingRequirements($pluginRequirements, $missingPlugins);
        $pluginBefore = SiteTemplatePluginData::targetSnapshot($package['plugin_data'], $matchedRequirements);
        $token = bin2hex(random_bytes(16));
        $this->ensureDirectory($this->store);
        $stored = $this->store . '/package.zip';
        $this->assertContained($stored);
        // 原样保存上传包：后续分阶段提取直接按名字从它取条目，不再反复整包解压
        if (!@copy($archive, $stored)) throw new RuntimeException('st_storage');
        $media = array_values(array_filter($package['names'], static fn(string $n): bool => str_starts_with($n, 'media/')));
        // A single pending preview bounds disk use; another preview explicitly invalidates the old token.
        $this->writeRecord('plan', ['token' => $token, 'owner' => $adminId, 'expires' => time() + 600,
            'fingerprint' => SiteTemplateData::fingerprint(), 'hash' => $package['hash'],
            'alias' => 'sitepack-' . bin2hex(random_bytes(8)), 'media' => $media, 'staged' => 0,
            'replace_existing' => !$fresh, 'origin' => $origin,
            'missing_plugins' => $missingPlugins, 'plugin_requirements' => $matchedRequirements,
            'plugin_before_hash' => SiteTemplatePluginData::fingerprint($pluginBefore)]);
        return ['token' => $token, 'summary' => $this->summary($package['manifest'], array_fill_keys($package['names'], '')),
            'missing_plugins' => $missingPlugins, 'plugin_actions' => $this->pluginActions($missingPlugins),
            'theme_plugins' => SiteTemplateArchive::themeRequiredPlugins(
                json_decode(SiteTemplateArchive::entry($stored, $package['manifest'], 'theme/theme.json'), true) ?: []
            ),
            'replace_existing' => !$fresh, 'origin' => $origin, 'brand' => self::templateBrand($package['manifest'])];
    }

    /** @return array{official:bool,name:string,screenshot:string,version:string,demo_url:string} */
    private static function origin(array $origin): array
    {
        $text = static fn(mixed $value, int $max): string => is_string($value) ? mb_substr(trim($value), 0, $max) : '';
        $screenshot = $text($origin['screenshot'] ?? '', 500);
        return [
            'official' => ($origin['official'] ?? false) === true,
            'name' => $text($origin['name'] ?? '', 120),
            'screenshot' => str_starts_with($screenshot, 'https://') || str_starts_with($screenshot, 'data:image/') ? $screenshot : '',
            'version' => $text($origin['version'] ?? '', 40),
            'demo_url' => SiteTemplateMarket::demoUrl($origin['demo_url'] ?? ''),
        ];
    }

    /**
     * 模板自带的名称与联系方式：导入表单用它们预填，默认沿用模板的样子，
     * 管理员可以当场改成自己的，也可以导入后再改——不再用安装器默认名覆盖、把联系方式清空。
     * @return array{site_name:string,contact_phone:string,contact_email:string,contact_address:string}
     */
    private static function templateBrand(array $manifest): array
    {
        $settings = is_array($manifest['data']['settings'] ?? null) ? $manifest['data']['settings'] : [];
        $brand = [];
        foreach (['site_name', 'contact_phone', 'contact_email', 'contact_address'] as $key) {
            $value = $settings[$key] ?? '';
            $brand[$key] = is_string($value) ? mb_substr(trim($value), 0, 500) : '';
        }
        if ($brand['contact_email'] !== '' && filter_var($brand['contact_email'], FILTER_VALIDATE_EMAIL) === false) $brand['contact_email'] = '';
        return $brand;
    }

    /**
     * 缺失插件各自怎么补：本站已有且版本够 → 启用；官方插件市场有且可下载 → 安装后启用；都不行 → 需要手动上传。
     * 市场不可达时不报错，只把需要下载的标成手动，预览照常可用。
     *
     * @param list<array{slug:string,version:string}> $missing
     * @return list<array{slug:string,version:string,action:string,available:string}>
     */
    public function pluginActions(array $missing): array
    {
        $catalog = null;
        $actions = [];
        foreach ($missing as $plugin) {
            $local = PluginMarketPackage::installedVersion($this->root . '/plugins', $plugin['slug']);
            if ($local !== '' && version_compare($local, $plugin['version'], '>=')) {
                $actions[] = $plugin + ['action' => 'enable', 'available' => $local];
                continue;
            }
            $catalog ??= $this->pluginMarket()->catalog() ?? [];
            $item = PluginMarketInstall::find($catalog, $plugin['slug']);
            $available = is_array($item) ? (string) ($item['version'] ?? '') : '';
            $usable = is_array($item) && MarketDownloadStatus::reason($item) === '' && (string) ($item['download_url'] ?? '') !== ''
                && $available !== '' && version_compare($available, $plugin['version'], '>=');
            $actions[] = $plugin + ['action' => $usable ? 'market' : 'manual', 'available' => $available];
        }
        return $actions;
    }

    /**
     * 「一并安装所需插件」：按预览中的清单逐个启用，或从官方插件市场安装（与插件页同一条校验链）后启用。
     * 只在本次预览有效期内、由发起预览的管理员执行；装不上的保留原因，照旧可按「跳过缺失插件数据」继续。
     * 之后必须在**下一个请求**里调用 refreshPreview()：新启用插件的整站数据适配器要随插件加载才注册。
     *
     * @param null|list<string> $only 只处理这些插件（导入页勾选的）；null 表示全部
     * @return list<array{slug:string,ok:bool,msg:string}>
     */
    public function installRequiredPlugins(string $token, int $adminId, ?array $only = null): array
    {
        $this->supported();
        [, $archive] = $this->pendingPlan($token, $adminId);
        $missing = $this->missingPlugins(SiteTemplateArchive::inspect($archive)['manifest']['plugins']);

        $results = [];
        $catalog = null;
        foreach ($this->pluginActions($missing) as $plugin) {
            $slug = $plugin['slug'];
            if ($only !== null && !in_array($slug, $only, true)) continue;
            if ($plugin['action'] === 'manual') {
                $results[] = ['slug' => $slug, 'ok' => false, 'msg' => __('st_plugin_action_manual')];
                continue;
            }
            if ($plugin['action'] === 'market') {
                $catalog ??= $this->pluginMarket()->catalog() ?? [];
                $installed = $this->pluginMarket()->install($slug, $catalog);
                if (!$installed['ok']) {
                    $results[] = ['slug' => $slug, 'ok' => false, 'msg' => $installed['msg']];
                    continue;
                }
            }
            $version = PluginMarketPackage::installedVersion($this->root . '/plugins', $slug);
            if ($version === '' || version_compare($version, $plugin['version'], '<')) {
                $results[] = ['slug' => $slug, 'ok' => false, 'msg' => __('st_plugin_action_manual')];
                continue;
            }
            pluginModel()->activate($slug);
            $results[] = ['slug' => $slug, 'ok' => true, 'msg' => $slug . ' ' . $version];
        }

        return $results;
    }

    /**
     * 用同一份包重新生成预览：缺失清单与插件数据的前置状态随之更新，导入时就会带上新启用插件的数据。
     * @return array<string,mixed>
     */
    public function refreshPreview(string $token, int $adminId): array
    {
        $this->supported();
        [$plan, $archive] = $this->pendingPlan($token, $adminId);
        // prepare() 会把传入的包存回 package.zip：先拷出一份再交给它
        $copy = tempnam(sys_get_temp_dir(), 'yk-st-refresh-');
        if ($copy === false || !@copy($archive, $copy)) throw new RuntimeException('st_storage');
        try {
            return $this->prepare($copy, $adminId, !empty($plan['replace_existing']), is_array($plan['origin'] ?? null) ? $plan['origin'] : []);
        } finally {
            @unlink($copy);
        }
    }

    /** @return array{0:array<string,mixed>,1:string} 本人、未过期、包未被替换的预览计划与包路径 */
    private function pendingPlan(string $token, int $adminId): array
    {
        $plan = $this->readRecord('plan');
        if ($plan === null || !hash_equals((string) $plan['token'], $token) || $plan['owner'] !== $adminId || $plan['expires'] < time()) throw new RuntimeException('st_stale');
        $archive = $this->store . '/package.zip';
        $this->assertContained($archive);
        if (!is_file($archive) || !hash_equals((string) $plan['hash'], (string) hash_file('sha256', $archive))) throw new RuntimeException('st_stale');
        return [$plan, $archive];
    }

    private function pluginMarket(): PluginMarketInstall
    {
        return $this->pluginMarket ??= new PluginMarketInstall($this->root);
    }

    /** 单次 stage 请求的预算：够小才能在共享主机的执行时限内完成，够大才不至于来回太多趟。 */
    public const STAGE_MAX_FILES = 40;
    public const STAGE_MAX_SECONDS = 8;

    /**
     * 分阶段提取（E03）：把媒体按预算逐批写进 uploads/<别名>/，可反复调用直到完成。
     *
     * 为什么只分批媒体：文件 IO 是大包的真实瓶颈，而数据库替换必须在单个事务内完成
     * （计划明确「不跨请求保持事务」），且新装站的数据量很小。主题文件同样留给 commit，
     * 因为它要整体交给 ThemeInstaller 走既有校验链，拆开反而绕过安全链。
     *
     * 幂等：已落盘且摘要正确的条目直接跳过，所以超时重试不会重复写入，也不会跳过没写完的。
     *
     * @return array{done:int,total:int,complete:bool}
     */
    public function stage(string $token, int $adminId): array
    {
        $this->supported();
        $plan = $this->readRecord('plan');
        if ($plan === null || !hash_equals((string) $plan['token'], $token) || $plan['owner'] !== $adminId || $plan['expires'] < time()) throw new RuntimeException('st_stale');
        if (!$this->planStillApplies($plan)) throw new RuntimeException('st_not_fresh');

        $archive = $this->store . '/package.zip';
        $this->assertContained($archive);
        if (!is_file($archive) || !hash_equals((string) $plan['hash'], (string) hash_file('sha256', $archive))) throw new RuntimeException('st_stale');

        /** @var list<string> $media */
        $media = is_array($plan['media'] ?? null) ? $plan['media'] : [];
        $alias = (string) $plan['alias'];
        $total = count($media);
        $done = max(0, min($total, (int) ($plan['staged'] ?? 0)));
        if ($done >= $total) return ['done' => $total, 'total' => $total, 'complete' => true];

        $manifest = SiteTemplateArchive::inspect($archive)['manifest'];
        $deadline = microtime(true) + self::STAGE_MAX_SECONDS;
        $processed = 0;
        while ($done < $total && $processed < $this->stageMaxFiles() && microtime(true) < $deadline) {
            $name = $media[$done];
            $target = $this->root . '/uploads/' . $alias . '/' . substr($name, 6);
            $this->ensureDirectory(dirname($target));
            $this->assertContained($target);
            $expected = (string) ($manifest['files'][$name] ?? '');
            if (is_file($target)) {
                // 上个进程可能完成了原子改名，却在游标落盘前中断；摘要一致就只推进游标，
                // 重试不得再次改写已经完成的文件。
                if ($expected === '' || !hash_equals($expected, (string) hash_file('sha256', $target))) throw new RuntimeException('st_storage');
                $done++;
                $processed++;
                $plan['staged'] = $done;
                $this->writeRecord('plan', $plan);
                continue;
            }
            // 内容校验在 entry() 里逐条完成（sha256 + SVG 消毒），与一次性读取同口径
            $bytes = SiteTemplateArchive::entry($archive, $manifest, $name);
            $temporary = $target . '.part';
            $this->assertContained($temporary);
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('st_storage');
            }
            $done++;
            $processed++;
            // 游标每条推进后落盘：中断后从断点继续，而不是从头再来
            $plan['staged'] = $done;
            $this->writeRecord('plan', $plan);
        }
        return ['done' => $done, 'total' => $total, 'complete' => $done >= $total];
    }

    /**
     * @param bool $trusted 管理员声明信任该模板来源（主题含可执行 PHP）
     * @param null|bool $confirmed 管理员确认替换内容；传入时单独判断：官方模板市场验签过的包可以代替「信任」，但永远代替不了「确认」
     */
    public function apply(string $token, int $adminId, array $brand, bool $trusted, ?bool $confirmed = null): void
    {
        $this->withLock(function () use ($token, $adminId, $brand, $trusted, $confirmed): void {
            $alias = '';
            $committed = false;
            try {
                $plan = $this->readRecord('plan');
                if ($plan === null || !hash_equals((string) $plan['token'], $token) || $plan['owner'] !== $adminId || $plan['expires'] < time()) throw new RuntimeException('st_stale');
                // 别名在 prepare 阶段就定下：分阶段提取要往固定目录落盘，重复请求也不会换目录
                $alias = (string) $plan['alias'];
                $this->supported();
                if (!$this->planStillApplies($plan)) throw new RuntimeException('st_not_fresh');
                $archive = $this->store . '/package.zip';
                $this->assertContained($archive);
                if (!is_file($archive) || !hash_equals((string) $plan['hash'], (string) hash_file('sha256', $archive))) throw new RuntimeException('st_stale');
                $package = SiteTemplateArchive::read($archive);
                $missingPlugins = $this->missingPlugins($package['manifest']['plugins']);
                // 主题离不开的插件还缺着：即使管理员选择「跳过缺失插件数据」也启用不了主题，先明确拦下
                $themeMeta = json_decode((string) ($package['files']['theme/theme.json'] ?? ''), true);
                $themeNeeds = SiteTemplateArchive::themeRequiredPlugins(is_array($themeMeta) ? $themeMeta : []);
                if (array_intersect($themeNeeds, array_column($missingPlugins, 'slug')) !== []) throw new RuntimeException('st_plugin_theme_required');
                $previewedMissing = is_array($plan['missing_plugins'] ?? null) ? $plan['missing_plugins'] : [];
                if ($this->pluginListFingerprint($missingPlugins) !== $this->pluginListFingerprint($previewedMissing)) {
                    // Any dependency change after preview changes what will be skipped/imported.
                    throw new RuntimeException('st_stale');
                }
                // 官方模板市场下载的包已在服务端验签、验包：不再要求管理员声明「信任该开发者」
                if ($confirmed !== null) $trusted = $confirmed && ($trusted || !empty($plan['origin']['official']));
                if (!$trusted) throw new RuntimeException($missingPlugins === [] ? 'st_trust' : 'st_plugin_missing');
                $pluginRequirements = $this->pluginRequirements($package['manifest']['plugins'], $package['plugin_data']);
                $matchedRequirements = $this->withoutMissingRequirements($pluginRequirements, $missingPlugins);
                $missingSlugs = array_values(array_map(static fn(array $plugin): string => $plugin['slug'], $missingPlugins));
                $pluginData = SiteTemplatePluginData::withoutMissing($package['plugin_data'], $missingSlugs);
                $pluginBefore = SiteTemplatePluginData::targetSnapshot($pluginData, $matchedRequirements);
                if (!hash_equals((string) ($plan['plugin_before_hash'] ?? ''), SiteTemplatePluginData::fingerprint($pluginBefore))) {
                    throw new RuntimeException('st_stale');
                }

                $map = ['/themes/' . $package['manifest']['theme'] . '/' => '/themes/' . $alias . '/', '/uploads/' => '/uploads/' . $alias . '/'];
                $data = SiteTemplateArchive::rewrite($package['manifest']['data'], $map);
                $pluginData = SiteTemplatePluginData::rewrite($pluginData, $map);
                foreach ($data['tables']['media'] as &$row) {
                    if (isset($row['path']) && str_starts_with($row['path'], 'uploads/')) $row['path'] = $this->root . '/uploads/' . $alias . '/' . substr($row['path'], 8);
                }
                unset($row);
                $oldTheme = $package['manifest']['theme'];
                $styles = json_decode($data['settings']['theme_style_settings'] ?? '', true);
                if (is_array($styles['themes'][$oldTheme] ?? null)) {
                    $styles['themes'] = [$alias => $styles['themes'][$oldTheme]];
                    $data['settings']['theme_style_settings'] = json_encode($styles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
                if (isset($data['settings']['theme_content_' . $oldTheme])) {
                    $data['settings']['theme_content_' . $alias] = $data['settings']['theme_content_' . $oldTheme];
                    unset($data['settings']['theme_content_' . $oldTheme]);
                }
                $data['settings']['current_theme'] = $alias;
                foreach (['site_name', 'contact_phone', 'contact_email', 'contact_address'] as $key) {
                    $value = trim((string) ($brand[$key] ?? ''));
                    if (strlen($value) > 500 || ($key === 'site_name' && $value === '')) throw new RuntimeException('st_brand');
                    $data['settings'][$key] = $value;
                    foreach (['zh-CN', 'en', 'ja'] as $language) {
                        if (array_key_exists($key . '_' . $language, $data['settings'])) $data['settings'][$key . '_' . $language] = $value;
                    }
                }
                if ($data['settings']['contact_email'] !== '' && filter_var($data['settings']['contact_email'], FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('st_brand');

                $this->beginLockedTransaction();
                if (!$this->planStillApplies($plan)) throw new RuntimeException('st_stale');
                $pluginBefore = SiteTemplatePluginData::targetSnapshot($pluginData, $matchedRequirements);
                if (!hash_equals((string) ($plan['plugin_before_hash'] ?? ''), SiteTemplatePluginData::fingerprint($pluginBefore))) throw new RuntimeException('st_stale');
                $journal = ['status' => 'preparing', 'created_at' => time(), 'before' => SiteTemplateData::fingerprint(),
                    'snapshot' => SiteTemplateData::snapshot(), 'plugin_snapshot' => $pluginBefore,
                    'plugin_requirements' => $matchedRequirements, 'alias' => $alias];
                $this->writeRecord('current', $journal);
                $this->installFiles($package['files'], $alias, $map);
                if (!empty($plan['replace_existing'])) {
                    // 清空前整表记入日志：「撤销导入并恢复」要连它们一起还原，
                    // 否则草稿 / 历史版本参与的数据指纹对不上，恢复会被拒绝。
                    $journal['cleared'] = [];
                    foreach (self::REPLACE_CLEARS as $table) {
                        // 不排序：并非每张表都有 id 列（如 blox_remote_template_states）
                        $journal['cleared'][$table] = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table);
                    }
                }
                SiteTemplateData::replace($data);
                if (!empty($plan['replace_existing'])) {
                    foreach (self::REPLACE_CLEARS as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
                }
                $journal['plugin_after'] = SiteTemplatePluginData::apply($pluginData, $matchedRequirements);
                $journal['after'] = SiteTemplateData::fingerprint();
                $journal['status'] = 'prepared_commit';
                $this->writeRecord('current', $journal);
                db()->commit();
                $committed = true;
                $journal['status'] = 'committed';
                $this->writeRecord('current', $journal);
                $this->writeRecord('plan', ['token' => '', 'owner' => 0, 'expires' => 0]);
                $this->forgetDerivedStyles();
            } catch (Throwable $e) {
                if (!$committed && db()->getPdo()->inTransaction()) db()->rollback();
                settingModel()->clearCache();
                // 事务已回滚 ⇒ 没有任何记录引用本次别名（别名是本次新生成的随机值，
                // installFiles 又拒绝写入已存在的目录），因此清理是安全的。
                // restore() 里"绝不删除"的理由是那些文件可能已被编辑器引用——失败路径不适用。
                if (!$committed && $alias !== '') $this->discardImportedFiles($alias);
                throw $e;
            }
        });
    }

    public function restore(): void
    {
        $this->withLock(function (): void {
            $this->supported();
            $journal = $this->readRecord('current');
            if ($journal === null || !isset($journal['after']) || in_array($journal['status'], ['restored', 'aborted'], true)) throw new RuntimeException('st_no_restore');
            $this->beginLockedTransaction();
            try {
                if (!hash_equals((string) $journal['after'], SiteTemplateData::fingerprint())) throw new RuntimeException('st_restore_changed');
                $pluginRequirements = is_array($journal['plugin_requirements'] ?? null) ? $journal['plugin_requirements'] : [];
                $pluginAfter = is_array($journal['plugin_after'] ?? null) ? $journal['plugin_after'] : [];
                if (!$this->pluginSnapshotMatches($pluginAfter, $pluginRequirements)) throw new RuntimeException('st_restore_changed');
                // A local backup must reproduce the original state, including pre-existing dangling seed references.
                // Uploaded packages still undergo full reference validation in Archive::read and replace().
                SiteTemplateData::replace($journal['snapshot'], false);
                // 覆盖现有站时被清空的草稿 / 历史版本等（见 REPLACE_CLEARS），原样放回
                foreach ((is_array($journal['cleared'] ?? null) ? $journal['cleared'] : []) as $table => $rows) {
                    if (!in_array($table, self::REPLACE_CLEARS, true) || !is_array($rows)) continue;
                    db()->execute('DELETE FROM ' . DB_PREFIX . $table);
                    foreach ($rows as $row) if (is_array($row)) db()->insert($table, $row);
                }
                $pluginSnapshot =is_array($journal['plugin_snapshot'] ?? null) ? $journal['plugin_snapshot'] : [];
                $portableBackup = [];
                foreach ($pluginSnapshot as $slug => $entry) $portableBackup[$slug] = [
                    'contract' => $entry['contract'], 'schema' => $entry['schema'], 'payload' => $entry['payload'],
                ];
                SiteTemplatePluginData::apply($portableBackup, $pluginRequirements);
                if (!hash_equals((string) $journal['before'], SiteTemplateData::fingerprint())) throw new RuntimeException('st_restore_changed');
                if (!$this->pluginSnapshotMatches($pluginSnapshot, $pluginRequirements)) throw new RuntimeException('st_restore_changed');
                db()->commit();
            } catch (Throwable $e) {
                if (db()->getPdo()->inTransaction()) db()->rollback();
                settingModel()->clearCache();
                throw $e;
            }
            $journal['status'] = 'restored';
            $this->writeRecord('current', $journal);
            $this->forgetDerivedStyles();
            // Imported files remain inactive. Never delete a file another editor may have started using.
        });
    }

    /**
     * 导入/恢复整体替换了全局类表：已生成的 uploads/blox/css/classes.css 仍是旧数据，
     * 且版本戳只看 mtime，前台会一直引用旧类样式。提交后删掉它，下次访问按新表重建。
     * 放在事务提交之后：失败回滚时旧文件仍与旧数据一致，不该动。
     */
    private function forgetDerivedStyles(): void
    {
        try {
            // 只载类定义（无顶层副作用）：整站导入可能跑在未加载构建器的后台入口或 CLI 里
            require_once __DIR__ . '/builder/BloxGlobalClasses.php';
            BloxGlobalClasses::forgetAfterBulkReplace($this->root);
        } catch (Throwable $e) {
            error_log('Site template: global class stylesheet refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * 清理一次失败导入留下的文件。只接受本类生成的随机别名形态，避免任何误删可能；
     * 删除失败只记日志，不能掩盖触发回滚的原始异常。
     */
    private function discardImportedFiles(string $alias): void
    {
        if (preg_match('/^sitepack-[0-9a-f]{16}$/D', $alias) !== 1) return;
        foreach ([$this->root . '/themes/' . $alias, $this->root . '/uploads/' . $alias] as $directory) {
            try {
                if (!is_dir($directory)) continue;
                $this->assertContained($directory);
                $entries = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($entries as $entry) {
                    if ($entry->isLink() || $entry->isFile()) @unlink($entry->getPathname());
                    elseif ($entry->isDir()) @rmdir($entry->getPathname());
                }
                @rmdir($directory);
            } catch (Throwable $cleanup) {
                error_log('[SiteTemplateService] orphan cleanup skipped for ' . $alias . ': ' . $cleanup->getMessage());
            }
        }
    }

    private function beginLockedTransaction(): void
    {
        // Plugin-owned hooks must validate/lock their own explicitly whitelisted
        // tables with this same db() connection. The core deliberately does not
        // know plugin table names.
        $tables = array_merge(SiteTemplateData::TABLES, ['settings'], self::PRIVATE_TABLES);
        if (!db()->isSqlite()) foreach ($tables as $table) {
            if (!db()->tableExists($table)) continue;
            $engine = db()->fetchColumn('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [DB_PREFIX . $table]);
            if (strtolower((string) $engine) !== 'innodb') throw new RuntimeException('st_engine');
        }
        // Full-range locking also protects empty tables against concurrent submissions.
        if (!db()->isSqlite()) db()->execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        db()->beginTransaction();
        try {
            if (!db()->isSqlite()) foreach ($tables as $table) {
                if (db()->tableExists($table)) db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' FOR UPDATE');
            }
        } catch (Throwable $e) { db()->rollback(); throw $e; }
    }

    private function installFiles(array $files, string $alias, array $map): void
    {
        // uploads/<别名> 可能是本次 stage() 建的（别名在 prepare 阶段生成、随计划固定），
        // 所以只拒绝主题目录冲突；媒体的重复写入由上面的摘要比对兜住。
        if (file_exists($this->root . '/themes/' . $alias)) throw new RuntimeException('st_storage');
        $temp = $this->temporaryFile();
        try {
            $zip = new ZipArchive();
            if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('st_storage');
            try {
                foreach ($files as $path => $bytes) {
                    if (!str_starts_with($path, 'theme/')) continue;
                    if ($this->textFile($path)) $bytes = SiteTemplateArchive::rewrite($bytes, $map);
                    if (!$zip->addFromString($alias . '/' . substr($path, 6), $bytes)) throw new RuntimeException('st_storage');
                }
            } finally { $zip->close(); }
            $this->assertContained($this->root . '/themes');
            $result = (new ThemeInstaller($this->root . '/themes', $this->root . '/storage'))->install($temp, $alias);
            if (!$result['ok']) throw new RuntimeException('st_theme');
        } finally { @unlink($temp); }
        foreach ($files as $path => $bytes) {
            if (!str_starts_with($path, 'media/')) continue;
            $target = $this->root . '/uploads/' . $alias . '/' . substr($path, 6);
            $this->ensureDirectory(dirname($target));
            $this->assertContained($target);
            // stage() 可能已按预算把这条写好了：内容一致就跳过，既保证幂等，
            // 也让「先分批准备、再一次生效」不至于把同一份媒体写两遍。
            if (is_file($target) && hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $target))) continue;
            $handle = fopen($target, 'xb');
            if ($handle === false) throw new RuntimeException('st_storage');
            try { if (fwrite($handle, $bytes) !== strlen($bytes)) throw new RuntimeException('st_storage'); }
            finally { fclose($handle); }
        }
    }

    private function summary(array $manifest, array $files): array
    {
        // 语言分布要在「应用」之前就摆出来：整站导入连 enabled_languages 一起覆盖，
        // 装完才发现某个语言是空的，就只能回滚重来。
        $languages = SiteTemplateLanguages::inspect(is_array($manifest['data'] ?? null) ? $manifest['data'] : []);

        $plugins = is_array($manifest['plugins'] ?? null) ? $manifest['plugins'] : [];
        return ['theme' => $manifest['theme'], 'cms' => $manifest['cms'],
            'plugins' => $plugins === [] ? '-' : implode(', ', array_map(static fn(array $plugin): string => $plugin['slug'] . ' ' . $plugin['version'], $plugins)),
            'languages' => SiteTemplateLanguages::labels($languages['content']),
            'languages_empty' => $languages['empty'],
            'channels' => count($manifest['data']['tables']['channels']), 'contents' => count($manifest['data']['tables']['contents']),
            'products' => count($manifest['data']['tables']['products']), 'forms' => count($manifest['data']['tables']['form_templates']),
            'media' => count(array_filter(array_keys($files), static fn(string $key): bool => str_starts_with($key, 'media/')))];
    }

    /** @return list<array{slug:string,version:string}> */
    private function activePlugins(bool $strict = true): array
    {
        if (!db()->tableExists('plugins')) return [];
        $plugins = [];
        foreach (pluginModel()->getActiveSlugs() as $slug) {
            $slug = (string) $slug;
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) !== 1) {
                if ($strict) throw new RuntimeException('st_plugin_manifest');
                continue;
            }
            $path = $this->root . '/plugins/' . $slug . '/plugin.json';
            $this->assertContained($path);
            $meta = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $version = is_array($meta) ? trim((string) ($meta['version'] ?? '')) : '';
            if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/D', $version) !== 1) {
                if ($strict) throw new RuntimeException('st_plugin_manifest');
                continue;
            }
            $plugins[$slug] = ['slug' => $slug, 'version' => $version];
        }
        ksort($plugins);
        return array_values($plugins);
    }

    /** @param list<array{slug:string,version:string}> $required @return list<array{slug:string,version:string}> */
    private function missingPlugins(array $required): array
    {
        if ($required === []) return [];
        $active = [];
        foreach ($this->activePlugins(false) as $plugin) $active[$plugin['slug']] = $plugin['version'];
        $missing = [];
        foreach ($required as $plugin) {
            $slug = $plugin['slug'];
            $version = $plugin['version'];
            if (!isset($active[$slug]) || version_compare($active[$slug], $version, '<')) $missing[] = $plugin;
        }
        return $missing;
    }

    /**
     * Only plugins with packaged data participate in plugin state guards.
     * Dependency-only plugins retain the W1-a trusted/confirm behavior.
     *
     * @param list<array{slug:string,version:string}> $plugins
     * @param array<string,array<string,mixed>> $pluginData
     * @return list<array{slug:string,version:string}>
     */
    private function pluginRequirements(array $plugins, array $pluginData): array
    {
        $wanted = array_fill_keys(array_keys($pluginData), true);
        return array_values(array_filter($plugins, static fn(array $plugin): bool => isset($wanted[$plugin['slug']])));
    }

    /**
     * @param list<array{slug:string,version:string}> $requirements
     * @param list<array{slug:string,version:string}> $missing
     * @return list<array{slug:string,version:string}>
     */
    private function withoutMissingRequirements(array $requirements, array $missing): array
    {
        $slugs = array_fill_keys(array_column($missing, 'slug'), true);
        return array_values(array_filter($requirements, static fn(array $plugin): bool => !isset($slugs[$plugin['slug']])));
    }

    /** @param list<array{slug:string,version:string}> $plugins */
    private function pluginListFingerprint(array $plugins): string
    {
        usort($plugins, static fn(array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));
        return hash('sha256', json_encode($plugins, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,array<string,mixed>> $expected
     * @param list<array{slug:string,version:string}> $requirements
     */
    private function pluginSnapshotMatches(array $expected, array $requirements): bool
    {
        if ($expected === [] && $requirements === []) return true;
        try {
            if ($this->missingPlugins($requirements) !== []) return false;
            $current = SiteTemplatePluginData::snapshot($requirements);
            if (array_keys($current) !== array_keys($expected)) return false;
            return hash_equals(SiteTemplatePluginData::fingerprint($expected), SiteTemplatePluginData::fingerprint($current));
        } catch (Throwable $error) {
            return false;
        }
    }

    /** 仅测试环境生效：让小夹具也能稳定压出多轮暂存。 */
    private function stageMaxFiles(): int
    {
        if (PHP_SAPI === 'cli' && getenv('APP_ENV') === 'testing') {
            $value = getenv('YIKAI_SITE_TEMPLATE_STAGE_MAX_FILES');
            if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) return min(self::STAGE_MAX_FILES, (int) $value);
        }
        return self::STAGE_MAX_FILES;
    }

    /**
     * A pinned value is export-safe only when the package already contains the same portable setting.
     * This keeps the override guard for runtime-only or divergent values while allowing authored sample
     * sites that pin current_theme/site_name to their identical stored values.
     */
    private function overridesMatchPortableSettings(array $overrides): bool
    {
        foreach ($overrides as $key => $value) {
            if (!is_string($key) || !SiteTemplateData::settingAllowed($key) || !is_scalar($value)
                || (string) settingModel()->get($key, '') !== (string) $value) return false;
        }
        return true;
    }

    private function textFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'css', 'js', 'json', 'svg'], true);
    }

    private function boundedRead(string $path, int &$total): string
    {
        $length = filesize($path);
        if ($length === false || $length > SiteTemplateArchive::MAX_FILE || $total + $length > SiteTemplateArchive::MAX_TOTAL) throw new RuntimeException('st_limit');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('st_storage');
        $total += strlen($bytes);
        return $bytes;
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yk-site-');
        if ($path === false) throw new RuntimeException('st_storage');
        return $path;
    }

    /** Reject links at every existing component, including Windows junctions resolving outside the root. */
    private function assertContained(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $this->root . '/')) throw new RuntimeException('st_unsafe');
        $cursor = $path;
        while ($cursor !== $this->root) {
            if (is_link($cursor)) throw new RuntimeException('st_unsafe');
            if (file_exists($cursor)) {
                $real = realpath($cursor);
                if ($real === false || strtolower(str_replace('\\', '/', $real)) !== strtolower($cursor)) throw new RuntimeException('st_unsafe');
            }
            $parent = str_replace('\\', '/', dirname($cursor));
            if ($parent === $cursor) throw new RuntimeException('st_unsafe');
            $cursor = $parent;
        }
    }

    private function ensureDirectory(string $path): void
    {
        $this->assertContained($path);
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('st_storage');
    }

    private function recordPath(string $name): string
    {
        if (!in_array($name, ['baseline', 'plan', 'current'], true)) throw new RuntimeException('st_unsafe');
        $path = $this->store . '/' . $name . '.php';
        $this->assertContained($path);
        return $path;
    }

    private function readRecord(string $name): ?array
    {
        $path = $this->recordPath($name);
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if (!is_string($raw) || !str_starts_with($raw, self::GUARD)) throw new RuntimeException('st_storage');
        $value = json_decode(substr($raw, strlen(self::GUARD)), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('st_storage');
        return $value;
    }

    private function writeRecord(string $name, array $data): void
    {
        $this->ensureDirectory($this->store);
        $target = $this->recordPath($name);
        $temporary = $this->store . '/write-' . bin2hex(random_bytes(16)) . '.php';
        $bytes = self::GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $target)) throw new RuntimeException('st_storage');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private function withLock(callable $action): void
    {
        $this->ensureDirectory($this->store);
        $path = $this->store . '/operation.lock';
        $this->assertContained($path);
        $handle = fopen($path, 'c');
        if ($handle === false) throw new RuntimeException('st_storage');
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('st_busy');
            $action();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
