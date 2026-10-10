<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 前台输出卫生（2026-10-10：日语首页源码里能看到中文开发注释）。
 *
 * 写给开发者的注释放 PHP 里（<?php /* … *\/ ?> 或字符串外），不进页面——访客、各语言站点都看不到。
 * 这里用 PHP 词法分析逐个前台模板检查「会输出到页面的部分」：模板里的 HTML（T_INLINE_HTML），
 * 以及含 <script>/<style> 的 PHP 字符串。出现带汉字的 JS/CSS/HTML 注释即失败。
 */
final class FrontendOutputHygieneTest extends TestCase
{
    /** @return list<string> */
    private static function frontTemplates(): array
    {
        $files = array_merge(glob(ROOT_PATH . '/*.php') ?: [], [ROOT_PATH . '/includes/header.php', ROOT_PATH . '/includes/footer.php']);
        foreach (['themes/default', 'includes/blocks', 'includes/partials'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    /** @return list<string> 带汉字的注释原文（截断） */
    private static function chineseComments(string $output): array
    {
        $found = [];
        $patterns = [
            '/<!--(.*?)-->/s',
            '/\/\*(.*?)\*\//s',
            '/(?m)(?<![:"\'\w\\\\])\/\/(.*)$/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $output, $m) > 0) {
                foreach ($m[1] as $comment) {
                    if (preg_match('/\p{Han}/u', $comment) === 1) {
                        $found[] = mb_substr(trim($comment), 0, 40);
                    }
                }
            }
        }
        return $found;
    }

    public function testFrontTemplatesDoNotPrintChineseDeveloperComments(): void
    {
        $problems = [];
        foreach (self::frontTemplates() as $file) {
            $tokens = token_get_all((string) file_get_contents($file));
            $inScript = false;
            foreach ($tokens as $token) {
                if (!is_array($token)) {
                    continue;
                }
                [$id, $text, $line] = $token;
                $checked = '';
                if ($id === T_INLINE_HTML) {
                    // 只看 <script>/<style> 内的 JS/CSS 注释和 HTML 注释；正文里的「//」可能是普通文字
                    $html = ($inScript ? '<script>' : '') . $text;
                    $inScript = (bool) preg_match('/<(script|style)\b(?![^<]*<\/\1>)[^<]*$/is', $html) || ($inScript && !preg_match('/<\/(script|style)>/i', $text));
                    if (preg_match_all('/<(script|style)\b[^>]*>(.*?)(?:<\/\1>|$)/is', $html, $blocks) > 0) {
                        $checked .= implode("\n", $blocks[2]);
                    }
                    if (preg_match_all('/<!--.*?-->/s', $text, $html_comments) > 0) {
                        $checked .= "\n" . implode("\n", $html_comments[0]);
                    }
                } elseif ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('/<(script|style)\b/i', $text) === 1) {
                    $checked = $text;
                }
                foreach ($checked === '' ? [] : self::chineseComments($checked) as $comment) {
                    $problems[] = str_replace(ROOT_PATH . DIRECTORY_SEPARATOR, '', $file) . ':' . $line . '  ' . $comment;
                }
            }
        }
        self::assertSame([], $problems, "这些注释会出现在前台页面源码里（各语言访客都看得到），改成 PHP 注释：\n" . implode("\n", $problems));
    }

    public function testSvgImagesGetIntrinsicDimensions(): void
    {
        require_once ROOT_PATH . '/includes/image.php';
        $attributes = responsiveImageAttributes('/assets/images/demo/article-201.svg', 'medium', '100vw');
        self::assertStringContainsString('width="800" height="500"', $attributes, '没有宽高时卡片图会让布局跳动');
        self::assertStringNotContainsString('srcset', $attributes, 'SVG 不需要多尺寸');

        $file = sys_get_temp_dir() . '/yk-svg-' . bin2hex(random_bytes(4)) . '.svg';
        file_put_contents($file, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360"><rect/></svg>');
        self::assertSame([640, 360], _svgDimensions($file), '只有 viewBox 时取它的宽高');
        file_put_contents($file, '<svg width="100%" height="100%"><rect/></svg>');
        self::assertSame([0, 0], _svgDimensions($file), '百分比尺寸不当像素');
        unlink($file);
    }

    public function testFaqChevronsAreHiddenFromScreenReaders(): void
    {
        foreach (['includes/builder/elements/AccordionElement.php', 'includes/channel_catalog.php'] as $file) {
            self::assertStringNotContainsString('<i class="ti ti-chevron-down text-gray-400 flex-shrink-0', (string) file_get_contents(ROOT_PATH . '/' . $file), $file);
        }
    }
}
