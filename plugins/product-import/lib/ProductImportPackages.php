<?php
/**
 * 导入包存放：storage/product-import/
 *   packages/<id>/      上传的 zip 解压到这里（_package.json 记名称、来源、时间）
 *   incoming/           大包用 FTP 传到这里（文件夹或 zip），向导里直接选，不受 PHP 上传大小限制
 *   jobs/               任务记录（ProductImportJobs）
 *
 * 解压只收导入包用得到的文件（清单与图片），不收 ../、绝对路径、符号链接；总量与文件数有上限。
 */

declare(strict_types=1);

final class ProductImportPackages
{
    public const MAX_FILES = 20000;
    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;
    public const KEEP_DAYS = 14;
    private const EXTENSIONS = ['json', 'csv', 'xlsx', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    private const MANIFESTS = ['products.json', 'products.xlsx', 'products.csv'];

    public function __construct(private string $root)
    {
    }

    public function incomingDir(): string
    {
        return $this->root . '/incoming';
    }

    public function jobsDir(): string
    {
        return $this->root . '/jobs';
    }

    /**
     * 解压一个 zip 成为新包。失败抛 RuntimeException（消息是错误代码）。
     * @return array{id:string,name:string,dir:string,source:string,created_at:int}
     */
    public function extractZip(string $zipFile, string $name, string $source = 'upload'): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('zip_unavailable');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('zip_unreadable');
        }
        $this->prepare();
        $id = ProductImportJobs::newId();
        $dir = $this->root . '/packages/' . $id;
        mkdir($dir, 0755, true);
        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('zip_too_many_files');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($entry === '' || str_ends_with($entry, '/') || str_starts_with($entry, '__MACOSX/')) {
                    continue;
                }
                if (str_starts_with($entry, '/') || preg_match('~(^|/)\.\.(/|$)~', $entry) || preg_match('/^[a-z]:/i', $entry) || str_contains($entry, "\0")) {
                    throw new RuntimeException('zip_bad_path');
                }
                // zip 里的符号链接（unix 模式 0120000）不收
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('zip_bad_path');
                }
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (!in_array($ext, self::EXTENSIONS, true) || basename($entry)[0] === '.') {
                    continue;
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_BYTES) {
                    throw new RuntimeException('zip_too_large');
                }
                $target = $dir . '/' . $entry;
                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                $in = $zip->getStream($stat['name']);
                if ($in === false) {
                    throw new RuntimeException('zip_unreadable');
                }
                $out = fopen($target, 'wb');
                $written = stream_copy_to_stream($in, $out, self::MAX_BYTES);
                fclose($in);
                fclose($out);
                if ($written === false || $written > (int) ($stat['size'] ?? 0) + 1) {
                    throw new RuntimeException('zip_unreadable');
                }
            }
            $zip->close();
        } catch (RuntimeException $e) {
            $zip->close();
            self::removeDir($dir);
            throw $e;
        }
        $package = ['id' => $id, 'name' => $name, 'dir' => $this->packageRoot($dir), 'source' => $source, 'created_at' => time()];
        file_put_contents($dir . '/_package.json', json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $package;
    }

    /**
     * 只有一张表（图片都用本站已有的 /uploads/…）：表本身就是包。
     * @return array{id:string,name:string,dir:string,source:string,created_at:int}
     */
    public function fromTable(string $file, string $ext, string $name): array
    {
        if (!in_array($ext, ['json', 'csv', 'xlsx'], true)) {
            throw new RuntimeException('package_bad_type');
        }
        $this->prepare();
        $id = ProductImportJobs::newId();
        $dir = $this->root . '/packages/' . $id;
        mkdir($dir, 0755, true);
        if (!copy($file, $dir . '/products.' . $ext)) {
            self::removeDir($dir);
            throw new RuntimeException('package_save_failed');
        }
        $package = ['id' => $id, 'name' => $name, 'dir' => $dir, 'source' => 'upload', 'created_at' => time()];
        file_put_contents($dir . '/_package.json', json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $package;
    }

    /**
     * incoming/ 里的一项（文件夹或 zip）登记成包。文件夹直接用、不复制。
     * @return array{id:string,name:string,dir:string,source:string,created_at:int}
     */
    public function useIncoming(string $name): array
    {
        if ($name === '' || $name !== basename($name) || $name[0] === '.') {
            throw new RuntimeException('incoming_missing');
        }
        $path = $this->incomingDir() . '/' . $name;
        if (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
            return $this->extractZip($path, $name, 'incoming');
        }
        if (!is_dir($path) || is_link($path)) {
            throw new RuntimeException('incoming_missing');
        }
        $this->prepare();
        $id = ProductImportJobs::newId();
        $dir = $this->root . '/packages/' . $id;
        mkdir($dir, 0755, true);
        $package = ['id' => $id, 'name' => $name, 'dir' => $this->packageRoot($path), 'source' => 'incoming', 'created_at' => time()];
        file_put_contents($dir . '/_package.json', json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $package;
    }

    /** @return array{id:string,name:string,dir:string,source:string,created_at:int}|null */
    public function get(string $id): ?array
    {
        if (!ProductImportJobs::validId($id)) {
            return null;
        }
        $file = $this->root . '/packages/' . $id . '/_package.json';
        $package = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($package) && is_dir((string) ($package['dir'] ?? '')) ? $package : null;
    }

    /** @return list<array{name:string,type:string,modified:int}> */
    public function incoming(): array
    {
        $list = [];
        foreach (glob($this->incomingDir() . '/*') ?: [] as $path) {
            $name = basename($path);
            if ($name[0] === '.' || is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                $list[] = ['name' => $name, 'type' => 'folder', 'modified' => (int) filemtime($path)];
            } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
                $list[] = ['name' => $name, 'type' => 'zip', 'modified' => (int) filemtime($path)];
            }
        }
        usort($list, static fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $list;
    }

    /** 清掉过期的解压目录（任务记录不动，撤销用不着包）。 */
    public function cleanup(): void
    {
        foreach (glob($this->root . '/packages/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - self::KEEP_DAYS * 86400) {
                self::removeDir($dir);
            }
        }
    }

    /** 清单可能在 zip 的一层子文件夹里（打包时连文件夹一起压）：找到真正的包根。 */
    private function packageRoot(string $dir): string
    {
        foreach (self::MANIFESTS as $manifest) {
            if (is_file($dir . '/' . $manifest)) {
                return $dir;
            }
        }
        $children = array_values(array_filter(glob($dir . '/*', GLOB_ONLYDIR) ?: [], static fn (string $d): bool => basename($d) !== 'images'));
        if (count($children) === 1) {
            foreach (self::MANIFESTS as $manifest) {
                if (is_file($children[0] . '/' . $manifest)) {
                    return $children[0];
                }
            }
        }
        return $dir;
    }

    private function prepare(): void
    {
        foreach ([$this->root . '/packages', $this->incomingDir()] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        if (!is_file($this->root . '/.htaccess')) {
            file_put_contents($this->root . '/.htaccess', "Require all denied\nDeny from all\n");
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
