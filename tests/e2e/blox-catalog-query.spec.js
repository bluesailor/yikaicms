const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { openPageEditor, observeConsole } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const seed = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-search-fixture.php'), action],
    { cwd: path.resolve(__dirname, '../..') });

test.beforeAll(() => seed('seed'));
test.afterAll(() => seed('cleanup'));

for (const [type, kind, id] of [['product-catalog', 'product', fixtures.product_page], ['content-catalog', 'article', fixtures.channel_list]]) {
    test(`${kind} query survives paging, refresh, reopening and retry without changing the draft @ci`, async ({ page }, testInfo) => {
        const errors = observeConsole(page), requests = [];
        await openPageEditor(page, id);
        await page.evaluate(type => {
            const app = window.Alpine.$data(document.body);
            app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
                if (e.type !== type) return;
                app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
            })));
            app.panelTab = 'content'; app.mobilePanel = 'settings';
        }, type);
        const panel = page.getByTestId('blox-catalog-source'), search = panel.locator('input[type="search"]');
        const rows = panel.getByTestId('blox-catalog-item');
        await panel.locator(':scope > button').click();
        await expect(rows.first()).toBeVisible();
        const snapshot = () => page.evaluate(() => {
            const app = window.Alpine.$data(document.body), history = app.historyStore();
            return JSON.stringify([app.documentData(), app._savedDocumentSnapshot, app.dirty,
                app.historySelection(), history.entries, history.index]);
        });
        const before = await snapshot();
        let fail = false;
        await page.route('**/admin/blox_page_api.php', async route => {
            const body = new URLSearchParams(route.request().postData());
            requests.push(Object.fromEntries(body));
            if (body.get('action') === 'catalog_items' && fail) return route.fulfill({ json: { code: 1 } });
            return route.continue();
        });
        await search.fill('0');
        await search.press('Enter');
        await expect(rows).toHaveCount(6);
        await expect.poll(() => panel.evaluate(el => window.Alpine.$data(el).resultKeyword)).toBe('0');
        // A legitimate match may be in summary/model rather than the title.
        expect(await rows.getByTestId('blox-catalog-item-title').allTextContents()).not.toContain('Catalog Unrelated');
        const firstIds = await rows.evaluateAll(nodes => nodes.map(node => node.getAttribute('href')));
        await search.fill('not submitted');
        await panel.getByRole('button', { name: /下一页条目|Next items|次の項目/ }).click();
        await expect.poll(() => panel.evaluate(el => window.Alpine.$data(el).page)).toBe(2);
        const nextIds = await rows.evaluateAll(nodes => nodes.map(node => node.getAttribute('href')));
        expect(nextIds.filter(id => firstIds.includes(id))).toEqual([]);
        for (const action of ['refresh', 'reopen']) {
            const count = requests.length;
            if (action === 'refresh') await panel.getByTestId('blox-catalog-refresh').click();
            else {
                await panel.locator(':scope > button').click();
                await panel.locator(':scope > button').click();
            }
            await expect.poll(() => requests.length).toBeGreaterThan(count);
            await expect(panel.locator('[role="status"] span:visible')).toHaveCount(0);
            expect(await rows.evaluateAll(nodes => nodes.map(node => node.getAttribute('href')))).toEqual(nextIds);
        }
        fail = true;
        await panel.getByRole('button', { name: /下一页条目|Next items|次の項目/ }).click();
        await expect(panel.locator('[role="status"] span:visible')).toHaveText(/加载失败|could not be loaded|読み込めません/);
        await search.fill('another unsubmitted query');
        fail = false;
        await panel.getByRole('button', { name: /重新加载条目|Reload items|項目を再読み込み/ }).click();
        await expect.poll(() => panel.evaluate(el => window.Alpine.$data(el).page)).toBe(3);
        await expect(rows).toHaveCount(6);
        const reads = requests.filter(r => r.action === 'catalog_items');
        expect(reads.map(r => [r.keyword, r.page])).toEqual([['0', '1'], ['0', '2'], ['0', '2'], ['0', '2'], ['0', '3'], ['0', '3']]);
        expect(requests.filter(r => !['catalog_items', 'preview'].includes(r.action))).toEqual([]);
        expect(await snapshot()).toBe(before);
        await expect(search).toHaveValue('another unsubmitted query');
        expect(await panel.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        await page.screenshot({ path: testInfo.outputPath(kind + '-query.png') });
        expect(errors).toEqual([]);
    });

    test(`${kind} public and management searches keep numeric zero and unpublished pagination @ci`, async ({ page }) => {
        const errors = observeConsole(page);
        const response = await page.goto(`/list.php?id=${id}&keyword=0`);
        expect(response.status()).toBe(200);
        await expect(page.locator('body')).toContainText('Catalog Zero 0 1');
        await expect(page.locator('body')).not.toContainText('Catalog Unrelated');
        await expect(page.locator('body')).not.toContainText('Catalog Zero 0 0');
        const lang = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
        await page.goto(`/admin/${kind}.php?keyword=0&status=0&lang=${lang}`);
        await expect(page.locator('table')).not.toContainText('Catalog Unrelated');
        await expect(page.locator('table')).not.toContainText('Catalog Zero 0 1');
        // Use the page parameter rather than a language-specific link label.
        const href = await page.locator('a[href*="&page=2"], a[href^="?page=2"]').first().getAttribute('href');
        const url = new URL(href, page.url());
        expect(url.searchParams.get('keyword')).toBe('0');
        expect(url.searchParams.get('status')).toBe('0');
        await page.goto(url.href);
        await expect(page.locator('input[name="keyword"]')).toHaveValue('0');
        await expect(page.locator('table')).toContainText('Catalog Zero 0 0');
        await expect(page.locator('table')).not.toContainText('Catalog Unrelated');
        await expect(page.locator('table')).not.toContainText('Catalog Zero 0 1');
        expect(errors).toEqual([]);
    });
}
