const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

const AREA_TEMPLATES = [
  { slug: 'corporate-site-header', name: 'Corporate Site Header' },
  { slug: 'corporate-site-footer', name: 'Corporate Site Footer' },
];

async function submit(page, form) {
  // waitForNavigation 已废弃且有竞态（重定向落在同 URL 时可能挂满 45s，CI 偶发）。
  // 先武装 POST 响应等待再点击，然后等重定向落地——时序上不可能错过。
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
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

test('header preset gallery uses responsive HTML wireframes @ci', async ({ page }) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'area template management is an advanced feature');
  await page.goto('/admin/blox_templates.php?type=header', { waitUntil: 'domcontentloaded' });

  const gallery = page.getByTestId('blox-area-presets');
  const previews = gallery.locator('[data-preview-layout]');
  await expect(previews).toHaveCount(4);
  expect(await previews.evaluateAll((elements) => elements.map((element) => element.dataset.previewLayout))).toEqual([
    'content-left',
    'viewport-left',
    'centered-brand',
    'corporate',
  ]);
  await expect(gallery.locator('.yk-area-wireframe img')).toHaveCount(0);

  const layout = await gallery.evaluate((element) => {
    const previewBoxes = Array.from(element.querySelectorAll('[data-preview-layout]'))
      .map((preview) => preview.getBoundingClientRect());
    return {
      clientWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      previewRatios: previewBoxes.map((box) => Math.round((box.width / box.height) * 100) / 100),
      previewsInsideViewport: previewBoxes.every((box) => box.left >= 0 && box.right <= document.documentElement.clientWidth + 1),
    };
  });
  expect(layout.scrollWidth).toBeLessThanOrEqual(layout.clientWidth);
  expect(layout.previewsInsideViewport).toBe(true);
  expect(layout.previewRatios.every((ratio) => ratio >= 3.35 && ratio <= 3.5)).toBe(true);
});

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
      const visualOptions = { threshold: 0.3, maxDiffPixelRatio: 0.05 };
      const expectedHeaderHeight = testInfo.project.name === 'desktop-1440' ? 149 : 229;
      const naturalHeaderBox = await header.boundingBox();
      expect(naturalHeaderBox).not.toBeNull();
      expect(naturalHeaderBox.height).toBeGreaterThanOrEqual(expectedHeaderHeight - 2);
      expect(naturalHeaderBox.height).toBeLessThanOrEqual(expectedHeaderHeight + 2);
      // Chromium font metrics can round the same area by 1-2px across runners.
      await header.evaluate((element, height) => {
        element.style.setProperty('box-sizing', 'border-box', 'important');
        element.style.setProperty('height', `${height}px`, 'important');
        element.style.setProperty('min-height', `${height}px`, 'important');
        element.style.setProperty('max-height', `${height}px`, 'important');
        element.style.setProperty('overflow', 'hidden', 'important');
      }, expectedHeaderHeight);
      const headerSnapshotBox = await header.boundingBox();
      const headerSnapshot = await page.screenshot({
        animations: 'disabled',
        clip: {
          x: Math.floor(headerSnapshotBox.x),
          y: Math.floor(headerSnapshotBox.y),
          width: Math.round(headerSnapshotBox.width),
          height: expectedHeaderHeight,
        },
      });
      expect(headerSnapshot).toMatchSnapshot('r35-default-corporate-header.png', visualOptions);
      const expectedFooterHeight = testInfo.project.name === 'desktop-1440' ? 453 : 648;
      const naturalFooterBox = await footer.boundingBox();
      expect(naturalFooterBox).not.toBeNull();
      expect(naturalFooterBox.height).toBeGreaterThanOrEqual(expectedFooterHeight - 2);
      expect(naturalFooterBox.height).toBeLessThanOrEqual(expectedFooterHeight + 2);
      await footer.evaluate((element, height) => {
        element.style.setProperty('box-sizing', 'border-box', 'important');
        element.style.setProperty('height', `${height}px`, 'important');
        element.style.setProperty('min-height', `${height}px`, 'important');
        element.style.setProperty('max-height', `${height}px`, 'important');
        element.style.setProperty('overflow', 'hidden', 'important');
      }, expectedFooterHeight);
      await footer.scrollIntoViewIfNeeded();
      const footerSnapshotBox = await footer.boundingBox();
      const footerSnapshot = await page.screenshot({
        animations: 'disabled',
        clip: {
          x: Math.floor(footerSnapshotBox.x),
          y: Math.floor(footerSnapshotBox.y),
          width: Math.round(footerSnapshotBox.width),
          height: expectedFooterHeight,
        },
        mask: [footer.locator('[data-yk-copyright-text]')],
        maskColor: '#030712',
      });
      expect(footerSnapshot).toMatchSnapshot('r35-default-corporate-footer.png', visualOptions);
    }
    expect(consoleEntries, 'published default areas must not log browser errors').toEqual([]);
  } finally {
    await unpublishAreas(page);
  }
});
