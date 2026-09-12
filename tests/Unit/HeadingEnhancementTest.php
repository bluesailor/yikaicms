<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class HeadingEnhancementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testSixLevelsKeepSemanticTagSeparateFromSize(): void
    {
        $element = new HeadingElement();
        $control = array_values(array_filter($element->controls(), static fn(array $control): bool => $control['key'] === 'level'))[0];
        self::assertSame(['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], array_keys($control['options']));
        foreach (['h5' => 'text-base', 'h6' => 'text-sm'] as $level => $size) {
            self::assertSame('<' . $level . ' class="' . $size . ' font-bold mb-4">Title</' . $level . '>', $element->render(['text' => 'Title', 'level' => $level]));
            self::assertStringStartsWith('<' . $level . ' class="text-4xl', $element->render(['text' => 'Title', 'level' => $level, 'visual_size' => '3xl']));
            self::assertSame($level, BloxValueSanitizer::sanitize($control, $level));
        }
        self::assertSame('<h2 class="text-2xl font-bold mb-4">Title</h2>', $element->render(['text' => 'Title']));
    }

    public function testLineBreakLinksAndIdsAreSafe(): void
    {
        $element = new HeadingElement();
        $html = $element->render(['text' => "One\n<two>", 'level' => 'h6', 'url' => '/contact.html', 'new_tab' => true, 'html_id' => '#services']);
        self::assertStringContainsString('id="services"', $html);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('One<br>&lt;two&gt;', $html);
        $html = $element->render(['text' => 'Safe', 'url' => 'javascript:alert(1)', 'html_id' => '" onclick="alert(1)', 'new_tab' => '0']);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString(' id=', $html);
        self::assertStringNotContainsString('onclick', $html);
    }

    public function testEntranceTriggersRemainOptInAndWhitelisted(): void
    {
        $element = new HeadingElement();
        self::assertStringNotContainsString('data-animate', $element->render(['text' => 'Title', 'animation_trigger' => 'load']));
        self::assertStringContainsString('data-animate-trigger="viewport"', $element->render(['animation' => 'fade']));
        self::assertStringContainsString('data-animate-trigger="load"', $element->render(['animation' => 'fade', 'animation_trigger' => 'load']));
        self::assertStringContainsString('data-animate-trigger="viewport"', $element->render(['animation' => 'fade', 'animation_trigger' => 'invalid']));
        $controls = array_column($element->controls(), null, 'key');
        self::assertSame('entrance', $controls['animation']['option_preview']);
        self::assertSame('viewport', BloxValueSanitizer::sanitize($controls['animation_trigger'], 'invalid'));
    }

    public function testLoopLinksAreOptInAndDoNotRepeatStaticIds(): void
    {
        $node = ['type' => 'heading', 'data' => ['text' => 'Original', 'level' => 'h5', 'loop_field' => 'title', 'html_id' => 'services']];
        $html = DynamicLoopTemplateRenderer::render([$node], ['query_source' => 'type:product']);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('id="services"', $html);
        $node['data']['loop_url_field'] = 'url';
        $html = DynamicLoopTemplateRenderer::render([$node], ['query_source' => 'type:product']);
        self::assertStringContainsString('href="{yk:field name=url /}"', $html);
        self::assertStringContainsString('<h5 ', $html);
    }
}
