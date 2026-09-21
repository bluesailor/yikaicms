<?php

declare(strict_types=1);

require_once __DIR__ . '/MediaOptimization.php';

/**
 * 安全替换媒体：新文件落进原路径，URL / 文件名 / 引用一律不变。
 *
 * 客户"换图不改内容"是高频需求：改 banner、换产品图时，如果走删除再上传，
 * 内容里的旧 URL 全部失效。替换保持路径不变，内容零迁移：
 *   1. 校验（同扩展名保 URL、大小上限、图片内容真实）；
 *   2. 旧文件备份到 storage/backups/media/（每条媒体保留最近 KEEP_BACKUPS 份）；
 *   3. 清掉旧衍生文件（缩略图 / WebP 兄弟文件，不动源文件槽位）；
 *   4. 新文件覆盖原路径，失败自动回滚备份；
 *   5. 媒体行的新元数据（size/md5/宽高/mime）返回给调用方落库，衍生重建复用
 *      MediaOptimization::repairMany（不在此处触碰数据库，保持纯文件系统可测）。
 */
final class MediaReplacement
{
    /** 每条媒体保留的历史替换备份数。 */
    public const KEEP_BACKUPS = 5;

    private const IMAGE_MIME = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    /**
     * 执行替换。返回 [ok, msg, meta?, backup?, repaired?]；ok=false 时现场保持原样。
     *
     * @param array<string,mixed> $media 媒体行（用 path / id）
     * @param string $sourcePath 新文件（上传流程为 tmp 文件；测试可用普通文件）
     * @param string $sourceExt 新文件扩展名（tmp 无扩展名，由原始上传名提供）
     * @return array<string,mixed>
     */
    public static function replace(array $media, string $sourcePath, string $sourceExt): array
    {
        $path = (string) ($media['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return self::fail('media_replace_source_missing');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return self::fail('media_replace_upload_missing');
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $sourceExt = strtolower(trim($sourceExt, '.'));
        if ($sourceExt === '' || $sourceExt !== $ext) {
            // 扩展名变 = URL 变 = 引用全断，替换的意义就没了
            return self::fail('media_replace_ext_mismatch');
        }
        if (defined('UPLOAD_MAX_SIZE') && (int) @filesize($sourcePath) > UPLOAD_MAX_SIZE) {
            return self::fail('media_replace_too_large');
        }
        if (isset(self::IMAGE_MIME[$ext])) {
            // 内容校验：防伪造扩展名（与 uploadFile 同口径；svg 无尺寸可查，走 finfo）
            $dimensions = @getimagesize($sourcePath);
            if ($dimensions === false) {
                return self::fail('media_replace_not_image');
            }
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? (string) finfo_file($finfo, $sourcePath) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }
                if ($mime !== '' && !in_array($mime, self::IMAGE_MIME[$ext], true)) {
                    return self::fail('media_replace_mime_mismatch');
                }
            }
        }

        $backup = self::backupSource((int) ($media['id'] ?? 0), $path, $ext);
        self::deleteDerivatives($path);

        // 先落临时名再原子 rename：中途失败不会留下半个文件占着原 URL
        $staged = $path . '.replacing-' . bin2hex(random_bytes(4));
        if (!@copy($sourcePath, $staged) || !@rename($staged, $path)) {
            if (is_file($staged)) {
                @unlink($staged);
            }
            if ($backup !== '') {
                @copy($backup, $path);   // 回滚：旧文件回到原位
            }
            return self::fail('media_replace_write_failed');
        }
        @touch($path);

        $meta = self::metaFor($path, $ext);
        // 衍生重建（仅图片）：repairMany 内部有像素上限与批次边界
        $repaired = null;
        if (isset(self::IMAGE_MIME[$ext])) {
            $repaired = MediaOptimization::repairMany([['id' => (int) ($media['id'] ?? 0), 'path' => $path] + $media]);
        }

        return [
            'ok' => true,
            'msg' => 'media_replace_done',
            'meta' => $meta,
            'backup' => $backup,
            'repaired' => $repaired,
        ];
    }

    /** @return array<string,mixed> */
    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }

    /**
     * 备份旧文件；返回备份路径（失败返回 ''，替换继续——备份是保障项不是前置条件）。
     */
    private static function backupSource(int $id, string $path, string $ext): string
    {
        $dir = ROOT_PATH . '/storage/backups/media';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return '';
        }
        $backup = $dir . '/' . max(0, $id) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.' . $ext;
        if (!@copy($path, $backup)) {
            return '';
        }
        self::pruneBackups($dir, max(0, $id));
        return $backup;
    }

    /** 只清本条媒体的历史备份，别的媒体不动。 */
    private static function pruneBackups(string $dir, int $id): void
    {
        $backups = glob($dir . '/' . $id . '-*.*');
        if ($backups === false || count($backups) <= self::KEEP_BACKUPS) {
            return;
        }
        usort($backups, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($backups, self::KEEP_BACKUPS) as $stale) {
            @unlink($stale);
        }
    }

    /**
     * 删除源文件的衍生文件（各档缩略图 + 对应 WebP 兄弟），保留源文件本身——
     * 与 MediaOptimization::deleteArtifacts 的差别只有"不动源文件"。
     */
    private static function deleteDerivatives(string $path): void
    {
        $candidates = [];
        foreach (array_keys(THUMBNAIL_SIZES) as $name) {
            $candidates[] = _thumbnailPath($path, (string) $name);
        }
        $candidates[] = $path;
        $derivatives = [];
        foreach ($candidates as $candidate) {
            $webp = preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $candidate);
            if (is_string($webp) && $webp !== $candidate) {
                $derivatives[] = $webp;
            }
        }
        foreach (array_unique($derivatives) as $candidate) {
            if (is_file($candidate) && !is_link($candidate)) {
                @unlink($candidate);
            }
        }
    }

    /** @return array<string,mixed> 落库用的新元数据 */
    private static function metaFor(string $path, string $ext): array
    {
        $meta = [
            'size' => (int) (@filesize($path) ?: 0),
            'md5' => (string) (@md5_file($path) ?: ''),
            'width' => 0,
            'height' => 0,
            'mime' => '',
        ];
        if (isset(self::IMAGE_MIME[$ext])) {
            $dimensions = @getimagesize($path);
            if (is_array($dimensions)) {
                $meta['width'] = (int) ($dimensions[0] ?? 0);
                $meta['height'] = (int) ($dimensions[1] ?? 0);
                $meta['mime'] = (string) ($dimensions['mime'] ?? '');
            }
        } elseif (function_exists('mime_content_type')) {
            $meta['mime'] = (string) (@mime_content_type($path) ?: '');
        }
        return $meta;
    }
}
