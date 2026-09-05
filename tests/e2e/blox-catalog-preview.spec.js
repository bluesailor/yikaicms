const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, frame, performPagePreviewUpdate, observeConsole } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

for (const [type, id] of [['product-catalog', fixtures.product_page], ['content-catalog', fixtures.channel_list]]) {
    test(`${type} previews public controls, responsive layout and unsaved changes @ci @shard-core`, async ({ page }, testInfo) => {
        const errors = observeConsole(page), writes = [];
        page.on('request', request => {
            if (request.method() === 'POST' && request.url().includes('/admin/blox_page_api.php')) {
                const action = new URLSearchParams(request.postData()).get('action');
                if (action !== 'preview') writes.push(action);
            }
        });
        expect(id).toBeGreaterThan(0);
        await openPageEditor(page, id);
        const canvas = await frame(page);
        const catalog = canvas.locator(`[data-${type}]`);
        await expect(catalog).toBeVisible();
        await expect(catalog.locator('input[name="keyword"]')).toBeVisible();
        const before = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
        const links = await catalog.locator('a').evaluateAll(nodes => nodes.map(node => node.getAttribute('href')));
        expect(links.length).toBeGreaterThan(0);
        expect(links.some(link => link.includes('/admin/'))).toBe(false);
        if (type === 'product-catalog') {
            await expect(catalog.locator('.category-item').first()).toBeVisible();
        }
        await page.screenshot({ path: testInfo.outputPath(type + '-desktop.png') });
        await page.evaluate(() => { window.Alpine.$data(document.body).previewDevice = 'mobile'; });
        await expect.poll(() => canvas.evaluate(() => window.innerWidth)).toBeLessThanOrEqual(430);
        expect(await catalog.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(before);
        await page.screenshot({ path: testInfo.outputPath(type + '-mobile.png') });
        await performPagePreviewUpdate(page, () => page.evaluate(type => {
            const app = window.Alpine.$data(document.body);
            const node = app.sections.flatMap(s => s.columns.flatMap(c => c.elements)).find(e => e.type === type);
            node.data.show_search = false;
            node.data.show_categories = false;
            node.data.show_sort = false;
            node.data.layout = 'grid';
            app.refreshPreview();
        }, type));
        await expect(catalog.locator('input[name="keyword"]')).toHaveCount(0);
        await expect(catalog.locator('.category-item')).toHaveCount(0);
        expect(writes).toEqual([]);
        expect(errors).toEqual([]);
    });
}
