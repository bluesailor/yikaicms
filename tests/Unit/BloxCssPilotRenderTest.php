<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** E05 试点：标题排版、按钮尺寸、容器间距经同一渲染入口输出声明式 CSS。 */
final class BloxCssPilotRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    private function render(string $type, array $data): string
    {
        return BlockRenderer::renderElementNode(['id' => 'e_pilot', 'type' => $type, 'data' => $data]);
    }

    public function testEmptyPilotValuesLeaveOutputUnchanged(): void
    {
        foreach ([
            ['heading', ['text' => 'Title', 'level' => 'h2'], ['type_font_size' => '', 'type_line_height' => '']],
            ['button', ['text' => 'Go', 'url' => '/go'], ['btn_padding_x' => '', 'btn_padding_y' => '', 'btn_radius' => '']],
            ['container', ['direction' => 'column'], ['gap_px' => '']],
        ] as [$type, $base, $empty]) {
            self::assertSame($this->render($type, $base), $this->render($type, $base + $empty), $type);
        }
    }

    public function testPilotValuesReachTheElementRoot(): void
    {
        $heading = $this->render('heading', ['text' => 'Title', 'level' => 'h2', 'type_font_size' => ['d' => 40, 'm' => 28], 'type_line_height' => '1.2']);
        // 属性顺序由 HTML 改写器决定，分别断言根标签上的类与样式。
        self::assertMatchesRegularExpression('/<h2\b[^>]*\bclass="[^"]*\byk-r-font-size\b[^"]*"/', $heading);
        self::assertMatchesRegularExpression('/<h2\b[^>]*\bstyle="--yk-r-font-size-m:28px;--yk-r-font-size-t:40px;--yk-r-font-size-d:40px;line-height:1.2;"/', $heading);

        $button = $this->render('button', ['text' => 'Go', 'url' => '/go', 'btn_padding_x' => 0, 'btn_padding_y' => 10, 'btn_radius' => 6]);
        self::assertMatchesRegularExpression('/<a\b[^>]*style="[^"]*padding-inline:0px;padding-block:10px;border-radius:6px;/', $button);
        self::assertDoesNotMatchRegularExpression('/^<div\b[^>]*style="[^"]*padding-inline/', $button);

        $container = $this->render('container', ['direction' => 'column', 'gap_px' => 24]);
        self::assertStringContainsString('gap:24px;', $container);
        self::assertStringNotContainsString('yk-r-gap', $container);
    }

    public function testTextUsesPublishedBodyOrCaptionThemeWithoutChangingEmptyThemeOutput(): void
    {
        $previous = $GLOBALS['_test_config'] ?? [];
        try {
            $GLOBALS['_test_config'] = [];
            BloxDesignTheme::resetCache();
            $plain = $this->render('text', ['html' => '<p>Body text</p>']);
            self::assertStringNotContainsString('yk-type-', $plain);
            $GLOBALS['_test_config'][BloxDesignTheme::PUBLISHED_KEY] = json_encode(['state' => [
                'typography' => ['body' => ['size' => 18], 'caption' => ['size' => 14]],
            ]], JSON_THROW_ON_ERROR);
            BloxDesignTheme::resetCache();
            $body = $this->render('text', ['html' => '<p>Body text</p>', 'color' => '#123456']);
            self::assertStringContainsString('yk-type-body', $body);
            self::assertStringContainsString('color:#123456;', $body);
            $caption = $this->render('text', ['html' => '<p>Caption</p>', 'typography_role' => 'caption']);
            self::assertStringContainsString('yk-type-caption', $caption);
        } finally {
            $GLOBALS['_test_config'] = $previous;
            BloxDesignTheme::resetCache();
        }
    }
}
