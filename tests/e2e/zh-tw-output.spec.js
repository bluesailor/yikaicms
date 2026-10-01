const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const root = path.resolve(__dirname, '../..');
const php = (args, input) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'zh-tw-fixture.php'), ...args], { cwd: root, input }).toString();

// 访客看得到的部分：去掉脚本（单独检查）、<style> 与 HTML 注释
const visible = (html) => html.replace(/<script\b[^>]*>[\s\S]*?<\/script>|<style\b[^>]*>[\s\S]*?<\/style>|<!--[\s\S]*?-->/gi, '');
const inlineScripts = (html) => [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].map((m) => m[1]).filter((s) => /[一-鿿]|\\u[4-9][0-9a-f]{3}/i.test(s));

test.beforeAll(() => php(['prepare']));
test.afterAll(() => php(['restore']));

// 繁体中文是简体的「渲染视图」：整页输出前简→繁。凡是绕过这一步的文字（<script> 里的结构化数据、
// 界面文案字典、灯箱标题，前台 AJAX 的 JSON 提示）都会以简体出现在繁体站上——这里把它们全找出来。
test('zh-TW pages contain no Simplified Chinese leftovers @ci', async ({ page, request }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one crawl is enough');
  const seen = new Set();
  const queue = ['/zh-TW/'];
  // 测试站首页链接不多：拿简体站的栏目、列表、详情页地址换上 /zh-TW/ 前缀一起抓
  const zhHome = await (await request.get('/')).text();
  for (const m of zhHome.matchAll(/href="(\/[a-z0-9][a-z0-9_/-]*\.html)"/g)) queue.push('/zh-TW' + m[1]);
  for (const p of ['/news.html', '/product.html', '/download.html', '/case.html', '/job.html', '/contact.html']) queue.push('/zh-TW' + p);
  const segments = [];
  const collect = (where, html) => {
    segments.push({ where: where + ' [html]', text: visible(html) });
    inlineScripts(html).forEach((s, i) => segments.push({ where: where + ' [script ' + i + ']', text: s }));
  };
  while (queue.length && seen.size < 60) {
    const url = queue.shift();
    if (seen.has(url)) continue;
    seen.add(url);
    const res = await request.get(url);
    const html = await res.text();
    collect(url, html);
    for (const m of html.matchAll(/href="([^"#?]+)"/g)) {
      let target;
      try { target = new URL(m[1], res.url()); } catch (e) { continue; }
      if (target.origin === new URL(res.url()).origin && target.pathname.startsWith('/zh-TW/')
        && !/\.(css|js|png|jpe?g|webp|svg|gif|ico|pdf|zip|xml)$/i.test(target.pathname) && !seen.has(target.pathname)) queue.push(target.pathname);
    }
  }
  expect(seen.size).toBeGreaterThan(20);

  // 搜索：访客输入繁体，内容存的是简体——要搜得到，结果页仍是繁体
  const search = await (await request.get('/zh-TW/search.html?keyword=' + encodeURIComponent('產品'))).text();
  collect('search', search);
  expect(search).not.toContain('沒有找到相關內容');
  expect(visible(search)).toMatch(/href="\/zh-TW\/(news|product|case)\/[^"]+\.html"/);

  // 前台 AJAX：表单提交的 JSON 提示也要是繁体
  const submit = await request.post('/form_submit.php?_lang=zh-TW', { form: { form_slug: 'contact' }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  const submitText = await submit.text();
  expect(JSON.parse(submitText).msg).toMatch(/[一-鿿]/);
  segments.push({ where: 'form_submit [json]', text: submitText });

  // 浏览器里跑一遍首页，收集运行时插入的文字与接口返回
  page.on('response', async (r) => {
    if (/json/.test(r.headers()['content-type'] || '')) {
      try { segments.push({ where: r.url() + ' [json]', text: await r.text() }); } catch (e) { /* 重定向等无正文 */ }
    }
  });
  await page.goto('/zh-TW/');
  await page.waitForLoadState('networkidle');
  segments.push({ where: '/zh-TW/ [rendered]', text: await page.locator('body').innerText() });

  const report = JSON.parse(php(['check'], JSON.stringify(segments)));
  fs.writeFileSync(info.outputPath('zh-tw-leftovers.json'), JSON.stringify({ pages: [...seen], report }, null, 2));
  expect(report).toEqual([]);
});
