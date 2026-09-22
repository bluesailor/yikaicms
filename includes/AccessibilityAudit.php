<?php
/**
 * 有界、只读的可访问性静态审计。
 *
 * 这里永不 include 主题或正文中的代码，也不改写 Blox/插件输出；站点体检只读取文件和
 * 已发布内容。规则刻意保持少而确定，避免把动态模板中的合法输出误判成问题。
 */
declare(strict_types=1);

final class AccessibilityAudit
{
    public const NORMAL_TEXT_MIN = 4.5;
    public const LARGE_TEXT_MIN = 3.0;
    public const UI_COMPONENT_MIN = 3.0;
    public const MAX_THEME_FILES = 80;
    public const MAX_THEME_BYTES = 524288;
    public const MAX_CONTENT_ROWS = 40;
    public const MAX_CONTENT_BYTES = 524288;
    public const MAX_DATABASE_FIELD_BYTES = 65536;
    private const MAX_ISSUES = 20;

    /** @return array{status:string,ratio:?float,normal:bool,large:bool,ui:bool,reason:string} */
    public static function evaluateContrast(string $foreground, string $background): array
    {
        $fg = self::parseColor($foreground);
        $bg = self::parseColor($background);
        if ($fg === null || $bg === null) {
            return ['status' => 'unknown', 'ratio' => null, 'normal' => false, 'large' => false, 'ui' => false, 'reason' => 'invalid'];
        }
        // 半透明色的实际对比度取决于下层颜色；体检不能擅自假定为白底或黑底。
        if ($fg['a'] < 1.0 || $bg['a'] < 1.0) {
            return ['status' => 'unknown', 'ratio' => null, 'normal' => false, 'large' => false, 'ui' => false, 'reason' => 'transparent'];
        }

        $lighter = max(self::luminance($fg), self::luminance($bg));
        $darker = min(self::luminance($fg), self::luminance($bg));
        $ratio = ($lighter + 0.05) / ($darker + 0.05);
        return [
            'status' => $ratio >= self::NORMAL_TEXT_MIN ? 'pass' : 'fail',
            'ratio' => round($ratio, 2),
            'normal' => $ratio >= self::NORMAL_TEXT_MIN,
            'large' => $ratio >= self::LARGE_TEXT_MIN,
            'ui' => $ratio >= self::UI_COMPONENT_MIN,
            'reason' => '',
        ];
    }

    /**
     * @param list<array{label:string,foreground:string,background:string}> $pairs
     * @return array{failed:list<string>,unknown:list<string>,details:list<string>}
     */
    public static function auditColorPairs(array $pairs): array
    {
        $failed = [];
        $unknown = [];
        $details = [];
        foreach ($pairs as $pair) {
            $label = trim((string) ($pair['label'] ?? ''));
            $result = self::evaluateContrast((string) ($pair['foreground'] ?? ''), (string) ($pair['background'] ?? ''));
            if ($result['ratio'] === null) {
                $unknown[] = $label . ':' . $result['reason'];
                continue;
            }
            $details[] = $label . '=' . number_format($result['ratio'], 2, '.', '');
            if (!$result['normal']) {
                $failed[] = $label . '=' . number_format($result['ratio'], 2, '.', '');
            }
        }
        return ['failed' => $failed, 'unknown' => $unknown, 'details' => $details];
    }

    /**
     * @return array{issues:list<array{code:string,location:string,line:int}>,truncated:bool,unavailable:bool}
     */
    public static function auditHtml(string $html, string $location = 'html'): array
    {
        $truncated = strlen($html) > self::MAX_CONTENT_BYTES;
        if ($truncated) {
            $html = substr($html, 0, self::MAX_CONTENT_BYTES);
        }
        if ($html === '' || !class_exists(DOMDocument::class)) {
            return ['issues' => [], 'truncated' => $truncated, 'unavailable' => !class_exists(DOMDocument::class)];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return ['issues' => [], 'truncated' => $truncated, 'unavailable' => true];
        }

        $xpath = new DOMXPath($document);
        $issues = [];
        foreach ($xpath->query('//img[not(@alt)]') ?: [] as $node) {
            self::addIssue($issues, 'image_alt', $location, $node->getLineNo());
        }

        foreach ($xpath->query('//input|//select|//textarea') ?: [] as $node) {
            if (!$node instanceof DOMElement || self::isIgnoredControl($node)) {
                continue;
            }
            if (!self::hasAccessibleName($node, $xpath, true)) {
                self::addIssue($issues, 'form_label', $location, $node->getLineNo());
            }
        }

        foreach ($xpath->query('//button|//a[@href]') ?: [] as $node) {
            if ($node instanceof DOMElement && !self::hasAccessibleName($node, $xpath, false)) {
                self::addIssue($issues, 'accessible_name', $location, $node->getLineNo());
            }
        }

        foreach ($xpath->query('//*[@onclick]') ?: [] as $node) {
            if (!$node instanceof DOMElement || in_array(strtolower($node->tagName), ['a', 'button', 'input', 'select', 'textarea', 'summary'], true)) {
                continue;
            }
            $role = strtolower(trim($node->getAttribute('role')));
            $tabindex = trim($node->getAttribute('tabindex'));
            $keyboard = $node->hasAttribute('onkeydown') || $node->hasAttribute('onkeyup') || $node->hasAttribute('onkeypress');
            if (!in_array($role, ['button', 'link'], true) || $tabindex === '' || (int) $tabindex < 0 || !$keyboard) {
                self::addIssue($issues, 'keyboard_click', $location, $node->getLineNo());
            }
        }

        return ['issues' => $issues, 'truncated' => $truncated, 'unavailable' => false];
    }

