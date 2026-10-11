<?php
/** v2.1 组件（RFC-2）：母版形状、属性定义、发布版本与修订、实例渲染 / 覆盖 / 重置、用量索引、脱离。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxComponents;
use BloxDocumentIndexes;
use BloxDocumentPipeline;
use BloxLoopQuery;
use BlockRenderer;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/models/BloxTemplateModel.php';

final class BloxComponentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BloxComponents::resetForTests();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'user',
                source_ref TEXT NOT NULL DEFAULT '',
                schema_version INTEGER NOT NULL DEFAULT 1,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                requirements TEXT,
                metadata TEXT,
                conditions TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_component_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                component_uuid TEXT NOT NULL,
                doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, draft_data TEXT NOT NULL, published_data TEXT, admin_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, published_at INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, blocks_data TEXT)",
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"key\" TEXT NOT NULL, value TEXT)",
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'page')",
            "CREATE TABLE blox_component_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                component_uuid TEXT NOT NULL,
                version INTEGER NOT NULL DEFAULT 0,
                snapshot TEXT NOT NULL,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    /** @param list<array<string,mixed>> $props */
    private function masterJson(string $title = '产品名', string $buttonUrl = '/contact', array $props = []): string
    {
        $props = $props !== [] ? $props : [
            ['key' => 'title', 'type' => 'text', 'label' => '标题', 'default' => $title, 'targets' => [['node' => 'e_title', 'field' => 'text']]],
            ['key' => 'link', 'type' => 'url', 'label' => '链接', 'default' => $buttonUrl, 'targets' => [['node' => 'e_btn', 'field' => 'url']]],
            ['key' => 'cta', 'type' => 'text', 'label' => '按钮文字', 'default' => '了解更多', 'targets' => [['node' => 'e_btn', 'field' => 'text']]],
        ];
        return json_encode([
            'schema' => 1,
            'settings' => ['component' => ['props' => $props]],
            'sections' => [[
                'id' => 's_card', 'type' => 'section', 'settings' => [],
                'columns' => [['id' => 'c_card', 'elements' => [[
                    'id' => 'e_root', 'type' => 'container', 'data' => ['children' => [
                        ['id' => 'e_title', 'type' => 'heading', 'data' => ['text' => $title, 'level' => 'h3']],
                        ['id' => 'e_btn', 'type' => 'button', 'data' => ['text' => '了解更多', 'url' => $buttonUrl]],
                    ]],
                ]]]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array{id:int,uuid:string} */
    private function publishComponent(string $json): array
    {
        $processed = BloxComponents::processMaster($json, 'tpl0');
        $model = bloxTemplateModel();
        $id = $model->createDraft('component', '产品卡片', $processed['json']);
        $model->publishDraft($id);
        $row = $model->find($id);
        $meta = json_decode((string) $row['metadata'], true)['component'];
        BloxComponents::resetForTests();
        return ['id' => $id, 'uuid' => (string) $meta['uuid']];
    }

    /** @param array<string,mixed> $props @return array<int,mixed> */
    private function pageWith(string $uuid, array $props = [], array $extra = []): array
    {
        return [[
            'id' => 's1', 'type' => 'section', 'settings' => [],
            'columns' => [['id' => 'c1', 'elements' => [[
                'id' => 'e_inst', 'type' => 'component', 'data' => ['component' => $uuid, 'props' => $props] + $extra,
            ]]]],
        ]];
    }

    private function renderPage(array $sections): string
    {
        return BlockRenderer::render((string) json_encode(['schema' => 1, 'settings' => [], 'sections' => $sections], JSON_UNESCAPED_UNICODE));
    }

    // ── 母版形状与属性定义 ──────────────────────────────────────────

    public function testMasterMustHaveExactlyOneRootElementAndNoInstances(): void
    {
        $twoRoots = json_decode($this->masterJson(), true);
        $twoRoots['sections'][0]['columns'][0]['elements'][] = ['id' => 'e_extra', 'type' => 'heading', 'data' => ['text' => 'x']];
        try {
            BloxComponents::processMaster(json_encode($twoRoots, JSON_UNESCAPED_UNICODE), 'tpl0');
            self::fail('两个根元素应被拒绝');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_component_shape'), $e->getMessage());
        }

        // 2.1.0：根元素之下可以放组件；根元素本身不能是组件
        $nested = json_decode($this->masterJson(), true);
        $nested['sections'][0]['columns'][0]['elements'][0]['data']['children'][] = [
            'id' => 'e_inner', 'type' => 'component', 'data' => ['component' => 'cmp_0123456789abcdef'],
        ];
        BloxComponents::processMaster(json_encode($nested, JSON_UNESCAPED_UNICODE), 'tpl0');
        $rootInstance = json_decode($this->masterJson(), true);
        $rootInstance['sections'][0]['columns'][0]['elements'][0] = ['id' => 'e_root', 'type' => 'component', 'data' => ['component' => 'cmp_0123456789abcdef']];
        try {
            BloxComponents::processMaster(json_encode($rootInstance, JSON_UNESCAPED_UNICODE), 'tpl0');
            self::fail('根元素是组件应被拒绝');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_component_root_instance'), $e->getMessage());
        }
    }

    /** 外层母版：根容器里一个标题 + 一个内层组件实例 */
    private function outerMasterJson(string $innerUuid, string $innerTitle = ''): string
    {
        return json_encode([
            'schema' => 1,
            'settings' => ['component' => ['props' => [
                ['key' => 'heading', 'type' => 'text', 'label' => '标题', 'default' => '外层', 'targets' => [['node' => 'o_title', 'field' => 'text']]],
            ]]],
            'sections' => [[
                'id' => 's_o', 'type' => 'section', 'settings' => [],
                'columns' => [['id' => 'c_o', 'elements' => [[
                    'id' => 'o_root', 'type' => 'container', 'data' => ['children' => [
                        ['id' => 'o_title', 'type' => 'heading', 'data' => ['text' => '外层', 'level' => 'h2']],
                        ['id' => 'o_inner', 'type' => 'component', 'data' => ['component' => $innerUuid, 'props' => $innerTitle !== '' ? ['title' => $innerTitle] : []]],
                    ]],
                ]]]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function testNestedComponentsRenderAndDetachAllLevels(): void
    {
        $inner = $this->publishComponent($this->masterJson('内层卡片'));
        $outer = $this->publishComponent($this->outerMasterJson($inner['uuid'], '母版里改过的内层标题'));

        $html = $this->renderPage($this->pageWith($outer['uuid'], ['heading' => '页面上的外层']));
        self::assertStringContainsString('页面上的外层', $html);
        self::assertStringContainsString('母版里改过的内层标题', $html, '外层母版里给内层实例填的属性生效');
        self::assertStringContainsString('data-yk-component="' . $inner['uuid'] . '"', $html);

        $detached = BloxComponents::detachAll($this->pageWith($outer['uuid']));
        self::assertSame(2, $detached['expanded'], '外层与里面的内层都脱离');
        self::assertSame([], BloxComponents::directDependencies($detached['sections'][0]['columns'][0]['elements']));
        $count = 0;
        $tree = BloxComponents::detachInTree(['sections' => $this->pageWith($outer['uuid'])], $count);
        self::assertSame(2, $count);
        self::assertStringNotContainsString('"component"', (string) json_encode($tree));

        self::assertSame([$inner['uuid']], BloxComponents::directDependencies([BloxComponents::find($outer['uuid'])['root']]));
    }

    public function testPublishingRefusesCyclesAndTooDeepNesting(): void
    {
        $a = $this->publishComponent($this->masterJson('A'));
        $b = $this->publishComponent($this->outerMasterJson($a['uuid']));
        // A 改成包含 B：B 已经包含 A，发布 A 会成环
        $model = bloxTemplateModel();
        $model->updateDraft($a['id'], BloxComponents::processMaster($this->outerMasterJson($b['uuid']), 'tpl' . $a['id'])['json'], []);
        try {
            $model->publishDraft($a['id']);
            self::fail('成环的母版不能发布');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_component_cycle'), $e->getMessage());
        }
        self::assertStringNotContainsString($b['uuid'], (string) $model->find($a['id'])['published_data'], '发布版没变');

        // 层数：一条 MAX_NESTING 层的链，再套一层就超了
        $chain = $this->publishComponent($this->masterJson('底层'));
        for ($i = 1; $i < BloxComponents::MAX_NESTING; $i++) {
            $chain = $this->publishComponent($this->outerMasterJson($chain['uuid']));
        }
        try {
            $this->publishComponent($this->outerMasterJson($chain['uuid']));
            self::fail('超过层数不能发布');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_component_too_deep', ['max' => (string) BloxComponents::MAX_NESTING]), $e->getMessage());
        }
    }

    public function testPinnedInstancesKeepTheirVersionUntilUpdated(): void
    {
        $component = $this->publishComponent($this->masterJson('第一版'));
        $model = bloxTemplateModel();
        $model->updateDraft($component['id'], BloxComponents::processMaster($this->masterJson('第二版'), 'tpl' . $component['id'])['json'], []);
        $model->publishDraft($component['id']);
        BloxComponents::resetForTests();

        $pinned = BloxDocumentPipeline::process((string) json_encode(['schema' => 1, 'settings' => [], 'sections' => $this->pageWith($component['uuid'], [], ['pin' => '1'])]), 'pg1');
        self::assertSame(1, $pinned['sections'][0]['columns'][0]['elements'][0]['data']['pin'], '保存时归一成整数');
        self::assertStringContainsString('第一版', $this->renderPage($pinned['sections']));
        self::assertStringContainsString('第二版', $this->renderPage($this->pageWith($component['uuid'])), '不固定就跟随最新');
        self::assertStringContainsString('第二版', $this->renderPage($this->pageWith($component['uuid'], [], ['pin' => 2])), '固定在最新版');

        $detached = BloxComponents::detach(['id' => 'e_inst', 'type' => 'component', 'data' => ['component' => $component['uuid'], 'pin' => 1]]);
        self::assertStringContainsString('第一版', (string) json_encode($detached, JSON_UNESCAPED_UNICODE), '脱离用固定的那一版');
        self::assertSame([2, 1], BloxComponents::availableVersions($component['uuid']));

        // 固定的版本已被清出修订记录：退回最新版（编辑器会提示）
        db()->execute('DELETE FROM blox_component_revisions WHERE version = 1');
        BloxComponents::resetForTests();
        self::assertStringContainsString('第二版', $this->renderPage($this->pageWith($component['uuid'], [], ['pin' => 1])));
    }

    public function testVariantsSitBetweenInstanceOverridesAndMasterDefaults(): void
    {
        $master = json_decode($this->masterJson('卡片'), true);
        $master['settings']['component']['variants'] = [
            ['key' => 'dark', 'label' => '深色', 'values' => ['title' => '深色标题', 'cta' => '深色按钮', 'ghost' => 'x']],
            ['key' => 'dark', 'label' => '重复的键', 'values' => []],
            ['key' => 'Bad Key', 'label' => 'x', 'values' => []],
            ['key' => 'compact', 'label' => '', 'values' => ['link' => 'javascript:alert(1)']],
        ];
        $processed = BloxComponents::processMaster((string) json_encode($master, JSON_UNESCAPED_UNICODE), 'tpl0');
        $variants = $processed['settings']['component']['variants'];
        self::assertSame(['dark', 'compact'], array_column($variants, 'key'), '重复键、非法键丢掉');
        self::assertSame(['title' => '深色标题', 'cta' => '深色按钮'], $variants[0]['values'], '只收已声明的属性');
        self::assertSame('compact', $variants[1]['label'], '没名字用键名');
        self::assertStringNotContainsString('javascript:', (string) json_encode($variants[1]['values']), '值按属性类型清洗');

        $component = $this->publishComponent($processed['json']);
        $html = $this->renderPage($this->pageWith($component['uuid'], ['cta' => '页面上的按钮'], ['variant' => 'dark']));
        self::assertStringContainsString('深色标题', $html, '变体的值');
        self::assertStringContainsString('页面上的按钮', $html, '实例自己的覆盖优先于变体');
        self::assertStringContainsString('卡片', $this->renderPage($this->pageWith($component['uuid'])), '不选变体用母版默认');

        $saved = BloxDocumentPipeline::process((string) json_encode(['schema' => 1, 'settings' => [], 'sections' => $this->pageWith($component['uuid'], [], ['variant' => 'gone'])]), 'pg1');
        self::assertArrayNotHasKey('variant', $saved['sections'][0]['columns'][0]['elements'][0]['data'], '母版里没有的变体不保留');
    }

    public function testRenderStopsOnACycleThatSlippedIn(): void
    {
        // 发布检查之外直接改库造出环（如旧数据、手工导入）：渲染不会无限递归，前台不输出该层
        $a = $this->publishComponent($this->masterJson('A'));
        $b = $this->publishComponent($this->outerMasterJson($a['uuid']));
        $cyclic = BloxComponents::processMaster($this->outerMasterJson($b['uuid']), 'tpl' . $a['id'])['json'];
        db()->update('blox_templates', ['published_data' => $cyclic, 'draft_data' => $cyclic], 'id = ?', [$a['id']]);
        BloxComponents::resetForTests();
        $html = $this->renderPage($this->pageWith($a['uuid']));
        self::assertSame(2, substr_count($html, 'data-yk-component='), 'A → B → (A 停住)');
    }

    public function testPropsSchemaKeepsOnlyValidKeysTypesAndDeclaredTargets(): void
    {
        $processed = BloxComponents::processMaster($this->masterJson('卡片', '/x', [
            ['key' => 'title', 'type' => 'text', 'default' => 'A', 'targets' => [['node' => 'e_title', 'field' => 'text']]],
            ['key' => 'title', 'type' => 'text', 'targets' => [['node' => 'e_title', 'field' => 'text']]],       // 重复键
            ['key' => 'Bad-Key', 'type' => 'text', 'targets' => [['node' => 'e_title', 'field' => 'text']]],     // 非法键
            ['key' => 'video', 'type' => 'video', 'targets' => [['node' => 'e_title', 'field' => 'text']]],      // 非 P0 类型
            ['key' => 'ghost', 'type' => 'text', 'targets' => [['node' => 'e_none', 'field' => 'text']]],        // 节点不存在
            ['key' => 'hack', 'type' => 'text', 'targets' => [['node' => 'e_title', 'field' => '_custom_css']]], // 未声明 / 内部字段
            ['key' => 'link', 'type' => 'url', 'default' => 'javascript:alert(1)', 'targets' => [['node' => 'e_btn', 'field' => 'url']]],
        ]), 'tpl0');

        $props = $processed['settings']['component']['props'];
        self::assertSame(['title', 'link'], array_column($props, 'key'));
        self::assertSame('', $props[1]['default'], '默认值同样按类型清洗，伪协议清空');
    }

    // ── 发布：版本号与修订 ──────────────────────────────────────────

    public function testPublishingAssignsStableUuidBumpsVersionAndKeepsRevisions(): void
    {
        $component = $this->publishComponent($this->masterJson());
        self::assertMatchesRegularExpression(BloxComponents::UUID_PATTERN, $component['uuid']);
        self::assertSame(1, BloxComponents::find($component['uuid'])['version']);

        $model = bloxTemplateModel();
        $model->updateDraft($component['id'], BloxComponents::processMaster($this->masterJson('第二版'), 'tpl0')['json'], []);
        $model->publishDraft($component['id']);
        BloxComponents::resetForTests();

        $definition = BloxComponents::find($component['uuid']);
        self::assertSame(2, $definition['version'], 'uuid 不变、版本 +1');
        self::assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_component_revisions WHERE component_uuid = ?', [$component['uuid']]));

        // 目录元数据表单保存不带组件块，也不能把 uuid 丢掉
        $model->saveMetadata($component['id'], ['purpose' => 'general']);
        BloxComponents::resetForTests();
        self::assertNotNull(BloxComponents::find($component['uuid']));
    }

    // ── 实例渲染：默认、覆盖、重置、母版更新 ─────────────────────────

    public function testInstanceRendersDefaultsOverridesAndFollowsMasterUpdates(): void
    {
        $component = $this->publishComponent($this->masterJson());

        $default = $this->renderPage($this->pageWith($component['uuid']));
        self::assertStringContainsString('产品名', $default);
        self::assertStringContainsString('href="/contact"', $default);
        self::assertStringContainsString('data-yk-component="' . $component['uuid'] . '"', $default);

        $overridden = $this->renderPage($this->pageWith($component['uuid'], ['cta' => '立即询价']));
        self::assertStringContainsString('立即询价', $overridden);
        self::assertStringNotContainsString('了解更多', $overridden);

        // 母版改默认值并发布：没覆盖的跟着变，覆盖过的保持
        $model = bloxTemplateModel();
        $model->updateDraft($component['id'], BloxComponents::processMaster($this->masterJson('新品名'), 'tpl0')['json'], []);
        $model->publishDraft($component['id']);
        BloxComponents::resetForTests();
        $after = $this->renderPage($this->pageWith($component['uuid'], ['cta' => '立即询价']));
        self::assertStringContainsString('新品名', $after);
        self::assertStringContainsString('立即询价', $after);

        // 重置 = 删掉键，回到母版默认
        $reset = $this->renderPage($this->pageWith($component['uuid'], []));
        self::assertStringContainsString('了解更多', $reset);
    }

    public function testMissingComponentRendersNothingOnTheSite(): void
    {
        self::assertSame('', trim(strip_tags($this->renderPage($this->pageWith('cmp_ffffffffffffffff')))));
    }

    public function testDynamicTagsInPropsResolveFromTheLoopRow(): void
    {
        $component = $this->publishComponent($this->masterJson());
        // loop.url 由行的 id / 类型推导，这里用管道兜底验证「先解析标签、再按 url 清洗」
        BloxLoopQuery::pushRow(['title' => '循环里的产品']);
        try {
            $html = $this->renderPage($this->pageWith($component['uuid'], ['title' => '{{loop.title}}', 'link' => '{{loop.no_such_field | /product/42.html}}']));
        } finally {
            BloxLoopQuery::popRow();
        }
        self::assertStringContainsString('循环里的产品', $html);
        self::assertStringContainsString('href="/product/42.html"', $html);
    }

    public function testResolvedTagValuesAreSanitizedByPropType(): void
    {
        $component = $this->publishComponent($this->masterJson());
        // 存储时整串是合法标签所以保留；解析出来的伪协议必须在渲染时按 url 规则清掉
        $html = $this->renderPage($this->pageWith($component['uuid'], ['link' => '{{loop.no_such_field | javascript:alert(1)}}']));
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testInstanceNodeIdsAreSaltedPerInstance(): void
    {
        $component = $this->publishComponent($this->masterJson());
        $definition = BloxComponents::find($component['uuid']);
        $a = BloxComponents::expand($definition, [], 'e_one');
        $b = BloxComponents::expand($definition, [], 'e_two');
        self::assertNotSame($a['id'], $b['id']);
        self::assertStringStartsWith('e_one-', $a['id']);
    }

    // ── 保存：实例属性的清洗 ────────────────────────────────────────

    public function testSavingAPageKeepsOnlyDeclaredPropsAndSanitizesThem(): void
    {
        $component = $this->publishComponent($this->masterJson());
        $json = json_encode(['schema' => 1, 'settings' => [], 'sections' => $this->pageWith($component['uuid'], [
            'link' => 'javascript:alert(1)',
            'cta' => '询价',
            'undeclared' => 'x',
        ])], JSON_UNESCAPED_UNICODE);
        $processed = BloxDocumentPipeline::process((string) $json, 'pg1');
        $data = $processed['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame($component['uuid'], $data['component']);
        self::assertSame(['link' => '', 'cta' => '询价'], $data['props']);

        $tag = json_encode(['schema' => 1, 'settings' => [], 'sections' => $this->pageWith($component['uuid'], ['link' => '{{loop.url}}'])]);
        $kept = BloxDocumentPipeline::process((string) $tag, 'pg1')['sections'][0]['columns'][0]['elements'][0]['data']['props'];
        self::assertSame(['link' => '{{loop.url}}'], $kept, 'url 属性允许整串是一个动态标签');
    }

    // ── 用量索引 ────────────────────────────────────────────────────

    public function testDocumentIndexesTrackComponentUsage(): void
    {
        $component = $this->publishComponent($this->masterJson());
        $sections = $this->pageWith($component['uuid']);
        $sections[0]['columns'][0]['elements'][] = ['id' => 'e_inst2', 'type' => 'component', 'data' => ['component' => $component['uuid']]];
        BloxDocumentIndexes::update('page:7', $sections);
        BloxDocumentIndexes::update('home', $this->pageWith($component['uuid']));

        self::assertSame(['docs' => 2, 'refs' => 3], BloxComponents::usage()[$component['uuid']]);
        self::assertSame(['home', 'page:7'], BloxComponents::usedIn($component['uuid']));

        BloxDocumentIndexes::update('page:7', []);
        self::assertSame(['home'], BloxComponents::usedIn($component['uuid']));
    }

    // ── 脱离 ────────────────────────────────────────────────────────

    public function testDetachReplacesTheInstanceWithPlainElementsCarryingProps(): void
    {
        $component = $this->publishComponent($this->masterJson());
        $result = BloxComponents::detachAll($this->pageWith(
            $component['uuid'],
            ['cta' => '询价', 'title' => '{{loop.title}}'],
            ['_classes' => ['gc_0123456789ab'], '_hide_on' => ['m']]
        ));

        self::assertSame(1, $result['expanded']);
        $root = $result['sections'][0]['columns'][0]['elements'][0];
        self::assertSame('container', $root['type']);
        self::assertSame(['gc_0123456789ab'], $root['data']['_classes']);
        self::assertSame(['m'], $root['data']['_hide_on']);
        self::assertSame('{{loop.title}}', $root['data']['children'][0]['data']['text'], '脱离后保留标签原文，循环里照常解析');
        self::assertSame('询价', $root['data']['children'][1]['data']['text']);
        self::assertNotSame('e_title', $root['data']['children'][0]['id'], '节点 id 重新生成');
        self::assertSame([], BloxComponents::directDependencies([$root]));

        // 母版之后再改，脱离出来的结构不受影响
        self::assertStringContainsString('询价', $this->renderPage($result['sections']));
    }

    // ── 全部脱离 / 修订 / 使用位置 ──────────────────────────────────

    public function testDetachSiteExpandsInstancesInEveryStoreButLeavesMastersAlone(): void
    {
        $component = $this->publishComponent($this->masterJson());
        $page = json_encode(['schema' => 1, 'settings' => [], 'sections' => $this->pageWith($component['uuid'], ['cta' => '询价'])], JSON_UNESCAPED_UNICODE);
        db()->insert('blox_page_drafts', ['page_id' => 3, 'draft_data' => $page, 'published_data' => $page]);
        db()->insert('contents', ['blocks_data' => $page]);
        // 首页用自己的格式、历史快照是数组的数组：按节点形状识别，照样展开
        db()->insert('settings', ['key' => 'home_blox_history', 'value' => json_encode([['sections' => $this->pageWith($component['uuid'])]], JSON_UNESCAPED_UNICODE)]);
        db()->insert('settings', ['key' => 'site_name', 'value' => '"type":"component" 只是文字']);
        BloxDocumentIndexes::update('page:3', $this->pageWith($component['uuid']));

        $result = BloxComponents::detachSite();

        self::assertSame(['documents' => 4, 'instances' => 4], $result);
        foreach (['SELECT draft_data FROM blox_page_drafts', 'SELECT published_data FROM blox_page_drafts', 'SELECT blocks_data FROM contents', "SELECT value FROM settings WHERE \"key\" = 'home_blox_history'"] as $sql) {
            $json = (string) db()->fetchColumn($sql);
            self::assertStringNotContainsString('"type":"component"', $json, $sql);
        }
        self::assertStringContainsString('询价', (string) db()->fetchColumn('SELECT blocks_data FROM contents'));
        self::assertSame('"type":"component" 只是文字', db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = 'site_name'"));
        self::assertSame([], BloxComponents::usedIn($component['uuid']));
        BloxComponents::resetForTests();
        self::assertNotNull(BloxComponents::find($component['uuid']), '母版本身不动');
    }

    public function testRevisionsListNewestFirstAndSnapshotsRestoreEarlierVersions(): void
    {
        $component = $this->publishComponent($this->masterJson('初版'));
        $model = bloxTemplateModel();
        $model->updateDraft($component['id'], BloxComponents::processMaster($this->masterJson('二版'), 'tpl0')['json'], []);
        $model->publishDraft($component['id']);

        self::assertSame([2, 1], array_column(BloxComponents::revisions($component['uuid']), 'version'));
        $snapshot = (string) BloxComponents::revisionSnapshot($component['uuid'], 1);
        self::assertStringContainsString('初版', $snapshot);
        self::assertNull(BloxComponents::revisionSnapshot($component['uuid'], 9));
    }

    public function testUsagePlacesAreDescribedWithEditorLinks(): void
    {
        db()->insert('channels', ['name' => '关于我们']);
        $page = BloxComponents::describeDocKey('page:1');
        self::assertSame('blox_editor.php?id=1', $page['edit_url']);
        self::assertStringContainsString('关于我们', $page['label']);
        self::assertSame('blox_editor.php?home=1', BloxComponents::describeDocKey('home')['edit_url']);
    }

    public function testLoopBindingSuggestionsMatchKeysAndTypes(): void
    {
        $suggestions = BloxComponents::suggestLoopBindings([
            ['key' => 'title', 'type' => 'text'],
            ['key' => 'product_image', 'type' => 'image'],
            ['key' => 'link', 'type' => 'url'],
            ['key' => 'button_text', 'type' => 'text'],   // 没有合适字段：不建议
            ['key' => 'cover', 'type' => 'text'],         // 文本属性不吃图片路径
            ['key' => 'featured', 'type' => 'boolean'],
        ]);
        self::assertSame([
            'title' => '{{loop.title}}',
            'product_image' => '{{loop.cover}}',
            'link' => '{{loop.url}}',
        ], $suggestions);
    }

    // ── 导出 / 整站模板导入 ─────────────────────────────────────────

    public function testTemplatePackagesExpandInstancesSoOtherSitesDoNotNeedTheMaster(): void
    {
        $component = $this->publishComponent($this->masterJson());
        $package = \BloxTemplateImporter::exportPackage([
            'type' => 'section', 'name' => 'With component',
            'draft_data' => (string) json_encode(['sections' => $this->pageWith($component['uuid'], ['cta' => '询价'])], JSON_UNESCAPED_UNICODE),
        ]);
        $json = (string) json_encode($package, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('"type":"component"', $json);
        self::assertStringContainsString('询价', $json);
        self::assertNotContains('component', $package['requires']['elements'] ?? []);
    }

    public function testReindexSiteRebuildsUsageFromImportedDocuments(): void
    {
        $component = $this->publishComponent($this->masterJson());
        db()->insert('channels', ['name' => '关于']);
        $page = (string) json_encode(['schema' => 1, 'sections' => $this->pageWith($component['uuid'])]);
        db()->insert('blox_page_drafts', ['page_id' => 1, 'draft_data' => $page]);
        db()->insert('settings', ['key' => 'home_blox_data', 'value' => (string) json_encode(['sections' => $this->pageWith($component['uuid'])])]);
        db()->insert('blox_component_refs', ['component_uuid' => 'cmp_ffffffffffffffff', 'doc_key' => 'page:99', 'ref_count' => 1]);

        self::assertSame(2, BloxComponents::reindexSite());
        self::assertSame(['home', 'page:1'], BloxComponents::usedIn($component['uuid']));
        self::assertSame([], BloxComponents::usedIn('cmp_ffffffffffffffff'), '旧站残留的用量清掉');
    }

    // ── 多语言属性 ──────────────────────────────────────────────────

    public function testLocalizablePropsResolvePerLanguageWithFallbacks(): void
    {
        $component = $this->publishComponent($this->masterJson('联系我们', '/contact', [
            ['key' => 'title', 'type' => 'text', 'label' => '标题', 'default' => '联系我们', 'localizable' => true,
                'default_i18n' => ['en' => 'Contact us', 'ja' => 'お問い合わせ', 'xx-bad' => 'nope'],
                'targets' => [['node' => 'e_title', 'field' => 'text']]],
            ['key' => 'link', 'type' => 'url', 'label' => '链接', 'default' => '/contact',
                'default_i18n' => ['en' => '/en/contact'],   // 没开 localizable：按语言默认值不收
                'targets' => [['node' => 'e_btn', 'field' => 'url']]],
        ]));
        $definition = BloxComponents::find($component['uuid']);
        self::assertSame(['en' => 'Contact us', 'ja' => 'お問い合わせ'], $definition['props'][0]['default_i18n']);
        self::assertArrayNotHasKey('default_i18n', $definition['props'][1]);

        // 母版按语言默认
        self::assertSame(['title' => 'Contact us'], BloxComponents::effectiveProps($definition, [], 'en'));
        self::assertSame([], BloxComponents::effectiveProps($definition, [], 'zh-CN'), '没有该语言的默认：用母版默认');
        // 实例值优先于母版按语言默认；实例本语言值再优先
        $data = ['props' => ['title' => '实例标题'], 'props_i18n' => ['ja' => ['title' => '実例']]];
        self::assertSame(['title' => '実例'], BloxComponents::effectiveProps($definition, $data, 'ja'));
        self::assertSame(['title' => '实例标题'], BloxComponents::effectiveProps($definition, $data, 'en'));
    }

    public function testSavingKeepsOnlyLocalizablePerLanguageOverrides(): void
    {
        $component = $this->publishComponent($this->masterJson('标题', '/x', [
            ['key' => 'title', 'type' => 'text', 'default' => 'T', 'localizable' => true, 'targets' => [['node' => 'e_title', 'field' => 'text']]],
            ['key' => 'link', 'type' => 'url', 'default' => '/x', 'targets' => [['node' => 'e_btn', 'field' => 'url']]],
        ]));
        $json = json_encode(['schema' => 1, 'settings' => [], 'sections' => $this->pageWith($component['uuid'], [], [
            'props_i18n' => ['en' => ['title' => 'Hello', 'link' => '/en'], 'bad lang' => ['title' => 'x']],
        ])], JSON_UNESCAPED_UNICODE);
        $data = BloxDocumentPipeline::process((string) $json, 'pg1')['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame(['en' => ['title' => 'Hello']], $data['props_i18n']);
    }

    public function testComponentPackagesKeepTheUuidAndUpdateTheSameMasterOnReimport(): void
    {
        $component = $this->publishComponent($this->masterJson('导出版'));
        $row = bloxTemplateModel()->find($component['id']);
        $json = \BloxTemplateImporter::exportJson($row);
        $package = json_decode($json, true);
        self::assertSame($component['uuid'], $package['component']['uuid']);

        // 目标站没有这个母版：按包里的 uuid 新建（草稿，属性目标仍指得到节点）
        db()->execute('DELETE FROM blox_templates');
        BloxComponents::resetForTests();
        try {
            \BloxTemplateImporter::importJson($json);
            self::fail('没有组件授权不能导入母版');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_component_license_required'), $e->getMessage());
        }
        $import = static fn (): array => \BloxFeaturePolicy::asTrustedWrite(static fn (): array => \BloxTemplateImporter::importJson($json));
        $imported = $import();
        $created = bloxTemplateModel()->find($imported['id']);
        self::assertSame($component['uuid'], json_decode((string) $created['metadata'], true)['component']['uuid']);
        bloxTemplateModel()->publishDraft($imported['id']);
        BloxComponents::resetForTests();
        self::assertSame(['title', 'link', 'cta'], array_column(BloxComponents::find($component['uuid'])['props'], 'key'));

        // 再导一次：写进同一母版的草稿，不另建
        $again = $import();
        self::assertTrue($again['merged'] ?? false);
        self::assertSame($imported['id'], $again['id']);
        self::assertSame(1, (int) db()->fetchColumn("SELECT COUNT(*) FROM blox_templates WHERE type = 'component'"));
    }
}
