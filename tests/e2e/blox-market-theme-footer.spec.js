const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');
const { frame, openEditor } = require('./helpers');

const root = path.resolve(__dirname, '../..');
let cleanup = () => {};

test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business', 'minimal']);
});

test.afterAll(() => cleanup());

async function activateTheme(page, slug) {
  await page.goto('/admin/theme.php', { waitUntil: 'domcontentloaded' });
  const form = page.locator(`form:has(input[name="slug"][value="${slug}"])`);
  await expect(form).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/theme.php'),
    form.getByRole('button').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('main')).toContainText(`当前主题：${slug}`);
}

async function pointerClick(page, locator) {
  await expect(locator).toBeVisible();
  await locator.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'center' }));
  await page.waitForTimeout(50);
  try {
    await locator.click({ timeout: 1500 });
    return;
  } catch (_) {
    // The editor canvas is zoomed, so Playwright can misclassify iframe coordinates.
  }
  await locator.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const MouseEvent = element.ownerDocument.defaultView.MouseEvent;
    const options = {
      bubbles: true,
      cancelable: true,
      composed: true,
      clientX: rect.x + rect.width / 2,
      clientY: rect.y + rect.height / 2,
      view: element.ownerDocument.defaultView,
    };
    element.dispatchEvent(new MouseEvent('mousedown', options));
    element.dispatchEvent(new MouseEvent('mouseup', options));
    element.dispatchEvent(new MouseEvent('click', options));
  });
}

for (const slug of ['business', 'minimal']) {
  test(`${slug} homepage can open the footer editor @ci`, async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');

    try {
      await activateTheme(page, slug);
      await openEditor(page);

      const contentFrame = await frame(page);
      const footerArea = contentFrame.locator('[data-yk-context-area="footer"]');
      const footerEdit = contentFrame.getByTestId('blox-context-edit-footer');
      await expect(footerEdit).toBeVisible();

      const footerUrl = new URL(
        await footerArea.getAttribute('data-yk-context-url'),
        page.url()
      ).href;
      await Promise.all([
        page.waitForURL(footerUrl, { waitUntil: 'domcontentloaded' }),
        pointerClick(page, footerEdit),
      ]);

      await expect(page).toHaveURL(/\/admin\/(?:blox_editor\.php\?template=\d+|site_design\.php#site-design-area-footer)$/);
      if (new URL(page.url()).pathname.endsWith('/blox_editor.php')) {
        await expect(page.getByTestId('blox-canvas')).toBeVisible();
        await expect((await frame(page)).locator('[data-yk-area="footer"]')).toBeVisible();
      } else {
        await expect(page.getByTestId('site-design-area-footer')).toBeVisible();
      }
    } finally {
      await activateTheme(page, 'default');
    }
  });
}
