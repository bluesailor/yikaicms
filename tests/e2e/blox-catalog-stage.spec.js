const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, observeConsole, frame } = require('./helpers');
let data;
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-stage-fixture.php'), action],
    { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
test.beforeAll(() => { data = JSON.parse(fixture('seed')); });
test.afterAll(() => fixture('cleanup'));

for (const lang of ['en', 'ja']) {
    for (const [kind, type, parent] of [['product', 'product-catalog', 'category'], ['article', 'content-catalog', 'child']]) {
        test(`${lang} ${kind} catalog management retains language through search, paging and return @ci`, async ({ page, context }, info) => {
            const errors = observeConsole(page);
            await openPageEditor(page, data[lang][kind]);
            await page.evaluate(type => {
                const app = window.Alpine.$data(document.body);
                app.sections.forEach((s, si) => s.columns.forEach((c, ci) => c.elements.forEach((e, ei) => {
                    if (e.type !== type) return;
                    app.selectedSi = si; app.selectedCi = ci; app.selectedEi = ei; app.selectedSubEi = -1;
                })));
                app.panelTab = 'content'; app.mobilePanel = 'settings';
            }, type);
            const snapshot = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).documentData()));
            const opened = context.waitForEvent('page');
            await page.getByTestId('blox-content-source').locator(`a[href^="/admin/${kind}.php?"]`).click();
            const manager = await opened;
            await manager.waitForLoadState('domcontentloaded');
            const managerErrors = observeConsole(manager);
            const search = manager.locator('input[name="keyword"]');
            await search.fill('Stage gate 0');
            await search.press('Enter');
            await expect(manager).toHaveURL(new RegExp(`[?&]lang=${lang}(?:&|$)`));
            await expect(manager.locator('table')).toContainText('Stage gate 0 ' + lang);
            await expect(manager.locator('table')).not.toContainText('Stage gate 0 zh-CN');
            const next = manager.locator('a[href*="page=2"]').first();
            const nextUrl = new URL(await next.getAttribute('href'), manager.url());
            expect(nextUrl.searchParams.get('lang')).toBe(lang);
            await next.click();
            await expect(manager.locator('table')).toContainText('Stage gate 0 ' + lang);
            const filter = manager.locator(`select[name="${kind === 'product' ? 'category_id' : 'channel_id'}"]`);
            await filter.selectOption(String(data[lang][parent]));
            await search.press('Enter');
            await expect(manager).toHaveURL(new RegExp(`[?&]lang=${lang}(?:&|$)`));
            await expect(filter).toHaveValue(String(data[lang][parent]));
            await expect(manager.locator('table')).toContainText('Stage gate 0 ' + lang);
            const add = manager.locator(`a[href^="/admin/${kind}_edit.php"]`).filter({ hasText: /新增|添加|Add|追加/ }).first();
            const defaultLang = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
            expect(new URL(await add.getAttribute('href'), manager.url()).searchParams.get('lang') || defaultLang).toBe(lang);
            await manager.screenshot({ path: info.outputPath(lang + '-' + kind + '-manager.png') });
            const editor = manager.locator(`a[href="/admin/blox_editor.php?id=${data[lang][kind]}"]`);
            await expect(editor).toBeVisible();
            await editor.click();
            await expect(manager).toHaveURL(new RegExp(`/admin/blox_editor.php\\?id=${data[lang][kind]}$`));
            await expect(manager.getByTestId('blox-canvas')).toBeVisible();
            await expect(manager.getByTestId('blox-save')).toBeAttached();
            await manager.close();
            expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).documentData()))).toBe(snapshot);
            expect(errors).toEqual([]);
            expect(managerErrors).toEqual([]);
        });

        test(`${lang} ${kind} category choices belong to the viewed language @ci`, async ({ page }) => {
            await page.goto(`/admin/${kind}.php?lang=${lang}`);
            const filter = page.locator(`select[name="${kind === 'product' ? 'category_id' : 'channel_id'}"]`);
            const choices = await filter.locator('option').allTextContents();
            expect(choices.some(text => text.includes('Stage ' + (kind === 'product' ? 'category ' : 'channel ') + lang))).toBe(true);
            expect(choices.some(text => text.includes('Stage category zh-CN') || text.includes('Stage channel zh-CN'))).toBe(false);
        });
    }
}

test('public category filtering and sorting preserve language and canvas links stay public @ci', async ({ page }) => {
    const id = data['zh-CN'].product, category = 'e2e-stage-category-zh-CN';
    for (const [sort, first] of [['default', 24], ['newest', 24], ['updated', 24], ['views', 24],
        ['price_asc', 1], ['price_desc', 24], ['not-a-sort', 24]]) {
        await page.goto(`/list.php?id=${id}&_lang=zh-CN&cat=${category}&keyword=Stage+gate+0&sort=${sort}`);
        const titles = await page.locator('h3').allTextContents();
        const matches = titles.filter(title => title.includes('Stage gate 0'));
        expect(matches[0].trim()).toBe('Stage gate 0 zh-CN ' + first);
        expect(matches.some(title => /Stage gate 0 (en|ja)/.test(title))).toBe(false);
    }
    await openPageEditor(page, id);
    const preview = await frame(page);
    await expect(preview.locator('[data-product-catalog]')).toBeVisible();
    const urls = await preview.locator('[data-product-catalog] a').evaluateAll(nodes => nodes.map(n => n.getAttribute('href')));
    expect(urls.some(url => url.includes('/admin/'))).toBe(false);
});
