<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeAboutContent;
use HomeAboutLocalization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HomeAboutEscapingTest extends TestCase
{
    private const INVALID_UTF8_TITLE = "\xC3\x28 荣誉";
    private mixed $previous;
    private bool $created;

    protected function setUp(): void
    {
        $this->previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'site_lang' => 'zh-CN', 'home_about_title' => 'Original title',
            'home_about_content' => 'Original body', 'home_about_link' => '/company.html',
            'home_about_tag_title' => 'Original badge', 'home_about_tag_desc' => 'Original caption',
        ];
        $this->created = !db()->tableExists('channels');
        if ($this->created) {
            db()->execute('CREATE TABLE channels (id INTEGER PRIMARY KEY, slug TEXT, status INTEGER)');
        }
    }

    protected function tearDown(): void
    {
        if ($this->created) { db()->execute('DROP TABLE channels'); }
        if ($this->previous === null) { unset($GLOBALS['yikai_config_runtime_overrides']); }
        else { $GLOBALS['yikai_config_runtime_overrides'] = $this->previous; }
    }

    /** @return array<string,array{string,string,string}> */
    public static function languages(): array
    {
        return [
            'English' => ['en', 'Honors & awards', 'Since 2010'],
            'Japanese' => ['ja', '実績・認証', '2010年から'],
        ];
    }

    private function language(string $lang, string $title, string $description): void
    {
        // SITE_LANG is request-local; the default language must stay zh-CN.
        define('SITE_LANG', $lang);
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_title_' . $lang] = $title;
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_desc_' . $lang] = $description;
    }

    private static function badgeHtml(string $title, string $description): string
    {
        return '<div class="bg-primary text-white rounded-lg p-6">'
            . '<h3 class="text-xl font-bold text-white m-0">'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<p class="text-white m-0">'
            . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></div>';
    }

    private static function editableBadgeHtml(string $title, string $description): string
    {
        return '<h3 class="text-xl font-bold text-inherit m-0">'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>'
            . '<p class="text-inherit m-0">'
            . htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }

    /** @return array<string,mixed> */
    private static function oldSection(string $title, string $description, ?array $parts = null): array
    {
        $html = self::badgeHtml($title, $description);
        // Pre-existing provenance bypasses bind(), so write-time guards cannot mask read-time bugs.
        return ['id' => 'home_s_1', 'columns' => [
            ['id' => 'home_s_1_text', 'span' => 6, 'elements' => []],
            ['id' => 'home_s_1_visual', 'span' => 6, 'elements' => [
                ['id' => 'home_s_1_image_badge', 'type' => 'div', 'data' => ['children' => [
                    ['id' => 'home_s_1_image', 'type' => 'image', 'data' => []],
                    ['id' => 'home_s_1_caption', 'type' => 'text', 'data' => [
                        'html' => $html,
                        HomeAboutLocalization::KEY => ['lang' => 'zh-CN', 'fields' => [
                            'html' => ['source' => $html, 'parts' => $parts ?? [
                                'override_tag_title' => $title, 'override_tag_description' => $description,
                            ]],
                        ]],
                    ]],
                ]]],
            ]],
        ]];
    }

    private static function captionHtml(array $section): string
    {
        return $section['columns'][1]['elements'][0]['data']['children'][1]['data']['html'];
    }

    public function testBootstrapStubMatchesActualProductionEscaping(): void
    {
        $inputs = [null, '', 'Quotes " & <tag>', self::INVALID_UTF8_TITLE];
        // Load real helpers outside PHPUnit's bootstrap instead of duplicating production e().
        $code = 'define("ROOT_PATH", ' . var_export(realpath(ROOT_PATH), true) . ');'
            . 'require ROOT_PATH . "/includes/functions.php";'
            . '$inputs = unserialize(base64_decode("' . base64_encode(serialize($inputs)) . '"), ["allowed_classes" => false]);'
            . 'echo json_encode(array_map("e", $inputs), JSON_THROW_ON_ERROR);';
        $process = proc_open([PHP_BINARY, '-r', $code], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame(json_decode($output, true, 512, JSON_THROW_ON_ERROR), array_map('e', $inputs));
    }

    #[DataProvider('languages')]
    public function testNewInvalidUtf8BadgeUsesActualReadTimeLocalization(string $lang, string $title, string $description): void
    {
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_title'] = self::INVALID_UTF8_TITLE;
        $section = HomeAboutContent::toSection([], 'home_s_1');
        $before = $section;
        $this->language($lang, $title, $description);
        $result = HomeAboutLocalization::localize($section);
        self::assertSame(self::editableBadgeHtml($title, $description), self::captionHtml($result));
        self::assertSame($before, $section);
        self::assertSame($result, HomeAboutLocalization::localize($result));
    }

    #[DataProvider('languages')]
    public function testOldInvalidUtf8BindingUsesActualReadTimeLocalization(string $lang, string $title, string $description): void
    {
        $section = self::oldSection(self::INVALID_UTF8_TITLE, 'Original caption');
        $before = $section;
        $this->language($lang, $title, $description);
        $result = HomeAboutLocalization::localize($section);
        self::assertSame(self::badgeHtml($title, $description), self::captionHtml($result));
        self::assertSame($before, $section);
    }

    #[DataProvider('languages')]
    public function testOldDuplicateBindingKeepsBothOriginalValues(string $lang, string $title, string $description): void
    {
        $section = json_decode(json_encode(self::oldSection('Same', 'Same'), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->language($lang, $title, $description);
        self::assertSame($section, HomeAboutLocalization::localize($section));
    }

    #[DataProvider('languages')]
    public function testReadTimeRejectsRepeatedSourceWithOnlyOneBoundPart(string $lang, string $title, string $description): void
    {
        $section = self::oldSection('Same', 'Same', ['override_tag_title' => 'Same']);
        $this->language($lang, $title, $description);
        self::assertSame($section, HomeAboutLocalization::localize($section));
    }

    #[DataProvider('languages')]
    public function testOldMissingBindingKeepsAmbiguousBadgeUnchanged(string $lang, string $title, string $description): void
    {
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_title'] = 'Same';
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_desc'] = 'Same';
        $section = self::oldSection('Same', 'Same');
        unset($section['columns'][1]['elements'][0]['data']['children'][1]['data'][HomeAboutLocalization::KEY]);
        $before = $section;
        $this->language($lang, $title, $description);
        self::assertSame(self::captionHtml($section), self::captionHtml(HomeAboutLocalization::localize($section)));
        self::assertSame($before, $section);
    }

    #[DataProvider('languages')]
    public function testValidBadgeActuallyTranslatesAndEscapesTargetMarkup(string $lang, string $title, string $description): void
    {
        $section = HomeAboutContent::toSection([], 'home_s_1');
        $this->language($lang, $title . ' <script>"x"</script>', $description);
        $result = HomeAboutLocalization::localize($section);
        self::assertSame(self::editableBadgeHtml($title . ' <script>"x"</script>', $description), self::captionHtml($result));
        self::assertStringNotContainsString('<script>', self::captionHtml($result));
    }

    #[DataProvider('languages')]
    public function testMissingTranslationDoesNotEraseOriginalCaption(string $lang, string $title, string $description): void
    {
        $section = self::oldSection('Original badge', 'Original caption');
        $GLOBALS['yikai_config_runtime_overrides']['home_about_tag_desc'] = '';
        $this->language($lang, $title, '');
        $result = HomeAboutLocalization::localize($section);
        self::assertSame(self::badgeHtml($title, 'Original caption'), self::captionHtml($result));
    }

    #[DataProvider('languages')]
    public function testEditedOrClearedCaptionNeverGetsReplaced(string $lang, string $title, string $description): void
    {
        $this->language($lang, $title, $description);
        foreach (['<p>Customer content</p>', ''] as $edited) {
            $section = self::oldSection('Original badge', 'Original caption');
            $section['columns'][1]['elements'][0]['data']['children'][1]['data']['html'] = $edited;
            self::assertSame($section, HomeAboutLocalization::localize($section));
        }
    }
}
