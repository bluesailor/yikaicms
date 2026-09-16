<?php
/**
 * 远程精品区块（首批十二款）打包与校验。
 *
 * 只做「资源准备」：把暂存的随包区块包装成市场契约的模板包 + 目录条目元数据，
 * 逐个过真实的 BloxTemplateImporter 管线证明可导入，并核对元素注册与素材存在。
 * 不上传、不发布、不写数据库、不改 CMS 运行代码。
 *
 * 用法：
 *   php tools/build_premium_sections.php            # 校验并输出清单
 *   php tools/build_premium_sections.php --write    # 同时写出包与 manifest 到暂存目录
 *
 * 暂存根目录（不在仓库内，避免随安装包分发）：
 *   D:/phpstudy_pro/g5-local/claude-section-library/
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$stageRoot = 'D:/phpstudy_pro/g5-local/claude-section-library';
$sourceDir = $stageRoot . '/source/sections';
$assetDir = $stageRoot . '/source/assets';
$outDir = $stageRoot . '/premium';
$write = in_array('--write', $argv, true);

/**
 * 首批十二款：六类 × 两个方向（任务书 §5）。
 * `source` 指向暂存的随包区块；`new` 表示需要新建（当前无对应设计）。
 * 三语名称/描述在这里定稿，随包条目一起进 manifest。
 */
