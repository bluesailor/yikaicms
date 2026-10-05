const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'localized-urls-fixture.php'), ...args], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// 2.0.5 LocalizedUrl：hreflang 与语言切换只指向真实存在的译文；没有译文的语言版本 302 回原文（不再拿中文冒充英文页）；
// 动态网址模式下 hreflang 保留 yk_route，语言放进 lang 参数（此前拼成 /en/index.php，404）。
test('hreflang, switcher and missing translations follow the real translation group @ci', async ({ browser, baseURL }) => {
  test.setTimeout(90000);
  const state = JSON.parse(fixture('setup'));
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const page = await visitor.newPage();
  const hreflangs = async () => Object.fromEntries(await page.locator('link[rel=alternate][hreflang]').evaluateAll(
    links => links.map(link => [link.getAttribute('hreflang'), new URL(link.href).pathname + new URL(link.href).search])));
  try {
    // 有三个版本：各语言用各自那一行的网址（译文行有自己的别名，与它页面上的 canonical 一致）
    expect((await page.goto(`/news/article/${state.translated}.html`)).status()).toBe(200);
    const all = await hreflangs();
    expect(all['zh-CN']).toBe(`/news/article/${state.translated}.html`);
    expect(all.en).toBe(`/en/news/article/${state.en}.html`);
    expect(all.ja).toBe(`/ja/news/article/${state.ja}.html`);
    expect(all['x-default']).toBe(`/news/article/${state.translated}.html`);
    for (const url of Object.values(all)) expect((await visitor.request.get(url, { maxRedirects: 0 })).status(), url).toBe(200);
    await expect(page.locator(`a[href="/ja/news/article/${state.ja}.html"]`).first()).toBeAttached();

    // 只有中文：不输出 hreflang；切换器去英文、日文首页；/en/ 地址 302 回中文原文
    expect((await page.goto('/news/article/only-chinese-e2e.html')).status()).toBe(200);
    await expect(page.locator('link[rel=alternate][hreflang]')).toHaveCount(0);
    await expect(page.locator('a[href="/en/"]').first()).toBeAttached();
    await expect(page.locator('a[href="/en/news/article/only-chinese-e2e.html"]')).toHaveCount(0);
    const fake = await visitor.request.get('/en/news/article/only-chinese-e2e.html', { maxRedirects: 0 });
    expect(fake.status()).toBe(302);
    expect(new URL(fake.headers().location, baseURL).pathname).toBe('/news/article/only-chinese-e2e.html');

    // 动态网址模式
    fixture('mode', 'query');
    expect((await page.goto(`/index.php?yk_route=article&slug=${state.translated}`)).status()).toBe(200);
    const dynamic = await hreflangs();
    expect(dynamic.en).toBe(`/index.php?yk_route=article&slug=${state.en}&lang=en`);
    expect(dynamic.ja).toBe(`/index.php?yk_route=article&slug=${state.ja}&lang=ja`);
    for (const url of Object.values(dynamic)) expect((await visitor.request.get(url, { maxRedirects: 0 })).status(), url).toBe(200);
    expect((await page.goto('/index.php?yk_route=news')).status()).toBe(200);
    expect((await hreflangs()).en).toBe('/index.php?yk_route=news&lang=en');
    const fakeDynamic = await visitor.request.get('/index.php?yk_route=article&slug=only-chinese-e2e&lang=en', { maxRedirects: 0 });
    expect(fakeDynamic.status()).toBe(302);
    expect(fakeDynamic.headers().location).toContain('yk_route=article&slug=only-chinese-e2e');
    expect(fakeDynamic.headers().location).not.toContain('lang=en');
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
