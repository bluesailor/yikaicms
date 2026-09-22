<?php

declare(strict_types=1);

/**
 * 表单附件使用私有 storage，而不是可执行的 uploads Web 目录。
 * 数据库只保存相对引用；真实路径永不进入表单、邮件或导出数据。
 */
final class FormUploadService
{
    private const PREFIX = '@form_file:';
    private const MAX_FILE_BYTES = 10485760;
    private const MAX_TOTAL_BYTES = 20971520;
    private const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    private string $root;
    private Closure $isUploaded;
    private Closure $move;

    public function __construct(?string $root = null, ?Closure $isUploaded = null, ?Closure $move = null)
    {
        $storage = defined('STORAGE_PATH') ? (string) STORAGE_PATH : dirname(__DIR__) . '/storage';
        $this->root = rtrim($root ?? $storage . '/form_uploads', '/\\');
        $this->isUploaded = $isUploaded ?? static fn(string $path): bool => is_uploaded_file($path);
        $this->move = $move ?? static fn(string $from, string $to): bool => move_uploaded_file($from, $to);
    }

    /** @return array{reference:string,fingerprint:string,size:int} */
    public function store(array $file, array $field, int &$totalBytes): array
    {
        if (!isset($file['error'], $file['tmp_name'], $file['name']) || is_array($file['error'])
            || is_array($file['tmp_name']) || is_array($file['name'])) {
            throw new RuntimeException('form_upload_failed');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !($this->isUploaded)((string) $file['tmp_name'])) {
            throw new RuntimeException('form_upload_failed');
        }
        $original = (string) $file['name'];
        if ($original === '' || !mb_check_encoding($original, 'UTF-8')
            || basename(str_replace('\\', '/', $original)) !== $original
            || preg_match('/[\x00-\x1F\x7F]/', $original)) {
            throw new RuntimeException('form_upload_name');
        }
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $configured = $field['accept'] ?? [];
        if (!is_array($configured) || $configured === [] || !isset(self::MIME_MAP[$ext]) || !in_array($ext, $configured, true)) {
            throw new RuntimeException('form_upload_type');
        }
        $limitMb = max(1, min(10, (int) ($field['max_size'] ?? 0)));
        $limit = min(self::MAX_FILE_BYTES, $limitMb * 1048576);
        // Cheap preflight preserves the no-move behavior for obvious oversize requests;
        // the authoritative checks are repeated on the private random target below.
        $preflightSize = @filesize((string) $file['tmp_name']);
        if ($preflightSize === false || $preflightSize <= 0 || $preflightSize > $limit) throw new RuntimeException('form_upload_size');
        if ($totalBytes + $preflightSize > self::MAX_TOTAL_BYTES) throw new RuntimeException('form_upload_total');

        $relativeDir = date('Y/m');
        $directory = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('form_upload_failed');
        }
        do {
            $filename = bin2hex(random_bytes(16)) . '.' . $ext;
            $relative = $relativeDir . '/' . $filename;
            $target = $directory . DIRECTORY_SEPARATOR . $filename;
        } while (is_file($target));
        $root = $this->canonicalRoot();
        $canonicalDirectory = realpath($directory);
        if ($canonicalDirectory === false || !$this->within($canonicalDirectory, $root) || is_link($directory)) {
            throw new RuntimeException('form_upload_failed');
        }
        $tmp = (string) $file['tmp_name'];
        if (!($this->move)($tmp, $target)) throw new RuntimeException('form_upload_failed');
        try {
            @chmod($target, 0640);
            $canonicalTarget = realpath($target);
            if ($canonicalTarget === false || !$this->within($canonicalTarget, $root) || is_link($target) || !is_file($canonicalTarget)) {
                throw new RuntimeException('form_upload_failed');
            }
            $size = @filesize($canonicalTarget);
            if ($size === false || $size <= 0 || $size > $limit) throw new RuntimeException('form_upload_size');
            if ($totalBytes + $size > self::MAX_TOTAL_BYTES) throw new RuntimeException('form_upload_total');
            if (!function_exists('finfo_open')) throw new RuntimeException('form_upload_unavailable');
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $canonicalTarget) : false;
            if ($finfo) finfo_close($finfo);
            if (!is_string($mime) || !in_array($mime, self::MIME_MAP[$ext], true)) throw new RuntimeException('form_upload_mime');
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $image = @getimagesize($canonicalTarget);
                if ($image === false || !in_array((string) ($image['mime'] ?? ''), self::MIME_MAP[$ext], true)) {
                    throw new RuntimeException('form_upload_mime');
                }
            }
            $fingerprint = hash_file('sha256', $canonicalTarget);
            if ($fingerprint === false) throw new RuntimeException('form_upload_failed');
            $reference = self::encodeReference([
                'path' => $relative, 'name' => mb_substr($original, 0, 180), 'mime' => $mime, 'size' => $size,
            ]);
        } catch (Throwable $error) {
            @unlink($target);
            if ($error instanceof RuntimeException) throw $error;
            throw new RuntimeException('form_upload_failed', 0, $error);
        }
        $totalBytes += $size;
        return ['reference' => $reference, 'fingerprint' => $fingerprint, 'size' => $size];
    }

    /** @param array{path:string,name:string,mime:string,size:int} $metadata */
    private static function encodeReference(array $metadata): string
    {
        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{path:string,name:string,mime:string,size:int}|null */
    public static function decodeReference(string $reference): ?array
    {
        if (!str_starts_with($reference, self::PREFIX)) return null;
        $encoded = substr($reference, strlen(self::PREFIX));
        $padding = strlen($encoded) % 4;
        if ($padding > 0) $encoded .= str_repeat('=', 4 - $padding);
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) return null;
        $data = json_decode($json, true);
        if (!is_array($data)) return null;
        $path = (string) ($data['path'] ?? '');
        $name = (string) ($data['name'] ?? '');
        $mime = (string) ($data['mime'] ?? '');
        $size = (int) ($data['size'] ?? 0);
        if (!preg_match('~^\d{4}/\d{2}/[a-f0-9]{32}\.(pdf|jpe?g|png|webp)$~D', $path)
            || $name === '' || basename(str_replace('\\', '/', $name)) !== $name || $size <= 0) {
            return null;
        }
        return ['path' => $path, 'name' => $name, 'mime' => $mime, 'size' => $size];
    }

    public function pathForReference(string $reference): ?string
    {
        $metadata = self::decodeReference($reference);
        if ($metadata === null) return null;
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $metadata['path']);
        $root = realpath($this->root);
        $parent = realpath(dirname($path));
        $target = realpath($path);
        if ($root === false || $parent === false || $target === false || is_link($path)
            || !$this->within($parent, $root) || !$this->within($target, $root) || !is_file($target)) return null;
        return $target;
    }

    public function remove(string $reference): void
    {
        $path = $this->pathForReference($reference);
        if ($path !== null) @unlink($path);
    }

    /** @return list<string> */
    public static function referencesFromExtra(string $extraJson): array
    {
        $extra = json_decode($extraJson, true);
        if (!is_array($extra)) return [];
        return array_values(array_filter($extra, static fn(mixed $value): bool => is_string($value) && self::decodeReference($value) !== null));
    }

    /** @param list<string> $references @return list<array{original:string,trash:string}> */
    public function stageRemoval(array $references): array
    {
        $root = $this->canonicalRoot();
        $trashDirectory = $root . DIRECTORY_SEPARATOR . '.trash';
        if (!is_dir($trashDirectory) && !@mkdir($trashDirectory, 0750, true) && !is_dir($trashDirectory)) {
            throw new RuntimeException('form_upload_cleanup_failed');
        }
        $trashRoot = realpath($trashDirectory);
        if ($trashRoot === false || !$this->within($trashRoot, $root) || is_link($trashDirectory)) {
            throw new RuntimeException('form_upload_cleanup_failed');
        }
        $staged = [];
        try {
            foreach (array_values(array_unique($references)) as $reference) {
                $original = $this->pathForReference($reference);
                if ($original === null) continue;
                $trash = $trashRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.pending';
                if (!@rename($original, $trash)) throw new RuntimeException('form_upload_cleanup_failed');
                $staged[] = ['original' => $original, 'trash' => $trash];
            }
        } catch (Throwable $error) {
            $this->restoreStaged($staged);
            throw $error;
        }
        return $staged;
    }

    /** @param list<array{original:string,trash:string}> $staged */
    public function restoreStaged(array $staged): void
    {
        foreach (array_reverse($staged) as $item) {
            if (is_file($item['trash'])) @rename($item['trash'], $item['original']);
        }
    }

    /** @param list<array{original:string,trash:string}> $staged */
    public function finalizeStaged(array $staged): void
    {
        foreach ($staged as $item) {
            if (is_file($item['trash']) && !@unlink($item['trash'])) error_log('Form upload trash cleanup failed');
        }
    }

    private function canonicalRoot(): string
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0750, true) && !is_dir($this->root)) {
            throw new RuntimeException('form_upload_failed');
        }
        $root = realpath($this->root);
        if ($root === false) throw new RuntimeException('form_upload_failed');
        return rtrim($root, '/\\');
    }

    private function within(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $root, strlen($root)) === 0
            : strncmp($path, $root, strlen($root)) === 0;
    }
}
