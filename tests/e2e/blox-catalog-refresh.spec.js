const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, frame, canvasScrollTop, performPagePreviewUpdate, observeConsole } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function openCatalog(page, type, id) {
    await openPageEditor(page, id);
    await performPagePreviewUpdate(page, () => page.evaluate(type => {
        const app = window.Alpine.$data(document.body);
        app.previewDevice = window.innerWidth < 640 ? 'mobile' : window.innerWidth < 1024 ? 'tablet' : 'desktop';
        window.__catalogInitialSettled = false;
        window.addEventListener('blox:preview-settled', () => { window.__catalogInitialSettled = true; }, { once: true });
        app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
            if (e.type !== type) return;
            app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
            e.data.show_search = false;
        })));
        app.panelTab = 'content'; app.mobilePanel = 'settings';
    }, type));
    await page.waitForFunction(() => window.__catalogInitialSettled);
    const panel = page.getByTestId('blox-catalog-source');
    await panel.locator(':scope > button').click();
    await expect(panel.getByTestId('blox-catalog-item').first()).toBeVisible();
    await page.evaluate(() => window.Alpine.$data(document.body).flushHistory(true));
    return panel;
}

async function draftSnapshot(page) {
    return page.evaluate(() => {
        const app = window.Alpine.$data(document.body), history = app.historyStore();
        return JSON.stringify([app.documentData(), app._savedDocumentSnapshot, app.dirty,
            app.historySelection(), history.entries, history.index]);
    });
}

for (const [type, kind, id] of [['product-catalog', 'product', fixtures.product_page], ['content-catalog', 'article', fixtures.channel_list]]) {
    test(`${kind} saved details refresh without replacing the Blox draft @ci @shard-core`, async ({ page, context }, testInfo) => {
        const errors = observeConsole(page), writes = [], detailErrors = [];
        context.on('page', detail => detail.on('pageerror', error => detailErrors.push(error.message)));
        page.on('request', req => {
            if (req.method() !== 'POST' || !req.url().includes('/admin/blox_page_api.php')) return;
            const action = new URLSearchParams(req.postData()).get('action');
            if (!['preview', 'catalog_items'].includes(action)) writes.push(action);
        });
        const panel = await openCatalog(page, type, id);
        const item = panel.getByTestId('blox-catalog-item').first();
        const title = (await item.getByTestId('blox-catalog-item-title').innerText()).trim();
        const changedTitle = `Refresh ${kind} ${testInfo.project.name} ${Date.now()}`;
        const catalog = (await frame(page)).locator(`[data-${type}]`);
        await expect(catalog).toContainText(title);
        await (await frame(page)).evaluate(() => window.scrollTo(0, 180));
        const scroll = await canvasScrollTop(page);
        expect(scroll).toBeGreaterThan(0);
        const before = await draftSnapshot(page);
        expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(true);

        const opened = context.waitForEvent('page');
        await item.click();
        const detail = await opened;
        await detail.waitForLoadState('domcontentloaded');
        expect(detailErrors).toEqual([]);
        await detail.locator('input[name="title"]').fill(changedTitle);
        const saved = detail.waitForResponse(res => res.request().method() === 'POST'
            && new URL(res.url()).pathname === `/admin/${kind}_edit.php`);
        await detail.locator('#editForm button[type="submit"]').click();
        expect(Number((await (await saved).json()).code)).toBe(0);
        await detail.close();
        await page.bringToFront();
        await expect(catalog).not.toContainText(changedTitle);
        await expect(item).toContainText(title);

        // Observe the actual settled frame, not just the preview HTTP response.
        await page.evaluate(() => {
            window.__catalogRefreshSettled = false;
            window.addEventListener('blox:preview-settled', () => { window.__catalogRefreshSettled = true; }, { once: true });
        });
        await panel.getByTestId('blox-catalog-refresh').focus();
        await panel.getByTestId('blox-catalog-refresh').press('Enter');
        await page.waitForFunction(() => window.__catalogRefreshSettled);
        await expect(catalog).toContainText(changedTitle);
        await expect(panel.getByTestId('blox-catalog-item').filter({ hasText: changedTitle })).toBeVisible();
        await expect(catalog.locator('input[name="keyword"]')).toHaveCount(0);
        expect(await draftSnapshot(page)).toBe(before);
        expect(Math.abs(await canvasScrollTop(page) - scroll)).toBeLessThan(8);
        const refresh = panel.getByTestId('blox-catalog-refresh');
        await expect(refresh).toBeEnabled();
        expect(await refresh.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        await page.screenshot({ path: testInfo.outputPath(kind + '-refreshed.png') });
        expect(writes).toEqual([]);
        expect(errors).toEqual([]);
        expect(detailErrors).toEqual([]);
    });
}

test('refresh preserves the query and page, disables duplicates and recovers from failures @ci @shard-core', async ({ page }, testInfo) => {
    const panel = await openCatalog(page, 'product-catalog', fixtures.product_page);
    const before = await draftSnapshot(page);
    const catalog = (await frame(page)).locator('[data-product-catalog]');
    const oldContent = await catalog.innerText();
    const keyword = 'no-match-' + Date.now();
    await panel.locator('input[type="search"]').fill(keyword);
    await panel.locator('input[type="search"]').press('Enter');
    await expect(panel.getByTestId('blox-catalog-no-match')).toBeVisible();
    // The tiny fixture has one page; retain a later page to exercise a now-empty result.
    await panel.evaluate(el => { const state = window.Alpine.$data(el); state.page = state.requestPage = 2; });
    let failing = true, release;
    const held = new Promise(resolve => { release = resolve; });
    const queries = [], actions = [];
    await page.route('**/admin/blox_page_api.php*', async route => {
        const body = new URLSearchParams(route.request().postData());
        actions.push(body.get('action'));
        if (body.get('action') === 'catalog_items') {
            queries.push(Object.fromEntries(body));
            if (failing) {
                await held;
                return route.fulfill({ json: { code: 1, msg: 'test-failure' } });
            }
        }
        if (body.get('action') === 'preview' && failing) {
            await held;
            return route.fulfill({ status: 503, body: 'Unavailable' });
        }
        return route.continue();
    });
    const refresh = panel.getByTestId('blox-catalog-refresh');
    await refresh.click();
    await expect(refresh).toBeDisabled();
    await expect(refresh).toHaveAttribute('aria-busy', 'true');
    await refresh.evaluate(el => el.click());
    await expect.poll(() => actions.length).toBe(2);
    release();
    await expect(panel.locator('[role="status"]')).toContainText(/加载失败|could not be loaded|読み込めません/);
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).previewFailed)).toBe(true);
    await expect(refresh).toBeEnabled();
    expect(await catalog.innerText()).toBe(oldContent);
    expect(await draftSnapshot(page)).toBe(before);
    await page.screenshot({ path: testInfo.outputPath('refresh-failed.png') });

    failing = false;
    await refresh.click();
    await expect(panel.getByTestId('blox-catalog-empty-page')).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).previewFailed)).toBe(false);
    await expect(refresh).toBeEnabled();
    expect(await panel.locator('input[type="search"]').inputValue()).toBe(keyword);
    expect(queries).toHaveLength(2);
    for (const query of queries) expect([query.keyword, query.page]).toEqual([keyword, '2']);
    expect(actions.sort()).toEqual(['catalog_items', 'catalog_items', 'preview', 'preview']);
    expect(await draftSnapshot(page)).toBe(before);
});
