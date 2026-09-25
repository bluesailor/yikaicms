<?php
declare(strict_types=1);
// Standalone local draft gallery. This file is never an import or marketplace authority.
$catalogPath = __DIR__ . '/catalog.json';
$catalog = is_file($catalogPath) ? json_decode((string) file_get_contents($catalogPath), true, 32) : null;
$items = is_array($catalog['templates'] ?? null) ? $catalog['templates'] : [];
function reviewEscape(string $text): string { return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Yikai · 整站模板草稿预览</title>
<style>
:root{color-scheme:light;--ink:#18292c;--muted:#617477;--line:#dbe6e4;--accent:#156950;--paper:#f4f8f5}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font-family:"Microsoft YaHei",system-ui,sans-serif}a{color:inherit}header{max-width:1320px;margin:auto;padding:50px 28px 32px}.eyebrow{display:flex;align-items:center;gap:14px;font-size:13px;font-weight:700;letter-spacing:.08em}.brand{font-size:24px;letter-spacing:.1em}.badge{border:1px solid #bad6c8;background:#e5f3e9;border-radius:20px;padding:5px 11px;color:var(--accent);letter-spacing:0}h1{font-size:clamp(30px,4vw,48px);letter-spacing:-.04em;margin:27px 0 16px}header p{max-width:780px;font-size:15px;line-height:1.85;color:var(--muted);margin:0}.facts{display:flex;flex-wrap:wrap;gap:12px;margin-top:22px}.facts span{font-size:13px;border-left:2px solid #9cbaa9;padding-left:10px}.controls{max-width:1320px;margin:auto;padding:10px 28px 25px;display:flex;flex-wrap:wrap;align-items:end;gap:16px}.field{display:grid;gap:7px;flex:1;max-width:360px}.field label{font-size:12px;color:var(--muted)}input,select{font:inherit;background:#fff;border:1px solid #c6d7d0;border-radius:8px;padding:11px 13px;width:100%;min-width:170px;color:var(--ink)}input:focus,select:focus,a:focus-visible{outline:3px solid #93cdb1;outline-offset:3px}#result-count{font-size:13px;color:var(--muted);margin-left:auto;padding-bottom:13px}main{max-width:1320px;margin:auto;padding:0 28px 54px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:25px}.card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 4px 15px #244a3610}.card[hidden]{display:none}.cover{display:block;aspect-ratio:1.52;background:#e8eeea;overflow:hidden;border-bottom:1px solid var(--line)}.cover img{width:100%;height:100%;object-fit:cover;object-position:top;display:block}.body{padding:23px;display:flex;flex:1;flex-direction:column;gap:11px}.industry{font-size:12px;color:var(--accent);font-weight:700}.body h2{font-size:19px;line-height:1.45;margin:0}.description{font-size:13px;line-height:1.8;color:var(--muted);margin:0;flex:1}.meta{font-size:12px;color:var(--muted);line-height:1.8}.dependency{font-size:12px;padding:9px 11px;border-radius:6px;background:#fff5df;color:#7c5b14;line-height:1.7}.actions{display:flex;gap:12px;align-items:center;margin-top:7px}.download{display:inline-block;text-decoration:none;background:var(--accent);color:white;border-radius:7px;padding:10px 13px;font-size:13px}.original{font-size:12px;text-underline-offset:4px;color:var(--muted)}footer{border-top:1px solid var(--line);padding:28px;text-align:center;color:var(--muted);font-size:12px;line-height:1.8}
@media(max-width:1050px){main{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){header{padding:30px 18px 23px}.controls{padding:0 18px 22px;gap:10px}.field{max-width:none}#result-count{width:100%;padding:0;margin:0}main{padding:0 18px 35px;grid-template-columns:1fr;gap:18px}h1{margin-top:22px}.body{padding:20px}}
</style></head><body>
<header><div class="eyebrow"><span class="brand">YIKAI</span><span class="badge">本地草稿 · 尚未上架</span></div>
<h1>为每一种生意，准备好开场。</h1><p>这批整站模板的页面、图片和栏目内容已经一起装进模板包。点击封面查看原图，下载后可在同一 CMS 版本线（主.次 版本相同）的站点中预览并导入，已有内容的站需确认替换。</p>
<div class="facts"><span><?= count($items) ?> 套行业模板</span><span>生成图片与封面采用 WebP</span><span>保留可视化编辑内容</span><span>待签名与正式发布</span></div></header>
<section class="controls" aria-label="筛选模板"><div class="field"><label for="search">查找模板</label><input type="search" id="search" placeholder="搜索名称或行业"></div><div class="field"><label for="industry">选择行业</label><select id="industry"><option value="">全部行业</option><?php foreach ($items as $item): ?><option value="<?= reviewEscape((string) $item['category']) ?>"><?= reviewEscape((string) ($item['category_name'] ?? $item['category'])) ?></option><?php endforeach; ?></select></div><span id="result-count" role="status">显示 <?= count($items) ?> 套</span></section>
<main id="templates">
<?php foreach ($items as $item):
    $slug = (string) ($item['slug'] ?? '');
    $version = (string) ($item['version'] ?? '');
    $package = (string) ($item['package'] ?? '');
    if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1 || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || basename($package) !== $package) continue;
    $coverPath = 'assets/site-templates/' . $slug . '/' . $version . '/preview.webp';
    $cover = $coverPath . '?v=' . (is_file(__DIR__ . '/' . $coverPath) ? (string) filemtime(__DIR__ . '/' . $coverPath) : '0');
    $name = (string) $item['name'];
    $industry = (string) ($item['category_name'] ?? $item['category']);
?>
<article class="card" data-industry="<?= reviewEscape((string) $item['category']) ?>" data-search="<?= reviewEscape($name . ' ' . $industry . ' ' . $slug) ?>">
<a class="cover" href="<?= reviewEscape($cover) ?>" target="_blank" rel="noopener" aria-label="<?= reviewEscape('查看 ' . $name . ' 原图') ?>"><img src="<?= reviewEscape($cover) ?>" alt="<?= reviewEscape($name . ' 首页预览') ?>" loading="lazy"></a>
<div class="body"><span class="industry"><?= reviewEscape($industry) ?></span><h2><?= reviewEscape($name) ?></h2><p class="description"><?= reviewEscape((string) ($item['description'] ?? '')) ?></p>
<div class="meta">版本 <?= reviewEscape($version) ?> · <?= number_format((int) ($item['size_bytes'] ?? 0) / 1000000, 2) ?> MB<br>适用 CMS <?= reviewEscape((string) $item['cms']) ?> · 包格式 <?= (int) $item['format_version'] ?></div>
<?php if (($item['format_version'] ?? 1) > 1): ?><div class="dependency">需要 2.0.0 起的导入器；导入时会一并安装、启用包里声明的插件。</div><?php endif; ?>
<div class="actions"><a class="download" href="<?= reviewEscape('packages/' . $package) ?>" download>下载整站包</a><a class="original" href="<?= reviewEscape($cover) ?>" target="_blank" rel="noopener">查看封面原图</a></div>
</div></article>
<?php endforeach; ?>
</main>
<footer>本页仅用于本地审阅，不是已经上线的模板市场。市场发布仍需完成官方签名和上传。</footer>
<script>
(function(){var search=document.getElementById('search'),industry=document.getElementById('industry'),cards=Array.from(document.querySelectorAll('.card')),result=document.getElementById('result-count');function filter(){var q=search.value.trim().toLowerCase(),category=industry.value,count=0;cards.forEach(function(card){var visible=(!category||card.dataset.industry===category)&&(!q||card.dataset.search.toLowerCase().includes(q));card.hidden=!visible;if(visible)count++;});result.textContent='显示 '+count+' 套';}search.addEventListener('input',filter);industry.addEventListener('change',filter);})();
</script></body></html>
