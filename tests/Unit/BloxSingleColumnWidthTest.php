<?php
/** 只有一列时的宽度与位置（2.1.0）：设了不足整行的宽度就按宽度占位，整行与未设宽度的输出不变。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxSingleColumnWidthTest extends TestCase
{
    /** @param array<string,mixed> $column @param array<string,mixed> $settings */
    private function render(array $column, array $settings = [], bool $editMode = false): string
    {
        $column += ['elements' => [['type' => 'text', 'id' => 'e1', 'data' => ['content' => 'x'], 'children' => []]]];
        $json = (string) json_encode([['type' => 'custom', 'id' => 's1', 'settings' => $settings, 'columns' => [$column]]]);
        if (!$editMode) {
            return BlockRenderer::render($json);
        }
        $oldChannel = BlockRenderer::$editChannelId;
        $oldSession = $_SESSION ?? null;
        BlockRenderer::$editChannelId = 7;
        $_SESSION = ['admin_id' => 1];
        try {
            return BlockRenderer::render($json);
        } finally {
            BlockRenderer::$editChannelId = $oldChannel;
            if ($oldSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $oldSession;
            }
        }
    }

    public function testQuarterWidthColumnTakesAQuarterAndStaysFullOnPhones(): void
    {
        $html = $this->render(['span' => 3]);
        self::assertStringContainsString('<div class="flex justify-start">', $html);
        self::assertStringContainsString('class="w-full min-w-0 md:w-3/12"', $html);
        self::assertStringNotContainsString('max-md:w-', $html, '手机默认整行');
    }

    public function testPositionAndBreakpoints(): void
    {
        self::assertStringContainsString('flex justify-center', $this->render(['span' => 4, 'position' => 'center']));
        self::assertStringContainsString('flex justify-end', $this->render(['span' => 4, 'position' => 'right']));
        self::assertStringContainsString('flex justify-start', $this->render(['span' => 4, 'position' => 'bogus']), '未知位置回落靠左');
        self::assertStringContainsString(
            'class="w-full min-w-0 max-md:w-6/12 md:w-8/12 lg:w-4/12"',
            $this->render(['span' => ['d' => 4, 't' => 8, 'm' => 6]])
        );
        self::assertStringContainsString(
            'class="w-full min-w-0 lg:w-3/12"',
            $this->render(['span' => 3], ['tablet_stack' => true]),
            '平板堆叠时平板也整行'
        );
    }

    public function testFullWidthAndUnsetColumnsKeepTheirOutput(): void
    {
        foreach ([['span' => 12], [], ['span' => 0]] as $column) {
            $html = $this->render($column);
            self::assertStringNotContainsString('flex justify-', $html);
            self::assertStringNotContainsString('w-full min-w-0', $html);
        }
    }

    public function testCanvasReportsTheRealSpanForASingleColumn(): void
    {
        self::assertStringContainsString('data-yk-col-span="3"', $this->render(['span' => 3], [], true));
        self::assertStringContainsString('data-yk-col-span="12"', $this->render([], [], true));
    }

    public function testWidthClassesAreCompiledIntoTheStylesheet(): void
    {
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        foreach (['md\\:w-3\\/12', 'lg\\:w-4\\/12', 'max-md\\:w-6\\/12', 'justify-end'] as $class) {
            self::assertStringContainsString('.' . $class . '{', $css, $class . ' 要编进 tailwind.css');
        }
    }
}
