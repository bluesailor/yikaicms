<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n/LanguageRegistry.php';

/**
 * 用 AI 给媒体库图片生成替代文字（2.1.0）。结果写进 metas（owner_type=media、meta_key=alt），
 * 与图片编辑弹窗里手填的是同一个字段；页面上用到这张图又没单独写替代文字时由 mediaAltFor() 兜底。
 * 只读本站 uploads/ 下的文件，优先用 800px 的 _medium 版（省流量、省费用）。
 */
final class MediaAltAi
{
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const MAX_BYTES = 4194304;
    public const MAX_ALT = 300;

    /**
     * 给一张图生成替代文字（不保存）。失败抛出给站长看的消息。
     */
    public static function generate(int $mediaId, AiService $ai, ?string $lang = null): string
    {
        $image = self::imageFor($mediaId);
        $lang ??= (string) config('site_lang', 'zh-CN');
        $language = LanguageRegistry::englishName($lang);
        $result = $ai->describeImage(
            $image['bytes'],
            $image['mime'],
            'Write alt text for this image as it appears on a company website. Language: ' . $language
                . '. At most 125 characters. Describe what is shown; do not start with "image of" or "picture of". Reply with the alt text only.',
            'You write concise, accurate image alt text for accessibility and SEO.'
        );
        if (empty($result['success'])) {
            throw new RuntimeException(__('media_alt_ai_failed', ['error' => (string) ($result['error'] ?? '')]));
        }
        return self::clean((string) $result['content']);
    }

    /** 生成并保存，返回替代文字。 */
    public static function generateAndSave(int $mediaId, AiService $ai): string
    {
        $alt = self::generate($mediaId, $ai);
        if ($alt === '') {
            throw new RuntimeException(__('media_alt_ai_failed', ['error' => 'empty']));
        }
        metaModel()->set('media', $mediaId, 'alt', $alt);
        if (function_exists('do_action')) {
            do_action('data_changed', 'media', $mediaId);
        }
        return $alt;
    }

    /**
     * 还没有替代文字的图片（新的在前）。
     *
     * @return list<int>
     */
    public static function missingIds(int $limit = 0): array
    {
        $sql = 'SELECT m.id FROM ' . DB_PREFIX . 'media m LEFT JOIN ' . DB_PREFIX
            . "metas x ON x.owner_type = 'media' AND x.owner_id = m.id AND x.meta_key = 'alt'"
            . " WHERE m.type = 'image' AND m.mime IN ('" . implode("','", self::TYPES) . "')"
            . " AND (x.meta_value IS NULL OR x.meta_value = '') ORDER BY m.id DESC"
            . ($limit > 0 ? ' LIMIT ' . $limit : '');
        return array_map(static fn (array $row): int => (int) $row['id'], db()->fetchAll($sql));
    }

    public static function missingCount(): int
    {
        return count(self::missingIds());
    }

    /** 模型回复 → 一行纯文字替代文字。 */
    public static function clean(string $text): string
    {
        $text = trim(strip_tags($text));
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        // 去掉模型常加的首尾引号（按字符，不能用 trim 的字节表——会切坏汉字）
        $text = (string) preg_replace('/^["\'“”‘’「」『』]+|["\'“”‘’「」『』]+$/u', '', trim($text));
        return mb_substr(trim($text), 0, self::MAX_ALT);
    }

    /** @return array{bytes:string,mime:string} */
    private static function imageFor(int $mediaId): array
    {
        $media = db()->fetchOne('SELECT path, mime FROM ' . DB_PREFIX . 'media WHERE id = ?', [$mediaId]);
        if (!$media || !in_array((string) $media['mime'], self::TYPES, true)) {
            throw new RuntimeException(__('media_alt_ai_unsupported'));
        }
        $uploads = realpath(ROOT_PATH . '/uploads');
        $file = realpath(ROOT_PATH . '/' . ltrim((string) $media['path'], '/'));
        if ($uploads === false || $file === false || !str_starts_with($file, $uploads . DIRECTORY_SEPARATOR) || !is_file($file)) {
            throw new RuntimeException(__('media_alt_ai_unsupported'));
        }
        if (function_exists('_thumbnailPath')) {
            $medium = _thumbnailPath($file, 'medium');
            if (is_file($medium)) {
                $file = $medium;
            }
        }
        if (filesize($file) > self::MAX_BYTES) {
            throw new RuntimeException(__('media_alt_ai_too_large'));
        }
        $mime = function_exists('mime_content_type') ? (string) mime_content_type($file) : (string) $media['mime'];
        return ['bytes' => (string) file_get_contents($file), 'mime' => in_array($mime, self::TYPES, true) ? $mime : (string) $media['mime']];
    }
}
