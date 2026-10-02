<?php
/**
 * 多语言翻译流水线（2.0.4 起）：把待译文案导出成 JSONL 分片交给翻译模型，校验译文，合回语言包。
 *
 * 翻译模型（GLM 等）不碰仓库，只处理分片：每行一个待译条目，译文写进同一行的 "text" 字段。
 *   export   导出目标语言尚未翻译的键（zh-CN 为原文，en / ja 作参考）
 *   validate 逐行校验译文（行数与顺序、占位符、HTML 标签、残留汉字、术语表）
 *   merge    只合入校验通过的分片；原文在导出后改过的键跳过并列为待重译
 *   status   各语言在核心 / 插件 / 安装器三块的进度与待重译数
 *
 * 三块文案：
 *   core       lang/zh-CN.php              → lang/<code>.php
 *   plugins    plugins/<slug>/lang/zh-CN.php → plugins/<slug>/lang/<code>.php
 *   installer  install/lang/zh.php          → install/lang/<code>.php
 *
 * 原文哈希记在 tools/i18n/hashes/<code>.json（不进安装包），供 status 判断哪些译文已过时。
 *
 * PHP 8.0+，只读写语言包与指定目录，不连数据库。
 */

declare(strict_types=1);

final class I18nPipeline
{
    public const SCOPES = ['core', 'plugins', 'installer'];
    /** 原文 / 人工维护的参考语言，不作为目标（zh-TW 由简体整页转换） */
    private const NOT_TARGETS = ['zh-CN', 'zh-TW', 'en', 'ja'];
    /** 译文中允许出现汉字的语言 */
    private const HAN_ALLOWED = ['ja', 'zh-CN', 'zh-TW'];
    private const BRAND = ['YikaiCMS', 'Yikai', 'Blox', 'SEO', 'URL', 'API', 'HTML', 'CSS', 'PHP', 'SMTP', 'Logo', 'ID', 'OK'];
    /**
     * 与英文参考一字不差的行占比：超过 WARN 提示，超过 ERROR 整片不合格。
     * 2026-10-02 第一次外包翻译交回的分片 83% 是照抄英文（脚本「词典替换 + 其余抄英文」），
     * 只给警告时「0 错误」照样能合入。样本太小（不足 MIN 行可比）不判，避免小分片误伤。
     */
    private const ENGLISH_COPY_WARN = 0.03;
    private const ENGLISH_COPY_ERROR = 0.15;
    private const ENGLISH_COPY_MIN = 10;
    /** 键名前缀 → 页面提示，帮助翻译模型判断语境 */
    private const NOTES = [
        'blox_' => 'Blox visual page builder (admin)',
        'admin_' => 'admin panel',
        'setting_' => 'admin settings page',
        'shop_' => 'online shop',
        'form_' => 'contact / inquiry forms',
        'inq_' => 'inquiry management (admin)',
        'health_' => 'site health check (admin)',
        'upgrade_' => 'system upgrade (admin)',
        'st_' => 'site templates (admin)',
        'theme_' => 'themes (admin)',
        'pl_' => 'plugins (admin)',
        'member_' => 'member accounts (front end)',
        'home_' => 'home page',
        'search_' => 'search page (front end)',
        'support_' => 'official support access (admin)',
        'repair_' => 'remote repair (admin)',
        'ai_' => 'AI assistant (admin)',
        'cron_' => 'scheduled tasks (admin)',
        'prod_' => 'products',
        'product_' => 'products',
        'mig_' => 'database upgrade (admin)',
        'login_' => 'login page',
        'install_' => 'installer',
    ];

