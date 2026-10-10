const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');

const root = path.resolve(__dirname, '../..');
let cleanup = () => {};
test.beforeAll(() => { cleanup = installMarketThemes(root, ['business', 'minimal']); });
test.afterAll(() => cleanup());

for (const theme of ['default', 'business', 'minimal']) {
    for (const lang of ['zh-CN', 'en', 'ja']) {
        test(`${theme} demo product images render on lists and details in ${lang} @ci`, async ({ browser, baseURL }, testInfo) => {
            // Public readback must not inherit the runner's administrator cookies.
            const context = await browser.newContext({ baseURL, viewport: testInfo.project.use.viewport });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            const query = new URLSearchParams({ theme, lang });
            try {
                const response = await page.goto(`/tests/e2e/theme-product-demo-page.php?${query}`);
                expect(response.status()).toBe(200);
                // 卡片用 800px 的 _medium 版（带 srcset，2026-10-10 起），详情可能用原图：按同一张图比较，不按文件名
                const images = page.locator('main img[src*="/assets/images/demo/product-"][src*="-v2"][src$=".webp"]');
                await expect(images).toHaveCount(12);
                // 不用 decode()：srcset 中途换候选图时它会拒绝（偶发 EncodingError），等加载完成即可
                await images.evaluateAll(nodes => Promise.all(nodes.map(node => {
                    node.loading = 'eager';
                    return node.complete && node.naturalWidth > 0 ? null : new Promise((resolve, reject) => {
                        node.addEventListener('load', resolve, { once: true });
                        node.addEventListener('error', () => reject(new Error('image failed: ' + node.currentSrc)), { once: true });
                    });
                })));
                await expect.poll(() => images.evaluateAll(nodes => nodes.every(node => node.complete && node.naturalWidth > 0))).toBe(true);
                const products = await images.evaluateAll(nodes => nodes.map(node => ({
                    title: node.alt, source: node.getAttribute('src').replace(/_medium(?=\.webp$)/, ''),
                    link: node.closest('a').href, width: node.naturalWidth, height: node.naturalHeight, current: node.currentSrc,
                })));
                expect(products.map(product => product.source).sort()).toEqual(
                    Array.from({ length: 12 }, (_, index) => `/assets/images/demo/product-${101 + index}-v2.webp`).sort()
                );
                for (const product of products) {
                    expect(product.title).not.toBe('');
                    // srcset 下 naturalWidth 按 sizes 换算过，不等于文件像素：只看图确实解出来、是 4:3 的演示图
                    expect(product.current).toMatch(/\/assets\/images\/demo\/product-1\d\d-v2(?:_medium)?\.webp$/);
                    expect(product.width).toBeGreaterThan(0);
                    expect(Math.abs(product.width / product.height - 4 / 3)).toBeLessThan(0.02);
                }
                await expect(page.locator('body')).not.toContainText(/Warning:|Fatal error:|Notice:/);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                await page.locator('main').screenshot({ path: testInfo.outputPath('product-list.png') });
                // Hardware, software and sensor each traverse their actual detail renderer.
                for (const product of products.slice(0, 3)) {
                    const route = new URL(product.link).searchParams;
                    expect(route.get('yk_route')).toBe('product');
                    const target = new URLSearchParams(query);
                    if (route.has('slug')) target.set('slug', route.get('slug'));
                    else {
                        expect(route.get('id')).toMatch(/^\d+$/);
                        target.set('id', route.get('id'));
                    }
                    const detail = await page.goto(`/tests/e2e/theme-product-demo-page.php?${target}`);
                    expect(detail.status()).toBe(200);
                    const stem = product.source.replace(/\.webp$/, '');
                    const cover = page.locator(`main img[src="${product.source}"], main img[src="${stem}_medium.webp"]`).first();
                    await expect(cover).toBeVisible();
                    await expect.poll(() => cover.evaluate(node => node.complete && node.naturalWidth > 0)).toBe(true);
                    await expect(page.locator('main')).toContainText(product.title);
                    await expect(page.locator('body')).not.toContainText(/Warning:|Fatal error:|Notice:/);
                    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
                }
                await page.locator('main').screenshot({ path: testInfo.outputPath('product-detail.png') });
                expect(errors).toEqual([]);
            } finally {
                await context.close();
            }
        });
    }
}
