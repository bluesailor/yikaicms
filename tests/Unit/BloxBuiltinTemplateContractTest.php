<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxTemplateImporter.php';
require_once ROOT_PATH . '/includes/builder/BloxBuiltinTemplateProvider.php';

// 联系类元素的模板用到 e()；tests/bootstrap.php 已给 config()/configLang()/__() 打桩，
// 但没有 e()。这里补一个等价实现，而不是整份 require functions.php——那会与桩函数重名。
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * 随包整页模板的契约。
 *
 * 模板是「数据」，元素是「代码」——改元素时没人会想起去看模板。所以这里把两者钉在一起：
 * 模板引用的每个元素类型必须仍然注册、模板必须能过导入管线、渲染出来必须真有内容。
 *
 * 由来 2026-08-24：做这两套模板时发现保存管线会把 accordion 的 items 清空
 * （见 BloxValueSanitizerTest 的复合类型用例），当时任何含 FAQ 的模板都导不进来。
 * 只测「JSON 能解析」是不够的，必须一路测到渲染产物里确实有那段文字。
 */
final class BloxBuiltinTemplateContractTest extends TestCase
{
    /**
     * 2026-09-16 起随包整页模板缩减为三款（在用的公司介绍 / 服务流程 + 功能性 404）。
     * 移出的 restaurant-landing / contact-page / brand-service-landing 见
     * CLAUDE-SECTION-LIBRARY-PROGRESS.md：重设计后进远程精品库，不再随包分发。
     *
     * @return array<string,array{0:string,1:list<string>}>
     */
    public static function templates(): array
    {
        return [
            '公司介绍' => ['company-intro', ['以专业与稳健', '成立年份', '为什么选择我们', '研发设计', '立即咨询']],
            '服务流程' => ['service-process', ['每一步都清晰可控', '需求沟通', '测试验收', '方案与计划', '合作前常见问题']],
        ];
    }

    /** @dataProvider templates */
    public function testTemplateImportsAndRendersItsContent(string $slug, array $needles): void
    {
        $file = ROOT_PATH . '/templates/blox/pages/' . $slug . '.json';
        self::assertFileExists($file);

        $prepared = BloxTemplateImporter::prepare((string) file_get_contents($file));
        self::assertSame('page', $prepared['type']);
        self::assertNotEmpty($prepared['sections'], '导入后不能是空文档');

        // contact_form / contact_map / contact_cards 要读表单模板与联系方式设置，
        // 依赖完整应用上下文与数据库；单元层渲染不了，它们由后台页面冒烟与 e2e 覆盖。
        // 这里渲染其余段落——四个断言词都落在这些段里，仍能验证模板真的有内容。
        $needsAppContext = ['contact_form', 'contact_map', 'contact_cards'];
        $renderable = [];
        foreach ($prepared['sections'] as $section) {
            $hasAppElement = false;
            foreach ((array) ($section['columns'] ?? []) as $column) {
                foreach ((array) ($column['elements'] ?? []) as $element) {
                    if (in_array((string) ($element['type'] ?? ''), $needsAppContext, true)) {
                        $hasAppElement = true;
                    }
                }
            }
            if (!$hasAppElement) {
                $renderable[] = $section;
            }
        }
        self::assertNotEmpty($renderable, '至少要有一段能脱离应用上下文渲染');

        $html = BlockRenderer::render((string) json_encode(
            ['schema' => 1, 'settings' => [], 'sections' => $renderable],
            JSON_UNESCAPED_UNICODE
        ));
        foreach ($needles as $needle) {
            self::assertStringContainsString($needle, $html, "渲染产物里缺少「{$needle}」");
        }
    }

    /** @dataProvider templates */
    public function testEveryElementTypeUsedIsStillRegistered(string $slug, array $needles): void
    {
        $known = [];
        foreach (BuilderRegistry::all() as $element) {
            $known[$element->type()] = true;
        }

        $document = json_decode((string) file_get_contents(ROOT_PATH . '/templates/blox/pages/' . $slug . '.json'), true);
        $missing = [];
        $walk = static function (array $elements) use (&$walk, $known, &$missing): void {
            foreach ($elements as $element) {
                $type = (string) ($element['type'] ?? '');
                if ($type !== '' && !isset($known[$type])) {
                    $missing[$type] = true;
                }
                foreach ((array) ($element['data']['children'] ?? []) as $child) {
                    $walk([$child]);
                }
            }
        };
        foreach ((array) ($document['document']['sections'] ?? []) as $section) {
            foreach ((array) ($section['columns'] ?? []) as $column) {
                $walk((array) ($column['elements'] ?? []));
            }
        }

        self::assertSame([], array_keys($missing), '模板引用了已不存在的元素类型');
    }

