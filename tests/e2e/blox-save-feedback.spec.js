const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, performPagePreviewUpdate, frame } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test('failed saves persist, repeated saves coalesce, and in-flight edits remain dirty @ci', async ({ page }, testInfo) => {
    await openPageEditor(page, fixtures.blox_page);
    const initial = await page.evaluate(() => window.Alpine.$data(document.body).baseRevision);
    let mode = 'fail', requests = 0, release;
    await page.route('**/admin/blox_page_api.php*', async route => {
        const action = new URLSearchParams(route.request().postData()).get('action');
        if (action !== 'save_draft') return route.continue();
        requests++;
        if (mode === 'wait') await new Promise(resolve => { release = resolve; });
        await route.fulfill({ json: mode === 'fail' ? { code: 1, msg: 'Test failure' } : { code: 0, data: { base_revision: 'test-revision' } } });
    });
    await performPagePreviewUpdate(page, () => page.evaluate(() => {
        const app = window.Alpine.$data(document.body);
        app.sections[0].settings.bg_color = '#eeeeee';
        app.refreshPreview();
    }));
    await page.evaluate(() => window.Alpine.$data(document.body).save());
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).saveOutcome)).toBe('failed');
    await page.evaluate(() => { window.Alpine.$data(document.body).toastMsg = ''; });
    const mobile = await page.getByTestId('blox-mobile-actions-open').isVisible();
    if (mobile) await page.getByTestId('blox-mobile-actions-open').click();
    const status = page.getByTestId(mobile ? 'blox-mobile-save-status' : 'blox-dirty');
    const failedText = await page.evaluate(() => window.Alpine.$data(document.body).uiText.saveStatusFailed);
    await expect(status).toHaveText(failedText);
    expect(await page.evaluate(() => window.Alpine.$data(document.body).baseRevision)).toBe(initial);
    await page.screenshot({ path: testInfo.outputPath('save-failed.png') });
    mode = 'wait';
    await page.evaluate(() => { const app = window.Alpine.$data(document.body); app.save(); app.save(); });
    await expect.poll(() => requests).toBe(2);
    await expect.poll(() => typeof release).toBe('function');
    await page.evaluate(() => { window.Alpine.$data(document.body).sections[0].settings.bg_color = '#dddddd'; });
    release();
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).saving)).toBe(false);
    expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(true);
    await expect(status).toHaveText(await page.evaluate(() => window.Alpine.$data(document.body).uiText.unsaved));
    mode = 'success';
    await page.evaluate(() => window.Alpine.$data(document.body).save());
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(false);
    await expect(status).toHaveText(await page.evaluate(() => window.Alpine.$data(document.body).uiText.draftSaved));
});

test('a failed canvas keeps the document and can retry without saving @ci', async ({ page }) => {
    await openPageEditor(page, fixtures.blox_page);
    const before = await page.evaluate(() => window.Alpine.$data(document.body).documentData());
    const canvasBefore = await (await frame(page)).locator('body').innerText();
    let failed = true, writes = 0;
    await page.route('**/admin/blox_page_api.php*', async route => {
        const action = new URLSearchParams(route.request().postData()).get('action');
        if (action !== 'preview') writes++;
        if (failed && action === 'preview') return route.fulfill({ status: 503, body: 'Unavailable' });
        return route.continue();
    });
    await page.evaluate(() => window.Alpine.$data(document.body).refreshPreview());
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).previewFailed)).toBe(true);
    expect(await (await frame(page)).locator('body').innerText()).toBe(canvasBefore);
    const mobile = await page.getByTestId('blox-mobile-actions-open').isVisible();
    if (mobile) await page.getByTestId('blox-mobile-actions-open').click();
    failed = false;
    await page.getByTestId(mobile ? 'blox-mobile-preview-retry' : 'blox-preview-retry').click();
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).previewFailed)).toBe(false);
    expect(await page.evaluate(() => window.Alpine.$data(document.body).documentData())).toBe(before);
    expect(writes).toBe(0);
});

test('malformed or HTTP-error save responses cannot acknowledge an unblurred image edit @ci', async ({ page }) => {
    await openPageEditor(page, fixtures.blox_page);
    await performPagePreviewUpdate(page, () => page.evaluate(() => {
        const app = window.Alpine.$data(document.body);
        app.selectSection(app.sections.length - 1, false);
        app.addElement(app.elementLib.find(el => el.type === 'image'));
        app.mobilePanel = 'settings';
        app.refreshPreview();
    }));
    let payload;
    let response = { json: {} };
    await page.route('**/admin/blox_page_api.php*', async route => {
        const params = new URLSearchParams(route.request().postData());
        if (params.get('action') !== 'save_draft') return route.continue();
        payload = params.get('blocks_data');
        await route.fulfill(response);
    });
    await page.getByTestId('blox-element-image-url').fill('/images/case-demo.jpg');
    await expect(page.getByTestId('blox-element-image-url')).toBeFocused();
    await page.evaluate(() => window.Alpine.$data(document.body).save());
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).saveOutcome)).toBe('failed');
    expect(payload).toContain('/images/case-demo.jpg');
    expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(true);
    response = { status: 503, json: { code: 0 } };
    await page.evaluate(() => window.Alpine.$data(document.body).save());
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).saveOutcome)).toBe('failed');
    expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(true);
});
