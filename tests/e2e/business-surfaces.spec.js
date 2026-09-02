const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');
const { frame, waitPreviewSettled, observeUnsafeWrites } = require('./helpers');
const root = path.resolve(__dirname, '../..');
let cleanup = () => {};
test.beforeAll(() => { cleanup = installMarketThemes(root, ['business']); });
test.afterAll(() => cleanup());
const url = (mode = 'normal', view = 'front') => `/tests/e2e/business-surfaces-page.php?mode=${mode}&view=${view}`;
const surfaces = (page) => page.locator('[data-business-surface]');
const tones = (page) => surfaces(page).evaluateAll((nodes) => nodes.map((node) => node.dataset.businessTone));

for (const view of ['front', 'legacy', 'preview']) {
    test(`Business alternates real sections in ${view} @ci`, async ({ page }, testInfo) => {
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const response = await page.goto(url('normal', view));
        expect(response.status()).toBe(200);
        const count = view === 'legacy' ? 6 : 4; // legacy automatically appends other home channels
        await expect(surfaces(page)).toHaveCount(count);
        if (view !== 'legacy') {
            await expect(page.locator('#siteHeader')).toHaveCSS('background-color', 'rgb(30, 41, 59)');
        }
        await expect.poll(() => tones(page)).toEqual(Array.from({ length: count }, (_, i) => i % 2 ? 'dark' : 'light'));
        await expect(surfaces(page).nth(1)).toHaveCSS('background-color', 'rgb(34, 39, 46)');
        await expect(surfaces(page).nth(3).locator('.business-card').first()).toHaveCSS('background-color', 'rgb(48, 55, 64)');
        await expect(surfaces(page).nth(3).locator('.business-copy').first()).toHaveCSS('color', 'rgb(195, 203, 212)');
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
        expect(errors).toEqual([]);
        if (view === 'front') {
            await surfaces(page).nth(3).locator('.business-title').first().scrollIntoViewIfNeeded();
            await expect(surfaces(page).nth(3).locator('[data-animate]').first()).toHaveCSS('opacity', '1');
            await page.screenshot({ path: testInfo.outputPath('business-surfaces.png') });
        }
    });
}

test('Business preserves custom backgrounds and excludes them from alternation @ci', async ({ page }) => {
    await page.goto(url('custom'));
    await expect(surfaces(page)).toHaveCount(4);
    await expect(surfaces(page).nth(0)).toHaveCSS('background-color', 'rgb(238, 245, 236)');
    await expect(surfaces(page).nth(0)).toHaveAttribute('data-business-surface', 'custom');
    await expect(surfaces(page).nth(2)).toHaveCSS('background-image', /cta-smart-manufacturing\.png/);
    await expect.poll(() => tones(page)).toEqual(['light', 'light', 'dark', 'dark']);
    const cta = page.locator('main section').filter({ has: page.locator('a[href*="contact"]') }).last();
    await expect(cta).not.toHaveAttribute('data-business-surface');
});

test('Business canvas uses the theme header when the active theme owns header rendering @ci', async ({ page }) => {
    await page.goto(url('published-header', 'preview'));
    await expect(page.locator('.yk-blox-header')).toHaveCount(0);
    const header = page.locator('#siteHeader');
    await expect(header).toHaveAttribute('data-business-home-header', '');
    await expect(header).toHaveCSS('background-color', 'rgb(30, 41, 59)');
    await expect(page.locator('body > main')).toHaveCount(1);
    await expect(page.locator('script[src*="/themes/business/assets/js/header.js"]')).toHaveCount(0);
});

test('Business editor iframe keeps the active theme homepage header chrome @ci', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Real editor shell is verified once; preview covers all sizes');
    await page.route('**/admin/blox_preview.php?home=1', async (route) => {
        const response = await route.fetch({ url: new URL(url('published-header', 'preview'), route.request().url()).href });
        await route.fulfill({ response });
    });
    await page.goto(url('published-header', 'editor'));
    await waitPreviewSettled(page);
    const canvas = await frame(page);
    await expect(canvas.locator('.yk-blox-header')).toHaveCount(0);
    const header = canvas.locator('#siteHeader');
    await expect(header).toHaveAttribute('data-business-home-header', '');
    await expect(header).toHaveCSS('background-color', 'rgb(30, 41, 59)');
    await expect(canvas.locator('body > main')).toHaveCount(1);
    await expect(canvas.locator('script[src*="/themes/business/assets/js/header.js"]')).toHaveCount(0);
});

