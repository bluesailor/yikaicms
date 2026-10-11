<?php

declare(strict_types=1);

/**
 * 随包预置组件（v2.1.0）：全新安装就有一组能直接用的组件，组件库不再是空的。
 *
 * 包文件在 templates/blox/components/（由 tools/blox/gen-starter-components.py 生成，改内容改脚本再生成），
 * 与「单个组件导入」同一格式：每个包带固定的 component.uuid，所以各站的同一预置组件是同一母版。
 * 安装 = 按包导入成母版并发布；本站已有同一 uuid 的母版（装过、或作者导入过）就跳过，从不覆盖作者的修改。
 * 迁移 20261011_starter_components 给老站补装；全新安装由安装器跑迁移时装上。
 */
final class BloxComponentPresets
{
    /** 预置组件（包文件名，不含 .json），按组件库里的展示顺序 */
    public const PRESETS = [
        'feature-card', 'pricing-card', 'testimonial-card', 'cta-bar',
        'team-member', 'faq-item', 'icon-list-item', 'contact-card',
    ];

    /** 组件库里显示的名称：按站点默认语言取（中文站中文、日文站日文，其余英文）；包里只存中文名 */
    private const NAMES = [
        'feature-card' => ['zh' => '功能卡片', 'en' => 'Feature card', 'ja' => '機能カード'],
        'pricing-card' => ['zh' => '价格卡片', 'en' => 'Pricing card', 'ja' => '料金カード'],
        'testimonial-card' => ['zh' => '评价卡片', 'en' => 'Testimonial card', 'ja' => 'お客様の声カード'],
        'cta-bar' => ['zh' => '行动号召条', 'en' => 'Call-to-action bar', 'ja' => 'CTAバー'],
        'team-member' => ['zh' => '团队成员', 'en' => 'Team member', 'ja' => 'チームメンバー'],
        'faq-item' => ['zh' => '常见问题条目', 'en' => 'FAQ item', 'ja' => 'FAQ項目'],
        'icon-list-item' => ['zh' => '图标列表项', 'en' => 'Icon list item', 'ja' => 'アイコンリスト項目'],
        'contact-card' => ['zh' => '联系卡片', 'en' => 'Contact card', 'ja' => '連絡先カード'],
    ];

    /** 还没装上的预置组件（本站没有同一 uuid 的母版）。 @return list<string> */
    public static function missing(): array
    {
        if (!db()->tableExists('blox_templates')) {
            return [];
        }
        $present = self::presentUuids();
        return array_values(array_filter(
            self::PRESETS,
            static fn (string $slug): bool => !isset($present[self::uuid($slug)])
        ));
    }

    /** 补装缺的预置组件，返回装了几个。 */
    public static function installMissing(int $adminId = 0): int
    {
        $installed = 0;
        foreach (self::missing() as $slug) {
            // 随包官方预置由代码提供，未授权站点也装（与预置页头页脚同一规则）；之后的修改照常受组件授权保护
            BloxFeaturePolicy::asTrustedWrite(static function () use ($slug, $adminId): void {
                $result = BloxTemplateImporter::importJson(self::packageJson($slug), $adminId, 'builtin', 'component:' . $slug);
                db()->update('blox_templates', ['name' => self::name($slug)], 'id = ?', [(int) $result['id']]);
                bloxTemplateModel()->publishDraft((int) $result['id']);
            });
            $installed++;
        }
        return $installed;   // 发布时 afterPublish 已清掉请求级组件目录，本请求内就能用
    }

    public static function name(string $slug, ?string $lang = null): string
    {
        $lang ??= (string) config('site_lang', 'zh-CN');
        $names = self::NAMES[$slug] ?? ['zh' => $slug, 'en' => $slug, 'ja' => $slug];
        return str_starts_with($lang, 'zh') ? $names['zh'] : ($lang === 'ja' ? $names['ja'] : $names['en']);
    }

    public static function uuid(string $slug): string
    {
        $package = json_decode(self::packageJson($slug), true);
        $uuid = is_array($package) ? (string) ($package['component']['uuid'] ?? '') : '';
        if (!BloxComponents::validUuid($uuid)) {
            throw new RuntimeException('Invalid starter component package: ' . $slug);
        }
        return $uuid;
    }

    private static function packageJson(string $slug): string
    {
        if (!in_array($slug, self::PRESETS, true)) {
            throw new RuntimeException('Unknown starter component: ' . $slug);
        }
        $json = file_get_contents(dirname(__DIR__, 2) . '/templates/blox/components/' . $slug . '.json');
        if (!is_string($json)) {
            throw new RuntimeException('Starter component package unreadable: ' . $slug);
        }
        return $json;
    }

    /** 本站所有组件母版的 uuid（含草稿、归档）。 @return array<string,true> */
    private static function presentUuids(): array
    {
        $out = [];
        foreach (db()->fetchAll('SELECT metadata FROM ' . DB_PREFIX . 'blox_templates WHERE type = ?', [BloxComponents::TYPE]) as $row) {
            $metadata = json_decode((string) ($row['metadata'] ?? ''), true);
            $uuid = is_array($metadata) ? ($metadata['component']['uuid'] ?? null) : null;
            if (BloxComponents::validUuid($uuid)) {
                $out[(string) $uuid] = true;
            }
        }
        return $out;
    }
}
