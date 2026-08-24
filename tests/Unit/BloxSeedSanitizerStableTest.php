<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 种子/预设富文本必须「净化器稳定」（v1.18.8）。
 *
 * 背景：TextElement 渲染时过 sanitizeHtml（HtmlPolicy::richText），style 属性
 * 会被整个剥掉——预设与随包模板里写内联样式，作者在编辑器里看到的与前台
 * 实际渲染的就是两个东西（价格方案的去圆点/居中/徽章全部散架，2026-08-25
 * 用户反馈）。规矩：种子 HTML 的排版一律走 app.css 的 yk-* 类（class 会保留），
 * 并且整段 HTML 必须原样通过净化器。
 */
final class BloxSeedSanitizerStableTest extends TestCase
{
    protected function setUp(): void
    {
        require_once ROOT_PATH . '/includes/HtmlPolicy.php';
    }

    /** 收集一个 blox 结构里所有 text 元素的 html（含嵌套 children） */
    private function collectTextHtml(array $node, array &$found): void
    {
        if (($node['type'] ?? '') === 'text' && is_string($node['data']['html'] ?? null)) {
            $found[] = (string) $node['data']['html'];
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectTextHtml($value, $found);
            }
        }
    }

    private function assertSanitizerStable(string $html, string $context): void
    {
        $this->assertStringNotContainsString(
            'style=',
            $html,
            "$context 含内联样式——净化器会剥掉，改用 app.css 的 yk-* 类"
        );
        $this->assertSame(
            $html,
            HtmlPolicy::richText($html),
            "$context 经净化器后发生了变化——作者所见与前台渲染不一致"
        );
    }

    public function testElementLibraryPresetTextIsSanitizerStable(): void
    {
        require_once ROOT_PATH . '/includes/builder/presets.php';
        $presets = builderPresets();
        $checked = 0;
        foreach (['sections', 'pages'] as $group) {
            foreach ($presets[$group] ?? [] as $preset) {
                $found = [];
                $this->collectTextHtml($preset, $found);
                foreach ($found as $html) {
                    $this->assertSanitizerStable($html, "预设 {$group}/{$preset['key']}");
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(10, $checked, '预设 text 元素采集数异常，遍历可能失效');
    }

    public function testBuiltinPageTemplateTextIsSanitizerStable(): void
    {
        $files = glob(ROOT_PATH . '/templates/blox/pages/*.json') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $doc = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($doc, basename($file));
            $found = [];
            $this->collectTextHtml($doc, $found);
            foreach ($found as $html) {
                $this->assertSanitizerStable($html, basename($file));
            }
        }
    }

    /**
     * install SQL 的首页种子：富文本无内联样式，banner 走多语言轮播表
     * （items_mode=custom 会把中文文案烤死在文档里——英文/日文安装站首页
     * 显示中文，2026-08-25 两个客户站实病）。
     */
    public function testInstallSqlHomeSeedsAreClean(): void
    {
        foreach (['mysql.sql', 'sqlite.sql'] as $name) {
            $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/' . $name);
            $this->assertNotSame('', $sql, $name);
            foreach (explode("\n", $sql) as $line) {
                $isHomeSeed = str_contains($line, 'home_custom_1')
                    || str_contains($line, 'home_blox_data')
                    || str_contains($line, 'home_blox_published');
                if (!$isHomeSeed || !str_contains($line, 'INSERT INTO')) {
                    continue;
                }
                $this->assertStringNotContainsString('style=', $line, "$name 首页种子含内联样式");
                $modePos = strpos($line, 'items_mode');
                if ($modePos !== false) {
                    $this->assertStringNotContainsString(
                        'custom',
                        substr($line, $modePos, 40),
                        "$name 首页 banner 种子应为 items_mode=inherit"
                    );
                }
            }
            // banner 数据行必须无条件随装（不再包进 @demo）：inherit 模式靠它出内容
            $this->assertMatchesRegularExpression(
                '/INSERT INTO [`"]yikai_banners[`"].*\'home\'.*\'en\'/s',
                $sql,
                "$name 缺少英文轮播种子行"
            );
        }
    }
}
