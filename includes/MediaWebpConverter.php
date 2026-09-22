<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTemplateData.php';
require_once __DIR__ . '/UploadReferences.php';
require_once __DIR__ . '/image.php';

/** @psalm-api Converts local PNG/JPEG uploads and atomically repoints every portable content reference. */
final class MediaWebpConverter
{
    private string $uploads;
    private string $siteOrigin;
    private const SOURCE_CONTRACT_SUFFIX = '.yikai-source.json';

    // 恢复草稿或历史版本后也不能重新引入已转换的图片；仅修改文档字段。
    private const LOCAL_DOCUMENT_FIELDS = [
        'blox_page_drafts' => ['draft_data', 'published_data'],
        'content_revisions' => ['snapshot'],
    ];

    public function __construct(string $root, string $siteOrigin = '')
    {
        $resolved = realpath($root);
        if ($resolved === false) {
            throw new RuntimeException('站点目录不存在');
        }
        $rootPath = str_replace('\\', '/', $resolved);
        $uploads = realpath($rootPath . '/uploads');
        if ($uploads === false || !is_dir($uploads)) {
            throw new RuntimeException('uploads 目录不存在');
        }
        $this->uploads = str_replace('\\', '/', $uploads);
        $this->siteOrigin = $siteOrigin !== '' ? rtrim($siteOrigin, '/')
            : (function_exists('siteBaseUrl') ? siteBaseUrl() : '');
    }

    /**
     * @return array{dry_run:bool,scanned:int,pending:int,converted:int,reused:int,saved_bytes:int,reference_changes:int,missing_references:int,missing_paths:list<string>}
     */
    public function run(bool $apply = false, int $quality = 85): array
    {
        if (!function_exists('imagewebp')) {
            throw new RuntimeException('当前 PHP GD 未启用 WebP 支持');
        }
        $quality = max(50, min(95, $quality));
        $files = $this->discoverFiles();
        $map = [];
        $pending = [];
        $reused = 0;
        $reuseChecks = [];
        $savedBytes = 0;
        $targets = [];
        $megapixels = uploadMaxImageMegapixels();
        $maxPixels = $megapixels === 0 ? 0 : $megapixels * 1000000;

        foreach ($files as $relative => $source) {
            $targetRelative = (string) preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $relative);
            $target = $this->uploads . '/' . $targetRelative;
            $targetKey = strtolower($targetRelative);
            if (isset($targets[$targetKey]) && $targets[$targetKey] !== $relative) {
                throw new RuntimeException('多个源文件会写入同一 WebP：' . $relative);
            }
            $targets[$targetKey] = $relative;
            $info = @getimagesize($source);
            $extension = match (is_array($info) ? ($info['mime'] ?? '') : '') {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => throw new RuntimeException('源文件不是有效 PNG/JPEG 图片：' . $relative),
            };
            $width = (int) $info[0];
            $height = (int) $info[1];
            if (!imageDimensionsWithinPixelLimit($width, $height, $maxPixels)) {
                throw new RuntimeException('源图片超过像素上限：' . $relative);
            }
            $sourceHash = (string) hash_file('sha256', $source);
            $contract = $target . self::SOURCE_CONTRACT_SUFFIX;
            $map[$relative] = $targetRelative;
            if (is_file($target)) {
                if (!$this->validWebp($target)) {
                    throw new RuntimeException('同名 WebP 已存在但不是有效图片：' . $targetRelative);
                }
                $targetHash = (string) hash_file('sha256', $target);
                $contractHash = $this->verifySourceContract($contract, $sourceHash, $targetHash, $quality, $width, $height, $targetRelative);
                $reuseChecks[] = ['source' => $source, 'target' => $target, 'contract' => $contract,
                    'source_hash' => $sourceHash, 'target_hash' => $targetHash, 'contract_hash' => $contractHash];
                $reused++;
                continue;
            }
            if (is_file($contract)) {
                throw new RuntimeException('WebP 来源契约存在但图片缺失：' . $targetRelative);
            }
            $pending[$relative] = [
                'source' => $source,
                'target' => $target,
                'temporary' => $target . '.convert-' . bin2hex(random_bytes(6)),
                'contract' => $contract,
                'contract_temporary' => $contract . '.convert-' . bin2hex(random_bytes(6)),
                'sha256' => $sourceHash,
                'extension' => $extension,
                'width' => $width,
                'height' => $height,
            ];
        }

        [$updates, $referenceChanges, $missing] = $this->referencePlan($map);
        $report = [
            'dry_run' => !$apply,
            'scanned' => count($files),
            'pending' => count($pending),
            'converted' => 0,
            'reused' => $reused,
            'saved_bytes' => max(0, $savedBytes),
            'reference_changes' => $referenceChanges,
            'missing_references' => array_sum($missing),
            'missing_paths' => array_values(array_keys($missing)),
        ];
        if (!$apply) {
            return $report;
        }

