const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, performPagePreviewUpdate, observeConsole, waitPreviewSettled, canvasScrollTop } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

for (const scope of ['element', 'section', 'container', 'column']) {
    test(`${scope} image control supports replace, clear, undo and cancel @ci`, async ({ page }, testInfo) => {
        const errors = observeConsole(page);
        await openPageEditor(page, fixtures.blox_page);
        await performPagePreviewUpdate(page, () => page.evaluate(scope => {
            const app = window.Alpine.$data(document.body);
            app.selectSection(app.sections.length - 1, false);
            if (scope === 'element') app.addElement(app.elementLib.find(el => el.type === 'image'));
            if (scope === 'container') app.selectContainer(app.selectedSi, false);
            if (scope === 'column') app.selectColumn(app.selectedSi, 0, false);
            if (scope !== 'element') app.panelTab = 'style';
            app.mobilePanel = 'settings';
            app.refreshPreview();
        }, scope));
        const id = scope === 'element' ? 'blox-element-image' : `blox-${scope}-background-image`;
        const control = page.getByTestId(id + '-control');
        const input = control.locator('input');
        await expect(input).toBeVisible();
        await performPagePreviewUpdate(page, async () => { await input.fill('/images/case-demo.jpg'); await input.blur(); });
        await expect.poll(() => control.locator('img').evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
        await waitPreviewSettled(page);
        const beforeScroll = await canvasScrollTop(page);
        const history = () => page.evaluate(() => window.Alpine.$data(document.body).historyStore().index);
        const beforeClear = await history();
        await performPagePreviewUpdate(page, () => page.getByTestId(id + '-clear').click());
        await expect(input).toHaveValue('');
        await expect(page.getByTestId(id + '-clear')).toBeDisabled();
        expect(await history()).toBe(beforeClear + 1);
        await performPagePreviewUpdate(page, () => page.evaluate(() => window.Alpine.$data(document.body).undo()));
        await expect(input).toHaveValue('/images/case-demo.jpg');
        expect(await history()).toBe(beforeClear);
        expect(Math.abs((await canvasScrollTop(page)) - beforeScroll)).toBeLessThan(8);
        await page.getByTestId(id + '-media').click();
        await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).mediaOpen)).toBe(true);
        // Closing never clears the image or changes the history.
        await page.keyboard.press('Escape');
        await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).mediaOpen)).toBe(false);
        await expect(input).toHaveValue('/images/case-demo.jpg');
        expect(await history()).toBe(beforeClear);
        // A deterministic catalog fixture exercises the real picker buttons and callback.
        await page.route('**/admin/media_api.php?*', route => route.fulfill({ json: { code: 0, data: {
            items: [{ id: 1, name: 'Fixture image', url: '/images/logo.png', width: 1920, height: 1080 }],
            pages: 1, total: 1,
        } } }));
        await page.getByTestId(id + '-media').click();
        await performPagePreviewUpdate(page, () => page.getByTestId('blox-media-item').first().click());
        await expect(input).toHaveValue('/images/logo.png');
        await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).mediaOpen)).toBe(false);
        expect(await history()).toBe(beforeClear + 1);
        await performPagePreviewUpdate(page, () => page.evaluate(() => window.Alpine.$data(document.body).undo()));
        await expect(input).toHaveValue('/images/case-demo.jpg');
        expect(await control.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        await page.screenshot({ path: testInfo.outputPath(scope + '-image-control.png') });
        expect(errors).toEqual([]);
    });
}
