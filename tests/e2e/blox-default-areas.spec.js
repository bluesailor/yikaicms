const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

const AREA_TEMPLATES = [
  { slug: 'corporate-site-header', name: 'Corporate Site Header' },
  { slug: 'corporate-site-footer', name: 'Corporate Site Footer' },
];

async function submit(page, form) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    form.locator('button[type="submit"]').click(),
  ]);
}

async function installAndPublish(page, template) {
  const installForm = page.locator('form').filter({
    has: page.locator(`input[name="slug"][value="${template.slug}"]`),
  });
  await expect(installForm).toHaveCount(1);
  await submit(page, installForm);

  const rows = page.locator('tbody tr').filter({ hasText: template.name });
  await expect(rows.first()).toBeVisible();
  if (await rows.locator('form:has(input[name="action"][value="unpublish"])').count()) return;
  const publishForm = rows.locator('form:has(input[name="action"][value="publish"])').first();
  await expect(publishForm).toHaveCount(1);
  await submit(page, publishForm);
}

async function unpublishAreas(page) {
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  for (const template of AREA_TEMPLATES) {
    const rows = page.locator('tbody tr').filter({ hasText: template.name });
    if (!await rows.first().isVisible().catch(() => false)) continue;
    const form = rows.locator('form:has(input[name="action"][value="unpublish"])').first();
    if (await form.count()) await submit(page, form);
  }
}

test('published default corporate areas stay responsive @ci', async ({ page }, testInfo) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'area template management is an advanced feature');
  const consoleEntries = observeConsole(page);
  try {
    await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
    for (const template of AREA_TEMPLATES) await installAndPublish(page, template);

    const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
    await page.goto(`${fixtures.blox_page_url}&preview=1`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts && document.fonts.ready);

    const header = page.locator('.yk-blox-header');
    const footer = page.locator('.yk-blox-footer');
    await expect(header).toBeVisible();
    await expect(footer).toBeVisible();
    await expect(header.locator('form[role="search"] input[name="keyword"]')).toBeVisible();
    const languageSwitcher = header.locator('[data-yk-language-switcher="dropdown"]');
    const languageTrigger = languageSwitcher.locator('[data-yk-language-trigger]');
    const languageMenu = languageSwitcher.locator('[data-yk-language-menu]');
    await expect(languageTrigger).toBeVisible();
    await expect(languageMenu).toBeHidden();
    await languageTrigger.click();
    await expect(languageMenu).toBeVisible();
    await expect(languageMenu.locator('a[hreflang]')).toHaveCount(3);
    await expect(languageMenu.locator('a[aria-current="page"]')).toHaveCount(1);
    const menuBox = await languageMenu.boundingBox();
    const viewport = page.viewportSize();
    expect(menuBox).not.toBeNull();
    expect(menuBox.x).toBeGreaterThanOrEqual(0);
    expect(menuBox.x + menuBox.width).toBeLessThanOrEqual(viewport.width + 1);
    await page.locator('main').click({ position: { x: 1, y: 1 } });
    await expect(languageMenu).toBeHidden();
    await languageTrigger.click();
    await languageMenu.locator('a[aria-current="page"]').focus();
    await page.keyboard.press('Escape');
    await expect(languageMenu).toBeHidden();
    await expect(languageTrigger).toBeFocused();
    await expect(header).toHaveAttribute('data-yk-sticky-behavior', 'always');
    await expect(header).toHaveAttribute('data-yk-sticky-desktop', '1');
    await expect(header).toHaveAttribute('data-yk-sticky-tablet', '1');
    await expect(header).toHaveAttribute('data-yk-sticky-mobile', '1');
    const phone = footer.locator('a[href^="tel:"]');
    await expect(phone).toBeVisible();
    await expect(phone).not.toHaveText('');
    await expect(footer).toContainText(String(new Date().getFullYear()));

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(header).toHaveClass(/yk-stuck/);
    await expect(header).not.toHaveClass(/yk-sticky-hidden/);
    await header.evaluate((element) => element.setAttribute('data-yk-sticky-behavior', 'scroll-up'));
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(header).not.toHaveClass(/yk-stuck/);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(header).toHaveClass(/yk-sticky-hidden/);
    await page.evaluate(() => window.scrollBy(0, -200));
    await expect(header).not.toHaveClass(/yk-sticky-hidden/);
    await header.evaluate((element) => element.setAttribute('data-yk-sticky-behavior', 'always'));
    await page.evaluate(() => window.scrollTo(0, 0));

    const mega = header.locator('.yk-mega');
    const drawer = header.locator('[data-yk-nav-drawer]');
    if (testInfo.project.name === 'desktop-1440') {
      await expect(mega).toBeVisible();
      await expect(drawer).toBeHidden();
    } else {
      await expect(mega).toBeHidden();
      await expect(drawer).toBeVisible();
      await expect(drawer.locator('[data-yk-drawer-open]')).toBeVisible();
    }

    if (testInfo.project.name !== 'tablet-768') {
      const visualOptions = { animations: 'disabled', threshold: 0.3, maxDiffPixelRatio: 0.05 };
      await expect(header).toHaveScreenshot('r35-default-corporate-header.png', visualOptions);
      await expect(footer).toHaveScreenshot('r35-default-corporate-footer.png', {
        ...visualOptions,
        mask: [footer.locator('[data-yk-copyright-text]')],
        maskColor: '#030712',
      });
    }
    expect(consoleEntries, 'published default areas must not log browser errors').toEqual([]);
  } finally {
    await unpublishAreas(page);
  }
});
