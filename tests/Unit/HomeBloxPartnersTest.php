<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxBlockSchema;
use HomeBloxRenderContext;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class HomeBloxPartnersTest extends TestCase
{
    public function testPartnerContractSanitizesAndKeepsLegacyInheritance(): void
    {
        $legacy = HomeBloxBlockSchema::normalize(['block_type' => 'partners']);
        self::assertFalse($legacy['partners_custom']);
        $data = HomeBloxBlockSchema::normalize([
            'block_type' => 'partners', 'partners_custom' => true,
            'partner_items' => array_fill(0, 15, [
                'name' => '<b>Partner</b>', 'url' => 'javascript:alert(1)', 'logo' => 'javascript:alert(2)',
            ]),
        ]);
        self::assertCount(12, $data['partner_items']);
        self::assertSame(['name' => 'Partner', 'url' => '', 'logo' => ''], $data['partner_items'][0]);
        self::assertTrue(HomeBloxBlockSchema::isEditableFieldPath('partners', 'partner_items.11.logo'));
        self::assertFalse(HomeBloxBlockSchema::isEditableFieldPath('partners', 'partner_items.12.logo'));
        $sparse = HomeBloxBlockSchema::normalize([
            'block_type' => 'partners', 'partner_items' => [null, ['name' => 'Second']],
        ]);
        self::assertSame('', $sparse['partner_items'][0]['name']);
        self::assertSame('Second', $sparse['partner_items'][1]['name']);
    }

    public function testRuntimePassesCustomPartnersAndEditorMarkers(): void
    {
        $template = tempnam(sys_get_temp_dir(), 'yk-partners-');
        self::assertNotFalse($template);
        file_put_contents($template, '<?php foreach ($links as $i => $link) { echo e($link["name"]) . e($link["url"]) . $ykHomeFieldAttr("partner_items." . $i . ".name") . $ykHomeFieldAttr("partner_items." . $i . ".logo"); } echo config("home_links_title");');
        try {
            $context = HomeBloxRenderContext::fromHomePageData(
                [], ['partners' => $template], [], [], null, [], true
            );
            $element = ['type' => 'home-block', 'data' => [
                'block_type' => 'partners', 'enabled' => true, 'partners_custom' => true, '_blox_path' => '0.0.0',
                'override_title' => 'Our partners',
                'partner_items' => [
                    ['name' => '', 'url' => '', 'logo' => ''],
                    ['name' => 'Alpha & Beta', 'url' => 'https://example.com/', 'logo' => ''],
                    ['name' => 'Logo partner', 'url' => '/partner', 'logo' => '/uploads/partner.png'],
                ],
            ]];
            $html = $context->renderLegacyBlock($element);
            self::assertStringContainsString('Alpha &amp; Beta', $html);
            self::assertStringContainsString('https://example.com/', $html);
            self::assertStringContainsString('Our partners', $html);
            self::assertStringContainsString('partner_items.1.name', $html);
            self::assertStringContainsString('partner_items.2.logo', $html);

            $element['data']['partner_items'] = [];
            self::assertStringNotContainsString('<section', $context->renderLegacyBlock($element));
        } finally {
            unlink($template);
        }
    }
}
