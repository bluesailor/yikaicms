<?php
/** 网页头/网页脚编辑器顶栏的语言切换：每种语言跳到它实际使用的设计。 */

declare(strict_types=1);

final class BloxAreaEditorLanguageLinks
{
    /**
     * 与模板库「多语言网页头与网页脚」面板同一套判定（BloxAreaLanguageManager::overview）：
     * - 有独立草稿 → 编辑该草稿；
     * - 独立 / 高级条件 / 默认语言使用中的设计 → 编辑该设计；
     * - 继承默认语言 → 编辑同一份共享设计，按该语言预览（顶栏徽标会提示共享）；
     * - 该语言还没有可用设计 → 去模板库复制或选择设计。
     *
     * @param array<string,mixed> $template 当前编辑的网页头/网页脚模板
     * @param array<string,string>|null $languages 测试注入；缺省读站点启用语言
     * @param list<array{code:string,label:string,is_default:bool,areas:array<string,array<string,mixed>>}>|null $overviewRows 测试注入
     * @return list<array{code:string,label:string,short:string,url:string,current:bool,state:string,title:string}>
     */
    public static function build(array $template, string $editorLanguage, ?array $languages = null, ?array $overviewRows = null): array
    {
        $area = (string) ($template['type'] ?? '');
        if (!in_array($area, ['header', 'footer'], true)) {
            return [];
        }
        $siteLanguage = function_exists('siteLang') ? siteLang() : 'zh-CN';
        if ($languages === null) {
            $enabled = function_exists('enabledLanguages') ? enabledLanguages() : [];
            $available = function_exists('availableLanguages') ? availableLanguages() : [];
            $languages = [$siteLanguage => ($available[$siteLanguage] ?? $enabled[$siteLanguage] ?? $siteLanguage)] + $enabled;
        }
        if (count($languages) < 2) {
            return [];
        }
        if ($overviewRows === null) {
            $stored = array_values(array_filter(
                bloxTemplateModel()->catalog(),
                static fn (array $row): bool => in_array((string) ($row['source'] ?? ''), ['user', 'import', 'remote', 'builtin'], true)
            ));
            $overviewRows = BloxAreaLanguageManager::overview(
                $languages,
                $siteLanguage,
                [$area => bloxTemplateModel()->publishedAreaTemplates($area)],
                $stored,
                [$area => (string) config('blox_custom_' . $area . '_enabled', '1') === '1']
            );
        }

        $currentId = (int) ($template['id'] ?? 0);
        // 语言专属的独立设计只服务它自己的语言；非专属设计（默认、按显示条件、尚未发布）可换语言预览
        $currentManaged = BloxAreaLanguageManager::managedLanguage($template) !== '';
        $links = [];
        foreach ($overviewRows as $row) {
            $code = (string) $row['code'];
            $state = is_array($row['areas'][$area] ?? null) ? $row['areas'][$area] : [];
            $mode = (string) ($state['mode'] ?? 'theme');
            $draft = is_array($state['draft'] ?? null) ? $state['draft'] : null;
            $candidate = is_array($state['candidate'] ?? null) ? $state['candidate'] : null;

            $targetId = 0;
            if ($draft !== null && $mode !== 'independent') {
                $targetId = (int) ($draft['id'] ?? 0);
                $kind = 'draft';
                $hint = __('blox_language_area_edit_draft');
            } elseif ($candidate !== null && in_array($mode, ['independent', 'advanced', 'default', 'inherit'], true)) {
                $targetId = (int) ($candidate['id'] ?? 0);
                $kind = $mode;
                $hint = __('blox_language_area_mode_' . $mode);
            } elseif ($currentId > 0 && !$currentManaged) {
                // 该语言还没有可用设计：留在当前设计，用该语言站点资料预览
                $targetId = $currentId;
                $kind = 'preview';
                $hint = __('blox_area_language_switch_preview');
            } else {
                $kind = 'none';
                $hint = __('blox_current_choose_design');
            }
            $url = $targetId > 0
                ? '/admin/blox_editor.php?template=' . $targetId . '&area_lang=' . rawurlencode($code)
                : '/admin/blox_templates.php?type=' . $area . '&area_lang=' . rawurlencode($code) . '#blox-language-areas';

            $links[] = [
                'code' => $code,
                'label' => (string) $row['label'],
                'short' => match ($code) {
                    'zh-CN' => 'ZH',
                    'en' => 'EN',
                    'ja' => 'JA',
                    default => strtoupper(substr($code, 0, 3)),
                },
                'url' => $url,
                'current' => $code === $editorLanguage,
                'state' => $kind,
                'title' => (string) $row['label'] . ' · ' . $hint,
            ];
        }
        return $links;
    }
}
