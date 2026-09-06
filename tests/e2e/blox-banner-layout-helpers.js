const { test, expect } = require('@playwright/test');
const { openBanner } = require('./banner-helpers');
const { frame, waitPreviewSettled, observeConsole } = require('./helpers');

async function theme(page, slug) {
    await page.goto('/admin/theme.php');
    const form = page.locator('form:has(input[name="action"][value="activate"]):has(input[name="slug"][value="' + slug + '"])');
    if (await form.count()) {
        page.once('dialog', dialog => dialog.accept());
        await form.getByRole('button').click();
        await page.waitForLoadState('domcontentloaded');
    }
}

const field = (page, key) => page.locator('[data-control-key="' + key + '"]');
async function number(page, key, value) {
    await field(page, key).locator('input').fill(String(value));
    await field(page, key).locator('input').press('Tab');
    await waitPreviewSettled(page);
}
async function setupSlides(page) {
    await page.evaluate(() => {
        const app = window.Alpine.$data(document.body);
        app.runCommand('test-banner-layout-fixture', () => {
            app.selEl.data.items_mode = 'custom';
            app.selEl.data.banner_autoplay = 0;
            app.selEl.data.banner_content_motion = 'none';
            app.selEl.data.banner_height_mode = 'fixed';
            app.selEl.data.banner_height_pc = 560;
            app.selEl.data.banner_height_mobile = 400;
            app.selEl.data.children = [1, 2].map(i => ({
                id: 'layout-slide-' + i, type: 'home-banner-item', data: {
                    title: 'Layout example ' + i, subtitle: 'A readable banner description',
                    image: '/assets/images/demo/banner-1.svg', btn1_text: 'Contact us',
                    btn1_url: '/contact.html', content_motion: 'inherit',
                },
            }));
        });
        app.refreshPreview();
    });
    await waitPreviewSettled(page);
}

function defineThemeLayoutTests(slugs) {
for (const slug of slugs) {
    test(slug + ' banner layout is editable, responsive and bounded @ci', async ({ page }, testInfo) => {
        test.setTimeout(90000);
        const errors = observeConsole(page);
        await theme(page, slug);
        await openBanner(page);
        await setupSlides(page);
        const mobile = testInfo.project.name === 'mobile-390';
        if (mobile) {
            await page.getByTestId('blox-device-mobile').click();
            await waitPreviewSettled(page);
        }
        await page.getByTestId('blox-banner-group-layout').click();
        await field(page, 'banner_layout_desktop_enabled').locator('input').check();
        await field(page, 'banner_layout_desktop_position').getByRole('button', { name: '右上', exact: true }).click();
        await number(page, 'banner_layout_desktop_width', 420);
        await field(page, 'banner_layout_desktop_align').getByRole('button', { name: '居左', exact: true }).click();
        await field(page, 'banner_layout_desktop_buttons').getByRole('button', { name: '居右', exact: true }).click();
        await number(page, 'banner_layout_desktop_gap', 32);
        await page.getByTestId('blox-banner-group-mobile').click();
        await field(page, 'banner_layout_mobile_enabled').locator('input').check();
        await field(page, 'banner_layout_mobile_position').getByRole('button', { name: '中下', exact: true }).click();
        await number(page, 'banner_layout_mobile_width', 1200);
        await number(page, 'banner_layout_mobile_x', 48);
        const canvas = await frame(page);
        const layer = canvas.locator('[data-blox-banner-content]').first();
        await expect(layer).toHaveCSS('align-items', mobile ? 'flex-end' : 'flex-start');
        await expect(layer).toHaveCSS('justify-content', mobile ? 'center' : 'flex-end');
        const box = layer.locator('[data-blox-banner-box]');
        await expect(box).toHaveCSS('text-align', mobile ? 'center' : 'left');
        await expect(layer.locator('[data-blox-banner-buttons]')).toHaveCSS('justify-content', mobile ? 'center' : 'flex-end');
        const bounds = await box.evaluate(node => {
            const a = node.getBoundingClientRect(), b = node.closest('[data-blox-banner-content]').getBoundingClientRect();
            return { left: a.left - b.left, right: b.right - a.right, top: a.top - b.top, bottom: b.bottom - a.bottom };
        });
        for (const value of Object.values(bounds)) expect(value).toBeGreaterThanOrEqual(-1);
        if (mobile) {
            const paginationGap = await box.evaluate(node => {
                const buttons = node.querySelector('[data-blox-banner-buttons]');
                const pagination = node.closest('[data-blox-banner]').querySelector('.swiper-pagination');
                return pagination.getBoundingClientRect().top - buttons.getBoundingClientRect().bottom;
            });
            expect(paginationGap).toBeGreaterThanOrEqual(8);
        }
        await page.screenshot({ path: testInfo.outputPath('banner-layout-' + slug + '.png') });
        if (mobile) {
            await page.getByTestId('blox-mobile-canvas-view').click();
            await expect(page.getByTestId('blox-canvas')).toBeVisible();
            await page.screenshot({ path: testInfo.outputPath('banner-layout-canvas-' + slug + '.png') });
        }
        expect(errors).toEqual([]);
        // The document remains a disposable preview; no publishing on developer sites.
    });
}

}

module.exports = { theme, field, number, setupSlides, defineThemeLayoutTests };