    public function __construct(private string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    // ── 目标语言 ─────────────────────────────────────────────

    /** @return list<string> */
    public function registeredCodes(): array
    {
        if (!class_exists('LanguageRegistry')) {
            require_once $this->root . '/includes/i18n/LanguageRegistry.php';
        }
        return LanguageRegistry::codes();
    }

    public function assertTarget(string $code): void
    {
        if (!in_array($code, $this->registeredCodes(), true)) {
            throw new InvalidArgumentException("Unknown language code: {$code} (not in includes/i18n/LanguageRegistry.php)");
        }
        if (in_array($code, self::NOT_TARGETS, true)) {
            throw new InvalidArgumentException("{$code} is not a translation target (zh-CN is the source, en / ja are maintained by hand, zh-TW is converted from zh-CN)");
        }
    }

    // ── 文案来源 ─────────────────────────────────────────────

    /**
     * 一块文案里的全部语言包单元。
     *
     * @return list<array{file:string, zh:string, en:string, ja:string}> 路径均相对仓库根
     */
    public function units(string $scope, string $code): array
    {
        $out = [];
        if ($scope === 'core') {
            $out[] = ['file' => "lang/{$code}.php", 'zh' => 'lang/zh-CN.php', 'en' => 'lang/en.php', 'ja' => 'lang/ja.php'];
        } elseif ($scope === 'plugins') {
            foreach (glob($this->root . '/plugins/*/lang/zh-CN.php') ?: [] as $src) {
                $dir = 'plugins/' . basename(dirname(dirname($src))) . '/lang';
                $out[] = ['file' => "{$dir}/{$code}.php", 'zh' => "{$dir}/zh-CN.php", 'en' => "{$dir}/en.php", 'ja' => "{$dir}/ja.php"];
            }
            usort($out, static fn(array $a, array $b): int => strcmp($a['file'], $b['file']));
        } elseif ($scope === 'installer') {
            $out[] = ['file' => "install/lang/{$code}.php", 'zh' => 'install/lang/zh.php', 'en' => 'install/lang/en.php', 'ja' => 'install/lang/ja.php'];
        } else {
            throw new InvalidArgumentException("Unknown scope: {$scope}");
        }
        return $out;
    }

    /** @return array<string,string> */
    public function load(string $rel): array
    {
        $path = $this->root . '/' . $rel;
        if (!is_file($path)) {
            return [];
        }
        $data = (static function (string $__file) {
            return require $__file;
        })($path);
        if (!is_array($data)) {
            throw new RuntimeException("Language file does not return an array: {$rel}");
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function hash(string $source): string
    {
        return substr(hash('sha256', $source), 0, 12);
    }

    public static function note(string $key): string
    {
        foreach (self::NOTES as $prefix => $note) {
            if (str_starts_with($key, $prefix)) {
                return $note;
            }
        }
        return '';
    }

    // ── export ──────────────────────────────────────────────

    /**
     * 导出目标语言尚未翻译（目标文件缺键或值为空）的键。
     *
     * @param list<string> $scopes
     * @return array{shards: list<array{file:string, lines:int}>, total:int}
     */
    public function export(string $code, array $scopes, string $prefix, int $size, string $outDir): array
    {
        $this->assertTarget($code);
        $size = max(1, $size);
        if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
            throw new RuntimeException("Cannot create {$outDir}");
        }
        $manifest = ['code' => $code, 'scopes' => $scopes, 'prefix' => $prefix, 'commit' => $this->gitCommit(), 'created_at' => date('c'), 'shards' => []];
        $total = 0;
        foreach ($scopes as $scope) {
            $rows = [];
            foreach ($this->units($scope, $code) as $unit) {
                $zh = $this->load($unit['zh']);
                $en = $this->load($unit['en']);
                $ja = $this->load($unit['ja']);
                $target = $this->load($unit['file']);
                foreach ($zh as $key => $source) {
                    if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                        continue;
                    }
                    if (isset($target[$key]) && trim($target[$key]) !== '') {
                        continue;
                    }
                    $rows[] = [
                        'file' => $unit['file'], 'key' => $key, 'zh' => $source,
                        'en' => $en[$key] ?? '', 'ja' => $ja[$key] ?? '',
                        'note' => self::note($key), 'src_hash' => self::hash($source),
                    ];
                }
            }
            foreach (array_chunk($rows, $size) as $i => $chunk) {
                $name = sprintf('%s-%s-%03d.jsonl', $code, $scope, $i + 1);
                $lines = array_map(static fn(array $r): string => (string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $chunk);
                file_put_contents($outDir . '/' . $name, implode("\n", $lines) . "\n");
                $manifest['shards'][] = ['file' => $name, 'scope' => $scope, 'lines' => count($chunk)];
                $total += count($chunk);
            }
        }
        $manifest['total'] = $total;
        file_put_contents($outDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        return ['shards' => array_map(static fn(array $s): array => ['file' => $s['file'], 'lines' => $s['lines']], $manifest['shards']), 'total' => $total];
    }

    // ── validate ────────────────────────────────────────────

    /**
     * @return list<array<string,mixed>>
     */
    public static function readJsonl(string $path): array
    {
        $rows = [];
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$path}");
        }
        $n = 0;
        while (($line = fgets($handle)) !== false) {
            $n++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                fclose($handle);
                throw new RuntimeException("{$path}:{$n} is not valid JSON");
            }
            $row['_line'] = $n;
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    /**
     * 校验译文分片。错误让整片不合格；警告只提示。
     *
     * @param array<string,string> $glossary 原文术语 → 本语言译法
     * @return array{errors: list<string>, warnings: list<string>, rows:int}
     */
    public function validate(string $code, string $inputPath, string $outputPath, array $glossary = []): array
    {
        $this->assertTarget($code);
        $errors = [];
        $warnings = [];
        try {
            $input = self::readJsonl($inputPath);
            $output = self::readJsonl($outputPath);
        } catch (RuntimeException $e) {
            return ['errors' => [$e->getMessage()], 'warnings' => [], 'rows' => 0];
        }
        if (count($input) !== count($output)) {
            $errors[] = sprintf('line count differs: input %d, output %d', count($input), count($output));
        }
        $sameAsEnglish = 0;
        $comparable = 0;
        foreach ($input as $i => $in) {
            $out = $output[$i] ?? null;
            $where = 'line ' . ($i + 1) . ' [' . ($in['key'] ?? '?') . ']';
            if (!is_array($out)) {
                $errors[] = "{$where}: missing in output";
                continue;
            }
            if (($out['key'] ?? null) !== ($in['key'] ?? null) || ($out['file'] ?? null) !== ($in['file'] ?? null)) {
                $errors[] = "{$where}: key or file changed or out of order (got " . (string) ($out['key'] ?? 'null') . ')';
                continue;
            }
            if (($out['src_hash'] ?? null) !== ($in['src_hash'] ?? null)) {
                $errors[] = "{$where}: src_hash changed";
            }
            $text = $out['text'] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $errors[] = "{$where}: empty or missing \"text\"";
                continue;
            }
            if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
                $errors[] = "{$where}: invalid UTF-8 or control characters";
            }
            $source = (string) ($in['zh'] ?? '');
            if (self::placeholders($source) !== self::placeholders($text)) {
                $errors[] = "{$where}: placeholders differ (source " . implode(' ', self::placeholders($source)) . ' / translation ' . implode(' ', self::placeholders($text)) . ')';
            }
            if (self::tags($source) !== self::tags($text)) {
                $errors[] = "{$where}: HTML tags differ (source " . implode(' ', self::tags($source)) . ' / translation ' . implode(' ', self::tags($text)) . ')';
            }
            if (!in_array($code, self::HAN_ALLOWED, true) && preg_match('/\p{Han}/u', $text) === 1) {
                $errors[] = "{$where}: Chinese characters left in translation";
            }
            if (substr_count($source, "\n") !== substr_count($text, "\n")) {
                $warnings[] = "{$where}: line breaks differ";
            }
            $english = (string) ($in['en'] ?? '');
            if ($english !== '' && mb_strlen($text) > max(12, (int) ceil(mb_strlen($english) * 2.5))) {
                $warnings[] = "{$where}: much longer than English (" . mb_strlen($text) . ' vs ' . mb_strlen($english) . ' chars)';
            }
            if ($english !== '' && !self::trivial($english)) {
                $comparable++;
                if (trim($text) === trim($english)) {
                    $sameAsEnglish++;
                }
            }
            foreach ($glossary as $term => $wanted) {
                if ($term !== '' && $wanted !== '' && str_contains($source, (string) $term) && mb_stripos($text, (string) $wanted) === false) {
                    $warnings[] = "{$where}: glossary term {$term} should be \"{$wanted}\"";
                }
            }
        }
        [$copyError, $copyWarning] = self::englishCopyVerdict($sameAsEnglish, $comparable);
        if ($copyError !== null) {
            $errors[] = $copyError;
        } elseif ($copyWarning !== null) {
            $warnings[] = $copyWarning;
        }
        return ['errors' => $errors, 'warnings' => $warnings, 'rows' => count($input)];
    }