$catalog = [
    // ── 首屏 ──────────────────────────────────────────
    'hero-intro' => ['category' => 'hero', 'direction' => '内容高度背景首屏', 'source' => 'hero-intro',
        'name' => ['zh-CN' => '内容高度首屏', 'en' => 'Content-height hero', 'ja' => 'コンテンツ高のヒーロー'],
        'desc' => ['zh-CN' => '高度由内容决定的开场区，适合以文字主张开场、不强制占满整屏。',
                   'en' => 'An opening section sized by its own content — leads with the message instead of forcing a full-screen cover.',
                   'ja' => '高さが内容で決まるオープニング。全画面を強制せず、メッセージで始めます。']],
    'hero-split' => ['category' => 'hero', 'direction' => '产品展示图文首屏', 'source' => 'hero-split',
        'name' => ['zh-CN' => '图文分栏首屏', 'en' => 'Split hero', 'ja' => '2カラムヒーロー'],
        'desc' => ['zh-CN' => '左文右图的开场区，主张、副文与两个按钮并排展示产品图。',
                   'en' => 'Copy on one side, product image on the other, with a headline, supporting text and two buttons.',
                   'ja' => '左に文章、右に製品画像。見出し・補足・2 つのボタンを並べます。']],

    // ── 企业介绍 ──────────────────────────────────────
    'image-text-reverse' => ['category' => 'about', 'direction' => '图文介绍', 'source' => 'image-text-reverse',
        'name' => ['zh-CN' => '图左文右介绍', 'en' => 'Image-left introduction', 'ja' => '画像左の紹介'],
        'desc' => ['zh-CN' => '图片在左、标题与正文在右的介绍区，含分隔线与行动按钮。',
                   'en' => 'Image on the left, heading and body on the right, with a divider and a call-to-action button.',
                   'ja' => '左に画像、右に見出しと本文。区切り線と行動ボタン付き。']],
    'stats-band' => ['category' => 'about', 'direction' => '指标条', 'source' => 'stats-band',
        'name' => ['zh-CN' => '数据指标条', 'en' => 'Metrics band', 'ja' => '指標バンド'],
        'desc' => ['zh-CN' => '四项关键指标横向排列，用数字支撑企业实力主张。',
                   'en' => 'Four key metrics in a row — numbers that back up the claims above them.',
                   'ja' => '主要指標を 4 つ横並びに。数字で主張を裏づけます。']],

    // ── 服务优势 ──────────────────────────────────────
    'trust-grid' => ['category' => 'features', 'direction' => '图标清单', 'source' => 'trust-grid',
        'name' => ['zh-CN' => '可核验优势清单', 'en' => 'Verifiable advantages', 'ja' => '検証できる強み'],
        'desc' => ['zh-CN' => '图标加标题加描述的三栏清单，适合罗列可核验的交付标准。',
                   'en' => 'A three-column icon list for delivery standards a buyer can actually verify.',
                   'ja' => 'アイコン＋見出し＋説明の 3 カラム。検証可能な基準の列挙に適します。']],
    'feature-cards-soft' => ['category' => 'features', 'direction' => '图片服务卡片', 'source' => 'feature-cards-soft',
        'name' => ['zh-CN' => '柔和服务卡片', 'en' => 'Soft service cards', 'ja' => 'ソフトなサービスカード'],
        'desc' => ['zh-CN' => '浅底卡片承载图标与说明，比清单式更有层次，适合服务介绍。',
                   'en' => 'Icons and copy on soft-tinted cards — more depth than a plain list, good for service overviews.',
                   'ja' => '淡い背景のカードにアイコンと説明。一覧より立体的で、サービス紹介に向きます。']],

    // ── 产品展示 ──────────────────────────────────────
    'card-grid' => ['category' => 'products', 'direction' => '三列产品卡片', 'source' => 'card-grid',
        'name' => ['zh-CN' => '三列图文卡片', 'en' => 'Three-column cards', 'ja' => '3 カラムカード'],
        'desc' => ['zh-CN' => '三张等宽卡片，每张含封面、标题与说明，适合产品或方案并列。',
                   'en' => 'Three equal cards with cover, title and copy — for products or offers side by side.',
                   'ja' => 'カバー・タイトル・説明を持つ均等な 3 カード。製品や提案の並列に。']],
    'product-comparison' => ['category' => 'products', 'direction' => '产品亮点对比', 'source' => 'product-comparison',
        'name' => ['zh-CN' => '方案对比', 'en' => 'Plan comparison', 'ja' => 'プラン比較'],
        'desc' => ['zh-CN' => '并排对比多个方案的亮点与按钮，帮助访客快速选型。',
                   'en' => 'Side-by-side highlights and buttons for each plan, so visitors can choose quickly.',
                   'ja' => '各プランの要点とボタンを並べ、訪問者が素早く選べるようにします。']],

    // ── 信任与答疑 ────────────────────────────────────
    'testimonial-grid' => ['category' => 'trust', 'direction' => '客户评价', 'source' => 'testimonial-grid',
        'name' => ['zh-CN' => '客户评价三栏', 'en' => 'Testimonial trio', 'ja' => '3 件のお客様の声'],
        'desc' => ['zh-CN' => '三条客户引语并排，手机端自动堆叠为单列。',
                   'en' => 'Three customer quotes side by side, stacking to one column on phones.',
                   'ja' => 'お客様の声を 3 件並べ、スマートフォンでは 1 列に積み重ねます。']],
    'faq-accordion' => ['category' => 'trust', 'direction' => '折叠 FAQ', 'source' => 'faq-accordion',
        'name' => ['zh-CN' => '折叠常见问题', 'en' => 'FAQ accordion', 'ja' => 'よくある質問（開閉）'],
        'desc' => ['zh-CN' => '可展开的常见问题列表，用分隔样式区分条目。',
                   'en' => 'An expandable FAQ list with divided entries.',
                   'ja' => '開閉できる FAQ 一覧。区切り線で項目を分けます。']],

    // ── 联系转化 ──────────────────────────────────────
    'faq-split' => ['category' => 'contact', 'direction' => '图文答疑转化', 'source' => 'faq-split',
        'name' => ['zh-CN' => '图文答疑', 'en' => 'Split FAQ', 'ja' => '画像付き FAQ'],
        'desc' => ['zh-CN' => '左图右问答，把答疑与视觉说明放在同一屏，减少来回滚动。',
                   'en' => 'Image beside the questions, so the explanation and the visual sit on one screen.',
                   'ja' => '左に画像、右に質問。説明と視覚情報を同じ画面に収めます。']],
    'contact-strip' => ['category' => 'contact', 'direction' => '联系信息与按钮', 'source' => 'contact-strip',
        'name' => ['zh-CN' => '联系转化条', 'en' => 'Contact strip', 'ja' => 'お問い合わせバー'],
        'desc' => ['zh-CN' => '一句主张加一个按钮的窄条，放在页面收尾促成联系。',
                   'en' => 'A slim band with one claim and one button, placed at the end of a page to prompt contact.',
                   'ja' => '主張とボタンだけの細いバー。ページ末尾で問い合わせを促します。']],
];

$version = '1.0.0';
$results = [];
$failures = 0;

