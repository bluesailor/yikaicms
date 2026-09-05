<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/**
 * 元素面板 R0 契约：面板的事实来源必须是 BuilderRegistry，不能在编辑器或测试里另维护清单。
 */
final class BloxElementLibraryContractTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    private function visibleMeta(string $context = 'home'): array
    {
        return array_filter(
            BuilderRegistry::meta($context),
            static fn(array $meta): bool => ($meta['paletteVisible'] ?? false) === true
        );
    }

    public function testVisibleElementsExposeStablePaletteMetadata(): void
    {
        $visible = $this->visibleMeta();

        self::assertGreaterThan(20, count($visible));
        foreach ($visible as $type => $meta) {
            self::assertNotSame('', (string) ($meta['label'] ?? ''), $type . ' label');
            self::assertNotSame('', (string) ($meta['category'] ?? ''), $type . ' category');
            self::assertNotSame('', (string) ($meta['icon'] ?? ''), $type . ' icon');
            self::assertIsArray($meta['defaults'] ?? null, $type . ' defaults');
            self::assertIsArray($meta['controls'] ?? null, $type . ' controls');
            self::assertIsBool($meta['container'] ?? null, $type . ' container flag');
            self::assertIsBool($meta['dynamic'] ?? null, $type . ' dynamic flag');
            self::assertIsBool($meta['deprecated'] ?? null, $type . ' deprecated flag');
        }
    }

    public function testCoreElementsRemainAvailableAcrossEditorContexts(): void
    {
        $required = ['heading', 'text', 'button', 'image', 'video', 'container', 'product-catalog'];
        foreach (['home', 'page', 'product', 'content-list', 'contact'] as $context) {
            $meta = BuilderRegistry::meta($context);
            foreach ($required as $type) {
                self::assertArrayHasKey($type, $meta, $context . ' missing ' . $type);
                self::assertFalse($meta[$type]['missing'] ?? true, $context . ' missing flag for ' . $type);
            }
        }
    }

    public function testContainerMetadataDeclaresAChildPolicy(): void
    {
        foreach (BuilderRegistry::meta('page') as $type => $meta) {
            if (($meta['container'] ?? false) !== true) {
                continue;
            }

            self::assertTrue(
                is_array($meta['childRules'] ?? null) || is_array($meta['allowedChildren'] ?? null),
                $type . ' must declare child rules or allowed children'
            );
        }
    }
}
