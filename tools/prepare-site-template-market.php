<?php
declare(strict_types=1);

/** Local release preparation only. Never signs packages, enables publication, or sends network requests. */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/SiteTemplateOfflineValidator.php';
require_once ROOT_PATH . '/includes/SiteTemplateMarket.php';

/**
 * Build and verify a complete candidate before replacing any caller-visible file. Replacements are
 * backed up and rolled back if the last staging/digest gate fails.
 *
 * @param array<string,string> $options
 * @param null|callable():void $beforeFinalValidation Test seam for deterministic package-swap coverage.
 * @param null|callable():void $beforeReplacement Test seam for deterministic junction-swap coverage.
 * @return array{templates:int,bytes:int,format_versions:array<int,int>,status:string,review:string}
 */
function prepareSiteTemplateMarket(
    array $options,
    ?callable $beforeFinalValidation = null,
    ?callable $beforeReplacement = null
): array
{
    foreach (['inventory', 'delivery', 'covers', 'catalog', 'import-report'] as $required) {
        if (!is_string($options[$required] ?? null) || $options[$required] === '') throw new RuntimeException('Required option: --' . $required);
    }
    $inventory = json_decode((string) file_get_contents($options['inventory']), true, 32, JSON_THROW_ON_ERROR);
    $catalog = json_decode((string) file_get_contents($options['catalog']), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($inventory) || !is_array($catalog) || !is_array($catalog['templates'] ?? null)) throw new RuntimeException('Invalid input');
    $deliveryInput = $options['delivery'];
    $deliveryResolved = realpath($deliveryInput);
    $coverRoot = realpath($options['covers']);
    if ($deliveryResolved === false || $coverRoot === false) throw new RuntimeException('Delivery and covers must already exist');
    $delivery = str_replace('\\', '/', $deliveryResolved);
    $coverRoot = str_replace('\\', '/', $coverRoot);
    siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);

    $publicKeys = ['slug', 'name', 'name_en', 'name_ja', 'description', 'description_en', 'description_ja',
        'category', 'category_name', 'category_name_en', 'category_name_ja', 'requires_php', 'tier'];
    $bySlug = [];
    foreach ($catalog['templates'] as $entry) {
        if (!is_array($entry) || ($entry['status'] ?? '') !== 'draft' || ($entry['sig'] ?? '') !== '') throw new RuntimeException('Only unsigned draft catalogs may be prepared');
        $slug = (string) ($entry['slug'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1 || isset($bySlug[$slug])) throw new RuntimeException('Invalid or duplicate catalog identity');
        $public = [];
        foreach ($publicKeys as $key) if (array_key_exists($key, $entry)) $public[$key] = $entry[$key];
        $bySlug[$slug] = $public;
    }

    $sites = [];
    $allowedFiles = ['catalog.json', 'index.php', 'review-router.php'];
    $requiredPackages = [];
    foreach ($inventory as $site) {
        if (!is_array($site)) throw new RuntimeException('Invalid inventory row');
        $slug = (string) ($site['theme'] ?? '');
        $id = (string) ($site['id'] ?? '');
        $version = (string) ($site['new_version'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1 || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || !isset($bySlug[$slug]) || isset($sites[$slug])) {
            throw new RuntimeException('Invalid or duplicate inventory identity');
        }
        $filename = $slug . '-site-v' . $version . '.zip';
        $packageRelative = 'packages/' . $filename;
        $coverRelative = 'assets/site-templates/' . $slug . '/' . $version . '/preview.webp';
        $sites[$slug] = ['id' => $id, 'version' => $version, 'filename' => $filename,
            'package_relative' => $packageRelative, 'cover_relative' => $coverRelative];
        $allowedFiles[] = $packageRelative;
        $allowedFiles[] = $coverRelative;
        $requiredPackages[] = $packageRelative;
    }
    if (count($sites) !== count($bySlug)) throw new RuntimeException('Inventory and draft catalog differ');
    SiteTemplateOfflineValidator::assertPublicStaging($delivery, $allowedFiles, $requiredPackages);
    siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);

    $assets = [];
    foreach ($sites as $site) {
        $cover = $coverRoot . '/' . $site['id'] . '.webp';
        $info = is_file($cover) ? getimagesize($cover) : false;
        if (!is_array($info) || ($info['mime'] ?? '') !== 'image/webp' || $info[0] < 640 || $info[1] < 320) {
            throw new RuntimeException('Missing desktop WebP cover: ' . $site['id']);
        }
        $assets[] = ['source' => $cover, 'relative' => $site['cover_relative']];
    }
    $reviewPage = file_get_contents(ROOT_PATH . '/deploy/site-template-market/review.php');
    $reviewRouter = file_get_contents(ROOT_PATH . '/deploy/site-template-market/review-router.php');
    if (!is_string($reviewPage) || !is_string($reviewRouter)) throw new RuntimeException('Cannot read review files');

    $workspace = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/yk-site-prepare-' . bin2hex(random_bytes(8));
    $candidate = $workspace . '/candidate';
    $backupRoot = $workspace . '/backups';
    if (!mkdir($candidate, 0755, true) || !mkdir($backupRoot, 0755, true)) throw new RuntimeException('Cannot create preparation workspace');
    try {
        $prepared = [];
        $expectedReport = [];
        $expectedPackages = [];
        foreach ($sites as $slug => $site) {
            $relative = $site['package_relative'];
            $target = SiteTemplateOfflineValidator::prepareContainedTarget($candidate, $relative)['path'];
            siteTemplatePrepareReplaceFrom($delivery . '/' . $relative, $target);
            $verified = SiteTemplateOfflineValidator::inspect($target);
            $manifest = $verified['manifest'];
            $meta = $verified['theme_meta'];
            if (($manifest['theme'] ?? '') !== $slug || ($meta['version'] ?? '') !== $site['version'] || ($manifest['cms'] ?? '') !== CMS_VERSION) {
                throw new RuntimeException('Package metadata mismatch: ' . $site['filename']);
            }
            $item = array_replace($bySlug[$slug], [
                'version' => $site['version'], 'cms' => $manifest['cms'], 'format_version' => $manifest['version'],
                'status' => 'draft', 'sig' => '', 'package' => $site['filename'], 'size_bytes' => $verified['size'],
                'hash' => 'sha256:' . $verified['sha256'],
                'download_url' => 'https://update.yikaicms.com/packages/site-templates/' . $site['filename'],
                'screenshot' => 'https://update.yikaicms.com/assets/site-templates/' . $slug . '/' . $site['version'] . '/preview.webp',
            ]);
            if (SiteTemplateMarket::normalize($item) === null) throw new RuntimeException('Invalid public catalog metadata: ' . $slug);
            $prepared[] = $item;
            $expectedReport[$slug] = ['version' => $site['version'], 'sha256' => $verified['sha256']];
            $expectedPackages[$relative] = ['size' => $verified['size'], 'sha256' => $verified['sha256']];
        }
        foreach ($assets as $asset) {
            $target = SiteTemplateOfflineValidator::prepareContainedTarget($candidate, $asset['relative'])['path'];
            siteTemplatePrepareReplaceFrom($asset['source'], $target);
            $info = getimagesize($target);
            if (!is_array($info) || ($info['mime'] ?? '') !== 'image/webp' || $info[0] < 640 || $info[1] < 320) {
                throw new RuntimeException('Prepared cover changed during copy');
            }
        }
        SiteTemplateOfflineValidator::validateImportReport($options['import-report'], $expectedReport);
        $catalog = ['updated_at' => gmdate('c'), 'templates' => $prepared];
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        siteTemplatePrepareWriteBytes(SiteTemplateOfflineValidator::prepareContainedTarget($candidate, 'catalog.json')['path'], $json);
        siteTemplatePrepareWriteBytes(SiteTemplateOfflineValidator::prepareContainedTarget($candidate, 'index.php')['path'], $reviewPage);
        siteTemplatePrepareWriteBytes(SiteTemplateOfflineValidator::prepareContainedTarget($candidate, 'review-router.php')['path'], $reviewRouter);
        SiteTemplateOfflineValidator::assertPublicStaging($candidate, $allowedFiles, $allowedFiles);
        SiteTemplateOfflineValidator::assertPackageDigests($candidate, $expectedPackages);
        siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
        SiteTemplateOfflineValidator::assertPackageDigests($delivery, $expectedPackages);
        siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);

        $createdDirectories = [];
        $committed = false;
        try {
            $replacements = [];
            foreach ($assets as $asset) {
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                $preparedTarget = SiteTemplateOfflineValidator::prepareContainedTarget($delivery, $asset['relative']);
                $createdDirectories = array_merge($createdDirectories, $preparedTarget['created']);
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                $replacements[] = ['source' => $candidate . '/' . $asset['relative'], 'target' => $preparedTarget['path'],
                    'root' => $delivery, 'relative' => $asset['relative']];
            }
            foreach (['catalog.json', 'index.php', 'review-router.php'] as $relative) {
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                $preparedTarget = SiteTemplateOfflineValidator::prepareContainedTarget($delivery, $relative);
                $createdDirectories = array_merge($createdDirectories, $preparedTarget['created']);
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                $replacements[] = ['source' => $candidate . '/' . $relative, 'target' => $preparedTarget['path'],
                    'root' => $delivery, 'relative' => $relative];
            }
            $externalCatalog = siteTemplatePrepareExistingTarget($options['catalog']);
            $externalRoot = dirname($externalCatalog);
            $externalRelative = basename($externalCatalog);
            $replacements[] = ['source' => $candidate . '/catalog.json', 'target' => $externalCatalog,
                'root' => $externalRoot, 'relative' => $externalRelative];
            $replacements = siteTemplatePrepareUniqueReplacements($replacements);

            $backups = [];
            foreach ($replacements as $index => $replacement) {
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                $target = SiteTemplateOfflineValidator::assertContainedTarget($replacement['root'], $replacement['relative']);
                $exists = is_file($target);
                $backup = $backupRoot . '/' . $index;
                if (($exists && is_link($target)) || (!$exists && file_exists($target))) throw new RuntimeException('Unsafe preparation target');
                if ($exists) siteTemplatePrepareReplaceFrom($target, $backup);
                $backups[] = ['target' => $target, 'backup' => $backup, 'existed' => $exists,
                    'root' => $replacement['root'], 'relative' => $replacement['relative']];
            }

            $appliedBackups = [];
            try {
                if ($beforeReplacement !== null) $beforeReplacement();
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                foreach ($replacements as $index => $replacement) {
                    siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                    $target = SiteTemplateOfflineValidator::assertContainedTarget($replacement['root'], $replacement['relative']);
                    $appliedBackups[] = $backups[$index];
                    siteTemplatePrepareReplaceFrom($replacement['source'], $target);
                    SiteTemplateOfflineValidator::assertContainedTarget($replacement['root'], $replacement['relative'], true);
                    siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                }
                if ($beforeFinalValidation !== null) $beforeFinalValidation();
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                SiteTemplateOfflineValidator::assertPublicStaging($delivery, $allowedFiles, $allowedFiles);
                SiteTemplateOfflineValidator::assertPackageDigests($delivery, $expectedPackages);
                siteTemplatePrepareAssertPinnedDeliveryRoot($deliveryInput, $delivery);
                $committed = true;
            } catch (Throwable $error) {
                $rollbackError = siteTemplatePrepareRollback($appliedBackups);
                if ($rollbackError !== null) throw new RuntimeException('Preparation failed and rollback was incomplete: ' . $rollbackError, 0, $error);
                throw $error;
            }
        } finally {
            if (!$committed) siteTemplatePrepareRemoveEmptyDirectories($createdDirectories);
        }

        return ['templates' => count($prepared), 'bytes' => array_sum(array_column($prepared, 'size_bytes')),
            'format_versions' => array_count_values(array_column($prepared, 'format_version')), 'status' => 'draft', 'review' => 'index.php'];
    } finally {
        siteTemplatePrepareRemoveTree($workspace);
    }
}