    public function testProviderListsPageTemplatesWithExistingThumbnails(): void
    {
        $items = [];
        foreach ((new BloxBuiltinTemplateProvider())->items('page') as $item) {
            $items[$item['key']] = $item;
        }

        foreach (['builtin:company-intro', 'builtin:service-process', 'builtin:404-route-lost'] as $key) {
            self::assertArrayHasKey($key, $items);
            self::assertNotSame('', $items[$key]['name']);
            self::assertNotSame('', $items[$key]['description']);
            // 缩略图缺失在界面上是一张碎图，属于「发出去才被发现」的那类问题
            self::assertFileExists(ROOT_PATH . $items[$key]['thumbnail']);
        }
    }

    /**
     * 整页模板的「页面外框偏好 + 区块锚点」必须完整穿过导入管线。
     *
     * 原先靠 restaurant-landing 这份随包模板当夹具；2026-09-16 该模板移出随包目录后，
     * 改用内联夹具——被保护的是**管线行为**（settings 不被吞、anchor_id 不被重写、
     * 锚点链接照常渲染），它与目录里恰好有哪几款模板无关，不该随目录增减而失去覆盖。
     */
    public function testPageFramePreferencesAndAnchorsSurviveTheImportPipeline(): void
    {
        $frame = array_fill_keys([
            'page_header_hidden', 'page_footer_hidden', 'page_breadcrumb_hidden', 'page_title_hidden', 'page_sidebar_hidden',
        ], true);
        $package = json_encode([
            'format' => 'yikaicms-blox-template',
            'version' => 1,
            'type' => 'page',
            'name' => 'Frame fixture',
            'requires' => ['elements' => ['heading', 'button'], 'plugins' => []],
            'document' => [
                'schema' => 1,
                'settings' => $frame,
                'sections' => [
                    [
                        'type' => 'section',
                        'settings' => ['anchor_id' => 'fixture-header'],
                        'columns' => [['elements' => [
                            ['type' => 'heading', 'data' => ['text' => '锚点夹具', 'level' => 'h1']],
                            ['type' => 'button', 'data' => ['text' => '去预约', 'url' => '#fixture-reservation']],
                        ]]],
                    ],
                    [
                        'type' => 'section',
                        'settings' => ['anchor_id' => 'fixture-reservation'],
                        'columns' => [['elements' => [
                            ['type' => 'heading', 'data' => ['text' => '预约', 'level' => 'h2']],
                        ]]],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $prepared = BloxTemplateImporter::prepare($package);
        self::assertSame($frame, $prepared['settings'], '页面外框偏好不能在导入时被吞掉');
        self::assertSame($frame, json_decode($prepared['draft_json'], true)['settings']);
        self::assertSame('fixture-header', $prepared['sections'][0]['settings']['anchor_id']);
        self::assertSame(
            'fixture-reservation',
            $prepared['sections'][array_key_last($prepared['sections'])]['settings']['anchor_id']
        );

        $header = BlockRenderer::render(json_encode([$prepared['sections'][0]], JSON_THROW_ON_ERROR));
        self::assertStringContainsString('href="#fixture-reservation"', $header, '页内锚点链接必须照常渲染');
    }

    public function testServiceProcessKeepsSixIndividuallyEditableSteps(): void
    {
        $document = json_decode(
            (string) file_get_contents(ROOT_PATH . '/templates/blox/pages/service-process.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $groups = [];
        foreach ((array) ($document['document']['sections'] ?? []) as $section) {
            foreach ((array) ($section['columns'] ?? []) as $column) {
                foreach ((array) ($column['elements'] ?? []) as $element) {
                    if (($element['type'] ?? '') === 'process-steps') {
                        $groups[] = $element;
                    }
                }
            }
        }

        self::assertCount(1, $groups);
        $steps = (array) ($groups[0]['data']['children'] ?? []);
        self::assertCount(6, $steps);
        self::assertSame(array_fill(0, 6, 'process-step'), array_column($steps, 'type'));
        self::assertSame(['01', '02', '03', '04', '05', '06'], array_column(array_column($steps, 'data'), 'number'));
    }

    /** 素材必须随包，不能指向 /uploads/——那是站点自有内容，模板装到别的站就是裂图。 */
    public function testTemplateAssetsShipWithThePackage(): void
    {
        foreach (array_column(self::templates(), 0) as $slug) {
            $raw = (string) file_get_contents(ROOT_PATH . '/templates/blox/pages/' . $slug . '.json');
            self::assertStringNotContainsString('/uploads/', $raw, $slug . ' 不得引用 /uploads/ 下的素材');

            preg_match_all('#"(/(?:assets/)?images/[A-Za-z0-9._/-]+)"#', $raw, $matches);
            foreach (array_unique($matches[1]) as $asset) {
                self::assertFileExists(ROOT_PATH . $asset, $slug . ' 引用了不存在的随包素材');
            }
        }
    }
}
