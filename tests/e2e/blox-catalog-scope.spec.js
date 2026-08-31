const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, observeConsole } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

for (const [type, kind, id] of [['product-catalog', 'product', fixtures.product_page], ['content-catalog', 'article', fixtures.channel_list]]) {
    test(`${kind} catalog identifies language and sources without changing layout or interpreting markup @ci`, async ({ page }, testInfo) => {
        const errors = observeConsole(page), writes = [];
        page.on('request', req => {
            if (req.method() === 'POST' && req.url().includes('/admin/blox_page_api.php')) {
                const action = new URLSearchParams(req.postData()).get('action');
                if (!['preview', 'catalog_items'].includes(action)) writes.push(action);
            }
        });
        await openPageEditor(page, id);
        await page.evaluate(type => {
            const app = window.Alpine.$data(document.body);
            app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
                if (e.type !== type) return;
                app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
            })));
            app.panelTab = 'content'; app.mobilePanel = 'settings';
        }, type);
        const snapshot = () => page.evaluate(() => {
            const app = window.Alpine.$data(document.body);
            return JSON.stringify([app.documentData(), app.dirty, app.historySelection()]);
        });
        const before = await snapshot(), panel = page.getByTestId('blox-catalog-source');
        const loaded = page.waitForResponse(res => res.request().method() === 'POST'
            && new URLSearchParams(res.request().postData()).get('action') === 'catalog_items');
        await panel.locator(':scope > button').click();
        const result = await (await loaded).json();
        const lang = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
        await expect(panel.getByTestId('blox-catalog-language')).toHaveAttribute('data-language', lang);
        await expect(panel.getByTestId('blox-catalog-language')).toHaveText({ 'zh-CN': '中文', en: 'English', ja: '日本語' }[lang]);
        await expect(panel.getByTestId('blox-catalog-range')).toHaveText(kind === 'product'
            ? /全部分类，含未分类产品|All categories, including uncategorized products|すべてのカテゴリ（未分類の商品を含む）/
            : /.+及全部下级栏目|.+ and all subchannels|.+とすべての下位チャネル/);
        const manager = page.getByTestId('blox-content-source').locator(`a[href^="/admin/${kind}.php?"]`);
        await expect(manager).toHaveAttribute('href', `/admin/${kind}.php?lang=${lang}`);
        for (const row of result.data.items) {
            expect(row.source_label).toBeTruthy();
            const item = panel.locator(`[data-testid="blox-catalog-item"][href="/admin/${kind}_edit.php?id=${row.id}"]`);
            await expect(item.getByTestId('blox-catalog-item-title')).toHaveText(row.title);
            await expect(item.getByTestId('blox-catalog-item-source')).toHaveText(row.source_label);
        }
        await page.screenshot({ path: testInfo.outputPath(kind + '-scope.png') });

        const maliciousName = '<img src=x onerror="window.catalogInjected=1">' + 'LongSourceName'.repeat(10);
        await page.route('**/admin/blox_page_api.php', async route => {
            if (new URLSearchParams(route.request().postData()).get('action') !== 'catalog_items') return route.continue();
            const response = await route.fetch(), json = await response.json();
            json.data.items[0].source_label = maliciousName;
            return route.fulfill({ response, json });
        });
        await panel.locator('input[type="search"]').press('Enter');
        const source = panel.getByTestId('blox-catalog-item-source').first();
        await expect(source).toHaveText(maliciousName);
        await expect(source.locator('img,script')).toHaveCount(0);
        expect(await page.evaluate(() => window.catalogInjected)).toBeUndefined();
        expect(await panel.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        expect(await snapshot()).toBe(before);
        await page.screenshot({ path: testInfo.outputPath(kind + '-long-source.png') });
        expect(writes).toEqual([]);
        expect(errors).toEqual([]);
    });
}
