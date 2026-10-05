<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxTemplateDesignImportTest extends TestCase
{
    private mixed $previousConfig;

    protected function setUp(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $this->previousConfig = $GLOBALS['_test_config'] ?? [];
        $GLOBALS['_test_config'] = ['blox_design_system' => json_encode([
            'tokens' => [['id' => 'local_color', 'name' => 'Brand', 'value' => '#123456']],
            'styles' => [
                ['id' => 'same', 'name' => 'Local', 'color' => '#111111'],
                ['id' => 'other', 'name' => 'Shared name', 'color' => '#222222'],
                ['id' => 'old', 'name' => 'Old', 'status' => 'archived'],
            ],
        ], JSON_THROW_ON_ERROR)];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = $this->previousConfig;
    }

    private function package(): array
    {
        return [
            'format' => BloxTemplateImporter::FORMAT, 'version' => 1, 'type' => 'section', 'name' => 'Design import',
            'requires' => ['design_styles' => ['old']],
            'design' => ['styles' => [['id' => 'same', 'name' => 'Shared name', 'color' => '#ffffff']]],
            'document' => [['type' => 'section', 'settings' => ['bg_color' => 'var(--yk-color-remote)'],
                'columns' => [['elements' => [['type' => 'heading', 'data' => [
                    'text' => 'Keep this text', '_global_style' => 'same',
                ]]]]],
            ]],
        ];
    }

    private function prepare(array $options = [], ?array $package = null): array
    {
        return BloxTemplateImporter::prepare(json_encode($package ?? $this->package(), JSON_THROW_ON_ERROR), $options);
    }

    /** 2.0.5：模板包带上文档用到的设计刻度；导入端只补本站没有的 id，同 id 的本站刻度不动。 */
    public function testScaleTokensTravelWithTemplatesAndOnlyFillGaps(): void
    {
        $GLOBALS['_test_config']['blox_design_system'] = json_encode(['tokens' => [],
            'spaces' => [['id' => 'sp_hero', 'name' => 'Hero gap', 'value' => '72px'], ['id' => 'md', 'name' => 'M', 'value' => '16px']],
            'typography' => [['id' => 'ty_lead', 'name' => 'Lead', 'size' => ['d' => '22px', 'm' => '19px'], 'line_height' => '1.5']],
        ], JSON_THROW_ON_ERROR);
        $sections = [['type' => 'section', 'settings' => ['max_width' => 'token:narrow'],
            'columns' => [['elements' => [
                ['type' => 'heading', 'data' => ['text' => 'Hi', 'type_token' => 'ty_lead', 'style_padding' => ['d' => 'token:sp_hero', 'm' => 'token:md']]],
            ]]]]];
        self::assertSame(['container' => ['narrow'], 'space' => ['md', 'sp_hero'], 'type' => ['ty_lead']],
            BloxDesignDependencies::scaleReferences($sections));
        $package = BloxTemplateImporter::exportPackage(['type' => 'section', 'name' => 'Scales',
            'published_data' => json_encode($sections, JSON_THROW_ON_ERROR)]);
        self::assertSame(['sp_hero', 'md'], array_column($package['design']['scales']['space'], 'id'));
        self::assertSame('ty_lead', $package['design']['scales']['type'][0]['id']);

        // 目标站：没有 sp_hero / ty_lead；md 是本站自己的值（不同），不能被模板覆盖
        $GLOBALS['_test_config']['blox_design_system'] = json_encode(['tokens' => [],
            'spaces' => [['id' => 'md', 'name' => 'M', 'value' => '20px']]], JSON_THROW_ON_ERROR);
        $prepared = BloxTemplateImporter::prepare(json_encode($package, JSON_THROW_ON_ERROR));
        self::assertSame(['sp_hero'], array_column($prepared['scale_plan']['space'], 'id'));
        self::assertSame('72px', $prepared['scale_plan']['space'][0]['value']);
        self::assertSame(['ty_lead'], array_column($prepared['scale_plan']['type'], 'id'));
        self::assertArrayNotHasKey('container', $prepared['scale_plan'], '出厂刻度本站已有，不补');
    }

    public function testImportReportsConflictsWithoutChangingLocalDesign(): void
    {
        $before = BloxDesignSystem::snapshot();
        $result = $this->prepare();
        self::assertSame(['remote'], $result['design_diagnostics']['missing_tokens']);
        self::assertSame(['old'], $result['design_diagnostics']['archived_styles']);
        self::assertSame(['same'], $result['design_diagnostics']['conflicting_styles']);
        self::assertSame(['same -> other'], $result['design_diagnostics']['same_name_styles']);
        self::assertSame('#ffffff', $result['sections'][0]['columns'][0]['elements'][0]['data']['_global_style_snapshot']['color']);
        self::assertSame($before, BloxDesignSystem::snapshot());
        self::assertSame('#111111', BloxDesignSystem::resolveStyle('same')['color']);
    }

    public function testMappingRewritesReferencesAndRequirementsOnly(): void
    {
        $result = $this->prepare(['tokens' => ['remote' => 'local_color'], 'styles' => ['same' => 'other', 'old' => 'other']]);
        $data = $result['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame('other', $data['_global_style']);
        self::assertSame('#222222', $data['_global_style_snapshot']['color']);
        self::assertSame('Keep this text', $data['text']);
        self::assertSame('var(--yk-color-local_color)', $result['sections'][0]['settings']['bg_color']);
        self::assertSame(['local_color'], $result['requirements']['design_tokens']);
        self::assertSame(['other'], $result['requirements']['design_styles']);
    }

    public function testDetachRemovesBindingAndSnapshotButKeepsElementSettings(): void
    {
        $result = $this->prepare(['style_mode' => 'detach']);
        $data = $result['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertEmpty($data['_global_style'] ?? '');
        self::assertEmpty($data['_global_style_snapshot'] ?? []);
        self::assertSame('Keep this text', $data['text']);
        self::assertSame([], $result['requirements']['design_styles']);
    }

    public function testMappingRejectsMissingOrArchivedTargetsAndMalformedInput(): void
    {
        foreach ([['tokens' => ['remote' => 'missing']], ['styles' => ['same' => 'old']],
            ['styles' => 'not-an-array'], ['styles' => ['same' => []]], ['style_mode' => 'overwrite']] as $options) {
            try {
                $this->prepare($options);
                self::fail('Invalid mapping was accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('blox_import_design_invalid', $error->getMessage());
            }
        }
    }

    public function testExportIncludesOnlyUsedDefinitionsAndLegacyPackagesStillWork(): void
    {
        $package = $this->package();
        unset($package['design']);
        $result = $this->prepare([], $package);
        self::assertContains('same', $result['design_diagnostics']['unverified_styles']);
        $export = BloxTemplateImporter::exportPackage([
            'type' => 'section', 'name' => 'Export', 'draft_data' => $result['draft_json'],
        ]);
        self::assertSame(['same'], array_column($export['design']['styles'], 'id'));
        self::assertSame([], $export['design']['tokens']);
    }

    public function testMalformedSourceStylesCannotInjectCssOrRaiseConversionWarnings(): void
    {
        $package = $this->package();
        $package['design']['styles'][0]['color'] = ['bad'];
        $result = $this->prepare([], $package);
        self::assertEmpty($result['sections'][0]['columns'][0]['elements'][0]['data']['_global_style_snapshot'] ?? []);
        self::assertNull(BloxDesignSystem::normalizeStyleSnapshot(['radius' => ['bad']]));
    }

    public function testColorsUsedOnlyInsidePortableStyleAreReviewedAndMapped(): void
    {
        $package = $this->package();
        $package['design']['styles'][0]['color'] = 'var(--yk-color-style_color)';
        $result = $this->prepare(['tokens' => ['style_color' => 'local_color']], $package);
        self::assertSame(['remote', 'style_color'], $result['design_diagnostics']['missing_tokens']);
        self::assertSame(['local_color', 'remote'], $result['requirements']['design_tokens']);
        self::assertSame('var(--yk-color-local_color)', $result['sections'][0]['columns'][0]['elements'][0]['data']['_global_style_snapshot']['color']);
    }
}
