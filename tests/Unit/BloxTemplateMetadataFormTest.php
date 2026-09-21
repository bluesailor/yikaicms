<?php
/**
 * 区块模板目录元数据：表单字段与保存口径必须对齐。
 *
 * 这类"表单多一格、保存少一格"的漏接不会报错——界面照常保存成功，值悄悄丢掉，
 * 作者以为填过了。所以这里直接比对两边的字段集合。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxSectionMetadata;
use PHPUnit\Framework\TestCase;

final class BloxTemplateMetadataFormTest extends TestCase
{
    private function page(): string
    {
        $source = file_get_contents(ROOT_PATH . '/admin/blox_templates.php');
        self::assertNotFalse($source);
        return (string) $source;
    }

    /** @return array{0:string,1:string} 保存分支与表单两段源码 */
    private function sections(): array
    {
        $page = $this->page();

        $start = strpos($page, "if (\$action === 'save_metadata') {");
        self::assertNotFalse($start, '找不到 save_metadata 分支');
        $handler = substr($page, $start, (int) strpos($page, "if (\$action === 'create_popup')", $start) - $start);

        $formStart = strpos($page, 'data-testid="blox-metadata-form"');
        self::assertNotFalse($formStart, '找不到元数据表单');
        $form = substr($page, $formStart, (int) strpos($page, '</form>', $formStart) - $formStart);

        return [$handler, $form];
    }

    public function testEveryFormFieldIsActuallySaved(): void
    {
        [$handler, $form] = $this->sections();

        preg_match_all('/name="([a-z_]+)(?:\[\])?"/', $form, $matches);
        $fields = array_values(array_unique(array_diff($matches[1], ['action', 'id'])));
        self::assertNotSame([], $fields);

        foreach ($fields as $field) {
            self::assertMatchesRegularExpression(
                '/[\'"]' . preg_quote($field, '/') . '[\'"]/',
                $handler,
                "表单里有 {$field}，保存分支却没读它——填了会被悄悄丢掉"
            );
        }
        self::assertContains('category', $fields, '分类字段必须在表单里');
    }

    /** 下拉里的分类必须是归一化器认得的，否则选了等于没选。 */
    public function testCategoryOptionsComeFromTheSharedVocabulary(): void
    {
        [, $form] = $this->sections();
        self::assertStringContainsString('$metadataCategoryLabels', $form);

        $page = $this->page();
        self::assertStringContainsString('BloxSectionMetadata::categories()', $page);

        // 整页模板的格子不该出现在区块表单里
        self::assertStringContainsString("if (\$category === 'page') {", $page);
        self::assertContains('page', BloxSectionMetadata::categories());
    }
}
