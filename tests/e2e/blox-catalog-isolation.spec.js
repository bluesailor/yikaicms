const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, observeConsole } = require('./helpers');

let data;
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-stage-fixture.php'), action],
    { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
test.beforeAll(() => { data = JSON.parse(fixture('seed')); });
test.afterAll(() => fixture('cleanup'));

for (const lang of ['zh-CN', 'en', 'ja']) {
    for (const kind of ['product', 'article']) {
        test(`${lang} ${kind} API ignores forged scope and excludes unpublished or unrelated records @ci`, async ({ page }) => {
            const errors = observeConsole(page);
            await openPageEditor(page, data[lang][kind]);
            const before = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).documentData()));
            const token = await page.evaluate(() => window.Alpine.$data(document.body).csrf);
            const ids = [], titles = [];
            for (let current = 1; current <= 4; current++) {
                const response = await page.request.post('/admin/blox_page_api.php', { form: {
                    action: 'catalog_items', id: String(data[lang][kind]), _token: token,
                    keyword: 'Stage gate 0', page: String(current),
                    lang: lang === 'ja' ? 'en' : 'ja', _skip_lang: '1', type: 'case',
                } });
                expect(response.ok()).toBe(true);
                const result = await response.json();
                expect(result.code).toBe(0);
                expect(result.data.page).toBe(current);
                expect(result.data.has_more).toBe(current < 4);
                expect(result.data.items).toHaveLength(6);
                for (const item of result.data.items) {
                    expect(item.source_label).toBe('Stage ' + (kind === 'product' ? 'category ' : 'channel ') + lang);
                    ids.push(item.id);
                    titles.push(item.title);
                }
            }
            expect(new Set(ids).size).toBe(24);
            expect(titles.sort()).toEqual(Array.from({ length: 24 }, (_, i) => 'Stage gate 0 ' + lang + ' ' + (i + 1)).sort());
            expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).documentData()))).toBe(before);
            expect(errors).toEqual([]);
        });
    }
}
