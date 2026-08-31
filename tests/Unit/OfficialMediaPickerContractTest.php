<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficialMediaPickerContractTest extends TestCase
{
    public function testGlobalMediaPickerSupportsOfficialSourceWithoutSelectingRemoteUrls(): void
    {
        $footer = $this->source('admin/includes/footer.php');

        self::assertStringContainsString('/assets/js/official-media-client.js', $footer);
        self::assertStringContainsString("_mpSource = options.source === 'official' && _mpType === 'image' ? 'official' : 'local';", $footer);
        self::assertStringContainsString("_mpSetSource('official')", $footer);
        self::assertStringContainsString('window._mpImportOfficial', $footer);
        self::assertStringContainsString("OfficialMediaClient.importAsset('/admin/media_api.php', assetId", $footer);
        self::assertStringContainsString('if (_mpCallback) _mpCallback(result.url, result.data);', $footer);
    }

    public function testBloxMediaPickerUsesTheSameOfficialMediaClient(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $overlays = $this->source('admin/blox_editor/partials/overlays.php');

        self::assertStringContainsString('/assets/js/official-media-client.js', $editor);
        self::assertStringContainsString('this.mediaSource = options.source === "official" ? "official" : "local";', $editor);
        self::assertStringContainsString('setMediaSource(source)', $editor);
        self::assertStringContainsString('window.OfficialMediaClient.list', $editor);
        self::assertStringContainsString('window.OfficialMediaClient.importAsset', $editor);
        self::assertStringContainsString('data-testid="blox-official-media-grid"', $overlays);
        self::assertStringContainsString("@click=\"importOfficialMedia(it)\"", $overlays);
    }

    public function testHomepageCtaBackgroundOpensTheRelevantOfficialMediaCategory(): void
    {
        $editor = $this->source('admin/page_edit_advance.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        self::assertStringContainsString('{ usage: "cta", source: "official" }', $editor);
        self::assertStringContainsString("require __DIR__ . '/home-image-control.php'", $workspace);
        self::assertStringContainsString('...window.BloxHomeContentPanel.methods', $this->source('admin/blox_editor.php'));
        self::assertStringContainsString('replaceHomeContentImage(ctrl.key)', $this->source('admin/blox_editor/partials/home-image-control.php'));
        self::assertStringContainsString('{ usage: "cta", source: "official" }', $this->source('assets/js/blox-home-content-panel.js'));
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(ROOT_PATH . '/' . $relativePath);
        self::assertIsString($source);
        return $source;
    }
}
