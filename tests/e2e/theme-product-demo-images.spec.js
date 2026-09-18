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
                const images = page.locator('main img[src*="/assets/images/demo/product-"][src$="-v2.webp"]');
                await expect(images).toHaveCount(12);
                await images.evaluateAll(nodes => Promise.all(nodes.map(node => {
                    node.loading = 'eager';
                    return node.decode();
                })));
                const products = await images.evaluateAll(nodes => nodes.map(node => ({
                    title: node.alt, source: node.getAttribute('src'),
                    link: node.closest('a').href, width: node.naturalWidth, height: node.naturalHeight,
                })));
                expect(products.map(product => product.source).sort()).toEqual(
                    Array.from({ length: 12 }, (_, index) => `/assets/images/demo/product-${101 + index}-v2.webp`).sort()
                );
                for (const product of products) {
                    expect(product.title).not.toBe('');
                    expect([product.width, product.height]).toEqual([1200, 900]);
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
                    const cover = page.locator(`main img[src="${product.source}"]`).first();
                    await expect(cover).toBeVisible();
                    await cover.evaluate(node => node.decode());
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