    /** @return list<string> 排好序的占位符：:name、%s / %d、{…} */
    public static function placeholders(string $text): array
    {
        preg_match_all('/(?<![\w:\/]):[A-Za-z_]\w*|%[sd]|\{[^{}\s]*\}/', $text, $m);
        $list = $m[0];
        sort($list);
        return $list;
    }

    /** @return list<string> 依次出现的 HTML 标签名（开 / 闭），不看属性 */
    public static function tags(string $text): array
    {
        preg_match_all('#<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>#', $text, $m, PREG_SET_ORDER);
        return array_map(static fn(array $t): string => $t[1] . strtolower($t[2]), $m);
    }

    /** @return array{0:?string,1:?string} [错误, 警告] */
    private static function englishCopyVerdict(int $same, int $comparable): array
    {
        if ($comparable === 0) {
            return [null, null];
        }
        $ratio = $same / $comparable;
        $message = sprintf('%d of %d lines are identical to English (%.1f%%)', $same, $comparable, 100 * $ratio);
        if ($comparable >= self::ENGLISH_COPY_MIN && $ratio > self::ENGLISH_COPY_ERROR) {
            return [$message . '; the shard was not translated (copying the English reference is not allowed)', null];
        }
        return [null, $ratio > self::ENGLISH_COPY_WARN ? $message . '; check they were translated' : null];
    }

