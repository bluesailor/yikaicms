<?php
/**
 * 编辑器模板里把 json_encode 的结果放进双引号属性（x-data="{ it: … }"）时必须先 HTML 转义：
 * JSON 自己的双引号会提前结束属性，Alpine 只拿到「{ it: {」，整块面板的文字对象不存在，
 * 每次选中元素都往站点错误日志里写 JS 错误。2.3.0 候选验收在条目编辑器（图片轮播、Logo 墙、评价轮播）
 * 与 Builder Pro 价格方案面板上实测到，两处都从 2.2.x 起就在。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxEditorAttributeJsonTest extends TestCase
{
    public function testJsonInDoubleQuotedAttributesIsEscaped(): void
    {
        $roots = [ROOT_PATH . '/admin/blox_editor', ROOT_PATH . '/plugins/yikai-builder/editor'];
        $offenders = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') continue;
                $source = (string) file_get_contents($file->getPathname());
                // 这个文件里哪些变量装的是 json_encode 的结果
                preg_match_all('/\$(\w+)\s*=\s*json_encode\(/', $source, $vars);
                foreach (array_unique($vars[1]) as $var) {
                    if (preg_match('/="[^"]*<\?=\s*\$' . $var . '\s*\?>/', $source)) {
                        $offenders[] = substr($file->getPathname(), strlen(ROOT_PATH) + 1) . ' $' . $var;
                    }
                }
            }
        }
        self::assertSame([], $offenders, 'JSON printed raw inside a double-quoted attribute; wrap it in e()');
    }

    public function testTheEscapedAttributeDecodesBackToTheSameObject(): void
    {
        $json = (string) json_encode(['add' => '添加', 'quote' => 'a "b" & <c>'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $html = '<div x-data="{ it: ' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . ' }"></div>';
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $attr = $dom->getElementsByTagName('div')->item(0)?->getAttribute('x-data');
        self::assertSame('{ it: ' . $json . ' }', $attr, 'the browser sees the whole JSON object');
        self::assertSame(['add' => '添加', 'quote' => 'a "b" & <c>'], json_decode(substr((string) $attr, 6, -2), true));
    }
}
