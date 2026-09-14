<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetailConditionEditorEntryTest extends TestCase
{
    public function testSharedScriptHasTemplateTypeInPageMode(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        $default = strpos($source, "\$templateType = '';");
        $override = strpos($source, "\$templateType = (string) \$templateRow['type'];");
        $this->assertNotFalse($default);
        $this->assertNotFalse($override);
        $this->assertLessThan($override, $default);
    }

    public function testListPublicationReadsDraftAfterLocking(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/blox_templates.php');
        $lock = strpos($source, 'DetailTemplatePublishGuard::lockForPublish');
        $this->assertNotFalse($lock);
        $read = strpos($source, '$row = bloxTemplateModel()->find($id);', $lock);
        $check = strpos($source, '$detailSettings =', $lock);
        $publish = strpos($source, 'bloxTemplateModel()->publishDraft($id);', $lock);
        $this->assertNotFalse($read);
        $this->assertNotFalse($check);
        $this->assertNotFalse($publish);
        $this->assertLessThan($check, $read);
        $this->assertLessThan($publish, $check);
    }

    public function testApiPublicationRejectsChangedDraftAfterLocking(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/blox_template_api.php');
        $lock = strpos($source, 'DetailTemplatePublishGuard::lockForPublish');
        $this->assertNotFalse($lock);
        $read = strpos($source, '$lockedRow = bloxTemplateModel()->find($id);', $lock);
        $guard = strpos($source, "\$lockedRow === null || (string) (\$lockedRow['draft_data'] ?? '') !== \$currentDraftRaw", $lock);
        $reject = strpos($source, "throw new RuntimeException(__('blox_save_conflict'));", $lock);
        $check = strpos($source, '$publishSettings =', $lock);
        $this->assertNotFalse($read);
        $this->assertNotFalse($guard);
        $this->assertNotFalse($reject);
        $this->assertNotFalse($check);
        $this->assertLessThan($guard, $read);
        $this->assertLessThan($reject, $guard);
        $this->assertLessThan($check, $reject);
    }

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
