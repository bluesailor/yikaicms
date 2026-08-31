const fs = require('fs');
const os = require('os');
const path = require('path');
const { test, expect } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const themes = ['business', 'aurora', 'minimal', 'trade'];
const installed = [];
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeAll(() => {
    if (!process.env.CI && (path.dirname(root) !== path.resolve(os.tmpdir())
        || !path.basename(root).startsWith('yikai-e2e-'))) {
        throw new Error('Run theme-market tests through run-local.js in a disposable site');
    }
    for (const slug of themes) {
        const destination = path.join(root, 'themes', slug);
        if (fs.existsSync(destination)) throw new Error(`Refusing to overwrite existing theme: ${slug}`);
        fs.cpSync(path.join(root, 'marketplace/themes', slug), destination, { recursive: true });
        installed.push(destination);
    }
});

test.afterAll(() => {
    for (const destination of installed) {
        if (path.dirname(destination) !== path.join(root, 'themes')
            || !themes.includes(path.basename(destination))) throw new Error('Unsafe test cleanup');
        fs.rmSync(destination, { recursive: true, force: true });
    }
});

for (const mode of ['none', 'banner', 'later', 'mobile-hidden', 'blox-none', 'blox-empty']) {
    test(`Business header follows visible leading banner: ${mode} @ci`, async ({ page }, testInfo) => {
        const response = await page.goto(`/tests/e2e/theme-market-page.php?theme=business&mode=${mode}`);
        expect(response.status()).toBe(200);
        const overlay = mode === 'banner' || (mode === 'mobile-hidden' && testInfo.project.name !== 'mobile-390');
        const header = page.locator('#siteHeader');
        await expect(header).toHaveClass(new RegExp(overlay ? 'nav-transparent' : 'nav-solid'));
        if (!overlay) await expect(header).toHaveCSS('background-color', 'rgb(30, 41, 59)');
        if (testInfo.project.name === 'mobile-390') {
            await page.locator('#mobileMenuBtn').click();
            await expect(page.locator('#mobileMenu')).toBeVisible();
        } else {
            await expect(header.locator('nav').first()).toBeVisible();
        }
        if (mode === 'none' || mode === 'banner') {
            await expect.poll(() => page.locator('[data-animate].animated').evaluateAll(
                (elements) => elements.every((element) => getComputedStyle(element).opacity === '1')
            )).toBe(true);
            await page.screenshot({ path: testInfo.outputPath(`business-${mode}.png`) });
        }
    });
}

test('Business signed-in mobile menu stays below the admin toolbar @ci', async ({ browser }) => {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        storageState: process.env.BLOX_E2E_STORAGE_STATE || path.join(__dirname, '.auth/admin.json'),
    });
    const page = await context.newPage();
    try {
        await page.goto(`${process.env.BLOX_E2E_BASE_URL || 'http://127.0.0.1:8080'}/tests/e2e/theme-market-page.php?theme=business&mode=banner`);
        await expect(page.locator('#ik-adminbar')).toBeVisible();
        await page.locator('#mobileMenuBtn').click();
        await expect(page.locator('#mobileMenu')).toBeVisible();
    } finally {
        await context.close();
    }
});

test('Business without JavaScript remains readable @ci', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    try {
        await page.goto(`${process.env.BLOX_E2E_BASE_URL || 'http://127.0.0.1:8080'}/tests/e2e/theme-market-page.php?theme=business&mode=none`);
        await expect(page.locator('#siteHeader')).toHaveCSS('background-color', 'rgb(30, 41, 59)');
    } finally {
        await context.close();
    }
});

for (const theme of themes.filter((slug) => slug !== 'business')) {
    test(`market theme renders after installation: ${theme} @ci`, async ({ page }) => {
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        const response = await page.goto(`/tests/e2e/theme-market-page.php?theme=${theme}&mode=banner`);
        expect(response.status()).toBe(200);
        await expect(page.locator('header')).toBeVisible();
        await expect(page.locator('footer')).toBeVisible();
        expect(await page.locator('body').innerText()).not.toMatch(/Fatal error|Uncaught Error|Warning:/);
        expect(errors).toEqual([]);
    });
}