test('Business respects section container and column backgrounds @ci', async ({ page }) => {
    await page.goto(url('parent'));
    for (let i = 0; i < 3; i++) {
        await expect(surfaces(page).nth(i)).toHaveAttribute('data-business-inherited', 'true');
        await expect(surfaces(page).nth(i)).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
    }
    await expect(surfaces(page).nth(1)).toHaveAttribute('data-business-tone', 'dark');
    await expect(surfaces(page).nth(3)).toHaveAttribute('data-business-tone', 'light');
});

test('Business explicit tones take precedence without erasing custom data @ci', async ({ page }) => {
    await page.goto(url('manual'));
    await expect.poll(() => tones(page)).toEqual(['dark', 'light', 'light', 'dark']);
    await expect(surfaces(page).first()).toHaveCSS('background-color', 'rgb(34, 39, 46)');
});

for (const view of ['front', 'preview']) {
    test(`Business mobile-hidden sections do not shift the ${view} rhythm @ci`, async ({ page }, testInfo) => {
        await page.goto(url('hidden', view));
        await expect(surfaces(page)).toHaveCount(4);
        const mobile = testInfo.project.name === 'mobile-390';
        await expect(surfaces(page).nth(2)).toHaveAttribute('data-business-tone', mobile ? 'dark' : 'light');
        await expect(surfaces(page).nth(3)).toHaveAttribute('data-business-tone', mobile ? 'light' : 'dark');
    });
}

test('Business reordering recolors without scrolling @ci', async ({ page }) => {
    await page.goto(url());
    await expect(surfaces(page)).toHaveCount(4);
    await page.evaluate(() => {
        const section = document.querySelector('[data-business-surface]');
        section.setAttribute('data-fixture-first', '1');
        const wrapper = section.closest('main > section');
        wrapper.parentNode.appendChild(wrapper);
    });
    await expect(page.locator('[data-fixture-first]')).toHaveAttribute('data-business-tone', 'dark');
    expect(await page.evaluate(() => window.scrollY)).toBe(0);
});

test('Business server-rendered surfaces work without JavaScript @ci', async ({ browser, baseURL }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
        await page.goto(baseURL + url());
        expect(await tones(page)).toEqual(['light', 'dark', 'light', 'dark']);
        await expect(surfaces(page).nth(1)).toHaveCSS('background-color', 'rgb(34, 39, 46)');
    } finally { await context.close(); }
});

test('Business editor exposes modes and previews without saving @ci', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Editor controls are verified once; preview covers all sizes');
    const writes = observeUnsafeWrites(page);
    await page.route('**/admin/blox_preview.php?home=1', async (route) => {
        const body = new URLSearchParams(route.request().postData() || '');
        expect(body.get('action')).toBe('preview');
        const response = await route.fetch({ url: new URL(url('normal', 'preview'), route.request().url()).href });
        await route.fulfill({ response });
    });
    await page.goto(url('normal', 'editor'));
    await waitPreviewSettled(page);
    const tree = page.locator('[data-home-block-type="advantage"]');
    await page.getByTestId('blox-tree-section-label').nth(3).click();
    await tree.locator('[data-element-drag-handle]').click();
    await page.getByRole('button', { name: '样式', exact: true }).click();
    const control = page.locator('select').filter({ has: page.locator('option[value="auto"]', { hasText: '自动交替' }) });
    await expect(control).toBeVisible();
    await control.selectOption('light');
    await waitPreviewSettled(page);
    await expect((await frame(page)).locator('[data-business-surface="light"]')).toHaveCount(1);
    await control.selectOption('auto');
    await waitPreviewSettled(page);
    await expect((await frame(page)).locator('[data-business-surface]').nth(3)).toHaveAttribute('data-business-tone', 'dark');
    expect(writes).toEqual([]);
});
