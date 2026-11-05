<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxEditorLanguageMenuTest extends TestCase
{
    private string $renderedHtml = '';
    public function testAllLanguagesRemainReachableWithCurrentLanguageFirstAndTextualStatus(): void
    {
        $items = [];
        for ($i = 0; $i < 18; $i++) {
            $items[] = ['current' => $i === 16, 'short' => 'L' . $i, 'label' => 'Language ' . $i,
                'url' => '/admin/blox_editor.php?id=' . ($i + 1), 'testid' => 'language-' . $i,
                'note' => $i === 16 ? 'Current' : 'Create version', 'title' => 'Language ' . $i];
        }
        $dom = $this->render($items);
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//details/summary')->length);
        $links = $xpath->query('//details/div/a');
        self::assertSame(18, $links->length);
        self::assertSame('page', $links->item(0)->getAttribute('aria-current'));
        self::assertSame('language-16', $links->item(0)->getAttribute('data-testid'));
        self::assertStringContainsString('Language 16', $xpath->query('//summary')->item(0)->getAttribute('aria-label'));
        foreach ($links as $link) {
            self::assertStringContainsString('Language ', $link->textContent);
        }
        self::assertSame(18, substr_count($this->renderedHtml, '@click="requestEditorNavigation($event)"'));
        self::assertStringContainsString('Create version', $links->item(1)->textContent);
    }

    public function testLanguageNamesAndLinksAreEscapedAndAreaStateIsPreserved(): void
    {
        $dom = $this->render([['current' => true, 'short' => 'EN', 'label' => '<script>unsafe</script>',
            'title' => 'English "quoted"', 'note' => 'Inherited', 'state' => 'inherit',
            'url' => '/admin/blox_editor.php?template=4&area_lang=en', 'testid' => 'blox-area-language-en']]);
        $xpath = new DOMXPath($dom);
        self::assertSame(0, $xpath->query('//script')->length);
        $link = $xpath->query('//a')->item(0);
        self::assertSame('inherit', $link->getAttribute('data-area-language-state'));
        self::assertSame('/admin/blox_editor.php?template=4&area_lang=en', $link->getAttribute('href'));
        self::assertStringContainsString('<script>unsafe</script>', $link->textContent);
    }

    private function render(array $languageItems): DOMDocument
    {
        $languageTestId = 'blox-language-switch';
        ob_start();
        require ROOT_PATH . '/admin/blox_editor/partials/language-switch.php';
        $html = (string) ob_get_clean();
        $this->renderedHtml = $html;
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $dom;
    }
}
