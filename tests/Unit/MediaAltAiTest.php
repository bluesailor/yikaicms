<?php
/** AI 生成图片替代文字（v2.1.0）：图片消息格式、回复清洗、只补缺的、正文图片兜底。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiService;
use MediaAltAi;
use ReflectionMethod;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/AiService.php';
require_once ROOT_PATH . '/includes/media/MediaAlt.php';
require_once ROOT_PATH . '/includes/media/MediaAltAi.php';

final class MediaAltAiTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE media (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', path TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'image', ext TEXT NOT NULL DEFAULT '', mime TEXT NOT NULL DEFAULT '', size INTEGER NOT NULL DEFAULT 0, width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0, md5 TEXT NOT NULL DEFAULT '', admin_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT NOT NULL, owner_id INTEGER NOT NULL DEFAULT 0, meta_key TEXT NOT NULL, meta_value TEXT, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, UNIQUE(owner_type, owner_id, meta_key))",
        ];
    }

    public function testImagePartsAreSentInEachProvidersFormat(): void
    {
        $method = new ReflectionMethod(AiService::class, 'messageContent');
        $method->setAccessible(true);
        $parts = [['type' => 'image', 'mime' => 'image/png', 'data' => 'QUJD'], ['type' => 'text', 'text' => 'describe']];
        self::assertSame('plain', $method->invoke(null, 'plain', 'openai'), '纯文字不变');
        self::assertSame([
            ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']],
            ['type' => 'text', 'text' => 'describe'],
        ], $method->invoke(null, $parts, 'openai'));
        self::assertSame([
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'QUJD']],
            ['type' => 'text', 'text' => 'describe'],
        ], $method->invoke(null, $parts, 'anthropic'));
    }

    public function testRepliesAreCleanedToOneLine(): void
    {
        self::assertSame('工厂车间里的数控机床', MediaAltAi::clean("“工厂车间里的数控机床”\n"));
        self::assertSame('A CNC machine', MediaAltAi::clean('"A  CNC   <b>machine</b>"'));
        self::assertSame(MediaAltAi::MAX_ALT, mb_strlen(MediaAltAi::clean(str_repeat('长', 400))));
    }

    public function testOnlyImagesWithoutAltAreFilledAndSaved(): void
    {
        $file = ROOT_PATH . '/uploads/alt-ai-test-' . bin2hex(random_bytes(3)) . '.png';
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $path = 'uploads/' . basename($file);
        try {
            db()->execute("INSERT INTO media (path, url, mime) VALUES (?, ?, 'image/png')", [$path, '/' . $path]);
            db()->execute("INSERT INTO media (path, url, mime) VALUES ('uploads/x.png', '/uploads/x.png', 'image/png')");
            db()->execute("INSERT INTO media (path, url, mime, type) VALUES ('uploads/a.pdf', '/uploads/a.pdf', 'application/pdf', 'file')");
            db()->execute("INSERT INTO metas (owner_type, owner_id, meta_key, meta_value) VALUES ('media', 2, 'alt', '已有')");
            self::assertSame([1], MediaAltAi::missingIds(), '已有替代文字的、非图片的不算');

            $ai = new class extends AiService {
                public array $seen = [];
                public function __construct()
                {
                }
                public function describeImage(string $bytes, string $mime, string $instruction, string $system = '', float $temperature = 0.3): array
                {
                    $this->seen[] = [$mime, $instruction];
                    return ['success' => true, 'content' => '"车间里的机床"', 'error' => ''];
                }
            };
            self::assertSame('车间里的机床', MediaAltAi::generateAndSave(1, $ai));
            self::assertSame('车间里的机床', (string) db()->fetchColumn("SELECT meta_value FROM metas WHERE owner_type = 'media' AND owner_id = 1 AND meta_key = 'alt'"));
            self::assertSame('image/png', $ai->seen[0][0]);
            self::assertStringContainsString('Chinese', $ai->seen[0][1], '按站点默认语言写');
            self::assertSame([], MediaAltAi::missingIds());

            try {
                MediaAltAi::generate(3, $ai);
                self::fail('非图片不能生成');
            } catch (\RuntimeException $e) {
                self::assertSame(__('media_alt_ai_unsupported'), $e->getMessage());
            }
        } finally {
            @unlink($file);
        }
    }

    public function testContentImagesWithoutAltFallBackToTheMediaLibrary(): void
    {
        db()->execute("INSERT INTO media (path, url, mime) VALUES ('uploads/p.jpg', '/uploads/p.jpg', 'image/jpeg')");
        db()->execute("INSERT INTO metas (owner_type, owner_id, meta_key, meta_value) VALUES ('media', 1, 'alt', '产品 \"A\" 正面')");
        $html = '<p><img src="/uploads/p.jpg?v=2"><img src="/uploads/p.jpg" alt=""><img src="/uploads/p.jpg" alt="自己写的"><img src="/uploads/other.jpg"></p>';
        $out = mediaAltFillHtml($html);
        self::assertSame(2, substr_count($out, 'alt="产品 &quot;A&quot; 正面"'), '没有 alt、alt 为空的都补上');
        self::assertStringContainsString('alt="自己写的"', $out, '写了的不动');
        self::assertStringContainsString('<img src="/uploads/other.jpg">', $out, '媒体库里没有的不动');
    }
}
