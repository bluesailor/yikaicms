const { test, expect } = require('@playwright/test');
const { openBanner } = require('./banner-helpers');
const { frame, waitPreviewSettled } = require('./helpers');
const { theme, field, number, setupSlides, defineThemeLayoutTests } = require('./blox-banner-layout-helpers');

defineThemeLayoutTests(['default']);

test('slide overrides are independent and undo restores the inherited layout @ci', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'Desktop editor history path');
    await theme(page, 'default');
    await openBanner(page);
    await setupSlides(page);
    await page.getByTestId('blox-banner-group-layout').click();
    await field(page, 'banner_layout_desktop_enabled').locator('input').check();
    await field(page, 'banner_layout_desktop_position').getByRole('button', { name: '左上', exact: true }).click();
    await waitPreviewSettled(page);
    await page.locator('[data-banner-thumb]').first().locator('button').first().click();
    await page.getByTestId('blox-banner-group-layout').click();
    await field(page, 'banner_layout_desktop_enabled').locator('input').check();
    await field(page, 'banner_layout_desktop_position').getByRole('button', { name: '右下', exact: true }).click();
    await waitPreviewSettled(page);
    const canvas = await frame(page);
    const layers = canvas.locator('[data-blox-banner-content]');
    await expect(layers.nth(0)).toHaveCSS('align-items', 'flex-end');
    await expect(layers.nth(1)).toHaveCSS('align-items', 'flex-start');
    await field(page, 'banner_layout_desktop_enabled').locator('input').uncheck();
    await waitPreviewSettled(page);
    await expect(layers.nth(0)).toHaveCSS('align-items', 'flex-start');
    await page.getByTestId('blox-undo').click();
    await waitPreviewSettled(page);
    await expect(layers.nth(0)).toHaveCSS('align-items', 'flex-end');
    await page.getByTestId('blox-banner-overall-settings').click();
    await page.getByTestId('blox-banner-group-layout').click();
    await expect(field(page, 'banner_layout_desktop_enabled').locator('input')).toBeChecked();
});

test('layout survives a real draft save and reopening the editor @ci', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'One real persistence round trip');
    await theme(page, 'default');
    await openBanner(page);
    expect(new URL(page.url()).hostname).toBe('127.0.0.1');
    const original = await page.evaluate(() => {
        const app = window.Alpine.$data(document.body);
        return { document: app.documentData(), csrf: app.csrf, revision: app.baseRevision };
    });
    let revision = original.revision;
    try {
        await setupSlides(page);
        await page.getByTestId('blox-banner-group-layout').click();
        await field(page, 'banner_layout_desktop_enabled').locator('input').check();
        await field(page, 'banner_layout_desktop_position').getByRole('button', { name: '左下', exact: true }).click();
        await number(page, 'banner_layout_desktop_width', 460);
        const response = page.waitForResponse(r => r.url().includes('/admin/blox_home_api.php') && r.request().method() === 'POST');
        await page.getByTestId('blox-save').click();
        const saved = await (await response).json();
        expect(Number(saved.code)).toBe(0);
        revision = saved.data.base_revision;
        await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).saveOutcome)).toBe('saved');
        await openBanner(page);
        await page.getByTestId('blox-banner-group-layout').click();
        await expect(field(page, 'banner_layout_desktop_enabled').locator('input')).toBeChecked();
        await expect(field(page, 'banner_layout_desktop_width').locator('input')).toHaveValue('460');
        await expect((await frame(page)).locator('[data-blox-banner-content]').first()).toHaveCSS('align-items', 'flex-end');
    } finally {
        const restored = await page.request.post('/admin/blox_home_api.php', { form: {
            blocks_data: original.document, _token: original.csrf, base_revision: revision,
        } });
        expect(Number((await restored.json()).code)).toBe(0);
    }
});