foreach ($catalog as $slug => $meta) {
    $file = $sourceDir . '/' . $meta['source'] . '.json';
    $row = [
        'slug' => $slug,
        'category' => $meta['category'],
        'direction' => $meta['direction'],
        'version' => $version,
        'name' => $meta['name'],
        'description' => $meta['desc'],
        'status' => 'ok',
        'notes' => [],
    ];

    if (!is_file($file)) {
        $row['status'] = 'missing-source';
        $row['notes'][] = '暂存目录缺少来源文件 ' . basename($file);
        $results[] = $row;
        $failures++;
        continue;
    }

    $raw = (string) file_get_contents($file);
    $package = json_decode($raw, true);
    if (!is_array($package)) {
        $row['status'] = 'bad-json';
        $results[] = $row;
        $failures++;
        continue;
    }
    // 市场包要求 meta.source_ref 与目录 slug 严格一致（客户端下载后会复核）
    $package['meta'] = ['source_ref' => $slug];
    $package['name'] = $meta['name']['en'];
    $packageJson = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    // 真实导入管线校验：信封、元素注册、插件依赖、设计依赖
    try {
        $prepared = BloxTemplateImporter::prepare($packageJson);
        $row['type'] = $prepared['type'];
        $row['sections'] = count($prepared['sections']);
        $row['requires'] = $prepared['requirements']['elements'];
        if ($prepared['requirements']['plugins'] !== []) {
            $row['notes'][] = '依赖插件：' . implode(',', $prepared['requirements']['plugins']);
        }
    } catch (Throwable $e) {
        $row['status'] = 'import-failed';
        $row['notes'][] = $e->getMessage();
        $results[] = $row;
        $failures++;
        continue;
    }

    // 素材：包内引用的图必须在暂存资产里存在
    if (preg_match_all('#/assets/images/blox-templates/[A-Za-z0-9._-]+#', $raw, $m)) {
        foreach (array_unique($m[0]) as $ref) {
            $row['assets'][] = basename($ref);
            if (!is_file($assetDir . '/' . basename($ref))) {
                $row['status'] = 'missing-asset';
                $row['notes'][] = '缺少素材 ' . basename($ref);
                $failures++;
            }
        }
    }

    // 缩略图：随包时代的 section-*.png 可直接沿用；没有的明确标注缺图，不谎称已生成
    $thumb = 'section-' . $meta['source'] . '.png';
    $thumbJpg = 'section-' . $meta['source'] . '.jpg';
    if (is_file($assetDir . '/' . $thumb)) {
        $row['thumbnail'] = $thumb;
    } elseif (is_file($assetDir . '/' . $thumbJpg)) {
        $row['thumbnail'] = $thumbJpg;
    } else {
        $row['thumbnail'] = null;
        $row['notes'][] = '缺预览图（需按真实结构补拍/生成，不得用营销海报代替）';
    }

    if ($write) {
        if (!is_dir($outDir . '/packages')) {
            mkdir($outDir . '/packages', 0775, true);
        }
        file_put_contents($outDir . '/packages/' . $slug . '-v' . $version . '.json', $packageJson);
        $row['package'] = 'packages/' . $slug . '-v' . $version . '.json';
        $row['sha256'] = hash('sha256', $packageJson);
    }

    $results[] = $row;
}

if ($write) {
    if (!is_dir($outDir)) {
        mkdir($outDir, 0775, true);
    }
    file_put_contents(
        $outDir . '/premium-manifest.json',
        json_encode([
            'generated_at' => date('c'),
            'note' => '资源准备产物；未签名、未上传、未发布。签名与下载令牌属服务端职责。',
            'items' => $results,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

printf("%-22s %-10s %-9s %-8s %s\n", 'slug', 'category', 'status', 'sections', 'thumbnail');
foreach ($results as $r) {
    printf(
        "%-22s %-10s %-9s %-8s %s\n",
        $r['slug'],
        $r['category'],
        $r['status'],
        (string) ($r['sections'] ?? '-'),
        $r['thumbnail'] ?? '（缺）'
    );
    foreach ($r['notes'] as $n) {
        echo "    · " . $n . "\n";
    }
}
printf("\n共 %d 款；失败 %d 款。%s\n", count($results), $failures, $write ? '已写出到 ' . $outDir : '（未写出，加 --write 生成）');
exit($failures > 0 ? 1 : 0);