        $created = [];
        try {
            foreach ($pending as $relative => $item) {
                if (!convertToWebp($item['source'], $item['temporary'], $item['extension'], $quality)) {
                    throw new RuntimeException('转换失败：' . $relative);
                }
                $convertedInfo = @getimagesize($item['temporary']);
                if (!$this->validWebp($item['temporary']) || !is_array($convertedInfo)
                    || (int) $convertedInfo[0] !== $item['width'] || (int) $convertedInfo[1] !== $item['height']) {
                    throw new RuntimeException('WebP 校验失败：' . $relative);
                }
                $targetHash = (string) hash_file('sha256', $item['temporary']);
                $contractBytes = json_encode($this->sourceContract($item['sha256'], $targetHash, $quality,
                    $item['width'], $item['height']), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                if (@file_put_contents($item['contract_temporary'], $contractBytes, LOCK_EX) !== strlen($contractBytes)) {
                    throw new RuntimeException('无法写入 WebP 来源契约：' . $relative);
                }
                $savedBytes += (int) filesize($item['source']) - (int) filesize($item['temporary']);
            }

            db()->beginTransaction();
            try {
                foreach ($reuseChecks as $item) {
                    if (!hash_equals($item['source_hash'], (string) hash_file('sha256', $item['source']))
                        || !hash_equals($item['target_hash'], (string) hash_file('sha256', $item['target']))
                        || !hash_equals($item['contract_hash'], (string) hash_file('sha256', $item['contract']))) {
                        throw new RuntimeException('复用期间源文件或 WebP 发生变化');
                    }
                }
                foreach ($pending as $relative => $item) {
                    if (!hash_equals($item['sha256'], (string) hash_file('sha256', $item['source']))) {
                        throw new RuntimeException('转换期间源文件发生变化：' . $relative);
                    }
                    if (is_file($item['target']) || is_file($item['contract']) || !@rename($item['temporary'], $item['target'])) {
                        throw new RuntimeException('无法写入 WebP：' . $relative);
                    }
                    $created[] = $item['target'];
                    if (!@rename($item['contract_temporary'], $item['contract'])) {
                        throw new RuntimeException('无法写入 WebP 来源契约：' . $relative);
                    }
                    $created[] = $item['contract'];
                }
                $this->applyUpdates($updates);
                if (!db()->commit()) {
                    throw new RuntimeException('数据库事务提交失败');
                }
            } catch (Throwable $error) {
                if (db()->getPdo()->inTransaction()) {
                    db()->rollback();
                }
                throw $error;
            }
        } catch (Throwable $error) {
            foreach ($pending as $item) {
                @unlink($item['temporary']);
                @unlink($item['contract_temporary']);
            }
            foreach ($created as $target) {
                @unlink($target);
            }
            settingModel()->clearCache();
            throw $error;
        }

        settingModel()->clearCache();
        if ($referenceChanges > 0 && function_exists('do_action')) {
            do_action('data_changed', 'media', 0);
        }
        $report['converted'] = count($pending);
        $report['saved_bytes'] = max(0, $savedBytes);
        return $report;
    }

