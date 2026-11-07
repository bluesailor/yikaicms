<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 模板包设计刻度的两种导入模式（评审 T07）：沿用本站设计（默认）与保留模板外观。
 * 别名链随包导出；值不同的刻度在保留模式下改名新增并改写引用，改名 id 可重复得到；满额、缺失如实列出。
 */
final class BloxDesignScaleImportModeTest extends TestCase
{
    private mixed $previousConfig;

    protected function setUp(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $this->previousConfig = $GLOBALS['_test_config'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = $this->previousConfig;
    }

    /** @param array<string,mixed> $design */
    private function site(array $design): void
    {
        $GLOBALS['_test_config'] = ['blox_design_system' => json_encode(['tokens' => []] + $design, JSON_THROW_ON_ERROR)];
    }

    private static function space(string $id, string $value, string $name = 'S'): array
    {
        return ['id' => $id, 'name' => $name, 'value' => $value];
    }

    /** @return array<string,array<string,mixed>> 按 kind:id 取 review 行 */
    private static function review(array $plan): array
    {
        $out = [];
        foreach ($plan['review'] as $row) $out[$row['kind'] . ':' . $row['id']] = $row;
        return $out;
    }

    /** 来源站：md 16px、hero 72px、gutter = {base}、base 24px；文档引用 md / hero / gutter */
    private function sourceScales(): array
    {
        $this->site(['spaces' => [self::space('md', '16px'), self::space('hero', '72px'), self::space('gutter', '{base}'), self::space('base', '24px')]]);
        return BloxDesignDependencies::exportScales(['space' => ['gutter', 'hero', 'md']]);
    }

    public function testExportFollowsTheAliasChain(): void
    {
        $scales = $this->sourceScales();
        $ids = array_column($scales['space'], 'id');
        sort($ids);
        self::assertSame(['base', 'gutter', 'hero', 'md'], $ids, '别名 gutter 引用的 base 一并导出');
    }

    public function testDefaultModeKeepsLocalValuesAndExplainsSubstitutions(): void
    {
        $scales = $this->sourceScales();
        $this->site(['spaces' => [self::space('md', '20px')]]);
        $plan = BloxDesignDependencies::planScales(['space' => ['gutter', 'hero', 'md']], $scales);
        $rows = self::review($plan);
        self::assertSame('substitute', $rows['space:md']['action'], '本站 md 值不同：沿用本站值并说明');
        self::assertSame(['20px', '16px'], [$rows['space:md']['local'], $rows['space:md']['source']]);
        self::assertMatchesRegularExpression('/^md-[0-9a-f]{6}$/', $rows['space:md']['to'], '同时给出保留模式下会改成的 id');
        self::assertSame('add', $rows['space:hero']['action']);
        self::assertSame('add', $rows['space:gutter']['action']);
        $added = array_column($plan['plan']['space'], 'value', 'id');
        self::assertSame(['base' => '24px', 'gutter' => '{base}', 'hero' => '72px'], [
            'base' => $added['base'] ?? null, 'gutter' => $added['gutter'] ?? null, 'hero' => $added['hero'] ?? null,
        ], '别名不再被跳过：别名照留，被引用的 base 随包补上');
        self::assertArrayNotHasKey('md', $added, '本站已有的 md 不动');
        self::assertSame([], $plan['map'], '默认模式不改写引用');
        self::assertSame($plan, BloxDesignDependencies::planScales(['space' => ['gutter', 'hero', 'md']], $scales, 'site'), '不传模式即默认的 site');
    }

    public function testTemplateModeRenamesConflictsFlattensAliasesAndRewritesReferences(): void
    {
        $scales = $this->sourceScales();
        $this->site(['spaces' => [self::space('md', '20px'), self::space('base', '40px')]]);
        $refs = ['space' => ['gutter', 'hero', 'md']];
        $plan = BloxDesignDependencies::planScales($refs, $scales, 'template');
        $rows = self::review($plan);
        $to = $rows['space:md']['to'];
        self::assertSame('rename', $rows['space:md']['action']);
        self::assertSame(['space' => ['md' => $to]], $plan['map']);
        $added = array_column($plan['plan']['space'], 'value', 'id');
        self::assertSame('16px', $added[$to], '改名新增的刻度带来源值');
        self::assertSame('24px', $added['gutter'], '别名展平为来源的具体值，不跟本站的 base（40px）走');
        self::assertArrayNotHasKey('base', $added, '展平后不需要补 base');
        self::assertSame($plan, BloxDesignDependencies::planScales($refs, $scales, 'template'), '同样的输入得到同样的计划');

        $sections = [['type' => 'section', 'settings' => ['max_width' => 'token:narrow'], 'columns' => [['elements' => [
            ['type' => 'text', 'data' => ['style_padding' => ['d' => 'token:md', 'm' => 'token:hero'], 'style_margin_top' => 'token:md']],
        ]]]]];
        $data = BloxDesignDependencies::remapScaleReferences($sections, $plan['map'])[0]['columns'][0]['elements'][0]['data'];
        self::assertSame(['d' => 'token:' . $to, 'm' => 'token:hero'], $data['style_padding']);
        self::assertSame('token:' . $to, $data['style_margin_top']);

        // 再导一次同一模板：改名后的刻度本站已有且同值 → 复用，不再新增
        $this->site(['spaces' => [self::space('md', '20px'), self::space($to, '16px'), self::space('gutter', '24px'), self::space('hero', '72px')]]);
        $again = BloxDesignDependencies::planScales($refs, $scales, 'template');
        self::assertSame('rename', self::review($again)['space:md']['action']);
        self::assertSame($to, self::review($again)['space:md']['to']);
        self::assertSame([], $again['plan'], '重复导入不越导越多');
    }

    public function testAliasToAnExistingLocalScaleFollowsTheSiteInDefaultModeOnly(): void
    {
        $this->site(['radii' => [['id' => 'md', 'name' => 'M', 'value' => '8px'], ['id' => 'card', 'name' => 'Card', 'value' => '{md}']]]);
        $scales = BloxDesignDependencies::exportScales(['radius' => ['card']]);
        $this->site(['radii' => [['id' => 'md', 'name' => 'M', 'value' => '12px']]]);
        $site = BloxDesignDependencies::planScales(['radius' => ['card']], $scales);
        self::assertSame('{md}', $site['plan']['radius'][0]['value'], '默认模式：别名指向本站的 md（12px）');
        $template = BloxDesignDependencies::planScales(['radius' => ['card']], $scales, 'template');
        self::assertSame('8px', $template['plan']['radius'][0]['value'], '保留模式：展平为来源的 8px');
    }

    public function testMissingLocalOnlyAndFullCatalogsAreReported(): void
    {
        $this->site(['spaces' => [self::space('own', '10px')]]);
        $rows = self::review(BloxDesignDependencies::planScales(['space' => ['ghost', 'own']], []));
        self::assertSame('missing', $rows['space:ghost']['action'], '包里没定义、本站也没有');
        self::assertSame('local', $rows['space:own']['action'], '包里没定义、本站有');

        $full = [];
        for ($i = 0; $i < BloxDesignScale::MAX_ITEMS; $i++) $full[] = self::space('s' . $i, $i . 'px');
        $full[0] = self::space('md', '20px');
        $this->site(['spaces' => $full]);
        $definitions = ['space' => [self::space('md', '16px'), self::space('hero', '72px')]];
        $site = BloxDesignDependencies::planScales(['space' => ['hero', 'md']], $definitions);
        self::assertSame('limit', self::review($site)['space:hero']['action'], '本站已满：不新增，如实列出');
        $template = BloxDesignDependencies::planScales(['space' => ['hero', 'md']], $definitions, 'template');
        self::assertSame('limit', self::review($template)['space:md']['action']);
        self::assertSame([], $template['map'], '新增不了就不改写引用，文档继续用本站的 md');
        self::assertSame([], $template['plan']);
    }

    public function testTypographyComparesValuesNotNames(): void
    {
        $lead = ['id' => 'ty_lead', 'name' => 'Lead', 'size' => ['d' => '22px'], 'line_height' => '1.5'];
        $this->site(['typography' => [['name' => 'Intro'] + $lead]]);
        self::assertSame('same', self::review(BloxDesignDependencies::planScales(['type' => ['ty_lead']], ['type' => [$lead]], 'template'))['type:ty_lead']['action']);
        $this->site(['typography' => [['size' => ['d' => '18px']] + $lead]]);
        $plan = BloxDesignDependencies::planScales(['type' => ['ty_lead']], ['type' => [$lead]], 'template');
        $to = self::review($plan)['type:ty_lead']['to'];
        self::assertSame('rename', self::review($plan)['type:ty_lead']['action']);
        self::assertSame(['d' => '22px'], $plan['plan']['type'][0]['size']);
        self::assertSame(['type_token' => $to], BloxDesignDependencies::remapScaleReferences([['type_token' => 'ty_lead']], $plan['map'])[0]);
    }

    public function testImporterAppliesTheChosenModeAndRejectsUnknownModes(): void
    {
        $package = [
            'format' => BloxTemplateImporter::FORMAT, 'version' => 1, 'type' => 'section', 'name' => 'Scale modes',
            'design' => ['scales' => ['space' => [self::space('md', '16px')]]],
            'document' => [['type' => 'section', 'settings' => [], 'columns' => [['elements' => [
                ['type' => 'heading', 'data' => ['text' => 'Hi', 'style_padding' => ['d' => 'token:md']]],
            ]]]]],
        ];
        $json = json_encode($package, JSON_THROW_ON_ERROR);
        $this->site(['spaces' => [self::space('md', '20px')]]);
        $padding = static fn (array $prepared): string => $prepared['sections'][0]['columns'][0]['elements'][0]['data']['style_padding']['d'];

        $site = BloxTemplateImporter::prepare($json);
        self::assertSame('token:md', $padding($site));
        self::assertSame([], $site['scale_plan']);
        self::assertSame('substitute', $site['scale_review'][0]['action']);

        $template = BloxTemplateImporter::prepare($json, ['scale_mode' => 'template']);
        $to = $template['scale_review'][0]['to'];
        self::assertSame('token:' . $to, $padding($template), '保留模式：文档引用改到改名后的刻度');
        self::assertSame([['id' => $to, 'value' => '16px']], array_map(static fn (array $item): array => ['id' => $item['id'], 'value' => $item['value']], $template['scale_plan']['space']));

        // 往返：目标站按计划补上刻度后再导出，带出的就是改名后的那一项和来源值
        $this->site(['spaces' => [self::space('md', '20px'), self::space($to, '16px')]]);
        self::assertSame(['space' => [['id' => $to, 'name' => 'S', 'value' => '16px']]],
            BloxDesignDependencies::exportScales(BloxDesignDependencies::scaleReferences($template['sections'])));

        $this->expectException(RuntimeException::class);
        BloxTemplateImporter::prepare($json, ['scale_mode' => 'flatten-everything']);
    }
}
