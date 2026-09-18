const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const rendered = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
    path.join(__dirname, 'fixtures/nav-overflow.php'),
], { encoding: 'utf8' }));
test.use({ storageState: { cookies: [], origins: [] } });

async function setup(page, type) {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.setContent(`<header style="display:flex;gap:24px;width:1100px;padding:16px">
        <div id="logo" style="flex:0 0 180px">Logo</div>
        ${type === 'mega' ? rendered.mega : `<div id="standard" style="flex:1;min-width:0">${rendered.standard}</div>`}
        <div id="language" style="flex:0 0 70px">English</div>
        </header>`);
    await page.addStyleTag({ path: path.join(root, 'assets/css/tailwind.css') });
    await page.addScriptTag({ path: path.join(root, 'assets/js/blox-nav-overflow.js') });
    await expect(page.locator('[data-yk-nav-more]')).toBeVisible();
}

async function barFits(page, type) {
    return page.evaluate((type) => {
        const boundary = document.querySelector(type === 'mega' ? '.yk-mega' : '#standard').getBoundingClientRect();
        const ul = document.querySelector('ul[data-yk-nav-overflow]');
        return [...ul.children].filter(li => li.getClientRects().length).every(li => {
            const box = li.getBoundingClientRect();
            return box.left >= boundary.left - 1 && box.right <= boundary.right + 1;
        });
    }, type);
}

for (const type of ['mega', 'standard']) {
    test(`${type} overflow keeps order, child links, CTA and keyboard access @ci`, async ({ page }) => {
        await setup(page, type);
        const more = page.locator('[data-yk-nav-more]');
        const trigger = more.locator(':scope > a');
        await expect.poll(() => barFits(page, type)).toBe(true);
        await expect(page.locator('ul[data-yk-nav-overflow] > li > a[href^="/section-"]')).not.toHaveCount(1);
        await expect(page.locator('[data-yk-nav-cta]')).toBeVisible();
        await expect(more.locator('[href="/contact"]')).toHaveCount(0);
        await trigger.click();
        await expect(trigger).toHaveAttribute('aria-expanded', 'true');
        await expect(more.getByRole('link', { name: 'Child', exact: true })).toBeVisible();
        if (type === 'mega') {
            await expect(more.getByRole('link', { name: 'Grandchild', exact: true })).toBeVisible();
        }
        const moved = await more.locator('a[href^="/section-"]').evaluateAll(links => links.map(a => Number(a.getAttribute('href').split('-').pop())));
        expect(moved).toEqual([...moved].sort((a, b) => a - b));
        await trigger.press('ArrowDown');
        await expect(more.locator(':scope > ul a').first()).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(trigger).toBeFocused();
        await expect(trigger).toHaveAttribute('aria-expanded', 'false');

        // Available header width can change without a window resize (Logo/sidebar/preview).
        await page.locator('header').evaluate(el => { el.style.width = '2400px'; });
        await expect(more).toBeHidden();
        await expect(page.locator('ul[data-yk-nav-overflow] > li > a[href^="/section-"]')).toHaveCount(10);
        expect(await page.locator('ul[data-yk-nav-overflow] > li > a[href^="/section-"]').evaluateAll(links => links.map(a => a.getAttribute('href'))))
            .toEqual(Array.from({length: 10}, (_, i) => `/section-${i + 1}`));
        await page.locator('header').evaluate(el => { el.style.width = '1100px'; });
        await expect(more).toBeVisible();
        await expect.poll(() => barFits(page, type)).toBe(true);
    });
}

test('mega reflows after returning from a mobile viewport @ci', async ({ page }) => {
    await setup(page, 'mega');
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('.yk-mega')).toBeHidden();
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.locator('[data-yk-nav-more]')).toBeVisible();
    await expect.poll(() => barFits(page, 'mega')).toBe(true);
});
