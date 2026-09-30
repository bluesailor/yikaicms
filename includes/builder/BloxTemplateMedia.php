<?php
/**
 * Blox 模板包携带图片（2.0.3）：包格式仍是单个 JSON（v1），可选字段 `media` 以 base64 嵌入
 * 文档与缩略图引用到的站内上传图片。旧版导入端忽略该字段（行为与以前一样：图片缺失）。
 *
 * 导出：只收 uploads/ 下实际存在、扩展名在白名单内的位图，按数量与体积上限截断。
 * 导入：逐项严格校验（路径、base64、SHA-256、真实图片类型与扩展名一致），按内容哈希落到
 *       uploads/blox-templates/，同内容复用不重复写；登记进媒体库；文档引用改写到新路径。
 * SVG 不携带（可执行内容风险），这类引用原样保留。
 */

declare(strict_types=1);

final class BloxTemplateMedia
{
    public const MAX_FILES = 30;
    public const MAX_FILE_BYTES = 2_000_000;
    public const MAX_TOTAL_BYTES = 5_000_000;
    public const TARGET_DIR = 'blox-templates';
    /** 扩展名 → 允许的真实 MIME */
    private const TYPES = [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'], 'avif' => ['image/avif'],
    ];

    /**
     * 收集文档（及缩略图）引用的上传图片，打包进导出。
     *
     * @return list<array{path:string,mime:string,sha256:string,data:string}>
     */
    public static function export(mixed $document, string $thumbnail = '', ?string $uploadsDir = null): array
    {
        $uploadsDir ??= ROOT_PATH . '/uploads';
        $refs = UploadReferences::collect([$document, $thumbnail]);
        $out = [];
        $total = 0;
        foreach (array_keys($refs) as $relative) {
            if (count($out) >= self::MAX_FILES) break;
            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (!isset(self::TYPES[$ext]) || !self::safeRelative($relative)) continue;
            $file = $uploadsDir . '/' . $relative;
            $size = is_file($file) ? (int) filesize($file) : 0;
            if ($size <= 0 || $size > self::MAX_FILE_BYTES || $total + $size > self::MAX_TOTAL_BYTES) continue;
            $bytes = (string) file_get_contents($file);
            $mime = self::imageMime($bytes);
            if ($mime === null || !in_array($mime, self::TYPES[$ext], true)) continue;
            $total += $size;
            $out[] = ['path' => $relative, 'mime' => $mime, 'sha256' => hash('sha256', $bytes), 'data' => base64_encode($bytes)];
        }
        return $out;
    }

    /**
     * 校验包里的 media 字段并规划落盘路径。任何一项不合格即整体拒绝（不做部分导入）。
     *
     * @return list<array{path:string,target:string,mime:string,sha256:string,bytes:string}>
     */
    public static function validate(mixed $raw): array
    {
        if ($raw === null) return [];
        if (!is_array($raw) || !self::isList($raw) || count($raw) > self::MAX_FILES) {
            throw new RuntimeException(__('blox_tpl_media_invalid'));
        }
        $out = [];
        $total = 0;
        foreach ($raw as $item) {
            $path = is_array($item) ? (string) ($item['path'] ?? '') : '';
            $sha = is_array($item) ? strtolower((string) ($item['sha256'] ?? '')) : '';
            $data = is_array($item) ? (string) ($item['data'] ?? '') : '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!self::safeRelative($path) || !isset(self::TYPES[$ext]) || preg_match('/^[0-9a-f]{64}$/D', $sha) !== 1) {
                throw new RuntimeException(__('blox_tpl_media_invalid'));
            }
            $bytes = base64_decode($data, true);
            if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_FILE_BYTES || !hash_equals($sha, hash('sha256', $bytes))) {
                throw new RuntimeException(__('blox_tpl_media_invalid'));
            }
            $total += strlen($bytes);
            $mime = self::imageMime($bytes);
            if ($total > self::MAX_TOTAL_BYTES || $mime === null || !in_array($mime, self::TYPES[$ext], true)) {
                throw new RuntimeException(__('blox_tpl_media_invalid'));
            }
            $out[] = ['path' => $path, 'target' => self::TARGET_DIR . '/' . substr($sha, 0, 24) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext),
                'mime' => $mime, 'sha256' => $sha, 'bytes' => $bytes];
        }
        return $out;
    }

    /** @param list<array{path:string,target:string,mime:string,sha256:string,bytes:string}> $media @return array<string,string> 源相对路径 → 本站相对路径 */
    public static function referenceMap(array $media): array
    {
        $map = [];
        foreach ($media as $item) $map[$item['path']] = $item['target'];
        return $map;
    }

    /**
     * 写入文件（内容寻址：已存在同哈希文件则复用）。返回本次新写的绝对路径，供失败时回滚删除。
     *
     * @param list<array{path:string,target:string,mime:string,sha256:string,bytes:string}> $media
     * @return list<string>
     */
    public static function writeFiles(array $media, ?string $uploadsDir = null): array
    {
        $uploadsDir ??= ROOT_PATH . '/uploads';
        $written = [];
        try {
            foreach ($media as $item) {
                $file = $uploadsDir . '/' . $item['target'];
                if (is_file($file) && hash_file('sha256', $file) === $item['sha256']) continue;
                if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
                    throw new RuntimeException(__('blox_tpl_media_write_failed'));
                }
                $temporary = $file . '.part-' . bin2hex(random_bytes(4));
                if (file_put_contents($temporary, $item['bytes'], LOCK_EX) !== strlen($item['bytes']) || !rename($temporary, $file)) {
                    @unlink($temporary);
                    throw new RuntimeException(__('blox_tpl_media_write_failed'));
                }
                $written[] = $file;
            }
        } catch (Throwable $e) {
            self::removeFiles($written);
            throw $e;
        }
        return $written;
    }

    /** @param list<string> $files */
    public static function removeFiles(array $files): void
    {
        foreach ($files as $file) @unlink($file);
    }

    /**
     * 登记进媒体库（同一路径已登记则跳过），调用方负责事务。
     *
     * @param list<array{path:string,target:string,mime:string,sha256:string,bytes:string}> $media
     */
    public static function register(array $media, int $adminId, ?string $uploadsDir = null): void
    {
        $uploadsDir ??= ROOT_PATH . '/uploads';
        foreach ($media as $item) {
            $url = '/uploads/' . $item['target'];
            if (db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'media WHERE url = ?', [$url])) continue;
            $size = getimagesizefromstring($item['bytes']) ?: [0, 0];
            db()->insert('media', [
                'name' => mb_substr(basename($item['path']), 0, 255),
                'path' => $uploadsDir . '/' . $item['target'],
                'url' => $url,
                'type' => 'image',
                'ext' => strtolower(pathinfo($item['target'], PATHINFO_EXTENSION)),
                'mime' => $item['mime'],
                'size' => strlen($item['bytes']),
                'width' => (int) ($size[0] ?? 0),
                'height' => (int) ($size[1] ?? 0),
                'md5' => md5($item['bytes']),
                'admin_id' => $adminId,
                'created_at' => time(),
            ]);
        }
    }

    /** 相对 uploads/ 的安全路径：字母数字与 ._-/，无回溯、无绝对路径、无隐藏段。 */
    private static function safeRelative(string $path): bool
    {
        return strlen($path) <= 200
            && preg_match('#^[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D', $path) === 1
            && !str_contains($path, '..');
    }

    /** PHP 8.0 没有 array_is_list()。 */
    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private static function imageMime(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        return is_array($info) && is_string($info['mime'] ?? null) ? $info['mime'] : null;
    }
}
