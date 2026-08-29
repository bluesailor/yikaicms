<?php

declare(strict_types=1);

final class ReleaseArtifactSmoke
{
    /** @var array{required_files:list<string>,generated_files?:list<string>,forbidden_paths:list<string>} */
    private array $manifest;

    /** @param array{required_files:list<string>,generated_files?:list<string>,forbidden_paths:list<string>} $manifest */
    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
    }

    /** @return list<string> */
    public function inspect(string $artifact): array
    {
        if (is_dir($artifact)) {
            return $this->inspectDirectory($artifact);
        }
        if (!is_file($artifact)) {
            return ['Artifact does not exist: ' . $artifact];
        }
        return $this->inspectZip($artifact);
    }

    /** @return list<string> */
    public function inspectDirectory(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $errors = [];

        foreach ($this->requiredFiles() as $path) {
            if (!is_file($root . '/' . $path)) {
                $errors[] = 'Missing runtime file: ' . $path;
            }
        }
        foreach ($this->manifest['forbidden_paths'] as $path) {
            if (file_exists($root . '/' . $path)) {
                $errors[] = 'Forbidden release path: ' . $path;
            }
        }

        foreach ($this->requiredFiles() as $path) {
            if (!str_ends_with($path, '.php') || !is_file($root . '/' . $path)) {
                continue;
            }
            $lint = $this->runPhp([ '-l', $root . '/' . $path ]);
            if ($lint['exit'] !== 0) {
                $errors[] = 'PHP syntax check failed: ' . $path . ' (' . trim($lint['output']) . ')';
            }
        }

        $probe = $this->runRuntimeProbe($root);
        if ($probe['exit'] !== 0) {
            $errors[] = 'Runtime probe failed: ' . trim($probe['output']);
        }

        return $errors;
    }

    /** @return list<string> */
    private function requiredFiles(): array
    {
        return array_values(array_unique(array_merge(
            $this->manifest['required_files'],
            $this->manifest['generated_files'] ?? []
        )));
    }

    /** @return list<string> */
    private function inspectZip(string $zipPath): array
    {
        if (!class_exists(ZipArchive::class)) {
            return ['PHP ZipArchive extension is required for artifact smoke tests'];
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            return ['Cannot open release ZIP: ' . $zipPath . ' (code ' . $opened . ')'];
        }

        $roots = [];
        $errors = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:\//', $name)) {
                $errors[] = 'Unsafe ZIP entry: ' . $name;
                continue;
            }
            $segments = explode('/', trim($name, '/'));
            if (in_array('..', $segments, true)) {
                $errors[] = 'Unsafe ZIP traversal entry: ' . $name;
                continue;
            }
            if ($segments[0] !== '') {
                $roots[$segments[0]] = true;
            }
        }
        if (count($roots) !== 1) {
            $errors[] = 'Release ZIP must contain exactly one top-level directory';
        }
        if ($errors !== []) {
            $zip->close();
            return $errors;
        }

        $temp = rtrim(sys_get_temp_dir(), '\\/') . '/yikaicms-artifact-' . bin2hex(random_bytes(6));
        if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
            $zip->close();
            return ['Cannot create artifact smoke directory: ' . $temp];
        }

        try {
            if (!$zip->extractTo($temp)) {
                return ['Cannot extract release ZIP: ' . $zipPath];
            }
            $rootName = (string) array_key_first($roots);
            return $this->inspectDirectory($temp . '/' . $rootName);
        } finally {
            $zip->close();
            $this->removeTree($temp);
        }
    }

    /** @return array{exit:int,output:string} */
    private function runRuntimeProbe(string $root): array
    {
        $probe = <<<'PHP'
<?php
declare(strict_types=1);

$root = $argv[1] ?? '';
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "invalid package root\n");
    exit(2);
}
define('ROOT_PATH', $root);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/UrlPolicy.php';
require_once $root . '/includes/HtmlPolicy.php';
require_once $root . '/includes/builder/BuilderRegistry.php';
require_once $root . '/includes/builder/BloxValueSanitizer.php';

$slug = generateSlug('中国制造');
if ($slug === '' || str_starts_with($slug, 'item-') || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    fwrite(STDERR, 'Chinese slug probe returned an unreadable value: ' . $slug . "\n");
    exit(1);
}
foreach ([UrlPolicy::class, HtmlPolicy::class, BuilderRegistry::class, BloxValueSanitizer::class] as $class) {
    if (!class_exists($class, false)) {
        fwrite(STDERR, 'Runtime class did not load: ' . $class . "\n");
        exit(1);
    }
}
fwrite(STDOUT, $slug . "\n");
PHP;

        $probeFile = tempnam(sys_get_temp_dir(), 'yk-artifact-probe-');
        if ($probeFile === false) {
            return ['exit' => 2, 'output' => 'Cannot create runtime probe'];
        }
        file_put_contents($probeFile, $probe);
        try {
            return $this->runPhp([$probeFile, $root]);
        } finally {
            @unlink($probeFile);
        }
    }

    /** @param list<string> $arguments @return array{exit:int,output:string} */
    private function runPhp(array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return ['exit' => 2, 'output' => 'Cannot start PHP subprocess'];
        }
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return ['exit' => $exit, 'output' => trim((string) $stdout . "\n" . (string) $stderr)];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