    /** @return list<array{code:string,location:string,line:int}> */
    public static function auditCss(string $css, string $location = 'css'): array
    {
        $issues = [];
        $offset = 0;
        foreach (explode('}', substr($css, 0, self::MAX_CONTENT_BYTES)) as $block) {
            $start = $offset;
            $offset += strlen($block) + 1;
            $brace = strpos($block, '{');
            if ($brace === false) {
                continue;
            }
            $selector = strtolower(substr($block, 0, $brace));
            $declarations = strtolower(substr($block, $brace + 1));
            if (!str_contains($selector, ':focus')) {
                continue;
            }
            $hidesOutline = preg_match('/(?:^|;)\s*outline\s*:\s*(?:0|none)(?:\s*!important)?\s*(?:;|$)/', $declarations) === 1;
            $visibleAlternative = preg_match('/(?:box-shadow|border(?:-color|-width)?|background(?:-color)?|text-decoration)\s*:/', $declarations) === 1;
            if ($hidesOutline && !$visibleAlternative) {
                self::addIssue($issues, 'focus_hidden', $location, 1 + substr_count(substr($css, 0, $start), "\n"));
            }
        }
        return $issues;
    }

    /**
     * @return array{issues:list<array{code:string,location:string,line:int}>,files:int,bytes:int,truncated:bool,unavailable:bool}
     */
    public static function auditTheme(string $themeDirectory, string $root): array
    {
        $themeReal = realpath($themeDirectory);
        $rootReal = realpath($root);
        if ($themeReal === false || $rootReal === false || !str_starts_with(strtolower($themeReal . DIRECTORY_SEPARATOR), strtolower($rootReal . DIRECTORY_SEPARATOR))) {
            return ['issues' => [], 'files' => 0, 'bytes' => 0, 'truncated' => false, 'unavailable' => true];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeReal, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), ['php', 'html', 'htm', 'css'], true)) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths, SORT_STRING);

        $issues = [];
        $files = 0;
        $bytes = 0;
        $truncated = count($paths) > self::MAX_THEME_FILES;
        foreach ($paths as $path) {
            if ($files >= self::MAX_THEME_FILES || $bytes >= self::MAX_THEME_BYTES) {
                $truncated = true;
                break;
            }
            $remaining = self::MAX_THEME_BYTES - $bytes;
            $source = file_get_contents($path, false, null, 0, $remaining + 1);
            if (!is_string($source)) {
                continue;
            }
            if (strlen($source) > $remaining) {
                $source = substr($source, 0, $remaining);
                $truncated = true;
            }
            $bytes += strlen($source);
            $files++;
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($rootReal, DIRECTORY_SEPARATOR)) + 1));
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'css') {
                $issues = array_merge($issues, self::auditCss($source, $relative));
            } else {
                $html = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php' ? self::phpInlineHtml($source) : $source;
                $audit = self::auditHtml($html, $relative);
                $issues = array_merge($issues, $audit['issues']);
            }
            if (count($issues) >= self::MAX_ISSUES) {
                $issues = array_slice($issues, 0, self::MAX_ISSUES);
                $truncated = true;
                break;
            }
        }
        return ['issues' => $issues, 'files' => $files, 'bytes' => $bytes, 'truncated' => $truncated, 'unavailable' => false];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{issues:list<array{code:string,location:string,line:int}>,rows:int,bytes:int,truncated:bool,unavailable:bool}
     */
    public static function auditContentRows(array $rows): array
    {
        $issues = [];
        $count = 0;
        $bytes = 0;
        $truncated = count($rows) > self::MAX_CONTENT_ROWS;
        foreach (array_slice($rows, 0, self::MAX_CONTENT_ROWS) as $row) {
            $id = max(0, (int) ($row['id'] ?? 0));
            $content = (string) ($row['content'] ?? '');
            $blocksData = (string) ($row['blocks_data'] ?? '');
            if (strlen($content) > self::MAX_DATABASE_FIELD_BYTES || strlen($blocksData) > self::MAX_DATABASE_FIELD_BYTES) {
                $truncated = true;
            }
            $fragments = [substr($content, 0, self::MAX_DATABASE_FIELD_BYTES)];
            // 截断后的 JSON 不再解析，避免把不完整数据当 HTML，也避免超大 Blox 文档占用无界内存。
            if (strlen($blocksData) <= self::MAX_DATABASE_FIELD_BYTES) {
                self::collectHtmlStrings($blocksData, $fragments);
            }
            foreach ($fragments as $index => $html) {
                if ($bytes >= self::MAX_CONTENT_BYTES) {
                    $truncated = true;
                    break 2;
                }
                $remaining = self::MAX_CONTENT_BYTES - $bytes;
                if (strlen($html) > $remaining) {
                    $html = substr($html, 0, $remaining);
                    $truncated = true;
                }
                $bytes += strlen($html);
                $audit = self::auditHtml($html, 'content#' . $id . ($index > 0 ? '/block' . $index : ''));
                $issues = array_merge($issues, $audit['issues']);
                if (count($issues) >= self::MAX_ISSUES) {
                    $issues = array_slice($issues, 0, self::MAX_ISSUES);
                    $truncated = true;
                    break 2;
                }
            }
            $count++;
        }
        return ['issues' => $issues, 'rows' => $count, 'bytes' => $bytes, 'truncated' => $truncated, 'unavailable' => !class_exists(DOMDocument::class)];
    }

    /** @return array{r:float,g:float,b:float,a:float}|null */
    private static function parseColor(string $color): ?array
    {
        $color = strtolower(trim($color));
        if ($color === 'transparent') {
            return ['r' => 0.0, 'g' => 0.0, 'b' => 0.0, 'a' => 0.0];
        }
        if (preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/D', $color, $match) !== 1) {
            return null;
        }
        $hex = $match[1];
        if (strlen($hex) <= 4) {
            $hex = implode('', array_map(static fn(string $char): string => $char . $char, str_split($hex)));
        }
        return [
            'r' => (float) hexdec(substr($hex, 0, 2)),
            'g' => (float) hexdec(substr($hex, 2, 2)),
            'b' => (float) hexdec(substr($hex, 4, 2)),
            'a' => strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0,
        ];
    }

    /** @param array{r:float,g:float,b:float,a:float} $color */
    private static function luminance(array $color): float
    {
        $channels = [];
        foreach (['r', 'g', 'b'] as $key) {
            $value = $color[$key] / 255;
            $channels[$key] = $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels['r'] + 0.7152 * $channels['g'] + 0.0722 * $channels['b'];
    }

    private static function isIgnoredControl(DOMElement $node): bool
    {
        if (strtolower(trim($node->getAttribute('aria-hidden'))) === 'true') {
            return true;
        }
        $type = strtolower(trim($node->getAttribute('type')));
        return strtolower($node->tagName) === 'input'
            && in_array($type, ['hidden', 'submit', 'reset', 'button', 'image'], true);
    }

    private static function hasAccessibleName(DOMElement $node, DOMXPath $xpath, bool $allowLabel): bool
    {
        foreach (['aria-label', 'aria-labelledby', 'title'] as $attribute) {
            if (trim($node->getAttribute($attribute)) !== '') {
                return true;
            }
        }
        if ($allowLabel) {
            for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                if (strtolower($parent->tagName) === 'label') {
                    return true;
                }
            }
            $id = trim($node->getAttribute('id'));
            if ($id !== '') {
                $quoted = self::xpathLiteral($id);
                if (($xpath->query('//label[@for=' . $quoted . ']')?->length ?? 0) > 0) {
                    return true;
                }
            }
            return false;
        }
        if (trim((string) $node->textContent) !== '') {
            return true;
        }
        foreach ($xpath->query('.//img[@alt]', $node) ?: [] as $image) {
            if ($image instanceof DOMElement && trim($image->getAttribute('alt')) !== '') {
                return true;
            }
        }
        return false;
    }

    private static function xpathLiteral(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }
        $parts = explode("'", $value);
        return "concat('" . implode("',\"'\",'", $parts) . "')";
    }

    /** @param list<array{code:string,location:string,line:int}> $issues */
    private static function addIssue(array &$issues, string $code, string $location, int $line): void
    {
        if (count($issues) >= self::MAX_ISSUES) {
            return;
        }
        $issues[] = ['code' => $code, 'location' => $location, 'line' => max(1, $line)];
    }

    private static function phpInlineHtml(string $source): string
    {
        $html = '';
        $inCode = false;
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $html .= $token[1];
                $inCode = false;
                continue;
            }
            $text = is_array($token) ? $token[1] : $token;
            if (!$inCode) {
                $html .= 'YK_DYNAMIC';
                $inCode = true;
            }
            $html .= str_repeat("\n", substr_count($text, "\n"));
        }
        return $html;
    }

    /** @param mixed $value @param list<string> $fragments */
    private static function collectHtmlStrings($value, array &$fragments): void
    {
        if (count($fragments) >= 40) {
            return;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                self::collectHtmlStrings($decoded, $fragments);
            } elseif (str_contains($value, '<') && str_contains($value, '>')) {
                $fragments[] = $value;
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            self::collectHtmlStrings($item, $fragments);
        }
    }
}
