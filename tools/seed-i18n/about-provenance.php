<?php

declare(strict_types=1);

/**
 * 给种子首页文档里「关于我们」转换段标明写作语言（HomeAboutLocalization 的 _home_about_i18n）。
 *
 * 种子里的关于段是经典区块转换出来的普通元素，中文烤在文档里。没有语言标记时，前台只能
 * 拿「站点当前设置」逐字比对去推断写作语言；而新装时安装器会改站名、迁移
 * 20260817 会把首页设置换成站点语言，推断必然落空——英文 / 日文新装站首页因此显示
 * 「关于Yikai CMS」和中文简介（2026-09-25 发布验证 P2）。
 *
 * 种子文档固定是中文写的，这里显式记下来源语言与各字段原文；前台按访问语言换成该语言的
 * 站点标题、简介、按钮文字与链接（HomeAboutContent::resolve），中文站不受影响。
 * 只认转换器的原始结构（<前缀>_text / <前缀>_visual 两列、按角色命名的元素 id），
 * 已有标记或结构对不上的一律不动。
 *
 * @param array<string,mixed> $doc
 * @return array<string,mixed>
 */
function seedAttachAboutProvenance(array $doc, string $sourceLang = 'zh-CN'): array
{
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $bind = static function (array $element, string $role) use ($escape, $sourceLang): array {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        if (array_key_exists('_home_about_i18n', $data)) {
            return $element;
        }
        $type = (string) ($element['type'] ?? '');
        $fields = [];
        if ($role === 'title' && $type === 'heading' && is_string($data['text'] ?? null) && $data['text'] !== '') {
            $fields['text'] = ['source' => $data['text'], 'parts' => ['override_title' => $data['text']]];
        } elseif ($role === 'image' && $type === 'image' && is_string($data['alt'] ?? null) && $data['alt'] !== '') {
            $fields['alt'] = ['source' => $data['alt'], 'parts' => ['override_title' => $data['alt']]];
        } elseif ($role === 'button' && $type === 'button') {
            if (is_string($data['text'] ?? null) && $data['text'] !== '') {
                $fields['text'] = ['source' => $data['text'], 'parts' => ['override_button_text' => $data['text']]];
            }
            if (is_string($data['url'] ?? null) && $data['url'] !== '') {
                $fields['url'] = ['source' => $data['url'], 'parts' => ['override_button_url' => $data['url']]];
            }
        } elseif ($role === 'body' && $type === 'text' && is_string($data['html'] ?? null)
            && preg_match('#^<p class="text-lg leading-relaxed(?: text-white)?">([^<]+)</p>$#u', $data['html'], $m) === 1) {
            // 运行期按 '>' . 转义(原文) . '<' 定位替换点；只收能唯一定位的纯文本段落
            $content = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($escape($content) === $m[1] && substr_count($data['html'], '>' . $m[1] . '<') === 1) {
                $fields['html'] = ['source' => $data['html'], 'parts' => ['override_content' => $content]];
            }
        }
        if ($fields !== []) {
            $element['data']['_home_about_i18n'] = ['lang' => $sourceLang, 'fields' => $fields];
        }
        return $element;
    };

    foreach (is_array($doc['sections'] ?? null) ? $doc['sections'] : [] as $si => $section) {
        $columnIds = array_map(static fn($column): string => (string) (is_array($column) ? ($column['id'] ?? '') : ''),
            is_array($section['columns'] ?? null) ? $section['columns'] : []);
        foreach ($columnIds as $columnId) {
            if (preg_match('/^((?:about_[a-f0-9]{12}|home_s_[0-9]+))_text$/D', $columnId, $match) !== 1
                || !in_array($match[1] . '_visual', $columnIds, true)) {
                continue;
            }
            $prefix = $match[1];
            $visit = static function (array $element) use (&$visit, $prefix, $bind): array {
                if (is_array($element['data']['children'] ?? null)) {
                    foreach ($element['data']['children'] as $index => $child) {
                        if (is_array($child)) {
                            $element['data']['children'][$index] = $visit($child);
                        }
                    }
                }
                $id = (string) ($element['id'] ?? '');
                if (preg_match('/^' . preg_quote($prefix, '/') . '_(title|body|button|image)$/D', $id, $role) === 1) {
                    $element = $bind($element, $role[1]);
                }
                return $element;
            };
            foreach ($section['columns'] as $ci => $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $ei => $element) {
                    if (is_array($element)) {
                        $doc['sections'][$si]['columns'][$ci]['elements'][$ei] = $visit($element);
                    }
                }
            }
        }
    }
    return $doc;
}
