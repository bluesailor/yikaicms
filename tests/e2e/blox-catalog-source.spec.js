const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, observeConsole, canvasScrollTop } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

for (const [type, kind, id] of [['product-catalog', 'product', fixtures.product_page], ['content-catalog', 'article', fixtures.channel_list]]) {
    test(`${kind} detail entry preserves the Blox draft, selection and canvas position @ci`, async ({ page, context }, testInfo) => {
        const errors = observeConsole(page), writes = [];
        page.on('request', req => {
            if (req.method() === 'POST' && req.url().includes('blox_page_api.php')) {
                const action = new URLSearchParams(req.postData()).get('action');
                if (!['preview', 'catalog_items'].includes(action)) writes.push(action);
            }
        });
        await openPageEditor(page, id);
        expect(await page.locator('body').innerText()).not.toMatch(/Warning:|Notice:|Fatal error:/);
        let previews = 0;
        await page.route('**/admin/blox_page_api.php*', async route => {
            if (new URLSearchParams(route.request().postData()).get('action') === 'preview') {
                previews++;
                await page.waitForTimeout(750);
            }
            await route.continue();
        });
        await page.evaluate(type => {
            const app = window.Alpine.$data(document.body);
            app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
                if (e.type !== type) return;
                app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
                e.data.show_search = false;
            })));
            app.panelTab = 'content'; app.mobilePanel = 'settings';
            app.refreshPreview();
        }, type);
        const panel = page.getByTestId('blox-catalog-source');
        await expect(panel).toBeVisible();
        await panel.locator(':scope > button').click();
        const items = panel.getByTestId('blox-catalog-item');
        await expect(items.first()).toBeVisible();
        const title = (await items.first().getByTestId('blox-catalog-item-title').innerText()).trim();
        const href = await items.first().getAttribute('href');
        expect(href).toMatch(new RegExp(`^/admin/${kind}_edit.php\\?id=\\d+$`));
        const snapshot = () => page.evaluate(() => {
            const app = window.Alpine.$data(document.body);
            return JSON.stringify([app.sections, app.selectedSi, app.selectedCi, app.selectedEi]);
        });
        const before = await snapshot(), scroll = await canvasScrollTop(page);
        expect(previews).toBeGreaterThan(0);
        await page.screenshot({ path: testInfo.outputPath(kind + '-source.png') });
        const opened = context.waitForEvent('page');
        await items.first().click();
        const detail = await opened;
        await detail.waitForLoadState('domcontentloaded');
        await expect(detail.locator('input[name="title"]')).toHaveValue(title);
        await detail.close();
        expect(await snapshot()).toBe(before);
        expect(Math.abs(await canvasScrollTop(page) - scroll)).toBeLessThan(8);
        await panel.locator('input[type="search"]').fill('no-match-' + Date.now());
        await panel.locator('form').evaluate(el => el.requestSubmit());
        await expect(items).toHaveCount(0);
        await expect(panel.getByTestId('blox-catalog-no-match')).toBeVisible();
        await panel.locator('input[type="search"]').fill(title);
        await panel.locator('form').evaluate(el => el.requestSubmit());
        await expect(items.first()).toHaveAttribute('href', href);
        expect(await snapshot()).toBe(before);
        expect(writes).toEqual([]);
        expect(errors).toEqual([]);
    });
}

test('catalog lookup rejects missing CSRF and non-catalog targets @ci', async ({ page }) => {
    await openPageEditor(page, fixtures.product_page);
    const results = await page.evaluate(async ids => {
        const app = window.Alpine.$data(document.body);
        const results = [];
        for (const data of [{ id: ids.product, _token: '' }, { id: ids.page, _token: app.csrf }, { id: 0, _token: app.csrf }]) {
            const response = await fetch('/admin/blox_page_api.php', { method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ action: 'catalog_items', ...data }) });
            results.push(await response.json());
        }
        return results;
    }, { product: fixtures.product_page, page: fixtures.blox_page });
    for (const result of results) expect(Number(result.code)).not.toBe(0);
});

test('catalog failure can be retried and paging keeps the query @ci', async ({ page }) => {
    await openPageEditor(page, fixtures.product_page);
    await page.evaluate(() => {
        const app = window.Alpine.$data(document.body);
        app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
            if (e.type === 'product-catalog') {
                app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
            }
        })));
        app.panelTab = 'content'; app.mobilePanel = 'settings';
    });
    let fail = true;
    const requested = [];
    await page.route('**/admin/blox_page_api.php', async route => {
        const body = new URLSearchParams(route.request().postData());
        if (body.get('action') !== 'catalog_items') return route.continue();
        requested.push(Object.fromEntries(body));
        if (fail) {
            fail = false;
            return route.fulfill({ json: { code: 1, msg: 'temporary-test-failure' } });
        }
        const response = await route.fetch();
        const json = await response.json();
        if (body.get('page') === '1') json.data.has_more = true;
        await route.fulfill({ response, json });
    });
    const panel = page.getByTestId('blox-catalog-source');
    await panel.locator(':scope > button').click();
    await expect(panel.locator('[role="status"]')).toContainText(/加载失败|could not be loaded|読み込めません/);
    await panel.getByRole('button', { name: /重新加载条目|Reload items|項目を再読み込み/ }).click();
    await expect(panel.getByTestId('blox-catalog-item').first()).toBeVisible();
    const next = panel.getByRole('button', { name: /下一页条目|Next items|次の項目/ });
    await next.click();
    await expect.poll(() => requested.at(-1).page).toBe('2');
    await expect(panel.getByRole('button', { name: /上一页条目|Previous items|前の項目/ })).toBeEnabled();
    expect(requested.at(-1).keyword).toBe('');
});
