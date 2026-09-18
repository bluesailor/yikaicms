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

    public function testButtonExposesCommonVisualVariants(): void
    {
        $controls = (new ButtonElement())->controls();
        $variant = null;
        foreach ($controls as $control) {
            if (($control['key'] ?? '') === 'variant') {
                $variant = $control;
                break;
            }
        }

        self::assertNotNull($variant);
        self::assertSame('button_style', $variant['type']);
        self::assertSame(
            ['primary', 'dark', 'outline', 'soft', 'ghost', 'link'],
            array_keys($variant['options'])
        );

        self::assertStringContainsString('bg-transparent', (new ButtonElement())->render([
            'text' => 'Learn more', 'variant' => 'ghost',
        ]));
        self::assertStringContainsString('hover:underline', (new ButtonElement())->render([
            'text' => 'Learn more', 'variant' => 'link',
        ]));
    }

    public function testButtonSupportsOptionalIconOnEitherSide(): void
    {
        $element = new ButtonElement();
        $controls = $element->controls();
        $icon = null;
        $position = null;
        foreach ($controls as $control) {
            if (($control['key'] ?? '') === 'icon') {
                $icon = $control;
            }
            if (($control['key'] ?? '') === 'icon_position') {
                $position = $control;
            }
        }

        self::assertNotNull($icon);
        self::assertSame('icon', $icon['type']);
        self::assertSame('none', $icon['default']);
        self::assertNotNull($position);
        self::assertSame('button_icon_position', $position['type']);
        self::assertSame(['left', 'right'], array_keys($position['options']));

        $hover = null;
        foreach ($controls as $control) {
            if (($control['key'] ?? '') === 'hover_effect') {
                $hover = $control;
                break;
            }
        }
        self::assertNotNull($hover);
        self::assertSame('button_hover_effect', $hover['type']);
        self::assertSame(['default', 'lift', 'bright', 'none'], array_keys($hover['options']));

        $left = $element->render(['text' => 'Contact', 'icon' => 'mail']);
        self::assertStringContainsString('inline-flex items-center justify-center gap-2', $left);
        self::assertStringContainsString('ti ti-mail', $left);
        self::assertLessThan(strpos($left, 'Contact'), strpos($left, 'ti ti-mail'));

        $right = $element->render(['text' => 'Learn more', 'icon' => 'bi:arrow-right', 'icon_position' => 'right']);
        self::assertStringContainsString('bi bi-arrow-right', $right);
        self::assertGreaterThan(strpos($right, 'Learn more'), strpos($right, 'bi bi-arrow-right'));
        self::assertSame(['/assets/bootstrap-icons/bootstrap-icons.min.css'], $element->stylesFor(['icon' => 'bi:arrow-right']));
        self::assertSame([], $element->stylesFor(['icon' => 'mail']));
        self::assertStringNotContainsString('ti ti-none', $element->render(['text' => 'No icon', 'icon' => 'none']));
        self::assertStringContainsString('hover:-translate-y-0.5', $element->render([
            'text' => 'Lift', 'hover_effect' => 'lift',
        ]));
        self::assertStringNotContainsString('hover:', $element->render([
            'text' => 'Static', 'hover_effect' => 'none',
        ]));
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
            // E05：声明式 CSS 试点控件同样按断点存储（type_font_size / gap_px）。
            [new HeadingElement(), ['visual_size', 'type_font_size']],
            [new ContainerElement(), ['direction', 'gap', 'gap_px', 'padding']],
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
