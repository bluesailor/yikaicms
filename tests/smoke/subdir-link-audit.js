// 子目录部署链接审计（2.1.3）：站点挂在子目录（默认 /sub）时，找出没带前缀的站内地址。
// 输出改写只碰 HTML 属性；脚本数据（菜单、搜索结果、图片切换、JSON 接口）里的地址要在生成处补前缀，
// 漏了就会跳到域名根目录。本工具登录后台、走遍侧栏全部页面、插件页、编辑器与前台抽样页，检查：
//   ① 渲染后 DOM 里 href/src/action 没带前缀  ② 浏览器实际发出的越界请求  ③ 内联脚本与 JSON 接口里的站内地址
// 会写回内容的地址（编辑器链接目录、区块数据）按设计保持站内相对口径，报告里会出现，属预期。
//
// 用法（与门禁同款的子目录夹具）：
//   1. 站点工作树放在 <父目录>/sub，SMOKE_SITE_URL=http://127.0.0.1:8791/sub php tests/smoke/setup.php
//   2. php -S 127.0.0.1:8791 tests/smoke/subdir-router.php（文档根是放站点目录的那一层）
//   3. node tests/smoke/subdir-link-audit.js [http://127.0.0.1:8791] [/sub] [报告.json]
//   用完 php tests/smoke/setup.php --restore
const { chromium } = require('playwright');
const fs = require('fs');

const ORIGIN = process.argv[2] || 'http://127.0.0.1:8791';
const BASE = process.argv[3] || '/sub';
const OUT = process.argv[4] || require('path').join(require('os').tmpdir(), 'yikai-subdir-link-audit.json');

const bad = (u) => typeof u === 'string' && u.startsWith('/') && !u.startsWith('//') && !u.startsWith(BASE + '/') && u !== BASE;

