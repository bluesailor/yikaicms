<?php
declare(strict_types=1);

/**
 * Contact Form 7 表单 → 本站表单模板（纯函数，2.0.4）。
 *
 * 本站表单模板就是 CF7 风格的文本（[text* name "占位"]、[submit "发送"]，见 formTagPattern()），
 * 大部分标签原样可用，只需改几处语义不同的地方：
 * - 下拉：CF7 的引号项全是选项（first_as_label 时第一项是提示），本站第一项是占位 → 没有 first_as_label 时补一个空占位；
 * - [acceptance 名称]说明[/acceptance] → 必选单选框 [checkbox* 名称 "说明"]；
 * - [file 名称 limit:2mb filetypes:pdf|jpg] → [file 名称 "pdf,jpg" max:2]（本站只收 pdf/jpg/jpeg/png/webp，最多 10MB）；
 * - 去掉 [quiz] [recaptcha] [response] [hidden-captcha] 等本站自带或不需要的标签，其它不认识的标签去掉并记下来。
 */
final class WordPressForms
{
    private const KEEP = ['text', 'email', 'tel', 'textarea', 'number', 'date', 'url', 'select', 'radio', 'checkbox', 'file', 'hidden', 'submit'];
    private const SILENT_DROP = ['quiz', 'recaptcha', 'response', 'hidden-captcha', 'cf7sr-simple-recaptcha', 'honeypot', 'cf7-honeypot'];
    private const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string,int> 去掉的不认识标签 → 次数 */
    public array $dropped = [];

    public function convert(string $template): string
    {
        $template = str_replace(["\r\n", "\r"], "\n", $template);
        // [acceptance name opt]说明[/acceptance]
        $template = (string) preg_replace_callback('/\[acceptance\*?\s+([a-zA-Z][\w-]*)[^\]]*\](.*?)\[\/acceptance\]/s', static function (array $m): string {
            $label = trim(preg_replace('/\s+/', ' ', strip_tags($m[2])) ?? '');
            return '[checkbox* ' . $m[1] . ' "' . str_replace('"', '', $label !== '' ? $label : $m[1]) . '"]';
        }, $template);
        return (string) preg_replace_callback('/\[([a-z][a-z0-9_-]*)(\*?)((?:\s+(?:"[^"]*"|[^\]\s]+))*)\s*\]/i', function (array $m): string {
            $type = strtolower($m[1]);
            $star = $m[2];
            $args = trim($m[3]);
            if (in_array($type, self::SILENT_DROP, true)) {
                return '';
            }
            if (!in_array($type, self::KEEP, true)) {
                $this->dropped[$type] = ($this->dropped[$type] ?? 0) + 1;
                return '';
            }
            preg_match_all('/"([^"]*)"|([^\s]+)/', $args, $parts, PREG_SET_ORDER);
            $tokens = [];
            $quoted = [];
            foreach ($parts as $part) {
                if (($part[2] ?? '') === '' && str_starts_with($part[0], '"')) $quoted[] = $part[1];
                else $tokens[] = $part[2];
            }
            if ($type === 'submit') {
                return '[submit' . ($quoted !== [] ? ' "' . $quoted[0] . '"' : '') . ']';
            }
            $name = array_shift($tokens);
            if ($name === null || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/', $name) !== 1) {
                $this->dropped[$type] = ($this->dropped[$type] ?? 0) + 1;
                return '';
            }
            $keep = array_values(array_filter($tokens, static fn (string $t): bool => preg_match('/^(min|max|step):/i', $t) === 1));
            $quote = static fn (array $values): string => implode('', array_map(static fn (string $v): string => ' "' . str_replace('"', '', $v) . '"', $values));
            if ($type === 'select') {
                if (!in_array('first_as_label', $tokens, true)) {
                    array_unshift($quoted, '');
                }
                return '[select' . $star . ' ' . $name . $quote($quoted) . ']';
            }
            if ($type === 'file') {
                $types = [];
                $limit = 2;
                foreach ($tokens as $token) {
                    if (preg_match('/^filetypes:(.+)$/i', $token, $f) === 1) {
                        $types = array_intersect(array_map(static fn (string $e): string => strtolower(ltrim($e, '.')), explode('|', $f[1])), self::FILE_TYPES);
                    } elseif (preg_match('/^limit:(\d+)(kb|mb)?$/i', $token, $l) === 1) {
                        $bytes = (int) $l[1] * (strtolower($l[2] ?? '') === 'mb' ? 1048576 : (strtolower($l[2] ?? '') === 'kb' ? 1024 : 1));
                        $limit = max(1, min(10, (int) ceil($bytes / 1048576)));
                    }
                }
                $types = $types !== [] ? array_values($types) : self::FILE_TYPES;
                return '[file' . $star . ' ' . $name . ' "' . implode(',', $types) . '" max:' . $limit . ']';
            }
            // 其它类型：保留占位（CF7 写成 placeholder "…"，引号项即占位）、选项与 min/max/step
            return '[' . $type . $star . ' ' . $name . ($keep !== [] ? ' ' . implode(' ', $keep) : '') . $quote($quoted) . ']';
        }, $template);
    }

    /** CF7 的 _messages 里的「发送成功」提示；取不到返回 ''。 */
    public static function successMessage(string $serializedMessages): string
    {
        $messages = @unserialize($serializedMessages, ['allowed_classes' => false]);
        $text = is_array($messages) ? (string) ($messages['mail_sent_ok'] ?? '') : '';
        return trim(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
