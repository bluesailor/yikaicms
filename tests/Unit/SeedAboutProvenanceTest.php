<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 安装种子首页文档里的「关于我们」转换段必须标明写作语言（2026-09-25 发布验证 P2）。
 *
 * 没有 _home_about_i18n 时，前台只能按站点当前设置逐字推断写作语言；新装时站名被安装器改掉、
 * 首页设置被迁移换成站点语言，推断落空，英文 / 日文新装站首页显示「关于Yikai CMS」和中文简介。
 */
final class SeedAboutProvenanceTest extends TestCase
{
    private const ROLES = ['title' => 'text', 'body' => 'html', 'button' => 'text', 'image' => 'alt'];

    /** @return array<string,mixed> */
    private static function mysqlSeedDocument(string $key): array
    {
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '100000000');
        foreach (file(ROOT_PATH . '/install/sql/mysql.sql') ?: [] as $line) {
            if (str_contains($line, ",'" . $key . "',")
                && preg_match("/VALUES \\(\\d+,'[^']*','" . $key . "',('(?:[^'\\\\]|\\\\.)*')/s", $line, $m) === 1) {
                return json_decode(stripcslashes(substr($m[1], 1, -1)), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        self::fail("mysql.sql 缺少 {$key}");
    }

    /** @param array<string,mixed> $doc @return array<string,array<string,mixed>> */
    private static function aboutElements(array $doc): array
    {
        $found = [];
        $walk = static function (array $element) use (&$walk, &$found): void {
            if (preg_match('/^about_[a-f0-9]{12}_(title|body|button|image)$/D', (string) ($element['id'] ?? ''), $m) === 1) {
                $found[$m[1]] = $element;
            }
            foreach (is_array($element['data']['children'] ?? null) ? $element['data']['children'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        foreach ($doc['sections'] ?? [] as $section) {
            foreach ($section['columns'] ?? [] as $column) {
                foreach ($column['elements'] ?? [] as $element) {
                    $walk($element);
                }
            }
        }
        return $found;
    }

    public function testSeededAboutSectionRecordsItsSourceLanguage(): void
    {
        foreach (['home_blox_data', 'home_blox_published'] as $key) {
            $elements = self::aboutElements(self::mysqlSeedDocument($key));
            self::assertSame(array_keys(self::ROLES), array_keys(array_intersect_key(self::ROLES, $elements)), "{$key} 的关于段结构变了");
            foreach (self::ROLES as $role => $field) {
                $binding = $elements[$role]['data']['_home_about_i18n'] ?? null;
                self::assertIsArray($binding, "{$key} 的关于段 {$role} 没有写作语言标记");
                self::assertSame('zh-CN', $binding['lang']);
                // 标记的原文必须与元素当前值一致，否则运行期视为「已被编辑」而不翻译
                self::assertSame($elements[$role]['data'][$field], $binding['fields'][$field]['source'], "{$key} {$role}");
            }
        }
    }

    public function testSqliteSeedCarriesTheSameMarkers(): void
    {
        $sqlite = (string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');
        foreach (['home_blox_data', 'home_blox_published'] as $key) {
            $line = '';
            foreach (explode("\n", $sqlite) as $candidate) {
                if (str_contains($candidate, ",'" . $key . "',")) {
                    $line = $candidate;
                }
            }
            self::assertSame(4, substr_count($line, '_home_about_i18n'), "sqlite.sql 的 {$key} 与 mysql.sql 不一致（用 tools/mysql_to_sqlite.php 重新生成）");
        }
    }

    public function testSeedToolKeepsTheMarkersAndIsIdempotent(): void
    {
        require_once ROOT_PATH . '/tools/seed-i18n/about-provenance.php';
        self::assertStringContainsString('$doc = seedAttachAboutProvenance($doc);', (string) file_get_contents(ROOT_PATH . '/tools/sync_demo_seed.php'));
        $doc = self::mysqlSeedDocument('home_blox_published');
        self::assertSame($doc, seedAttachAboutProvenance($doc));

        // 去掉标记后由工具重新挂上，结果与种子一致
        $stripped = json_decode(preg_replace('/,"_home_about_i18n":\{(?:[^{}]|\{(?:[^{}]|\{(?:[^{}]|\{[^{}]*\})*\})*\})*\}/u', '',
            (string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);
        self::assertStringNotContainsString('_home_about_i18n', (string) json_encode($stripped));
        self::assertSame($doc, seedAttachAboutProvenance($stripped));
    }
}
