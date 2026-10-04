const fs = require('fs');
const path = require('path');
const { test } = require('@playwright/test');

// 无障碍体检（按需运行，不进 CI）：axe-core 扫前台与后台主要页面，按规则汇总写到 A11Y_REPORT。
// 用法：A11Y_AXE=<axe.min.js 路径（cdnjs axe-core 4.10）> A11Y_REPORT=<输出.json> node tests/e2e/run-local.js tests/e2e/a11y-audit.spec.js
const axePath = process.env.A11Y_AXE;
const reportPath = process.env.A11Y_REPORT;
test.skip(!axePath || !reportPath, 'set A11Y_AXE and A11Y_REPORT');

const ADMIN = [
  '/admin/', '/admin/content.php', '/admin/article.php', '/admin/product.php', '/admin/product_edit.php',
  '/admin/channel.php', '/admin/media.php', '/admin/setting.php', '/admin/setting_lang.php', '/admin/banner.php',
  '/admin/form_design.php', '/admin/plugin.php', '/admin/theme.php', '/admin/nav_menu.php', '/admin/user.php',
  '/admin/recycle.php', '/admin/upgrade.php', '/admin/blox_templates.php', '/admin/blox_editor.php?page=home',
];

async function scan(page, url, results) {
  const res = await page.goto(url, { waitUntil: 'load' }).catch(() => null);
  if (!res || res.status() >= 400) { results.skipped.push(`${url} (${res ? res.status() : 'error'})`); return; }
  await page.waitForTimeout(600);
  await page.addScriptTag({ content: fs.readFileSync(axePath, 'utf8') });
  const out = await page.evaluate(async () => {
    const r = await window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] } });
    return r.violations.map((v) => ({
      id: v.id, impact: v.impact, help: v.help, tags: v.tags.filter((t) => t.startsWith('wcag')),
      nodes: v.nodes.map((n) => ({ target: n.target.join(' '), html: n.html.slice(0, 220), summary: (n.failureSummary || '').slice(0, 300) })),
    }));
  });
  results.pages[url] = out;
}

test('a11y audit', async ({ browser, baseURL }, info) => {
  test.setTimeout(15 * 60_000);
  const results = { project: info.project.name, pages: {}, skipped: [] };

  // 前台：匿名访问，从首页导航收集站内地址
  const anon = await browser.newContext({ baseURL, viewport: info.project.use.viewport, storageState: undefined });
  const fp = await anon.newPage();
  await fp.goto('/');
  const links = await fp.evaluate(() => [...document.querySelectorAll('a[href]')]
    .map((a) => new URL(a.getAttribute('href'), location.href))
    .filter((u) => u.origin === location.origin && !/\.(zip|pdf|jpg|png|webp|xml)$/i.test(u.pathname))
    .map((u) => u.pathname + u.search));
  const front = ['/', ...new Set(links)].filter((u, i, a) => a.indexOf(u) === i).slice(0, 30);
  front.push('/search.php?q=test', '/this-page-does-not-exist-a11y');
  for (const u of front) await scan(fp, u, results);
  await anon.close();

  // 后台：沿用全局登录态；另外单独扫登录页
  const login = await browser.newContext({ baseURL, viewport: info.project.use.viewport, storageState: undefined });
  await scan(await login.newPage(), '/admin/login.php', results);
  await login.close();
  const adminCtx = await browser.newContext({ baseURL, viewport: info.project.use.viewport, storageState: info.project.use.storageState });
  const ap = await adminCtx.newPage();
  for (const u of ADMIN) await scan(ap, u, results);
  // 编辑页：取列表里第一条
  for (const [list, re] of [['/admin/article.php', /article_edit\.php\?id=\d+|content_edit\.php\?id=\d+/], ['/admin/product.php', /product_edit\.php\?id=\d+/]]) {
    await ap.goto(list);
    const href = await ap.evaluate((src) => { const r = new RegExp(src); const a = [...document.querySelectorAll('a[href]')].find((x) => r.test(x.getAttribute('href'))); return a ? a.getAttribute('href') : ''; }, re.source);
    if (href) await scan(ap, new URL(href, ap.url()).pathname + new URL(href, ap.url()).search, results);
  }
  await adminCtx.close();

  const file = reportPath.replace(/\.json$/, `-${info.project.name}.json`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify(results, null, 2));
});