/**
 * Keep the caller-visible delivery alias bound to the canonical root selected at startup.
 * All writes use the pinned root; this additional check makes an ancestor junction swap fail
 * instead of silently completing against a different tree.
 */
function siteTemplatePrepareAssertPinnedDeliveryRoot(string $input, string $pinned): void
{
    $trimmed = rtrim($input, "/\\");
    clearstatcache(true);
    $current = realpath($trimmed);
    $pinnedCurrent = realpath($pinned);
    $parent = realpath(dirname($trimmed));
    $lexical = $parent === false ? false : $parent . DIRECTORY_SEPARATOR . basename($trimmed);
    if ($trimmed === '' || $current === false || $pinnedCurrent === false || $parent === false || $lexical === false
        || !is_dir($trimmed) || is_link($trimmed)
        || !siteTemplatePrepareSamePath($current, $pinned)
        || !siteTemplatePrepareSamePath($pinnedCurrent, $pinned)
        || !siteTemplatePrepareSamePath($lexical, $pinned)) {
        throw new RuntimeException('Delivery root changed during preparation');
    }
}

function siteTemplatePrepareSamePath(string $left, string $right): bool
{
    $left = rtrim(str_replace('\\', '/', $left), '/');
    $right = rtrim(str_replace('\\', '/', $right), '/');
    return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
}

