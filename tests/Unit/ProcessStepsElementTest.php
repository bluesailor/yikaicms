<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class ProcessStepsElementTest extends TestCase
{
    public function testRegistryKeepsStepItemsInsideTheirProcessGroup(): void
    {
        $group = BuilderRegistry::get('process-steps');
        $step = BuilderRegistry::get('process-step');

        self::assertInstanceOf(ProcessStepsElement::class, $group);
        self::assertInstanceOf(ProcessStepElement::class, $step);
        self::assertTrue($group->isContainer());
        self::assertSame(['process-step'], $group->allowedChildren());
        self::assertCount(3, $group->defaultChildren());
        $controls = array_column($group->controls(), null, 'key');
        self::assertTrue($controls['auto_number']['default']);
        self::assertFalse($step->paletteVisible());
        self::assertFalse($step->canBeGenericChild());
        self::assertSame('title', $step->treeLabelField());
        self::assertTrue(BuilderRegistry::allowsChild($group, $step));
        self::assertFalse(BuilderRegistry::allowsChild($group, BuilderRegistry::get('heading')));
    }

    public function testStepRenderEscapesContentAndRejectsUnsafePresentationValues(): void
    {
        $html = (new ProcessStepElement())->render([
            'number' => '<01>',
            'icon' => 'javascript:alert(1)',
            'title' => '<script>alert(1)</script>',
            'text' => '<b>description</b>',
            'accent_color' => 'red;position:fixed',
        ]);

        self::assertStringContainsString('&lt;01&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;description&lt;/b&gt;', $html);
        self::assertStringNotContainsString('position:fixed', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testGroupRenderUsesBoundedResponsiveLayoutOptions(): void
    {
        $html = (new ProcessStepsElement())->render([
            'tablet_columns' => '3',
            'desktop_columns' => '4',
            'gap' => 'sm',
        ], '<article>Step</article>');

        self::assertStringContainsString('md:grid-cols-3', $html);
        self::assertStringContainsString('lg:grid-cols-4', $html);
        self::assertStringContainsString('gap-4', $html);
        self::assertStringContainsString('<article>Step</article>', $html);
    }
}
