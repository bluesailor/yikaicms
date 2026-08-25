<?php

declare(strict_types=1);

require_once __DIR__ . '/image.php';

/** 媒体衍生文件健康检查与有边界修复。 */
final class MediaOptimization
{
    public const MAX_BATCH = 24;

    /** @param array<string,mixed> $media @return array<string,mixed> */
    public static function inspect(array $media): array
    {
        if (($media['type'] ?? '') !== 'image') {
            return self::result('unsupported', false, false);
        }

        $path = self::sourcePath($media);
        if ($path === null) {
            return self::result('missing', true, false);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return self::result('unsupported', false, false);
        }

        $dimensions = @getimagesize($path);
        if ($dimensions === false) {
            return self::result('missing', true, false);
        }
        $width = max(0, (int) ($dimensions[0] ?? 0));
        $height = max(0, (int) ($dimensions[1] ?? 0));
        $sourceMtime = @filemtime($path) ?: 0;
        $pending = [];
        $expectedSources = ['original' => $path];

        foreach (THUMBNAIL_SIZES as $name => $size) {
            if ($width <= (int) $size['width'] && $height <= (int) $size['height']) {
                continue;
            }
            $thumbnailPath = _thumbnailPath($path, (string) $name);
            $thumbnailMtime = @filemtime($thumbnailPath) ?: 0;
            if (!is_file($thumbnailPath) || $thumbnailMtime < $sourceMtime) {
                $pending[] = 'thumbnail:' . $name;
            }
            $expectedSources[(string) $name] = $thumbnailPath;
        }

        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            foreach ($expectedSources as $name => $source) {
                $target = preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $source);
                $sourceTime = @filemtime($source) ?: $sourceMtime;
                $targetTime = is_string($target) ? (@filemtime($target) ?: 0) : 0;
                if (!is_string($target) || !is_file($target) || $targetTime < $sourceTime) {
                    $pending[] = 'webp:' . $name;
                }
            }
        }

        $pending = array_values(array_unique($pending));
        $expected = count($expectedSources) + (in_array($ext, ['jpg', 'jpeg', 'png'], true)
            ? count($expectedSources)
            : 0);
        $needsThumbnail = array_filter($pending, static fn(string $item): bool => str_starts_with($item, 'thumbnail:')) !== [];
        $needsWebp = array_filter($pending, static fn(string $item): bool => str_starts_with($item, 'webp:')) !== [];
        $decoder = match ($ext) {
            'jpg', 'jpeg' => 'imagecreatefromjpeg',
            'png' => 'imagecreatefrompng',
            'webp' => 'imagecreatefromwebp',
            default => '',
        };
        $repairable = (!$needsThumbnail || ($decoder !== '' && function_exists($decoder)))
            && (!$needsWebp || function_exists('imagewebp'));

        return [
            'status' => $pending === [] ? 'healthy' : 'pending',
            'applicable' => true,
            'repairable' => $pending === [] || $repairable,
            'expected' => $expected,
            'ready' => max(0, $expected - count($pending)),
            'pending' => $pending,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    public static function inspectMany(array $rows): array
    {
        $health = [];
        foreach (array_slice($rows, 0, self::MAX_BATCH) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $health[$id] = self::inspect($row);
            }
        }
        return $health;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    public static function repairMany(array $rows): array
    {
        $summary = [
            'processed' => 0,
            'repaired' => 0,
            'healthy' => 0,
            'failed' => 0,
            'skipped' => 0,
            'items' => [],
        ];

        foreach (array_slice($rows, 0, self::MAX_BATCH) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $summary['processed']++;
            $before = self::inspect($row);
            if ($before['status'] === 'healthy') {
                $summary['healthy']++;
                $summary['items'][$id] = $before;
                continue;
            }
            if ($before['status'] === 'unsupported') {
                $summary['skipped']++;
                $summary['items'][$id] = $before;
                continue;
            }
            if (!$before['repairable']) {
                $summary['failed']++;
                $summary['items'][$id] = $before;
                continue;
            }

            $after = self::repair($row, $before);
            $summary['items'][$id] = $after;
            if ($after['status'] === 'healthy') {
                $summary['repaired']++;
            } else {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /** @param array<string,mixed> $media */
    public static function deleteArtifacts(array $media): int
    {
        $path = self::sourcePath($media);
        if ($path === null) {
            return 0;
        }

        $paths = [$path];
        foreach (array_keys(THUMBNAIL_SIZES) as $name) {
            $paths[] = _thumbnailPath($path, (string) $name);
        }
        foreach ($paths as $candidate) {
            $webp = preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $candidate);
            if (is_string($webp) && $webp !== $candidate) {
                $paths[] = $webp;
            }
        }

        $deleted = 0;
        foreach (array_values(array_unique($paths)) as $candidate) {
            if (is_file($candidate) && !is_link($candidate) && self::isInsideUploads($candidate) && @unlink($candidate)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /** @param mixed $ids @return list<int> */
    public static function normalizeIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $normalized = [];
        foreach ($ids as $id) {
            if ((is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0) {
                $normalized[(int) $id] = (int) $id;
            }
        }
        return array_values($normalized);
    }

    /** @param array<string,mixed> $media @param array<string,mixed> $before @return array<string,mixed> */
    private static function repair(array $media, array $before): array
    {
        $path = self::sourcePath($media);
        if ($path === null) {
            return self::result('missing', true, false);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $pending = is_array($before['pending'] ?? null) ? $before['pending'] : [];
        $needsThumbnail = array_filter(
            $pending,
            static fn(mixed $item): bool => is_string($item) && str_starts_with($item, 'thumbnail:')
        ) !== [];
        if ($needsThumbnail) {
            generateThumbnails($path, $ext);
        }
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $quality = max(50, min(95, (int) config('upload_jpeg_quality', 85)));
            generateWebpDerivatives($path, $ext, $quality);
        }
        clearstatcache();
        return self::inspect($media);
    }

    /** @param array<string,mixed> $media */
    private static function sourcePath(array $media): ?string
    {
        $raw = (string) ($media['path'] ?? '');
        if ($raw === '' || is_link($raw)) {
            return null;
        }
        $path = realpath($raw);
        return $path !== false && is_file($path) && self::isInsideUploads($path) ? $path : null;
    }

    private static function isInsideUploads(string $path): bool
    {
        $root = realpath(defined('UPLOADS_PATH') ? UPLOADS_PATH : ROOT_PATH . '/uploads');
        $real = realpath($path);
        if ($root === false || $real === false) {
            return false;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $real = str_replace('\\', '/', $real);
        return DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($real), strtolower($root))
            : str_starts_with($real, $root);
    }

    /** @return array{status:string,applicable:bool,repairable:bool,expected:int,ready:int,pending:list<string>} */
    private static function result(string $status, bool $applicable, bool $repairable): array
    {
        return [
            'status' => $status,
            'applicable' => $applicable,
            'repairable' => $repairable,
            'expected' => 0,
            'ready' => 0,
            'pending' => [],
        ];
    }
}