function siteTemplatePrepareWriteBytes(string $path, string $bytes): void
{
    $temporary = $path . '.prepare-' . bin2hex(random_bytes(6));
    try {
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Cannot write preparation file');
        siteTemplatePrepareInstallTemporary($temporary, $path);
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function siteTemplatePrepareReplaceFrom(string $source, string $target): void
{
    if (!is_file($source) || is_link($source)) throw new RuntimeException('Invalid preparation source');
    $temporary = $target . '.prepare-' . bin2hex(random_bytes(6));
    try {
        if (!copy($source, $temporary) || !hash_equals((string) hash_file('sha256', $source), (string) hash_file('sha256', $temporary))) {
            throw new RuntimeException('Preparation copy verification failed');
        }
        siteTemplatePrepareInstallTemporary($temporary, $target);
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function siteTemplatePrepareInstallTemporary(string $temporary, string $target): void
{
    if ((file_exists($target) || is_link($target)) && (!is_file($target) || is_link($target) || !unlink($target))) {
        throw new RuntimeException('Unsafe preparation target');
    }
    if (!rename($temporary, $target)) throw new RuntimeException('Cannot replace preparation file');
}

/**
 * @param list<array{source:string,target:string,root:string,relative:string}> $replacements
 * @return list<array{source:string,target:string,root:string,relative:string}>
 */
function siteTemplatePrepareUniqueReplacements(array $replacements): array
{
    $unique = [];
    foreach ($replacements as $replacement) {
        $key = str_replace('\\', '/', $replacement['target']);
        if (DIRECTORY_SEPARATOR === '\\') $key = strtolower($key);
        if (isset($unique[$key])) {
            if (!hash_equals((string) hash_file('sha256', $unique[$key]['source']), (string) hash_file('sha256', $replacement['source']))) {
                throw new RuntimeException('Conflicting preparation targets');
            }
            continue;
        }
        $unique[$key] = $replacement;
    }
    return array_values($unique);
}

function siteTemplatePrepareExistingTarget(string $path): string
{
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || is_link($path)) throw new RuntimeException('Catalog target must be an ordinary existing file');
    return $resolved;
}

/** @param list<array{target:string,backup:string,existed:bool,root:string,relative:string}> $backups */
function siteTemplatePrepareRollback(array $backups): ?string
{
    $failure = null;
    foreach (array_reverse($backups) as $backup) {
        try {
            if ($backup['existed']) {
                $target = SiteTemplateOfflineValidator::assertContainedTarget($backup['root'], $backup['relative']);
                siteTemplatePrepareReplaceFrom($backup['backup'], $target);
                SiteTemplateOfflineValidator::assertContainedTarget($backup['root'], $backup['relative'], true);
            } else {
                $target = SiteTemplateOfflineValidator::assertContainedTarget($backup['root'], $backup['relative']);
                if ((file_exists($target) || is_link($target)) && !unlink($target)) {
                    throw new RuntimeException('Cannot remove new preparation file');
                }
                SiteTemplateOfflineValidator::assertContainedTarget($backup['root'], $backup['relative']);
            }
        } catch (Throwable $error) {
            $failure = $failure ?? $error->getMessage();
        }
    }
    return $failure;
}

/** @param list<string> $directories */
function siteTemplatePrepareRemoveEmptyDirectories(array $directories): void
{
    $seen = [];
    foreach (array_reverse($directories) as $directory) {
        $key = str_replace('\\', '/', $directory);
        if (DIRECTORY_SEPARATOR === '\\') $key = strtolower($key);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($directory) || is_link($directory)) continue;
        $left = rtrim(str_replace('\\', '/', $directory), '/');
        $right = rtrim(str_replace('\\', '/', $resolved), '/');
        $same = DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
        if ($same) @rmdir($directory);
    }
}

function siteTemplatePrepareRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($path);
}

$script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($script !== false && strcasecmp(str_replace('\\', '/', $script), str_replace('\\', '/', __FILE__)) === 0) {
    $parsed = getopt('', ['inventory:', 'delivery:', 'covers:', 'catalog:', 'import-report:']);
    $options = [];
    if (is_array($parsed)) foreach ($parsed as $key => $value) {
        if (is_string($key) && is_string($value)) $options[$key] = $value;
    }
    $result = prepareSiteTemplateMarket($options);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
