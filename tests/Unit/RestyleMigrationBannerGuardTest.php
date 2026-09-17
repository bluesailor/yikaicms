<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 迁移 20260825 的轮播「出厂未修改」判定（独立复查 R1 的回归钉子）。
 *
 * 首版只比 title+subtitle：站长删条、换图、改链接、复制条目都会被误判成
 * 出厂内容而切 items_mode=inherit，等于升级替换客户首页展示。本测试把
 * 收紧后的判定条件逐个钉死——这里任何一条放松都意味着 R1 复活。
 */
final class RestyleMigrationBannerGuardTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require ROOT_PATH . '/migrations/20260825_restyle_seed_markup_and_banner_lang.php';
    }

    /** 种子出厂三条（与 install/sql 逐字一致） */
    private function factoryChildren(): array
    {
        $slides = [
            ['title' => '数字化转型解决方案', 'subtitle' => '助力企业实现智能化升级', 'btn1_text' => '了解更多', 'btn2_text' => '', 'image' => 'https://picsum.photos/1920/600?random=1', 'btn1_url' => '/about.html', 'btn2_url' => '', 'link_url' => '', 'link_target' => '_self', 'content_motion' => 'clip-reveal'],
            ['title' => '专业的技术服务团队', 'subtitle' => '7x24小时为您保驾护航', 'btn1_text' => '服务支持', 'btn2_text' => '', 'image' => 'https://picsum.photos/1920/600?random=2', 'btn1_url' => '/service.html', 'btn2_url' => '', 'link_url' => '', 'link_target' => '_self', 'content_motion' => 'slide-left'],
            ['title' => '创新引领未来', 'subtitle' => '持续创新，追求卓越', 'btn1_text' => '了解更多', 'btn2_text' => '', 'image' => 'https://picsum.photos/1920/600?random=3', 'btn1_url' => '/about.html', 'btn2_url' => '', 'link_url' => '', 'link_target' => '_self', 'content_motion' => 'slide-right'],
        ];
        return array_map(
            static fn (array $data, int $i): array => ['id' => 'e_seed' . $i, 'type' => 'home-banner-item', 'data' => $data],
            $slides,
            array_keys($slides)
        );
    }

    public function testFactorySetMatches(): void
    {
        $this->assertTrue(yk_20260825_is_factory_banner_children($this->factoryChildren()));
    }

    public function testOrderDoesNotMatter(): void
    {
        $children = $this->factoryChildren();
        $this->assertTrue(yk_20260825_is_factory_banner_children([$children[2], $children[0], $children[1]]));
    }

    /** 经编辑器重存的文档会显式带默认值（image_mobile=''、background_motion='inherit'），视为等价 */
    public function testResavedDefaultsStillMatch(): void
    {
        $children = $this->factoryChildren();
        foreach ($children as &$child) {
            $child['data']['image_mobile'] = '';
            $child['data']['background_motion'] = 'inherit';
            $child['data']['source_banner_id'] = 0; // schema 外围键不参与比对
            $child['data']['lang'] = '';
        }
        unset($child);
        $this->assertTrue(yk_20260825_is_factory_banner_children($children));
    }

    public function testDeletedSlideDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        array_pop($children);
        $this->assertFalse(yk_20260825_is_factory_banner_children($children), '删到两条=站长改过，不得自动切换');
        $this->assertFalse(yk_20260825_is_factory_banner_children([$children[0]]), '只剩一条同样不得切换');
    }

    public function testDuplicatedSlideDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        $children[2] = $children[0]; // 三条但重复
        $this->assertFalse(yk_20260825_is_factory_banner_children($children), '复制条目=站长改过');
    }

    public function testChangedImageDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        $children[1]['data']['image'] = '/uploads/2026/08/my-banner.jpg';
        $this->assertFalse(yk_20260825_is_factory_banner_children($children), '换图=站长改过');
    }

    public function testChangedButtonUrlDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        $children[0]['data']['btn1_url'] = '/contact.html';
        $this->assertFalse(yk_20260825_is_factory_banner_children($children), '改按钮链接=站长改过');
    }

    public function testChangedMotionDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        $children[0]['data']['content_motion'] = 'zoom-in';
        $this->assertFalse(yk_20260825_is_factory_banner_children($children), '改动效=站长改过');
    }

    public function testChangedLinkTargetDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        $children[0]['data']['link_target'] = '_blank';
        $this->assertFalse(yk_20260825_is_factory_banner_children($children));
    }

    public function testEmptyChildrenDoesNotMatch(): void
    {
        $this->assertFalse(yk_20260825_is_factory_banner_children([]));
    }

    public function testMalformedChildDoesNotMatch(): void
    {
        $children = $this->factoryChildren();
        $children[1] = 'not-an-array';
        $this->assertFalse(yk_20260825_is_factory_banner_children($children));
    }

    /**
     * 出厂指纹是历史事实：老站装的两代出厂图（外链 picsum 与随包 SVG）都必须认得。
     *
     * 这里刻意**不**跟当前 install/sql 种子比对：新装与老站升级是两条链路，
     * 种子跟随参照站演进（tools/sync_demo_seed.php），而这份指纹描述的是
     * 2026-08-25 之前已经发出去的内容，改它等于改写历史，会让老站被误判。
     */
    public function testFactoryFingerprintStaysFrozenHistory(): void
    {
        $picsum = $this->factoryChildren();
        $this->assertTrue(yk_20260825_is_factory_banner_children($picsum), '认不出 picsum 那一代出厂图');

        $bundled = $picsum;
        foreach ($bundled as $i => &$child) {
            $child['data']['image'] = '/assets/images/demo/banner-' . ($i + 1) . '.svg';
        }
        unset($child);
        $this->assertTrue(yk_20260825_is_factory_banner_children($bundled), '认不出 v1.19.3 起随包 SVG 那一代');

        // 改过任一展示字段就不再是出厂内容
        $edited = $bundled;
        $edited[0]['data']['title'] = '我自己写的标题';
        $this->assertFalse(yk_20260825_is_factory_banner_children($edited));
    }

    /**
     * 新装链路与本迁移无关：种子里的首页文档本来就是 items_mode=inherit，
     * 迁移对它无事可做，所以种子的轮播子项可以随参照站演进。
     */
    public function testFreshInstallSeedNeedsNoBannerMigration(): void
    {
        $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/mysql.sql');
        $line = '';
        foreach (explode("\n", $sql) as $candidate) {
            if (str_contains($candidate, "'home_blox_data'")) {
                $line = $candidate;
                break;
            }
        }
        $this->assertNotSame('', $line, '种子里找不到首页文档');

        $modePos = strpos($line, 'items_mode');
        $this->assertIsInt($modePos, '首页文档缺少 items_mode');
        $this->assertStringNotContainsString(
            'custom',
            substr($line, $modePos, 40),
            '种子的轮播必须是 inherit：custom 会把中文文案烤死在文档里'
        );
    }
}
