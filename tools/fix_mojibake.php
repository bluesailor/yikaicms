<?php
/**
 * 一次性修复 contents 表内容字段中的 U+FFFD（"\xEF\xBF\xBD"）替换字符乱码。
 *
 * 起因：某次备份/还原时编码错配，把部分汉字（如 `果`）变成两个 `�`。
 * 用法：浏览器打开 /tools/fix_mojibake.php       先 dry-run 看会改哪些行；
 *      确认无误后访问 /tools/fix_mojibake.php?apply=1 实际写入。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

$dry = !isset($_GET['apply']);

$db = Database::getInstance();
$table = DB_PREFIX . 'contents';

$rows = $db->fetchAll(
    "SELECT id, slug, title, content FROM {$table} WHERE content LIKE :p",
    [':p' => '%' . "\xEF\xBF\xBD" . '%']
);

if (!$rows) {
    echo "OK：未发现 U+FFFD 乱码内容。\n";
    exit;
}

echo "发现 " . count($rows) . " 行有乱码字符：\n\n";

// slug => 已知正确的全文（从 install/sql/mysql.sql 中提取）
$KNOWN = [
    'manufacturing-digital-transformation' => "<h3>项目背景</h3>\n<p>客户为国内大型制造企业，拥有5个生产基地、2000+台设备。面临设备数据孤岛、生产计划依赖人工经验、质量追溯困难等痛点。</p>\n<h3>解决方案</h3>\n<ul>\n<li><strong>设备互联</strong>：部署200+台IoT网关，接入全部生产设备，实现数据实时采集</li>\n<li><strong>数据中台</strong>：搭建统一数据平台，打通ERP、MES、WMS系统数据</li>\n<li><strong>智能排产</strong>：基于AI算法的智能排产系统，优化生产计划</li>\n<li><strong>质量追溯</strong>：全流程二维码追溯体系，精准定位质量问题</li>\n</ul>\n<h3>项目成果</h3>\n<ul>\n<li>生产效率提升 <strong>30%</strong></li>\n<li>设备停机时间减少 <strong>45%</strong></li>\n<li>产品不良率降低 <strong>60%</strong></li>\n<li>库存周转率提升 <strong>25%</strong></li>\n</ul>\n<p>项目实施周期6个月，投入使用后第一年即实现投资回报。</p>",

    'php80-new-features' => "<p>PHP 8.0 是 PHP 语言的重大版本更新，带来了众多令人兴奋的新特性和性能改进。本文将深入解析其中最重要的变化。</p>\n<h3>JIT 编译器</h3>\n<p>PHP 8.0 引入了 JIT（即时编译）支持，在计算密集型场景下性能提升可达3倍。虽然对典型 Web 应用提升有限，但在数据处理和科学计算场景表现优异。</p>\n<h3>命名参数</h3>\n<pre><code>htmlspecialchars(\$string, double_encode: false);</code></pre>\n<p>命名参数使代码更具可读性，不再需要记忆参数顺序。</p>\n<h3>联合类型</h3>\n<pre><code>function foo(int|string \$id): void {}</code></pre>\n<p>原生支持联合类型声明，减少对 PHPDoc 注释的依赖。</p>\n<h3>Match 表达式</h3>\n<pre><code>\$result = match(\$status) {\n    1 => \"active\",\n    2 => \"inactive\",\n    default => \"unknown\",\n};</code></pre>\n<p>match 是 switch 的现代替代，支持严格比较和返回值。</p>\n<h3>Null 安全运算符</h3>\n<pre><code>\$country = \$user?->getAddress()?->country;</code></pre>\n<p>链式调用中优雅处理 null 值，避免冗长的 null 检查。</p>",
];

// 只对已确认的字段做逐字替换；未知内容不自动猜测，避免误改用户数据。
$REPLACEMENTS = [
    'process' => [
        'プロジェ���ト' => 'プロジェクト',
    ],
];

$fixedCount = 0;

foreach ($rows as $r) {
    $id   = (int) $r['id'];
    $slug = (string) $r['slug'];
    $orig = (string) $r['content'];

    echo "—— 行 #{$id}  slug=`{$slug}`  title=「{$r['title']}」\n";

    // 列出每处乱码上下文
    $pos = 0;
    while (($p = strpos($orig, "\xEF\xBF\xBD", $pos)) !== false) {
        $ctxStart = max(0, $p - 12);
        $ctxLen   = min(36, strlen($orig) - $ctxStart);
        $ctx = substr($orig, $ctxStart, $ctxLen);
        echo "    乱码上下文：…{$ctx}…\n";
        $pos = $p + 3;
    }

    if (isset($KNOWN[$slug])) {
        echo "    → 用 install/sql 中的干净版本覆盖。\n";
        if (!$dry) {
            $db->execute(
                "UPDATE {$table} SET content = :c, updated_at = :t WHERE id = :id",
                [':c' => $KNOWN[$slug], ':t' => time(), ':id' => $id]
            );
            $fixedCount++;
        }
    } elseif (isset($REPLACEMENTS[$slug])) {
        $clean = $orig;
        foreach ($REPLACEMENTS[$slug] as $broken => $replacement) {
            $clean = str_replace($broken, $replacement, $clean);
        }

        if ($clean === $orig) {
            echo "    ! 没有命中已确认的逐字修复规则，保持不变。\n";
            continue;
        }

        echo "    → 应用已确认的逐字替换。\n";
        if (!$dry) {
            $db->execute(
                "UPDATE {$table} SET content = :c, updated_at = :t WHERE id = :id",
                [':c' => $clean, ':t' => time(), ':id' => $id]
            );
            $fixedCount++;
        }
    } else {
        echo "    ! 没有该 slug 的已知干净文本——请去后台手工编辑，或往 \$KNOWN 加映射后重跑。\n";
    }
}

echo "\n";
if ($dry) {
    echo "Dry-run 完毕。确认要写入请访问 /tools/fix_mojibake.php?apply=1\n";
} else {
    echo "已修复 {$fixedCount} 行。请清空 storage/cache/html/ 后刷新页面。\n";
}
