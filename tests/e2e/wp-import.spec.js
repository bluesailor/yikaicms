const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY || 'php';
const fixture = action => execFileSync(php, [path.join(__dirname, 'wp-import-fixture.php'), action], { cwd: root, encoding: 'utf8' });
const importer = (...args) => execFileSync(php, [path.join(root, 'tools/wp-import.php'), `--dsn=sqlite:${path.join(root, 'storage/e2e-wordpress.sqlite')}`, ...args], { cwd: root, encoding: 'utf8' });

// WordPress 迁移：从 WordPress 数据库导入后，原站网址原样可访问，内容、SEO、翻译、产品参数都在；再导一次是更新不是重复。
test('WordPress import keeps every original URL with content, SEO and translations @ci', async ({ browser, baseURL }) => {
  test.setTimeout(120000);
  fixture('setup');
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const page = await visitor.newPage();
  try {
    const dry = importer('--dry-run');
    expect(dry).toContain('试运行');
    expect((await visitor.request.get('/slewing-bearing-installation-procedure/', { maxRedirects: 0 })).status()).toBe(404);

    const first = importer();
    expect(first).toContain('新建：');
    expect(first).toContain('contact-form-7');

    expect((await page.goto('/slewing-bearing-installation-procedure/')).status()).toBe(200);
    await expect(page).toHaveTitle(/^Installing a Slewing Bearing/);
    await expect(page.locator('meta[name=description]')).toHaveAttribute('content', 'Step-by-step slewing bearing installation.');
    await expect(page.locator('img[src="/wp-content/uploads/2023/05/gear.jpg"]').first()).toBeAttached();
    await expect(page.locator('a[href="/product/worm-gear-slew-drive/"]').first()).toBeAttached();
    await expect(page.locator('link[rel=alternate][hreflang=ja]')).toHaveAttribute('href', /\/ja\/%E6%97%8B/i);

    for (const url of ['/about/', '/about/engineer-team/', '/category/resource/technical-information/', '/tag/worm-gear/',
      '/product-category/slewing-drive/worm-gear-slew-drive-cat/', '/product-tag/heavy-duty/', '/how-does-a-slewing-bearing-work%EF%BC%9F/']) {
      expect((await visitor.request.get(url, { maxRedirects: 0 })).status(), url).toBe(200);
    }
    await page.goto('/about/engineer-team/');
    await expect(page.getByText('Twenty engineers.').first()).toBeVisible();

    expect((await page.goto('/product/worm-gear-slew-drive/')).status()).toBe(200);
    await expect(page).toHaveTitle(/^SE7 Worm Gear Slew Drive/);
    expect(await page.content()).toContain('73:1');   // 参数在默认收起的选项卡里

    expect((await visitor.request.get('/draft-post/', { maxRedirects: 0 })).status()).toBe(404);
    const again = importer();
    expect(again).toContain('更新：');
    expect(again).not.toContain('新建：');
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
