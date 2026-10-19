<?php
/**
 * 下载语言的翻译缺口（2.1.1）：php tools/i18n/gaps.php [--json]
 *
 * 一行汇总各下载语言（核心 / 插件 / 安装器）缺多少键、多少过时，有缺口退出码 1。
 * premerge-check 只报告不拦；release-precheck 要求清零，或设 YK_ALLOW_I18N_GAPS=1 并在更新日志写明。
 * 起因：2.1.0 发出时 14 种下载语言每种缺 86 条文案，界面显示英文兜底，没人发现。
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

[, $opt] = i18n_args($argv);
$status = i18n_pipeline()->status();

$summary = [];
$languagesWithGaps = [];
foreach ($status as $code => $scopes) {
    foreach (I18nPipeline::SCOPES as $scope) {
        $s = $scopes[$scope];
        $missing = max(0, (int) $s['total'] - (int) $s['translated']);
        $stale = (int) $s['stale'];
        $summary[$scope]['missing'] = max($summary[$scope]['missing'] ?? 0, $missing);
        $summary[$scope]['stale'] = max($summary[$scope]['stale'] ?? 0, $stale);
        if ($missing + $stale > 0) {
            $languagesWithGaps[$code] = true;
        }
    }
}
$gaps = count($languagesWithGaps);

if (isset($opt['json'])) {
    echo json_encode(['languages' => count($status), 'languages_with_gaps' => $gaps, 'max_per_language' => $summary], JSON_PRETTY_PRINT) . "\n";
    exit($gaps > 0 ? 1 : 0);
}
$parts = [];
foreach ($summary as $scope => $s) {
    if ($s['missing'] + $s['stale'] > 0) {
        $parts[] = "{$scope} 最多缺 {$s['missing']}、过时 {$s['stale']}";
    }
}
echo $gaps === 0
    ? '下载语言 ' . count($status) . " 种已全部翻完\n"
    : "下载语言翻译缺口：{$gaps}/" . count($status) . ' 种语言有缺口（' . implode('；', $parts) . "），详见 php tools/i18n/status.php\n";
exit($gaps > 0 ? 1 : 0);
