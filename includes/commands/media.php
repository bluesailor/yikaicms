<?php
/**
 * 命令组：media
 *   media:webp  为历史 JPG/PNG 原图与现有缩略图补齐 WebP 副本
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

function mediaWebpResolveRoot(string $relativePath): ?string
{
    $uploadsRoot = realpath(ROOT_PATH . '/uploads');
    if ($uploadsRoot === false || !is_dir($uploadsRoot)) {
        return null;
    }

    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
    if (str_contains($relativePath, "\0")) {
        return null;
    }
    $candidate = $relativePath === ''
        ? $uploadsRoot
        : $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $resolved = realpath($candidate);
    if ($resolved === false || !is_dir($resolved)) {
        return null;
    }

    $root = strtolower(str_replace('\\', '/', rtrim($uploadsRoot, DIRECTORY_SEPARATOR)));
    $path = strtolower(str_replace('\\', '/', rtrim($resolved, DIRECTORY_SEPARATOR)));
    if ($path !== $root && !str_starts_with($path, $root . '/')) {
        return null;
    }

    return $resolved;
}

function mediaWebpIsThumbnail(string $filename): bool
{
    $sizeNames = array_map(
        static fn(string $name): string => preg_quote($name, '/'),
        array_keys(THUMBNAIL_SIZES)
    );
    return preg_match('/_(?:' . implode('|', $sizeNames) . ')$/i', $filename) === 1;
}

function mediaWebpExceedsPixelLimit(string $path): bool
{
    $dimensions = @getimagesize($path);
    if ($dimensions === false) {
        return false;
    }

    return !imageDimensionsWithinPixelLimit(
        (int) ($dimensions[0] ?? 0),
        (int) ($dimensions[1] ?? 0),
        uploadMaxImageMegapixels() * 1_000_000
    );
}

CLI::register('media:webp', '为历史图片补齐响应式 WebP 副本', function (array $args, array $opts): int {
    if (array_key_exists('path', $opts) && !is_string($opts['path'])) {
        CLI::err('--path 必须指定 uploads/ 下的相对目录');
        return 1;
    }
    $relativePath = isset($opts['path']) && is_string($opts['path']) ? $opts['path'] : '';
    $root = mediaWebpResolveRoot($relativePath);
    if ($root === null) {
        CLI::err('扫描路径不存在或超出 uploads/：' . ($relativePath !== '' ? $relativePath : '.'));
        return 1;
    }

    $limitOption = $opts['limit'] ?? '0';
    if (!is_string($limitOption) || !ctype_digit($limitOption)) {
        CLI::err('--limit 必须是大于等于 0 的整数');
        return 1;
    }
    $limit = (int) $limitOption;
    $dryRun = !empty($opts['dry-run']);
    $force = !empty($opts['force']);
    if (!function_exists('imagewebp')) {
        CLI::err('当前 PHP GD 未启用 WebP 支持');
        return 1;
    }

    $quality = max(50, min(95, (int) config('upload_jpeg_quality', 85)));
    $files = 0;
    $pending = 0;
    $generated = 0;
    $current = 0;
    $failed = 0;
    $oversized = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            continue;
        }
        if (mediaWebpIsThumbnail($file->getBasename('.' . $ext))) {
            continue;
        }
        if ($limit > 0 && $files >= $limit) {
            break;
        }

        $files++;
        if (mediaWebpExceedsPixelLimit($file->getPathname())) {
            $oversized++;
            continue;
        }
        $plan = webpDerivativePlan($file->getPathname(), $ext, $force);
        foreach ($plan as $item) {
            if ($item['current']) {
                $current++;
            } else {
                $pending++;
            }
        }
        if ($dryRun) {
            continue;
        }

        $result = generateWebpDerivatives($file->getPathname(), $ext, $quality, $force);
        $generated += $result['generated'];
        $failed += $result['failed'];
    }

    if ($dryRun) {
        CLI::info("[dry-run] 扫描原图 {$files}，待生成 {$pending}，已是最新 {$current}，跳过超限 {$oversized}");
        return 0;
    }
    if ($failed > 0) {
        CLI::err("WebP 回填完成但有失败：原图 {$files}，生成 {$generated}，已是最新 {$current}，跳过超限 {$oversized}，失败 {$failed}");
        return 1;
    }

    CLI::ok("WebP 回填完成：原图 {$files}，生成 {$generated}，已是最新 {$current}，跳过超限 {$oversized}");
    return 0;
}, [
    'usage' => 'media:webp [--path=images/202608] [--dry-run] [--force] [--limit=100]',
]);
