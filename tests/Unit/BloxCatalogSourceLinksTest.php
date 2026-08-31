<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxCatalogSourceLinksTest extends TestCase
{
    public function testCatalogLinksUseDocumentLanguageAndKeepPermissionGates(): void
    {
        // Isolate the auth stub from the rest of the suite; no site configuration is loaded.
        $script = '<?php require ' . var_export(ROOT_PATH . '/tests/bootstrap.php', true) . ';' . <<<'PHP'
function hasPermission(string $permission): bool { return in_array($permission, $GLOBALS['permissions'], true); }
$results = [];
foreach (['en', 'ja', 'zh-CN'] as $language) {
    foreach ([[], ['edit_product'], ['edit_article'], ['edit_product', 'edit_article']] as $permissions) {
        foreach ([true, false] as $isProductBlox) {
            $isContentListBlox = !$isProductBlox;
            $page = ['lang' => $language];
            require ROOT_PATH . '/admin/blox_editor/source-links.php';
            $results[] = [$language, $permissions, $bloxSourceLinks];
        }
    }
}
echo json_encode($results);
PHP;
        $process = proc_open([PHP_BINARY], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame('', $errors);
        $results = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(24, $results);
        foreach ($results as [$language, $permissions, $links]) {
            foreach (['product-catalog' => 'product', 'content-catalog' => 'article'] as $key => $kind) {
                self::assertSame(in_array('edit_' . $kind, $permissions, true), isset($links[$key]));
                if (isset($links[$key])) {
                    self::assertSame('/admin/' . $kind . '.php?lang=' . rawurlencode($language), $links[$key]['url']);
                }
            }
        }
    }
}