    /** @return array<string,string> */
    private function discoverFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->uploads, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($this->uploads) + 1);
            if (!$this->safeRelative($relative)) {
                throw new RuntimeException('uploads 中存在不安全路径');
            }
            $files[$relative] = $path;
        }
        ksort($files);
        return $files;
    }

    /**
     * @param array<string,string> $map
     * @return array{0:list<array{table:string,field:string,value:string,new_value:string,key:array<string,int|string>}>,1:int,2:array<string,int>}
     */
    private function referencePlan(array $map): array
    {
        $updates = [];
        $changes = 0;
        $missing = [];
        $tables = array_merge(SiteTemplateData::TABLES, array_keys(self::LOCAL_DOCUMENT_FIELDS));
        foreach ($tables as $table) {
            if (!db()->tableExists($table)) {
                continue;
            }
            foreach (db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table) as $row) {
                $key = $this->rowKey($table, $row);
                if ($key === []) {
                    continue;
                }
                foreach ($row as $field => $value) {
                    if (isset(self::LOCAL_DOCUMENT_FIELDS[$table])
                        && !in_array($field, self::LOCAL_DOCUMENT_FIELDS[$table], true)) {
                        continue;
                    }
                    if (!is_string($value) || $value === '') {
                        continue;
                    }
                    $this->mergeMissing($value, $missing);
                    $fieldChanges = 0;
                    $newValue = UploadReferences::rewrite($value, $map, $fieldChanges, $this->uploads, $this->siteOrigin);
                    if (!is_string($newValue) || $newValue === $value) {
                        continue;
                    }
                    $changes += $fieldChanges;
                    $updates[] = ['table' => $table, 'field' => (string) $field, 'value' => $value,
                        'new_value' => $newValue, 'key' => $key];
                }
            }
        }

        if (db()->tableExists('settings')) {
            foreach (db()->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX . 'settings') as $row) {
                $settingKey = (string) ($row['key'] ?? '');
                $value = (string) ($row['value'] ?? '');
                if (!SiteTemplateData::settingAllowed($settingKey) || $value === '') {
                    continue;
                }
                $this->mergeMissing($value, $missing);
                $fieldChanges = 0;
                $newValue = UploadReferences::rewrite($value, $map, $fieldChanges, $this->uploads, $this->siteOrigin);
                if (!is_string($newValue) || $newValue === $value) {
                    continue;
                }
                $changes += $fieldChanges;
                $updates[] = ['table' => 'settings', 'field' => 'value', 'value' => $value,
                    'new_value' => $newValue, 'key' => ['key' => $settingKey]];
            }
        }
        ksort($missing);
        return [$updates, $changes, $missing];
    }

    /** @param list<array{table:string,field:string,value:string,new_value:string,key:array<string,int|string>}> $updates */
    private function applyUpdates(array $updates): void
    {
        /** @var array<int,bool> $mediaRows */
        $mediaRows = [];
        foreach ($updates as $update) {
            $where = [];
            $params = [];
            foreach ($update['key'] as $field => $value) {
                $where[] = '`' . $field . '` = ?';
                $params[] = $value;
            }
            $where[] = '`' . $update['field'] . '` = ?';
            $params[] = $update['value'];
            $affected = db()->update($update['table'], [$update['field'] => $update['new_value']], implode(' AND ', $where), $params);
            if ($affected !== 1) {
                throw new RuntimeException('引用在转换期间发生变化：' . $update['table'] . '.' . $update['field']);
            }
            if ($update['table'] === 'media' && isset($update['key']['id'])) {
                $mediaRows[(int) $update['key']['id']] = true;
            }
        }

        foreach (array_keys($mediaRows) as $id) {
            $row = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'media WHERE id = ?', [$id]);
            if ($row === null) {
                throw new RuntimeException('媒体记录在转换期间被删除');
            }
            $refs = UploadReferences::collect([$row['url'] ?? '', $row['path'] ?? ''], $this->uploads, $this->siteOrigin);
            $relative = (string) array_key_first($refs);
            if ($relative === '' || !str_ends_with(strtolower($relative), '.webp')) {
                continue;
            }
            $target = $this->uploads . '/' . $relative;
            if (!$this->validWebp($target)) {
                throw new RuntimeException('媒体 WebP 文件缺失：' . $relative);
            }
            $name = (string) ($row['name'] ?? '');
            if (preg_match('/\.(?:jpe?g|png)$/i', $name)) {
                $name = (string) preg_replace('/\.(?:jpe?g|png)$/i', '.webp', $name);
            }
            db()->update('media', [
                'name' => $name,
                'ext' => 'webp',
                'mime' => 'image/webp',
                'size' => (int) filesize($target),
                'md5' => (string) md5_file($target),
            ], 'id = ?', [$id]);
        }
    }

    /** @return array<string,int|string> */
    private function rowKey(string $table, array $row): array
    {
        if (array_key_exists('id', $row)) {
            return ['id' => (int) $row['id']];
        }
        if ($table === 'product_tag_map' && isset($row['product_id'], $row['tag_id'])) {
            return ['product_id' => (int) $row['product_id'], 'tag_id' => (int) $row['tag_id']];
        }
        return [];
    }

    /** @param array<string,int> $missing */
    private function mergeMissing(string $value, array &$missing): void
    {
        foreach (UploadReferences::collect($value, $this->uploads, $this->siteOrigin) as $relative => $count) {
            if (!preg_match('/\.(?:jpe?g|png)$/i', $relative)) {
                continue;
            }
            if (!$this->safeRelative($relative) || !is_file($this->uploads . '/' . $relative)) {
                $missing[$relative] = ($missing[$relative] ?? 0) + $count;
            }
        }
    }

    private function safeRelative(string $relative): bool
    {
        return $relative !== '' && !str_contains($relative, "\0")
            && !str_contains(str_replace('\\', '/', $relative), '../')
            && !str_starts_with(str_replace('\\', '/', $relative), '/');
    }

    /** @return array{format:string,version:int,source_sha256:string,target_sha256:string,quality:int,width:int,height:int} */
    private function sourceContract(string $sourceHash, string $targetHash, int $quality, int $width, int $height): array
    {
        return ['format' => 'yikaicms-webp-source', 'version' => 1, 'source_sha256' => $sourceHash,
            'target_sha256' => $targetHash, 'quality' => $quality, 'width' => $width, 'height' => $height];
    }

    private function verifySourceContract(string $path, string $sourceHash, string $targetHash, int $quality,
        int $width, int $height, string $relative): string
    {
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 2 || $size > 4096) {
            throw new RuntimeException('同名 WebP 缺少有效来源契约：' . $relative);
        }
        $bytes = @file_get_contents($path);
        $decoded = is_string($bytes) ? json_decode($bytes, true) : null;
        if (!is_array($decoded) || $decoded !== $this->sourceContract($sourceHash, $targetHash, $quality, $width, $height)) {
            throw new RuntimeException('同名 WebP 来源契约与当前源图或转换品质不一致：' . $relative);
        }
        return (string) hash_file('sha256', $path);
    }

    private function validWebp(string $path): bool
    {
        $info = @getimagesize($path);
        if (!is_array($info) || strtolower((string) ($info['mime'] ?? '')) !== 'image/webp') {
            return false;
        }
        $image = @imagecreatefromwebp($path);
        if ($image === false) {
            return false;
        }
        imagedestroy($image);
        return true;
    }
}
