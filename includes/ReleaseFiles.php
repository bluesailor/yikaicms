<?php
/**
 * YikaiCMS —— 发行包文件清单与「本站改过的核心文件」体检（2.0.3，WP-09）。
 *
 * 打包时（build.sh）把包内每个文件的哈希写进 config/release-files.php，随包分发。
 * 升级前拿它与本站现有文件比较：哈希对不上 = 站点改过这个文件。若新包也要写这个文件
 * （且内容与本站现有的不同），升级就会静默盖掉站点的定制——页面照常 200、不报错，
 * 只是少了东西。老站把定制写进核心文件的事故多次发生，这里在写任何文件之前把它拦下来。
 *
 * 只在本地比较哈希，不上传任何文件内容。哈希前把 CRLF 统一成 LF：FTP 文本模式上传
 * 会改行尾，不能因此把整站都判成「改过」。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class ReleaseFiles
{
    public const FILE = 'config/release-files.php';

    public static function hash(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    /**
     * 为打包目录生成清单（清单文件自身不在其中）。
     *
     * @return array{schema: int, version: string, files: array<string, string>}
     * @psalm-suppress PossiblyUnusedMethod 调用方是 tools/build-release-files.php（不在 Psalm projectFiles 内）
     */
    public static function build(string $packageRoot, string $version): array
    {
        $packageRoot = rtrim(str_replace('\\', '/', $packageRoot), '/');
        if ($packageRoot === '' || !is_dir($packageRoot)) {
            throw new InvalidArgumentException('Package root does not exist.');
        }
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($packageRoot) + 1);
            if ($rel === self::FILE) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                throw new RuntimeException('Unable to read package file: ' . $rel);
            }
            $files[$rel] = self::hash($content);
        }
        ksort($files, SORT_STRING);
        return ['schema' => 1, 'version' => $version, 'files' => $files];
    }

    /**
     * 读本站的清单；没有（2.0.2 及更早、开发工作树）或与当前版本对不上时返回 null——
     * 此时无法判断，调用方按「未能检查」处理，不能当作「没有改动」。
     *
     * @return array<string, string>|null
     */
    public static function load(string $root, string $currentVersion): ?array
    {
        $file = rtrim($root, '/\\') . '/' . self::FILE;
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = require $file;
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_array($data['files'] ?? null)
            || $currentVersion === '' || (string) ($data['version'] ?? '') !== $currentVersion) {
            return null;
        }
        return $data['files'];
    }

    /**
     * 新包要写入、而本站改过的文件。
     *
     * 判定：本站存在该文件，且内容与本版本出厂哈希不同（清单里没有的也算——站点自己
     * 放了个同名文件），且新包写入的内容与本站现有的也不同（相同就谈不上覆盖）。
     *
     * @param array<string, string> $manifest 本站当前版本的出厂哈希
     * @param list<string> $rels 新包要写入的相对路径（已排除受保护路径）
     * @param callable(string): (string|false) $readIncoming 读新包里该文件的内容
     * @return list<string>
     */
    public static function localChanges(string $root, array $manifest, array $rels, callable $readIncoming): array
    {
        $root = rtrim($root, '/\\');
        $changed = [];
        foreach ($rels as $rel) {
            $local = $root . '/' . $rel;
            if (!is_file($local)) {
                continue;
            }
            $content = file_get_contents($local);
            if ($content === false) {
                continue;
            }
            $localHash = self::hash($content);
            $expected = $manifest[$rel] ?? null;
            if (is_string($expected) && hash_equals($expected, $localHash)) {
                continue;
            }
            $incoming = $readIncoming($rel);
            if ($incoming !== false && hash_equals(self::hash($incoming), $localHash)) {
                continue;
            }
            $changed[] = $rel;
        }
        sort($changed, SORT_STRING);
        return $changed;
    }

    /**
     * 当前各文件的本地哈希：自动升级因改动被拦下后，站长把改动迁走（文件变了）才值得再试，
     * 否则每小时都重新下载、重新备份数据库再被拦一次。
     *
     * @param list<string> $rels
     * @return array<string, string>
     */
    public static function localHashes(string $root, array $rels): array
    {
        $out = [];
        foreach ($rels as $rel) {
            $content = @file_get_contents(rtrim($root, '/\\') . '/' . $rel);
            $out[$rel] = $content === false ? '' : self::hash($content);
        }
        return $out;
    }
}
