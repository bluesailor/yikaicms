<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailConditionEditorEntryTest extends TestCase
{
    public function testPrioritySynchronizesWhileTyping(): void
    {
        $panel = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/detail-conditions.php');
        $this->assertStringContainsString('@input="conditionPriorityChanged($event.target.value)"', $panel);
        $this->assertStringNotContainsString('@change="conditionPriorityChanged($event.target.value)"', $panel);
    }

    public function testDetailEditorsUseOnlyTheFullConditionPanel(): void
    {
        $header = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/header.php');
        $this->assertSame(2, substr_count($header, "require __DIR__ . '/detail-conditions.php'"));
        $this->assertStringNotContainsString('data-testid="product-template-scope"', $header);
        $this->assertStringNotContainsString('data-testid="article-template-scope"', $header);
        $this->assertStringNotContainsString('x-model.number="docSettings.product_template.ids"', $header);
        $this->assertStringNotContainsString('@change="toggleArticleScopeId(', $header);
        $this->assertStringContainsString('data-testid="product-template-preview"', $header);
        $this->assertStringContainsString('data-testid="article-template-preview"', $header);
    }
}
