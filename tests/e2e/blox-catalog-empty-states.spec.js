const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, frame, canvasScrollTop, performPagePreviewUpdate, observeConsole } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function openCatalog(page, type, id) {
    await openPageEditor(page, id);
    await performPagePreviewUpdate(page, () => page.evaluate(type => {
        const app = window.Alpine.$data(document.body);
        window.__catalogEmptySettled = false;
        window.addEventListener('blox:preview-settled', () => { window.__catalogEmptySettled = true; }, { once: true });
        app.previewDevice = window.innerWidth < 640 ? 'mobile' : window.innerWidth < 1024 ? 'tablet' : 'desktop';
        app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
            if (e.type !== type) return;
            app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
            e.data.show_search = false;
        })));
        app.panelTab = 'content'; app.mobilePanel = 'settings';
    }, type));
    await page.waitForFunction(() => window.__catalogEmptySettled);
    await page.evaluate(() => window.Alpine.$data(document.body).flushHistory(true));
    const panel = page.getByTestId('blox-catalog-source');
    await panel.locator(':scope > button').click();
    return panel;
}

async function snapshot(page) {
    return page.evaluate(() => {
        const app = window.Alpine.$data(document.body), history = app.historyStore();
        return JSON.stringify([app.documentData(), app._savedDocumentSnapshot, app.dirty,
            app.historySelection(), history.entries, history.index]);
    });
}

for (const [type, kind, id] of [['product-catalog', 'product', fixtures.product_page], ['content-catalog', 'article', fixtures.channel_list]]) {
    test(`${kind} empty states retain management access and clear search without changing the draft @ci`, async ({ page }, testInfo) => {
        const errors = observeConsole(page), reads = [];
        let unpublished = true;
        await page.route('**/admin/blox_page_api.php', async route => {
            const query = new URLSearchParams(route.request().postData());
            if (query.get('action') !== 'catalog_items' || !unpublished) return route.continue();
            return route.fulfill({ json: { code: 0, data: { items: [], page: 1, has_more: false } } });
        });
        const panel = await openCatalog(page, type, id);
        await expect(panel.getByTestId('blox-catalog-unpublished')).toBeVisible();
        await expect(panel.getByTestId('blox-catalog-unpublished')).toHaveText(kind === 'product'
            ? /暂无已发布产品|No published products|公開済みの商品/ : /暂无已发布文章|No published articles|公開済みの記事/);
        await expect(panel.getByTestId('blox-catalog-no-match')).toBeHidden();
        await expect(panel.getByTestId('blox-catalog-clear-search')).toBeHidden();
        const manage = page.getByTestId('blox-content-source').locator(`a[href^="/admin/${kind}.php?"]`);
        await expect(manage).toHaveCount(1);
        await expect(manage).toBeVisible();
        await expect(manage).toHaveAttribute('target', '_blank');
        const lang = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
        expect(new URL(await manage.getAttribute('href'), 'http://localhost').searchParams.get('lang')).toBe(lang);
        await page.screenshot({ path: testInfo.outputPath(kind + '-unpublished.png') });

        await (await frame(page)).evaluate(() => window.scrollTo(0, 180));
        const scroll = await canvasScrollTop(page), before = await snapshot(page);
        page.on('request', req => {
            if (req.method() === 'POST' && req.url().includes('/admin/blox_page_api.php')) {
                reads.push(new URLSearchParams(req.postData()).get('action'));
            }
        });
        const search = panel.locator('input[type="search"]');
        await search.fill('no-match-' + Date.now());
        await expect(panel.getByTestId('blox-catalog-unpublished')).toBeVisible();
        unpublished = false;
        await search.press('Enter');
        await expect(panel.getByTestId('blox-catalog-no-match')).toBeVisible();
        await expect(panel.getByTestId('blox-catalog-unpublished')).toBeHidden();
        await search.fill('');
        await expect(panel.getByTestId('blox-catalog-no-match')).toBeVisible();
        const clear = panel.getByTestId('blox-catalog-clear-search');
        await expect(clear).toBeVisible();
        await clear.focus();
        await page.screenshot({ path: testInfo.outputPath(kind + '-no-match.png') });
        await clear.press('Enter');
        await expect(panel.getByTestId('blox-catalog-item').first()).toBeVisible();
        await expect(clear).toBeHidden();
        await expect(search).toHaveValue('');
        expect(await snapshot(page)).toBe(before);
        expect(Math.abs(await canvasScrollTop(page) - scroll)).toBeLessThan(8);
        expect(reads).toEqual(['catalog_items', 'catalog_items']);
        expect(await panel.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        expect(errors).toEqual([]);
    });
}

test('an empty later page returns to the same search and failures never look unpublished @ci', async ({ page }, testInfo) => {
    const errors = observeConsole(page), queries = [];
    let failing = false;
    await page.route('**/admin/blox_page_api.php', async route => {
        const query = new URLSearchParams(route.request().postData());
        if (query.get('action') !== 'catalog_items') return route.continue();
        queries.push(Object.fromEntries(query));
        if (failing) return route.fulfill({ json: { code: 1, msg: 'test-failure' } });
        const response = await route.fetch(), json = await response.json();
        if (query.get('page') === '1') json.data.has_more = true;
        return route.fulfill({ response, json });
    });
    const panel = await openCatalog(page, 'product-catalog', fixtures.product_page);
    const item = panel.getByTestId('blox-catalog-item').first();
    await expect(item).toBeVisible();
    const title = (await item.getByTestId('blox-catalog-item-title').innerText()).trim();
    const before = await snapshot(page), search = panel.locator('input[type="search"]');
    await search.fill(title);
    await search.press('Enter');
    await expect(item).toBeVisible();
    await panel.getByRole('button', { name: /下一页条目|Next items|次の項目/ }).click();
    await expect(panel.getByTestId('blox-catalog-empty-page')).toBeVisible();
    await expect(panel.getByTestId('blox-catalog-unpublished')).toBeHidden();
    await expect(panel.getByTestId('blox-catalog-no-match')).toBeHidden();
    await expect(panel.getByTestId('blox-catalog-clear-search')).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath('empty-page.png') });
    await search.fill('not-submitted');
    const firstPage = panel.getByTestId('blox-catalog-first-page');
    await firstPage.focus();
    await firstPage.press('Enter');
    await expect(item).toContainText(title);
    await expect(firstPage).toBeHidden();
    await expect(search).toHaveValue(title);
    expect([queries.at(-1).keyword, queries.at(-1).page]).toEqual([title, '1']);

    failing = true;
    await search.fill('');
    await search.press('Enter');
    await expect(panel.locator('[role="status"] span:visible')).toHaveText(/加载失败|could not be loaded|読み込めません/);
    await expect(panel.getByTestId('blox-catalog-unpublished')).toBeHidden();
    await expect(panel.getByTestId('blox-catalog-clear-search')).toBeHidden();
    await expect(firstPage).toBeHidden();
    failing = false;
    await panel.getByRole('button', { name: /重新加载条目|Reload items|項目を再読み込み/ }).click();
    await expect(item).toBeVisible();
    expect(await snapshot(page)).toBe(before);
    expect(errors).toEqual([]);
});
