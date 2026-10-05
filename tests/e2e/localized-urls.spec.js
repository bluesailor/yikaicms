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

    // 栏目（第二步）：有英文版的单页栏目——hreflang 指向英文栏目自己的网址；只有中文的单页栏目 /en/ 地址 302 回原文
    expect((await page.goto(state.pageZh)).status()).toBe(200);
    const about = await hreflangs();
    expect(about['zh-CN']).toBe(state.pageZh);
    expect(about.en).toBe(state.pageEn);
    expect((await visitor.request.get(about.en, { maxRedirects: 0 })).status()).toBe(200);
    expect((await page.goto('/only-chinese-channel-e2e.html')).status()).toBe(200);
    await expect(page.locator('link[rel=alternate][hreflang]')).toHaveCount(0);
    const fakeChannel = await visitor.request.get('/en/only-chinese-channel-e2e.html', { maxRedirects: 0 });
    expect(fakeChannel.status()).toBe(302);
    expect(new URL(fakeChannel.headers().location, baseURL).pathname).toBe('/only-chinese-channel-e2e.html');
    // 站点地图：英文栏目带 /en 前缀、用自己的别名
    const sitemap = await (await visitor.request.get('/sitemap.xml')).text();
    expect(sitemap).toContain(`${state.pageEn}</loc>`);

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
    // 动态网址下站点地图里的英文条目带 lang=en（此前按当前语言出网址，英文条目指向中文页）
    const dynamicSitemap = await (await visitor.request.get('/sitemap.xml')).text();
    expect(dynamicSitemap).toMatch(new RegExp(`slug=${state.en}&amp;lang=en</loc>`));
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
