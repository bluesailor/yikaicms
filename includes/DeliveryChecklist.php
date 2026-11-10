<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteSetup.php';

/**
 * 交付检查清单（评审 T09）：把建站向导的资料项、站点健康、内容自检和人工确认汇到一处，三类分开计：
 *   data   资料是否已有——打开页面时实时读
 *   check  自动检查是否通过——点「运行检查」时跑并记下摘要，打开页面不自动跑（内容自检要扫全站）
 *   accept 人确认过的事——询盘测试、手机端浏览、多语言浏览、交付；记谁、何时
 * 状态存一个设置键（不在整站模板导出白名单内，不会随模板带到别的站）。
 * 设计：yikaicms-docs/T09-交付检查清单-设计-20261107.md
 */
final class DeliveryChecklist
{
    public const SETTING_KEY = 'delivery_checklist';
    public const ACCEPT_KEYS = ['inquiry_test', 'mobile_review', 'language_review', 'handover'];
    private const TOP_ISSUES = 10;

    /**
     * 站点现状（只读、轻量）。
     * @return array{setup:list<array{label:string,url:string,done:bool}>,description:bool,languages:array<string,string>,translation_gaps:array<string,array{missing:int,draft:int,outdated:int}>,latest_inquiry:int}
     */
    public static function facts(): array
    {
        $default = (string) config('site_lang', 'zh-CN');
        $languages = array_diff_key(enabledLanguages(), [$default => true]);
        return [
            'setup' => SiteSetup::checklist(),
            'description' => trim((string) config('site_description', '')) !== '',
            'languages' => array_map('strval', $languages),
            'translation_gaps' => self::translationGaps($default, array_map('strval', array_keys($languages))),
            'latest_inquiry' => db()->tableExists('forms') ? (int) db()->fetchColumn('SELECT MAX(created_at) FROM ' . DB_PREFIX . 'forms') : 0,
        ];
    }

    /**
     * 默认语言已发布的产品，在每种语言下还要处理的数量：缺译文、有译文但未发布、原文改过后过期（路线图 CORE-02）。
     * 只要有译文就算「已翻译」会高估交付就绪：草稿前台看不到，过期的内容跟原文对不上。
     * 判断与「译文状态」页同一份（TranslationCoverage）。
     * @param list<string> $languages @return array<string,array{missing:int,draft:int,outdated:int}>
     */
    public static function translationGaps(string $default, array $languages): array
    {
        require_once __DIR__ . '/TranslationCoverage.php';
        return (new TranslationCoverage(db()))->gapsByLanguage($default, $languages);
    }

    /** @return array{accept:array<string,array{admin_id:int,name:string,at:int}>,run:array<string,mixed>} */
    public static function state(): array
    {
        $raw = json_decode((string) settingModel()->get(self::SETTING_KEY, ''), true);
        $raw = is_array($raw) ? $raw : [];
        $accept = [];
        foreach (is_array($raw['accept'] ?? null) ? $raw['accept'] : [] as $key => $row) {
            if (in_array($key, self::ACCEPT_KEYS, true) && is_array($row)) {
                $accept[$key] = ['admin_id' => (int) ($row['admin_id'] ?? 0), 'name' => (string) ($row['name'] ?? ''), 'at' => (int) ($row['at'] ?? 0)];
            }
        }
        return ['accept' => $accept, 'run' => is_array($raw['run'] ?? null) ? $raw['run'] : []];
    }

    /**
     * 两套自动检查的摘要：只留计数、严重项前 10 条（标题键与修复链接）和时间，不存全文。
     * @param list<array<string,mixed>> $health SiteHealth::runDirect() 的结果
     * @param array<string,mixed> $content SiteContentChecks::run() 的结果
     * @return array<string,mixed>
     */
    public static function summarizeRun(array $health, array $content, int $now): array
    {
        $counts = ['critical' => 0, 'recommended' => 0, 'good' => 0, 'unknown' => 0];
        $critical = [];
        foreach ($health as $result) {
            $status = (string) ($result['status'] ?? 'unknown');
            if (!isset($counts[$status])) $status = 'unknown';
            $counts[$status]++;
            if ($status === 'critical' && count($critical) < self::TOP_ISSUES) {
                $critical[] = ['id' => (string) ($result['id'] ?? ''), 'title' => (string) ($result['title'] ?? ''), 'url' => (string) ($result['action_url'] ?? '')];
            }
        }
        $levels = ['error' => 0, 'review' => 0];
        foreach (is_array($content['issues'] ?? null) ? $content['issues'] : [] as $issue) {
            $level = (string) ($issue['level'] ?? 'review');
            $levels[$level === 'error' ? 'error' : 'review']++;
        }
        return ['at' => $now, 'health' => $counts + ['issues' => $critical],
            'content' => $levels + ['scanned' => (int) ($content['scanned'] ?? 0), 'limited' => !empty($content['limited'])]];
    }

    /** @param array<string,mixed> $run summarizeRun() 的结果 */
    public static function saveRun(array $run): void
    {
        $state = self::state();
        $state['run'] = $run;
        self::save($state);
    }