    private static function trivial(string $english): bool
    {
        $plain = trim(str_ireplace(self::BRAND, '', $english));
        return $plain === '' || preg_match('/^[\d\s\W]*$/u', $plain) === 1 || preg_match('#^https?://#', $english) === 1 || mb_strlen($plain) <= 2;
    }

    // ── merge ───────────────────────────────────────────────

    /**
     * 合入译文分片（应已通过 validate；这里再校验一次，失败整片拒绝）。
     *
     * @param list<string> $outputs 翻译后的 JSONL
     * @return array{written: array<string,int>, stale: list<string>, rejected: list<string>}
     */
    public function merge(string $code, array $outputs): array
    {
        $this->assertTarget($code);
        $byFile = [];
        $stale = [];
        $rejected = [];
        foreach ($outputs as $path) {
            $rows = self::readJsonl($path);
            // 用分片自身做 input：只能核对占位符 / 标签 / 汉字，行数与顺序由 validate 对照导出件负责
            $check = $this->validateRows($code, $rows);
            if ($check !== []) {
                $rejected[] = basename($path) . ': ' . $check[0] . (count($check) > 1 ? ' (+' . (count($check) - 1) . ' more)' : '');
                continue;
            }
            foreach ($rows as $row) {
                $byFile[(string) $row['file']][(string) $row['key']] = $row;
            }
        }

        $hashes = $this->loadHashes($code);
        $written = [];
        foreach ($byFile as $file => $rows) {
            $unit = $this->unitForFile($code, $file);
            $zh = $this->load($unit['zh']);
            $existing = $this->load($file);
            $count = 0;
            foreach ($rows as $key => $row) {
                if (!array_key_exists($key, $zh)) {
                    $stale[] = "{$file}|{$key} (no longer in the source)";
                    continue;
                }
                if (self::hash($zh[$key]) !== (string) $row['src_hash']) {
                    $stale[] = "{$file}|{$key} (source changed after export)";
                    continue;
                }
                $existing[$key] = (string) $row['text'];
                $hashes["{$file}|{$key}"] = (string) $row['src_hash'];
                $count++;
            }
            $this->writePack($file, $zh, $existing, $code);
            $written[$file] = $count;
        }
        $this->saveHashes($code, $hashes);
        return ['written' => $written, 'stale' => $stale, 'rejected' => $rejected];
    }

    /** @return list<string> */
    private function validateRows(string $code, array $rows): array
    {
        $errors = [];
        $sameAsEnglish = 0;
        $comparable = 0;
        foreach ($rows as $row) {
            $where = (string) ($row['key'] ?? '?');
            $text = $row['text'] ?? null;
            if (!isset($row['file'], $row['key'], $row['zh'], $row['src_hash']) || !is_string($text) || trim($text) === '') {
                $errors[] = "{$where}: incomplete line";
                continue;
            }
            if (!str_ends_with((string) $row['file'], '/' . $code . '.php')) {
                $errors[] = "{$where}: file is not a {$code} language pack";
            }
            if (self::placeholders((string) $row['zh']) !== self::placeholders($text) || self::tags((string) $row['zh']) !== self::tags($text)) {
                $errors[] = "{$where}: placeholders or HTML tags differ";
            }
            if (!in_array($code, self::HAN_ALLOWED, true) && preg_match('/\p{Han}/u', $text) === 1) {
                $errors[] = "{$where}: Chinese characters left in translation";
            }
            $english = (string) ($row['en'] ?? '');
            if ($english !== '' && !self::trivial($english)) {
                $comparable++;
                if (trim($text) === trim($english)) {
                    $sameAsEnglish++;
                }
            }
        }
        $copyError = self::englishCopyVerdict($sameAsEnglish, $comparable)[0];
        if ($copyError !== null) {
            $errors[] = $copyError;
        }
        return $errors;
    }

