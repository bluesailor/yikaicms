<?php
/**
 * 翻译流水线（tools/i18n）：导出 → 校验（含故意出错的译文）→ 合入 → 进度 / 过时检测。
 * 在临时目录里搭一个最小仓库，不碰真实语言包。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/tools/i18n/I18nPipeline.php';

final class I18nPipelineTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yk-i18n-' . bin2hex(random_bytes(4));
        foreach (['lang', 'includes/i18n', 'plugins/demo/lang', 'install/lang', 'out'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
        copy(ROOT_PATH . '/includes/i18n/LanguageRegistry.php', $this->root . '/includes/i18n/LanguageRegistry.php');
        $this->pack('lang/zh-CN.php', ['home_title' => '首页', 'home_hello' => '你好，:name', 'home_lines' => "第一行\n第二行", 'home_link' => '查看 <a href="/x">详情</a>', 'admin_x' => '后台']);
        $this->pack('lang/en.php', ['home_title' => 'Home', 'home_hello' => 'Hello, :name', 'home_lines' => "Line one\nLine two", 'home_link' => 'See <a href="/x">details</a>', 'admin_x' => 'Admin']);
        $this->pack('lang/ja.php', ['home_title' => 'ホーム', 'home_hello' => 'こんにちは、:name', 'home_lines' => "一行目\n二行目", 'home_link' => '<a href="/x">詳細</a>を見る', 'admin_x' => '管理']);
        $this->pack('plugins/demo/lang/zh-CN.php', ['demo_ok' => '好的']);
        $this->pack('plugins/demo/lang/en.php', ['demo_ok' => 'OK fine']);
        $this->pack('install/lang/zh.php', ['install_go' => '开始安装']);
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    /** @param array<string,string> $values */
    private function pack(string $rel, array $values): void
    {
        $body = "<?php\nreturn [\n";
        foreach ($values as $k => $v) {
            $body .= '    ' . I18nPipeline::phpString($k) . ' => ' . I18nPipeline::phpString($v) . ",\n";
        }
        file_put_contents($this->root . '/' . $rel, $body . "];\n");
    }

    /** @param list<array<string,mixed>> $rows */
    private function writeJsonl(string $name, array $rows): string
    {
        $path = $this->root . '/out/' . $name;
        file_put_contents($path, implode("\n", array_map(static fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rows)) . "\n");
        return $path;
    }

    /** @return array<string,string> 合格的韩语译文 */
    private function korean(): array
    {
        return ['home_title' => '홈', 'home_hello' => '안녕하세요, :name', 'home_lines' => "첫 줄\n둘째 줄", 'home_link' => '<a href="/x">자세히</a> 보기'];
    }

    public function testExportValidateMergeStatusRoundTrip(): void
    {
        $p = new I18nPipeline($this->root);
        $result = $p->export('ko', ['core'], 'home_', 3, $this->root . '/out');
        self::assertSame(4, $result['total']);
        self::assertSame(['ko-core-001.jsonl', 'ko-core-002.jsonl'], array_column($result['shards'], 'file'));
        $manifest = json_decode((string) file_get_contents($this->root . '/out/manifest.json'), true);
        self::assertSame(4, $manifest['total']);

        $rows = array_merge(
            I18nPipeline::readJsonl($this->root . '/out/ko-core-001.jsonl'),
            I18nPipeline::readJsonl($this->root . '/out/ko-core-002.jsonl')
        );
        self::assertSame('home_title', $rows[0]['key']);
        self::assertSame('Home', $rows[0]['en']);
        self::assertSame('home page', $rows[0]['note']);
        self::assertSame(I18nPipeline::hash('首页'), $rows[0]['src_hash']);

        // 故意出错：丢占位符、动了标签、残留汉字、少一行
        $bad = [];
        foreach (I18nPipeline::readJsonl($this->root . '/out/ko-core-001.jsonl') as $row) {
            unset($row['_line']);
            $row['text'] = $this->korean()[$row['key']];
            $bad[] = $row;
        }
        $bad[1]['text'] = '안녕하세요';
        $bad[2]['text'] = "첫 줄\n二行";
        $badPath = $this->writeJsonl('bad.jsonl', $bad);
        $r = $p->validate('ko', $this->root . '/out/ko-core-001.jsonl', $badPath);
        $joined = implode("\n", $r['errors']);
        self::assertStringContainsString('placeholders differ', $joined);
        self::assertStringContainsString('Chinese characters', $joined);

        $short = $this->writeJsonl('short.jsonl', [$bad[0]]);
        self::assertStringContainsString('line count differs', implode("\n", $p->validate('ko', $this->root . '/out/ko-core-001.jsonl', $short)['errors']));

        $tagBad = $bad;
        $tagBad[1]['text'] = '안녕하세요, :name';
        $tagBad[2]['text'] = "첫 줄\n둘째 줄";
        $exported2 = I18nPipeline::readJsonl($this->root . '/out/ko-core-002.jsonl');
        $second = array_map(function ($row) {
            unset($row['_line']);
            $row['text'] = $this->korean()[$row['key']];
            return $row;
        }, $exported2);
        $second[0]['text'] = '자세히 보기';
        self::assertStringContainsString('HTML tags differ', implode("\n", $p->validate('ko', $this->root . '/out/ko-core-002.jsonl', $this->writeJsonl('tags.jsonl', $second))['errors']));

        // 修好后通过并合入
        $good1 = $this->writeJsonl('good1.jsonl', $tagBad);
        $second[0]['text'] = $this->korean()['home_link'];
        $good2 = $this->writeJsonl('good2.jsonl', $second);
        self::assertSame([], $p->validate('ko', $this->root . '/out/ko-core-001.jsonl', $good1)['errors']);
        self::assertSame([], $p->validate('ko', $this->root . '/out/ko-core-002.jsonl', $good2)['errors']);
        $merged = $p->merge('ko', [$good1, $good2]);
        self::assertSame(['lang/ko.php' => 4], $merged['written']);
        self::assertSame([], $merged['stale']);

        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($this->root . '/lang/ko.php'), $out, $code);
        self::assertSame(0, $code);
        $ko = require $this->root . '/lang/ko.php';
        self::assertSame($this->korean(), $ko);
        self::assertSame(['home_title', 'home_hello', 'home_lines', 'home_link'], array_keys($ko), 'keys follow zh-CN order');

        $status = $p->status()['ko'];
        self::assertSame(['translated' => 4, 'total' => 5, 'stale' => 0], $status['core']);
        self::assertSame(0, $status['plugins']['translated']);

        // 原文改过：status 报过时，旧分片再合入会跳过
        $this->pack('lang/zh-CN.php', ['home_title' => '主页', 'home_hello' => '你好，:name', 'home_lines' => "第一行\n第二行", 'home_link' => '查看 <a href="/x">详情</a>', 'admin_x' => '后台']);
        self::assertSame(1, $p->status()['ko']['core']['stale']);
        $again = $p->merge('ko', [$good1]);
        self::assertSame(['lang/ko.php|home_title (source changed after export)'], $again['stale']);
    }

    public function testPluginAndInstallerScopesWriteTheirOwnFiles(): void
    {
        $p = new I18nPipeline($this->root);
        $p->export('es', ['plugins', 'installer'], '', 50, $this->root . '/out');
        $plugin = I18nPipeline::readJsonl($this->root . '/out/es-plugins-001.jsonl');
        $installer = I18nPipeline::readJsonl($this->root . '/out/es-installer-001.jsonl');
        self::assertSame('plugins/demo/lang/es.php', $plugin[0]['file']);
        self::assertSame('install/lang/es.php', $installer[0]['file']);

        $rows = [];
        foreach (array_merge($plugin, $installer) as $row) {
            unset($row['_line']);
            $row['text'] = ['demo_ok' => 'De acuerdo', 'install_go' => 'Instalar'][$row['key']];
            $rows[] = $row;
        }
        $merged = $p->merge('es', [$this->writeJsonl('es.jsonl', $rows)]);
        self::assertSame(['plugins/demo/lang/es.php' => 1, 'install/lang/es.php' => 1], $merged['written']);
        self::assertSame(['demo_ok' => 'De acuerdo'], require $this->root . '/plugins/demo/lang/es.php');
    }

    public function testReferenceLanguagesAndUnknownCodesAreNotTargets(): void
    {
        $p = new I18nPipeline($this->root);
        foreach (['zh-CN', 'zh-TW', 'en', 'ja', 'xx'] as $code) {
            try {
                $p->export($code, ['core'], '', 10, $this->root . '/out');
                self::fail("{$code} should be refused");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMergeRejectsAShardWithBrokenPlaceholders(): void
    {
        $p = new I18nPipeline($this->root);
        $p->export('fr', ['core'], 'home_hello', 10, $this->root . '/out');
        $row = I18nPipeline::readJsonl($this->root . '/out/fr-core-001.jsonl')[0];
        unset($row['_line']);
        $row['text'] = 'Bonjour';
        $merged = $p->merge('fr', [$this->writeJsonl('fr.jsonl', [$row])]);
        self::assertCount(1, $merged['rejected']);
        self::assertFileDoesNotExist($this->root . '/lang/fr.php');
    }

    /** 英文参考为空串的拆分标签（第 / 页 → Page / ''）可以译成空串，合入后算已译、不再导出；其他键仍不许空 */
    public function testEmptyTranslationOnlyWhereEnglishIsEmpty(): void
    {
        $this->pack('lang/zh-CN.php', ['pager_page_no' => '第', 'pager_page_word' => '页']);
        $this->pack('lang/en.php', ['pager_page_no' => 'Page', 'pager_page_word' => '']);
        $p = new I18nPipeline($this->root);
        $p->export('es', ['core'], 'pager_', 10, $this->root . '/out');
        $exported = $this->root . '/out/es-core-001.jsonl';
        $rows = array_map(static function (array $row): array {
            unset($row['_line']);
            $row['text'] = $row['key'] === 'pager_page_no' ? 'Página' : '';
            return $row;
        }, I18nPipeline::readJsonl($exported));

        self::assertSame([], $p->validate('es', $exported, $this->writeJsonl('pager.jsonl', $rows))['errors']);
        $bad = $rows;
        $bad[0]['text'] = '';
        self::assertStringContainsString('empty or missing', implode("\n", $p->validate('es', $exported, $this->writeJsonl('bad.jsonl', $bad))['errors']));

        self::assertSame([], $p->merge('es', [$this->writeJsonl('pager.jsonl', $rows)])['rejected']);
        self::assertSame(['pager_page_no' => 'Página', 'pager_page_word' => ''], require $this->root . '/lang/es.php');
        self::assertSame(['translated' => 2, 'total' => 2, 'stale' => 0], $p->status()['es']['core']);
        self::assertSame(0, $p->export('es', ['core'], 'pager_', 10, $this->root . '/out2')['total']);
    }

    /** 照抄英文参考不算翻译：超过 15% 的分片 validate 报错、merge 拒收；样本不足 10 行不判 */
    public function testShardThatCopiesEnglishIsRejected(): void
    {
        $zh = $en = [];
        for ($i = 1; $i <= 12; $i++) {
            $zh["copy_{$i}"] = "第{$i}条说明文字";
            $en["copy_{$i}"] = "Description text number {$i}";
        }
        $this->pack('lang/zh-CN.php', $zh);
        $this->pack('lang/en.php', $en);
        $p = new I18nPipeline($this->root);
        $p->export('es', ['core'], 'copy_', 50, $this->root . '/out');
        $exported = $this->root . '/out/es-core-001.jsonl';

        $rows = array_map(static function (array $row): array {
            unset($row['_line']);
            $row['text'] = 'Texto descriptivo ' . substr((string) $row['key'], 5);
            return $row;
        }, I18nPipeline::readJsonl($exported));
        self::assertSame([], $p->validate('es', $exported, $this->writeJsonl('ok.jsonl', $rows))['errors']);

        // 12 行里抄 1 行英文（8%）：只是警告
        $one = $rows;
        $one[0]['text'] = $one[0]['en'];
        $r = $p->validate('es', $exported, $this->writeJsonl('one.jsonl', $one));
        self::assertSame([], $r['errors']);
        self::assertStringContainsString('identical to English', implode("\n", $r['warnings']));

        // 抄 3 行（25%）：整片不合格，merge 也拒收
        $copied = $rows;
        foreach ([0, 1, 2] as $i) {
            $copied[$i]['text'] = $copied[$i]['en'];
        }
        $copiedPath = $this->writeJsonl('copied.jsonl', $copied);
        self::assertStringContainsString('the shard was not translated', implode("\n", $p->validate('es', $exported, $copiedPath)['errors']));
        $merged = $p->merge('es', [$copiedPath]);
        self::assertCount(1, $merged['rejected']);
        self::assertStringContainsString('identical to English', $merged['rejected'][0]);
        self::assertFileDoesNotExist($this->root . '/lang/es.php');

        // 小分片（可比行不足 10）不判：3 行全是英文也能合入
        $small = $this->writeJsonl('small.jsonl', array_slice($copied, 0, 3));
        self::assertSame([], $p->merge('es', [$small])['rejected']);
    }

    public function testPhpStringRoundTrips(): void
    {
        foreach (["it's", 'back\\slash', "two\nlines", 'dollar $x "q"', "tab\tx"] as $value) {
            self::assertSame($value, eval('return ' . I18nPipeline::phpString($value) . ';'));
        }
    }

    public function testPlaceholderAndTagExtraction(): void
    {
        self::assertSame([':count', ':name'], I18nPipeline::placeholders('共 :count 条，:name 你好'));
        self::assertSame([], I18nPipeline::placeholders('https://example.com 12:30'));
        self::assertSame(['%s', '{site}'], I18nPipeline::placeholders('{site} %s'));
        self::assertSame(['a', '/a', 'br'], I18nPipeline::tags('<a href="x">y</a><br>'));
    }
}
