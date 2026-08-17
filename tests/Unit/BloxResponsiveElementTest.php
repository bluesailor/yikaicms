<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxResponsiveElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testHeadingVisualSizeChangesWithoutChangingSemanticLevel(): void
    {
        $html = (new HeadingElement())->render([
            'text' => 'Responsive heading',
            'level' => 'h2',
            'visual_size' => ['d' => '3xl', 't' => 'xl', 'm' => 'sm'],
        ]);

        self::assertStringStartsWith('<h2 ', $html);
        self::assertStringContainsString('text-base', $html);
        self::assertStringContainsString('md:text-2xl', $html);
        self::assertStringContainsString('lg:text-4xl', $html);
        self::assertStringEndsWith('</h2>', $html);
    }

    public function testHeadingSupportsDisplayScaleAndSafeTextColor(): void
    {
        $html = (new HeadingElement())->render([
            'text' => '404',
            'level' => 'h1',
            'visual_size' => ['d' => 'display', 'm' => '5xl'],
            'color' => '#dededc',
            'align' => 'center',
        ]);

        self::assertStringContainsString('text-6xl', $html);
        self::assertStringContainsString('md:text-8xl', $html);
        self::assertStringContainsString('style="color:#dededc;"', $html);

        $unsafe = (new HeadingElement())->render(['text' => 'Unsafe', 'color' => 'red;display:none']);
        self::assertStringNotContainsString('display:none', $unsafe);
    }

    public function testButtonSupportsDarkPillWithoutChangingItsLinkContract(): void
    {
        $html = (new ButtonElement())->render([
            'text' => 'Back home',
            'url' => '/',
            'align' => 'center',
            'variant' => 'dark',
            'shape' => 'pill',
        ]);

        self::assertStringContainsString('bg-gray-900', $html);
        self::assertStringContainsString('rounded-full', $html);
        self::assertStringContainsString('href="/"', $html);
    }

    public function testContainerResponsiveLayoutUsesMobileFirstClasses(): void
    {
        $html = (new ContainerElement())->render([
            'direction' => ['d' => 'row', 'm' => 'column'],
            'gap' => ['d' => 'xl', 't' => 'md', 'm' => 'sm'],
            'padding' => ['d' => 'none', 'm' => 'md'],
        ], '<span>Child</span>');

        foreach ([
            'flex-col', 'md:flex-row',
            'md:flex-wrap',
            'gap-2', 'md:gap-4', 'lg:gap-12',
            'p-6', 'md:p-0',
        ] as $class) {
            self::assertStringContainsString($class, $html);
        }
        self::assertStringContainsString('<span>Child</span>', $html);
    }

    public function testDivUsesTheSameResponsiveLayoutContractWhenFlexIsEnabled(): void
    {
        $html = (new DivElement())->render([
            'display' => 'flex',
            'direction' => ['d' => 'column', 't' => 'row'],
            'gap' => ['d' => 'lg', 't' => 'sm'],
            'padding' => ['d' => 'sm', 'm' => 'none'],
        ], 'Child');

        foreach (['flex-row', 'lg:flex-col', 'gap-2', 'lg:gap-8', 'md:p-3'] as $class) {
            self::assertStringContainsString($class, $html);
        }
    }

    public function testResponsiveControlsAreDeclaredInElementSchemas(): void
    {
        foreach ([
            [new HeadingElement(), ['visual_size']],
            [new ContainerElement(), ['direction', 'gap', 'padding']],
            [new DivElement(), ['direction', 'gap', 'padding']],
        ] as [$element, $keys]) {
            $responsive = [];
            foreach ($element->controls() as $control) {
                if (!empty($control['responsive'])) {
                    $responsive[] = $control['key'];
                }
            }
            self::assertSame($keys, $responsive);
        }
    }
}