async function scanPage(page, label, findings) {
  const res = await page.evaluate(({ BASE }) => {
    const bad = (u) => typeof u === 'string' && u.startsWith('/') && !u.startsWith('//') && !u.startsWith(BASE + '/') && u !== BASE;
    const out = [];
    for (const [sel, attr] of [['a[href]', 'href'], ['[src]', 'src'], ['form[action]', 'action'], ['link[href]', 'href'], ['[formaction]', 'formaction'], ['[data-href]', 'data-href'], ['[data-src]', 'data-src']]) {
      document.querySelectorAll(sel).forEach((el) => {
        const v = el.getAttribute(attr);
        if (bad(v)) out.push({ kind: 'attr', attr, value: v, html: el.outerHTML.slice(0, 160) });
      });
    }
    // 内联脚本里的 JSON/字符串：只报 "url"/"href"/"src"/"link" 这类键或明显的站内路径
    document.querySelectorAll('script:not([src])').forEach((s) => {
      const t = s.textContent || '';
      const re = /"(?:url|href|src|link|action|endpoint|api)"\s*:\s*"((?:\\\/|\/)[^"]{0,120})"/g;
      let m;
      while ((m = re.exec(t))) {
        const v = m[1].replace(/\\\//g, '/');
        if (bad(v)) out.push({ kind: 'script-json', value: v, ctx: t.slice(Math.max(0, m.index - 60), m.index + 80) });
      }
    });
    return out;
  }, { BASE });
  for (const r of res) findings.push({ page: label, ...r });
}

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const findings = [];
  const requests = [];
  let current = '';
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (u.origin === ORIGIN && bad(u.pathname)) requests.push({ page: current, url: u.pathname + u.search, type: req.resourceType() });
  });
  page.on('dialog', (d) => d.dismiss());
  // JSON 接口返回里的站内地址（给前端拼链接用的数据）
  const jsonHits = [];
  const SITE = /^\/(?:admin|uploads|plugins|assets|api|member|themes|[a-z0-9_-]+\.(?:html|php))(?:[\/?#]|$)/;
  const walk = (v, path, out) => {
    if (typeof v === 'string') { if (SITE.test(v) && bad(v)) out.push({ key: path, value: v }); return; }
    if (Array.isArray(v)) { v.forEach((x, i) => walk(x, path + '[]', out)); return; }
    if (v && typeof v === 'object') for (const [k, x] of Object.entries(v)) walk(x, path + '.' + k, out);
  };
  page.on('response', async (resp) => {
    try {
      const u = new URL(resp.url());
      if (u.origin !== ORIGIN || !(resp.headers()['content-type'] || '').includes('json')) return;
      const out = [];
      walk(await resp.json(), '', out);
      for (const o of out) jsonHits.push({ page: current, endpoint: u.pathname, ...o });
    } catch (e) {}
  });

  // 登录（冒烟夹具的测试账号，仅本机 127.0.0.1）
  current = 'login';
  await page.goto(ORIGIN + BASE + '/admin/login.php');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'smoke@Test123');
  await Promise.all([page.waitForNavigation({ timeout: 30000 }).catch(() => {}), page.click('button[type="submit"] >> visible=true')]);
  if (!page.url().includes('/admin/') || page.url().includes('login.php')) throw new Error('login failed: ' + page.url());

  // 后台：侧栏菜单的全部条目（从飞出菜单数据取，补上前缀访问）
  const menu = await page.evaluate(() => window.__ykMenuFly || {});
  const adminUrls = new Set(['/admin/index.php']);
  for (const g of Object.values(menu)) for (const it of g.items) if (it.url) adminUrls.add(it.url.slice(it.url.startsWith(BASE + '/') ? BASE.length : 0));
  // 前台
  const front = ['/', '/news.html', '/product.html', '/search.php?q=a', '/contact.html', '/sitemap.xml', '/en/', '/member/login.php'];

  const visit = async (path) => {
    current = path;
    try {
      const resp = await page.goto(ORIGIN + BASE + path, { waitUntil: 'networkidle', timeout: 30000 });
      await page.waitForTimeout(300);
      const status = resp ? resp.status() : 0;
      if (path.endsWith('.xml')) return status;
      await scanPage(page, path, findings);
      if (!path.startsWith('/admin/')) {
        // 前台交互：切换产品缩略图、打开灯箱（这些图片地址由脚本数据决定）
        for (const sel of ['.thumb-btn >> nth=1', '[data-lightbox] >> nth=0', '#mainImage', '[onclick*="openCaseLightbox"] >> nth=0']) {
          const el = await page.$(sel).catch(() => null);
          if (el) { await el.click({ timeout: 2000 }).catch(() => {}); await page.waitForTimeout(500); await page.keyboard.press('Escape').catch(() => {}); }
        }
      }
      return status;
    } catch (e) {
      findings.push({ page: path, kind: 'error', value: String(e).slice(0, 200) });
      return -1;
    }
  };
  const statuses = {};
  for (const u of adminUrls) statuses[u] = await visit(u);
  // 折叠侧栏后重看一次控制台，飞出菜单的模板会被 Alpine 渲染
  await page.evaluate(() => { document.cookie = 'sidebarCollapsed=1; path=/'; try { localStorage.setItem('sidebarCollapsed', '1'); } catch (e) {} });
  current = '/admin/index.php#collapsed';
  await page.goto(ORIGIN + BASE + '/admin/index.php', { waitUntil: 'networkidle' });
  const groups = await page.$$('.sidebar-group');
  for (const g of groups) { await g.hover().catch(() => {}); await page.waitForTimeout(150); await scanPage(page, '/admin/index.php#flyout', findings); }
  await page.evaluate(() => { document.cookie = 'sidebarCollapsed=0; path=/'; try { localStorage.removeItem('sidebarCollapsed'); } catch (e) {} });
  // 交互：顶栏搜索、提醒铃铛
  current = '/admin/index.php#search';
  await page.goto(ORIGIN + BASE + '/admin/index.php', { waitUntil: 'networkidle' });
  for (const q of ['产品', '设置', 'seo']) {
    await page.fill('#adminSearchInput', q).catch(() => {});
    await page.waitForTimeout(800);
    await scanPage(page, '/admin/index.php#search:' + q, findings);
  }
  const bell = await page.$('[aria-label*="提醒"], [data-testid="admin-bell"], .admin-bell button');
  if (bell) { await bell.click().catch(() => {}); await page.waitForTimeout(600); await scanPage(page, '/admin/index.php#bell', findings); }
  // 编辑器与编辑页
  for (const u of ['/admin/blox_editor.php?id=2', '/admin/blox_editor.php?home=1', '/admin/product_edit.php?id=1', '/admin/article_edit.php', '/admin/page_edit.php', '/admin/content_edit.php', '/admin/site_setup.php', '/admin/site_templates.php', '/admin/setting_home.php', '/admin/form_design.php']) statuses[u] = await visit(u);
  for (const slug of ['announcement', 'cookie-consent', 'dologin', 'menu-sort', 'product-import', 'search-replace', 'seo', 'shop', 'stats']) {
    const u = '/admin/plugin_page.php?plugin=' + slug;
    statuses[u] = await visit(u);
  }
  // 前台：站点地图里的地址（去掉 /sub 前缀），抽 30 个，按类型分散
  const sm = await (await page.request.get(ORIGIN + BASE + '/sitemap.xml')).text();
  const locs = [...sm.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => ((p) => (p.startsWith(BASE + '/') ? p.slice(BASE.length) : p))(new URL(m[1]).pathname));
  const step = Math.max(1, Math.floor(locs.length / 30));
  for (let i = 0; i < locs.length; i += step) front.push(locs[i]);
  for (const u of front) statuses[u] = await visit(u);

  // 去重
  const key = (f) => [f.kind, f.attr || '', f.value].join('|');
  const grouped = {};
  for (const f of findings) { const k = key(f); (grouped[k] ||= { ...f, pages: [] }).pages.push(f.page); }
  const reqGrouped = {};
  for (const r of requests) { const k = r.type + '|' + r.url.split('?')[0]; (reqGrouped[k] ||= { ...r, pages: [] }).pages.push(r.page); }
  const jg = {};
  for (const h of jsonHits) { const k = h.endpoint + '|' + h.key; (jg[k] ||= { ...h, values: [] }).values.push(h.value); }
  const report = { json: Object.values(jg).map((h) => ({ endpoint: h.endpoint, key: h.key, page: h.page, values: [...new Set(h.values)].slice(0, 4) })), statuses, findings: Object.values(grouped).map((f) => ({ ...f, pages: [...new Set(f.pages)].slice(0, 6), count: f.pages.length })), requests: Object.values(reqGrouped).map((r) => ({ ...r, pages: [...new Set(r.pages)].slice(0, 6) })) };
  fs.writeFileSync(OUT, JSON.stringify(report, null, 2));
  console.log('pages', Object.keys(statuses).length, 'non-200', Object.entries(statuses).filter(([, s]) => s !== 200).length, 'findings', report.findings.length, 'requests', report.requests.length, 'json', report.json.length);
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
