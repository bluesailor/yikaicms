<?php
declare(strict_types=1);

// 仅比较同一源码文件集合的 PHPUnit 行覆盖率；浏览器、JS 和升级冒烟不在此指标内。
[$script, $cloverPath, $outputPath, $baselinePath] = array_pad($argv, 4, '');
if ($cloverPath === '' || $outputPath === '') {
    fwrite(STDERR, "Usage: php tools/coverage-baseline.php <clover.xml> <summary.json> [baseline.json]\n");
    exit(2);
}

try {
    $document = new DOMDocument();
    if (!is_file($cloverPath) || !$document->load($cloverPath, LIBXML_NONET)) {
        throw new RuntimeException('Clover report is unavailable or invalid');
    }
    $xpath = new DOMXPath($document);
    $metrics = $xpath->query('/coverage/project/metrics')->item(0);
    if (!$metrics instanceof DOMElement) {
        throw new RuntimeException('Clover project metrics are missing');
    }
    $statements = (int) $metrics->getAttribute('statements');
    $covered = (int) $metrics->getAttribute('coveredstatements');
    if ($statements <= 0 || $covered < 0 || $covered > $statements) {
        throw new RuntimeException('Clover statement counts are invalid');
    }

    $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__))) . '/';
    $sourceFiles = [];
    foreach ($xpath->query('/coverage/project//file') as $file) {
        if (!$file instanceof DOMElement) {
            continue;
        }
        $name = str_replace('\\', '/', $file->getAttribute('name'));
        if (str_starts_with($name, $root)) {
            $name = substr($name, strlen($root));
        }
        $sourceFiles[] = $name;
    }
    $sourceFiles = array_values(array_unique($sourceFiles));
    sort($sourceFiles, SORT_STRING);
    if ($sourceFiles === []) {
        throw new RuntimeException('Clover has no source files');
    }

    $summary = [
        'metric' => 'PHPUnit line coverage, percentage points',
        'line_coverage_percent' => round($covered * 100 / $statements, 4),
        'covered_statements' => $covered,
        'statements' => $statements,
        'source_file_count' => count($sourceFiles),
        'source_files_sha256' => hash('sha256', implode("\n", $sourceFiles)),
        'source_config_sha256' => hash_file('sha256', $root . 'phpunit.xml'),
        'commit' => getenv('GITHUB_SHA') ?: '',
        'generated_utc' => gmdate('c'),
    ];

    if ($baselinePath !== '') {
        if (!is_file($baselinePath)) {
            throw new RuntimeException('Coverage baseline is unavailable');
        }
        $baseline = json_decode((string) file_get_contents($baselinePath), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($baseline)
            || ($baseline['source_files_sha256'] ?? '') !== $summary['source_files_sha256']
            || ($baseline['source_config_sha256'] ?? '') !== $summary['source_config_sha256']
            || ($baseline['statements'] ?? -1) !== $statements) {
            throw new RuntimeException('Coverage source scope changed; establish a new baseline');
        }
        $previousCovered = $baseline['covered_statements'] ?? -1;
        if (!is_int($previousCovered) || $previousCovered < 0 || $previousCovered > $statements) {
            throw new RuntimeException('Baseline covered statement count is invalid');
        }
        // 用原始计数判断阈值，避免先四舍五入后把略超 2 个百分点的下降当作通过。
        $changePp = ($covered - $previousCovered) * 100 / $statements;
        $summary['change_pp'] = round($changePp, 4);
    }

    $directory = dirname($outputPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        throw new RuntimeException('Cannot create summary directory');
    }
    $encoded = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($outputPath, $encoded) !== strlen($encoded)) {
        throw new RuntimeException('Cannot write coverage summary');
    }
    echo 'PHPUnit line coverage: ' . $summary['line_coverage_percent'] . '% (' . $covered . '/' . $statements . ')';
    if (isset($summary['change_pp'])) {
        echo ', change ' . $summary['change_pp'] . ' percentage points';
    }
    echo PHP_EOL;
    if (isset($changePp) && $changePp < -2.0) {
        throw new RuntimeException('Coverage drop exceeds 2 percentage points');
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