    /** @return array{file:string, zh:string, en:string, ja:string} */
    private function unitForFile(string $code, string $file): array
    {
        foreach (self::SCOPES as $scope) {
            foreach ($this->units($scope, $code) as $unit) {
                if ($unit['file'] === $file) {
                    return $unit;
                }
            }
        }
        throw new InvalidArgumentException("Not a known language pack target: {$file}");
    }

    /**
     * 按 zh-CN 的键顺序写语言包；原文里已没有的键不再写出。
     *
     * @param array<string,string> $source
     * @param array<string,string> $values
     */
    private function writePack(string $file, array $source, array $values, string $code): void
    {
        $name = LanguageRegistry::englishName($code);
        $lines = ['<?php', '/**', " * YikaiCMS - {$name} language pack ({$code}).", ' * Generated by tools/i18n/merge.php from reviewed machine translations; keys follow lang/zh-CN.php.', ' */', '', 'return ['];
        foreach ($source as $key => $_) {
            if (isset($values[$key]) && $values[$key] !== '') {
                $lines[] = '    ' . self::phpString($key) . ' => ' . self::phpString($values[$key]) . ',';
            }
        }
        $lines[] = '];';
        $path = $this->root . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, implode("\n", $lines) . "\n");
        $lint = self::lint($tmp);
        if ($lint !== null) {
            @unlink($tmp);
            throw new RuntimeException("Generated {$file} does not lint: {$lint}");
        }
        rename($tmp, $path);
    }

    public static function phpString(string $value): string
    {
        if (strpbrk($value, "\n\r\t") === false) {
            return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
        }
        return '"' . strtr($value, ['\\' => '\\\\', '"' => '\\"', '$' => '\\$', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']) . '"';
    }

    private static function lint(string $path): ?string
    {
        $out = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        return $code === 0 ? null : implode(' ', $out);
    }

    // ── status ──────────────────────────────────────────────

    /** @return array<string, array<string, array{translated:int, total:int, stale:int}>> */
    public function status(): array
    {
        $out = [];
        foreach ($this->registeredCodes() as $code) {
            if (in_array($code, self::NOT_TARGETS, true)) {
                continue;
            }
            $hashes = $this->loadHashes($code);
            foreach (self::SCOPES as $scope) {
                $translated = 0;
                $total = 0;
                $staleCount = 0;
                foreach ($this->units($scope, $code) as $unit) {
                    $zh = $this->load($unit['zh']);
                    $target = $this->load($unit['file']);
                    foreach ($zh as $key => $source) {
                        $total++;
                        if (isset($target[$key]) && trim($target[$key]) !== '') {
                            $translated++;
                            $recorded = $hashes["{$unit['file']}|{$key}"] ?? null;
                            if ($recorded !== null && $recorded !== self::hash($source)) {
                                $staleCount++;
                            }
                        }
                    }
                }
                $out[$code][$scope] = ['translated' => $translated, 'total' => $total, 'stale' => $staleCount];
            }
        }
        return $out;
    }

    // ── 原文哈希（判断过时译文） ─────────────────────────────

    private function hashesPath(string $code): string
    {
        return $this->root . "/tools/i18n/hashes/{$code}.json";
    }

    /** @return array<string,string> */
    private function loadHashes(string $code): array
    {
        $raw = @file_get_contents($this->hashesPath($code));
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? array_map('strval', $data) : [];
    }

    /** @param array<string,string> $hashes */
    private function saveHashes(string $code, array $hashes): void
    {
        ksort($hashes);
        $path = $this->hashesPath($code);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function gitCommit(): string
    {
        $head = @file_get_contents($this->root . '/.git/HEAD');
        if (is_string($head) && preg_match('/^[0-9a-f]{40}$/', trim($head)) === 1) {
            return trim($head);
        }
        $out = [];
        exec('git -C ' . escapeshellarg($this->root) . ' rev-parse HEAD 2>&1', $out, $code);
        return $code === 0 ? trim((string) ($out[0] ?? '')) : '';
    }
}
