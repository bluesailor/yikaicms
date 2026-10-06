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

        $nested = json_decode($this->masterJson(), true);
        $nested['sections'][0]['columns'][0]['elements'][0]['data']['children'][] = [
            'id' => 'e_inner', 'type' => 'component', 'data' => ['component' => 'cmp_0123456789abcdef'],
        ];
        try {
            BloxComponents::processMaster(json_encode($nested, JSON_UNESCAPED_UNICODE), 'tpl0');
            self::fail('组件里放组件应被拒绝');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_component_nested'), $e->getMessage());
        }
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
        self::assertFalse(BloxComponents::containsInstance([$root]));

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
}