    public static function accept(string $key, int $adminId, string $name, int $now): void
    {
        if (!in_array($key, self::ACCEPT_KEYS, true)) throw new InvalidArgumentException('delivery_key');
        $state = self::state();
        $state['accept'][$key] = ['admin_id' => $adminId, 'name' => mb_substr($name, 0, 60), 'at' => $now];
        self::save($state);
    }

    public static function revoke(string $key): void
    {
        if (!in_array($key, self::ACCEPT_KEYS, true)) throw new InvalidArgumentException('delivery_key');
        $state = self::state();
        unset($state['accept'][$key]);
        self::save($state);
    }

    /**
     * 组装清单（纯函数）。每项：key、label（语言键）、status（done / todo / warn / fail / unknown）、url（修复入口）、
     * detail（语言键 + 参数，可空）。open = 交付之外还没完成的项数。
     * @param array<string,mixed> $facts facts() 的结果 @param array<string,mixed> $state state() 的结果
     * @return array{data:list<array<string,mixed>>,check:list<array<string,mixed>>,accept:list<array<string,mixed>>,open:int}
     */
    public static function evaluate(array $facts, array $state): array
    {
        $item = static fn (string $key, string $label, string $status, string $url, string $detail = '', array $params = []): array
            => ['key' => $key, 'label' => $label, 'status' => $status, 'url' => $url, 'detail' => $detail, 'params' => $params];

        $data = [];
        foreach ($facts['setup'] as $check) {
            $data[] = $item((string) $check['label'], (string) $check['label'], $check['done'] ? 'done' : 'todo', (string) $check['url']);
        }
        $data[] = $item('description', 'dlv_description', $facts['description'] ? 'done' : 'todo', '/admin/setting.php?tab=basic');
        if ($facts['languages'] !== []) {
            $gaps = array_filter($facts['translation_gaps'], static fn (array $g): bool => $g['missing'] + $g['draft'] + $g['outdated'] > 0);
            // 修复入口指向最先要处理的一类：先补缺的，再发布草稿，最后更新过期的
            $first = 'missing';
            foreach (['missing', 'draft', 'outdated'] as $kind) {
                if (array_sum(array_column($gaps, $kind)) > 0) { $first = $kind; break; }
            }
            $data[] = $item('translations', 'dlv_translations', $gaps === [] ? 'done' : 'todo', '/admin/translation_status.php?filter=' . $first,
                $gaps === [] ? '' : 'dlv_translations_gaps',
                ['list' => implode(', ', array_map(static fn (string $lang, array $g): string => ($facts['languages'][$lang] ?? $lang)
                    . ' ' . $g['missing'] . '/' . $g['draft'] . '/' . $g['outdated'], array_keys($gaps), $gaps))]);
        }

        $run = is_array($state['run'] ?? null) ? $state['run'] : [];
        $ran = (int) ($run['at'] ?? 0) > 0;
        $health = is_array($run['health'] ?? null) ? $run['health'] : [];
        $content = is_array($run['content'] ?? null) ? $run['content'] : [];
        $check = [
            $item('health', 'dlv_health', !$ran ? 'unknown' : ((int) ($health['critical'] ?? 0) > 0 ? 'fail' : ((int) ($health['recommended'] ?? 0) > 0 ? 'warn' : 'done')),
                '/admin/site_health.php', $ran ? 'dlv_health_counts' : 'dlv_not_run',
                ['critical' => (string) (int) ($health['critical'] ?? 0), 'recommended' => (string) (int) ($health['recommended'] ?? 0)]),
            $item('content', 'dlv_content', !$ran ? 'unknown' : ((int) ($content['error'] ?? 0) > 0 ? 'fail' : ((int) ($content['review'] ?? 0) > 0 ? 'warn' : 'done')),
                '/admin/site_content_check.php?scan=1', $ran ? 'dlv_content_counts' : 'dlv_not_run',
                ['error' => (string) (int) ($content['error'] ?? 0), 'review' => (string) (int) ($content['review'] ?? 0), 'scanned' => (string) (int) ($content['scanned'] ?? 0)]),
        ];

        $accept = [];
        $urls = ['inquiry_test' => '/admin/form.php', 'mobile_review' => '/', 'language_review' => '/admin/translation_status.php', 'handover' => ''];
        foreach (self::ACCEPT_KEYS as $key) {
            if ($key === 'language_review' && $facts['languages'] === []) continue;
            $by = $state['accept'][$key] ?? null;
            $row = $item($key, 'dlv_accept_' . $key, $by !== null ? 'done' : 'todo', $urls[$key]);
            $row['by'] = $by;
            if ($key === 'inquiry_test') {
                [$row['detail'], $row['params']] = $facts['latest_inquiry'] > 0
                    ? ['dlv_latest_inquiry', ['time' => date('Y-m-d H:i', $facts['latest_inquiry'])]] : ['dlv_no_inquiry', []];
            }
            $accept[] = $row;
        }

        $open = 0;
        foreach ([$data, $check, $accept] as $group) {
            foreach ($group as $row) if ($row['status'] !== 'done' && $row['key'] !== 'handover') $open++;
        }
        return ['data' => $data, 'check' => $check, 'accept' => $accept, 'open' => $open];
    }

    /** @param array<string,mixed> $state */
    private static function save(array $state): void
    {
        settingModel()->set(self::SETTING_KEY, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'delivery');
    }
}
