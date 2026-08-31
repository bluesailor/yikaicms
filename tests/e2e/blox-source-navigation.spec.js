const { test, expect } = require('@playwright/test');
const { openEditor, performPreviewUpdate, observeConsole, observeUnsafeWrites, canvasScrollTop } = require('./helpers');

test('shared source opens the relevant panel without disturbing the editor @ci', async ({ page, context }) => {
    const errors = observeConsole(page), writes = observeUnsafeWrites(page);
    await openEditor(page);
    await performPreviewUpdate(page, () => page.evaluate(() => {
        const app = window.Alpine.$data(document.body);
        const index = app.sections.findIndex(s => s.columns.some(c => c.elements.some(e => e.data?.block_type === 'about')));
        const column = app.sections[index].columns.findIndex(c => c.elements.some(e => e.data?.block_type === 'about'));
        const element = app.sections[index].columns[column].elements.findIndex(e => e.data?.block_type === 'about');
        app.selectedSi = index;
        app.selectedCi = column;
        app.selectedEi = element;
        app.selectedSubEi = -1;
        app.panelTab = 'content';
        app.selEl.data.override_title = 'Uncommitted source navigation draft';
        app.refreshPreview();
    }));
    const link = page.getByTestId('blox-content-source').locator('a');
    // Compact workspaces may keep the properties drawer closed after programmatic selection.
    await page.evaluate(() => { window.Alpine.$data(document.body).mobilePanel = 'settings'; });
    await expect(link).toHaveAttribute('href', /setting_home\.php\?lang=.*#home-source-about$/);
    const before = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
    const scroll = await canvasScrollTop(page);
    const opened = context.waitForEvent('page');
    await link.click();
    const source = await opened;
    await source.waitForLoadState('domcontentloaded');
    await expect(source.locator('#home-source-about')).toBeVisible();
    await expect.poll(() => source.locator('#home-source-about').evaluate(el => window.Alpine.$data(el).expanded)).toBe(true);
    await source.close();
    expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(before);
    expect(Math.abs((await canvasScrollTop(page)) - scroll)).toBeLessThan(8);
    expect(writes).toEqual([]);
    expect(errors).toEqual([]);
});
