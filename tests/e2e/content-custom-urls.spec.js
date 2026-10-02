const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'content-custom-urls-fixture.php'), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// WordPress 迁移：文章、栏目、相册、标签都能用原站网址访问，旧地址 301 过去，hreflang 按条目给各语言网址。
test('registered URLs serve articles, channels, albums and tags; old URLs 301 @ci', async ({ browser, baseURL }) => {
  test.setTimeout(90000);
  const state = JSON.parse(fixture('setup'));
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const page = await visitor.newPage();
  const status = async (url) => (await visitor.request.get(url, { maxRedirects: 0 }));
  try {
    expect((await page.goto('/slewing-bearing-installation/')).status()).toBe(200);
    await expect(page.locator('h1').first()).toContainText(state.article.title);
    await expect(page.locator('link[rel=canonical]')).toHaveAttribute('href', /\/slewing-bearing-installation\/$/);
    await expect(page.locator('a[href="/category/news/"]').first()).toBeAttached();

    const old = await status(`/news/article/${state.article.slug}.html`);
    expect(old.status()).toBe(301);
    expect(old.headers().location).toBe('/slewing-bearing-installation/');
    const oldNews = await status('/news.html');
    expect(oldNews.status()).toBe(301);
    expect(oldNews.headers().location).toBe('/category/news/');
    const noSlash = await status('/tag/worm-gear');
    expect(noSlash.status()).toBe(301);
    expect(noSlash.headers().location).toBe('/tag/worm-gear/');

    expect((await page.goto('/category/news/')).status()).toBe(200);
    await expect(page.locator('a[href="/slewing-bearing-installation/"]').first()).toBeAttached();
    await expect(page.locator('link[rel=alternate][hreflang=en]')).toHaveAttribute('href', /\/en\/category\/news\/$/);
    await expect(page.locator('link[rel=alternate][hreflang=x-default]')).toHaveAttribute('href', /\/category\/news\/$/);
    expect((await status('/category/news/page/2/')).status()).toBe(200);
    expect((await status('/slewing-bearing-installation/page/2/')).status()).toBe(404);

    expect((await page.goto('/albums/lab-equipment/')).status()).toBe(200);
    await expect(page.getByText('WP lab description').first()).toBeVisible();

    expect((await page.goto('/tag/worm-gear/')).status()).toBe(200);
    await expect(page.locator('a[href="/slewing-bearing-installation/"]').first()).toBeAttached();
    expect((await status('/tag/not-registered/')).status()).toBe(404);
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
